<?php
// What is waiting for an admin to look at, and where to find it. One place for the counts behind the
// dashboard's "Waiting for review" cards, the navbar badges and the church pickers, so they always agree
// with what the lists show.
//   Sacraments -> sacrament-review.php (Confession bookings are confirmed automatically, so never counted)
//   Pastoral   -> pastoral-manage.php
//   Donations  -> donation-manage.php

const REVIEW_QUEUES = [
    'sacraments' => ['sql' => "SELECT church_id, COUNT(*) FROM sacrament_applications WHERE status = 'submitted' AND sacrament <> 'confession' GROUP BY church_id",
                     'page' => 'sacrament-review.php', 'status' => 'submitted'],
    'pastoral'   => ['sql' => "SELECT church_id, COUNT(*) FROM pastoral_requests WHERE status = 'submitted' GROUP BY church_id",
                     'page' => 'pastoral-manage.php', 'status' => 'submitted'],
    'donations'  => ['sql' => "SELECT church_id, COUNT(*) FROM donations WHERE status = 'pending' GROUP BY church_id",
                     'page' => 'donation-manage.php', 'status' => 'pending'],
];

/** church_id => number waiting, for one queue (every church). */
function review_by_church(PDO $pdo, string $queue): array
{
    static $cache = [];
    if (isset($cache[$queue])) return $cache[$queue];
    try {
        $rows = $pdo->query(REVIEW_QUEUES[$queue]['sql'])->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $ex) {
        $rows = [];   // table not created yet
    }
    return $cache[$queue] = array_map('intval', $rows);
}

/** How many are waiting in a queue: for one church, or every church when $churchId is null. */
function review_count(PDO $pdo, string $queue, ?int $churchId): int
{
    $by = review_by_church($pdo, $queue);
    return $churchId === null ? array_sum($by) : ($by[$churchId] ?? 0);
}

/**
 * Link (from the site root) to the waiting items of a queue, already filtered to them.
 * The Super Admin is taken to the church with the most waiting.
 */
function review_link(PDO $pdo, string $queue, ?int $churchId): string
{
    $q = REVIEW_QUEUES[$queue];
    $params = ['status' => $q['status']];
    if ($churchId === null) {
        $by = review_by_church($pdo, $queue);
        arsort($by);
        if ($by) $params = ['church' => (int)array_key_first($by)] + $params;
    }
    return $q['page'] . '?' . http_build_query($params);
}

/** The Super Admin's church to show first when none was picked: the one with the most waiting (0 = no preference). */
function review_busiest_church(PDO $pdo, string $queue): int
{
    $by = review_by_church($pdo, $queue);
    arsort($by);
    return $by ? (int)array_key_first($by) : 0;
}

/** Text for a church picker option: "St Simon Church — 2 waiting". */
function review_option_label(PDO $pdo, string $queue, int $churchId, string $name): string
{
    $n = review_count($pdo, $queue, $churchId);
    return $n ? $name . ' — ' . $n . ' waiting' : $name;
}
