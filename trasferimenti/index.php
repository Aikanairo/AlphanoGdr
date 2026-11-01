<?php
declare(strict_types=1);

// ─── 1) BOOTSTRAP ──────────────────────────────────────────────────────────
require_once __DIR__ . '/../libs/kernel.inc.php';
require_once __DIR__ . '/../libs/libpg.inc.php';

// ─── 2) AUTENTICAZIONE ────────────────────────────────────────────────────
$idpg = GetIDPG(false);
if ($idpg === null) {
    header('Location: /login.php');
    exit;
}

// ─── 3) CARICO SALDO E CONTROLLI ──────────────────────────────────────────
$db = getDB();
$user = $db->query(
    'SELECT nome, denari, razza, admin
       FROM utenti
      WHERE id = ?',
    [$idpg]
)[0] ?? null;

if (!$user) {
    die('Utente non trovato.');
}
if (in_array((int)$user['razza'], [ALPHANO_RACE_SPIRIT,ALPHANO_RACE_DEAD_VAMPIRE,ALPHANO_RACE_LEGACY_SPIRIT], true)) {
    http_response_code(403);
    die('Non puoi amministrare i tuoi soldi quando sei morto.');
}

$balance = (int)$user['denari'];
$pgname  = htmlspecialchars($user['nome'], ENT_QUOTES, 'UTF-8');
$isAdmin = (bool)$user['admin'];

?><!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Trasferimenti – <?= $pgname ?></title>

  <script src="/js/global.js" defer></script>
  <script src="/js/trasferimenti.js" defer></script>

</head>
<body>

  <!-- 4.1) SALDO -->
  <h2><?=alphano_currency_html()?> in tuo possesso: <strong id="transfer-balance"><?= $balance ?></strong></h2>

  <!-- 4.2) NAV TABS -->
  <div class="tabs">
    <button id="mmonete"    class="tab-btn active">Trasferimento Denaro</button>
    <button id="mdonazione" class="tab-btn">Donazione</button>
    <button id="mtrasf"     class="tab-btn">Stato Trasferimenti</button>
    <button id="mcronobj"   class="tab-btn">Cronologia Oggetti</button>
    <button id="mcronmoney" class="tab-btn">Cronologia Denaro</button>
  </div>

  <!-- 4.3) CONTAINERS -->
  <section id="monete"    class="active"></section>
  <section id="donazione" ></section>

  <!-- qui va tutto: contrattazioni, ordini client, ordini presi, magazzino, consegne -->
  <section id="trasf"     ></section>

  <!-- qui va solo lo storico acquisti (cronologia oggetti) -->
  <section id="cronobj"   ></section>

  <!-- qui va solo la cronologia dei movimenti di denaro -->
  <section id="cronmoney" ></section>

</body>
</html>
