<?php
// An old article address (/dispatches/view.php?slug=...) goes to the same article in the Journal; no slug goes to the list.
$slug = $_GET['slug'] ?? '';
header('Location: ' . ((is_string($slug) && $slug !== '') ? '/journal/view.php?slug=' . urlencode($slug) : '/journal/'), true, 301);
exit;
