<?php
require_once 'config.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

// Dynamic CORS validation
$allowedOrigins = [
    'https://kuronyx.in',
    'https://www.kuronyx.in'
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins) || preg_match('/^https?:\/\/localhost(:\d+)?$/', $origin) || preg_match('/^https?:\/\/127\.0\.0\.1(:\d+)?$/', $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Get and validate email
$input = json_decode(file_get_contents('php://input'), true);
$email = filter_var(trim($input['email'] ?? ''), FILTER_VALIDATE_EMAIL);

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
    echo json_encode(['success' => false, 'message' => 'Email could not be sent.', 'debug' => $response]);
}
