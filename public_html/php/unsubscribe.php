<?php
require_once 'config.php';

$email = filter_var(trim($_GET['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$done  = false;
$error = false;

if ($email) {
    // Remove contact from list 7 via Brevo API
    $payload = json_encode(['emails' => [$email]]);

    $ch = curl_init('https://api.brevo.com/v3/contacts/lists/7/contacts/remove');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'POST',
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

    $done  = ($httpCode === 201 || $httpCode === 204);
    $error = !$done;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Unsubscribe — Kuronyx Sciences</title>
  <style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body { background:#22384A; font-family:'Helvetica Neue',Helvetica,Arial,sans-serif; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px; }
    .card { max-width:440px; width:100%; background:#1c3040; border:1px solid rgba(88,149,157,0.2); padding:48px 44px; }
    .logo { font-size:10px; letter-spacing:0.22em; text-transform:uppercase; color:#58959D; margin-bottom:36px; }
    .rule { border:none; border-top:1px solid rgba(88,149,157,0.3); margin-bottom:32px; }
    h1 { font-family:Georgia,'Times New Roman',serif; font-size:22px; font-weight:400; color:#F0EDE8; margin-bottom:16px; }
    p { font-size:14px; font-weight:300; color:rgba(240,237,232,0.7); line-height:1.75; }
    .error { color:rgba(240,100,100,0.8); }
    a { color:#58959D; text-decoration:none; font-size:12px; display:inline-block; margin-top:28px; letter-spacing:0.08em; }
  </style>
</head>
<body>
  <div class="card">
    <div class="logo">Kuronyx Sciences Pvt Ltd</div>
    <hr class="rule"/>
    <?php if ($done): ?>
      <h1>You've been unsubscribed.</h1>
      <p>You will no longer receive Field Dispatches emails. We're sorry to see you go.</p>
    <?php elseif ($error): ?>
      <h1>Something went wrong.</h1>
      <p class="error">We couldn't process your request. Please try again or contact us at hello@kuronyx.in.</p>
    <?php else: ?>
      <h1>Invalid request.</h1>
      <p>No email address was provided.</p>
    <?php endif; ?>
    <a href="https://kuronyx.in">← kuronyx.in</a>
  </div>
</body>
</html>
