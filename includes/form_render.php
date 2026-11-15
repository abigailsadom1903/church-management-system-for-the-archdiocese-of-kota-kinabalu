<?php
// Form field rendering shared by the sacrament and pastoral request forms (needs e() from the page).

function fc(array $errors, string $key, string $extra = ''): string { return trim('pfield' . (isset($errors[$key]) ? ' has-error' : '') . ' ' . $extra); }
function ferr(array $errors, string $key): string { return isset($errors[$key]) ? '<span class="row-error">' . e($errors[$key]) . '</span>' : ''; }

/**
 * One form field. Field options: full (span both columns), boxes (radio options drawn as boxes), ph (placeholder),
 * nolabel (the section heading is the label, so the label is only for screen readers), pick (a calendar that greys out taken days),
 * side (fills the right column beside the other fields; the grid needs class has-side and --side-rows).
 */
function sx_render_field(array $fd, array $f, array $errors, array $taken = []): void
{
    $k = $fd['k'];
    $v = $f[$k] ?? '';
    $cls = fc($errors, $k, !empty($fd['full']) ? 'is-full' : (!empty($fd['side']) ? 'is-side' : ''));
    $labelCls = !empty($fd['nolabel']) ? ' class="sr-only"' : '';
    $ph = !empty($fd['ph']) ? ' placeholder="' . e($fd['ph']) . '"' : '';
    echo '<div class="' . $cls . '">';
    if ($fd['t'] === 'radio') {
        echo '<span class="sx-label' . (!empty($fd['nolabel']) ? ' sr-only' : '') . '">' . e($fd['l']) . '</span><div class="sx-radios' . (!empty($fd['boxes']) ? ' is-boxes' : '') . '">';
        foreach ($fd['o'] as $val => $lab) {
            echo '<label><input type="radio" name="' . e($k) . '" value="' . e($val) . '"' . ($v === $val ? ' checked' : '') . '> <span>' . e($lab) . '</span></label>';
        }
        echo '</div>';
    } else {
        echo '<label for="' . e($k) . '"' . $labelCls . '>' . e($fd['l']) . '</label>';
        if ($fd['t'] === 'textarea') {
            echo '<textarea id="' . e($k) . '" name="' . e($k) . '" rows="4" maxlength="' . (int)($fd['max'] ?? 300) . '"' . $ph . '>' . e($v) . '</textarea>';
        } elseif ($fd['t'] === 'select') {
            echo '<select id="' . e($k) . '" name="' . e($k) . '"><option value="">Select&hellip;</option>';
            foreach ($fd['o'] as $val => $lab) echo '<option value="' . e($val) . '"' . ($v === $val ? ' selected' : '') . '>' . e($lab) . '</option>';
            echo '</select>';
        } else {
            $type = ['tel' => 'tel', 'email' => 'email', 'date' => 'date', 'number' => 'number', 'datetime' => 'datetime-local'][$fd['t']] ?? 'text';
            $extra = $fd['t'] === 'date' && !empty($fd['past']) ? ' max="' . date('Y-m-d') . '"' : ($fd['t'] === 'date' && !empty($fd['future']) ? ' min="' . date('Y-m-d', strtotime('+1 day')) . '"' : '');
            if ($fd['t'] === 'number') $extra = ' min="' . (int)($fd['min'] ?? 0) . '" max="' . (int)($fd['max'] ?? 999) . '"';
            echo '<input type="' . $type . '" id="' . e($k) . '" name="' . e($k) . '" maxlength="' . (int)($fd['max'] ?? 100) . '"' . $extra . $ph . ' value="' . e($v) . '">';
            if (!empty($fd['geo'])) {   // "Use my current location": fills the address and keeps the exact spot (assets/js/geo-field.js)
                $geo = sx_geo_clean((string)($f[$k . '_geo'] ?? ''));
                echo '<input type="hidden" name="' . e($k) . '_geo" value="' . e($geo) . '" data-geo-for="' . e($k) . '">'
                   . '<div class="sx-geo" data-geo="' . e($k) . '">'
                   . '<button type="button" class="sx-geo-btn">' . sx_icon('pin', 14) . ' <span>Use my current location</span></button>'
                   . '<span class="sx-geo-msg" role="status" aria-live="polite">' . ($geo ? 'Exact location saved. You can still add details like a room or ward number.' : '') . '</span>'
                   . '<a class="sx-geo-map" target="_blank" rel="noopener"' . ($geo ? ' href="https://www.google.com/maps/search/?api=1&amp;query=' . rawurlencode($geo) . '"' : ' hidden') . '>Check on map</a>'
                   . '</div>';
            }
        }
    }
    if (!empty($fd['pick'])) {   // a calendar that greys out what is already taken (the plain input above stays as the fallback)
        $slots = $fd['t'] === 'datetime';
        $hours = $fd['hours'] ?? ANOINTING_HOURS;
        $ahead = $fd['days'] ?? ($slots ? ANOINTING_DAYS_AHEAD : WEDDING_DAYS_AHEAD);
        $minDays = $fd['min_days'] ?? ($slots ? 0 : 1);
        echo '<div class="dp" data-mode="' . ($slots ? 'datetime' : 'date') . '" data-target="' . e($k) . '"'
           . ' data-min="' . date('Y-m-d', strtotime('+' . $minDays . ' days')) . '"'
           . ' data-max="' . date('Y-m-d', strtotime('+' . $ahead . ' days')) . '"'
           . ' data-taken="' . e(json_encode($slots ? (object)($taken['slots'] ?? []) : ($taken['dates'] ?? []))) . '"'
           . ($slots ? ' data-hours="' . e(implode(',', $hours)) . '"' : '')
           . (!empty($fd['dow']) ? ' data-dow="' . e(implode(',', $fd['dow'])) . '"' : '') . '></div>';
    }
    echo ferr($errors, $k) . '</div>';
}

/** One document upload (a tile with icon, title and hint, or a caption above a plain drop zone). */
function sx_render_doc(string $key, array $doc, array $errors, array $files, ?array $app, string $idKey = 'application_id', string $fileScript = 'sacrament-file.php'): void
{
    [$label, $hint, $required, $icon, $tile] = $doc;
    $has = !empty($files[$key]) && sx_doc_path($files[$key]);
    echo '<div class="' . fc($errors, 'doc_' . $key, 'sx-up' . ($tile ? ' is-tile' : '')) . '">';
    if ($tile) {
        echo '<label class="sx-tile' . ($has ? ' has-file' : '') . '">'
           . '<input type="file" name="doc_' . e($key) . '" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">'
           . '<span class="sx-tile-ico">' . sx_icon($icon, 26) . '</span>'
           . '<b>' . e($label) . '</b><small>' . e($hint) . '</small>'
           . '<span class="sx-choose sx-drop-text">' . ($has ? 'File on record &mdash; replace' : 'Choose Files') . '</span></label>';
    } else {
        echo '<span class="sx-label">' . e($label) . ($required ? ($icon === 'cloud' ? ' <span class="req">*</span>' : '') : ' <em>(if applicable)</em>') . '</span>'
           . '<label class="sx-drop' . ($has ? ' has-file' : '') . '">'
           . '<input type="file" name="doc_' . e($key) . '" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">'
           . sx_icon($icon, 26)
           . '<span class="sx-drop-text">' . ($has ? 'File on record &mdash; click to replace' : e($hint ?: 'Click to upload file')) . '</span></label>';
    }
    if ($has && $app) echo '<a class="sx-link sx-view" href="' . e($fileScript) . '?id=' . (int)$app[$idKey] . '&amp;doc=' . e($key) . '" target="_blank" rel="noopener">View uploaded file</a>';
    echo ferr($errors, 'doc_' . $key) . '</div>';
}

