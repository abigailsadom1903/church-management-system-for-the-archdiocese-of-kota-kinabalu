<?php
// One place that talks to the AI. Used by the AI Announcement Generator (includes/announcement_ai.php) and the
// Intelligent Church Assistant (includes/assistant_ai.php). Google Gemini (free tier) is asked first; when it is
// out of free quota, too slow or failing, Groq (free tier, OpenAI-style API, usually answers in about a second)
// answers instead. Keys live in .env as GEMINI_API_KEY and GROQ_API_KEY (see .env.example); either one is enough.
require_once __DIR__ . '/env.php';

// Gemini models with a free tier, in the order they are asked. How long one takes changes from minute to minute
// (a few seconds, or half a minute when Google is busy), so when the first has not answered after
// GEMINI_HEDGE_SECONDS the next is asked as well, and the first good answer is used. Each model has its own
// small daily free quota, so a second model is only asked when needed, and a model whose daily quota is used
// up is skipped until it starts again (see gemini_spent_models()).
const GEMINI_MODELS = ['gemini-flash-lite-latest', 'gemini-3.5-flash', 'gemini-flash-latest'];
const GEMINI_HEDGE_SECONDS = 5;
// The least thinking each model accepts (thinking adds many seconds and these tasks do not need it).
const GEMINI_THINKING = ['gemini-flash-latest' => 'low'];

// Groq models, in the order they are tried (checked against Groq's model list, October 2026): the largest writes
// best; the others have their own free quota. The gpt-oss models reason before answering: kept "low", and their
// reasoning counts towards the length limit, so they get GROQ_REASONING_TOKENS on top.
const GROQ_MODELS = ['openai/gpt-oss-120b', 'qwen/qwen3.8-27b', 'openai/gpt-oss-20b'];
const GROQ_REASONING_TOKENS = 512;
// Seconds kept back for Groq when it is set up (it is quick, so Gemini still gets most of the wait).
const GROQ_RESERVE_SECONDS = 5;

/** True once an AI key (GEMINI_API_KEY or GROQ_API_KEY) is in .env. */
function gemini_ready(): bool
{
    return (env_value('GEMINI_API_KEY') !== '' || groq_ready()) && function_exists('curl_init');
}

function groq_ready(): bool
{
    return env_value('GROQ_API_KEY') !== '' && function_exists('curl_init');
}

/**
 * Sends one request and returns ['text' => the model's reply, 'model' => who wrote it].
 * On failure returns ['error' => 'setup' | 'key' | 'limit' | 'limit_day' | 'network' | 'blocked' | 'failed'] and writes the
 * reason to logs/ai-errors.log.
 *
 * $contents: the conversation, e.g. [['role' => 'user', 'parts' => [['text' => '...']]]] ('model' for the AI's turns).
 * $config:   Gemini generationConfig (e.g. a JSON responseSchema, maxOutputTokens, temperature); Groq gets the same
 *            settings translated. Gemini's thinking is kept to a minimum unless $config sets its own thinkingConfig.
 * $timeout:  seconds to wait in all. $models: how many of GEMINI_MODELS may be asked.
 */
function gemini_generate(string $system, array $contents, array $config = [], int $timeout = 20, int $models = 3): array
{
    if (!gemini_ready()) return ['error' => 'setup'];

    $end = microtime(true) + $timeout;
    $groq = groq_ready();
    $errors = [];

    if (env_value('GEMINI_API_KEY') !== '') {
        $wanted = array_slice(GEMINI_MODELS, 0, max(1, $models));
        $queue = array_values(array_diff($wanted, gemini_spent_models()));
        if (count($queue) < count($wanted)) $errors[] = 'limit_day';   // some have used today's free quota
        $geminiTime = $groq ? max(3, $timeout - min(GROQ_RESERVE_SECONDS, intdiv($timeout, 2))) : $timeout;
        if ($queue) {
            $out = gemini_ask($system, $contents, $config, $geminiTime, $queue);
            if (isset($out['text'])) return $out;
            $errors = array_merge($errors, $out['errors']);
            if (in_array('blocked', $errors, true)) return ['error' => 'blocked'];   // unsuitable: do not ask somebody else
        }
    }

    if ($groq) {
        $left = (int)floor($end - microtime(true));
        $out = groq_ask($system, $contents, $config, max(3, $left));
        if (isset($out['text'])) return $out;
        $errors = array_merge($errors, $out['errors']);
    }

    // Nobody answered: report the reason that matters most to the person.
    foreach (['key', 'limit', 'limit_day', 'blocked', 'failed', 'network'] as $why) if (in_array($why, $errors, true)) return ['error' => $why];
    return ['error' => 'network'];
}

/** Gemini: asks $queue's models (staggered, see GEMINI_HEDGE_SECONDS). ['text', 'model'] or ['errors' => [...]]. */
function gemini_ask(string $system, array $contents, array $config, int $timeout, array $queue): array
{
    $deadline = microtime(true) + $timeout;
    $multi = curl_multi_init();
    $handles = [];
    // Starts the next model in the queue; false when there is none left or no time to ask it.
    $start = function () use (&$queue, &$handles, $multi, $deadline, $system, $contents, $config): bool {
        $left = (int)floor($deadline - microtime(true));
        if (!$queue || $left < 2) return false;
        $model = array_shift($queue);
        $body = json_encode([
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => $contents,
            'generationConfig' => $config + ['thinkingConfig' => ['thinkingLevel' => GEMINI_THINKING[$model] ?? 'minimal']],
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . env_value('GEMINI_API_KEY')],   // the key travels in a header, never in the URL
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $left,   // a fresh connection here sometimes takes several seconds
            CURLOPT_TIMEOUT => $left,
        ]);
        curl_multi_add_handle($multi, $ch);
        $handles[(int)$ch] = [$ch, $model];
        return true;
    };

    $start();
    $hedgeAt = microtime(true) + GEMINI_HEDGE_SECONDS;
    $result = null;
    $errors = [];
    do {
        $state = curl_multi_exec($multi, $running);
        while ($done = curl_multi_info_read($multi)) {
            [$ch, $model] = $handles[(int)$done['handle']];
            $raw = curl_multi_getcontent($ch);
            $out = $done['result'] === CURLE_OK
                ? gemini_parse((int)curl_getinfo($ch, CURLINFO_HTTP_CODE), (string)$raw, $model)
                : ['error' => 'network', 'why' => curl_error($ch) ?: curl_strerror($done['result'])];
            if (isset($out['text'])) { $result = $out; break 2; }
            gemini_log($model . ': ' . $out['why']);
            $errors[] = $out['error'];
            if ($out['error'] === 'limit_day') gemini_mark_spent($model);
            if ($out['error'] === 'key') break 2;   // a rejected key fails for every model
            if ($start()) { $running = true; $hedgeAt = microtime(true) + GEMINI_HEDGE_SECONDS; }   // failed: ask the next one now
        }
        if (microtime(true) >= $hedgeAt && $start()) { $running = true; $hedgeAt = microtime(true) + GEMINI_HEDGE_SECONDS; }   // slow: ask the next one too
        if ($running) curl_multi_select($multi, 0.25);
    } while ($running && $state === CURLM_OK && microtime(true) < $deadline + 1);

    foreach ($handles as [$ch]) { curl_multi_remove_handle($multi, $ch); curl_close($ch); }
    curl_multi_close($multi);

    if (!$result && count($errors) < count($handles)) $errors[] = 'network';   // still running at the deadline: too slow
    return $result ?? ['errors' => $errors];
}

/** One Gemini model's response: ['text' => ...], or ['error' => ..., 'why' => what to log]. */
function gemini_parse(int $status, string $raw, string $model): array
{
    $reply = json_decode($raw, true);
    if ($status !== 200) {
        $why = $status . ' ' . (string)($reply['error']['message'] ?? mb_substr($raw, 0, 300));
        if (in_array($status, [401, 403], true) || ($status === 400 && str_contains($why, 'API key'))) return ['error' => 'key', 'why' => $why];
        // 429 is either the per-minute limit (gone in a minute) or the daily free allowance (gone until tomorrow).
        if ($status === 429) return ['error' => str_contains($raw, 'PerDay') ? 'limit_day' : 'limit', 'why' => $why];
        return ['error' => 'failed', 'why' => $why];
    }
    $candidate = $reply['candidates'][0] ?? null;
    if (!$candidate || !empty($reply['promptFeedback']['blockReason']) || in_array($candidate['finishReason'] ?? '', ['SAFETY', 'RECITATION', 'PROHIBITED_CONTENT', 'BLOCKLIST'], true)) {
        return ['error' => 'blocked', 'why' => 'blocked: ' . ($reply['promptFeedback']['blockReason'] ?? $candidate['finishReason'] ?? 'no answer')];
    }
    $text = '';
    foreach ($candidate['content']['parts'] ?? [] as $part) {
        if (isset($part['text']) && empty($part['thought'])) $text .= $part['text'];
    }
    return trim($text) !== '' ? ['text' => $text, 'model' => $model] : ['error' => 'failed', 'why' => 'empty reply (' . ($candidate['finishReason'] ?? '?') . ')'];
}

// ---- Gemini models whose daily free quota is used up ------------------------------------------------------
// Google starts the daily quota again at midnight Pacific time, i.e. 07:00 or 08:00 UTC. A spent model is skipped
// until 08:00 UTC so the chat does not wait for a refusal on every message. Kept in logs/ (blocked from the web).

function gemini_spent_file(): string
{
    return dirname(__DIR__) . '/logs/ai-quota.json';
}

/** Models still known to be out of daily quota. */
function gemini_spent_models(): array
{
    $spent = json_decode((string)@file_get_contents(gemini_spent_file()), true);
    return is_array($spent) ? array_keys(array_filter($spent, fn($until) => (int)$until > time())) : [];
}

function gemini_mark_spent(string $model): void
{
    $spent = json_decode((string)@file_get_contents(gemini_spent_file()), true);
    $spent = is_array($spent) ? array_filter($spent, fn($until) => (int)$until > time()) : [];
    $reset = strtotime('today 08:00 UTC');
    $spent[$model] = $reset > time() ? $reset : strtotime('tomorrow 08:00 UTC');
    @file_put_contents(gemini_spent_file(), json_encode($spent), LOCK_EX);
}

// ---- Groq (free backup) -------------------------------------------------------------------------------------

/** Groq: tries GROQ_MODELS in turn within $timeout seconds. ['text', 'model'] or ['errors' => [...]]. */
function groq_ask(string $system, array $contents, array $config, int $timeout): array
{
    $deadline = microtime(true) + $timeout;
    // Gemini's structured-output schema becomes an instruction (Groq's JSON mode only promises valid JSON).
    $json = ($config['responseMimeType'] ?? '') === 'application/json';
    if ($json) {
        $system .= "\n\nReply with only one JSON object and nothing else"
                 . (isset($config['responseSchema']) ? ', matching this JSON schema: ' . json_encode($config['responseSchema'], JSON_UNESCAPED_UNICODE) : '.');
    }
    $messages = [['role' => 'system', 'content' => $system]];
    foreach ($contents as $turn) {
        $text = implode('', array_map(fn($p) => (string)($p['text'] ?? ''), $turn['parts'] ?? []));
        $messages[] = ['role' => ($turn['role'] ?? 'user') === 'model' ? 'assistant' : 'user', 'content' => $text];
    }

    $errors = [];
    foreach (GROQ_MODELS as $model) {
        $left = (int)floor($deadline - microtime(true));
        if ($left < 2) { $errors[] = 'network'; break; }
        $body = ['model' => $model, 'messages' => $messages, 'max_completion_tokens' => (int)($config['maxOutputTokens'] ?? 1024)];
        if (str_starts_with($model, 'openai/gpt-oss')) {
            $body['reasoning_effort'] = 'low';
            $body['max_completion_tokens'] += GROQ_REASONING_TOKENS;
        }
        if (isset($config['temperature'])) $body['temperature'] = (float)$config['temperature'];
        if ($json) $body['response_format'] = ['type' => 'json_object'];

        $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . env_value('GROQ_API_KEY')],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $left,
            CURLOPT_TIMEOUT => $left,
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $netError = curl_error($ch);
        curl_close($ch);

        $out = $raw === false ? ['error' => 'network', 'why' => $netError] : groq_parse($status, (string)$raw, $model);
        if (isset($out['text'])) return $out;
        gemini_log('groq/' . $model . ': ' . $out['why']);
        $errors[] = $out['error'];
        if ($out['error'] === 'key' || $out['error'] === 'blocked') break;   // the next model would fail the same way
    }
    return ['errors' => $errors];
}

/** One Groq response: ['text' => ...], or ['error' => ..., 'why' => what to log]. */
function groq_parse(int $status, string $raw, string $model): array
{
    $reply = json_decode($raw, true);
    if ($status !== 200) {
        $why = $status . ' ' . (string)($reply['error']['message'] ?? mb_substr($raw, 0, 300));
        if (in_array($status, [401, 403], true)) return ['error' => 'key', 'why' => $why];
        if ($status === 429) return ['error' => preg_match('/per day|\(RPD\)|\(TPD\)/i', $why) ? 'limit_day' : 'limit', 'why' => $why];
        return ['error' => 'failed', 'why' => $why];
    }
    $choice = $reply['choices'][0] ?? null;
    if (($choice['finish_reason'] ?? '') === 'content_filter') return ['error' => 'blocked', 'why' => 'blocked: content_filter'];
    $text = (string)($choice['message']['content'] ?? '');
    return trim($text) !== '' ? ['text' => $text, 'model' => 'groq/' . $model] : ['error' => 'failed', 'why' => 'empty reply (' . ($choice['finish_reason'] ?? '?') . ')'];
}

function gemini_log(string $error): void
{
    $dir = dirname(__DIR__) . '/logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($dir . '/ai-errors.log', date('Y-m-d H:i:s') . '  ' . str_replace(["\r", "\n"], ' ', mb_substr($error, 0, 600)) . "\n", FILE_APPEND | LOCK_EX);
}
