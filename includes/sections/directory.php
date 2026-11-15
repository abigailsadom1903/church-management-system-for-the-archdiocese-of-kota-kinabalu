<?php
// "Directory of Churches" section (one-page site). Needs $pdo.
require_once __DIR__ . '/../church_helpers.php';
$dirChurches = $pdo->query(
    'SELECT c.church_info_id, c.church_name, c.church_location, c.church_contact_details, c.church_office_hours, ' . PRIEST_NAMES_SQL . ' AS church_priest,
            c.church_lat, c.church_lng, c.church_photo
       FROM church_info c ORDER BY c.church_name'
)->fetchAll();

// Churches grouped by area (the areas are listed in includes/church_helpers.php).
$dirGroups = church_group_by_area($dirChurches);

/**
 * Stack the areas into $cols columns of about equal height. An area's height is its church count plus
 * a bit for its header. Every way of assigning areas to columns is tried (there are only a handful of
 * areas), keeping the one whose tallest column is shortest. Columns, and the areas inside each, keep
 * the areas' normal order. Returns a list of columns, each a list of area keys.
 */
function dir_balance(array $groups, int $cols = 3): array
{
    $keys = array_keys($groups);
    $w = array_map(fn($k) => count($groups[$k]['churches']) + 1.5, $keys);
    $n = count($keys);
    if ($n <= $cols) return array_map(fn($k) => [$k], $keys);

    $best = null; $bestScore = INF;
    if ($cols ** $n <= 200000) {
        for ($i = 0, $total = $cols ** $n; $i < $total; $i++) {
            $h = array_fill(0, $cols, 0.0); $pick = [];
            for ($j = 0, $x = $i; $j < $n; $j++, $x = intdiv($x, $cols)) { $pick[$j] = $x % $cols; $h[$pick[$j]] += $w[$j]; }
            if (min($h) == 0) continue;
            $score = max($h) * 100 + (max($h) - min($h));   // shortest tallest column, then the most even
            if ($score < $bestScore) { $bestScore = $score; $best = $pick; }
        }
    }
    if ($best === null) {   // too many areas to try every way: put each in the shortest column so far
        $h = array_fill(0, $cols, 0.0);
        foreach ($w as $j => $wt) { $c = array_keys($h, min($h))[0]; $best[$j] = $c; $h[$c] += $wt; }
    }
    $stacks = [];
    foreach ($keys as $j => $k) $stacks[$best[$j]][] = $k;
    usort($stacks, fn($a, $b) => array_search($a[0], $keys) <=> array_search($b[0], $keys));
    return $stacks;
}
$dirStacks = dir_balance($dirGroups);

function dir_e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
/** Phone numbers as tap-to-call links: "088-711009 / 088-715804" -> two links. */
function dir_phone_html(string $value): string
{
    $parts = array_map(function ($p) {
        $p = trim($p);
        $tel = preg_replace('/[^\d+]/', '', $p);
        return strlen($tel) >= 7 && !preg_match('/[a-z]/i', $p) ? '<a href="tel:' . dir_e($tel) . '">' . dir_e($p) . '</a>' : dir_e($p);
    }, explode('/', $value));
    return implode(' <span class="cd-sep">/</span> ', $parts);
}

/**
 * Office hours as a small timetable: a line like "Mon-Fri: 8:00 AM - 5:00 PM;" becomes a day and a time
 * side by side; any other line (a note) is shown as it is.
 */
function dir_hours_html(string $value): string
{
    $out = '';
    foreach (preg_split('/\R/u', $value) as $line) {
        $line = trim(preg_replace('/\s+/u', ' ', $line), ' ;');
        if ($line === '') continue;
        if (preg_match('/^([^:\d]{2,30}):\s*(.+)$/u', $line, $m)) {
            $closed = (bool)preg_match('/^closed\b/i', $m[2]);
            $out .= '<span class="cd-hours-row' . ($closed ? ' is-closed' : '') . '"><span class="cd-day">' . dir_e(trim($m[1])) . '</span><span class="cd-time">' . dir_e($m[2]) . '</span></span>';
        } else {
            $out .= '<span class="cd-hours-row is-note">' . dir_e($line) . '</span>';
        }
    }
    return $out;
}

const DIR_DETAIL_ICONS = [
    'priest' => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    'phone'  => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
    'clock'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
];

function dir_map_query(array $c): string
{
    return trim($c['church_name'] . ', ' . $c['church_location'] . ', Sabah, Malaysia');
}
?>
<div class="directory-page">
    <header class="page-heading dir-heading">
        <span class="dir-eyebrow">Archdiocese of Kota Kinabalu</span>
        <h2>Directory of Churches</h2>
        <p><?= count($dirChurches) ?> parishes across Sabah and Labuan, grouped by area. Select a church to view its map, contacts and office hours.</p>
    </header>

    <div class="dir-toolbar">
        <div class="dir-toolbar-row">
            <div class="dir-chips" role="group" aria-label="Filter by area">
                <button type="button" class="dir-chip is-active" data-area="all" aria-pressed="true">All areas <span class="dir-chip-n"><?= count($dirChurches) ?></span></button>
                <?php foreach ($dirGroups as $key => $g): ?>
                    <button type="button" class="dir-chip" data-area="<?= dir_e($key) ?>" aria-pressed="false"><?= dir_e($g['name']) ?> <span class="dir-chip-n"><?= count($g['churches']) ?></span></button>
                <?php endforeach; ?>
            </div>
            <button type="button" id="dir-near-btn" class="dir-near-btn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 12-9 12s-9-5-9-12a9 9 0 0 1 18 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                <span>Find Nearest Church</span>
            </button>
        </div>
        <p class="dir-near-status" id="dir-near-status" hidden></p>
    </div>

    <!-- The selected church's info card is shown here, above the board, so it always opens in the same place. -->
    <div class="dir-detail" id="dir-detail" hidden>
        <button type="button" class="dir-detail-close" id="dir-detail-close" aria-label="Close church details">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
    </div>

    <!-- Three columns of about equal height, each holding one or more areas stacked. -->
    <div class="dir-board">
        <?php foreach ($dirStacks as $stack): ?>
        <div class="dir-stack">
        <?php foreach ($stack as $key):
            $g = $dirGroups[$key];
            $n = count($g['churches']);
        ?>
        <section class="dir-group" data-area="<?= dir_e($key) ?>" aria-labelledby="dir-group-<?= dir_e($key) ?>">
            <header class="dir-group-head">
                <span class="dir-group-tag"><?= dir_e($g['tag']) ?></span>
                <h3 class="dir-group-name" id="dir-group-<?= dir_e($key) ?>"><?= dir_e($g['name']) ?></h3>
                <span class="dir-group-count" title="<?= $n ?> <?= $n === 1 ? 'parish' : 'parishes' ?>"><?= $n ?></span>
            </header>
            <div class="dir-group-body">
                <?php foreach ($g['churches'] as $c):
                    $id = 'church-' . (int)$c['church_info_id'];
                    $q  = dir_map_query($c);
                    $photo = church_photo_url($c['church_photo']);
                    $tel = '';   // first dialable number, for the Call button
                    foreach (explode('/', (string)$c['church_contact_details']) as $p) {
                        $digits = preg_replace('/[^\d+]/', '', $p);
                        if (strlen($digits) >= 7 && !preg_match('/[a-z]/i', $p)) { $tel = $digits; break; }
                    }
                    $details = array_filter([
                        ['priest', str_contains((string)$c['church_priest'], ',') ? 'Priests' : 'Parish Priest', $c['church_priest']],
                        ['phone', 'Contact', $c['church_contact_details']],
                        ['clock', 'Office Hours', $c['church_office_hours']],
                    ], fn($d) => trim((string)$d[2]) !== '');
                ?>
                    <article class="church-card" id="<?= $id ?>"<?= $c['church_lat'] !== null ? ' data-lat="' . dir_e((string)$c['church_lat']) . '" data-lng="' . dir_e((string)$c['church_lng']) . '"' : '' ?>>
                        <h4>
                            <button type="button" class="church-toggle" aria-expanded="false" aria-controls="<?= $id ?>-panel">
                                <span class="church-icon<?= $photo ? ' has-photo' : '' ?>">
                                    <?php if ($photo): ?>
                                        <img src="<?= dir_e($photo) ?>" alt="" loading="lazy">
                                    <?php else: ?>
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m18 7 4 2v11a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9l4-2"/><path d="M14 22v-4a2 2 0 0 0-2-2a2 2 0 0 0-2 2v4"/><path d="M18 22V5l-6-3-6 3v17"/><path d="M12 7v5"/><path d="M10 9h4"/></svg>
                                    <?php endif; ?>
                                </span>
                                <span class="church-label">
                                    <span class="church-name"><?= dir_e($c['church_name']) ?></span>
                                    <span class="church-town"><?= dir_e($c['church_location']) ?></span>
                                    <span class="church-dist" hidden></span>
                                </span>
                                <svg class="church-plus" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path class="v" d="M12 5v14"/><path d="M5 12h14"/></svg>
                            </button>
                        </h4>
                        <div class="church-panel" id="<?= $id ?>-panel" hidden data-map="https://www.google.com/maps?q=<?= dir_e(rawurlencode($q)) ?>&amp;z=15&amp;output=embed">
                            <figure class="cp-photo<?= $photo ? '' : ' is-empty' ?>">
                                <?php if ($photo): ?>
                                    <img src="<?= dir_e($photo) ?>" alt="<?= dir_e($c['church_name']) ?>" loading="lazy">
                                <?php else: ?>
                                    <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m18 7 4 2v11a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9l4-2"/><path d="M14 22v-4a2 2 0 0 0-2-2a2 2 0 0 0-2 2v4"/><path d="M18 22V5l-6-3-6 3v17"/><path d="M12 7v5"/><path d="M10 9h4"/></svg>
                                <?php endif; ?>
                                <figcaption>
                                    <span class="cp-area"><?= dir_e($g['name']) ?></span>
                                    <strong><?= dir_e($c['church_name']) ?></strong>
                                    <span class="cp-town">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 12-9 12s-9-5-9-12a9 9 0 0 1 18 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                                        <?= dir_e($c['church_location']) ?>, Sabah
                                    </span>
                                </figcaption>
                            </figure>

                            <div class="cp-info">
                                <div class="cp-actions">
                                    <a class="cp-btn is-primary" href="https://www.google.com/maps/dir/?api=1&amp;destination=<?= dir_e(rawurlencode($q)) ?>" target="_blank" rel="noopener">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 11 19-9-9 19-2-8-8-2z"/></svg>
                                        Get Directions
                                    </a>
                                    <?php if ($tel): ?>
                                        <a class="cp-btn" href="tel:<?= dir_e($tel) ?>">
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= DIR_DETAIL_ICONS['phone'] ?></svg>
                                            Call
                                        </a>
                                    <?php endif; ?>
                                    <?php if (!empty($_SESSION['user_id'])): ?>
                                        <a class="cp-btn" href="church-information.php?church=<?= (int)$c['church_info_id'] ?>">
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/></svg>
                                            Full Info
                                        </a>
                                    <?php endif; ?>
                                </div>
                                <?php if ($details): ?>
                                    <dl class="church-details">
                                        <?php foreach ($details as [$icon, $label, $value]): ?>
                                            <div class="cd-row">
                                                <span class="cd-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= DIR_DETAIL_ICONS[$icon] ?></svg></span>
                                                <div class="cd-text">
                                                    <dt><?= dir_e($label) ?></dt>
                                                    <dd<?= $icon === 'clock' ? ' class="cd-hours"' : '' ?>><?= $icon === 'clock' ? dir_hours_html($value) : ($icon === 'phone' ? dir_phone_html($value) : dir_e($value)) ?></dd>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </dl>
                                <?php else: ?>
                                    <p class="cp-none">Contact details and office hours for this parish have not been added yet.</p>
                                <?php endif; ?>
                            </div>

                            <div class="cp-map">
                                <iframe class="church-map" title="Map of <?= dir_e($c['church_name']) ?>, <?= dir_e($c['church_location']) ?>" allowfullscreen></iframe>
                                <a class="cp-map-open" href="https://www.google.com/maps/search/?api=1&amp;query=<?= dir_e(rawurlencode($q)) ?>" target="_blank" rel="noopener">
                                    Open in Google Maps
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17 17 7"/><path d="M8 7h9v9"/></svg>
                                </a>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>

</div>

<script>
(function () {
    var cards = document.querySelectorAll('.church-card');
    var groups = document.querySelectorAll('.dir-group');
    var board = document.querySelector('.dir-board');
    var detail = document.getElementById('dir-detail');

    // Each card keeps its own info panel; while a church is selected its panel is moved into the
    // detail area above the board, and moved back into the card when it closes.
    function setOpen(card, open) {
        var panel = card._panel || (card._panel = card.querySelector('.church-panel'));
        card.querySelector('.church-toggle').setAttribute('aria-expanded', open);
        card.classList.toggle('is-open', open);
        panel.hidden = !open;
        if (open) {
            detail.appendChild(panel);
            detail.hidden = false;
            var frame = panel.querySelector('iframe');
            if (!frame.getAttribute('src')) frame.setAttribute('src', panel.dataset.map); // load each map only when first opened
        } else if (panel.parentNode === detail) {
            card.appendChild(panel);
            detail.hidden = true;
        }
    }

    function closeAll() { cards.forEach(function (c) { setOpen(c, false); }); }

    // Bring the info card (above the board) into view.
    function showOpen(card, smooth) {
        detail.scrollIntoView({ behavior: smooth === false ? 'auto' : 'smooth', block: 'start' });
    }

    cards.forEach(function (card) {
        card.querySelector('.church-toggle').addEventListener('click', function () {
            var willOpen = !card.classList.contains('is-open');
            closeAll();   // one open at a time
            setOpen(card, willOpen);
            if (willOpen) showOpen(card);
        });
    });

    document.getElementById('dir-detail-close').addEventListener('click', closeAll);

    // Deep link: index.php#church-5 opens that church (and scrolls to it).
    if (/^#church-\d+$/.test(location.hash)) {
        var target = document.querySelector(location.hash);
        if (target && target.classList.contains('church-card')) {
            setOpen(target, true);
            setTimeout(function () { showOpen(target, false); }, 60);
        }
    }

    // Area chips: show one area, or all of them.
    var chips = document.querySelectorAll('.dir-chip');
    var area = 'all';

    function applyFilters() {
        groups.forEach(function (group) {
            group.hidden = area !== 'all' && group.dataset.area !== area;
            if (group.hidden && group.querySelector('.church-card.is-open')) closeAll();
        });
        // One area on its own spreads across the full width instead of sitting in one of the three columns.
        board.classList.toggle('is-single', area !== 'all');
        board.querySelectorAll('.dir-stack').forEach(function (stack) {
            stack.hidden = !stack.querySelector('.dir-group:not([hidden])');
        });
    }

    chips.forEach(function (chip) {
        chip.addEventListener('click', function () {
            area = chip.dataset.area;
            chips.forEach(function (c) {
                var on = c === chip;
                c.classList.toggle('is-active', on);
                c.setAttribute('aria-pressed', on);
            });
            applyFilters();
        });
    });

    // "Find Nearest Church": use the browser's own location, work out the distance to each church that
    // has coordinates, then sort the churches inside each area nearest-first.
    var nearBtn = document.getElementById('dir-near-btn');
    var status = document.getElementById('dir-near-status');

    function say(msg, ok) {
        status.hidden = false;
        status.textContent = msg;
        status.className = 'dir-near-status' + (ok === true ? ' is-ok' : ok === false ? ' is-error' : '');
    }

    function km(lat1, lon1, lat2, lon2) {
        var R = 6371, toRad = function (d) { return d * Math.PI / 180; };
        var dLat = toRad(lat2 - lat1), dLon = toRad(lon2 - lon1);
        var a = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLon / 2) ** 2;
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    nearBtn.addEventListener('click', function () {
        if (!('geolocation' in navigator)) { say('Your browser does not support location services.', false); return; }
        nearBtn.disabled = true;
        say('Finding your location…');

        navigator.geolocation.getCurrentPosition(function (pos) {
            var here = pos.coords, nearest = null;
            cards.forEach(function (card) {
                var lat = parseFloat(card.dataset.lat), lng = parseFloat(card.dataset.lng);
                card._d = Infinity;
                if (isNaN(lat) || isNaN(lng)) return;
                var d = km(here.latitude, here.longitude, lat, lng), distEl = card.querySelector('.church-dist');
                card._d = d;
                distEl.hidden = false;
                distEl.textContent = (d < 1 ? Math.round(d * 1000) + ' m' : d.toFixed(1) + ' km') + ' away';
                if (!nearest || d < nearest._d) nearest = card;
            });
            nearBtn.disabled = false;
            if (!nearest) { say('Distances are not available for these churches yet.', false); return; }

            // Sort churches nearest-first inside each area (areas stay in their columns to keep them balanced).
            var byDist = function (a, b) { return a._d - b._d; };
            groups.forEach(function (group) {
                var body = group.querySelector('.dir-group-body');
                Array.prototype.slice.call(body.querySelectorAll('.church-card')).sort(byDist)
                    .forEach(function (c) { body.appendChild(c); });
            });

            closeAll();
            setOpen(nearest, true);
            showOpen(nearest);
            say('Showing distance from your location — nearest is ' + nearest.querySelector('.church-name').textContent + '.', true);
        }, function (err) {
            nearBtn.disabled = false;
            if (err.code === err.PERMISSION_DENIED) say('Location access was denied. You can still browse or search above.', false);
            else if (err.code === err.POSITION_UNAVAILABLE) say('Your location is currently unavailable. Please try again.', false);
            else if (err.code === err.TIMEOUT) say('Finding your location took too long. Please try again.', false);
            else say('Could not get your location.', false);
        }, { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 });
    });
})();
</script>
