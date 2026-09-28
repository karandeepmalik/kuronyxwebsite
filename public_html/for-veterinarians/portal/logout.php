<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/auth.php';
gs_session_start();
$vet = current_vet();
if ($vet) {
    audit('vet_logout', 'vet_account', $vet['id'], [], 'public');
}
unset($_SESSION['vet']);
header('Location: /for-veterinarians/login.php');
exit;
