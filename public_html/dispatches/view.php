<?php
require __DIR__ . '/../includes/db.php';

$slug = trim($_GET['slug'] ?? '');
if ($slug === '') {
    header('Location: /dispatches');
    exit;
}

$stmt = db()->prepare("SELECT * FROM dispatches WHERE slug = ? AND status = 'published' LIMIT 1");
$stmt->execute([$slug]);
$article = $stmt->fetch();

if (!$article) {
    http_response_code(404);
    $pageTitle     = 'Not Found — Kuronyx Sciences';
    $robotsNoindex = true;
    $activeNav     = 'dispatches';
    require __DIR__ . '/../includes/layout-header.php';
    ?>
        <p class="doc-eyebrow">Dispatches</p>
        <h1 class="doc-title">Not found</h1>
        <p class="lead">This dispatch doesn't exist or hasn't been published. <a href="/dispatches">Back to Dispatches</a>.</p>
    <?php
    require __DIR__ . '/../includes/layout-footer.php';
    exit;
}

$pageTitle       = $article['title'] . ' — Kuronyx Dispatches';
$pageDescription = $article['excerpt'] ?: ('Field notes from Kuronyx Sciences: ' . $article['title']);
$canonical       = 'https://kuronyx.in/dispatches/view.php?slug=' . urlencode($article['slug']);
$activeNav       = 'dispatches';
require __DIR__ . '/../includes/layout-header.php';
?>
    <p class="doc-eyebrow">Dispatches</p>
    <h1 class="doc-title"><?= htmlspecialchars($article['title'], ENT_QUOTES) ?></h1>
    <p class="doc-meta">Kuronyx Sciences<span class="sep">·</span><?= htmlspecialchars(fmt_time($article['published_at']), ENT_QUOTES) ?></p>

    <div class="lead"><?= nl2br(htmlspecialchars($article['body'], ENT_QUOTES)) ?></div>

    <p style="margin-top:2.5rem;"><a href="/dispatches" style="color:var(--paper); border-bottom:1px solid var(--paper-3);">← Back to Dispatches</a></p>
<?php require __DIR__ . '/../includes/layout-footer.php'; ?>
