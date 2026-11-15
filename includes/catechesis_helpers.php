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

// ---- Lesson materials ---------------------------------------------------------------------------------
// The Church Admin adds lessons to a program (catechesis_lessons): text, a video link and a handout. Students whose
// enrolment is active or completed can open the published ones and mark each as done (catechesis_lesson_progress).
// Handouts are private (uploads/catechesis_lessons/ is blocked) and only served by catechesis-lesson-file.php.

const CAT_LESSON_DIR = __DIR__ . '/../uploads/catechesis_lessons';
const CAT_LESSON_MAX_MB = 20;
// extension => [label, content types the file may really have (checked with finfo)]
const CAT_LESSON_TYPES = [
    'pdf'  => ['PDF', ['application/pdf']],
    'docx' => ['Word', ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream']],
    'pptx' => ['PowerPoint', ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream']],
    'jpg'  => ['Image', ['image/jpeg']],
    'png'  => ['Image', ['image/png']],
    'mp3'  => ['Audio', ['audio/mpeg', 'audio/mp3', 'application/octet-stream']],
];

/** The lessons of a program in order (only the published ones unless $all). Empty if the table is not created yet. */
function cat_lessons(PDO $pdo, int $programId, bool $all = false): array
{
    try {
        $stmt = $pdo->prepare('SELECT * FROM catechesis_lessons WHERE program_id = ?' . ($all ? '' : ' AND is_published = 1') . ' ORDER BY position, lesson_id');
        $stmt->execute([$programId]);
        return $stmt->fetchAll();
    } catch (PDOException $ex) {
        return [];   // database/catechesis_lessons.sql not run yet
    }
}

/** Lessons each enrolment has marked done: [enrolment_id => [lesson_id => completed_at]] */
function cat_lessons_done(PDO $pdo, array $enrolmentIds): array
{
    $ids = array_values(array_unique(array_map('intval', $enrolmentIds)));
    if (!$ids) return [];
    try {
        $stmt = $pdo->prepare('SELECT enrolment_id, lesson_id, completed_at FROM catechesis_lesson_progress WHERE enrolment_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute($ids);
    } catch (PDOException $ex) {
        return [];
    }
    $out = [];
    foreach ($stmt as $r) $out[(int)$r['enrolment_id']][(int)$r['lesson_id']] = $r['completed_at'];
    return $out;
}

/** Numbers the lessons of a program 1, 2, 3 ... in their current order (after a delete or a move). */
function cat_lessons_renumber(PDO $pdo, int $programId): void
{
    $stmt = $pdo->prepare('SELECT lesson_id FROM catechesis_lessons WHERE program_id = ? ORDER BY position, lesson_id');
    $stmt->execute([$programId]);
    $upd = $pdo->prepare('UPDATE catechesis_lessons SET position = ?, updated_at = updated_at WHERE lesson_id = ?');
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $i => $lid) $upd->execute([$i + 1, $lid]);
}

/** Full path of a stored handout, or null if the name is not one we generated or the file is gone. */
function cat_lesson_path(?string $file): ?string
{
    if (!$file || !preg_match('/^[a-f0-9]{24}\.(' . implode('|', array_keys(CAT_LESSON_TYPES)) . ')$/', $file)) return null;
    $path = CAT_LESSON_DIR . '/' . $file;
    return is_file($path) ? $path : null;
}

function cat_lesson_delete_file(?string $file): void
{
    if ($path = cat_lesson_path($file)) @unlink($path);
}

/**
 * Checks an uploaded handout ($_FILES entry). Returns [extension, null] when it is fine, [null, error] when not,
 * or [null, null] when no file was chosen.
 */
function cat_lesson_check_upload(?array $up): array
{
    if (!$up || $up['error'] === UPLOAD_ERR_NO_FILE) return [null, null];
    if ($up['error'] === UPLOAD_ERR_INI_SIZE || $up['error'] === UPLOAD_ERR_FORM_SIZE || ($up['error'] === UPLOAD_ERR_OK && $up['size'] > CAT_LESSON_MAX_MB * 1024 * 1024)) {
        return [null, 'The file must be ' . CAT_LESSON_MAX_MB . ' MB or smaller.'];
    }
    if ($up['error'] !== UPLOAD_ERR_OK) return [null, 'The file could not be uploaded. Please try again.'];
    $ext = strtolower(pathinfo((string)$up['name'], PATHINFO_EXTENSION));
    if ($ext === 'jpeg') $ext = 'jpg';
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($up['tmp_name']);
    if (!isset(CAT_LESSON_TYPES[$ext]) || !in_array($mime, CAT_LESSON_TYPES[$ext][1], true)) {
        return [null, 'Please choose a PDF, Word (.docx), PowerPoint (.pptx), JPG, PNG or MP3 file.'];
    }
    return [$ext, null];
}

/** Moves a checked upload into the private folder; returns the stored file name, or null. */
function cat_lesson_store_upload(array $up, string $ext): ?string
{
    if (!is_dir(CAT_LESSON_DIR)) mkdir(CAT_LESSON_DIR, 0755, true);
    $file = bin2hex(random_bytes(12)) . '.' . $ext;
    return move_uploaded_file($up['tmp_name'], CAT_LESSON_DIR . '/' . $file) ? $file : null;
}

/** The embeddable player address of a YouTube or Vimeo link, or null for any other link. */
function cat_video_embed(?string $url): ?string
{
    if (!$url) return null;
    if (preg_match('~^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([\w-]{11})~i', $url, $m)) {
        return 'https://www.youtube-nocookie.com/embed/' . $m[1];
    }
    if (preg_match('~^https?://(?:www\.)?vimeo\.com/(\d+)~i', $url, $m)) return 'https://player.vimeo.com/video/' . $m[1];
    return null;
}

/** The lesson text as HTML: escaped, blank lines start a paragraph, web addresses become links. */
function cat_lesson_body(?string $text): string
{
    $text = trim(str_replace("\r\n", "\n", (string)$text));
    if ($text === '') return '';
    $out = '';
    foreach (preg_split("/\n\s*\n/", $text) as $para) {
        $html = htmlspecialchars($para, ENT_QUOTES, 'UTF-8');
        $html = preg_replace('~https?://[^\s<]+[^\s<.,;:!?)\]\'"]~i', '<a href="$0" target="_blank" rel="noopener nofollow">$0</a>', $html);
        $out .= '<p>' . nl2br($html) . '</p>';
    }
    return $out;
}

/** "PDF · 1.2 MB" for a stored handout. */
function cat_lesson_file_label(array $lesson): string
{
    $path = cat_lesson_path($lesson['file'] ?? null);
    if (!$path) return '';
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $size = filesize($path);
    return CAT_LESSON_TYPES[$ext][0] . ' · ' . ($size >= 1048576 ? round($size / 1048576, 1) . ' MB' : max(1, (int)round($size / 1024)) . ' KB');
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
            ['title' => 'Guardian Information', 'note' => 'Required when the student is under 18.', 'guardian' => true, 'fields' => [
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
        'book'     => '<path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/>',
        'play'     => '<circle cx="12" cy="12" r="10"/><path d="m10 8 6 4-6 4Z"/>',
        'file'     => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/>',
        'up'       => '<path d="m18 15-6-6-6 6"/>',
        'down'     => '<path d="m6 9 6 6 6-6"/>',
        'eye'      => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
        'back'     => '<path d="M19 12H5"/><path d="m12 19-7-7 7-7"/>',
    ];
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}
