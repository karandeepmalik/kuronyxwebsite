<?php
require_once 'config.php';

// Set JSON header
header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

// Get raw POST payload
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

// Log path
$logFile = __DIR__ . '/webhook.log';
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
    file_put_contents($logFile, json_encode($logData) . PHP_EOL, FILE_APPEND);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input.']);
    exit;
}

$event = $input['event'] ?? '';
$email = filter_var(trim($input['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$messageId = $input['message-id'] ?? $input['messageId'] ?? '';

if ($event !== 'delivered') {
    $logData['status'] = 'ignored_non_delivered';
    file_put_contents($logFile, json_encode($logData) . PHP_EOL, FILE_APPEND);
    echo json_encode(['success' => true, 'message' => 'Ignored non-delivered event.']);
    exit;
}

if (!$email) {
    http_response_code(400);
    $logData['status'] = 'failed_invalid_email';
    file_put_contents($logFile, json_encode($logData) . PHP_EOL, FILE_APPEND);
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
    file_put_contents($logFile, json_encode($logData) . PHP_EOL, FILE_APPEND);
    echo json_encode([
        'success' => true,
        'message' => 'Contact successfully added or updated on list.',
        'messageId' => $messageId
    ]);
} else {
    http_response_code(500);
    $logData['status'] = 'failed_api_error';
    file_put_contents($logFile, json_encode($logData) . PHP_EOL, FILE_APPEND);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to create contact in Brevo.',
        'debug' => $response,
        'httpCode' => $httpCode
    ]);
}
