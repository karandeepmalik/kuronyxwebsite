<?php
require __DIR__ . '/../includes/auth.php';
gs_session_start();
$staff = current_staff();
if ($staff) {
    audit('staff_logout', 'staff_user', $staff['id'], []);
}
$_SESSION = [];
session_destroy();
header('Location: /admin/login.php');
exit;
