<?php
/**
 * Shared cross-section nav for the admin hub pages.
 * Set before including: $staff (from require_login()/require_role()), $activeAdminNav.
 */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden.');
}

$activeAdminNav = $activeAdminNav ?? '';
$adminNavItems = [
    'gs-requests'      => ['/admin/gs-requests/', 'GS-441524 Requests'],
    'vet-applications' => ['/admin/veterinary-applications/', 'Veterinary Applications'],
    'dispatches'       => ['/admin/dispatches/', 'Dispatches'],
    'audit-log'        => ['/admin/audit-log/', 'Audit Log'],
];
?>
    <nav class="admin-nav" aria-label="Admin">
      <?php foreach ($adminNavItems as $key => [$href, $label]): ?>
        <a href="<?= htmlspecialchars($href, ENT_QUOTES) ?>"<?= $activeAdminNav === $key ? ' class="active"' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES) ?></a>
      <?php endforeach; ?>
      <?php if (($staff['role'] ?? null) === 'admin'): ?>
        <a href="/admin/staff/"<?= $activeAdminNav === 'staff' ? ' class="active"' : '' ?>>Staff</a>
        <a href="/admin/data-erasure/"<?= $activeAdminNav === 'data-erasure' ? ' class="active"' : '' ?>>Data Erasure</a>
      <?php endif; ?>
      <form method="POST" action="/admin/logout.php" class="signout-form"><?= csrf_field() ?><button type="submit" class="signout">Sign Out</button></form>
    </nav>
