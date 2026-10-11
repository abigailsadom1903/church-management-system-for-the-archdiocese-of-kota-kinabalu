<?php
// Catholic Sabah News for the home page slideshow: the latest posts of https://www.catholicsabah.com/ (the Archdiocese's
// news site), read from its public WordPress feed.
//
// The list is kept in logs/catholic-sabah-news.json and refreshed at most every 30 minutes, so the home page does not
// wait on another website for each visitor. If that site cannot be reached, the last list that was read is shown; with
// no list at all the slideshow shows a single card that links to the site.

const NEWS_SITE = 'https://www.catholicsabah.com/';
const NEWS_FEED = NEWS_SITE . 'wp-json/wp/v2/posts?per_page=8&_embed=wp:featuredmedia&_fields=id,date,link,title,_links,_embedded';
const NEWS_CACHE = __DIR__ . '/../logs/catholic-sabah-news.json';
const NEWS_MAX_AGE = 1800;   // seconds before the list is read again
const NEWS_RETRY = 600;      // after a failed read, wait this long before trying again

/** Only links and pictures on catholicsabah.com itself are ever used. */
function news_own_url(?string $url): ?string
{
    $url = trim((string)$url);
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    return parse_url($url, PHP_URL_SCHEME) === 'https' && preg_match('/(^|\.)catholicsabah\.com$/', $host) ? $url : null;
}

/** Reads the feed; null when the site cannot be reached or answers with something unexpected. */
function news_fetch(): ?array
{
    if (!function_exists('curl_init')) return null;
    $ch = curl_init(NEWS_FEED);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5,   // never hold the home page up for long
        CURLOPT_USERAGENT => 'ArchKK-Parish-Portal/1.0', CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $ok = $body !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);
    $posts = $ok ? json_decode((string)$body, true) : null;
    if (!is_array($posts)) return null;

    $items = [];
    foreach ($posts as $p) {
        $link = news_own_url($p['link'] ?? null);
        $title = trim(html_entity_decode(strip_tags((string)($p['title']['rendered'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (!$link || $title === '') continue;
        $media = $p['_embedded']['wp:featuredmedia'][0] ?? [];
        $sizes = $media['media_details']['sizes'] ?? [];
        $image = null;
        foreach (['jnews-750x375', 'medium_large', 'jnews-750x536', 'large', 'full'] as $size) {   // a wide, light picture first
            if ($image = news_own_url($sizes[$size]['source_url'] ?? null)) break;
        }
        $image = $image ?: news_own_url($media['source_url'] ?? null);
        $time = strtotime((string)($p['date'] ?? ''));
        $items[] = ['title' => mb_substr($title, 0, 200), 'link' => $link, 'image' => $image, 'date' => $time ? date('Y-m-d', $time) : null];
    }
    return $items ?: null;
}

/**
 * The latest news, newest first: [['title', 'link', 'image' (or null), 'date' (Y-m-d or null)], ...]. Empty when the
 * site has never been reached from this server.
 */
function catholic_sabah_news(int $limit = 6): array
{
    $cache = is_file(NEWS_CACHE) ? json_decode((string)@file_get_contents(NEWS_CACHE), true) : null;
    $items = is_array($cache['items'] ?? null) ? $cache['items'] : [];
    $checked = (int)($cache['checked'] ?? 0);
    $wait = !empty($cache['failed']) ? NEWS_RETRY : NEWS_MAX_AGE;

    if (time() - $checked >= $wait) {
        $fresh = news_fetch();
        if ($fresh !== null) $items = $fresh;
        // Written even after a failure (keeping the old list), so a site that is down is not asked again by every visitor.
        @file_put_contents(NEWS_CACHE, json_encode(['checked' => time(), 'failed' => $fresh === null, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
    return array_slice($items, 0, max(1, $limit));
}
