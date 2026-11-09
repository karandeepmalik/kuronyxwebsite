<?php
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/journal.php';

$slug = trim($_GET['slug'] ?? '');
if ($slug === '') {
    header('Location: /journal/');
    exit;
}

$stmt = db()->prepare("SELECT * FROM dispatches WHERE slug = ? AND status = 'published' LIMIT 1");
$stmt->execute([$slug]);
$article = $stmt->fetch();

if (!$article) {
    http_response_code(404);
    $pageTitle     = 'Not found | Kuronyx';
    $robotsNoindex = true;
    $activeNav     = 'journal';
    require __DIR__ . '/../includes/layout-header.php';
    ?>
        <p class="doc-eyebrow">Journal</p>
        <h1 class="doc-title">Not found</h1>
        <p class="lead">This issue doesn't exist or hasn't been published.</p>
        <p class="end-nav"><a class="back" href="/journal/"><span class="arr">←</span> Back to Journal</a></p>
    <?php
    require __DIR__ . '/../includes/layout-footer.php';
    exit;
}

$issue           = journal_issue_label($article['issue_number'] === null ? null : (int) $article['issue_number']);
$pageTitle       = $article['title'] . ' | Journal | Kuronyx';
$pageDescription = $article['excerpt'] ?: ('A clinical education series for veterinarians: ' . $article['title']);
$canonical       = 'https://kuronyx.in/journal/view.php?slug=' . urlencode($article['slug']);
$activeNav       = 'journal';
require __DIR__ . '/../includes/layout-header.php';
?>
    <p class="doc-eyebrow">Journal<?= $issue !== '' ? ' · ' . htmlspecialchars($issue, ENT_QUOTES) : '' ?></p>
    <h1 class="doc-title"><?= htmlspecialchars($article['title'], ENT_QUOTES) ?></h1>
    <p class="doc-meta"><?= $article['author'] ? htmlspecialchars($article['author'], ENT_QUOTES) . '<span class="sep">·</span>' : '' ?><?= htmlspecialchars(fmt_time($article['published_at']), ENT_QUOTES) ?></p>

    <div class="lead"><?= nl2br(htmlspecialchars($article['body'], ENT_QUOTES)) ?></div>

    <p class="end-nav"><a class="back" href="/journal/"><span class="arr">←</span> Back to Journal</a></p>
<?php require __DIR__ . '/../includes/layout-footer.php'; ?>
