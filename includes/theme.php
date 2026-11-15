<?php
// Light / dark mode.
//
// The site has one stylesheet (assets/css/style.css) written with fixed light colours. Rather than keep a second,
// hand-written dark stylesheet in step with it, assets/css/dark.css is generated from style.css: every colour in a
// rule is translated to its dark-mode partner and the rule is repeated under html[data-theme="dark"]. The file is
// rebuilt automatically whenever style.css is newer, so editing style.css is all that is ever needed.
//
// Colour translation keeps the hue and changes the lightness by what the colour is used for:
//   text, icons      dark -> light (white text stays white)
//   backgrounds      white / pale -> deep purple-grey surfaces; strong colours (brand purple, gold, red buttons) stay
//   borders          pale -> dark lines
//
// theme_head($base)   prints, inside <head>: the dark stylesheet and a tiny script that applies the saved choice
//                     (or the device's preference) before the page is drawn, so there is no white flash.
// theme_toggle()      prints the sun / moon button for the header.

const THEME_SOURCE = __DIR__ . '/../assets/css/style.css';
const THEME_OUTPUT = __DIR__ . '/../assets/css/dark.css';

/** Make sure dark.css exists and is newer than style.css; returns its modification time (for cache busting). */
function theme_dark_css_version(): int
{
    $src = @filemtime(THEME_SOURCE) ?: 0;
    $out = @filemtime(THEME_OUTPUT) ?: 0;
    if ($out < $src) {
        $css = @file_get_contents(THEME_SOURCE);
        if ($css !== false) {
            @file_put_contents(THEME_OUTPUT, theme_build_dark_css($css), LOCK_EX);
            clearstatcache(true, THEME_OUTPUT);
            $out = @filemtime(THEME_OUTPUT) ?: $src;
        }
    }
    return $out;
}

function theme_head(string $base = ''): void
{
    $v = theme_dark_css_version();
    ?>
    <script>
    // Apply the saved light / dark choice before the page is drawn (no flash). Default: follow the device.
    (function () {
        var t = null;
        try { t = localStorage.getItem('theme'); } catch (e) {}
        if (t !== 'light' && t !== 'dark') t = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        document.documentElement.setAttribute('data-theme', t);
    })();
    </script>
    <link rel="stylesheet" href="<?= htmlspecialchars($base) ?>assets/css/dark.css?v=<?= $v ?>">
    <?php
}

function theme_toggle(): void
{
    ?>
    <button type="button" class="theme-toggle" id="theme-toggle" aria-label="Switch to dark mode" title="Switch to dark mode">
        <svg class="tt-moon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
        <svg class="tt-sun" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
    </button>
    <script>
    (function () {
        var btn = document.getElementById('theme-toggle'), root = document.documentElement;
        function label() {
            var dark = root.getAttribute('data-theme') === 'dark';
            var text = dark ? 'Switch to light mode' : 'Switch to dark mode';
            btn.setAttribute('aria-label', text);
            btn.setAttribute('title', text);
            btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
        }
        btn.addEventListener('click', function () {
            var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            root.setAttribute('data-theme', next);
            try { localStorage.setItem('theme', next); } catch (e) {}
            label();
        });
        label();
    })();
    </script>
    <?php
}

/* ------------------------------------------------------------------------------------------------
   Generator: style.css -> dark.css
   ------------------------------------------------------------------------------------------------ */

function theme_build_dark_css(string $css): string
{
    $css = preg_replace('~/\*.*?\*/~s', '', $css);   // drop comments
    $out = "/* GENERATED from style.css by includes/theme.php - do not edit; it is rebuilt when style.css changes. */\n"
         . theme_base_rules() . "\n"
         . theme_walk($css);
    return $out . "\n" . theme_extra_rules();
}

/** Walk a block of CSS: plain rules are translated, @media / @supports are entered, other at-rules skipped. */
function theme_walk(string $css): string
{
    $out = '';
    $len = strlen($css);
    $i = 0;
    while ($i < $len) {
        $open = theme_find($css, '{', $i);
        if ($open === -1) break;
        $prelude = trim(substr($css, $i, $open - $i));
        $close = theme_match_brace($css, $open);
        if ($close === -1) break;
        $body = substr($css, $open + 1, $close - $open - 1);
        $i = $close + 1;

        // an at-rule without a block (e.g. @import ...;) may sit in front of the prelude
        if (str_contains($prelude, ';')) $prelude = trim(substr($prelude, strrpos($prelude, ';') + 1));
        if ($prelude === '') continue;

        if ($prelude[0] === '@') {
            if (preg_match('/^@(media|supports)\b/i', $prelude)) {
                $inner = theme_walk($body);
                if (trim($inner) !== '') $out .= $prelude . " {\n" . $inner . "}\n";
            }
            continue;   // @keyframes, @font-face, ... : nothing to recolour
        }

        $decls = theme_translate_declarations($body);
        if ($decls === '') continue;
        $out .= theme_scope_selectors($prelude) . ' { ' . $decls . " }\n";
    }
    return $out;
}

/** Position of $ch at or after $from, skipping quoted strings. */
function theme_find(string $s, string $ch, int $from): int
{
    $len = strlen($s);
    for ($i = $from; $i < $len; $i++) {
        $c = $s[$i];
        if ($c === '"' || $c === "'") { $j = strpos($s, $c, $i + 1); if ($j === false) return -1; $i = $j; continue; }
        if ($c === $ch) return $i;
    }
    return -1;
}

function theme_match_brace(string $s, int $open): int
{
    $depth = 0;
    $len = strlen($s);
    for ($i = $open; $i < $len; $i++) {
        $c = $s[$i];
        if ($c === '"' || $c === "'") { $j = strpos($s, $c, $i + 1); if ($j === false) return -1; $i = $j; continue; }
        if ($c === '{') $depth++;
        elseif ($c === '}') { $depth--; if ($depth === 0) return $i; }
    }
    return -1;
}

/** "a, b:hover" -> 'html[data-theme="dark"] a, html[data-theme="dark"] b:hover' (html / :root are replaced, not prefixed). */
function theme_scope_selectors(string $prelude): string
{
    $root = 'html[data-theme="dark"]';
    $parts = [];
    foreach (theme_split($prelude, ',') as $sel) {
        $sel = trim($sel);
        if ($sel === '') continue;
        if (preg_match('/^(:root|html)\b/i', $sel)) $parts[] = preg_replace('/^(:root|html)/i', $root, $sel);
        else $parts[] = $root . ' ' . $sel;
    }
    return implode(', ', $parts);
}

/** Split on $sep outside brackets, parentheses and quotes. */
function theme_split(string $s, string $sep): array
{
    $parts = [];
    $depth = 0;
    $buf = '';
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $c = $s[$i];
        if ($c === '"' || $c === "'") {
            $j = strpos($s, $c, $i + 1);
            if ($j === false) $j = $len - 1;
            $buf .= substr($s, $i, $j - $i + 1);
            $i = $j;
            continue;
        }
        if ($c === '(' || $c === '[') $depth++;
        elseif ($c === ')' || $c === ']') $depth--;
        if ($c === $sep && $depth === 0) { $parts[] = $buf; $buf = ''; continue; }
        $buf .= $c;
    }
    if (trim($buf) !== '') $parts[] = $buf;
    return $parts;
}

/**
 * Keep the colour-carrying declarations of a rule (text colour, backgrounds, borders), translated.
 * Every one of them is repeated, even when its colour does not change: the dark rules all carry the same
 * html[data-theme="dark"] prefix, so they out-rank every light rule, and only by repeating the whole set do they
 * keep the same order among themselves as in style.css (a later hover background still beats an earlier one).
 */
function theme_translate_declarations(string $body): string
{
    $kept = [];
    foreach (theme_split($body, ';') as $decl) {
        $pos = strpos($decl, ':');
        if ($pos === false) continue;
        $prop = strtolower(trim(substr($decl, 0, $pos)));
        $value = trim(substr($decl, $pos + 1));
        $role = match (true) {
            in_array($prop, ['color', 'fill', 'stroke', 'caret-color', 'text-decoration-color', 'outline-color', 'scrollbar-color', '-webkit-text-fill-color'], true) => 'text',
            in_array($prop, ['background', 'background-color', 'background-image'], true) => 'bg',
            preg_match('/^border(-(top|right|bottom|left|block|inline)(-(start|end))?)?(-(color|style|width))?$/', $prop) === 1,
            $prop === 'outline', $prop === 'column-rule' => 'line',
            default => null,
        };
        if ($role === null) continue;
        if ($prop === 'scrollbar-color') $role = 'line';
        $new = theme_map_value($value, $role);
        $kept[] = $prop . ': ' . $new;
    }
    return $kept ? implode('; ', $kept) . ';' : '';
}

/** Translate every colour inside a declaration value. */
function theme_map_value(string $value, string $role): string
{
    if (stripos($value, 'url(') !== false && $role === 'bg') {
        // keep images; only recolour gradient colours that sit outside url(...)
        $chunks = preg_split('/(url\((?:[^()"\']|"[^"]*"|\'[^\']*\')*\))/i', $value, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($chunks as $k => $chunk) if (stripos($chunk, 'url(') !== 0) $chunks[$k] = theme_map_colours($chunk, $role);
        return implode('', $chunks);
    }
    return theme_map_colours($value, $role);
}

function theme_map_colours(string $s, string $role): string
{
    // The brand purple variables stay as they are for fills (purple buttons), but as text or icon colour
    // they would be dark purple on a dark page, so they become a light purple.
    if ($role === 'text') $s = preg_replace('/var\(\s*--purple-(btn|dark|link)\s*\)/i', '#cdb0f0', $s);
    $s = preg_replace_callback('/#([0-9a-f]{8}|[0-9a-f]{6}|[0-9a-f]{3,4})\b/i', function ($m) use ($role) {
        $h = $m[1];
        if (strlen($h) <= 4) $h = implode('', array_map(fn($c) => $c . $c, str_split($h)));
        $a = strlen($h) === 8 ? hexdec(substr($h, 6, 2)) / 255 : 1.0;
        return theme_colour_out(theme_map_rgb(hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2)), $role), $a);
    }, $s);
    $s = preg_replace_callback('/rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)(?:\s*[,\/]\s*([\d.]+%?))?\s*\)/i', function ($m) use ($role) {
        $a = isset($m[4]) && $m[4] !== '' ? (str_ends_with($m[4], '%') ? (float)$m[4] / 100 : (float)$m[4]) : 1.0;
        return theme_colour_out(theme_map_rgb((float)$m[1], (float)$m[2], (float)$m[3], $role, $a), $a);
    }, $s);
    $s = preg_replace_callback('/(?<![\w-])(white|black)(?![\w-])/i', function ($m) use ($role) {
        $v = strtolower($m[1]) === 'white' ? 255 : 0;
        return theme_colour_out(theme_map_rgb($v, $v, $v, $role), 1.0);
    }, $s);
    return $s;
}

function theme_colour_out(array $rgb, float $a): string
{
    [$r, $g, $b] = array_map(fn($v) => (int)round(max(0, min(255, $v))), $rgb);
    if ($a >= 0.999) return sprintf('#%02x%02x%02x', $r, $g, $b);
    return sprintf('rgba(%d, %d, %d, %s)', $r, $g, $b, rtrim(rtrim(number_format($a, 3, '.', ''), '0'), '.'));
}

/** The colour rule itself. */
function theme_map_rgb(float $r, float $g, float $b, string $role, float $alpha = 1.0): array
{
    [$h, $s, $l] = theme_rgb_to_hsl($r / 255, $g / 255, $b / 255);
    $vivid = $s > 0.45 && $l > 0.18 && $l < 0.68;   // brand purple, gold, red/green buttons and badges

    if ($role === 'text') {
        if ($l >= 0.6) return [$r, $g, $b];   // already light (white text on buttons, light labels)
        // dark -> light, keeping the hue; strong colours get a softer, lighter tint that reads on dark surfaces
        $l2 = $vivid ? min(0.86, 0.62 + (0.6 - $l) * 0.45) : 0.94 - $l * 0.55;
        $s2 = $vivid ? min($s, 0.75) : min($s, 0.25);
        return theme_hsl_to_rgb($h, $s2, $l2);
    }

    if ($role === 'bg') {
        // shadows and dark see-through overlays (photo captions, backdrops) stay as they are
        if ($l < 0.25 && $alpha < 1) return [$r, $g, $b];
        if ($vivid || $l < 0.45) return [$r, $g, $b];   // strong / dark fills already work on a dark page
        // pale -> deep surface: white becomes #1c1726, light lavender tints a little lighter and purpler
        $l2 = 0.115 + (1 - $l) * 0.55;
        $s2 = $s < 0.08 ? 0.22 : min(0.45, $s * 0.6 + 0.12);
        if ($h === 0.0 && $s < 0.08) $h = 270 / 360;   // greys and white pick up the site's purple undertone
        return theme_hsl_to_rgb($h, $s2, min($l2, 0.42));
    }

    // lines / borders
    if ($vivid) return [$r, $g, $b];
    if ($l >= 0.45) {
        $l2 = 0.19 + (1 - $l) * 0.45;
        $s2 = $s < 0.08 ? 0.16 : min(0.35, $s * 0.5 + 0.1);
        if ($s < 0.08) $h = 270 / 360;
        return theme_hsl_to_rgb($h, $s2, $l2);
    }
    return theme_hsl_to_rgb($h, min($s, 0.3), 0.55 - $l * 0.4);   // dark lines -> mid lines
}

function theme_rgb_to_hsl(float $r, float $g, float $b): array
{
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $l = ($max + $min) / 2;
    if ($max === $min) return [0.0, 0.0, $l];
    $d = $max - $min;
    $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
    $h = match ($max) {
        $r => ($g - $b) / $d + ($g < $b ? 6 : 0),
        $g => ($b - $r) / $d + 2,
        default => ($r - $g) / $d + 4,
    };
    return [$h / 6, $s, $l];
}

function theme_hsl_to_rgb(float $h, float $s, float $l): array
{
    if ($s == 0) return [$l * 255, $l * 255, $l * 255];
    $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
    $p = 2 * $l - $q;
    $f = function ($t) use ($p, $q) {
        if ($t < 0) $t += 1;
        if ($t > 1) $t -= 1;
        if ($t < 1 / 6) return $p + ($q - $p) * 6 * $t;
        if ($t < 1 / 2) return $q;
        if ($t < 2 / 3) return $p + ($q - $p) * (2 / 3 - $t) * 6;
        return $p;
    };
    return [$f($h + 1 / 3) * 255, $f($h) * 255, $f($h - 1 / 3) * 255];
}

/** Page-wide basics for dark mode (before the translated rules). */
function theme_base_rules(): string
{
    return <<<CSS
html[data-theme="dark"] { color-scheme: dark; --bg: #15111d; --text: #e7e3ee; --muted: #a9a3b6; --purple-link: #c9a8ef; }
html[data-theme="dark"] body { background: var(--bg); color: var(--text); }
html[data-theme="dark"] input, html[data-theme="dark"] select, html[data-theme="dark"] textarea { background-color: #1e1829; color: var(--text); border-color: #3a3149; }
html[data-theme="dark"] ::placeholder { color: #7f7891; }
html[data-theme="dark"] img { color-scheme: normal; }
CSS;
}

/** Hand-made touches the automatic translation cannot know about (after the translated rules, so they win). */
function theme_extra_rules(): string
{
    return <<<CSS
/* logos are PNGs on white: give them a white disc instead of blending into the dark page */
html[data-theme="dark"] .home-hero img, html[data-theme="dark"] .brand img { mix-blend-mode: normal; background: #fff; border-radius: 50%; padding: 6px; }
html[data-theme="dark"] .home-hero img { padding: 14px; }
html[data-theme="dark"] .dash-brand img { background: #fff; border-radius: 6px; padding: 2px; }
CSS;
}
