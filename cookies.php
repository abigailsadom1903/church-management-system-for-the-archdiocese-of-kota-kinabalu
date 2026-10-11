<?php
session_start();
require __DIR__ . '/includes/legal.php';

render_legal_page('Cookies', 'The portal uses only the cookies it needs to work.', [
    ['A sign-in cookie', 'When you visit, your browser keeps one small session cookie. It lets the portal remember that you are signed in while you move between pages. It is removed when you sign out or close your browser.'],
    ['Protection', 'The same session is used to protect forms from being sent by other websites on your behalf.'],
    ['What we do not use', 'We do not use advertising or tracking cookies, and we do not build a profile of what you read.'],
    ['Outside fonts and maps', 'The pages load fonts from Google Fonts, and the About Us section shows a Google map. Those services may set their own cookies under their own policies.'],
    ['Your choices', 'You can clear or block cookies in your browser settings. The public pages still work, but you will not be able to sign in.'],
]);
