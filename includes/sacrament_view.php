<?php
// Read-only view of an application's answers and documents (used by the status page and the admin review page).

/**
 * @param array $cfg     the sacrament's form definition (sx_config)
 * @param array $details the saved answers
 * @param array $files   the saved document names
 */
function sx_render_details(array $cfg, array $details, array $files, int $appId, string $fileScript = 'sacrament-file.php'): void
{
    $h = fn(?string $s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

    if (($cfg['kind'] ?? 'form') === 'booking') {
        $d = (string)($details['date'] ?? '');
        $t = (string)($details['time'] ?? '');
        echo '<dl class="sx-dl"><dt>Date</dt><dd>' . ($d ? $h(date('l, j F Y', strtotime($d))) : '&mdash;') . '</dd>'
           . '<dt>Time</dt><dd>' . ($t ? $h(date('g:i a', strtotime($t))) : '&mdash;') . '</dd></dl>';
        return;
    }
    foreach ($cfg['sections'] as $s) {
        $rows = '';
        foreach ($s['fields'] ?? [] as $fd) {
            $v = sx_show($fd, (string)($details[$fd['k']] ?? ''));
            $valHtml = ($v !== '' ? nl2br($h($v)) : '<span class="muted">&mdash;</span>') . sx_geo_link($fd, $details);
            // A field marked 'nolabel' repeats the section heading as its label, so skip that redundant cell.
            $rows .= empty($fd['nolabel'])
                ? '<tr><th>' . $h($fd['l']) . '</th><td>' . $valHtml . '</td></tr>'
                : '<tr><td class="sx-table-solo" colspan="2">' . $valHtml . '</td></tr>';
        }
        $docs = '';
        foreach ($s['docs'] ?? [] as $key) {
            $label = $cfg['docs'][$key][0];
            $has = !empty($files[$key]) && sx_doc_path($files[$key]);
            $docs .= '<li>' . $h($label) . ': ' . ($has
                ? '<a class="sx-link" href="' . $h($fileScript) . '?id=' . $appId . '&amp;doc=' . $h($key) . '" target="_blank" rel="noopener">View file</a>'
                : '<span class="muted">not provided</span>') . '</li>';
        }
        if ($rows === '' && $docs === '') continue;
        if (!empty($s['title'])) echo '<h3 class="sx-view-h">' . $h($s['title']) . '</h3>';
        if ($rows !== '') echo '<table class="sx-table">' . $rows . '</table>';
        if ($docs !== '') echo '<ul class="sx-docs">' . $docs . '</ul>';
    }
}
