<?php
// The editable text of the "About Us" section and the footer's contact details.
// Stored in site_content (edited on superadmin/about.php); anything not saved yet uses the defaults below.

const SITE_CONTENT_DEFAULTS = [
    'about_eyebrow' => 'Who we are',
    'about_title'   => 'Catholic Archdiocesan Centre (CAC)',
    'about_body'    => 'As we moved forward into a more connected era, the Archdiocese of Kota Kinabalu remains committed to serving our faithful through both traditional devotion and modern stewardship.',
    'about_photo'   => '',   // file name inside uploads/site/; empty = assets/images/cac.jpg
    'contact_address' => "Catholic Archdiocesan Centre (CAC)\nJalan Sang Kancil 3, Karamunsing\n88300 Kota Kinabalu Sabah",
    'contact_phone' => '088-203300 / 276006 / 211722',
    'contact_fax'   => '088-203292',
    'contact_email' => 'adkkadmin@catholicadkk.org',
    'map_query'     => 'Catholic Archdiocesan Centre, Jalan Sang Kancil 3, Karamunsing, 88300 Kota Kinabalu, Sabah',
];

/** Every value, saved or default. Read once per page. */
function site_content(): array
{
    global $pdo;
    static $content = null;
    if ($content !== null) return $content;
    $content = SITE_CONTENT_DEFAULTS;
    if (isset($pdo)) {
        try {
            foreach ($pdo->query('SELECT content_key, content_value FROM site_content') as $r) {
                if (array_key_exists($r['content_key'], $content)) $content[$r['content_key']] = $r['content_value'];
            }
        } catch (PDOException $ex) { /* table not created yet: database/site_content.sql */ }
    }
    return $content;
}

/** The About photo's path from the site root: the uploaded one if it is still there, else the original. */
function site_about_photo(): string
{
    $file = site_content()['about_photo'];
    if ($file !== '' && preg_match('/^[\w.-]+$/', $file) && is_file(dirname(__DIR__) . '/uploads/site/' . $file)) {
        return 'uploads/site/' . $file;
    }
    return 'assets/images/cac.jpg';
}
