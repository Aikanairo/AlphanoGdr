<?php
declare(strict_types=1);

// variabile globale per calcolare il tempo di esecuzione degli script
if (!defined('script_start_timestamp')) define('script_start_timestamp', microtime(true));

// Configurazione e servizi comuni
require_once __DIR__ . '/app.inc.php';

// valori massimi ammessi
define('maxmissive', 50);
define('maxpuntif', 1000);
define('numerorighe', 20);
define('tempomax', 900);
define('mintimelican', 7200);
define('minactionslican', 20);
define('maxobjinaccademia', 5);
define('maxpointvalue', 8);

// dimensione massima dei file da caricare
$size1 = return_bytes(ini_get('upload_max_filesize'));
$size2 = return_bytes(ini_get('post_max_size'));
define('MaxUploadFileSize', min($size1, $size2));
unset($size1, $size2);

// titolo e percorsi
define('TitoloSito', (string)alphano_config('game_name'));
define('htmlroot', alphano_config('storage_root') ?: dirname(__DIR__));
define('site_domain', alphano_config('base_url'));
define('admin_passwd', '');
define('ADMIN_PASSWD', '');

/**
 * Connessione al database con PDO (PHP 8+).
 */
function ConnectDB(): \Alphano\Database\PDOMySQLDriver
{
    static $connection;
    if ($connection === null) {
        require_once __DIR__ . '/mysql.php';
        $connection = new \Alphano\Database\PDOMySQLDriver(
            host: alphano_config('db_host'), db: alphano_config('db_db'),
            user: alphano_config('db_user'), pass: alphano_config('db_pass'), port: alphano_config('db_port'), socket: alphano_config('db_socket')
        );
    }
    return $connection;
}

/**
 * Restituisce i byte corrispondenti a "2M", "512K"...
 */
function return_bytes(string $val): int
{
    $val  = trim($val);
    $last = strtolower(substr($val, -1));
    $num  = (int)$val;
    return match ($last) {
        'g' => $num * 1024 ** 3,
        'm' => $num * 1024 ** 2,
        'k' => $num * 1024,
        default => $num,
    };
}

/**
 * Stampa in pagina il tempo di esecuzione (solo debug).
 */
function ClosePage(): void
{
    if (str_contains(strtolower((string)($GLOBALS['alphano_response_type'] ?? '')), 'json')) return;
    foreach (headers_list() as $header) {
        if (stripos($header, 'Content-Type: application/json') === 0) return;
    }
    if (alphano_config('debug')) echo '<!-- Script eseguito in ' . (microtime(true) - script_start_timestamp) . ' secondi -->';
}

// fallback per mime_content_type
if (!function_exists('mime_content_type')) {
    function mime_content_type(string $f): string
    {
        return (new finfo(FILEINFO_MIME_TYPE))->file($f) ?: 'application/octet-stream';
    }
}

/**
 * Restituisce l’attributo type per gli <input> file, se significativo.
 */
function GetMIME(string $file): string
{
    $no = ['text/plain','application/octet-stream','httpd/unix-directory'];
    if (file_exists(htmlroot . $file)) {
        $file = htmlroot . $file;
    }
    $mime = @mime_content_type($file) ?: '';
    return (strlen($mime) && !in_array($mime, $no, true))
        ? " type=\"{$mime}\""
        : '';
}

/**
 * Genera una password casuale usando il generatore crittografico.
 */
function GeneratePassword(string $string): string
{
    return bin2hex(random_bytes(16));
}

/**
 * Timestamp di N giorni fa alle 00:00.
 */
function GeneratePastTimestamp(int $daysago): int
{
    return mktime(0,0,0,(int)date('n'),(int)date('j')-$daysago,(int)date('Y'));
}

/**
 * Formatta un timestamp in “gg-mm-YYYY hh:ii:ss”.
 */
function FormatTimestamp(int $time, string $alt = ''): string
{
    if ($time <= 0 && $alt !== '') {
        return $alt;
    }
    $day = date('d-m-Y',$time);
    $hour = date('H:i:s',$time);
    return ($hour === '00:00:00') ? $day : "{$day} {$hour}";
}

/**
 * Converte "\n" in "<BR>".
 */
function Gnl2br(string $str): string
{
    return str_replace("\n", '<BR>', str_replace("\r", '', $str));
}

/**
 * Converte "\n" in literal "\n".
 */
function Gnl2n(string $str): string
{
    return str_replace("\n", '\n', str_replace("\r", '', $str));
}

/**
 * Diff testuale tra due stringhe, restituisce array strutturato.
 */
function Alphano_diff(string $text1, string $text2): array
{
    if($text1===$text2)return [];
    $encode=static fn(string $text)=>array_map(static fn($line)=>implode(';',array_values(unpack('C*',$line)?:[])),explode("\n",$text));
    return [[['da'=>1,'a'=>count(explode("\n",$text1)),'text'=>$encode($text1)],['da'=>1,'a'=>count(explode("\n",$text2)),'text'=>$encode($text2)]]];
}

// La cornice gestisce gli ingressi diretti; i controller nei frame eseguono una sola volta.
alphano_route_document();
