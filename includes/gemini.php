<?php
// One place that talks to Google Gemini (free tier). Used by the AI Announcement Generator
// (includes/announcement_ai.php) and the Intelligent Church Assistant (includes/assistant_ai.php).
// The key lives in .env as GEMINI_API_KEY (see .env.example).
require_once __DIR__ . '/env.php';

// Models with a free tier. The first is the quickest and most reliably available; when one is overloaded or
// too slow the next is tried.
const GEMINI_MODELS = ['gemini-flash-lite-latest', 'gemini-3.5-flash', 'gemini-flash-latest'];

/** True once GEMINI_API_KEY is in .env. */
function gemini_ready(): bool
{
    return env_value('GEMINI_API_KEY') !== '' && function_exists('curl_init');
}

/**
 * Sends one request and returns ['text' => the model's reply].
 * On failure returns ['error' => 'setup' | 'key' | 'limit' | 'network' | 'blocked' | 'failed'] and writes the
 * reason to logs/ai-errors.log.
 *
 * $contents: the conversation, e.g. [['role' => 'user', 'parts' => [['text' => '...']]]].
 * $config:   Gemini generationConfig (e.g. a JSON responseSchema). $timeout: seconds allowed per model.
 * $models:   how many of GEMINI_MODELS may be tried.
 */
function gemini_generate(string $system, array $contents, array $config = [], int $timeout = 20, int $models = 3): array
{
    if (!gemini_ready()) return ['error' => 'setup'];

    $request = ['systemInstruction' => ['parts' => [['text' => $system]]], 'contents' => $contents];
    if ($config) $request['generationConfig'] = $config;
    $body = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

    $raw = false;
    $status = 0;
    $netError = '';
    foreach (array_slice(GEMINI_MODELS, 0, max(1, $models)) as $model) {
        $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . env_value('GEMINI_API_KEY')],   // the key travels in a header, never in the URL
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $netError = curl_error($ch);
        curl_close($ch);
        // Busy, rate-limited, withdrawn or too slow: the next model may still answer. Anything else is final.
        if ($raw !== false && !in_array($status, [404, 429, 500, 503], true)) break;
        gemini_log($model . ': ' . ($raw === false ? $netError : $status . ' ' . mb_substr((string)$raw, 0, 200)));
    }

    if ($raw === false) return ['error' => 'network'];
    $reply = json_decode((string)$raw, true);
    if ($status !== 200) {
        $why = (string)($reply['error']['message'] ?? mb_substr((string)$raw, 0, 300));
        gemini_log($status . ' ' . $why);
        if (in_array($status, [401, 403], true) || ($status === 400 && str_contains($why, 'API key'))) return ['error' => 'key'];
        return ['error' => $status === 429 ? 'limit' : 'failed'];
    }

    $candidate = $reply['candidates'][0] ?? null;
    if (!$candidate || !empty($reply['promptFeedback']['blockReason']) || in_array($candidate['finishReason'] ?? '', ['SAFETY', 'RECITATION', 'PROHIBITED_CONTENT', 'BLOCKLIST'], true)) {
        return ['error' => 'blocked'];
    }
    $text = '';
    foreach ($candidate['content']['parts'] ?? [] as $part) {
        if (isset($part['text']) && empty($part['thought'])) $text .= $part['text'];
    }
    return trim($text) !== '' ? ['text' => $text] : ['error' => 'failed'];
}

function gemini_log(string $error): void
{
    $dir = dirname(__DIR__) . '/logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($dir . '/ai-errors.log', date('Y-m-d H:i:s') . '  ' . str_replace(["\r", "\n"], ' ', mb_substr($error, 0, 600)) . "\n", FILE_APPEND | LOCK_EX);
}
