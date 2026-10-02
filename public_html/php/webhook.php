<?php
require_once 'config.php';
require_once __DIR__ . '/../includes/storage-path.php';

// Set JSON header
header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

// Require the shared secret configured on the webhook URL in Brevo's dashboard
// (Settings → Webhooks → .../php/webhook.php?secret=...). Without this, anyone on
// the internet could POST fake events here — Brevo has no other way to prove a
// request is really from them, and this endpoint's job is to write to the contact
// list, so an unauthenticated version of it is an open write primitive.
if (!defined('BREVO_WEBHOOK_SECRET') || BREVO_WEBHOOK_SECRET === '' || !hash_equals(BREVO_WEBHOOK_SECRET, $_GET['secret'] ?? '')) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Forbidden.']));
}

// Get raw POST payload
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

// Logged off-webroot (alongside the private document store) rather than inside
// public_html/php/ — a log file there was previously directly downloadable by
// anyone, since nothing in that folder restricts direct file access. Every write below
// uses FILE_APPEND | LOCK_EX — without the lock, two webhook deliveries arriving at once
// (Brevo can send events in parallel) could interleave their writes mid-line and corrupt
// one JSON log entry; the lock serializes them so each line is written whole.
$logFile = private_storage_path() . '/webhook.log';
$timestamp = date('Y-m-d H:i:s');

// Prepare base log message
$logData = [
    'timestamp' => $timestamp,
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'raw_payload' => $rawInput,
    'parsed_payload' => $input,
    'status' => 'initiated'
];

if (!$input) {
    http_response_code(400);
    $logData['status'] = 'failed_bad_json';
    file_put_contents($logFile, json_encode($logData) . PHP_EOL, FILE_APPEND | LOCK_EX);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input.']);
    exit;
}

$event = $input['event'] ?? '';
$email = filter_var(is_string($input['email'] ?? null) ? trim($input['email']) : '', FILTER_VALIDATE_EMAIL);
$messageId = $input['message-id'] ?? $input['messageId'] ?? '';

if ($event !== 'delivered') {
    $logData['status'] = 'ignored_non_delivered';
    file_put_contents($logFile, json_encode($logData) . PHP_EOL, FILE_APPEND | LOCK_EX);
    echo json_encode(['success' => true, 'message' => 'Ignored non-delivered event.']);
    exit;
}

// Only the newsletter welcome email may subscribe anyone. "delivered" fires for EVERY
// transactional email sent through this Brevo account - staff case emails to pet owners and
// vets, activation and password-reset mails, enquiry notifications - and none of those
// recipients opted in to the newsletter. Brevo includes the template id of template-based
// sends in the payload, so anything that isn't the welcome template is ignored.
$templateId = $input['template_id'] ?? $input['templateId'] ?? null;
if (!defined('BREVO_TEMPLATE_ID') || !is_numeric($templateId) || (int) $templateId !== (int) BREVO_TEMPLATE_ID) {
    $logData['status'] = 'ignored_not_welcome_template';
    file_put_contents($logFile, json_encode($logData) . PHP_EOL, FILE_APPEND | LOCK_EX);
    echo json_encode(['success' => true, 'message' => 'Ignored: not a newsletter welcome email.']);
    exit;
}

if (!$email) {
    http_response_code(400);
    $logData['status'] = 'failed_invalid_email';
    file_put_contents($logFile, json_encode($logData) . PHP_EOL, FILE_APPEND | LOCK_EX);
    echo json_encode(['success' => false, 'message' => 'Missing or invalid recipient email.']);
    exit;
}

// Call Brevo's Create/Update Contact endpoint
// API: POST /v3/contacts
$payload = json_encode([
    'email'         => $email,
    'listIds'       => [ (int) BREVO_LIST_ID ],
    'updateEnabled' => true
]);

$ch = curl_init('https://api.brevo.com/v3/contacts');
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

$logData['api_request'] = [
    'url' => 'https://api.brevo.com/v3/contacts',
    'payload' => json_decode($payload, true)
];
$logData['api_response'] = [
    'http_code' => $httpCode,
    'response' => json_decode($response, true) ?? $response
];

if ($httpCode === 201 || $httpCode === 204 || $httpCode === 200) {
    $logData['status'] = 'success';
    file_put_contents($logFile, json_encode($logData) . PHP_EOL, FILE_APPEND | LOCK_EX);
    echo json_encode([
        'success' => true,
        'message' => 'Contact successfully added or updated on list.',
        'messageId' => $messageId
    ]);
} else {
    http_response_code(500);
    $logData['status'] = 'failed_api_error';
    file_put_contents($logFile, json_encode($logData) . PHP_EOL, FILE_APPEND | LOCK_EX);
    error_log('[webhook] Brevo contact upsert failed (HTTP ' . $httpCode . '): ' . $response);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to create contact in Brevo.',
    ]);
}
