<?php
// Pastoral & Spiritual Care: the services and their request forms.
// Uses the same field definitions and checks as the sacrament forms (includes/sacrament_helpers.php).
//
// Every church starts with the six standard services below. Its Church Admin can rename them, change the description
// and photo, close or remove them, and add services of their own (pastoral-services.php); those are kept per church
// in the pastoral_services table. A service the church added uses the general request form (see pc_config()).

const PASTORAL = [
    // key => [title, description]   (the standard services, and their wording until a church changes it)
    'mass_intention'     => ['Mass Intentions',      'Offer a mass for a loved one, a special occasion or soul in need'],
    'prayer'             => ['Prayer Request',       'Share your prayer requests with our prayer community and seek intercession'],
    'counselling'        => ['Counselling Session',  'Schedule a private session for spiritual direction or pastoral counseling'],
    'sick_visit'         => ['Sick Visit',           'Request a visit for those who are sick, homebound, hospitalized or in need of spiritual support and prayer'],
    'house_blessing'     => ['House Blessing',       'Invite a priest to bless your home for a new beginning or a special occasion'],
    'funeral_assistance' => ['Funeral Assistance',   'Request help arranging a funeral mass, service or last rites for a loved one'],
];

/** A service a church added itself (as opposed to one of the standard six). */
function pc_is_custom(string $service): bool
{
    return (bool)preg_match('/^c_[0-9a-f]{10}$/', $service);
}

/**
 * A church's services, keyed by service_key, in display order. The standard six are added the first time a church's
 * services are read. Removed services are left out unless $withRemoved (they are kept so old requests keep their name).
 * With no church (a parishioner who has not chosen a home parish yet) the standard list is returned as it is.
 */
function pc_services(PDO $pdo, int $churchId, bool $withRemoved = false, bool $fresh = false): array
{
    static $cache = [];
    if ($fresh) unset($cache[$churchId]);
    if (!isset($cache[$churchId])) {
        $rows = [];
        if ($churchId > 0) {
            try {
                $read = $pdo->prepare('SELECT * FROM pastoral_services WHERE church_id = ? ORDER BY sort_order, service_id');
                $read->execute([$churchId]);
                $rows = array_column($read->fetchAll(), null, 'service_key');
                if (array_diff_key(PASTORAL, $rows)) {
                    $add = $pdo->prepare('INSERT IGNORE INTO pastoral_services (church_id, service_key, title, description, sort_order) VALUES (?, ?, ?, ?, ?)');
                    $n = 0;
                    foreach (PASTORAL as $key => [$title, $text]) {
                        $n++;
                        if (!isset($rows[$key])) $add->execute([$churchId, $key, $title, $text, $n]);
                    }
                    $read->execute([$churchId]);
                    $rows = array_column($read->fetchAll(), null, 'service_key');
                }
            } catch (PDOException $ex) {
                $rows = [];   // table not created yet (database/pastoral_services.sql): fall back to the standard list
            }
        }
        if (!$rows) {
            $n = 0;
            foreach (PASTORAL as $key => [$title, $text]) {
                $rows[$key] = ['service_id' => 0, 'church_id' => $churchId, 'service_key' => $key, 'title' => $title, 'description' => $text, 'photo' => null, 'is_open' => 1, 'sort_order' => ++$n, 'deleted_at' => null];
            }
        }
        $cache[$churchId] = $rows;
    }
    return $withRemoved ? $cache[$churchId] : array_filter($cache[$churchId], fn($s) => $s['deleted_at'] === null);
}

/** One service of a church (also one that was removed), or null. */
function pc_service(PDO $pdo, int $churchId, string $service): ?array
{
    return pc_services($pdo, $churchId, true)[$service] ?? null;
}

/** What a church calls a service, for lists and old requests. */
function pc_title(PDO $pdo, string $service, int $churchId): string
{
    return pc_service($pdo, $churchId, $service)['title'] ?? PASTORAL[$service][0] ?? 'Pastoral request';
}

/**
 * The request form of a service at a church: pc_config() with the church's own name for it. A standard service
 * keeps its form heading ("Mass Intention") unless the church renamed the service.
 */
function pc_form(PDO $pdo, string $service, int $churchId): ?array
{
    $cfg = pc_config($service);
    $row = pc_service($pdo, $churchId, $service);
    if (!$cfg || !$row) return $cfg;
    if (pc_is_custom($service)) {
        $cfg['title'] = $row['title'];
        $cfg['intro'] = $row['description'];
    } elseif ($row['title'] !== PASTORAL[$service][0]) {
        $cfg['title'] = $row['title'];
    }
    return $cfg;
}

/** Web path of a photo a Church Admin uploaded for a service, or null. Only file names we generated are accepted. */
function pc_photo_url(?string $file): ?string
{
    if (!$file || !preg_match('/^[\w.-]+$/', $file)) return null;
    return is_file(dirname(__DIR__) . '/uploads/pastoral_photos/' . $file) ? 'uploads/pastoral_photos/' . $file : null;
}

function pc_delete_photo(?string $file): void
{
    if ($file && preg_match('/^[\w.-]+$/', $file)) @unlink(dirname(__DIR__) . '/uploads/pastoral_photos/' . $file);
}

/**
 * Cover photo of a service card: the photo the church uploaded, else assets/images/pastoral/<key>.jpg (or .png / .webp),
 * e.g. sick_visit.jpg. Returns null while there is neither, and the card shows the service's icon instead.
 */
function pc_cover_url(string $service, ?string $photo = null): ?string
{
    if ($url = pc_photo_url($photo)) return $url;
    if (!isset(PASTORAL[$service])) return null;
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
        $file = 'assets/images/pastoral/' . $service . '.' . $ext;
        if (is_file(dirname(__DIR__) . '/' . $file)) return $file;
    }
    return null;
}

/** The line drawing shown on a service card that has no cover photo yet. */
function pc_cover_icon(string $service, int $size = 46): string
{
    $p = [
        'mass_intention'     => '<path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/>',
        'prayer'             => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>',
        'counselling'        => '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/><path d="M8 12h.01"/><path d="M12 12h.01"/><path d="M16 12h.01"/>',
        'sick_visit'         => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/><path d="M3.22 12H9.5l.5-1 2 4.5 2-7 1.5 3.5h5.27"/>',
        'house_blessing'     => '<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'funeral_assistance' => '<path d="M12 2v20"/><path d="M6 8h12"/>',
    ];
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$service] ?? $p['prayer']) . '</svg>';
}

// Counselling sessions are on the hour, Monday to Friday, up to 60 days ahead. One session per slot per church.
const COUNSELLING_HOURS = ['09', '10', '11', '14', '15', '16'];
const COUNSELLING_DAYS_AHEAD = 60;

// Which datetime field holds the priest's visit time for each service that books a one-hour slot (so no two
// requests at the same church can hold the same hour), and its hours/how far ahead it may be booked.
const PC_SLOT_FIELD = [
    'counselling'        => 'slot',
    'sick_visit'         => 'visit_at',
    'house_blessing'     => 'preferred_at',
    'funeral_assistance' => 'funeral_at',
];

/**
 * The form of one service, in the same shape as sx_config() plus:
 *   steps  number the section headings 1, 2, 3...
 * and per field: 'boxes' (radio options drawn as boxes), 'ph' (placeholder), 'nolabel' (the section heading is the label),
 * 'side' (the field fills the right column beside the section's other fields).
 */
function pc_config(string $service): ?array
{
    $f = fn(string $k, string $l, string $t = 'text', bool $r = true, array $x = []) => ['k' => $k, 'l' => $l, 't' => $t, 'r' => $r] + $x;
    // A service the church added: one general form. Its title and introduction come from the service (see pc_form()).
    if (pc_is_custom($service)) {
        return ['title' => 'Request', 'steps' => true, 'intro' => '', 'sections' => [
            ['title' => 'Your Request', 'fields' => [
                $f('request', 'Your request', 'textarea', true, ['nolabel' => true, 'full' => true, 'max' => 800, 'ph' => 'Tell us what you need']),
            ]],
            ['title' => 'Preferred Date (optional)', 'fields' => [
                $f('preferred_date', 'Preferred date', 'date', false, ['nolabel' => true, 'future' => true]),
            ]],
            ['title' => 'Contact Information (optional)', 'fields' => [
                $f('contact_name', 'Name', 'text', false),
                $f('contact', 'Email/Phone Number', 'contact', false),
            ]],
        ], 'docs' => []];
    }
    switch ($service) {
        case 'mass_intention':
            return ['title' => 'Mass Intention', 'steps' => true, 'intro' => '', 'fee' => 'RM10.00', 'sections' => [
                ['title' => 'Select the type of intention', 'fields' => [
                    $f('intention_type', 'Type of intention', 'radio', true, ['boxes' => true, 'nolabel' => true, 'full' => true, 'o' => ['deceased' => 'Deceased', 'thanksgiving' => 'Thanksgiving', 'special' => 'Special intentions', 'wellbeing' => 'Wellbeing']]),
                ]],
                ['title' => 'I would like to offer a mass for', 'fields' => [
                    $f('intention_for', 'Name of person or reason for the intention', 'text', true, ['nolabel' => true, 'full' => true, 'max' => 150, 'ph' => 'name of person or reason for the intention']),
                ]],
                ['title' => 'Offer my intention on', 'fields' => [
                    $f('offer_date', 'Date', 'date', true, ['nolabel' => true, 'future' => true]),
                ]],
                ['title' => 'Contribution & Payment', 'note' => 'A contribution of RM10.00 to the church is appreciated for each Mass Intention.', 'bank' => true, 'docs' => ['receipt']],
            ], 'docs' => [
                'receipt' => ['Upload Receipt', 'Click or drag to upload receipt', true, 'cloud', false],
            ]];
        case 'prayer':
            return ['title' => 'Prayer Request', 'steps' => true, 'intro' => '', 'sections' => [
                ['title' => 'Your Prayer Request', 'fields' => [
                    $f('request', 'Your prayer request', 'textarea', true, ['nolabel' => true, 'full' => true, 'max' => 800]),
                ]],
                ['title' => 'Contact Information (optional)', 'fields' => [
                    $f('contact_name', 'Name', 'text', false),
                    $f('contact', 'Email/Phone Number', 'contact', false),
                ]],
            ], 'docs' => []];
        case 'counselling':
            return ['title' => 'Counselling', 'steps' => true, 'intro' => '', 'sections' => [
                ['title' => 'Session Details', 'fields' => [
                    $f('session_type', 'Session type', 'select', true, ['nolabel' => true, 'o' => ['marriage' => 'Marriage counselling', 'family' => 'Family counselling', 'grief' => 'Grief and loss', 'direction' => 'Spiritual direction', 'vocation' => 'Vocation discernment', 'other' => 'Something else']]),
                ]],
                ['title' => 'Preferred format', 'fields' => [
                    $f('format', 'Preferred format', 'radio', true, ['nolabel' => true, 'full' => true, 'o' => ['in_person' => 'In-person at church office', 'online' => 'Online (phone call or zoom)']]),
                ]],
                ['title' => 'Pick a date and time', 'fields' => [
                    $f('slot', 'Date and time', 'datetime', true, ['nolabel' => true, 'full' => true, 'future' => true, 'pick' => true, 'hours' => COUNSELLING_HOURS, 'days' => COUNSELLING_DAYS_AHEAD, 'min_days' => 1, 'dow' => [1, 2, 3, 4, 5]]),
                ]],
                ['title' => 'Brief Overview (optional)', 'fields' => [
                    $f('overview', 'Brief overview', 'textarea', false, ['nolabel' => true, 'full' => true, 'max' => 500]),
                ]],
            ], 'docs' => []];
        case 'sick_visit':
            return ['title' => 'Sick Visit', 'steps' => false, 'intro' => 'Request a visit for someone who is sick, homebound or in hospital. For an emergency, please call the parish office.', 'sections' => [
                ['title' => null, 'fields' => [
                    $f('patient_name', "Patient's Full Name"),
                    $f('communion', 'Able to receive holy communion?', 'radio', true, ['o' => ['yes' => 'Yes', 'no' => 'No']]),
                    $f('location', 'Location Details', 'text', true, ['max' => 255, 'geo' => true, 'ph' => 'e.g. Queen Elizabeth Hospital, Ward 5B, Bed 12']),
                    $f('visit_at', 'Preferred Visit Date/Time', 'datetime', true, ['future' => true, 'pick' => true, 'hours' => ANOINTING_HOURS, 'days' => ANOINTING_DAYS_AHEAD]),
                ]],
                ['title' => 'Contact Person', 'small' => true, 'fields' => [
                    $f('contact_name', 'Name'), $f('contact_phone', 'Contact Number', 'tel'), $f('relationship', 'Relationship'),
                ]],
            ], 'docs' => []];
        case 'house_blessing':
            return ['title' => 'House Blessing', 'steps' => false, 'intro' => 'Invite a priest to bless your home. Our office will confirm the date and time with you.', 'sections' => [
                ['title' => null, 'fields' => [
                    $f('owner_name', "House Owner's Full Name"),
                    $f('address', 'House Address', 'text', true, ['max' => 255, 'geo' => true, 'ph' => 'House number, street, area']),
                    $f('preferred_at', 'Preferred Date/Time', 'datetime', true, ['side' => true, 'future' => true, 'pick' => true, 'hours' => ANOINTING_HOURS, 'days' => ANOINTING_DAYS_AHEAD]),
                    $f('contact_phone', 'Contact Number', 'tel', true),
                    $f('notes', 'Anything else the priest should know?', 'textarea', false, ['max' => 300]),
                ]],
            ], 'docs' => []];
        case 'funeral_assistance':
            return ['title' => 'Funeral Assistance', 'steps' => false, 'intro' => 'Request help arranging a funeral mass or service. For an urgent need, please call the parish office directly.', 'sections' => [
                ['title' => null, 'fields' => [
                    $f('deceased_name', "Deceased's Full Name"),
                    $f('date_of_death', 'Date of Death', 'date', true, ['past' => true]),
                    $f('funeral_at', 'Preferred Funeral Date/Time', 'datetime', true, ['future' => true, 'pick' => true, 'hours' => ANOINTING_HOURS, 'days' => ANOINTING_DAYS_AHEAD]),
                    $f('venue', 'Funeral Venue', 'text', true, ['max' => 255, 'geo' => true, 'ph' => 'e.g. family home, funeral parlour or church hall']),
                ]],
                ['title' => 'Contact Person', 'small' => true, 'fields' => [
                    $f('contact_name', 'Name'), $f('contact_phone', 'Contact Number', 'tel'), $f('relationship', 'Relationship to the Deceased'),
                ]],
            ], 'docs' => []];
    }
    return null;
}

/** [label, css class] for a request status. */
function pc_status(string $s): array
{
    return [
        'draft'     => ['Draft', 'is-draft'],
        'submitted' => ['Submitted', 'is-submitted'],
        'review'    => ['Under Review', 'is-review'],
        'given'     => ['Approved', 'is-ok'],
        'declined'  => ['Declined', 'is-no'],
    ][$s] ?? [ucfirst($s), 'is-draft'];
}

/** How many of the 3 tracker steps (Application Submitted, Review, Approved) are done. */
function pc_progress(string $s): int
{
    return ['submitted' => 1, 'review' => 2, 'given' => 3, 'declined' => 2][$s] ?? 0;
}

function pc_ref(int $id): string { return 'PC-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT); }

/** One line that says what a request is about (for the lists). */
function pc_summary(string $service, array $d): string
{
    $cfg = pc_config($service);
    $fields = $cfg ? sx_fields($cfg) : [];
    $t = match ($service) {
        'mass_intention'     => $d['intention_for'] ?? '',
        'prayer'             => $d['request'] ?? '',
        'counselling'        => isset($fields['session_type']) ? ($fields['session_type']['o'][$d['session_type'] ?? ''] ?? '') : '',
        'sick_visit'         => $d['patient_name'] ?? '',
        'house_blessing'     => $d['address'] ?? '',
        'funeral_assistance' => $d['deceased_name'] ?? '',
        default              => $d['request'] ?? '',   // a service the church added
    };
    return mb_strlen($t) > 70 ? mb_substr($t, 0, 70) . '…' : $t;
}

/**
 * The hour slots other people already hold at this church for this service (sent requests that are not
 * declined), so the date picker can grey them out. Covers every service in PC_SLOT_FIELD.
 * @return array ['slots' => [date => [hour, ...]]]
 */
function pc_taken(PDO $pdo, string $service, int $churchId, int $excludeId = 0): array
{
    $field = PC_SLOT_FIELD[$service] ?? null;
    if (!$field) return [];
    $stmt = $pdo->prepare("SELECT details FROM pastoral_requests WHERE service = ? AND church_id = ? AND status IN ('submitted', 'review', 'given') AND request_id <> ?");
    $stmt->execute([$service, $churchId, $excludeId]);
    $slots = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
        $d = json_decode((string)$json, true) ?: [];
        if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}):/', (string)($d[$field] ?? ''), $m)) $slots[$m[1]][$m[2]] = $m[2];
    }
    return ['slots' => array_map('array_values', $slots)];
}

/** Errors for a slot that is taken, or outside the days and hours the church offers. */
function pc_conflicts(string $service, array $f, array $taken): array
{
    $field = PC_SLOT_FIELD[$service] ?? null;
    if (!$field || ($f[$field] ?? '') === '') return [];
    if (!preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}):00$/', $f[$field], $m)) return [$field => 'Please pick one of the available times.'];
    if ($service === 'counselling') {
        $dow = (int)date('N', strtotime($m[1]));
        if ($dow > 5 || !in_array($m[2], COUNSELLING_HOURS, true)) return [$field => 'Please pick one of the available times.'];
        if ($m[1] > date('Y-m-d', strtotime('+' . COUNSELLING_DAYS_AHEAD . ' days'))) return [$field => 'That date is too far ahead.'];
    } elseif (!in_array($m[2], ANOINTING_HOURS, true)) {
        return [$field => 'Please pick one of the available times.'];
    } elseif ($m[1] > date('Y-m-d', strtotime('+' . ANOINTING_DAYS_AHEAD . ' days'))) {
        return [$field => 'That date is too far ahead.'];
    }
    if (in_array($m[2], $taken['slots'][$m[1]] ?? [], true)) return [$field => 'That time is already booked. Please choose another.'];
    return [];
}

function pc_icon(string $name, int $size = 24): string
{
    $p = [
        'clipboard' => '<rect width="8" height="4" x="8" y="2" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/>',
        'check'     => '<path d="M20 6 9 17l-5-5"/>',
        'heartplus' => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/><path d="M12 8v6"/><path d="M9 11h6"/>',
    ];
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}
