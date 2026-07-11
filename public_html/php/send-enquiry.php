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

// Get raw JSON payload
$input = json_decode(file_get_contents('php://input'), true);

$name    = trim($input['name'] ?? '');
$clinic  = trim($input['clinic'] ?? '');
$city    = trim($input['city'] ?? '');
$email   = filter_var(trim($input['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$phone   = trim($input['phone'] ?? '');
$message = trim($input['message'] ?? '');

// Server-side validation
if (empty($name) || empty($clinic) || empty($city) || !$email || empty($phone) || empty($message)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please fill in all fields with valid information.']);
    exit;
}

// Call Brevo API
$payload = json_encode([
    'to'         => [['email' => RECEIVER_EMAIL, 'name' => SENDER_NAME]],
    'templateId' => BREVO_ENQUIRY_TEMPLATE_ID,
    'replyTo'    => ['email' => $email, 'name' => $name],
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
    echo json_encode(['success' => false, 'message' => 'Enquiry could not be transmitted.', 'debug' => $response]);
}
