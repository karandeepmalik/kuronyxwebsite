<?php
require_once 'config.php';
require_once __DIR__ . '/../includes/auth.php';

// Two steps — GET only shows a confirmation page; the actual removal needs a POST from that
// page. Email security scanners and link-preview bots fetch every URL in a message with a
// plain GET, so when GET itself unsubscribed the address, people were being unsubscribed
// by software before they ever read the email. The CSRF token (tied to a session cookie
// a bot following the link never presents on a POST) and the per-IP limit also keep this
// from being used to mass-unsubscribe arbitrary addresses by script.
// NOTE: this still can't prove the requester owns the address — that needs a signed token
// in the link, which means changing the unsubscribe link in the Brevo email templates
// (outside this repo); Brevo's own built-in unsubscribe link is the proper long-term fix.
csrf_token();
$email = filter_var(trim($_GET['email'] ?? $_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$done  = false;
$error = false;
$limited = false;

if ($email && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = true;
    } elseif (rate_limited('unsubscribe:' . client_ip(), 10, 60)) {
        $limited = true;
    } else {
        // Remove contact from the newsletter list via Brevo API
        $payload = json_encode(['emails' => [$email]]);

        $ch = curl_init('https://api.brevo.com/v3/contacts/lists/' . (int) BREVO_LIST_ID . '/contacts/remove');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
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
    <?php elseif ($limited): ?>
      <h1>Too many requests.</h1>
      <p class="error">Please try again later, or contact us at hello@kuronyx.in.</p>
    <?php elseif ($error): ?>
      <h1>Something went wrong.</h1>
      <p class="error">We couldn't process your request. Please try again or contact us at hello@kuronyx.in.</p>
    <?php elseif ($email): ?>
      <h1>Unsubscribe?</h1>
      <p>Stop sending Field Dispatches emails to <strong><?= htmlspecialchars($email, ENT_QUOTES) ?></strong>?</p>
      <form method="POST" style="margin-top:24px;">
        <?= csrf_field() ?>
        <input type="hidden" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES) ?>">
        <button type="submit" style="background:#58959D; color:#0c161e; border:0; padding:12px 22px; font-size:12px; letter-spacing:0.12em; text-transform:uppercase; cursor:pointer;">Confirm unsubscribe</button>
      </form>
    <?php else: ?>
      <h1>Invalid request.</h1>
      <p>No email address was provided.</p>
    <?php endif; ?>
    <a href="https://kuronyx.in">← kuronyx.in</a>
  </div>
</body>
</html>
