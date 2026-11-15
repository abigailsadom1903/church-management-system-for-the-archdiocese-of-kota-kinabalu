<?php
// Mass finder: "Masses near you in the next 3 hours, in your language", with distance and directions.
// Open to everyone (Mass times are public). The page lists every Mass of the next 24 hours from the Church
// Calendar (category "mass"); the browser then filters (time window, language), measures the distance from
// where the person is (only if they allow it; the location never leaves their phone) and sorts.
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/church_helpers.php';
require __DIR__ . '/includes/event_helpers.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** The language of a Mass from its title, "Sunday Mass (Mandarin)" -> "Chinese"; '' when the title does not say. */
function mf_language(string $title): string
{
    if (!preg_match('/\(([^)]+)\)\s*$/', $title, $m)) return '';
    $l = mb_strtolower($m[1]);
    return match (true) {
        str_contains($l, 'mandarin'), str_contains($l, 'chinese'), str_contains($l, 'hakka'), str_contains($l, 'cantonese') => 'Chinese',
        str_contains($l, 'kadazan'), str_contains($l, 'dusun') => 'Kadazan-Dusun',
        str_contains($l, 'bahasa'), str_contains($l, 'malay'), $l === 'bm' => 'Bahasa Malaysia',
        str_contains($l, 'english') => 'English',
        str_contains($l, 'tamil') => 'Tamil',
        default => trim($m[1]),
    };
}

$now = time();
$today = date('Y-m-d', $now);
$tomorrow = date('Y-m-d', $now + 86400);
$st = $pdo->query(
    "SELECT e.*, c.church_info_id, c.church_name, c.church_location, c.church_lat, c.church_lng, c.church_location_verified
       FROM events e JOIN church_info c ON c.church_info_id = e.church_id
      WHERE e.category = 'mass' AND e.event_date <= '$tomorrow'
        AND ((e.repeat_weekly = 0 AND e.event_date >= '$today') OR (e.repeat_weekly = 1 AND (e.repeat_until IS NULL OR e.repeat_until >= '$today')))"
);
$masses = [];
$langs = [];
foreach ($st->fetchAll() as $ev) {
    foreach (event_occurrences($ev, $today, $tomorrow) as $d) {
        if ($ev['is_all_day'] || !$ev['start_time']) continue;
        [$start, $end] = event_window($ev, $d);
        if ($end <= $now || $start > $now + 86400) continue;      // over, or more than a day away
        $lang = mf_language($ev['title']);
        if ($lang !== '') $langs[$lang] = ($langs[$lang] ?? 0) + 1;
        $masses[] = ['ev' => $ev, 'start' => $start, 'end' => $end, 'lang' => $lang];
    }
}
usort($masses, fn($a, $b) => $a['start'] <=> $b['start'] ?: strcmp($a['ev']['church_name'], $b['ev']['church_name']));
arsort($langs);

$pageTitle = 'Masses near you';
$activeNav = 'services';
include __DIR__ . '/includes/header.php';
?>
<main class="mf-page" data-now="<?= $now ?>" data-eod="<?= strtotime('tomorrow', $now) - 1 ?>">
    <section class="mf-hero">
        <div class="mf-wrap">
            <?php back_button('index.php', 'Home'); ?>
            <span class="mf-eyebrow"><?= ev_icon('pin', 13) ?> Mass finder</span>
            <h1>Masses near you</h1>
            <p class="mf-lead">Find a Mass starting soon, in your language, at the churches closest to you.</p>

            <div class="mf-loc" id="mf-loc">
                <button type="button" class="mf-loc-btn" id="mf-loc-btn"><?= ev_icon('pin', 16) ?> <span>Use my location</span></button>
                <span class="mf-loc-msg" id="mf-loc-msg" role="status">Allow your location to see the nearest churches first. It stays on your phone.</span>
            </div>

            <div class="mf-filters">
                <div class="mf-chips" role="group" aria-label="When">
                    <span class="mf-chips-l">When</span>
                    <button type="button" class="mf-chip is-on" data-win="3">Next 3 hours</button>
                    <button type="button" class="mf-chip" data-win="today">Rest of today</button>
                    <button type="button" class="mf-chip" data-win="24">Next 24 hours</button>
                </div>
                <div class="mf-chips" role="group" aria-label="Language">
                    <span class="mf-chips-l">Language</span>
                    <button type="button" class="mf-chip is-on" data-lang="">Any</button>
                    <?php foreach ($langs as $l => $n): ?><button type="button" class="mf-chip" data-lang="<?= e($l) ?>"><?= e($l) ?></button><?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>

    <div class="mf-wrap mf-body">
        <p class="mf-count" id="mf-count" aria-live="polite"></p>
        <ol class="mf-list" id="mf-list">
            <?php foreach ($masses as $m): $ev = $m['ev']; $c = $ev; $hasPin = $c['church_lat'] !== null && $c['church_lng'] !== null;
                $dest = $hasPin && $c['church_location_verified'] ? $c['church_lat'] . ',' . $c['church_lng'] : $c['church_name'] . ', ' . $c['church_location'] . ', Sabah'; ?>
                <li class="mf-item" data-start="<?= $m['start'] ?>" data-end="<?= $m['end'] ?>" data-lang="<?= e($m['lang']) ?>"
                    <?= $hasPin ? 'data-lat="' . e($c['church_lat']) . '" data-lng="' . e($c['church_lng']) . '"' : '' ?> data-dest="<?= e($dest) ?>">
                    <div class="mf-when">
                        <b><?= e(date('g:i', $m['start'])) ?><small><?= e(date('A', $m['start'])) ?></small></b>
                        <span class="mf-day"><?= e(date('Y-m-d', $m['start']) === $today ? 'Today' : 'Tomorrow') ?></span>
                        <span class="mf-soon" data-soon></span>
                    </div>
                    <div class="mf-main">
                        <h2><?= e(preg_replace('/\s*\([^)]*\)\s*$/', '', $ev['title'])) ?>
                            <?php if ($m['lang'] !== ''): ?><span class="mf-lang"><?= e($m['lang']) ?></span><?php else: ?><span class="mf-lang is-none">Language not stated</span><?php endif; ?></h2>
                        <p class="mf-church"><a href="church-information.php?church=<?= (int)$c['church_info_id'] ?>"><?= e($c['church_name']) ?></a> &middot; <?= e($c['church_location']) ?></p>
                        <?php if ($ev['location'] && $ev['location'] !== $c['church_name']): ?><p class="mf-venue"><?= ev_icon('pin', 12) ?> <?= e($ev['location']) ?></p><?php endif; ?>
                    </div>
                    <div class="mf-go">
                        <span class="mf-dist" data-dist hidden></span>
                        <a class="mf-dir" data-dir target="_blank" rel="noopener" href="https://www.google.com/maps/dir/?api=1&amp;travelmode=driving&amp;destination=<?= e(rawurlencode($dest)) ?>"><?= ev_icon('arrow', 14) ?> Directions</a>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
        <div class="mf-empty" id="mf-empty" hidden>
            <span class="mf-empty-ico"><?= ev_icon('calendar', 22) ?></span>
            <b id="mf-empty-t">No Masses in the next 3 hours.</b>
            <p id="mf-empty-next"></p>
            <a class="me-btn is-solid" href="events.php?type=mass">See all Mass times <?= ev_icon('right', 14) ?></a>
        </div>
        <p class="mf-foot">Times come from each parish&rsquo;s Church Calendar. Distances are in a straight line; the drive may be longer.</p>
    </div>
</main>
<script>
(function () {
    var page = document.querySelector('.mf-page');
    var items = Array.prototype.slice.call(document.querySelectorAll('.mf-item'));
    var list = document.getElementById('mf-list'), countEl = document.getElementById('mf-count');
    var empty = document.getElementById('mf-empty'), emptyT = document.getElementById('mf-empty-t'), emptyNext = document.getElementById('mf-empty-next');
    var locBtn = document.getElementById('mf-loc-btn'), locMsg = document.getElementById('mf-loc-msg');
    var skew = +page.dataset.now - Date.now() / 1000;          // use the server's clock (Sabah time), not the phone's
    var win = '3', lang = '', here = null;
    try { lang = localStorage.getItem('mf-lang') || ''; } catch (e) {}
    if (lang && !document.querySelector('.mf-chip[data-lang="' + (window.CSS && CSS.escape ? CSS.escape(lang) : lang) + '"]')) lang = '';   // no Mass in that language today

    function nowS() { return Date.now() / 1000 + skew; }
    function endOfToday() { return +page.dataset.eod; }   // midnight in Sabah, from the server
    function km(a, b, c, d) {   // straight-line distance in km
        var r = Math.PI / 180, x = Math.sin((c - a) * r / 2), y = Math.sin((d - b) * r / 2);
        return 12742 * Math.asin(Math.sqrt(x * x + Math.cos(a * r) * Math.cos(c * r) * y * y));
    }
    function soonText(start, end, now) {
        if (now >= start) return now < end ? 'Happening now' : '';
        var m = Math.round((start - now) / 60);
        return m < 60 ? 'in ' + m + ' min' : 'in ' + Math.floor(m / 60) + ' h' + (m % 60 ? ' ' + (m % 60) + ' min' : '');
    }

    function render() {
        var now = nowS(), until = win === 'today' ? endOfToday() : now + (+win) * 3600, shown = [];
        items.forEach(function (li) {
            var s = +li.dataset.start, e = +li.dataset.end;
            var ok = e > now && s <= until && (!lang || li.dataset.lang === lang);
            li.hidden = !ok;
            var soon = li.querySelector('[data-soon]');
            soon.textContent = soonText(s, e, now);
            li.classList.toggle('is-now', now >= s && now < e);
            if (ok) shown.push(li);
        });
        // Nearest first once we know where the person is (a Mass in the next hour still beats a far one); soonest first otherwise.
        shown.sort(function (a, b) {
            if (here && a.dataset.km && b.dataset.km) return (+a.dataset.km) - (+b.dataset.km) || (+a.dataset.start) - (+b.dataset.start);
            return (+a.dataset.start) - (+b.dataset.start);
        });
        shown.forEach(function (li) { list.appendChild(li); });
        var label = win === '3' ? 'in the next 3 hours' : win === 'today' ? 'for the rest of today' : 'in the next 24 hours';
        countEl.textContent = shown.length ? shown.length + (shown.length === 1 ? ' Mass ' : ' Masses ') + label + (lang ? ' in ' + lang : '') + (here ? ', nearest first' : ', soonest first') + '.' : '';
        empty.hidden = !!shown.length;
        if (!shown.length) {
            emptyT.textContent = 'No Masses ' + label + (lang ? ' in ' + lang : '') + '.';
            var next = items.filter(function (li) { return +li.dataset.end > now && (!lang || li.dataset.lang === lang); })
                            .sort(function (a, b) { return a.dataset.start - b.dataset.start; })[0];
            emptyNext.innerHTML = '';
            if (next) {
                var t = next.querySelector('.mf-when b').textContent.replace(/(AM|PM)$/, ' $1'), day = next.querySelector('.mf-day').textContent;
                emptyNext.textContent = 'The next one is ' + day.toLowerCase() + ' at ' + t + ', ' + next.querySelector('.mf-church').textContent.trim() + '. ';
                var b = document.createElement('button'); b.type = 'button'; b.className = 'mf-link'; b.textContent = 'Show the next 24 hours';
                b.addEventListener('click', function () { setWin('24'); }); emptyNext.appendChild(b);
            }
        }
    }

    function setWin(w) {
        win = w;
        document.querySelectorAll('[data-win]').forEach(function (b) { b.classList.toggle('is-on', b.dataset.win === w); });
        render();
    }
    document.querySelectorAll('[data-win]').forEach(function (b) { b.addEventListener('click', function () { setWin(b.dataset.win); }); });
    document.querySelectorAll('.mf-chip[data-lang]').forEach(function (b) {
        if (b.dataset.lang === lang) document.querySelectorAll('.mf-chip[data-lang]').forEach(function (x) { x.classList.toggle('is-on', x === b); });
        b.addEventListener('click', function () {
            lang = b.dataset.lang;
            try { localStorage.setItem('mf-lang', lang); } catch (e) {}
            document.querySelectorAll('.mf-chip[data-lang]').forEach(function (x) { x.classList.toggle('is-on', x === b); });
            render();
        });
    });

    function located(pos) {
        here = [pos.coords.latitude, pos.coords.longitude];
        items.forEach(function (li) {
            var dir = li.querySelector('[data-dir]');
            dir.href = 'https://www.google.com/maps/dir/?api=1&travelmode=driving&origin=' + here.join(',') + '&destination=' + encodeURIComponent(li.dataset.dest);
            if (!li.dataset.lat) return;
            var d = km(here[0], here[1], +li.dataset.lat, +li.dataset.lng);
            li.dataset.km = d.toFixed(2);
            var el = li.querySelector('[data-dist]');
            el.textContent = d < 1 ? Math.round(d * 1000) + ' m' : (d < 10 ? d.toFixed(1) : Math.round(d)) + ' km';
            el.hidden = false;
        });
        locBtn.classList.add('is-on');
        locBtn.querySelector('span').textContent = 'Location on';
        locMsg.textContent = 'Showing the nearest churches first.';
        render();
    }
    function locate(quiet) {
        if (!('geolocation' in navigator) || !window.isSecureContext) {
            if (!quiet) locMsg.textContent = 'Your location can only be used on a secure (https) address. Masses are shown soonest first.';
            return;
        }
        locBtn.disabled = true; locBtn.querySelector('span').textContent = 'Finding you…';
        navigator.geolocation.getCurrentPosition(function (p) { locBtn.disabled = false; located(p); }, function (err) {
            locBtn.disabled = false; locBtn.querySelector('span').textContent = 'Use my location';
            if (!quiet) locMsg.textContent = err.code === 1 ? 'Location is blocked. Allow it for this site in your browser settings, or browse by time.' : 'Your location could not be found. Masses are shown soonest first.';
        }, { enableHighAccuracy: false, timeout: 12000, maximumAge: 300000 });
    }
    locBtn.addEventListener('click', function () { locate(false); });
    // Already allowed before? Use it straight away (no permission question is shown).
    if (navigator.permissions && navigator.permissions.query) {
        navigator.permissions.query({ name: 'geolocation' }).then(function (p) { if (p.state === 'granted') locate(true); }).catch(function () {});
    }

    render();
    setInterval(render, 30000);   // keep "in 12 min" / "Happening now" up to date
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
