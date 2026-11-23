<?php
// "Continue with Google" for Public Users (OAuth 2.0 authorization-code flow, no library needed).
// Used by google-login.php (the round trip to Google), google-register.php (new accounts) and the
// login / register pages (the button). Settings live in config/google.php (see config/google.example.php).

if (is_file(dirname(__DIR__) . '/config/google.php')) {
    require_once dirname(__DIR__) . '/config/google.php';
}

/** Is Google sign-in set up? The button is only shown when it is. */
function google_configured(): bool
{
    return defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID !== ''
        && defined('GOOGLE_CLIENT_SECRET') && GOOGLE_CLIENT_SECRET !== '';
}

/** The address Google sends people back to: config value, or this site's google-login.php. */
function google_redirect_uri(): string
{
    if (defined('GOOGLE_REDIRECT_URI') && GOOGLE_REDIRECT_URI !== '') return GOOGLE_REDIRECT_URI;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/google-login.php';
}

/** Google's consent page. $state protects against forged callbacks (checked in google-login.php). */
function google_auth_url(string $state): string
{
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'     => GOOGLE_CLIENT_ID,
        'redirect_uri'  => google_redirect_uri(),
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state,
        'prompt'        => 'select_account',
    ]);
}

/** POST/GET over HTTPS; returns the decoded JSON or null. */
function google_http(string $url, ?array $post = null, ?string $bearer = null): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => $bearer ? ['Authorization: Bearer ' . $bearer] : [],
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($body === false) error_log('Google sign-in: ' . curl_error($ch));
    curl_close($ch);
    $data = is_string($body) ? json_decode($body, true) : null;
    if ($status !== 200 || !is_array($data)) {
        error_log('Google sign-in: HTTP ' . $status . ' from ' . strtok($url, '?'));
        return null;
    }
    return $data;
}

/**
 * Swap the one-time code for the person's Google profile.
 * @return array{sub:string,email:string,name:string}|null  null when anything fails or the email is not verified
 */
function google_fetch_profile(string $code): ?array
{
    $token = google_http('https://oauth2.googleapis.com/token', [
        'code'          => $code,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => google_redirect_uri(),
        'grant_type'    => 'authorization_code',
    ]);
    if (empty($token['access_token'])) return null;

    $info = google_http('https://openidconnect.googleapis.com/v1/userinfo', null, $token['access_token']);
    if (empty($info['sub']) || empty($info['email']) || empty($info['email_verified'])) return null;

    return [
        'sub'   => (string)$info['sub'],
        'email' => mb_strtolower((string)$info['email']),
        'name'  => trim((string)($info['name'] ?? '')),
    ];
}

/** Sign a Public User in (same session values as login.php) and send them on. */
function google_sign_in(array $user): void
{
    require_once __DIR__ . '/login_gate.php';
    session_regenerate_id(true);
    $_SESSION['csrf']       = bin2hex(random_bytes(32));
    $_SESSION['user_id']    = (int)$user['user_id'];
    $_SESSION['user_name']  = $user['user_name'];
    $_SESSION['role']       = 'user';
    $_SESSION['user_photo'] = $user['user_photo'] ?: null;
    unset($_SESSION['google_pending'], $_SESSION['google_state']);
    $dest = safe_next($_SESSION['login_next'] ?? null);
    unset($_SESSION['login_next']);
    header('Location: ' . ($dest ?? 'index.php'));
    exit;
}

/** The "Continue with Google" button (nothing when Google sign-in is not set up). */
function google_button(string $label = 'Continue with Google'): void
{
    if (!google_configured()) return;
    ?>
    <div class="auth-or" aria-hidden="true"><span>or</span></div>
    <a class="btn-google" href="google-login.php">
        <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>
        <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
    </a>
    <?php
}
