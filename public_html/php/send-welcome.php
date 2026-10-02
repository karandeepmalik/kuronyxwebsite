<?php
require_once 'config.php';
require_once __DIR__ . '/../includes/auth.php';

// Dynamic CORS validation
$origin = allowed_site_origin();
$originAllowed = $origin !== null;
if ($originAllowed) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json');

// Handle preflight OPTIONS request (before the POST-only check, or it would always get a 405)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

// The Origin allow-list above previously only controlled whether the browser could
// *read* the response — it never stopped the request from actually being processed.
// A page on any other site can still fire a same-effect request the browser won't
// preflight at all (a `fetch` with `mode:'no-cors'` and a `text/plain` Content-Type),
// which this endpoint would happily execute since it only reads the raw body. Actually
// rejecting requests whose Origin isn't recognized closes that off.
if (!$originAllowed) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Origin not allowed.']);
    exit;
}

// This sends a real transactional email to whatever address is supplied, so it's also
// a target for abuse (email-bombing an arbitrary inbox) independent of who's allowed
// to read the response — throttle by IP.
if (rate_limited('send_welcome:' . client_ip(), 5, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many requests. Please try again later.']);
    exit;
}
// Get and validate email
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = [];
$rawEmail = is_string($input['email'] ?? null) ? trim($input['email']) : '';
$email = mb_strlen($rawEmail) <= 190 ? filter_var($rawEmail, FILTER_VALIDATE_EMAIL) : false;

// Honeypot (the hidden "bot-field" input on the page): people never fill it in. Pretend it
// worked so a script gets no signal to adapt to, and send nothing.
if (!empty($input['bot-field'])) {
    echo json_encode(['success' => true]);
    exit;
}

// Optional Cloudflare Turnstile — a no-op unless keys are configured (see captcha_verify()).
if (!captcha_verify(is_string($input['cf-turnstile-response'] ?? null) ? $input['cf-turnstile-response'] : '')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please complete the verification check and try again.']);
    exit;
}

if (!$email) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
    exit;
}

// 1. Call Brevo SMTP API to send the welcome email
$payload = json_encode([
    'to'         => [['email' => $email]],
    'templateId' => BREVO_TEMPLATE_ID,
    'sender'     => ['email' => SENDER_EMAIL, 'name' => SENDER_NAME],
]);

$ch = curl_init('https://api.brevo.com/v3/smtp/email');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'accept: application/json',
        'api-key: ' . BREVO_API_KEY,
        'content-type: application/json',
    ],
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 201) {
    // 2. Add/update contact in Brevo list ONLY if welcome email was successfully sent
    $contactPayload = json_encode([
        'email'         => $email,
        'listIds'       => [ (int)BREVO_LIST_ID ],
        'updateEnabled' => true,
    ]);

    $chContact = curl_init('https://api.brevo.com/v3/contacts');
    curl_setopt_array($chContact, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $contactPayload,
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'api-key: ' . BREVO_API_KEY,
            'content-type: application/json',
        ],
    ]);

    $contactResponse = curl_exec($chContact);
    $contactHttpCode = curl_getinfo($chContact, CURLINFO_HTTP_CODE);
    curl_close($chContact);

    $resData = json_decode($response, true);
    $messageId = $resData['messageId'] ?? '';
    echo json_encode([
        'success' => true,
        'messageId' => $messageId
    ]);
} else {
    http_response_code(500);
    error_log('[send-welcome] Brevo send failed (HTTP ' . $httpCode . '): ' . $response);
    echo json_encode(['success' => false, 'message' => 'Email could not be sent.']);
}
