<?php
declare(strict_types=1);

const ALPHANO_IMPORT_MAX_BYTES = 16777216;

function GetXMLFromArray($array, $area = '')
{
    if (!is_array($array)) return false;
    $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n<FIELDSET area=\""
        . htmlspecialchars((string)$area, ENT_QUOTES | ENT_XML1, 'UTF-8') . '" count="' . count($array) . '">' . "\n";
    foreach ($array as $key => $value) {
        $value = serialize($value);
        $out .= '<FIELD name="' . htmlspecialchars((string)$key, ENT_QUOTES | ENT_XML1, 'UTF-8')
            . '" ctrl="' . md5(md5($value)) . '">' . base64_encode($value) . "</FIELD>\n";
    }
    return $out . '</FIELDSET>';
}

function PrintBZXMLFromArray($array, $area = '')
{
    $xml = GetXMLFromArray($array, $area);
    if ($xml === false) return false;
    $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$area) ?: 'alphano';
    header('Content-Type: application/xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.alphano"');
    // Portable XML needs no optional compression extension on shared hosting.
    exit($xml);
}

function alphano_import_value_allowed(mixed $value, int $depth = 0): bool
{
    if ($depth > 64 || is_object($value) || is_resource($value)) return false;
    if (is_array($value)) {
        if (count($value) > 100000) return false;
        foreach ($value as $child) if (!alphano_import_value_allowed($child, $depth + 1)) return false;
    }
    return true;
}

function GetArrayFromXML($xml, $area = '')
{
    if (!is_string($xml) || strlen($xml) > ALPHANO_IMPORT_MAX_BYTES || preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $xml)) return false;
    $previous = libxml_use_internal_errors(true);
    $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors(); libxml_use_internal_errors($previous);
    if (!$document || $document->getName() !== 'FIELDSET' || ((string)$document['area'] !== '' && (string)$document['area'] !== (string)$area)
        || count($document->FIELD) !== (int)$document['count'] || count($document->children()) !== count($document->FIELD)) return false;
    $out = [];
    foreach ($document->FIELD as $field) {
        $name = (string)$field['name'];
        if (array_key_exists($name, $out) || strlen($name) > 255) return false;
        $serialized = base64_decode((string)$field, true);
        if ($serialized === false || !hash_equals(md5(md5($serialized)), (string)$field['ctrl'])) return false;
        set_error_handler(static function ($severity, $message) { throw new UnexpectedValueException($message); });
        try { $value = unserialize($serialized, ['allowed_classes' => false, 'max_depth' => 64]); }
        catch (Throwable) { restore_error_handler(); return false; }
        restore_error_handler();
        if (!alphano_import_value_allowed($value)) return false;
        $out[$name] = $value;
    }
    return $out;
}

function GetArrayFromBZXML($xml, $area = '')
{
    if (!is_string($xml) || strlen($xml) > ALPHANO_IMPORT_MAX_BYTES) return false;
    if (str_starts_with(ltrim($xml), '<?xml') || str_starts_with(ltrim($xml), '<FIELDSET')) return GetArrayFromXML($xml, $area);
    if (!str_starts_with($xml, 'BZh') || !function_exists('bzopen')) return false;
    $path = tempnam(sys_get_temp_dir(), 'alphano-import-');
    if ($path === false) return false;
    $stream = false;
    try {
        file_put_contents($path, $xml);
        $stream = bzopen($path, 'r');
        if ($stream === false) return false;
        $decoded = '';
        while (!feof($stream)) {
            $chunk = bzread($stream, 8192);
            if ($chunk === false || bzerrno($stream) !== 0) return false;
            if ($chunk === '') break;
            $decoded .= $chunk;
            if (strlen($decoded) > ALPHANO_IMPORT_MAX_BYTES) return false;
        }
        $decoded = base64_decode($decoded, true);
        return $decoded === false ? false : GetArrayFromXML($decoded, $area);
    } finally {
        if (is_resource($stream)) bzclose($stream);
        unlink($path);
    }
}
