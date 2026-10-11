<?php
// "Directory of Churches" section (one-page site). Needs $pdo.
require_once __DIR__ . '/../church_helpers.php';
$dirChurches = $pdo->query(
    'SELECT c.church_info_id, c.church_name, c.church_location, c.church_contact_details, c.church_office_hours, ' . PRIEST_NAMES_SQL . ' AS church_priest,
            c.church_lat, c.church_lng, c.church_photo
       FROM church_info c ORDER BY c.church_name'
)->fetchAll();

// Two columns: each column grows on its own when a card opens.
$dirColumns = array_chunk($dirChurches, max(1, (int)ceil(count($dirChurches) / 2)));

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
    <header class="page-heading">
        <h2>Directory of Churches</h2>
        <p>Select a church to see where it is on the map.</p>
    </header>

    <div class="dir-search">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
        <input type="search" id="dir-filter" placeholder="Search by church or town" aria-label="Search churches" autocomplete="off">
    </div>

    <div class="dir-locate">
        <button type="button" id="dir-near-btn" class="dir-near-btn">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 12-9 12s-9-5-9-12a9 9 0 0 1 18 0Z"/><circle cx="12" cy="10" r="3"/></svg>
            <span>Find Nearest Church</span>
        </button>
        <p class="dir-near-status" id="dir-near-status" hidden></p>
    </div>

    <div class="dir-columns">
        <?php foreach ($dirColumns as $col): ?>
            <div class="dir-col">
                <?php foreach ($col as $c):
                    $id = 'church-' . (int)$c['church_info_id'];
                    $q  = dir_map_query($c);
                    $photo = church_photo_url($c['church_photo']);
                    $details = array_filter([
                        ['priest', str_contains((string)$c['church_priest'], ',') ? 'Priests' : 'Parish Priest', $c['church_priest']],
                        ['phone', 'Contact', $c['church_contact_details']],
                        ['clock', 'Office Hours', $c['church_office_hours']],
                    ], fn($d) => trim((string)$d[2]) !== '');
                ?>
                    <article class="church-card" id="<?= $id ?>" data-search="<?= dir_e(mb_strtolower($c['church_name'] . ' ' . $c['church_location'])) ?>"<?= $c['church_lat'] !== null ? ' data-lat="' . dir_e((string)$c['church_lat']) . '" data-lng="' . dir_e((string)$c['church_lng']) . '"' : '' ?>>
                        <h3>
                            <button type="button" class="church-toggle" aria-expanded="false" aria-controls="<?= $id ?>-panel">
                                <span class="church-icon<?= $photo ? ' has-photo' : '' ?>">
                                    <?php if ($photo): ?>
                                        <img src="<?= dir_e($photo) ?>" alt="" loading="lazy">
                                    <?php else: ?>
                                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m18 7 4 2v11a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9l4-2"/><path d="M14 22v-4a2 2 0 0 0-2-2a2 2 0 0 0-2 2v4"/><path d="M18 22V5l-6-3-6 3v17"/><path d="M12 7v5"/><path d="M10 9h4"/></svg>
                                    <?php endif; ?>
                                </span>
                                <span class="church-label">
                                    <span class="church-name"><?= dir_e($c['church_name']) ?></span>
                                    <span class="church-town"><?= dir_e($c['church_location']) ?></span>
                                    <span class="church-dist" hidden></span>
                                </span>
                                <svg class="church-plus" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path class="v" d="M12 5v14"/><path d="M5 12h14"/></svg>
                            </button>
                        </h3>
                        <div class="church-panel" id="<?= $id ?>-panel" hidden data-map="https://www.google.com/maps?q=<?= dir_e(rawurlencode($q)) ?>&amp;z=15&amp;output=embed">
                            <iframe class="church-map" title="Map of <?= dir_e($c['church_name']) ?>, <?= dir_e($c['church_location']) ?>" allowfullscreen></iframe>
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
                            <?php endif; ?>
                            <?php if (!empty($_SESSION['user_id'])): ?>
                                <a class="info-link" href="church-information.php?church=<?= (int)$c['church_info_id'] ?>">View full church information</a>
                            <?php endif; ?>
                            <a class="map-link" href="https://www.google.com/maps/search/?api=1&amp;query=<?= dir_e(rawurlencode($q)) ?>" target="_blank" rel="noopener">Open in Google Maps</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <p class="dir-empty" id="dir-empty" hidden>No churches match your search.</p>
</div>

<script>
(function () {
    var cards = document.querySelectorAll('.church-card');

    function setOpen(card, open) {
        var btn = card.querySelector('.church-toggle');
        var panel = card.querySelector('.church-panel');
        btn.setAttribute('aria-expanded', open);
        card.classList.toggle('is-open', open);
        panel.hidden = !open;
        if (open) {
            var frame = panel.querySelector('iframe');
            if (!frame.getAttribute('src')) frame.setAttribute('src', panel.dataset.map); // load each map only when first opened
        }
    }

    cards.forEach(function (card) {
        card.querySelector('.church-toggle').addEventListener('click', function () {
            var willOpen = !card.classList.contains('is-open');
            cards.forEach(function (c) { setOpen(c, false); });   // one open at a time
            setOpen(card, willOpen);
        });
    });

    // Deep link: index.php#church-5 opens that church (and scrolls to it).
    if (/^#church-\d+$/.test(location.hash)) {
        var target = document.querySelector(location.hash);
        if (target && target.classList.contains('church-card')) {
            setOpen(target, true);
            setTimeout(function () { target.scrollIntoView({ block: 'center' }); }, 60);
        }
    }

    var filter = document.getElementById('dir-filter');
    var empty = document.getElementById('dir-empty');
    filter.addEventListener('input', function () {
        var q = filter.value.trim().toLowerCase(), shown = 0;
        cards.forEach(function (card) {
            var match = card.dataset.search.indexOf(q) !== -1;
            card.hidden = !match;
            if (match) shown++;
        });
        empty.hidden = shown !== 0;
    });

    // "Find Nearest Church": use the browser's own location, work out the distance to each church that
    // has coordinates, then sort the cards nearest-first (interleaved back into the two columns).
    var nearBtn = document.getElementById('dir-near-btn');
    var status = document.getElementById('dir-near-status');
    var columns = document.querySelectorAll('.dir-col');

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
            var here = pos.coords, withDist = [];
            cards.forEach(function (card) {
                var lat = parseFloat(card.dataset.lat), lng = parseFloat(card.dataset.lng);
                var distEl = card.querySelector('.church-dist');
                if (isNaN(lat) || isNaN(lng)) return;
                var d = km(here.latitude, here.longitude, lat, lng);
                if (distEl) { distEl.hidden = false; distEl.textContent = (d < 1 ? Math.round(d * 1000) + ' m' : d.toFixed(1) + ' km') + ' away'; }
                withDist.push({ card: card, d: d });
            });
            nearBtn.disabled = false;
            if (!withDist.length) { say('Distances are not available for these churches yet.', false); return; }

            withDist.sort(function (a, b) { return a.d - b.d; });
            withDist.forEach(function (item, i) { columns[i % columns.length].appendChild(item.card); });

            cards.forEach(function (c) { setOpen(c, false); });
            setOpen(withDist[0].card, true);
            withDist[0].card.scrollIntoView({ behavior: 'smooth', block: 'center' });
            say('Showing distance from your location — nearest is ' + withDist[0].card.querySelector('.church-name').textContent + '.', true);
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
