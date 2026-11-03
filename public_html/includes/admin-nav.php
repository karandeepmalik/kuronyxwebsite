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
// Links go only to pages the person can actually open: every one of the admin-only pages answers a
// pharmacy_staff request with a 403, so showing them the link would just lead to a dead end.
$adminNavItems = [
    'gs-requests'      => ['/admin/gs-requests/', 'GS-441524 Requests'],
    'vet-applications' => ['/admin/veterinary-applications/', 'Veterinary Applications'],
];
if (($staff['role'] ?? null) === 'admin') {
    $adminNavItems += [
        'dispatches'   => ['/admin/dispatches/', 'Journal'],
        'audit-log'    => ['/admin/audit-log/', 'Audit Log'],
        'staff'        => ['/admin/staff/', 'Staff'],
        'data-erasure' => ['/admin/data-erasure/', 'Data Erasure'],
    ];
}
?>
    <nav class="admin-nav" aria-label="Admin">
      <?php foreach ($adminNavItems as $key => [$href, $label]): ?>
        <a href="<?= htmlspecialchars($href, ENT_QUOTES) ?>"<?= $activeAdminNav === $key ? ' class="active"' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES) ?></a>
      <?php endforeach; ?>
      <form method="POST" action="/admin/logout.php" class="signout-form"><?= csrf_field() ?><button type="submit" class="signout">Sign Out</button></form>
    </nav>
