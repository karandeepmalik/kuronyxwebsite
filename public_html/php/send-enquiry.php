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

// See send-welcome.php for why this is checked again here rather than only gating the
// Access-Control-Allow-Origin header: a `mode:'no-cors'` + `text/plain` fetch skips the
// preflight entirely and this endpoint would otherwise still process it.
if (!$originAllowed) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Origin not allowed.']);
    exit;
}

if (rate_limited('send_enquiry:' . client_ip(), 5, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many requests. Please try again later.']);
    exit;
}
// Get raw JSON payload
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = [];

// A non-string value (e.g. {"name": []}) would otherwise throw from trim() — treat as empty.
$str = fn(string $key): string => is_string($input[$key] ?? null) ? trim($input[$key]) : '';
$name    = $str('name');
$clinic  = $str('clinic');
$city    = $str('city');
$rawEmail = $str('email');
$phone   = $str('phone');
$message = $str('message');

// Honeypot (the hidden "bot-field" input on the page): people never fill it in. Pretend it
// worked so a script gets no signal to adapt to, and send nothing.
if (!empty($input['bot-field'])) {
    echo json_encode(['success' => true]);
    exit;
}

// Optional Cloudflare Turnstile — a no-op unless keys are configured (see captcha_verify()).
if (!captcha_verify($str('cf-turnstile-response'))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please complete the verification check and try again.']);
    exit;
}

// Server-side validation. Length caps match the other public forms' column sizes; without
// them anyone could push megabytes through to Brevo and the ops inbox.
$email = mb_strlen($rawEmail) <= 190 ? filter_var($rawEmail, FILTER_VALIDATE_EMAIL) : false;
if ($name === '' || $clinic === '' || $city === '' || !$email || $phone === '' || $message === ''
    || !preg_match('/^[0-9+()\-\s.]{5,30}$/', $phone)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please fill in all fields with valid information.']);
    exit;
}
if (mb_strlen($name) > 150 || mb_strlen($clinic) > 190 || mb_strlen($city) > 100 || mb_strlen($message) > 5000) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'One of the fields is too long. Please shorten it and try again.']);
    exit;
}

// Call Brevo API
$payload = json_encode([
    'to'         => [['email' => RECEIVER_EMAIL, 'name' => SENDER_NAME]],
    'templateId' => BREVO_ENQUIRY_TEMPLATE_ID,
    // No replyTo: the address is whatever the (unverified) submitter typed, so a plain "Reply"
    // in the ops mailbox would write to it blindly. It is still in params below — the
    // template shows it, and staff copy it deliberately.
    'params'     => [
        'name'    => $name,
        'clinic'  => $clinic,
        'city'    => $city,
        'email'   => $email,
        'phone'   => $phone,
        'message' => $message
    ]
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
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    error_log('[send-enquiry] Brevo send failed (HTTP ' . $httpCode . '): ' . $response);
    echo json_encode(['success' => false, 'message' => 'Enquiry could not be transmitted.']);
}
