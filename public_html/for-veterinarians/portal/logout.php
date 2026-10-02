<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/auth.php';
// POST + CSRF only — see admin/logout.php.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    header('Location: /for-veterinarians/portal/');
    exit;
}
$vet = current_vet();
if ($vet) {
    audit('vet_logout', 'vet_account', $vet['id'], [], 'vet', (int) $vet['id']);
}
unset($_SESSION['vet']);
header('Location: /for-veterinarians/login.php');
exit;
