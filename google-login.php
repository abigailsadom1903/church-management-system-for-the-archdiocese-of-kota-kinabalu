<?php
// "Continue with Google": sends the person to Google, and handles Google sending them back here.
//   - Google account already linked            -> signed in
//   - email already has a Public User account  -> the Google account is linked to it, signed in
//   - new person                               -> google-register.php to choose their home parish
session_start();
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/google_auth.php';

/** Back to the login page with a message. */
function google_fail(string $message): void
{
    $_SESSION['login_error'] = $message;
    header('Location: login.php');
    exit;
}

if (!google_configured()) {
    google_fail('Signing in with Google is not available yet. Please use your email and password.');
}

// ---- Step 1: off to Google -----------------------------------------------------------------
if (!isset($_GET['code']) && !isset($_GET['error'])) {
    $_SESSION['google_state'] = bin2hex(random_bytes(16));
    header('Location: ' . google_auth_url($_SESSION['google_state']));
    exit;
}

// ---- Step 2: back from Google ----------------------------------------------------------------
$state = (string)($_GET['state'] ?? '');
$expected = (string)($_SESSION['google_state'] ?? '');
unset($_SESSION['google_state']);   // one use only
if ($expected === '' || !hash_equals($expected, $state)) {
    google_fail('Your Google sign-in expired. Please try again.');
}
if (isset($_GET['error'])) {
    google_fail($_GET['error'] === 'access_denied' ? 'Google sign-in was cancelled.' : 'Google sign-in did not work. Please try again.');
}

$profile = google_fetch_profile((string)$_GET['code']);
if (!$profile) {
    google_fail('We could not get your details from Google, or your Google email is not verified. Please try again.');
}

$stmt = $pdo->prepare('SELECT user_id, user_name, user_photo, google_sub FROM user WHERE google_sub = ?');
$stmt->execute([$profile['sub']]);
if ($user = $stmt->fetch()) {
    google_sign_in($user);
}

$stmt = $pdo->prepare('SELECT user_id, user_name, user_photo, google_sub FROM user WHERE user_email = ?');
$stmt->execute([$profile['email']]);
if ($user = $stmt->fetch()) {
    if ($user['google_sub'] !== null && $user['google_sub'] !== $profile['sub']) {
        google_fail('This email is already linked to a different Google account. Please sign in with your password.');
    }
    // Google has verified they own this email, so the Google account can be linked to it.
    $pdo->prepare('UPDATE user SET google_sub = ? WHERE user_id = ?')->execute([$profile['sub'], (int)$user['user_id']]);
    google_sign_in($user);
}

// New to the site: they still need to pick a home parish (and give a phone number) before the account exists.
$_SESSION['google_pending'] = $profile + ['at' => time()];
header('Location: google-register.php');
exit;
