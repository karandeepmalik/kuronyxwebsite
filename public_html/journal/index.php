<?php
require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/journal.php';

// Highest issue number first; entries with no number come after the numbered ones, newest first (JOURNAL_ORDER_SQL).
$articles = db()->query(
    "SELECT title, slug, excerpt, issue_number, author, published_at FROM dispatches
     WHERE status = 'published' ORDER BY " . JOURNAL_ORDER_SQL . ' LIMIT 50'
)->fetchAll();

$pageTitle       = 'Journal | Veterinary Clinical Education Series | Kuronyx India';
$pageDescription = 'A clinical education series for veterinarians on feline medicine, compounding standards, and FIP. Written by named veterinarians and pharmacologists.';
$canonical       = 'https://kuronyx.in/journal/';
$activeNav       = 'journal';
require __DIR__ . '/../includes/layout-header.php';
?>
    <p class="doc-eyebrow">Journal</p>
    <h1 class="doc-title">A clinical education series.</h1>

    <p class="lead">
      Written by veterinarians, scientists and pharmacologists, for the people who treat the animal.
    </p>

    <p class="lead">
      Each issue takes one question and stays with it. What a dose form has to account for in a cat. What a molecule
      is, and is not. What to ask of any compounded medicine before you trust it. The science leads. We stay out of
      its way.
    </p>

    <p class="lead">New issues arrive monthly.</p>

    <div class="card-panel">
      <h2>Subscribe</h2>
      <p class="field-hint spaced">No marketing. Unsubscribe in one click.</p>
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
      <h2><span>Issues</span></h2>
      <?php if (!$articles): ?>
        <p>The first issue is on its way.</p>
      <?php endif; ?>
      <?php foreach ($articles as $a):
          $label = journal_issue_label($a['issue_number'] === null ? null : (int) $a['issue_number']);
      ?>
        <div class="note-item" style="padding:1.25rem 0;">
          <div class="note-meta"><?= htmlspecialchars(fmt_time($a['published_at']), ENT_QUOTES) ?></div>
          <h3 style="font-family:var(--serif); font-weight:300; font-size:1.25rem; margin:0.35rem 0 0.5rem;">
            <a href="/journal/view.php?slug=<?= urlencode($a['slug']) ?>" style="color:var(--paper); text-decoration:none; border-bottom:1px solid var(--paper-3);"><?= htmlspecialchars(($label !== '' ? $label . ' · ' : '') . $a['title'], ENT_QUOTES) ?></a>
          </h3>
          <?php if ($a['excerpt']): ?>
            <p style="color:var(--paper-2); font-size:0.875rem; margin:0 0 0.4rem;"><?= htmlspecialchars($a['excerpt'], ENT_QUOTES) ?></p>
          <?php endif; ?>
          <?php if ($a['author']): ?>
            <p class="note-meta" style="margin:0;"><?= htmlspecialchars($a['author'], ENT_QUOTES) ?></p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </section>
<script src="/js/captcha.js"></script>
<script src="/js/post-json.js"></script>
<script src="/js/subscribe.js"></script>
<?php require __DIR__ . '/../includes/layout-footer.php'; ?>
