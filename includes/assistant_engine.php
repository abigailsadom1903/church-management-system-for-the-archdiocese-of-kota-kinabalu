<?php
// The Intelligent Church Assistant. It is a rules-based assistant: it recognises what a person is asking about and answers
// from this portal's own data (the church's Mass times, sacrament notes, confession slots, programs, announcements, contacts,
// priests, the person's own applications and donations, and where each page of the portal is).
// It does not use an outside AI service, and it never invents times or prices: when the portal has no data it says so
// and points to the parish office.

require_once __DIR__ . '/sacrament_helpers.php';
require_once __DIR__ . '/event_helpers.php';
require_once __DIR__ . '/catechesis_helpers.php';
require_once __DIR__ . '/pastoral_helpers.php';
require_once __DIR__ . '/donation_helpers.php';
require_once __DIR__ . '/site_content.php';
require_once __DIR__ . '/review_queue.php';

/** Quick-reply chips shown under the assistant's greeting and answers. */
const ASSISTANT_CHIPS = ['Find Mass Times', 'Request a Prayer', 'How to register for Baptism?', 'Support for Sick Visit'];

/** Words the assistant listens for, used to forgive small typing mistakes ("baptisim", "confesion", "donasion"). */
const ASSISTANT_VOCAB = [
    'masses', 'baptism', 'baptise', 'baptize', 'christening', 'godparent', 'communion', 'eucharist', 'confirmation',
    'confession', 'reconciliation', 'anointing', 'marriage', 'wedding', 'matrimony', 'married', 'intention', 'intentions',
    'prayer', 'prayers', 'counselling', 'counseling', 'hospital', 'blessing', 'funeral', 'burial', 'donate', 'donation',
    'donations', 'offering', 'tithe', 'collection', 'event', 'events', 'calendar', 'activities', 'catechesis', 'catechism',
    'classes', 'announcement', 'announcements', 'bulletin', 'bulletins', 'contact', 'phone', 'telephone', 'email', 'address',
    'location', 'office', 'priest', 'priests', 'archbishop', 'bishop', 'clergy', 'directory', 'church', 'churches', 'parish',
    'parishes', 'account', 'password', 'register', 'registered', 'registration', 'profile', 'status', 'application', 'applications',
    'request', 'requests', 'receipt', 'sacrament', 'sacraments', 'documents', 'requirements', 'schedule', 'website',
    'facebook', 'whatsapp', 'privacy', 'archdiocese', 'statistics', 'enrolment', 'enrollment', 'attendance', 'emergency',
    'timetable', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'tomorrow',
    'pending', 'logout', 'login',
];

/** The message in lower case with near-misses of the words above corrected. Short words are left alone. */
function assistant_fix_typos(string $msg): string
{
    static $known = null;
    $known ??= array_flip(ASSISTANT_VOCAB);
    return preg_replace_callback('/[a-z]{5,}/', function ($m) use ($known) {
        $w = $m[0];
        if (isset($known[$w])) return $w;
        $max = strlen($w) >= 8 ? 2 : 1;
        $best = $w;
        $bestD = $max + 1;
        foreach (ASSISTANT_VOCAB as $v) {
            if ($v[0] !== $w[0] || abs(strlen($v) - strlen($w)) > $max) continue;
            $d = levenshtein($w, $v);
            if ($d < $bestD) { $bestD = $d; $best = $v; }
        }
        return $best;
    }, mb_strtolower($msg));
}

/**
 * What the person asked about. Several topics can match one message; they are returned in the order they matter.
 * English first, with the common Bahasa Malaysia words for the same things.
 * @return string[]
 */
function assistant_topics(string $msg): array
{
    $m = ' ' . mb_strtolower($msg) . ' ';
    $has = fn(string $re) => (bool)preg_match($re, $m);
    $topics = [];

    // "What is the status of my application?" is about the person's own records, not about how to apply.
    if ($has('/\b(status|progress|track|tracking|approved|rejected|declined|pending|my (applications?|requests?|donations?|enrol?ments?|registrations?|bookings?|appointments?|events|classes|history|drafts?|submissions?)|application (id|number|ref\w*)|continue (my )?(draft|application)|did (i|you) (get|receive)|has (my|it) been|waiting for review|to review|needs? review|(my|the) receipts?|attendance|how many (classes|sessions|lessons))\b/')) $topics[] = 'my_status';

    if ($has('/\b(mass|masses|misa|service times?|mass times?|timetable|liturgy|worship)\b/')) $topics[] = 'mass';
    if ($has('/\b(baptis|baptiz|christen|godparent|godfather|godmother|pembaptisan)\w*/')) $topics[] = 'baptism';
    if ($has('/\b(first (holy )?communion|eucharist|komuni)\b/') && !$has('/\bmass times?\b/')) $topics[] = 'eucharist';
    if ($has('/\b(confirmation|krisma|penguatan)\b/')) $topics[] = 'confirmation';
    if ($has('/\b(confession|reconciliation|confess|penance|pengakuan)\b/')) $topics[] = 'confession';
    if ($has('/\b(anoint|last rites|viaticum|pengurapan)\w*/')) $topics[] = 'anointing';
    if ($has('/\b(marriage|wedding|matrimony|get married|married|marry|perkahwinan|kahwin|nikah)\b/')) $topics[] = 'matrimony';
    if ($has('/\b(mass intentions?|offer a mass|intention|intensi)\b/')) $topics[] = 'mass_intention';
    if ($has('/\b(pray|prayer|prayers|intercession|doa)\b/')) $topics[] = 'prayer';
    if ($has('/\b(counsel|counselling|counseling|spiritual direction|guidance|advice|kaunseling)\w*/')) $topics[] = 'counselling';
    if ($has('/\b(sick|ill|hospital|homebound|hospitali[sz]ed|dying|sakit)\b/') || ($has('/\bvisit\b/') && !$has('/\b(website|web ?site|page|site|facebook)\b/'))) $topics[] = 'sick_visit';
    if (in_array('anointing', $topics, true) && !$has('/\b(visit|hospital|homebound)\b/')) $topics = array_values(array_diff($topics, ['sick_visit']));
    if ($has('/\b(house blessing|home blessing|bless(ing)? (my|the|our|a) (new )?(house|home)|new home|berkat rumah)\b/')) $topics[] = 'house_blessing';
    if ($has('/\b(funeral|bereavement|passed away|died|death of|wake|burial|pengebumian|kematian)\b/')) $topics[] = 'funeral_assistance';
    if ($has('/\b(weekly collections?|collections?|kutipan)\b/')) $topics[] = 'collection';
    if ($has('/\b(donat|offering|tithe|contribut|bank|give money|how to give|derma|sumbangan|duitnow|qr)\w*/')) $topics[] = 'donation';
    if ($has('/\b(events?|calendar|activities|activity|upcoming|what\'?s on|acara|aktiviti)\b/')) $topics[] = 'events';
    if ($has('/\b(catechesis|catechism|rcia|sunday school|faith formation|classes?|lessons?|katekesis)\b/')) $topics[] = 'catechesis';
    if ($has('/\b(announcements?|news|notices?|pengumuman)\b/')) $topics[] = 'announcements';
    if ($has('/\b(bulletins?|newsletters?|buletin)\b/')) $topics[] = 'bulletins';
    if ($has('/\b(priests?|archbishop|bishop|clergy|rector|vicar|monsignor|msgr|chancellor|paderi|pastor)\b|\bfr\.? [a-z]/')) $topics[] = 'priests';
    if ($has('/\b(profile|home parish|(change|update|edit|set|tukar) (my |the )?(home )?(parish|church|name|phone|number|address|email|photo|picture|details|information|info|birthday|date of birth)|my (details|photo|picture)|profile (photo|picture))\b/')) $topics[] = 'profile';
    if ($has('/\b(contact|phone|telephone|call|email|e-mail|address|where is|located|location|office|(office|opening|operating|business) hours|opening|directions?|map|website|facebook|whatsapp|alamat|telefon|pejabat|hubungi)\b/')) $topics[] = 'contact';
    if ($has('/\bhow many (churches|parishes|priests|catholics|outstations|parishioners|members|people)\b|\b(catholic population|statistics|stats)\b/')) $topics[] = 'stats';
    if ($has('/\b(directory|other churches?|another (church|parish)|parishes|churches|find a church|nearest (church|parish)|which church|gereja)\b/')) $topics[] = 'directory';
    if ($has('/\b(about (the |this )?(archdiocese|portal|system|website|site|us)|archdiocese|archdiocesan|history|mission|vision|who (are|is) (we|the archdiocese))\b/')) $topics[] = 'about';
    if ($has('/\b(privacy|terms|cookies?|personal data|data protection|pdpa|policy|policies)\b/')) $topics[] = 'legal';
    if ($has('/\b(create an? account|sign ?up|new account|no account|don\'?t have an account|forgot(ten)? (my )?(password|username|email)|reset (my )?password|change (my )?password|can\'?t (log|sign) ?in|cannot (log|sign) ?in|(log|sign) ?in|login|(log|sign) ?(out|off)|logout|(delete|close|remove|deactivate) (my )?account|kata laluan|akaun)\b/')) $topics[] = 'account';
    if ($has('/\b(who are you|what are you|are you (a |an )?(robot|bot|human|ai|real|person)|your name|chatbot|what is this (assistant|chat))\b/')) $topics[] = 'identity';
    if ($has('/\b(emergency|urgent|urgently|kecemasan)\b/')) $topics[] = 'emergency';

    $specific = ['baptism', 'eucharist', 'confirmation', 'confession', 'anointing', 'matrimony'];
    $pastoral = ['mass_intention', 'prayer', 'counselling', 'sick_visit', 'house_blessing', 'funeral_assistance'];
    // A question about cost or paperwork is answered by the sacrament or service it names; on its own it gets the overview.
    if ($has('/\b(fees?|cost|costs|price|prices|how much|charges?|payment|bayaran|harga)\b/') && !array_intersect($topics, array_merge($specific, $pastoral, ['donation', 'collection', 'my_status']))) $topics[] = 'fees';
    if ($has('/\b(documents?|requirements?|paperwork|certificates?|what (do|should|must) i (need|bring|prepare|upload)|dokumen)\b/') && !array_intersect($topics, array_merge($specific, ['my_status']))) $topics[] = 'documents';

    // Bare "register"/"registration" only means the sacraments when nothing more specific has already matched.
    if ($has('/\bsacraments?\b|\bsakramen\b/') && !array_intersect($topics, $specific)) $topics[] = 'sacraments';
    if (!$topics && $has('/\b(register|registration|daftar)\b/')) $topics[] = 'sacraments';
    if ($has('/\b(help|what can you do|menu|options|tolong|bantuan)\b/')) $topics[] = 'help';
    if (!$topics && $has('/\b(hi|hello|hey|good (morning|afternoon|evening)|assalamualaikum|shalom|greetings|selamat (pagi|petang|malam|tengah ?hari)|hai)\b/')) $topics[] = 'greet';
    if (!$topics && $has('/\b(thanks?|thank you|terima kasih|god bless|amen|bye|goodbye)\b/')) $topics[] = 'thanks';

    $drop = [];
    // "offer a mass intention" is about intentions, not about Mass times
    if (in_array('mass_intention', $topics, true)) $drop[] = 'mass';
    // Joining an event is its own answer, and "sign up for the event" is not about creating an account.
    if (in_array('events', $topics, true) && $has('/\b(register|registration|join|sign ?up|attend|rsvp|book|cancel|my events)\b/')) {
        $topics[array_search('events', $topics, true)] = 'event_register';
        array_push($drop, 'account', 'sacraments', 'my_status');
    }
    if (in_array('profile', $topics, true)) array_push($drop, 'contact', 'directory', 'account');   // "change my email / address / parish"
    if (in_array('stats', $topics, true)) array_push($drop, 'directory', 'priests');                // "how many churches / priests"
    if (in_array('bulletins', $topics, true) && !$has('/\bannouncements?\b/')) $drop[] = 'announcements';
    if (in_array('priests', $topics, true) && !$has('/\b(contact|phone|call|email|address|office|hours)\b/')) $drop[] = 'contact';
    if (in_array('about', $topics, true) && array_intersect($topics, ['priests', 'stats', 'directory', 'donation', 'mass', 'events'])) $drop[] = 'about';   // "archbishop", "churches of the archdiocese"
    if (in_array('emergency', $topics, true) && array_intersect($topics, ['anointing', 'sick_visit', 'funeral_assistance'])) $drop[] = 'emergency';   // those answers already say whom to call
    if (count($topics) > 1) array_push($drop, 'greet', 'thanks');
    return array_values(array_diff($topics, $drop));
}

/** A reply: text (with **bold** markers), links to the right pages, and quick-reply chips. */
function assistant_answer(string $text, array $links = []): array
{
    return ['text' => $text, 'links' => $links];
}

/** Strip punctuation, collapse whitespace, lowercase — for comparing a message against a church name. */
function assistant_norm(string $s): string
{
    return trim(preg_replace('/\s+/', ' ', preg_replace('/[^\p{L}\p{N}\s]/u', ' ', mb_strtolower($s))));
}

/**
 * Which church (if any) is named in the message — so "What are the Mass times at St Michael Church?" answers
 * about St Michael, not whichever church the person is signed in under. Tries the full name first (longest
 * first, so "St Peter Claver Church" beats "St Peter Church"), then each name with "Church/Cathedral/Parish"
 * dropped, so a short form like "St Michael" still matches.
 */
function assistant_match_church(PDO $pdo, string $message): ?array
{
    static $churches = null;
    if ($churches === null) {
        $churches = $pdo->query('SELECT church_info_id, church_name FROM church_info')->fetchAll();
        usort($churches, fn($a, $b) => mb_strlen($b['church_name']) <=> mb_strlen($a['church_name']));
    }
    $m = ' ' . assistant_norm($message) . ' ';
    foreach ($churches as $c) {
        if (str_contains($m, ' ' . assistant_norm($c['church_name']) . ' ')) return $c;
    }
    foreach ($churches as $c) {
        $short = trim(preg_replace('/\b(church|cathedral|parish)\b/', '', assistant_norm($c['church_name'])));
        if ($short !== '' && preg_match('/(?:^| )' . preg_quote($short, '/') . '(?: |$)/', $m)) return $c;
    }
    return null;
}

/**
 * Priests named in the message ("who is Fr David Sham?"), with the churches they serve. Matched on the name without
 * titles, so "david sham", "Fr David Sham" and "Rev David Sham" all find the same priest.
 * @return array<string,array{name:string,roles:string[]}>
 */
function assistant_match_priests(PDO $pdo, string $message): array
{
    static $rows = null;
    try {
        $rows ??= $pdo->query('SELECT p.priest_name, p.priest_title, c.church_name FROM church_priests p JOIN church_info c ON c.church_info_id = p.church_id ORDER BY p.priest_title = \'Parish Priest\' DESC, c.church_name')->fetchAll();
    } catch (PDOException $ex) {
        $rows = [];
    }
    $skip = ['rev', 'fr', 'msgr', 'friar', 'most', 'very', 'abp', 'emeritus', 'dr', 'ofm', 'father'];
    $m = ' ' . assistant_norm($message) . ' ';
    $found = [];
    foreach ($rows as $r) {
        $words = array_values(array_filter(explode(' ', assistant_norm($r['priest_name'])), fn($w) => !in_array($w, $skip, true) && mb_strlen($w) > 1));
        if (count($words) < 2) continue;
        $key = implode(' ', $words);
        if (!str_contains($m, ' ' . $key . ' ') && !str_contains($m, ' ' . $words[0] . ' ' . end($words) . ' ')) continue;
        $found[$key]['name'] ??= $r['priest_name'];
        $found[$key]['roles'][] = ($r['priest_title'] ? $r['priest_title'] . ', ' : '') . $r['church_name'];
    }
    return $found;
}

/** The day a Mass question is about ("Sunday", "tomorrow", "this weekend"), as [label, weekday numbers 0-6], or null. */
function assistant_mass_day(string $mLow): ?array
{
    $days = [
        'sunday' => 0, 'ahad' => 0, 'minggu' => 0, 'monday' => 1, 'isnin' => 1, 'tuesday' => 2, 'selasa' => 2, 'wednesday' => 3, 'rabu' => 3,
        'thursday' => 4, 'khamis' => 4, 'friday' => 5, 'jumaat' => 5, 'saturday' => 6, 'sabtu' => 6,
    ];
    foreach ($days as $word => $n) {
        if (preg_match('/\b' . $word . 's?\b/', $mLow)) return [CAT_DAY_NAMES[$n], [$n]];
    }
    if (preg_match('/\b(today|tonight|hari ini)\b/', $mLow)) return ['today', [(int)date('w')]];
    if (preg_match('/\b(tomorrow|esok)\b/', $mLow)) return ['tomorrow', [(int)date('w', strtotime('+1 day'))]];
    if (preg_match('/\bweekends?\b/', $mLow)) return ['the weekend', [6, 0]];
    if (preg_match('/\bweekdays?\b/', $mLow)) return ['weekdays', [1, 2, 3, 4, 5]];
    return null;
}

/**
 * A parishioner's own records, newest first: sacrament applications, pastoral requests, donations, catechesis
 * enrolments and event registrations. $only narrows it to the kind the question named (or null for everything).
 * @return array{0:string[],1:array} lines and links
 */
function assistant_my_records(PDO $pdo, int $userId, ?string $only): array
{
    $lines = [];
    $links = [];
    $want = fn(string $kind) => $only === null || $only === $kind;

    if ($want('sacraments')) {
        $stmt = $pdo->prepare('SELECT application_id, sacrament, status, details, admin_note FROM sacrament_applications WHERE user_id = ? ORDER BY updated_at DESC, application_id DESC LIMIT 4');
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $a) {
            $d = json_decode((string)$a['details'], true) ?: [];
            $title = SACRAMENTS[$a['sacrament']][0] ?? ucfirst($a['sacrament']);
            if ($a['sacrament'] === 'confession') {
                $when = !empty($d['date']) ? date('D j M Y', strtotime($d['date'])) . (!empty($d['time']) ? ', ' . sx_time_label($d['time']) : '') : '';
                $lines[] = "• **Confession** booking" . ($when ? " for $when" : '') . ': ' . ($a['status'] === 'cancelled' ? 'Cancelled' : 'Confirmed');
            } else {
                $who = sx_subject($a['sacrament'], $d);
                $lines[] = "• **$title** " . sx_ref((int)$a['application_id']) . ($who !== '' ? " ($who)" : '') . ': ' . sx_status($a['status'])[0]
                    . ($a['status'] === 'draft' ? ' – not sent yet' : '') . ($a['admin_note'] && in_array($a['status'], ['rejected', 'cancelled'], true) ? ' – ' . $a['admin_note'] : '');
            }
        }
        if ($stmt->rowCount()) $links[] = ['My sacrament applications', 'sacraments.php'];
    }
    if ($want('pastoral')) {
        $stmt = $pdo->prepare('SELECT request_id, service, church_id, status, admin_note FROM pastoral_requests WHERE user_id = ? ORDER BY updated_at DESC, request_id DESC LIMIT 4');
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $r) {
            $lines[] = '• **' . pc_title($pdo, $r['service'], (int)$r['church_id']) . '** ' . pc_ref((int)$r['request_id']) . ': ' . pc_status($r['status'])[0]
                . ($r['status'] === 'draft' ? ' – not sent yet' : '') . ($r['admin_note'] && $r['status'] === 'declined' ? ' – ' . $r['admin_note'] : '');
        }
        if ($stmt->rowCount()) $links[] = ['My pastoral requests', 'pastoral-care.php'];
    }
    if ($want('donations')) {
        $stmt = $pdo->prepare('SELECT donation_id, amount, status, reject_reason, created_at FROM donations WHERE donor_user_id = ? ORDER BY created_at DESC, donation_id DESC LIMIT 3');
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $d) {
            $lines[] = '• **Donation** ' . donation_ref((int)$d['donation_id']) . ', ' . rm($d['amount']) . ' on ' . date('j M Y', strtotime($d['created_at'])) . ': ' . donation_status($d['status'])[0]
                . ($d['status'] === 'rejected' && $d['reject_reason'] ? ' – ' . $d['reject_reason'] : '');
        }
        if ($stmt->rowCount()) $links[] = ['Donation & Offering', 'donations.php'];
    }
    if ($want('catechesis')) {
        $stmt = $pdo->prepare('SELECT en.status, en.attendance, p.title, p.total_sessions FROM catechesis_enrolments en JOIN catechesis_programs p ON p.program_id = en.program_id WHERE en.user_id = ? ORDER BY en.updated_at DESC LIMIT 3');
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $en) {
            $lines[] = '• **' . $en['title'] . '** (catechesis): ' . cat_status($en['status'])[0]
                . ($en['status'] === 'draft' ? ' – not sent yet' : ((int)$en['total_sessions'] ? ', attended ' . (int)$en['attendance'] . ' of ' . (int)$en['total_sessions'] . ' sessions' : ''));
        }
        if ($stmt->rowCount()) $links[] = ['My catechesis programs', 'catechesis.php'];
    }
    if ($want('events')) {
        $stmt = $pdo->prepare('SELECT e.title, e.event_date, e.start_time, r.people FROM event_registrations r JOIN events e ON e.event_id = r.event_id WHERE r.user_id = ? AND e.event_date >= CURDATE() ORDER BY e.event_date, e.start_time LIMIT 3');
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $ev) {
            $lines[] = '• **' . $ev['title'] . '** on ' . date('D j M Y', strtotime($ev['event_date'])) . ($ev['start_time'] ? ', ' . fmt_time($ev['start_time']) : '') . ': registered (' . people_label((int)$ev['people']) . ')';
        }
        if ($stmt->rowCount()) $links[] = ['My registered events', 'my-events.php'];
    }
    return [$lines, $links];
}

/**
 * How-to answers for the admins who run the portal: [what the question must mention, the answer, links, Super Admin only?].
 * The first entries win, so the more specific tasks come first. Links are from the site root.
 */
function assistant_admin_tasks(bool $super): array
{
    $events = $super ? 'events.php?church=0&view=manage' : 'events.php?view=manage';
    return [
        ['/\b(confession)\b/', 'Open **Confession slots** to add the days and times parishioners can book, write the notes they see, and cancel a booking.', [['Confession slots', 'confession-manage.php']], false],
        ['/\b(deadline|close (the )?(applications?|registrations?)|open (the )?(applications?|registrations?)|notes?|forms?|baptism dates?|briefing)\b/', 'Open **Forms & notes** to switch a sacrament\'s applications on or off, set the last day to apply, and edit the notes parishioners see (baptism dates, briefing, deadline).', [['Forms & notes', 'sacrament-schedule.php']], false],
        ['/\b(sacraments?|baptism|eucharist|communion|confirmation|matrimony|wedding|marriage|anointing|applications?)\b/', 'Sacrament applications are reviewed under **Sacraments → Applications**: open one to check the details and documents, then approve it or reject it with a reason. The parishioner sees the result on their status page.', [['Sacrament applications', 'sacrament-review.php']], false],
        ['/\b(pastoral services?|services?)\b/', $super
            ? 'Each church\'s Church Admin manages its Pastoral & Spiritual Care services. As Super Admin you can view them, with their open or closed status and request counts, under **Pastoral Care → Services**.'
            : 'Under **Pastoral Care → Services** you can add a service, change its name, description and photo, close or reopen it for requests, and delete or restore it.',
            [['Pastoral services', 'pastoral-services.php']], false],
        ['/\b(pastoral|prayers?|mass intentions?|counsel\w*|sick visits?|house blessings?|funerals?|requests?)\b/', 'Pastoral requests (prayer, Mass intentions, counselling, sick visits, house blessings, funeral assistance) are handled under **Pastoral Care**: open a request, then approve or decline it with a note.', [['Pastoral Care', 'pastoral-manage.php']], false],
        ['/\b(weekly collections?|collections?)\b/', 'Record each week\'s collection under **Weekly collection**. The figures appear as a chart on the Donation & Offering page.', [['Weekly collection', 'collection-manage.php']], false],
        ['/\b(bank|account number|qr|duitnow|payment methods?|donation settings?)\b/', 'Set the bank account and the QR code donors pay to under **Bank details**. Online giving stays off for a church until one of them is set.', [['Bank details', 'donation-settings.php']], true],
        ['/\b(donations? reports?|export|csv)\b/', 'Open **Donation reports** for totals across the parishes, filters by date and church, and an export.', [['Donation reports', 'donation-report.php']], true],
        ['/\b(donat\w*|receipts?|offerings?)\b/', 'Donations wait under **Manage donations**: open one to see the receipt, then approve or reject it. The donor is emailed the result.', [['Manage donations', 'donation-manage.php']], false],
        ['/\b(bulletins?|newsletters?)\b/', 'Bulletins are on the **Announcements** page, Bulletins tab: use **New Bulletin** to upload the PDF with its title, date, topic and language, or the pencil and bin on an existing one.', [['Bulletins', 'announcements.php?tab=bulletins']], false],
        ['/\b(announcements?|notices?|news)\b/', 'Open **Announcements**' . ($super ? ', pick the church,' : '') . ' and use **New Announcement**. You can pin it to the top, mark it urgent, and set the day it appears and the day it expires.', [['Announcements', 'announcements.php']], false],
        ['/\b(participants?|attendees?|who (has )?registered|email (the )?(participants|attendees))\b/', 'On the **Events** page open the event to see who has registered, and to email the participants.', [['Manage events', $events]], false],
        ['/\b(events?|mass times?|mass schedule|masses|calendar)\b/', 'Use **Events** to add an event or a Mass time. A Mass can repeat weekly; an event can ask for registration and limit the number of places.', [['Manage events', $events]], false],
        ['/\b(catechesis|programs?|classes|attendance|enrol\w*|students?)\b/', 'Under **Catechesis** you can add a program, see who is enrolled and mark attendance for each session.', [['Catechesis', 'catechesis-manage.php']], false],
        ['/\b(priests?|clergy)\b/', 'Priests are edited on **Church Information**, in the Priests card: add a priest, change the name, title, dates, details or photo, or remove one. A Church Admin can do this for their own church only.', [['Church Information', 'church-information.php#priests']], false],
        ['/\b(church admins?|admins?|invite)\b/', 'Church Admins are added under **Admins**: enter the name and email and choose the church, and send them the link to set their password.', [['Admins', 'superadmin/admins.php']], true],
        ['/\b(parishioners?|users?|members?)\b/', 'Use **Add Parishioner** to create an account for someone who cannot register themselves.', [['Add Parishioner', 'user-form.php']], false],
        ['/\b(statistics|stats|home ?page|numbers)\b/', 'The figures on the home page (parishes, priests, Catholic population) are edited under **Home Page**.', [['Home Page', 'superadmin/statistics.php']], true],
        ['/\b(about( us)?|contact details of the archdiocese|footer)\b/', 'The About Us text, photo and the Archdiocese contact details in the footer are edited under **About Us**.', [['About Us', 'superadmin/about.php']], true],
        ['/\b(church|churches|parish|parishes|church information|office hours|address|phone)\b/', $super
            ? 'Use **New Church** to add a parish, or open **Church Information**, pick the church and use the pencil icons to change its photo, name, town, contact details and priests. **Delete this church** is at the bottom of that page; a church with donation records cannot be deleted.'
            : 'Open **Church Information** and use the pencil icons to change your church\'s photo, contact details, office hours and priests.',
            $super ? [['Church Information', 'church-information.php'], ['New Church', 'superadmin/church-form.php']] : [['Church Information', 'church-information.php']], false],
        ['/\b(reports?|print)\b/', 'The report button at the top of your dashboard opens a printable report of everything: applications, requests, donations and enrolments.', [['Full report', 'report-all.php']], false],
        ['/\b(my profile|my account|my password|my photo)\b/', 'Your own name, photo and password are under **My Profile**.', [['My Profile', 'account.php']], false],
    ];
}

function assistant_reply(PDO $pdo, string $message, int $churchId, array $who): array
{
    // A church named in the message (e.g. "St Michael Church") always wins over the person's own parish,
    // so anyone can ask about any church, not only the one they are signed in under.
    $ownChurchId = $churchId;   // before any override: a parishioner's actual home parish
    $named = assistant_match_church($pdo, $message);
    $askedOther = $named !== null && (int)$named['church_info_id'] !== $churchId;
    if ($named !== null) $churchId = (int)$named['church_info_id'];

    $stmt = $pdo->prepare('SELECT c.church_name, c.church_location, c.church_address, c.church_contact_details, c.church_email, c.church_office_hours, c.church_website, c.church_facebook, c.church_whatsapp, ' . PRIEST_NAMES_SQL . ' AS church_priest, c.donation_bank_name, c.donation_account_name, c.donation_account_number FROM church_info c WHERE c.church_info_id = ?');
    $stmt->execute([$churchId]);
    $church = $stmt->fetch() ?: null;
    $role = $who['role'] ?? '';
    $isUser = $role === 'user';
    $isAdmin = in_array($role, ['super_admin', 'church_admin'], true);
    $isSuper = $role === 'super_admin';
    $userId = (int)($who['id'] ?? 0);
    $name = $church['church_name'] ?? 'your parish';
    $mLow = mb_strtolower($message);
    $mFixed = assistant_fix_typos($message);
    // What was typed first, then anything a corrected spelling adds ("baptisim" -> baptism).
    $topics = array_values(array_unique(array_merge(assistant_topics($message), assistant_topics($mFixed))));
    // Naming a church with no other clear question ("Tell me about St Michael Church") is still a real
    // request — answer with that church's general details instead of "I didn't understand".
    $namedPriests = assistant_match_priests($pdo, $message);
    if ($namedPriests && !in_array('priests', $topics, true)) array_unshift($topics, 'priests');
    if (!$topics && $askedOther) $topics = ['contact'];

    // Online applications always go to the person's own home parish, never to whichever church is being
    // discussed — say so instead of silently offering a "Register" link that would submit somewhere else.
    $ownNote = '';
    if ($isUser && $askedOther && $ownChurchId) {
        $ownStmt = $pdo->prepare('SELECT church_name FROM church_info WHERE church_info_id = ?');
        $ownStmt->execute([$ownChurchId]);
        $ownName = $ownStmt->fetchColumn();
        if ($ownName) $ownNote = " Note: your application would still be sent to your own home parish, **$ownName**, not to $name.";
    }

    $parts = [];
    $links = [];
    $add = function (string $text, array $l = []) use (&$parts, &$links) { $parts[] = $text; foreach ($l as $x) $links[$x[1]] = $x; };
    $done = function () use (&$parts, &$links) { return ['text' => implode("\n\n", $parts), 'links' => array_values($links), 'chips' => ASSISTANT_CHIPS]; };
    $office = $church && $church['church_contact_details'] ? ' You can also call the parish office on ' . $church['church_contact_details'] . '.' : ' Please contact the parish office.';

    // An admin asking how to do something in the portal ("how do I add a bulletin?") gets the page for that job.
    if ($isAdmin && preg_match('/\b(add|create|post|publish|new|edit|update|change|delete|remove|manage|review|approve|reject|decline|verify|export|print|download|set|set ?up|switch|turn|upload|invite|assign|record|mark|pin|cancel|participants?|attendees?|who (has )?registered|how (do|can|to)|where (do|can|is|are))\b/', $mFixed)) {
        $given = 0;
        foreach (assistant_admin_tasks($isSuper) as [$re, $text, $taskLinks, $superOnly]) {
            if (!preg_match($re, $mFixed)) continue;
            $add($superOnly && !$isSuper ? 'That is managed by the Super Admin of the Archdiocese, not by a Church Admin. Please ask them to do it for you.' : $text, $superOnly && !$isSuper ? [] : $taskLinks);
            if (++$given === (str_contains($mFixed, ' and ') ? 2 : 1)) break;
        }
        if ($given) return $done();
    }

    if (!$topics) {
        return ['text' => "I'm sorry, I didn't quite understand that. I can help with **Mass times**, **sacraments** (Baptism, Eucharist, Confirmation, Confession, Anointing of the Sick, Matrimony), **prayer requests and Mass intentions**, **counselling, sick visits, house blessings and funeral assistance**, **donations**, **events**, **catechesis**, **announcements and bulletins**, **priests and contact details**, **the status of your applications** and **your account or profile**. Try one of the buttons below, or ask in your own words." . ($church && $church['church_contact_details'] ? " For anything else, the parish office of **$name** is on {$church['church_contact_details']}." : ''), 'links' => [], 'chips' => ASSISTANT_CHIPS, 'matched' => false];
    }

    // "Status of my baptism application": only the person's records are wanted, not the how-to for Baptism as well.
    if (in_array('my_status', $topics, true)) {
        $about = array_values(array_diff($topics, ['my_status', 'help']));
        $kind = null;
        foreach ([
            'sacraments' => ['baptism', 'eucharist', 'confirmation', 'confession', 'anointing', 'matrimony', 'sacraments'],
            'pastoral'   => ['mass_intention', 'prayer', 'counselling', 'sick_visit', 'house_blessing', 'funeral_assistance'],
            'donations'  => ['donation', 'collection'],
            'catechesis' => ['catechesis'],
            'events'     => ['events', 'event_register'],
        ] as $k => $ts) {
            if (array_intersect($about, $ts)) { $kind = $k; break; }
        }
        if ($kind === null && preg_match('/\b(requests?)\b/', $mFixed) && !preg_match('/\bapplications?\b/', $mFixed)) $kind = 'pastoral';
        if ($kind === null && preg_match('/\b(enrol?ments?|classes|attendance|sessions|lessons)\b/', $mFixed)) $kind = 'catechesis';
        if ($kind === null && preg_match('/\breceipts?\b/', $mFixed)) $kind = 'donations';
        if ($kind === null && preg_match('/\b(bookings?|appointments?)\b/', $mFixed)) $kind = 'sacraments';

        if ($isAdmin) {
            $scope = $isSuper ? null : $ownChurchId;
            $rows = [['Sacrament applications', 'sacraments'], ['Pastoral requests', 'pastoral'], ['Donations to review', 'donations']];
            $lines = [];
            foreach ($rows as [$label, $queue]) {
                $n = review_count($pdo, $queue, $scope);
                $lines[] = "• **$label:** " . ($n ? "$n waiting" : 'all clear');
                $links[REVIEW_QUEUES[$queue]['page']] = [$label, REVIEW_QUEUES[$queue]['page']];
            }
            $parts[] = 'Waiting for review ' . ($isSuper ? 'across every church' : "at **$name**") . ":\n" . implode("\n", $lines);
            return $done();
        }
        if (!$userId) { $add('Please sign in to see your own applications, requests and donations.', [['Login / Register', 'login.php']]); return $done(); }
        [$lines, $recLinks] = assistant_my_records($pdo, $userId, $kind);
        $what = ['sacraments' => 'sacrament applications', 'pastoral' => 'pastoral requests', 'donations' => 'donations', 'catechesis' => 'catechesis enrolments', 'events' => 'event registrations'][$kind ?? ''] ?? 'records';
        if ($lines) {
            $add("Here is where your $what stand:\n" . implode("\n", $lines) . "\nOpen the page below for the full details and any notes from the parish office.", $recLinks);
        } else {
            $add($kind === null
                ? 'You have not sent any applications, requests or donations through the portal yet. When you do, you can follow each one on its status page, and I can tell you where it stands.'
                : "I could not find any $what under your account yet.",
                [['Sacraments', 'sacraments.php'], ['Pastoral & Spiritual Care', 'pastoral-care.php']]);
        }
        return $done();
    }

    $topics = array_slice(array_values(array_unique($topics)), 0, 2);   // answer at most two topics per message so replies stay short
    if (count($topics) === 2 && in_array($topics[0], ['greet', 'thanks', 'help'], true)) $topics = [$topics[1]];

    // Nothing to mix up: a parishioner with no home parish set gets no church's Mass times, contact number or bank
    // account substituted for another's. Say so up front instead of silently answering with nothing specific.
    $general = ['greet', 'thanks', 'help', 'directory', 'account', 'contact', 'profile', 'stats', 'about', 'legal', 'identity', 'fees', 'documents', 'sacraments', 'priests', 'emergency'];
    if ($isUser && !$church && array_diff($topics, $general)) {
        $add('You have not chosen your home parish yet, so I can only answer in general here. Please set your home parish on your profile so I can give you details from your own church.', [['Set your home parish', 'profile.php']]);
    }

    foreach ($topics as $t) {
        switch ($t) {
            case 'greet':
                $add("Hello" . (($who['name'] ?? '') ? ', ' . strtok($who['name'], ' ') : '') . "! I am your Intelligent Church Assistant for **$name**. Ask me about Mass times, sacraments, prayer, donations, events, catechesis, your priests, or the status of your applications.");
                break;

            case 'thanks':
                $add("You're welcome! God bless you. Is there anything else I can help you with?");
                break;

            case 'identity':
                $add('I am the **Intelligent Church Assistant** of this portal: an automatic helper, not a person. I answer from the portal\'s own information (Mass times, sacraments, events, priests, your own applications) and I never make up times or prices. For anything I cannot answer, the parish office can help.' . ($church ? $office : ''));
                break;

            case 'help':
                $add($isAdmin
                    ? "I can tell you **what is waiting for review**, and where to **add or manage** announcements, bulletins, events and Mass times, sacrament applications, confession slots, pastoral requests, donations, weekly collections, catechesis programs, priests and church details. I can also answer anything a parishioner would ask. What would you like to do?"
                    : "I can help with: **Mass times**, **sacraments** and how to register, **prayer requests**, **Mass intentions**, **counselling**, **sick visits**, **house blessings**, **funeral assistance**, **donations**, **the church calendar and event registration**, **catechesis programs**, **announcements and bulletins**, **your priests and parish contact details**, **other churches**, **the status of your applications**, and **your account and profile**. What would you like to know?");
                break;

            case 'mass':
                $today = date('Y-m-d');
                $to = date('Y-m-d', strtotime('+7 days'));
                $stmt = $pdo->prepare("SELECT * FROM events WHERE church_id = ? AND category = 'mass' AND (repeat_weekly = 1 OR event_date BETWEEN ? AND ?)");
                $stmt->execute([$churchId, $today, $to]);
                $occ = [];
                foreach ($stmt->fetchAll() as $e) foreach (event_occurrences($e, $today, $to) as $d) $occ[] = ['date' => $d, 'event' => $e];
                foreach (['mandarin', 'chinese', 'english', 'bahasa', 'malay', 'tamil', 'kadazan'] as $lang) {   // "the English Mass": show only that language when one is named
                    if (str_contains($mLow, $lang)) { $only = array_values(array_filter($occ, fn($o) => stripos($o['event']['title'], $lang) !== false)); if ($only) $occ = $only; break; }
                }
                sort_occurrences($occ);
                $dayNote = '';
                if ($occ && ($day = assistant_mass_day($mLow))) {   // "Mass on Sunday", "Mass tomorrow"
                    $onDay = array_values(array_filter($occ, fn($o) => in_array((int)date('w', strtotime($o['date'])), $day[1], true)));
                    if ($onDay) $occ = $onDay;
                    else $dayNote = "I could not find a Mass for {$day[0]} at **$name** in the portal. ";
                }
                if (!$occ) {
                    $add("I couldn't find any Mass times for **$name** in the portal yet." . $office, [['Church Calendar', 'events.php']]);
                } else {
                    $lines = [];
                    foreach (array_slice($occ, 0, 6) as $o) $lines[] = '• **' . date('D j M', strtotime($o['date'])) . '**, ' . fmt_time($o['event']['start_time']) . ' – ' . $o['event']['title'] . ($o['event']['location'] ? ' (' . $o['event']['location'] . ')' : '');
                    $add($dayNote . "Here are the upcoming Masses at **$name**:\n" . implode("\n", $lines) . "\nFor the full schedule, open the Church Calendar.", [['Open Church Calendar', 'events.php']]);
                }
                break;

            case 'baptism': case 'eucharist': case 'confirmation': case 'matrimony': case 'anointing':
                $cfg = sx_config($t);
                $need = array_map(fn($d) => $d[0], array_filter($cfg['docs'], fn($d) => $d[2]));
                $intro = [
                    'baptism' => 'To register a child for **Baptism** (infants up to 7 years old), complete the online form: the child\'s details, the parents and a godparent, and upload the documents.',
                    'eucharist' => 'For **First Holy Communion (Eucharist)**, the candidate must have completed the required catechesis classes first. Then fill in the candidate and guardian details.',
                    'confirmation' => 'For **Confirmation**, the candidate must have completed the required catechesis classes. You will give the candidate\'s details, the name chosen for confirmation and a sponsor.',
                    'matrimony' => 'To plan a **wedding (Matrimony)**, fill in the couple\'s details and your proposed wedding date. Days already taken are greyed out, so please apply well ahead.',
                    'anointing' => 'The **Anointing of the Sick** is for anyone seriously ill, not only at the point of death. Use the request form to tell the priest where the patient is and when a visit would suit.',
                ][$t];
                $docs = $need ? ' Please have these ready: ' . implode(', ', array_map(fn($x) => strtolower($x), $need)) . '.' : '';
                $fee = !empty($cfg['fee']) ? ' A registration fee of ' . $cfg['fee'] . ' applies, paid by bank transfer with a receipt upload.' : '';
                $stmt = $pdo->prepare('SELECT * FROM sacrament_schedules WHERE church_id = ? AND sacrament = ?');
                $stmt->execute([$churchId, $t]);
                $sch = $stmt->fetch();
                $note = '';
                if ($sch && $t === 'baptism') {
                    $note = "\n**$name's baptism notes:**" . ($sch['line_date'] ? "\n• Baptism: {$sch['line_date']}" : '') . ($sch['line_brief'] ? "\n• Godparent briefing: {$sch['line_brief']}" : '') . ($sch['line_close'] ? "\n• Registration deadline: {$sch['line_close']}" : '');
                } elseif ($sch && $t === 'anointing' && $sch['line_date']) {
                    $note = "\nFor an after-hours emergency (danger of death) please call **{$sch['line_date']}** straight away.";
                }
                // Whether this church is taking applications right now (set on Forms & notes).
                $intake = $church ? sx_intake($pdo, $churchId, $t) : ['open' => true, 'until' => null, 'why' => null];
                if (!$intake['open']) {
                    $note .= "\n**Please note:** $name is not taking {$cfg['title']} applications online at the moment" . ($intake['why'] === 'deadline' && $intake['until'] ? ' (they closed on ' . sx_day($intake['until']) . ')' : '') . '.' . $office;
                } elseif ($intake['until']) {
                    $note .= "\nApplications are open until **" . sx_day($intake['until']) . '**.';
                }
                $add($intro . $docs . $fee . ($isUser ? ' Your church reviews the application and you can follow its progress on the status page.' : '') . $note . $ownNote,
                    $isUser ? [['Register for ' . $cfg['title'], 'sacrament-apply.php?type=' . $t]] : [['Sacraments', 'sacraments.php']]);
                break;

            case 'confession':
                $stmt = $pdo->prepare("SELECT slot_date, TIME_FORMAT(slot_time, '%H:%i') t FROM confession_slots WHERE church_id = ? AND slot_date >= CURDATE() ORDER BY slot_date, slot_time LIMIT 3");
                $stmt->execute([$churchId]);
                $slots = $stmt->fetchAll();
                $text = "You can book **Confession** online: pick a day and a time and confirm.";
                if ($slots) {
                    $text .= " The next times open at **$name**:\n" . implode("\n", array_map(fn($s) => '• ' . date('D j M', strtotime($s['slot_date'])) . ', ' . sx_time_label($s['t']), $slots));
                } else {
                    $text .= " **$name** has not opened any confession times yet." . $office;
                }
                $add($text . $ownNote, [['Book Confession', 'sacrament-confession.php']]);
                break;

            case 'sacraments':
                $add("Sacraments you can register for online at **$name**: **Baptism**, **Eucharist**, **Confirmation**, **Confession**, **Anointing of the Sick** and **Matrimony**. Each has a short form, document uploads and a status page so you can follow your application. Which one are you interested in?", [['Open Sacraments', 'sacraments.php']]);
                break;

            case 'fees':
                $lines = [];
                foreach (SACRAMENTS as $key => $s) {
                    if ($key === 'confession') continue;
                    $cfg = sx_config($key);
                    $lines[] = '• **' . $s[0] . ':** ' . (!empty($cfg['fee']) ? $cfg['fee'] . ' registration fee' : 'no fee listed in the portal');
                }
                foreach (PASTORAL as $key => $p) {
                    $cfg = pc_config($key);
                    if (!empty($cfg['fee'])) $lines[] = '• **' . $p[0] . ':** a contribution of ' . $cfg['fee'] . ' is appreciated';
                }
                $add("Here is what the portal lists for fees:\n" . implode("\n", $lines) . "\nConfession, prayer requests, counselling, sick visits, house blessings and funeral assistance have no fee in the portal. Fees are paid by bank transfer with a receipt upload. Donations are always voluntary.", [['Sacraments', 'sacraments.php'], ['Donation & Offering', 'donations.php']]);
                break;

            case 'documents':
                $lines = [];
                foreach (SACRAMENTS as $key => $s) {
                    $cfg = sx_config($key);
                    $need = $cfg && !empty($cfg['docs']) ? array_map(fn($d) => strtolower($d[0]), array_filter($cfg['docs'], fn($d) => $d[2])) : [];
                    if ($need) $lines[] = '• **' . $s[0] . ':** ' . implode(', ', $need);
                }
                $add("These are the documents each sacrament asks you to upload:\n" . implode("\n", $lines) . "\nFiles can be PDF, JPG or PNG, up to 5 MB each, and only you and your church's administrators can open them.", [['Sacraments', 'sacraments.php']]);
                break;

            case 'mass_intention': case 'prayer': case 'counselling': case 'sick_visit': case 'house_blessing': case 'funeral_assistance':
                $svc = $t;
                $pc = pc_config($svc);
                $text = [
                    'mass_intention' => 'You can offer a **Mass intention** for a loved one, a thanksgiving or a special occasion. A contribution of RM10.00 is appreciated, paid by bank transfer with a receipt upload.',
                    'prayer' => 'You can send a **prayer request** to the church\'s prayer community. Tell us your intention, and choose whether the community may see it or only the priests. Contact details are optional.',
                    'counselling' => 'You can ask for a private **counselling session** for spiritual direction or pastoral care. Choose the session type, in-person or online, and pick a free date and time on the calendar.',
                    'sick_visit' => 'For a **sick visit**, tell us the patient\'s name, where they are, whether they can receive Holy Communion and who we should contact. A priest or minister will follow up. If it is an emergency, please call the parish office straight away.' . ($church && $church['church_contact_details'] ? ' (' . $church['church_contact_details'] . ')' : ''),
                    'house_blessing' => 'You can request a **house blessing**: tell us your address, a preferred date and time, and a contact number, and a priest will follow up to confirm.',
                    'funeral_assistance' => 'For **funeral assistance**, tell us the deceased\'s name, the date of death, the preferred date and time, the venue and a contact person. For an urgent need, please call the parish office directly.' . ($church && $church['church_contact_details'] ? ' (' . $church['church_contact_details'] . ')' : ''),
                ][$svc];
                $row = $church ? pc_service($pdo, $churchId, $svc) : null;   // has this church closed or removed it?
                $canAsk = !$row || ($row['deleted_at'] === null && (int)$row['is_open']);
                if ($row && $row['deleted_at'] !== null) $text .= "\n**Please note:** $name does not offer this service online." . $office;
                elseif ($row && !(int)$row['is_open']) $text .= "\n**Please note:** $name is not taking these requests online at the moment." . $office;
                $add($text . $ownNote, $isUser && $canAsk ? [[$pc['title'] . ' request', 'pastoral-request.php?service=' . $svc]] : [['Pastoral & Spiritual Care', 'pastoral-care.php']]);
                if ($svc === 'sick_visit' && $isUser) $links['sacrament-apply.php?type=anointing'] = ['Request Anointing of the Sick', 'sacrament-apply.php?type=anointing'];
                break;

            case 'emergency':
                $add('For an **emergency**, please do not wait for an online form: call the parish office straight away' . ($church && $church['church_contact_details'] ? " on **{$church['church_contact_details']}**" : '') . '. For someone seriously ill or dying you can also request the Anointing of the Sick, and for a death in the family, funeral assistance.',
                    $isUser ? [['Request Anointing of the Sick', 'sacrament-apply.php?type=anointing'], ['Funeral Assistance request', 'pastoral-request.php?service=funeral_assistance']] : [['Church Information', 'church-information.php']]);
                break;

            case 'donation':
                $bank = $church && $church['donation_bank_name'] && $church['donation_account_number']
                    ? "\n**Bank:** {$church['donation_bank_name']}\n**Account name:** {$church['donation_account_name']}\n**Account number:** {$church['donation_account_number']}"
                    : "\nThe parish office will share the bank details.";
                $add("You can give to **$name** by bank transfer or QR Pay (DuitNow) where the church has set it up, then record your donation with the receipt on the Donation & Offering page. The parish office reviews it and emails you when it is approved; ask me for the **status of my donations** at any time." . $bank, [['Donation & Offering', 'donations.php']]);
                break;

            case 'collection':
                $rows = $church ? collection_rows($pdo, $churchId, 1) : [];
                $add($rows
                    ? "The latest weekly collection recorded for **$name** is **" . rm($rows[0]['amount']) . '** for the week of ' . date('j M Y', strtotime($rows[0]['week_date'])) . ($rows[0]['note'] ? ' (' . $rows[0]['note'] . ')' : '') . '. The Donation & Offering page shows the chart for recent weeks.'
                    : "No weekly collection has been recorded for **$name** in the portal yet.",
                    [['Donation & Offering', 'donations.php']]);
                break;

            case 'events':
                $today = date('Y-m-d');
                $to = date('Y-m-d', strtotime('+30 days'));
                $stmt = $pdo->prepare("SELECT * FROM events WHERE church_id = ? AND category = 'event' AND (repeat_weekly = 1 OR event_date BETWEEN ? AND ?)");
                $stmt->execute([$churchId, $today, $to]);
                $occ = [];
                foreach ($stmt->fetchAll() as $e) foreach (event_occurrences($e, $today, $to) as $d) $occ[] = ['date' => $d, 'event' => $e];
                sort_occurrences($occ);
                if (!$occ) $add("There are no events on **$name**'s calendar for the next 30 days.", [['Church Calendar', 'events.php']]);
                else $add("Coming up at **$name**:\n" . implode("\n", array_map(fn($o) => '• **' . date('D j M', strtotime($o['date'])) . '**, ' . event_time_text($o['event']) . ' – ' . $o['event']['title'] . ($o['event']['registration_required'] ? ' (registration needed)' : ''), array_slice($occ, 0, 5))), [['Open Church Calendar', 'events.php']]);
                break;

            case 'event_register':
                $stmt = $pdo->prepare("SELECT title, event_date, start_time FROM events WHERE church_id = ? AND category = 'event' AND registration_required = 1 AND repeat_weekly = 0 AND event_date >= CURDATE() ORDER BY event_date, start_time LIMIT 3");
                $stmt->execute([$churchId]);
                $open = $stmt->fetchAll();
                $add('To join an event, open the **Church Calendar**, choose the event and press **Register**: give your name, contact and how many people are coming. Only events that ask for registration show the button, and places can be limited. Under **My Events** you can see what you have registered for and cancel a registration until the event has taken place.'
                    . ($open ? "\nEvents at **$name** open for registration:\n" . implode("\n", array_map(fn($e) => '• **' . date('D j M', strtotime($e['event_date'])) . '**' . ($e['start_time'] ? ', ' . fmt_time($e['start_time']) : '') . ' – ' . $e['title'], $open)) : ($church ? "\nNo event at **$name** is asking for registration right now." : '')),
                    $isUser ? [['Open Church Calendar', 'events.php'], ['My Events', 'my-events.php']] : [['Open Church Calendar', 'events.php']]);
                if ($isUser && $userId) {
                    [$mine] = assistant_my_records($pdo, $userId, 'events');
                    $parts[count($parts) - 1] = ($mine ? "You are registered for:\n" . implode("\n", $mine) : 'You are not registered for any upcoming event.') . "\n" . $parts[count($parts) - 1];
                }
                break;

            case 'catechesis':
                $stmt = $pdo->prepare('SELECT * FROM catechesis_programs WHERE church_id = ? ORDER BY title LIMIT 6');
                $stmt->execute([$churchId]);
                $progs = $stmt->fetchAll();
                $catNote = $ownNote ? str_replace('your application', 'your enrolment', $ownNote) : '';
                $add(($progs
                    ? "**$name** offers these catechesis programs:\n" . implode("\n", array_map(fn($p) => '• **' . $p['title'] . '** – ' . cat_schedule_text($p) . ($p['location'] ? ' (' . $p['location'] . ')' : ''), $progs)) . "\nYou can register online and follow your attendance and progress."
                    : "**$name** has not opened any catechesis programs yet." . $office) . $catNote,
                    [['Catechesis Programs', 'catechesis.php']]);
                break;

            case 'announcements':
                $stmt = $pdo->prepare('SELECT title, publish_date FROM announcements WHERE church_id = ? AND publish_date <= CURDATE() AND (expires_date IS NULL OR expires_date >= CURDATE()) ORDER BY is_pinned DESC, publish_date DESC LIMIT 3');
                $stmt->execute([$churchId]);
                $an = $stmt->fetchAll();
                $add($an ? "Latest from **$name**:\n" . implode("\n", array_map(fn($a) => '• ' . $a['title'] . ' (' . date('j M Y', strtotime($a['publish_date'])) . ')', $an)) : "There are no current announcements for **$name**.", [['Announcements & Bulletins', 'announcements.php']]);
                break;

            case 'bulletins':
                $stmt = $pdo->prepare('SELECT title, language, bulletin_date FROM bulletins WHERE church_id = ? ORDER BY bulletin_date DESC, bulletin_id DESC LIMIT 3');
                $stmt->execute([$churchId]);
                $bl = $stmt->fetchAll();
                $add($bl
                    ? "The latest bulletins from **$name**:\n" . implode("\n", array_map(fn($b) => '• ' . $b['title'] . ' (' . date('j M Y', strtotime($b['bulletin_date'])) . ', ' . (BULLETIN_LANGUAGES[$b['language']] ?? $b['language']) . ')', $bl)) . "\nOpen the Bulletins tab to read or download them, and to search by month or language."
                    : "**$name** has not published any bulletins in the portal yet.",
                    [['Digital Bulletins', 'announcements.php?tab=bulletins']]);
                break;

            case 'priests':
                $out = [];
                // The Archbishop is asked about whatever the person's parish is.
                if (preg_match('/\b(archbishop|bishop)\b/', $mFixed)) {
                    $abp = $pdo->query("SELECT priest_name, priest_title FROM church_priests WHERE priest_title LIKE 'Archbishop%' ORDER BY priest_title = 'Archbishop of Kota Kinabalu' DESC, priest_id LIMIT 1")->fetch();
                    $out[] = $abp ? "The **{$abp['priest_title']}** is **{$abp['priest_name']}**." : 'The portal does not list the Archbishop yet.';
                }
                foreach ($namedPriests as $np) {
                    $out[] = "**{$np['name']}** serves as:\n" . implode("\n", array_map(fn($r) => '• ' . $r, array_slice($np['roles'], 0, 6)));
                }
                $rows = $church && (!$namedPriests || $named !== null) ? church_priests($pdo, $churchId) : [];
                if ($namedPriests && !$rows) {
                    $out[] = 'On the Church Information page you can tap a priest to see their photo and details.';
                } elseif ($rows) {
                    $out[] = (count($rows) > 1 ? "The priests of **$name**:" : "The priest of **$name**:") . "\n" . implode("\n", array_map(fn($p) => '• **' . $p['priest_name'] . '**' . ($p['priest_title'] ? ' – ' . $p['priest_title'] : ''), $rows))
                        . "\nOn the Church Information page you can tap a priest to see their photo and details.";
                } elseif ($church) {
                    $out[] = "The portal does not list a priest for **$name** yet." . $office;
                } elseif (!$out) {
                    $out[] = 'Please choose your home parish on your profile, or name a church (for example "priests of St Michael Church"), and I will list its priests.';
                }
                $add(implode("\n", $out), [['Church Information', 'church-information.php']]);
                break;

            case 'contact':
                if (!$church) { $add('Please choose your home parish on your profile so I can share your church\'s contact details.', [['My profile', 'profile.php']]); break; }
                $lines = array_filter([
                    $church['church_address'] ? '**Address:** ' . $church['church_address'] : ($church['church_location'] ? '**Location:** ' . $church['church_location'] : ''),
                    $church['church_contact_details'] ? '**Phone:** ' . $church['church_contact_details'] : '',
                    $church['church_email'] ? '**Email:** ' . $church['church_email'] : '',
                    $church['church_office_hours'] ? '**Office hours:** ' . str_replace("\n", '; ', $church['church_office_hours']) : '',
                    $church['church_website'] ? '**Website:** ' . $church['church_website'] : '',
                    $church['church_facebook'] ? '**Facebook:** ' . $church['church_facebook'] : '',
                    $church['church_whatsapp'] ? '**WhatsApp community:** the join link is on the Church Information page' : '',
                    $church['church_priest'] ? (str_contains($church['church_priest'], ',') ? '**Priests:** ' : '**Parish priest:** ') . $church['church_priest'] : '',
                ]);
                $add("Here is how to reach **$name**:\n" . implode("\n", $lines) . "\nThe Church Information page also has the map and directions.", [['Church Information', 'church-information.php']]);
                break;

            case 'directory':
                $all = $pdo->query('SELECT church_name, church_location FROM church_info ORDER BY church_name')->fetchAll();
                $mNorm = ' ' . assistant_norm($message) . ' ';
                $near = array_values(array_filter($all, fn($c) => $c['church_location'] && str_contains($mNorm, ' ' . assistant_norm($c['church_location']) . ' ')));
                if ($near) {
                    $add('In **' . $near[0]['church_location'] . '** the portal lists: ' . implode(', ', array_map(fn($c) => '**' . $c['church_name'] . '**', $near)) . '. Ask me about any of them by name (Mass times, priests, contact), or open the Directory for the map.', [['Open Directory', 'index.php#directory']]);
                } else {
                    $add('The portal lists **' . count($all) . ' churches** of the Archdiocese: ' . implode(', ', array_map(fn($c) => $c['church_name'] . ' (' . $c['church_location'] . ')', $all)) . '. Ask me about any of them by name, or open the Directory for each one\'s map and details.', [['Open Directory', 'index.php#directory']]);
                }
                break;

            case 'stats':
                $rows = [];
                try { $rows = $pdo->query('SELECT stat_label, stat_value FROM site_stats ORDER BY sort_order')->fetchAll(); } catch (PDOException $ex) { /* table not created yet */ }
                $add($rows
                    ? "The Archdiocese of Kota Kinabalu at a glance:\n" . implode("\n", array_map(fn($r) => '• **' . $r['stat_label'] . ':** ' . number_format((int)$r['stat_value']), $rows)) . "\nThese are the figures shown on the home page."
                    : 'The portal does not have the Archdiocese\'s figures yet.',
                    [['Home page', 'index.php'], ['Open Directory', 'index.php#directory']]);
                break;

            case 'about':
                $sc = site_content();
                $add('**' . $sc['about_title'] . '**: ' . trim($sc['about_body']) . "\n**Archdiocese office:** " . str_replace("\n", ', ', trim($sc['contact_address']))
                    . ($sc['contact_phone'] !== '' ? "\n**Phone:** " . $sc['contact_phone'] : '') . ($sc['contact_email'] !== '' ? "\n**Email:** " . $sc['contact_email'] : ''),
                    [['About Us', 'index.php#about']]);
                break;

            case 'legal':
                $add('The portal\'s rules and how your information is handled are written in plain language on three short pages: **Terms of Use**, **Privacy** and **Cookies**. Documents you upload can only be opened by you and your church\'s administrators.', [['Terms', 'terms.php'], ['Privacy', 'privacy.php'], ['Cookies', 'cookies.php']]);
                break;

            case 'profile':
                $add($isAdmin
                    ? 'Your own name, photo and password are under **My Profile**.'
                    : 'On your **Profile** page you can change your name, email, phone number, home address, date of birth and photo, and choose your **home parish**. Your home parish decides which church your applications and requests are sent to, so applications still in progress move with you when you change it.',
                    [[$isAdmin ? 'My Profile' : 'My profile', $isAdmin ? 'account.php' : 'profile.php']]);
                break;

            case 'account':
                if (preg_match('/\b(forgot|forgotten|reset|change).{0,12}password\b|(log|sign) ?in (problem|issue|trouble)|can\'?t (log|sign) ?in|cannot (log|sign) ?in|kata laluan/', $mFixed)) {
                    $add('You can reset your password from the **Forgot Password** link on the sign-in page. We will email you a link to choose a new one.', [['Forgot Password', 'forgot-password.php']]);
                } elseif (preg_match('/\b(delete|close|remove|deactivate) (my )?account\b/', $mFixed)) {
                    $add('An account cannot be deleted from the portal by yourself. Please ask the parish office to remove it for you.' . ($church ? $office : ''), [['Privacy', 'privacy.php']]);
                } elseif (preg_match('/\b(log|sign) ?(out|off)\b|\blogout\b/', $mFixed)) {
                    $add('Use **Sign Out** at the top of the page, or the link below. You will be asked to confirm before you are signed out.', [['Sign out', 'signout.php']]);
                } elseif (preg_match('/\b(create|sign ?up|new account|no account|don\'?t have|akaun)\b/', $mFixed)) {
                    $add('New parishioners can create a free account from the **Login / Register** page: just your name, email and a password to get started.', [['Login / Register', 'login.php']]);
                } else {
                    $add('Sign in from the **Login / Register** page with your email and password. If you have forgotten your password, use **Forgot Password** and we will email you a link.', [['Login / Register', 'login.php'], ['Forgot Password', 'forgot-password.php']]);
                }
                break;
        }
    }
    return $done();
}
