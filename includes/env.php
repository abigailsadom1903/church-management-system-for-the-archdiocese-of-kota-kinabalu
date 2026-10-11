<?php
// Reads secrets from the .env file in the project folder (see .env.example). .env is listed in .gitignore
// and blocked from the web by .htaccess, so keys kept there are never pushed to GitHub or served to a browser.

/** The value of one .env setting, or '' if it is not set. A real environment variable wins over the file. */
function env_value(string $name): string
{
    static $file = null;
    if ($file === null) {
        $file = [];
        $path = dirname(__DIR__) . '/.env';
        foreach (is_file($path) ? file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
            [$key, $value] = explode('=', $line, 2);
            $value = trim($value);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0]) {
                $value = substr($value, 1, -1);   // KEY="value" or KEY='value'
            }
            $file[trim($key)] = $value;
        }
    }
    $real = getenv($name);
    return is_string($real) && $real !== '' ? $real : ($file[$name] ?? '');
}
