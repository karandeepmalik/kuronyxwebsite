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
        // Capped well under the VARCHAR(200) column — the disambiguating suffix a slug
        // collision appends (-xxxx) has to fit too, and an over-long title used to turn into
        // an over-long slug that threw from the INSERT.
        $slug    = substr(slugify($slug), 0, 180);
        $excerpt = trim($_POST['excerpt'] ?? '');
        $body    = trim($_POST['body'] ?? '');
        $status  = ($_POST['status'] ?? 'draft') === 'published' ? 'published' : 'draft';

        if ($title === '') $errors['title'] = 'Required';
        elseif (mb_strlen($title) > 200) $errors['title'] = 'Must be 200 characters or fewer';
        if ($body === '') $errors['body'] = 'Required';
        elseif (mb_strlen($body) > 60000 || strlen($body) > TEXT_MAX_BYTES) $errors['body'] = 'Too long'; // TEXT column: bytes
        if (mb_strlen($excerpt) > 400) $errors['excerpt'] = 'Must be 400 characters or fewer';

        if (empty($errors)) {
            $wasPublished = $dispatch && $dispatch['status'] === 'published';
            $publishedAt  = $dispatch['published_at'] ?? null;
            // Stamped only the first time it's ever published — unpublishing and then
            // republishing used to reset this, re-sorting an old article to the top of the
            // public list as if it were new.
            if ($status === 'published' && !$wasPublished && $publishedAt === null) {
                $publishedAt = gmdate('Y-m-d H:i:s');
            }
            $expectedLockVersion = (int) ($_POST['expected_lock_version'] ?? -1);
            $conflict = false;

            // Attempts the write directly and only disambiguates the slug on an actual
            // UNIQUE violation, rather than checking for a conflicting slug first and only
            // appending a suffix if one is found. A separate check-then-write lets two
            // staff saving around the same auto-generated slug both pass the check before
            // either commits — the DB's UNIQUE constraint on dispatches.slug is what
            // actually prevents the duplicate; catching it here (bounded retries, since a
            // random 4-hex-char suffix could theoretically collide again) means that
            // guarantee is enforced atomically instead of raced against in PHP first.
            $baseSlug = $slug;
            for ($attempt = 0; ; $attempt++) {
                try {
                    if ($id > 0) {
                        // Optimistic lock — without it, two staff editing the same article
                        // silently overwrite each other's whole body (last save wins).
                        $upd = $pdo->prepare(
                            'UPDATE dispatches SET title=?, slug=?, excerpt=?, body=?, status=?, published_at=?, lock_version=lock_version+1 WHERE id=? AND lock_version=?'
                        );
                        $upd->execute([$title, $slug, $excerpt ?: null, $body, $status, $publishedAt, $id, $expectedLockVersion]);
                        if ($upd->rowCount() === 0) {
                            $conflict = true;
                            $flash = ['type' => 'err', 'text' => 'This dispatch was changed by someone else since you opened it. Copy what you wrote, reload, and re-apply your edits.'];
                        } else {
                            audit('dispatch_updated', 'dispatch', $id, ['status' => $status]);
                            $flash = ['type' => 'ok', 'text' => 'Dispatch saved.'];
                        }
                    } else {
                        $pdo->prepare(
                            'INSERT INTO dispatches (title, slug, excerpt, body, status, author_staff_id, published_at) VALUES (?,?,?,?,?,?,?)'
                        )->execute([$title, $slug, $excerpt ?: null, $body, $status, $staff['id'], $publishedAt]);
                        $id = (int) $pdo->lastInsertId();
                        audit('dispatch_created', 'dispatch', $id, ['status' => $status]);
                        $flash = ['type' => 'ok', 'text' => 'Dispatch created.'];
                    }
                    break;
                } catch (PDOException $e) {
                    if ($e->getCode() !== '23000' || $attempt >= 4) {
                        throw $e;
                    }
                    $slug = $baseSlug . '-' . substr(bin2hex(random_bytes(3)), 0, 4);
                }
            }

            $stmt = $pdo->prepare('SELECT * FROM dispatches WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $dispatch = $stmt->fetch();
            // On a conflict, keep showing what this person typed (not the other editor's
            // version) so their work isn't lost; the hidden lock_version still comes from
            // $dispatch, i.e. the current DB value, so a resubmit after reviewing succeeds.
            $old = $conflict ? ['title' => $title, 'slug' => $slug, 'excerpt' => $excerpt, 'body' => $body, 'status' => $status] : $dispatch;
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
      <input type="hidden" name="expected_lock_version" value="<?= (int) ($dispatch['lock_version'] ?? 0) ?>">
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
