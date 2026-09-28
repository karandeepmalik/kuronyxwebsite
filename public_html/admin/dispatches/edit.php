<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/auth.php';
$staff = require_login();

$pdo = db();
$id  = (int) ($_GET['id'] ?? 0);
$dispatch = null;
if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM dispatches WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $dispatch = $stmt->fetch();
    if (!$dispatch) {
        http_response_code(404);
        exit('Dispatch not found.');
    }
}

function slugify(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-') ?: 'dispatch';
}

$errors = [];
$flash  = null;
$old    = $dispatch ?: ['title' => '', 'slug' => '', 'excerpt' => '', 'body' => '', 'status' => 'draft'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'err', 'text' => 'Your session expired. Please try again.'];
    } else {
        $action = $_POST['action'] ?? 'save';

        if ($action === 'delete' && $id > 0) {
            $pdo->prepare('DELETE FROM dispatches WHERE id = ?')->execute([$id]);
            audit('dispatch_deleted', 'dispatch', $id, []);
            header('Location: /admin/dispatches/');
            exit;
        }

        $title   = trim($_POST['title'] ?? '');
        $slug    = trim($_POST['slug'] ?? '') ?: slugify($title);
        $slug    = slugify($slug);
        $excerpt = trim($_POST['excerpt'] ?? '');
        $body    = trim($_POST['body'] ?? '');
        $status  = ($_POST['status'] ?? 'draft') === 'published' ? 'published' : 'draft';

        if ($title === '') $errors['title'] = 'Required';
        if ($body === '') $errors['body'] = 'Required';

        if (empty($errors)) {
            $dupe = $pdo->prepare('SELECT id FROM dispatches WHERE slug = ? AND id != ? LIMIT 1');
            $dupe->execute([$slug, $id]);
            if ($dupe->fetch()) {
                $slug .= '-' . substr(bin2hex(random_bytes(3)), 0, 4);
            }

            $wasPublished = $dispatch && $dispatch['status'] === 'published';
            $publishedAt  = $dispatch['published_at'] ?? null;
            if ($status === 'published' && !$wasPublished) {
                $publishedAt = gmdate('Y-m-d H:i:s');
            }

            if ($id > 0) {
                $pdo->prepare(
                    'UPDATE dispatches SET title=?, slug=?, excerpt=?, body=?, status=?, published_at=? WHERE id=?'
                )->execute([$title, $slug, $excerpt ?: null, $body, $status, $publishedAt, $id]);
                audit('dispatch_updated', 'dispatch', $id, ['status' => $status]);
                $flash = ['type' => 'ok', 'text' => 'Dispatch saved.'];
            } else {
                $pdo->prepare(
                    'INSERT INTO dispatches (title, slug, excerpt, body, status, author_staff_id, published_at) VALUES (?,?,?,?,?,?,?)'
                )->execute([$title, $slug, $excerpt ?: null, $body, $status, $staff['id'], $publishedAt]);
                $id = (int) $pdo->lastInsertId();
                audit('dispatch_created', 'dispatch', $id, ['status' => $status]);
                $flash = ['type' => 'ok', 'text' => 'Dispatch created.'];
            }

            $stmt = $pdo->prepare('SELECT * FROM dispatches WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $dispatch = $stmt->fetch();
            $old = $dispatch;
        } else {
            $old = ['title' => $title, 'slug' => $slug, 'excerpt' => $excerpt, 'body' => $body, 'status' => $status];
        }
    }
}

$pageTitle     = ($id > 0 ? 'Edit Dispatch' : 'New Dispatch') . ' — Kuronyx Admin';
$robotsNoindex = true;
$activeNav     = 'dispatches';
$wideWrap      = true;
require __DIR__ . '/../../includes/layout-header.php';
?>
    <p class="doc-eyebrow">Admin · Dispatches</p>
    <h1 class="doc-title"><?= $id > 0 ? 'Edit Dispatch' : 'New Dispatch' ?></h1>

    <?php if ($flash): ?>
      <div class="alert <?= $flash['type'] === 'ok' ? 'alert-ok' : '' ?>"><?= htmlspecialchars($flash['text'], ENT_QUOTES) ?></div>
    <?php endif; ?>

    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <label class="field <?= isset($errors['title']) ? 'has-error' : '' ?>">
        <span class="lbl">Title</span>
        <input type="text" name="title" value="<?= htmlspecialchars($old['title'] ?? '', ENT_QUOTES) ?>" required>
      </label>
      <label class="field">
        <span class="lbl">Slug (optional — auto-generated from title if left blank)</span>
        <input type="text" name="slug" value="<?= htmlspecialchars($old['slug'] ?? '', ENT_QUOTES) ?>" placeholder="e.g. new-oral-formulation-notes">
      </label>
      <label class="field">
        <span class="lbl">Excerpt (optional, shown in the listing)</span>
        <input type="text" name="excerpt" value="<?= htmlspecialchars($old['excerpt'] ?? '', ENT_QUOTES) ?>" maxlength="400">
      </label>
      <label class="field <?= isset($errors['body']) ? 'has-error' : '' ?>">
        <span class="lbl">Body</span>
        <textarea name="body" rows="14"><?= htmlspecialchars($old['body'] ?? '', ENT_QUOTES) ?></textarea>
      </label>
      <label class="field">
        <span class="lbl">Status</span>
        <select name="status">
          <option value="draft" <?= ($old['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Draft</option>
          <option value="published" <?= ($old['status'] ?? '') === 'published' ? 'selected' : '' ?>>Published</option>
        </select>
      </label>
      <div style="display:flex; gap:0.75rem; margin-top:1.5rem;">
        <button type="submit" class="btn-primary">Save</button>
        <a href="/admin/dispatches/" class="btn-secondary">Cancel</a>
      </div>
    </form>

    <?php if ($id > 0): ?>
      <form method="POST" style="margin-top:2.5rem; padding-top:1.5rem; border-top:1px solid var(--rule);" onsubmit="return confirm('Delete this dispatch permanently?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <button type="submit" class="btn-secondary" style="border-color:#ff8787; color:#ff8787;">Delete Dispatch</button>
      </form>
    <?php endif; ?>
<?php require __DIR__ . '/../../includes/layout-footer.php'; ?>
