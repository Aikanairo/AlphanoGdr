<?php declare(strict_types=1);

/**
 * Restituisce il giorno giuliano (JD) in UT per un timestamp Unix.
 */
function julianDayUtc(int $ts): float
{
    $dt = gmdate('Y-m-d H:i:s', $ts);
    [$Y, $M, $D, $h, $m, $s] = preg_split('/[ \-:]/', $dt);
    if ($M <= 2) { $Y--; $M += 12; }
    $A = floor($Y / 100);
    $B = 2 - $A + floor($A / 4);
    $day = $D + ($h / 24) + ($m / 1440) + ($s / 86400);
    return floor(365.25 * ($Y + 4716)) + floor(30.6001 * ($M + 1)) + $day + $B - 1524.5;
}

/**
 * Prossimo plenilunio dopo $ts (Unix UTC) → timestamp Unix UTC.
 */
function NextFullMoon(int $ts): int
{
    $JD = julianDayUtc($ts);
    // riferimento: 2451550.1 = plenilunio maggio 2000
    $k   = floor(($JD - 2451550.1) / 29.530588861) + 1;
    $JDE = 2451550.1 + 29.530588861 * $k;
    return (int)round(($JDE - 2440587.5) * 86400);
}

/**
 * Restituisce il timestamp Unix (UTC) dell'ultimo plenilunio prima di $ts.
 */
function PrevFullMoon(int $ts): int
{
    $JD  = julianDayUtc($ts);
    $syn = 29.530588861;
    $k = floor(($JD - 2451550.1) / $syn);
    $JDE = 2451550.1 + $syn * $k;
    return (int)round(($JDE - 2440587.5) * 86400);
}

/**
 * Ritorna il timestamp Unix (UTC) di fine “full-phase” (Luna Piena),
 * definita quando il giorno lunare supera il 21° giorno lunare (Luna Calante).
 */
function EndFullMoon(int $ts): int
{
    $prev = PrevFullMoon($ts);
    $syn  = 29.530588861;
    // la Luna Piena dura 22 giorni, il giorno di fine full-phase
    $offset = ($syn * (22 / 30)) * 86400;
    return $prev + (int)round($offset);
}

/**
 * Calcola il giorno lunare (1–30) approssimato.
 */
function GetFaseLunare(): int
{
    $now    = time();
    $tsNext = NextFullMoon($now);
    $syn    = 29.530588861;
    // ultimo plenilunio
    $tsPrev = $tsNext - $syn * 86400;
    $age    = ($now - $tsPrev) / ($syn * 86400);
    return max(1, min(30, (int)floor($age * 30) + 1));
}

/**
 * Descrizione testuale della fase lunare.
 */
function FaseLunare(): string
{
    $f = GetFaseLunare();
    if ($f < 7)   return 'Luna Nuova';
    if ($f < 14)  return 'Luna Crescente';
    if ($f < 22)  return 'Luna Piena';
    return 'Luna Calante';
}

/**
 * Giorni interi mancanti al prossimo plenilunio.
 */
function DaysToFullMoon(): int
{
    if (FaseLunare() === 'Luna Piena') {
        return 0; // Se siamo in Luna Piena, non mancano giorni.
    }
    $now    = time();
    $tsNext = NextFullMoon($now);
    $diff   = $tsNext - $now;
    return (int)ceil(max(0, $diff) / 86400);
}

/**
 * Restituisce il tempo rimanente fino al prossimo plenilunio come array associativo.
 */
function TimeToFullMoon(): array
{
    if (FaseLunare() === 'Luna Piena') {
        return [
          'days'    => 0,
          'hours'   => 0,
          'minutes' => 0,
          'seconds' => 0,
        ];
    }

    $now    = time();
    $tsNext = NextFullMoon($now);
    $delta  = max(0, $tsNext - $now);

    $days    = floor($delta / 86400);
    $delta  -= $days * 86400;
    $hours   = floor($delta / 3600);
    $delta  -= $hours * 3600;
    $minutes = floor($delta / 60);
    $seconds = $delta - $minutes * 60;

    return [
      'days'    => $days,
      'hours'   => $hours,
      'minutes' => $minutes,
      'seconds' => $seconds,
    ];
}

/**
 * Durata della Luna Piena (da inizio a fine fase piena).
 */
function FullMoonDuration(): array
{
    $now   = time();
    $start = PrevFullMoon($now);
    $end   = EndFullMoon($now);
    $dur   = max(0, $end - $start);

    $days    = floor($dur / 86400);
    $dur    -= $days * 86400;
    $hours   = floor($dur / 3600);
    $dur    -= $hours * 3600;
    $minutes = floor($dur / 60);
    $seconds = $dur - $minutes * 60;

    return [
      'days'    => $days,
      'hours'   => $hours,
      'minutes' => $minutes,
      'seconds' => $seconds,
    ];
}

/**
 * Tempo rimanente fino alla fine della fase di Luna Piena.
 */
function TimeToEndFullMoon(): array
{
    if (FaseLunare() !== 'Luna Piena') {
        return ['days' => 0, 'hours' => 0, 'minutes' => 0, 'seconds' => 0];
    }

    $now = time();
    $end = EndFullMoon($now);
    $delta = max(0, $end - $now);

    $days    = floor($delta / 86400);
    $delta  -= $days * 86400;
    $hours   = floor($delta / 3600);
    $delta  -= $hours * 3600;
    $minutes = floor($delta / 60);
    $seconds = $delta - $minutes * 60;

    return [
      'days'    => $days,
      'hours'   => $hours,
      'minutes' => $minutes,
      'seconds' => $seconds,
    ];
}
