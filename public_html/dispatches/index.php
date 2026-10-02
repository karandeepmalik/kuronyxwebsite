<?php
require __DIR__ . '/../includes/db.php';

$articles = db()->query(
    "SELECT title, slug, excerpt, published_at FROM dispatches
     WHERE status = 'published' ORDER BY published_at DESC LIMIT 50"
)->fetchAll();

$pageTitle       = 'Dispatches — Field Notes from Kuronyx Sciences';
$pageDescription = 'Occasional dispatches from the formulary — new compounds, regulatory notes, case reports from the field.';
$canonical       = 'https://kuronyx.in/dispatches';
$activeNav       = 'dispatches';
require __DIR__ . '/../includes/layout-header.php';
?>
    <p class="doc-eyebrow">Field notes</p>
    <h1 class="doc-title">Dispatches</h1>
    <p class="doc-meta">Kuronyx Sciences<span class="sep">·</span>Occasional, no marketing</p>

    <p class="lead">
      Occasional dispatches from the formulary — new compounds, regulatory notes, case reports from the field.
      No marketing. Unsubscribe in one click.
    </p>

    <div class="card-panel">
      <h2>Subscribe</h2>
      <form id="subscribeForm" data-captcha>
        <p hidden><label>Don't fill this out: <input name="bot-field" tabindex="-1" autocomplete="off"></label></p>
        <div class="field-row two">
          <label class="field" style="flex:1;">
            <span class="lbl">Email</span>
            <input type="email" id="subscribeEmail" placeholder="your.name@clinic.in" autocomplete="email" spellcheck="false" required>
          </label>
        </div>
        <button type="submit" id="subscribeBtn" class="btn-primary"><span class="label">Subscribe</span></button>
        <p class="form-status" id="subscribeStatus">Awaiting input</p>
      </form>
    </div>

    <section class="doc">
      <h2><span class="n">01</span><span>Latest</span></h2>
      <?php if (!$articles): ?>
        <p>No dispatches published yet — check back soon.</p>
      <?php endif; ?>
      <?php foreach ($articles as $a): ?>
        <div class="note-item" style="padding:1.25rem 0;">
          <div class="note-meta"><?= htmlspecialchars(fmt_time($a['published_at']), ENT_QUOTES) ?></div>
          <h3 style="font-family:var(--serif); font-weight:300; font-size:1.25rem; margin:0.35rem 0 0.5rem;">
            <a href="/dispatches/view.php?slug=<?= urlencode($a['slug']) ?>" style="color:var(--paper); text-decoration:none; border-bottom:1px solid var(--paper-3);"><?= htmlspecialchars($a['title'], ENT_QUOTES) ?></a>
          </h3>
          <?php if ($a['excerpt']): ?>
            <p style="color:var(--paper-2); font-size:0.875rem; margin:0;"><?= htmlspecialchars($a['excerpt'], ENT_QUOTES) ?></p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </section>
<script src="/js/captcha.js"></script>
<script src="/js/subscribe.js"></script>
<?php require __DIR__ . '/../includes/layout-footer.php'; ?>
