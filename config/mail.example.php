<?php
// Email sending settings (SMTP). Copy this file to config/mail.php and fill in your own values.
// config/mail.php is listed in .gitignore, so the password never gets pushed to GitHub.
//
// With a Gmail account (free):
//   1. Turn on 2-Step Verification:  https://myaccount.google.com/security
//   2. Create an app password:       https://myaccount.google.com/apppasswords
//      (name it e.g. "Church Management System"; Google shows a 16-letter password once)
//   3. Put the Gmail address in SMTP_USER and the 16 letters in SMTP_PASS (spaces do not matter).
// While SMTP_USER or SMTP_PASS is empty, emails on localhost are only written to the logs/ folder.

define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);            // 587 = STARTTLS, 465 = SSL
define('SMTP_USER', '');             // e.g. yourname@gmail.com
define('SMTP_PASS', '');             // the app password, NOT your normal Gmail password

// Address the emails come from. Leave empty to use SMTP_USER (Gmail only allows its own address).
define('SMTP_FROM', '');
