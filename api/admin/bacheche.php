<?php
declare(strict_types=1);
// api/admin/bacheche.php

// 1) Forza buffering ed evita output spuri
ob_start();
ini_set('display_errors','0');
ini_set('log_errors','1');

error_reporting(E_ALL);

// 2) Includi solo le librerie essenziali
require_once __DIR__ . '/../../libs/kernel.inc.php';
require_once __DIR__ . '/../../libs/libpg.inc.php';
require_once __DIR__ . '/../../libs/libgrafica.inc.php';
require_once __DIR__ . '/../../libs/libbacheca.inc.php';
require_once __DIR__ . '/../../libs/forum-presentation.inc.php';

use Alphano\Database\PDOMySQLDriver;

// 3) Header JSON
header('Content-Type: application/json; charset=UTF-8');

try {
    // Connessione + autenticazione
    $db   = getDB();
    $idpg = alphano_require_login(true);
    if (!$idpg) throw new \Exception('Non autenticato', 401);

    // Permessi
    $u = $db->query('SELECT admin, sindaco FROM utenti WHERE id = ?', [$idpg])[0] ?? [];
    $isAdmin   = (bool)($u['admin'] ?? false);
    $isSindaco = (bool)($u['sindaco'] ?? false);
    if (!$isAdmin && !$isSindaco) throw new \Exception('Permessi insufficienti', 403);

    // Se sei solo sindaco, filtro per la tua città
    $cityFilter = null;
    if ($isSindaco && !$isAdmin) {
        $r = $db->query(
            'SELECT city.city AS c
             FROM city
             JOIN araldiche ON araldiche.city = city.id
             JOIN utenti    ON utenti.araldica = araldiche.id
             WHERE utenti.id = ?',
            [$idpg]
        )[0] ?? null;
        if (!$r) throw new \Exception('Città non trovata', 404);
        $cityFilter = $r['c'];
    }

    // 1) Elenco completo di tutte le città
    $cityRows = $db->query('SELECT city FROM city ORDER BY city', []);
    $cities   = array_column($cityRows, 'city');

    // Aggiunge “Generale” come prima voce
    array_unshift($cities, 'Generale');

    // 2) Leggi input JSON o POST
    $raw = file_get_contents('php://input');
    $in  = json_decode($raw, true) ?: $_POST;
    $act = (string)($in['azione'] ?? '');
    $msg = null;
    if ($act !== '') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Usa POST',405);
        alphano_require_csrf(true);
    }

    if(array_key_exists('header_image',$in))alphano_board_validate_image((string)$in['header_image']);
    foreach(['location'=>255,'nome'=>255,'descrizione'=>100000] as $field=>$max)if(isset($in[$field])&&(!is_string($in[$field])||mb_strlen($in[$field])>$max))throw new DomainException('Campo non valido o troppo lungo: '.$field,422);

    // 3) CRUD
    if ($act !== '') {
        switch ($act) {
            case 'svuota':
                $id = (int)($in['id'] ?? 0);
                if ($id <= 0) throw new \Exception('ID non valido', 400);
                if (!$db->query('SELECT id FROM luoghi WHERE id=?',[$id])) throw new \Exception('Bacheca non trovata',404);
                if ($cityFilter !== null) {
                    $loc = $db->query('SELECT location FROM luoghi WHERE id = ?', [$id])[0]['location'] ?? '';
                    if ($loc !== $cityFilter) throw new \Exception('Violazione diritti', 403);
                }
                $db->beginTransaction();
                $db->execute('DELETE FROM storicobacheche WHERE oldid IN(SELECT id FROM bacheche WHERE bacheca=?)',[$id]);
                $db->execute('DELETE FROM bacheche WHERE bacheca = ?', [$id]);
                $db->commit();
                $msg = 'Bacheca svuotata';
                break;

            case 'modifica':
                $id   = (int)($in['id'] ?? 0);
                $loc  = trim((string)($in['location'] ?? ''));
                $nome = trim((string)($in['nome'] ?? ''));
                $desc = alphano_sanitize_html(trim((string)($in['descrizione'] ?? '')));
                $clr  = !empty($in['clear']) ? 1 : 0;
                if ($id <= 0 || $loc === '' || $nome === '' || trim(strip_tags($desc)) === '') {
                    throw new \Exception('Parametri mancanti', 400);
                }
                if (!$db->query('SELECT id FROM luoghi WHERE id=?',[$id])) throw new Exception('Bacheca non trovata',404);
                if ($cityFilter !== null && ($loc !== $cityFilter || ($act==='modifica' && ($db->query('SELECT location FROM luoghi WHERE id=?',[$id])[0]['location'] ?? null) !== $cityFilter))) {
                    throw new \Exception('Violazione diritti', 403);
                }
                $db->execute(
                    'UPDATE luoghi
                     SET location = ?, nome = ?, descrizione = ?, clear = ?
                     WHERE id = ?',
                    [$loc, $nome, $desc, $clr, $id]
                );
                if(array_key_exists('header_image',$in))$db->execute('UPDATE luoghi SET header_image=? WHERE id=?',[alphano_board_validate_image((string)$in['header_image']),$id]);
                $msg = 'Bacheca modificata';
                break;

            case 'permessi':
                $id = (int)($in['id'] ?? 0);
                if ($id <= 0) throw new \Exception('ID non valido', 400);
                if (!$db->query('SELECT id FROM luoghi WHERE id=?',[$id])) throw new \Exception('Bacheca non trovata',404);
                if ($cityFilter !== null) {
                    $loc = $db->query('SELECT location FROM luoghi WHERE id = ?', [$id])[0]['location'] ?? '';
                    if ($loc !== $cityFilter) throw new \Exception('Violazione diritti', 403);
                }
                $flags = [
                    !empty($in['all']),
                    !empty($in['needadmin']),
                    !empty($in['needfatepower']),
                    !empty($in['needadminferiti']),
                    !empty($in['needaccademia']),
                ];
                $cnt = $db->query('SELECT COUNT(*) AS cnt FROM diritti WHERE bacheca = ?', [(string)$id])[0]['cnt'] ?? 0;
                if ($cnt) {
                    $db->execute(
                        'UPDATE diritti
                         SET `all` = ?, needadmin = ?, needfatepower = ?, needadminferiti = ?, needaccademia = ?
                         WHERE bacheca = ?',
                        array_merge($flags, [(string)$id])
                    );
                } else {
                    $db->execute(
                        'INSERT INTO diritti (`all`, needadmin, needfatepower, needadminferiti, needaccademia, bacheca)
                         VALUES (?, ?, ?, ?, ?, ?)',
                        array_merge($flags, [(string)$id])
                    );
                }
                $msg = 'Permessi aggiornati';
                break;

            case 'cancella':
                $id  = (int)($in['id'] ?? 0);
                // Cancellazione di un gruppo dal menu: elimina tutte le relative bacheche.
                if (!$id && !empty($in['location'])) {
                    $group = (string)$in['location'];
                    if (!$isAdmin) throw new Exception('Privilegi amministrativi richiesti',403);
                    $db->beginTransaction();
                    foreach ($db->query('SELECT id FROM luoghi WHERE location=?',[$group]) as $board) {
                        $db->execute('DELETE FROM storicobacheche WHERE oldid IN(SELECT id FROM bacheche WHERE bacheca=?)',[$board['id']]);
                        $db->execute('DELETE FROM bacheche WHERE bacheca=?',[$board['id']]);
                        $db->execute('DELETE FROM diritti WHERE bacheca=?',[(string)$board['id']]);
                    }
                    $db->execute('DELETE FROM luoghi WHERE location=?',[$group]);
                    $db->commit();
                    $msg = 'Gruppo di bacheche cancellato';
                    break;
                }
                $all = !empty($in['all']) ? 1 : 0;
                if ($id <= 0) throw new \Exception('ID non valido', 400);
                if (!$db->query('SELECT id FROM luoghi WHERE id=?',[$id])) throw new \Exception('Bacheca non trovata',404);
                if ($cityFilter !== null) {
                    $loc = $db->query('SELECT location FROM luoghi WHERE id = ?', [$id])[0]['location'] ?? '';
                    if ($loc !== $cityFilter) throw new \Exception('Violazione diritti', 403);
                }
                $db->beginTransaction();
                $ok = true;
                if ($db->execute('DELETE FROM luoghi WHERE id = ?', [$id]) === false) $ok = false;
                if($all){$db->execute('DELETE FROM storicobacheche WHERE oldid IN(SELECT id FROM bacheche WHERE bacheca=?)',[$id]);if($db->execute('DELETE FROM bacheche WHERE bacheca = ?', [$id])===false)$ok=false;}
                $db->execute('DELETE FROM diritti WHERE bacheca=?',[(string)$id]);
                $db->commit();
                $msg = $ok ? 'Bacheca cancellata' : 'Errore cancellazione';
                break;

            case 'aggiungi':
                $loc  = trim((string)($in['location'] ?? ''));
                $nome = trim((string)($in['nome'] ?? ''));
                $desc = alphano_sanitize_html(trim((string)($in['descrizione'] ?? '')));
                $clr  = !empty($in['clear']) ? 1 : 0;
                if ($loc === '' || $nome === '' || trim(strip_tags($desc)) === '') {
                    throw new \Exception('Parametri mancanti', 400);
                }
                if ($cityFilter !== null && ($loc !== $cityFilter || ($act==='modifica' && ($db->query('SELECT location FROM luoghi WHERE id=?',[$id])[0]['location'] ?? null) !== $cityFilter))) {
                    throw new \Exception('Violazione diritti', 403);
                }
                $db->beginTransaction();
                $db->execute(
                    'INSERT INTO luoghi (location, nome, descrizione, clear)
                     VALUES (?, ?, ?, ?)',
                    [$loc, $nome, $desc, $clr]
                );
                $newBoard=(int)$db->lastInsertId();$cover=isset($in['header_image'])?alphano_board_validate_image((string)$in['header_image']):alphano_board_image(['location'=>$loc,'nome'=>$nome]);$db->execute('UPDATE luoghi SET header_image=? WHERE id=?',[$cover,$newBoard]);
                $db->execute('INSERT INTO diritti(bacheca,`all`) VALUES(?,1)',[(string)$newBoard]);
                $db->commit();
                $msg = 'Bacheca creata';
                break;

            default:
                throw new \Exception('Azione sconosciuta', 400);
        }
    }

    // 4) Leggi le bacheche
    $sql    = $isAdmin
            ? 'SELECT id,location,nome,descrizione,clear,header_image FROM luoghi ORDER BY location,nome'
            : 'SELECT id,location,nome,descrizione,clear,header_image FROM luoghi WHERE location = ? ORDER BY nome';
    $params = $isAdmin ? [] : [$cityFilter];
    $rows   = $db->query($sql, $params);

    $bacheche = [];
    foreach ($rows as $r) {
        $d = $db->query(
            'SELECT `all`, needadmin, needfatepower, needadminferiti, needaccademia
             FROM diritti WHERE bacheca = ?',
            [(string)$r['id']]
        );
        $rights = $d[0] ?? [
            'all'=>1,'needadmin'=>0,'needfatepower'=>0,'needadminferiti'=>0,'needaccademia'=>0
        ];
        $bacheche[] = [
            'id'          => (int)$r['id'],
            'location'    => $r['location'],
            'nome'        => $r['nome'],
            'descrizione' => $r['descrizione'],
            'clear'       => (bool)$r['clear'],
            'diritti'     => array_map('boolval', $rights),
            'header_image'=>alphano_board_image($r),
        ];
    }

    // 5) Risposta JSON con counts
    ob_clean();
    echo json_encode([
        'success'   => true,
        'message'   => $msg,
        'counts'    => [
            'groups' => count($cities),
            'rules'  => count($bacheche),
            'cities' => count($cities),
            'boards' => count($bacheche),
        ],
        'cities'    => $cities,
        'images' => alphano_board_images(),
        'bacheche'  => $bacheche,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
catch (\Throwable $e) {
    if(isset($db)&&$db->inTransaction())$db->rollBack();
    $code = (int)$e->getCode();
    if ($code < 400 || $code >= 600) $code = 500;
    if($code===500){error_log('[Board admin] '.$e);$reference=alphano_error_reference($e,'board-admin');}
    http_response_code($code);
    ob_clean();
    echo json_encode([
        'success' => false,
        'error'   => $code===500?'Operazione non completata. Codice assistenza: '.$reference:$e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
