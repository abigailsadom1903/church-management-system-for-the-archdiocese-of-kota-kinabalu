<?php
// Shared bits for the Sacraments pages: the list of sacraments, the form definitions (one per sacrament),
// validation, icons, status labels and the private document folder.

const SACRAMENTS = [
    // key => [name, icon, colour, application ready?]
    'baptism'      => ['Baptism',               'droplet',  '#2f6fb3', true],
    'eucharist'    => ['Eucharist',             'host',     '#b7791f', true],
    'confirmation' => ['Confirmation',          'flame',    '#c0392b', true],
    'confession'   => ['Confession',            'dialogue', '#5b2d82', true],
    'anointing'    => ['Anointing of the Sick', 'oil',      '#2e8b57', true],
    'matrimony'    => ['Matrimony',             'rings',    '#a3446f', true],
];

const SACRAMENT_DOC_DIR = __DIR__ . '/../uploads/sacrament_docs';
const SACRAMENT_DOC_TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
const SACRAMENT_DOC_MAX = 5 * 1024 * 1024;

// Confession booking: how far ahead a booking can be made. The times themselves are set up by the church admin (confession_slots).
const CONFESSION_DAYS_AHEAD = 90;

/**
 * The form of one sacrament.
 *   kind     'form' (fields + documents) or 'booking' (pick a date and time)
 *   sections list of [title, note, fields, docs, bank]; fields are [key, label, type, required, extras...]
 *   docs     key => [label, hint, required, icon, tile?]
 * Field types: text, tel, email, date, number, textarea, radio, select, datetime.
 */
function sx_config(string $type): ?array
{
    $f = fn(string $k, string $l, string $t = 'text', bool $r = true, array $x = []) => ['k' => $k, 'l' => $l, 't' => $t, 'r' => $r] + $x;
    $gender = ['male' => 'Male', 'female' => 'Female'];

    $candidate = [
        $f('full_name', 'Full Name'), $f('gender', 'Gender', 'radio', true, ['o' => $gender]),
        $f('email', 'Email Address', 'email'), $f('phone', 'Phone Number', 'tel'),
        $f('address', 'Home Address', 'textarea'), $f('ic', 'IC/Passport No', 'text', true, ['max' => 30]),
        $f('dob', 'Date of Birth', 'date', true, ['past' => true]),
    ];

    switch ($type) {
        case 'baptism':
            return [
                'title' => 'Baptism', 'kind' => 'form', 'side' => 'baptism',
                'intro' => 'Please complete the form below to register a child for baptism. Ensure all details are accurate according to official documentation.',
                'sections' => [
                    ['title' => "Child's Personal Information", 'fields' => [
                        $f('child_name', 'Full Name (As in birth certificate)'), $f('dob', 'Date of birth', 'date', true, ['past' => true, 'age_max' => 7]),
                        $f('gender', 'Gender', 'radio', true, ['o' => $gender]), $f('birth_place', 'Place of birth'),
                    ]],
                    ['title' => 'Parental & Godparent Information', 'one_of' => ['father_name', 'mother_name', "Enter at least one parent's name."], 'fields' => [
                        $f('father_name', "Father's Full Name", 'text', false), $f('mother_name', "Mother's Full Name", 'text', false),
                        $f('contact', 'Contact Number', 'tel'), $f('address', 'Address', 'text', true, ['max' => 255]),
                        $f('godparent_name', "Godparent's name"), $f('godparent_contact', "Godparent's Contact Number", 'tel'),
                    ]],
                    ['title' => 'Documentation Uploads', 'docs' => ['birth_cert', 'marriage_cert', 'godparent_cert']],
                ],
                'docs' => [
                    'birth_cert'     => ["Child's birth certificate (PDF/image)", '', true, 'upload', false],
                    'marriage_cert'  => ["Parents' marriage certificate", '', false, 'upload', false],
                    'godparent_cert' => ["Godparent's confirmation certificate", '', true, 'upload', false],
                ],
            ];

        case 'eucharist':
            return [
                'title' => 'Eucharist', 'kind' => 'form', 'side' => 'notes', 'fee' => 'RM30',
                'intro' => 'A candidate must have completed the required catechesis classes before they can register for the First Holy Communion sacrament itself.',
                'sections' => [
                    ['title' => 'Candidate Information', 'fields' => $candidate],
                    ['title' => 'Guardian Information', 'fields' => [
                        $f('g_name', 'Full Name'), $f('g_phone', 'Phone Number', 'tel'), $f('g_relationship', 'Relationship to candidate'),
                    ]],
                    ['title' => 'Documents Upload', 'docs' => ['ic_copy', 'baptism_cert']],
                    ['title' => 'Registration Fee', 'note' => 'A registration fee of RM30 is required. This fee covers: Preparation materials, photos, communion certificate and reception following mass.', 'bank' => true, 'docs' => ['receipt']],
                ],
                'docs' => [
                    'ic_copy'      => ['IC / Passport Copy', 'For identity verification purposes', true, 'id', true],
                    'baptism_cert' => ["Child's Baptismal Certificate", 'Required to verify sacramental eligibility', true, 'church', true],
                    'receipt'      => ['Upload Receipt', 'Click or drag to upload receipt', true, 'cloud', false],
                ],
            ];

        case 'confirmation':
            return [
                'title' => 'Confirmation', 'kind' => 'form', 'side' => 'notes',
                'intro' => 'A candidate must have completed the required catechesis classes before they can register for the Confirmation sacrament itself.',
                'sections' => [
                    ['title' => 'Candidate Information', 'fields' => $candidate],
                    ['title' => 'Documents Upload', 'docs' => ['baptism_cert', 'communion_cert', 'ic_copy']],
                    ['title' => null, 'fields' => [$f('conf_name', 'Name chosen for confirmation', 'text', true, ['full' => true])],
                     'note' => "The candidate's Confirmation name maybe his/her baptismal name, the name of a saint or the name of a person who has demonstrated a commitment to fully living the Christian faith.",
                     'strong' => 'PLEASE COMPLETE EVEN IF USING YOUR BAPTISMAL NAME.'],
                    ['title' => 'Sponsor Information', 'fields' => [
                        $f('s_name', 'Full Name'), $f('s_gender', 'Gender', 'radio', true, ['o' => $gender]),
                        $f('s_email', 'Email Address', 'email'), $f('s_phone', 'Phone Number', 'tel'),
                        $f('s_address', 'Home Address', 'textarea'), $f('s_church', 'Church Name'),
                        $f('s_dob', 'Date of Birth', 'date', true, ['past' => true]),
                    ]],
                ],
                'docs' => [
                    'baptism_cert'   => ["Child's Baptismal Certificate", 'Required to verify sacramental eligibility', true, 'church', true],
                    'communion_cert' => ['First Holy Communion Certificate', 'Only required for Confirmation applicants', true, 'host', true],
                    'ic_copy'        => ['IC / Passport Copy', 'For identity verification purposes', true, 'id', true],
                ],
            ];

        case 'matrimony':
            return [
                'title' => 'Matrimony', 'kind' => 'form', 'side' => 'notes',
                'intro' => 'Please complete the couple\'s details and upload the required documents. Where a document is for both partners, combine the copies into one PDF or image.',
                'sections' => [
                    ['title' => 'Couple Information', 'fields' => [
                        $f('male_name', "Male's Name"), $f('female_name', "Female's Name"),
                        $f('male_phone', "Male's Phone Number", 'tel'), $f('female_phone', "Female's Phone Number", 'tel'),
                        $f('male_occupation', "Male's Occupation"), $f('female_occupation', "Female's Occupation"),
                        $f('wedding_date', 'Proposed Wedding Date', 'date', true, ['future' => true, 'pick' => true]),
                    ]],
                    ['title' => 'Required Documents', 'docs' => ['birth_certs', 'id_cards', 'baptism_certs', 'confirmation_certs', 'premarriage', 'photos']],
                ],
                'docs' => [
                    'birth_certs'        => ['Birth Certificates', 'Copy of both partners', true, 'file', true],
                    'id_cards'           => ['Identity Cards', 'Copy of both partners', true, 'id', true],
                    'baptism_certs'      => ['Baptism Certificates', 'Copy of both partners', true, 'church', true],
                    'confirmation_certs' => ['Confirmation Certificates', 'Copy of both partners', true, 'book', true],
                    'premarriage'        => ['Pre-Marriage Course', 'Completion certificate', true, 'cap', true],
                    'photos'             => ['Passport Photos', 'One photo of each partner', true, 'photo', true],
                ],
            ];

        case 'anointing':
            return [
                'title' => 'Anointing of the Sick', 'kind' => 'form', 'side' => 'anointing', 'intro' => '',
                'sections' => [
                    ['title' => null, 'fields' => [
                        $f('urgency', 'Urgency Level', 'select', true, ['o' => ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'emergency' => 'Emergency']]),
                    ]],
                    ['title' => null, 'fields' => [
                        $f('patient_name', "Patient's Full Name"), $f('age', 'Age', 'number', true, ['min' => 0, 'max' => 120]),
                        $f('location', 'Location Details', 'text', true, ['max' => 255]), $f('visit_at', 'Preferred Visit Date & Time', 'datetime', true, ['future' => true, 'pick' => true]),
                    ]],
                    ['title' => 'Contact Person', 'small' => true, 'fields' => [
                        $f('cp_name', 'Name'), $f('cp_number', 'Contact Number', 'tel'), $f('cp_relationship', 'Relationship'),
                    ]],
                ],
                'docs' => [],
            ];

        case 'confession':
            return ['title' => 'Confession', 'kind' => 'booking', 'side' => null, 'intro' => 'Choose a day and a time, then confirm your booking.', 'sections' => [], 'docs' => []];
    }
    return null;
}

// Hours offered for an Anointing visit (the visit is booked by the hour), and how far ahead each date field may reach.
const ANOINTING_HOURS = ['08', '09', '10', '11', '12', '13', '14', '15', '16', '17', '18', '19'];
const WEDDING_DAYS_AHEAD = 1095;
const ANOINTING_DAYS_AHEAD = 60;

/**
 * What other people have already taken at this church (their applications that are sent and not rejected;
 * drafts hold nothing). Matrimony: whole days. Anointing: one-hour slots (an Emergency request never blocks).
 * @return array ['dates' => [...]] for matrimony, ['slots' => [date => [hour, ...]]] for anointing, [] otherwise
 */
function sx_taken(PDO $pdo, string $type, int $churchId, int $excludeId = 0): array
{
    if ($type !== 'matrimony' && $type !== 'anointing') return [];
    $stmt = $pdo->prepare(
        "SELECT details FROM sacrament_applications
          WHERE sacrament = ? AND church_id = ? AND status NOT IN ('draft', 'rejected', 'cancelled') AND application_id <> ?"
    );
    $stmt->execute([$type, $churchId, $excludeId]);
    $dates = [];
    $slots = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
        $d = json_decode((string)$json, true) ?: [];
        if ($type === 'matrimony') {
            if (!empty($d['wedding_date'])) $dates[$d['wedding_date']] = true;
        } elseif (($d['urgency'] ?? '') !== 'emergency' && preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}):/', (string)($d['visit_at'] ?? ''), $m)) {
            $slots[$m[1]][$m[2]] = $m[2];
        }
    }
    return $type === 'matrimony' ? ['dates' => array_keys($dates)] : ['slots' => array_map('array_values', $slots)];
}

/** Errors when the chosen date / time is already taken (see sx_taken). */
function sx_conflicts(string $type, array $f, array $taken): array
{
    if ($type === 'matrimony' && ($f['wedding_date'] ?? '') !== '' && in_array($f['wedding_date'], $taken['dates'] ?? [], true)) {
        return ['wedding_date' => 'That date is already taken. Please choose another date.'];
    }
    if ($type === 'anointing' && ($f['urgency'] ?? '') !== 'emergency' && preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}):/', (string)($f['visit_at'] ?? ''), $m)
        && in_array($m[2], $taken['slots'][$m[1]] ?? [], true)) {
        return ['visit_at' => 'That time is already booked. Please choose another time.'];
    }
    return [];
}

/** All fields of a form as key => definition. */
function sx_fields(array $cfg): array
{
    $out = [];
    foreach ($cfg['sections'] as $s) {
        foreach ($s['fields'] ?? [] as $fd) $out[$fd['k']] = $fd;
    }
    return $out;
}

/** Clean the posted values for a form: trimmed, cut to length, radios/selects limited to their options. */
function sx_collect(array $cfg, array $post): array
{
    $out = [];
    foreach (sx_fields($cfg) as $k => $fd) {
        $max = $fd['max'] ?? ($fd['t'] === 'textarea' ? 300 : 100);
        $v = mb_substr(trim((string)($post[$k] ?? '')), 0, $max);
        if (in_array($fd['t'], ['radio', 'select'], true) && !isset($fd['o'][$v])) $v = '';
        $out[$k] = $v;
    }
    return $out;
}

/**
 * Check the answers. $complete = the person pressed Submit (required things must be filled in);
 * a draft only has to be well-formed where something was typed.
 * @return array<string,string> field key => message
 */
function sx_validate(array $cfg, array $f, bool $complete): array
{
    $err = [];
    $today = date('Y-m-d');
    foreach (sx_fields($cfg) as $k => $fd) {
        $v = $f[$k] ?? '';
        if ($v === '') {
            if ($complete && $fd['r']) $err[$k] = in_array($fd['t'], ['radio', 'select'], true) ? 'Please choose one.' : 'This field is required.';
            continue;
        }
        switch ($fd['t']) {
            case 'tel':
                if (!preg_match('/^\+?[0-9][0-9\s\-()]{6,25}$/', $v)) $err[$k] = 'Enter a valid phone number.';
                break;
            case 'email':
                if (!filter_var($v, FILTER_VALIDATE_EMAIL)) $err[$k] = 'Enter a valid email address.';
                break;
            case 'contact':   // an email address or a phone number
                if (!filter_var($v, FILTER_VALIDATE_EMAIL) && !preg_match('/^\+?[0-9][0-9\s\-()]{6,25}$/', $v)) $err[$k] = 'Enter a valid email address or phone number.';
                break;
            case 'number':
                if (!ctype_digit($v) || (int)$v < ($fd['min'] ?? 0) || (int)$v > ($fd['max'] ?? 999)) $err[$k] = 'Enter a valid number.';
                break;
            case 'date':
                $d = DateTime::createFromFormat('Y-m-d', $v);
                if (!$d || $d->format('Y-m-d') !== $v) { $err[$k] = 'Enter a valid date.'; break; }
                if (!empty($fd['past']) && $v > $today) $err[$k] = 'The date cannot be in the future.';
                elseif (!empty($fd['age_max']) && $v <= date('Y-m-d', strtotime('-' . ($fd['age_max'] + 1) . ' years'))) $err[$k] = 'This sacrament is for ages up to ' . $fd['age_max'] . '. Please contact the parish office.';
                elseif (!empty($fd['future']) && $v <= $today) $err[$k] = 'Choose a date in the future.';
                break;
            case 'datetime':
                $d = DateTime::createFromFormat('Y-m-d\TH:i', $v);
                if (!$d || $d->format('Y-m-d\TH:i') !== $v) $err[$k] = 'Enter a valid date and time.';
                elseif (!empty($fd['future']) && $d->getTimestamp() < time()) $err[$k] = 'Choose a date and time that has not passed.';
                break;
        }
    }
    if ($complete) {
        foreach ($cfg['sections'] as $s) {
            if (!empty($s['one_of'])) {
                [$a, $b, $msg] = $s['one_of'];
                if (($f[$a] ?? '') === '' && ($f[$b] ?? '') === '') $err[$a] = $msg;
            }
        }
    }
    return $err;
}

function sx_icon(string $name, int $size = 24): string
{
    $p = [
        'droplet'  => '<path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"/>',
        'host'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v10"/><path d="M8.5 10.5h7"/>',
        'calendar' => '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/>',
        'flame'    => '<path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.07-2.14-.22-4.05 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.15.43-2.29 1-3a2.5 2.5 0 0 0 2.5 2.5z"/>',
        'dialogue' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M8 9h8"/><path d="M8 13h5"/>',
        'oil'      => '<path d="M7 16.3c2.2 0 4-1.83 4-4.05 0-1.16-.57-2.26-1.71-3.19S7.29 6.75 7 5.3c-.29 1.45-1.14 2.84-2.29 3.76S3 11.1 3 12.25c0 2.22 1.8 4.05 4 4.05z"/><path d="M12.56 6.6A10.97 10.97 0 0 0 14 3.02c.5 2.5 2 4.9 4 6.5s3 3.5 3 5.5a6.98 6.98 0 0 1-11.91 4.97"/>',
        'rings'    => '<circle cx="9" cy="12" r="5.5"/><circle cx="15" cy="12" r="5.5"/>',
        'dots'     => '<circle cx="5" cy="12" r="1.6" fill="currentColor"/><circle cx="12" cy="12" r="1.6" fill="currentColor"/><circle cx="19" cy="12" r="1.6" fill="currentColor"/>',
        'upload'   => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M12 18v-6"/><path d="m9 15 3-3 3 3"/>',
        'cloud'    => '<path d="M4 14.9A7 7 0 1 1 15.7 8h1.8a4.5 4.5 0 0 1 2.5 8.2"/><path d="M12 12v9"/><path d="m16 16-4-4-4 4"/>',
        'file'     => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/>',
        'id'       => '<rect width="18" height="14" x="3" y="5" rx="2"/><circle cx="9" cy="11" r="2"/><path d="M14 9h4"/><path d="M14 13h4"/><path d="M6 17c.7-1.4 1.8-2 3-2s2.3.6 3 2"/>',
        'book'     => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
        'cap'      => '<path d="M22 10 12 5 2 10l10 5 10-5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/>',
        'photo'    => '<rect width="18" height="18" x="3" y="3" rx="2"/><circle cx="12" cy="10" r="3"/><path d="M7 21v-2a5 5 0 0 1 10 0v2"/>',
        'check'    => '<path d="M20 6 9 17l-5-5"/>',
        'info'     => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
        'save'     => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8"/><path d="M7 3v5h8"/>',
        'church'   => '<path d="m18 7 4 2v11a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9l4-2"/><path d="M14 22v-4a2 2 0 0 0-4 0v4"/><path d="M18 22V5l-6-3-6 3v17"/><path d="M12 7v5"/><path d="M10 9h4"/>',
        'left'     => '<path d="m15 18-6-6 6-6"/>',
        'right'    => '<path d="m9 18 6-6-6-6"/>',
        'trash'    => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'pencil'   => '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/>',
        'plus'     => '<path d="M12 5v14"/><path d="M5 12h14"/>',
        'x'        => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'search'   => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'print'    => '<path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/>',
        'chevron'  => '<path d="m6 9 6 6 6-6"/>',
        'star'     => '<path d="M12 3v18"/><path d="m4.9 7.5 14.2 9"/><path d="m4.9 16.5 14.2-9"/>',
    ];
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}

/** [label, css class] for an application status. */
function sx_status(string $s): array
{
    return [
        'draft'     => ['Draft', 'is-draft'],
        'submitted' => ['Submitted', 'is-submitted'],
        'verifying' => ['Verification', 'is-verify'],
        'review'    => ['Under Review', 'is-review'],
        'approved'  => ['Approved', 'is-ok'],
        'rejected'  => ['Rejected', 'is-no'],
        'cancelled' => ['Cancelled', 'is-draft'],
    ][$s] ?? [ucfirst($s), 'is-draft'];
}

/** The four steps of the status tracker; returns how many are done for a status (0-4). */
function sx_progress(string $s): int
{
    return ['submitted' => 1, 'verifying' => 2, 'review' => 3, 'approved' => 4, 'rejected' => 3][$s] ?? 0;
}

function sx_ref(int $id): string { return 'APP-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT); }

/** The person or couple an application is for (shown in the admin lists). */
function sx_subject(string $type, array $d): string
{
    switch ($type) {
        case 'baptism':      return (string)($d['child_name'] ?? '');
        case 'matrimony':    return trim(($d['male_name'] ?? '') . (($d['male_name'] ?? '') !== '' && ($d['female_name'] ?? '') !== '' ? ' & ' : '') . ($d['female_name'] ?? ''));
        case 'anointing':    return (string)($d['patient_name'] ?? '');
        case 'confession':   return '';
        default:             return (string)($d['full_name'] ?? '');
    }
}

/** "20 June 2026" style label for a confession time like 09:30 -> "9:30 am". */
function sx_time_label(string $t): string
{
    $ts = strtotime($t);
    return ltrim(date('g:i', $ts), '0') . (date('G', $ts) >= 12 ? ' pm' : ' am');
}

/** Full path of a stored document, or null if the name is unsafe or the file is gone. */
function sx_doc_path(?string $file): ?string
{
    if (!$file || !preg_match('/^[a-f0-9]{24}\.(pdf|jpg|png)$/', $file)) return null;
    $path = SACRAMENT_DOC_DIR . '/' . $file;
    return is_file($path) ? $path : null;
}

function sx_delete_doc(?string $file): void
{
    if ($path = sx_doc_path($file)) @unlink($path);
}

/** Human text for one answer (radio/select show their label, dates read nicely). */
function sx_show(array $fd, string $v): string
{
    if ($v === '') return '';
    if (isset($fd['o'][$v])) return $fd['o'][$v];
    if ($fd['t'] === 'date') return date('j F Y', strtotime($v));
    if ($fd['t'] === 'datetime') return date('j F Y, g:i A', strtotime($v));
    return $v;
}

/**
 * The confession times a church has set up between two dates, with how many people have booked each one.
 * A booking counts unless it was cancelled or rejected.
 * @return array<string, array<string, array{location:string, max:int, booked:int}>>  date => time (HH:MM) => info
 */
function sx_confession_slots(PDO $pdo, int $churchId, string $from, string $to): array
{
    $stmt = $pdo->prepare('SELECT slot_date, TIME_FORMAT(slot_time, \'%H:%i\') AS t, location, max_bookings FROM confession_slots WHERE church_id = ? AND slot_date BETWEEN ? AND ? ORDER BY slot_date, slot_time');
    $stmt->execute([$churchId, $from, $to]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[$r['slot_date']][$r['t']] = ['location' => (string)$r['location'], 'max' => (int)$r['max_bookings'], 'booked' => 0];
    }
    if ($out) {
        $stmt = $pdo->prepare("SELECT details FROM sacrament_applications WHERE sacrament = 'confession' AND church_id = ? AND status NOT IN ('draft', 'rejected', 'cancelled')");
        $stmt->execute([$churchId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $d = json_decode((string)$json, true) ?: [];
            if (isset($out[$d['date'] ?? ''][$d['time'] ?? ''])) $out[$d['date']][$d['time']]['booked']++;
        }
    }
    return $out;
}

// ---- Is a sacrament's application form taking applications? --------------------------------------
// Set per church on sacrament-schedule.php: the "Applications open" switch, and an optional last day
// ("Applications open until"). After that day the form closes by itself.

/**
 * Every sacrament's intake for one church: sacrament => ['open' => bool, 'until' => 'Y-m-d'|null, 'why' => 'switch'|'deadline'|null].
 * Sacraments that were never set up are open with no deadline.
 */
function sx_intakes(PDO $pdo, int $churchId): array
{
    $out = [];
    try {
        $stmt = $pdo->prepare('SELECT sacrament, is_open, apply_until FROM sacrament_schedules WHERE church_id = ?');
        $stmt->execute([$churchId]);
        foreach ($stmt->fetchAll() as $r) {
            $until = $r['apply_until'] ?: null;
            $why = !(int)$r['is_open'] ? 'switch' : ($until !== null && $until < date('Y-m-d') ? 'deadline' : null);
            $out[$r['sacrament']] = ['open' => $why === null, 'until' => $until, 'why' => $why];
        }
    } catch (PDOException $ex) { /* column not added yet: database/sacrament_deadline.sql */ }
    return $out;
}

/** One sacrament's intake (see sx_intakes). */
function sx_intake(PDO $pdo, int $churchId, string $sacrament): array
{
    return sx_intakes($pdo, $churchId)[$sacrament] ?? ['open' => true, 'until' => null, 'why' => null];
}

/** "2026-10-15" -> "15 Oct 2026" */
function sx_day(string $ymd): string
{
    return date('j M Y', strtotime($ymd));
}
