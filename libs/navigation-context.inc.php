<?php
declare(strict_types=1);

/** Remember only a scene already authorized by the chat controller, per player. */
function alphano_navigation_remember_scene(int $player, string $chat): void
{
    $_SESSION['navigation_scene'] = ['player_id'=>$player, 'chat'=>$chat];
}

/** The shortcut is personal. Room grants and session state are checked afresh. */
function alphano_navigation_recent_scene(array $user): ?array
{
    $player = (int)$user['id'];
    $memory = $_SESSION['navigation_scene'] ?? [];
    $chat = (int)($memory['player_id'] ?? 0) === $player
        ? (string)($memory['chat'] ?? '') : (string)($user['chat'] ?? '');
    if ($chat === '') return null;

    $db = ConnectDB();
    $room = $db->query('SELECT chat_key,title,position FROM mappe WHERE chat_key=? AND chat=1', [$chat])[0] ?? null;
    if (!$room) {
        require_once __DIR__.'/pvt/private-core.inc.php';
        try { $room = alphano_private_room($chat, $player); }
        catch (DomainException) { $room = null; }
    }
    if (!$room) return null;

    // Reuse the territory resolver used by the game, including private room hosts.
    require_once __DIR__.'/ai.inc.php';
    $territory = ai_authority_place((string)($room['rental_host'] ?? $chat));
    $city = (string)$territory['city'];
    if ($city === '' && isset($room['private_slug'])) $city = alphano_private_city($room['private_slug'])['city'];
    $title = trim((string)$room['title']);
    if ($city !== '') {
        $name = preg_quote($city, '/');
        $title = preg_replace(['/\s+[-–—]\s+'.$name.'$/iu', '/^'.$name.'\s+[-–—]\s+/iu'], '', $title) ?? $title;
    }
    $session = $db->query('SELECT nome FROM sessioni WHERE chat=? AND run=1 ORDER BY id DESC LIMIT 1', [$chat])[0] ?? null;
    return [
        'key'=>$chat, 'title'=>$title, 'city'=>$city,
        'url'=>'/chat/index.php?chat='.rawurlencode($chat),
        'current'=>($user['chat'] ?? '') === $chat && !in_array($user['posizione'] ?? '', ['', 'online', 'off'], true),
        'session_active'=>$session !== null, 'session_title'=>$session['nome'] ?? '',
    ];
}
