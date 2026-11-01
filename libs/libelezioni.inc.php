<?php
declare(strict_types=1);

// libs/libelezioni.inc.php
// Gestione elezioni cittadine, con PDO e bypass se manca il sindaco nella città.

require_once __DIR__ . '/kernel.inc.php';
require_once __DIR__.'/elections.inc.php';  // ConnectDB()

/**
 * Ritorna true se esiste almeno un utente sindaco nella città data.
 */
function HasMayor(int $city): bool
{
    $db = ConnectDB();
    // Cerco utenti con sindaco=1 la cui araldica appartiene alla city
    $row = $db->query(
        'SELECT COUNT(*) AS cnt
           FROM utenti u
     JOIN araldiche a ON u.araldica = a.id
          WHERE a.city = ?
            AND u.sindaco = 1 AND u.puntif>0 AND u.bloccato=0',
        [$city]
    )[0] ?? ['cnt' => 0];

    return ((int)$row['cnt']) > 0;
}

/**
 * Elezioni a marzo, luglio e novembre:
 * primi 15 giorni per candidarsi.
 * Se manca un sindaco, torna sempre true (elezioni straordinarie).
 */
function ElectionTime(int $city): bool
{
    if(alphano_elections_ready()){ $round=alphano_elections_round($city);return !$round||$round['civic_state']==='candidacy'; }
    $db = ConnectDB();
    $round = $db->query('SELECT start_candidature, start_elezioni FROM storicoelezioni WHERE city_id=? AND winner_id IS NULL ORDER BY id DESC LIMIT 1', [$city])[0] ?? null;
    if ($round) return time() >= strtotime($round['start_candidature']) && time() < strtotime($round['start_elezioni']);
    $row = $db->query('SELECT elezioni,electiontime FROM city WHERE id=?', [$city])[0] ?? [];
    return !empty($row['elezioni']) && ((!HasMayor($city)) || ((int)date('n') % 4 === (int)($row['electiontime'] ?? -1) && (int)date('j') <= 15));
}

/**
 * Seconda metà del mese per votare, o ballottaggio aperto.
 * Se manca un sindaco, torna sempre true (elezioni straordinarie).
 */
function VoteTime(int $city): bool
{
    if(alphano_elections_ready()){ $round=alphano_elections_round($city);return $round&&in_array($round['civic_state'],['voting','runoff'],true)&&time()<strtotime($round['end_elezioni'].' UTC'); }
    $db = ConnectDB();
    $round = $db->query('SELECT start_elezioni,end_elezioni FROM storicoelezioni WHERE city_id=? AND winner_id IS NULL ORDER BY id DESC LIMIT 1', [$city])[0] ?? null;
    if ($round) return time() >= strtotime($round['start_elezioni']) && time() < strtotime($round['end_elezioni']);
    $row = $db->query('SELECT elezioni,electiontime,ballottaggio FROM city WHERE id=?', [$city])[0] ?? [];
    return !empty($row['elezioni']) && (!empty($row['ballottaggio']) || ((int)date('n') % 4 === (int)($row['electiontime'] ?? -1) && (int)date('j') > 15));
}

/**
 * Inserisce un candidato, aggiorna budget di org e città.
 * Restituisce true o stringa di errore.
 */
function InserisciCandidato(int $budget, int $costo, int $pg, int $city, string $programma, int $org): true|string
{
    if(alphano_elections_ready()){if((int)(alphano_user()['id']??0)!==$pg)return 'La candidatura deve essere confermata personalmente dal cittadino.';try{alphano_elections_candidate($city,$pg,$programma);return true;}catch(DomainException $e){return $e->getMessage();}}
    if ($costo < 0 || trim($programma) === '' || !ElectionTime($city)) return 'Candidature non aperte o parametri non validi';
    $db = ConnectDB();
    $pdo = $db->getConnection();
    try {
        $pdo->beginTransaction();
        $current = $db->query('SELECT budget FROM organizzazioni WHERE id=? FOR UPDATE', [$org])[0] ?? null;
        $member = $db->query('SELECT u.id FROM utenti u JOIN araldiche a ON a.id=u.araldica WHERE u.id=? AND u.organizzazione=? AND a.city=?', [$pg,$org,$city]);
        if (!$current || !$member || (int)$current['budget'] < $costo || $db->query('SELECT 1 FROM candidati WHERE idpg=?', [$pg])) {
            $pdo->rollBack();
            return 'Candidato non valido, già presente o fondi insufficienti';
        }
        $db->execute('INSERT INTO candidati(idpg,organizzazione,city,programma) VALUES(?,?,?,?)', [$pg,$org,$city,$programma]);
        $db->execute('UPDATE organizzazioni SET budget=budget-? WHERE id=?', [$costo,$org]);
        $db->execute('UPDATE city SET budget=budget+? WHERE id=?', [$costo,$city]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[elezioni] '.$e->getMessage());
        return 'Errore nell’inserimento del candidato';
    }
}

/**
 * TRUE se il PG non è già candidato e non ha già votato.
 */
function CanVote(int $idpg): bool
{
    if(alphano_elections_ready()){ $city=alphano_elections_city($idpg);return $city>0&&(bool)alphano_elections_state($city,$idpg)['can_vote']; }
    return !ConnectDB()->query('SELECT 1 FROM elezioni WHERE idvotante=? LIMIT 1', [$idpg]);
}

/**
 * Restituisce votazioni aggregate:
 * [ idpg => [ 'voti'=>…, 'id'=>…, 'nome'=>…, 'idorg'=>…, 'nomeorg'=>… ], … ]
 */
function GetVotazioni(int $city): array
{
    if(alphano_elections_ready()){ $round=alphano_elections_round($city);$out=[];foreach($round?alphano_elections_candidates($round):[] as $r)$out[$r['id']]=['id'=>$r['id'],'nome'=>$r['name'],'voti'=>$r['votes'],'idorg'=>$r['idorg'],'nomeorg'=>$r['organization']];return $out; }
    $rows = ConnectDB()->query('SELECT c.idpg AS id,u.nome,c.organizzazione AS idorg,org.nome AS nomeorg,COUNT(DISTINCT e.idvotante) AS voti FROM candidati c JOIN utenti u ON u.id=c.idpg LEFT JOIN organizzazioni org ON org.id=c.organizzazione LEFT JOIN elezioni e ON e.idcandidato=c.idpg WHERE c.city=? GROUP BY c.idpg,u.nome,c.organizzazione,org.nome ORDER BY u.nome', [$city]);
    $out=[];
    foreach ($rows as $row) {
        $row['id']=(int)$row['id']; $row['voti']=(int)$row['voti']; $row['idorg']=(int)$row['idorg'];
        $out[$row['id']]=$row;
    }
    return $out;
}


/**
 * Restituisce lista candidati:
 * [ idpg => [ 'id'=>…, 'nome'=>…, 'nomeorg'=>… ], … ]
 */
function GetCandidati(int $city): array
{
    if(alphano_elections_ready()){ $out=[];foreach(GetVotazioni($city) as $r)$out[$r['id']]=['id'=>$r['id'],'nome'=>$r['nome'],'nomeorg'=>$r['nomeorg']];return $out; }
    $db = ConnectDB();
    $rows = $db->query(
        'SELECT u.id, u.nome, org.nome AS nomeorg
           FROM candidati c
     JOIN utenti u           ON u.id = c.idpg
      LEFT JOIN organizzazioni org ON org.id = c.organizzazione
          WHERE c.city = ?
       ORDER BY u.nome',
        [$city]
    );

    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $out[$id] = [
            'id'      => $id,
            'nome'    => $r['nome'],
            'nomeorg' => $r['nomeorg'],
        ];
    }
    return $out;
}

/**
 * Restituisce array programmi:
 * [ idpg => 'programma', … ]
 */
function GetProgrammi(int $city): array
{
    if(alphano_elections_ready()){ $round=alphano_elections_round($city);$out=[];foreach($round?alphano_elections_candidates($round):[] as $r)$out[$r['id']]=$r['program'];return $out; }
    $db = ConnectDB();
    $rows = $db->query(
        'SELECT idpg, programma FROM candidati WHERE city = ?',
        [$city]
    );

    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['idpg']] = $r['programma'];
    }
    return $out;
}

/**
 * Apre il ballottaggio:
 * - Cancella tutte le votazioni esistenti
 * - Filtra candidati esterni alla lista
 * - Imposta city.ballottaggio = 1
 */
function ApriBallottaggio(int $city, array $candidati): void
{
    $db = ConnectDB();

    // Cancella votazioni esistenti
    $ids = array_column(
        $db->query('SELECT idpg FROM candidati WHERE city = ?', [$city]),
        'idpg'
    );
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $db->execute("DELETE FROM elezioni WHERE idcandidato IN ($ph)", $ids);
    }

    // Rimuovi candidati non in shortlist
    if ($candidati) {
        $keep   = array_map('intval', $candidati);
        $ph2    = implode(',', array_fill(0, count($keep), '?'));
        $params = array_merge([$city], $keep);
        $db->execute("DELETE FROM candidati WHERE city = ? AND idpg NOT IN ($ph2)", $params);
    }

    // Segna ballottaggio aperto
    $db->execute('UPDATE city SET ballottaggio = 1 WHERE id = ?', [$city]);
}

/**
 * Elegg il nuovo sindaco:
 * - Reset sindaco per tutte le araldiche fornite
 * - Imposta il nuovo sindaco
 * - Aggiorna city.organizzazione, chiude ballottaggio
 * - Pulisci candidati ed elezioni
 */
function Eleggi(int $city, int $sindaco, int $org, array $cariche): void
{
    $db = ConnectDB();

    require_once __DIR__.'/admin-governance.inc.php';
    alphano_governance_retire_mayors(0,$sindaco,$city,'Esito elezioni');
    $winner=$db->query('SELECT * FROM utenti WHERE id=? FOR UPDATE',[$sindaco])[0]??null;
    if(!$winner)throw new DomainException('Vincitore non disponibile.',409);
    $before=alphano_governance_state($winner);$after=$before;$after['sindaco']=1;$after['araldica']=(int)alphano_governance_city_role($city,true)['id'];
    $db->execute('UPDATE utenti SET sindaco=1,araldica=? WHERE id=?',[$after['araldica'],$sindaco]);
    alphano_governance_audit(0,$sindaco,$before,$after,'Nomina alla carica apicale dopo le elezioni');

    // Aggiorna city e chiudi ballottaggio
    $db->execute('UPDATE city SET organizzazione = ?, ballottaggio = 0 WHERE id = ?', [$org, $city]);

    // Cancella candidati ed elezioni precedenti
    $ids = array_column(
        $db->query('SELECT idpg FROM candidati WHERE city = ?', [$city]),
        'idpg'
    );
    if ($ids) {
        $ph3 = implode(',', array_fill(0, count($ids), '?'));
        $db->execute('DELETE FROM candidati WHERE city = ?', [$city]);
        $db->execute("DELETE FROM elezioni WHERE idcandidato IN ($ph3)", $ids);
    }
}
