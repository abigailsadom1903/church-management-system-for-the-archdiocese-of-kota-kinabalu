<?php
// "Continue with Google" settings. Copy this file to config/google.php and fill in your own values.
// config/google.php is listed in .gitignore, so the secret never gets pushed to GitHub.
//
// Where to get them (free):
//   1. https://console.cloud.google.com/  ->  create a project (or pick one)
//   2. APIs & Services -> OAuth consent screen: User type "External", fill in the app name and your email,
//      scopes: openid, email, profile. While it is in "Testing", add your own Google account under Test users.
//   3. APIs & Services -> Credentials -> Create credentials -> OAuth client ID -> "Web application"
//      Authorised redirect URI: the address of google-login.php on your site, e.g.
//        http://localhost/church-management-system/google-login.php
//   4. Paste the Client ID and Client secret below.
// While both are empty the Google button is simply not shown.

define('GOOGLE_CLIENT_ID', '');
define('GOOGLE_CLIENT_SECRET', '');

// Leave empty to use this site's own google-login.php address (must match the redirect URI in Google exactly).
define('GOOGLE_REDIRECT_URI', '');
