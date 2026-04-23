<?php
/**
 * JB.AI — Master API Proxy
 * Routes via ?service= URL parameter: anthropic | mailchimp | github | config
 */

// ── READ BODY + SERVICE FIRST ──
$raw_body = file_get_contents('php://input');
$body     = $raw_body ? json_decode($raw_body, true) : [];
$service  = trim($_GET['service'] ?? '');
$service_base = explode('/', $service)[0];

// ── MODELS ENDPOINT — handle before anything else ──
// Open WebUI calls this to populate the model dropdown
if (strpos($service, 'models') !== false) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    echo json_encode([
        'object' => 'list',
        'data'   => [
            ['id' => 'claude-sonnet-4-20250514',  'object' => 'model', 'created' => 1700000000, 'owned_by' => 'anthropic'],
            ['id' => 'claude-haiku-4-5-20251001', 'object' => 'model', 'created' => 1700000000, 'owned_by' => 'anthropic'],
        ]
    ]);
    exit();
}

// ── LOAD CONFIG ──
$config_file = __DIR__ . '/config.php';
if (!file_exists($config_file)) {
    header('Content-Type: application/json');
    http_response_code(500);
    die(json_encode(['error' => 'config.php not found']));
}
require_once $config_file;

// ── CORS ──
$allowed_origins = [
    'https://tmjpllc.com',
    'https://www.tmjpllc.com',
    'http://localhost',
    'http://localhost:3000',
    'http://127.0.0.1',
    'http://192.168.0.110:8080',
    'http://192.168.0.110:3000',
    'http://192.168.0.110',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin === '' || in_array($origin, $allowed_origins)) {
    header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
} else {
    header('Content-Type: application/json');
    http_response_code(403);
    die(json_encode(['error' => 'Forbidden origin: ' . $origin]));
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ── RATE LIMITING ──
session_start();
$now = time();
if (!isset($_SESSION['rl'])) $_SESSION['rl'] = ['c' => 0, 't' => $now];
if ($now - $_SESSION['rl']['t'] > 60) $_SESSION['rl'] = ['c' => 0, 't' => $now];
$_SESSION['rl']['c']++;
if ($_SESSION['rl']['c'] > 60) {
    http_response_code(429);
    die(json_encode(['error' => 'Rate limit exceeded']));
}

// ── DEBUG ENDPOINT ──
if ($service_base === 'debug') {
    echo json_encode([
        'service_raw'   => $service,
        'service_base'  => $service_base,
        'method'        => $_SERVER['REQUEST_METHOD'],
        'body_keys'     => array_keys($body ?: []),
        'stream'        => $body['stream'] ?? 'not set',
        'model'         => $body['model'] ?? 'not set',
        'has_messages'  => isset($body['messages']),
        'origin'        => $origin ?: 'none',
    ]);
    exit();
}

// ── CONFIG ENDPOINT ──
if ($service_base === 'config') {
    echo json_encode([
        'mailchimp_dc'      => defined('MAILCHIMP_DC')      ? MAILCHIMP_DC      : '',
        'mailchimp_aud_id'  => defined('MAILCHIMP_AUD_ID')  ? MAILCHIMP_AUD_ID  : '',
        'github_repo'       => defined('GITHUB_REPO')       ? GITHUB_REPO       : '',
        'google_places_key' => defined('GOOGLE_PLACES_KEY') ? GOOGLE_PLACES_KEY : '',
        'site_url'          => defined('SITE_URL')          ? SITE_URL          : '',
    ]);
    exit();
}

// ── ANTHROPIC ──
if ($service_base === 'anthropic') {
    $api_key = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
    if (!$api_key || $api_key === 'sk-ant-YOUR_KEY_HERE') {
        http_response_code(500);
        die(json_encode(['error' => 'Anthropic API key not configured in config.php']));
    }
    if (!isset($body['messages']) || !is_array($body['messages'])) {
        http_response_code(400);
        die(json_encode(['error' => 'messages array required', 'keys' => array_keys($body ?: [])]));
    }

    // Remove OpenAI-only fields Anthropic rejects
    foreach (['service','stream_options','user','n','logprobs','top_logprobs','presence_penalty','frequency_penalty','logit_bias'] as $field) {
        unset($body[$field]);
    }

    // Force safe model + cap tokens
    $allowed = ['claude-sonnet-4-20250514', 'claude-haiku-4-5-20251001'];
    if (!in_array($body['model'] ?? '', $allowed)) $body['model'] = 'claude-sonnet-4-20250514';
    if (!isset($body['max_tokens'])) $body['max_tokens'] = 1000;
        if ($body['max_tokens'] > 4000) $body['max_tokens'] = 4000;

    // Move system role messages to top-level system param (OpenAI → Anthropic format)
    if (!empty($body['messages'])) {
        $system_parts = [];
        $filtered = [];
        foreach ($body['messages'] as $msg) {
            if (($msg['role'] ?? '') === 'system') {
                $system_parts[] = is_array($msg['content'])
                    ? implode(' ', array_column($msg['content'], 'text'))
                    : $msg['content'];
            } else {
                $filtered[] = $msg;
            }
        }
        $body['messages'] = $filtered;
        if (!empty($system_parts)) {
            $body['system'] = implode("\n\n", $system_parts);
        }
    }

    // Force non-streaming — Hostinger buffers SSE
    $body['stream'] = false;

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        http_response_code(502);
        die(json_encode(['error' => 'cURL error: ' . $curl_err]));
    }

    if ($http_code !== 200) {
        http_response_code($http_code);
        echo $response;
        exit();
    }

    // Convert Anthropic response to OpenAI format for Open WebUI
    $data = json_decode($response, true);
    $text = '';
    foreach (($data['content'] ?? []) as $block) {
        if ($block['type'] === 'text') $text .= $block['text'];
    }

    echo json_encode([
        'id'      => 'chatcmpl-' . uniqid(),
        'object'  => 'chat.completion',
        'created' => time(),
        'model'   => $body['model'],
        'choices' => [[
            'index'         => 0,
            'message'       => ['role' => 'assistant', 'content' => $text],
            'finish_reason' => 'stop',
        ]],
        'usage' => $data['usage'] ?? [],
    ]);
    exit();
}

// ── MAILCHIMP ──
if ($service_base === 'mailchimp') {
    $mc_key = defined('MAILCHIMP_API_KEY') ? MAILCHIMP_API_KEY : '';
    $mc_dc  = defined('MAILCHIMP_DC')      ? MAILCHIMP_DC      : 'us22';
    if (!$mc_key || $mc_key === 'YOUR_MAILCHIMP_KEY_HERE') {
        http_response_code(500);
        die(json_encode(['error' => 'Mailchimp API key not configured']));
    }
    if (!isset($body['endpoint'])) {
        http_response_code(400);
        die(json_encode(['error' => 'endpoint required']));
    }
    $endpoint = ltrim($body['endpoint'], '/');
    $method   = strtoupper($body['method'] ?? 'POST');
    $payload  = $body['payload'] ?? null;
    $url      = "https://{$mc_dc}.api.mailchimp.com/3.0/{$endpoint}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_POSTFIELDS     => $payload ? json_encode($payload) : null,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Basic ' . base64_encode('anystring:' . $mc_key)],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);
    if ($curl_err) { http_response_code(502); die(json_encode(['error' => $curl_err])); }
    http_response_code($http_code);
    echo $response;
    exit();
}

// ── GITHUB ──
if ($service_base === 'github') {
    $gh_token = defined('GITHUB_TOKEN') ? GITHUB_TOKEN : '';
    $gh_repo  = defined('GITHUB_REPO')  ? GITHUB_REPO  : '';
    if (!$gh_token || $gh_token === 'ghp_YOUR_GITHUB_TOKEN_HERE') {
        http_response_code(500);
        die(json_encode(['error' => 'GitHub token not configured']));
    }
    if (!isset($body['path']) || !isset($body['content'])) {
        http_response_code(400);
        die(json_encode(['error' => 'path and content required']));
    }
    $path    = ltrim($body['path'], '/');
    $message = $body['message'] ?? 'Update via JB.AI Newsletter Bot';
    $content = base64_encode($body['content']);
    $url     = "https://api.github.com/repos/{$gh_repo}/contents/{$path}";
    $headers = ['Authorization: token ' . $gh_token, 'Content-Type: application/json', 'Accept: application/vnd.github.v3+json', 'User-Agent: JBAI-Newsletter-Bot'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true]);
    $check = curl_exec($ch); $cc = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $pl = ['message' => $message, 'content' => $content];
    if ($cc === 200) { $ex = json_decode($check, true); if (isset($ex['sha'])) $pl['sha'] = $ex['sha']; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => json_encode($pl), CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => true]);
    $response = curl_exec($ch); $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $curl_err = curl_error($ch); curl_close($ch);
    if ($curl_err) { http_response_code(502); die(json_encode(['error' => $curl_err])); }
    http_response_code($http_code);
    echo $response;
    exit();
}

// ── UNKNOWN ──
http_response_code(400);
echo json_encode([
    'error'   => "Unknown service: '{$service}'",
    'valid'   => ['anthropic', 'mailchimp', 'github', 'config'],
    'tip'     => 'Add ?service=anthropic to URL',
]);
