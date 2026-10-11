<?php
// Catechesis programs: categories, schedules, progress, and the registration & enrolment form.
// The form uses the same field definitions and checks as the sacrament forms (includes/sacrament_helpers.php).

const CAT_CATEGORIES = [
    // category => [gradient from, gradient to]   (used for the picture strip at the top of a program card)
    'Children & Youth'        => ['#2b1d4d', '#7a5fb0'],
    'Conversion'              => ['#3a1d3f', '#a0508f'],
    'Growth'                  => ['#1f2f57', '#5b7fc4'],
    'Sacramental Preparation' => ['#4a2a12', '#c98a3c'],
    'Other'                   => ['#2b3a3a', '#5f8f8a'],
];
const CAT_DAYS = ['Sundays', 'Mondays', 'Tuesdays', 'Wednesdays', 'Thursdays', 'Fridays', 'Saturdays'];
const CAT_DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

function cat_time(?string $t): string { return $t ? date('h:i A', strtotime($t)) : ''; }

/** "Sundays, 08:30 AM - 10:00 AM" or "Monthly, Last Saturday". */
function cat_schedule_text(array $p): string
{
    $range = $p['start_time'] ? cat_time($p['start_time']) . ($p['end_time'] ? ' - ' . cat_time($p['end_time']) : '') : '';
    if ($p['frequency'] === 'monthly_last') return 'Monthly, Last ' . CAT_DAY_NAMES[(int)$p['weekday']] . ($range ? ', ' . $range : '');
    return CAT_DAYS[(int)$p['weekday']] . ($range ? ', ' . $range : '');
}

/** The date (Y-m-d) of the next lesson on or after $from. */
function cat_next_lesson(array $p, ?string $from = null): string
{
    $from = $from ?? date('Y-m-d');
    $wd = (int)$p['weekday'];
    if ($p['frequency'] === 'monthly_last') {
        for ($i = 0; $i < 3; $i++) {
            $first = date('Y-m-01', strtotime($from . " +$i month"));
            $d = date('Y-m-t', strtotime($first));                       // last day of the month, then step back to the weekday
            while ((int)date('w', strtotime($d)) !== $wd) $d = date('Y-m-d', strtotime($d . ' -1 day'));
            if ($d >= $from) return $d;
        }
    }
    $d = $from;
    while ((int)date('w', strtotime($d)) !== $wd) $d = date('Y-m-d', strtotime($d . ' +1 day'));
    return $d;
}

/** Curriculum progress in percent: lessons attended out of the programme's total (a completed enrolment is 100). */
function cat_progress(int $attendance, int $total, string $status = 'active'): int
{
    if ($status === 'completed') return 100;
    return $total > 0 ? min(100, (int)round($attendance / $total * 100)) : 0;
}

// ---- Attendance by date ------------------------------------------------------------------------------
// Each lesson date is marked present or absent (catechesis_attendance). The enrolment's `attendance` number,
// which progress is worked out from, is kept equal to: lessons counted before dates were kept + the "present" dates.

/** The dated marks of these enrolments, newest first: [enrolment_id => [['session_date' => 'Y-m-d', 'status' => 'present'|'absent'], ...]] */
function cat_attendance_rows(PDO $pdo, array $enrolmentIds): array
{
    $ids = array_values(array_unique(array_map('intval', $enrolmentIds)));
    if (!$ids) return [];
    try {
        $stmt = $pdo->prepare('SELECT enrolment_id, session_date, status FROM catechesis_attendance WHERE enrolment_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY session_date DESC');
        $stmt->execute($ids);
    } catch (PDOException $ex) {
        return [];   // table not created yet (database/catechesis_attendance.sql)
    }
    $out = [];
    foreach ($stmt as $r) $out[(int)$r['enrolment_id']][] = ['session_date' => $r['session_date'], 'status' => $r['status']];
    return $out;
}

/** [present, absent] counts of one enrolment's dated marks. */
function cat_attendance_counts(array $rows): array
{
    $present = count(array_filter($rows, fn($r) => $r['status'] === 'present'));
    return [$present, count($rows) - $present];
}

/** Marks one lesson date present or absent ($status), or takes the mark off again ($status = 'remove'), and updates the count. */
function cat_attendance_mark(PDO $pdo, int $enrolmentId, string $date, string $status, int $adminId): void
{
    if ($status === 'remove') {
        $pdo->prepare('DELETE FROM catechesis_attendance WHERE enrolment_id = ? AND session_date = ?')->execute([$enrolmentId, $date]);
    } else {
        $pdo->prepare('INSERT INTO catechesis_attendance (enrolment_id, session_date, status, marked_by) VALUES (?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE status = VALUES(status), marked_by = VALUES(marked_by)')
            ->execute([$enrolmentId, $date, $status === 'present' ? 'present' : 'absent', $adminId]);
    }
    $pdo->prepare("UPDATE catechesis_enrolments
                      SET attendance = attendance_undated + (SELECT COUNT(*) FROM catechesis_attendance WHERE enrolment_id = ? AND status = 'present')
                    WHERE enrolment_id = ?")->execute([$enrolmentId, $enrolmentId]);
}

/** The list of dated marks, as shown to the admin (with a remove button when $removeForm is given) and to the parishioner. */
function cat_attendance_list(array $rows, int $undated, ?callable $removeForm = null): string
{
    if (!$rows && !$undated) return '<p class="cat-att-empty">No attendance has been recorded yet.</p>';
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $out = '<ul class="cat-att-list">';
    foreach ($rows as $r) {
        $present = $r['status'] === 'present';
        $out .= '<li class="' . ($present ? 'is-present' : 'is-absent') . '">'
              . '<span class="cat-att-date">' . $h(date('D, j M Y', strtotime($r['session_date']))) . '</span>'
              . '<span class="cat-att-mark">' . ($present ? 'Present' : 'Absent') . '</span>'
              . ($removeForm ? $removeForm($r['session_date']) : '')
              . '</li>';
    }
    if ($undated) {
        $out .= '<li class="is-undated"><span class="cat-att-date">Earlier lessons (no date kept)</span><span class="cat-att-mark">' . $undated . ' present</span></li>';
    }
    return $out . '</ul>';
}

/** [label, css class] for an enrolment status. */
function cat_status(string $s): array
{
    return [
        'draft'     => ['Draft', 'is-draft'],
        'active'    => ['Active', 'is-active'],
        'completed' => ['Completed', 'is-done'],
        'dropped'   => ['Dropped', 'is-dropped'],
    ][$s] ?? [ucfirst($s), 'is-draft'];
}

function cat_gradient(string $category): string
{
    [$a, $b] = CAT_CATEGORIES[$category] ?? CAT_CATEGORIES['Other'];
    return 'linear-gradient(135deg, ' . $a . ', ' . $b . ')';
}

/** Web path of a program's cover photo, or null. Only file names we generated are accepted. */
function cat_photo_url(?string $file): ?string
{
    if (!$file || !preg_match('/^[\w.-]+$/', $file)) return null;
    return is_file(dirname(__DIR__) . '/uploads/catechesis_photos/' . $file) ? 'uploads/catechesis_photos/' . $file : null;
}

function cat_delete_photo(?string $file): void
{
    if ($file && preg_match('/^[\w.-]+$/', $file)) @unlink(dirname(__DIR__) . '/uploads/catechesis_photos/' . $file);
}

/** The top of a program card: its photo (darkened at the bottom so the category stays readable) or the category's colours. */
function cat_cover_style(array $p): string
{
    $photo = cat_photo_url($p['photo'] ?? null);
    return $photo
        ? "background: linear-gradient(to top, rgba(0, 0, 0, .55), rgba(0, 0, 0, 0) 60%), url('$photo') center / cover"
        : 'background: ' . cat_gradient($p['category']);
}

/** The registration & enrolment form, in the same shape as sx_config(). */
function cat_config(): array
{
    $f = fn(string $k, string $l, string $t = 'text', bool $r = true, array $x = []) => ['k' => $k, 'l' => $l, 't' => $t, 'r' => $r] + $x;
    return [
        'title' => 'Catechesis Registration and Enrolment Form', 'kind' => 'form', 'side' => null, 'intro' => '',
        'sections' => [
            ['title' => 'Student Information', 'fields' => [
                $f('full_name', 'Full Name'), $f('gender', 'Gender', 'radio', true, ['o' => ['male' => 'Male', 'female' => 'Female']]),
                $f('email', 'Email Address', 'email'), $f('phone', 'Phone Number', 'tel'),
                $f('address', 'Home Address', 'textarea'), $f('ic', 'IC/Passport No', 'text', true, ['max' => 30]),
                $f('dob', 'Date of Birth', 'date', true, ['past' => true]),
            ]],
            ['title' => 'Guardian Information', 'note' => 'Required when the student is under 18.', 'fields' => [
                $f('g_name', 'Full Name', 'text', false), $f('g_phone', 'Phone Number', 'tel', false),
                $f('g_relationship', 'Relationship to Student', 'text', false),
            ]],
            ['title' => 'Documents Upload', 'docs' => ['baptism_cert', 'communion_cert', 'ic_copy']],
        ],
        'docs' => [
            'baptism_cert'   => ["Child's Baptismal Certificate", 'Required to verify sacramental eligibility', false, 'church', true],
            'communion_cert' => ['First Holy Communion Certificate', 'Only required for Confirmation applicants', false, 'host', true],
            'ic_copy'        => ['IC / Passport Copy', 'For identity verification purposes', true, 'id', true],
        ],
    ];
}

/** Age in whole years on a Y-m-d birth date. */
function cat_age(string $dob): int
{
    $d = DateTime::createFromFormat('Y-m-d', $dob);
    return $d ? (int)$d->diff(new DateTime('today'))->y : 99;
}

function cat_icon(string $name, int $size = 16): string
{
    $p = [
        'calendar' => '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/>',
        'clock'    => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'pin'      => '<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
        'mail'     => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
        'minus'    => '<path d="M5 12h14"/>',
        'plus'     => '<path d="M12 5v14"/><path d="M5 12h14"/>',
        'pencil'   => '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/>',
        'trash'    => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'arrow'    => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'users'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'check'    => '<path d="M20 6 9 17l-5-5"/>',
    ];
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}
