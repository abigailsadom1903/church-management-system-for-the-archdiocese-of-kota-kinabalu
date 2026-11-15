<?php
// The Church's liturgical calendar, worked out for any year: the main feasts and solemnities (fixed and
// movable) and the current season. Used by the Church Calendar to label days ("All Saints", "Advent I")
// and to show "Ordinary Time · Advent begins 29 Nov". Follows the General Roman Calendar.

/** Easter Sunday of $year (Gregorian computus), as 'Y-m-d'. */
function lit_easter(int $year): string
{
    $a = $year % 19; $b = intdiv($year, 100); $c = $year % 100;
    $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30; $i = intdiv($c, 4); $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31); $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

function lit_shift(string $date, int $days): string
{
    return date('Y-m-d', strtotime($date . ($days >= 0 ? ' +' : ' ') . $days . ' days'));
}

/** First Sunday of Advent of $year. */
function lit_advent1(int $year): string
{
    $christmas = "$year-12-25";
    $dow = (int)date('w', strtotime($christmas));          // 0 = Sunday
    return lit_shift($christmas, -($dow === 0 ? 7 : $dow) - 21);
}

/** Baptism of the Lord in $year (the Sunday after 6 January), which ends the Christmas season. */
function lit_baptism(int $year): string
{
    $dow = (int)date('w', strtotime("$year-01-06"));
    return lit_shift("$year-01-06", 7 - $dow);
}

/** Named days of $year: ['Y-m-d' => [name, rank]], rank 'solemnity' | 'feast' | 'season'. */
function lit_feasts(int $year): array
{
    static $cache = [];
    if (isset($cache[$year])) return $cache[$year];
    $y = $year;
    $f = [
        "$y-01-01" => ['Mary, Mother of God', 'solemnity'],
        "$y-02-02" => ['Presentation of the Lord', 'feast'],
        "$y-03-19" => ['St Joseph', 'solemnity'],
        "$y-03-25" => ['Annunciation', 'solemnity'],
        "$y-06-24" => ['Birth of St John the Baptist', 'solemnity'],
        "$y-06-29" => ['Sts Peter & Paul', 'solemnity'],
        "$y-08-06" => ['Transfiguration', 'feast'],
        "$y-08-15" => ['Assumption of Mary', 'solemnity'],
        "$y-09-08" => ['Birth of Mary', 'feast'],
        "$y-09-14" => ['Exaltation of the Cross', 'feast'],
        "$y-09-29" => ['Sts Michael, Gabriel & Raphael', 'feast'],
        "$y-10-07" => ['Our Lady of the Rosary', 'feast'],
        "$y-11-01" => ['All Saints', 'solemnity'],
        "$y-11-02" => ['All Souls', 'feast'],
        "$y-11-09" => ['Lateran Basilica', 'feast'],
        "$y-11-21" => ['Presentation of Mary', 'feast'],
        "$y-11-30" => ['St Andrew', 'feast'],
        "$y-12-08" => ['Immaculate Conception', 'solemnity'],
        "$y-12-25" => ['Christmas', 'solemnity'],
        "$y-12-26" => ['St Stephen', 'feast'],
        "$y-12-28" => ['Holy Innocents', 'feast'],
    ];
    // Epiphany is kept on the Sunday between 2 and 8 January (as in Malaysia).
    $jan2dow = (int)date('w', strtotime("$y-01-02"));
    $f[lit_shift("$y-01-02", (7 - $jan2dow) % 7)] = ['Epiphany', 'solemnity'];
    $f[lit_baptism($y)] = ['Baptism of the Lord', 'feast'];

    $easter = lit_easter($y);
    $f[lit_shift($easter, -46)] = ['Ash Wednesday', 'season'];
    $f[lit_shift($easter, -7)] = ['Palm Sunday', 'season'];
    $f[lit_shift($easter, -3)] = ['Holy Thursday', 'season'];
    $f[lit_shift($easter, -2)] = ['Good Friday', 'season'];
    $f[lit_shift($easter, -1)] = ['Holy Saturday', 'season'];
    $f[$easter] = ['Easter Sunday', 'solemnity'];
    $f[lit_shift($easter, 7)] = ['Divine Mercy Sunday', 'feast'];
    $f[lit_shift($easter, 39)] = ['Ascension', 'solemnity'];
    $f[lit_shift($easter, 49)] = ['Pentecost', 'solemnity'];
    $f[lit_shift($easter, 56)] = ['Holy Trinity', 'solemnity'];
    $f[lit_shift($easter, 63)] = ['Corpus Christi', 'solemnity'];
    $f[lit_shift($easter, 68)] = ['Sacred Heart', 'solemnity'];

    $adv = lit_advent1($y);
    $f[lit_shift($adv, -7)] = ['Christ the King', 'solemnity'];
    $f[$adv] = ['Advent I', 'season'];
    $f[lit_shift($adv, 7)] = ['Advent II', 'season'];
    $f[lit_shift($adv, 14)] = ['Advent III', 'season'];
    $f[lit_shift($adv, 21)] = ['Advent IV', 'season'];

    // Holy Family: the Sunday between 26 and 31 December, or 30 December when Christmas is a Sunday.
    $hf = null;
    for ($d = 26; $d <= 31; $d++) if ((int)date('w', strtotime("$y-12-$d")) === 0) $hf = "$y-12-$d";
    $f[$hf ?? "$y-12-30"] = ['Holy Family', 'feast'];

    ksort($f);
    return $cache[$year] = $f;
}

/** The feast / solemnity on $date, or null. */
function lit_feast(string $date): ?array
{
    return lit_feasts((int)substr($date, 0, 4))[$date] ?? null;
}

/**
 * The liturgical season on $date: ['name' => 'Ordinary Time', 'tone' => 'green', 'next' => 'Advent begins 29 Nov'].
 * tone is the season's colour: green, purple or white.
 */
function lit_season(string $date): array
{
    $y = (int)substr($date, 0, 4);
    $easter = lit_easter($y);
    $ash = lit_shift($easter, -46);
    $pentecost = lit_shift($easter, 49);
    $adv = lit_advent1($y);
    $baptism = lit_baptism($y);
    $short = fn(string $d) => date('j M', strtotime($d));

    if ($date >= $adv && $date < "$y-12-25") return ['name' => 'Advent', 'tone' => 'purple', 'next' => 'Christmas on 25 Dec'];
    if ($date >= "$y-12-25") return ['name' => 'Christmas', 'tone' => 'white', 'next' => 'until ' . $short(lit_baptism($y + 1))];
    if ($date <= $baptism) return ['name' => 'Christmas', 'tone' => 'white', 'next' => 'until ' . $short($baptism)];
    if ($date >= $ash && $date < lit_shift($easter, -3)) return ['name' => 'Lent', 'tone' => 'purple', 'next' => 'Easter on ' . $short($easter)];
    if ($date >= lit_shift($easter, -3) && $date < $easter) return ['name' => 'Easter Triduum', 'tone' => 'purple', 'next' => 'Easter on ' . $short($easter)];
    if ($date >= $easter && $date <= $pentecost) return ['name' => 'Easter', 'tone' => 'white', 'next' => 'Pentecost on ' . $short($pentecost)];
    if ($date < $ash) return ['name' => 'Ordinary Time', 'tone' => 'green', 'next' => 'Lent begins ' . $short($ash)];
    return ['name' => 'Ordinary Time', 'tone' => 'green', 'next' => 'Advent begins ' . $short($adv)];
}
