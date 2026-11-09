<?php
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/slug.php';
require_once __DIR__ . '/../../includes/form-errors.php';
require_once __DIR__ . '/../../includes/journal.php';
// Admin-only, like the audit log, data erasure and staff pages: publishing and deleting public site content
// is an admin decision (the only roles are admin and pharmacy_staff - there is no separate editor role).
$staff = require_role(['admin']);

$pdo = db();
$id  = (int) ($_GET['id'] ?? 0);
$dispatch = null;
if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM dispatches WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $dispatch = $stmt->fetch();
    if (!$dispatch) {
        http_response_code(404);
        exit('Issue not found.');
    }
}

$errors = [];
$flash  = null;
// The message left by the redirect that follows a successful save (below), shown once.
if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['dispatch_flash'])) {
    $flash = $_SESSION['dispatch_flash'];
    unset($_SESSION['dispatch_flash']);
}
$old    = $dispatch ?: ['title' => '', 'slug' => '', 'excerpt' => '', 'issue_number' => '', 'author' => '', 'body' => '', 'status' => 'draft'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'err', 'text' => 'Your session expired. Please try again.'];
    } else {
        $action = $_POST['action'] ?? 'save';

        if ($action === 'delete' && $id > 0) {
            // Once the row is gone "dispatch #12 deleted" says nothing about what #12 was, so the audit
            // entry carries the title, slug and status ($dispatch was loaded above, before the delete).
            // The audit entry is written only if this request really removed the row (rowCount), in the
            // same transaction: two deletes that both loaded the row before either ran (two sessions, two
            // devices) would otherwise each log a deletion of something already gone.
            $pdo->beginTransaction();
            try {
                $deleted = $pdo->prepare('DELETE FROM dispatches WHERE id = ?');
                $deleted->execute([$id]);
                $removed = $deleted->rowCount() === 1;
                if ($removed) {
                    audit('dispatch_deleted', 'dispatch', $id, [
                        'title' => $dispatch['title'], 'slug' => $dispatch['slug'], 'status' => $dispatch['status'],
                    ]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            $_SESSION['dispatch_flash'] = $removed
                ? ['type' => 'ok', 'text' => 'Issue deleted.']
                : ['type' => 'ok', 'text' => 'That issue had already been deleted, so nothing was changed.'];
            header('Location: /admin/dispatches/');
            exit;
        }

        $title   = trim($_POST['title'] ?? '');
        $slug    = trim($_POST['slug'] ?? '') ?: slugify($title);
        // Capped well under the VARCHAR(200) column — the disambiguating suffix a slug
        // collision appends (-xxxx) has to fit too, and an over-long title used to turn into
        // an over-long slug that threw from the INSERT.
        $slug    = substr(slugify($slug), 0, 180);

        // Once an issue has been published, its slug IS its public address (/journal/view.php?slug=...)
        // and the public page finds the article by the slug alone - so changing it silently breaks every
        // link anyone already has (a bookmark, a LinkedIn post, Google). "Ever published" means
        // published_at is set (unpublishing keeps it; the address was public all the same). The slug
        // therefore stays as it is unless the person deliberately ticks "Change the slug anyway" - and a
        // crafted request that sends a different slug without it is ignored here, not trusted.
        $slugLocked = $dispatch !== null && ($dispatch['published_at'] ?? null) !== null;
        $unlockSlug = ($_POST['unlock_slug'] ?? '') === '1';
        $slugNotes  = []; // sentences added to the "saved" message about what happened to the slug
        if ($slugLocked && !$unlockSlug) {
            if (trim($_POST['slug'] ?? '') !== $dispatch['slug']) {
                $slugNotes[] = 'The slug was left as "' . $dispatch['slug'] . '" because this issue has been published and its address is public. Tick "Change the slug anyway" if you really want to change it.';
            }
            $slug = $dispatch['slug'];
        }
        $excerpt = trim($_POST['excerpt'] ?? '');
        $author  = trim($_POST['author'] ?? '');
        $issueRaw = trim($_POST['issue_number'] ?? '');
        $issue   = null; // optional; the database also refuses a number that is already taken
        $body    = trim($_POST['body'] ?? '');
        $status  = ($_POST['status'] ?? 'draft') === 'published' ? 'published' : 'draft';

        // Each message is printed under its field (see the form below) and says what the limit is, so
        // the person knows what to change. The body is limited twice: in characters, and in bytes,
        // because the TEXT column holds 65,535 *bytes* and accented / non-English text takes more than
        // one byte per character - so a long article can hit the byte limit well before the character one.
        if ($title === '') $errors['title'] = 'Enter a title.';
        elseif (mb_strlen($title) > 200) $errors['title'] = 'The title is too long: 200 characters at most, and yours is ' . number_format(mb_strlen($title)) . '.';
        if ($body === '') $errors['body'] = 'Enter the body text.';
        elseif (mb_strlen($body) > 60000) $errors['body'] = 'The body is too long: 60,000 characters at most, and yours is ' . number_format(mb_strlen($body)) . '.';
        elseif (strlen($body) > TEXT_MAX_BYTES) $errors['body'] = 'The body is too long to store: the limit is ' . number_format(TEXT_MAX_BYTES) . ' bytes (about ' . round(TEXT_MAX_BYTES / 1024) . ' KB) and yours is ' . number_format(strlen($body)) . '. Accented and non-English text takes more than one byte per character, so shorten it or split it into two issues.';
        if ($issueRaw !== '') {
            if (ctype_digit($issueRaw) && (int) $issueRaw >= 1 && (int) $issueRaw <= JOURNAL_ISSUE_MAX) {
                $issue = (int) $issueRaw;
            } else {
                $errors['issue_number'] = 'Enter a whole number from 1 to ' . JOURNAL_ISSUE_MAX . ', or leave it empty.';
            }
        }
        if (mb_strlen($author) > 255) $errors['author'] = 'The author line is too long: 255 characters at most, and yours is ' . number_format(mb_strlen($author)) . '.';
        if (mb_strlen($excerpt) > 400) $errors['excerpt'] = 'The excerpt is too long: 400 characters at most, and yours is ' . number_format(mb_strlen($excerpt)) . '.';

        // Creating is a bare INSERT, so a double-click on Save (two POSTs to this same id-less URL) would
        // create two dispatches. Updates don't need this: their lock_version already makes a repeat a
        // conflict instead of a duplicate. Each render of the new-dispatch form carries a single-use
        // token; a repeat finds it spent. Consumed before validation (as on the intake forms): a form
        // re-rendered after an error gets a fresh token.
        $tokenOk = $id > 0 || consume_one_time_token('dispatch_create');

        if (!$tokenOk) {
            $flash = ['type' => 'err', 'text' => 'This form was already submitted (a double-click or a refresh), so nothing more was created. If you don\'t see the issue you wrote, check the Journal list.'];
            $old = ['title' => $title, 'slug' => $slug, 'excerpt' => $excerpt, 'issue_number' => $issueRaw, 'author' => $author, 'body' => $body, 'status' => $status];
        } elseif (empty($errors)) {
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
            $issueTaken = false;

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
                            'UPDATE dispatches SET title=?, slug=?, excerpt=?, issue_number=?, author=?, body=?, status=?, published_at=?, lock_version=lock_version+1 WHERE id=? AND lock_version=?'
                        );
                        $upd->execute([$title, $slug, $excerpt ?: null, $issue, $author ?: null, $body, $status, $publishedAt, $id, $expectedLockVersion]);
                        if ($upd->rowCount() === 0) {
                            $conflict = true;
                            $flash = ['type' => 'err', 'text' => 'This issue was changed by someone else since you opened it. Copy what you wrote, reload, and re-apply your edits.'];
                        } else {
                            audit('dispatch_updated', 'dispatch', $id, ['title' => $title, 'slug' => $slug, 'status' => $status, 'issue_number' => $issue]);
                            $flash = ['type' => 'ok', 'text' => 'Issue saved.'];
                        }
                    } else {
                        $pdo->prepare(
                            'INSERT INTO dispatches (title, slug, excerpt, issue_number, author, body, status, author_staff_id, published_at) VALUES (?,?,?,?,?,?,?,?,?)'
                        )->execute([$title, $slug, $excerpt ?: null, $issue, $author ?: null, $body, $status, $staff['id'], $publishedAt]);
                        $id = (int) $pdo->lastInsertId();
                        audit('dispatch_created', 'dispatch', $id, ['title' => $title, 'slug' => $slug, 'status' => $status, 'issue_number' => $issue]);
                        $flash = ['type' => 'ok', 'text' => 'Issue created.'];
                    }
                    break;
                } catch (PDOException $e) {
                    // Only a duplicate SLUG is worth retrying with a suffix. SQLSTATE 23000 covers every
                    // integrity error (a foreign key, a NOT NULL), which a different slug can't fix - those
                    // used to be retried four more times with a mangled slug before being rethrown anyway.
                    // The issue number is UNIQUE: a number someone else already used is a message under the field, not a retry.
                    if (is_unique_violation($e, 'dispatches', 'issue_number')) {
                        $issueTaken = true;
                        break;
                    }
                    if (!is_unique_violation($e, 'dispatches', 'slug') || $attempt >= 4) {
                        throw $e;
                    }
                    $slug = $baseSlug . '-' . bin2hex(random_bytes(2));
                }
            }

            if ($issueTaken) {
                $errors['issue_number'] = journal_issue_label($issue) . ' already exists. Each issue number can be used once; pick another or leave it empty.';
                $flash = ['type' => 'err', 'text' => 'Please fix the problem below. Nothing was saved.'];
                $old = ['title' => $title, 'slug' => $slug, 'excerpt' => $excerpt, 'issue_number' => $issueRaw, 'author' => $author, 'body' => $body, 'status' => $status];
            } else {
            // Say what happened to the slug instead of leaving "Issue saved." to imply it went as asked.
            // A taken slug gets a random -xxxx suffix (see the loop above); $baseSlug is what was asked for.
            if (!$conflict) {
                if ($slug !== $baseSlug) {
                    $slugNotes[] = 'The slug "' . $baseSlug . '" was already taken, so it was saved as "' . $slug . '".';
                }
                if ($slugLocked && $unlockSlug && $dispatch['slug'] !== $slug) {
                    $slugNotes[] = 'The old address (slug "' . $dispatch['slug'] . '") no longer works.';
                }
                if ($slugNotes) {
                    $flash['text'] .= ' ' . implode(' ', $slugNotes);
                }
            }

            // Post/Redirect/Get. After a successful create the page used to re-render as "Edit Dispatch"
            // while the browser was still on the id-less URL with a form that posts back to it, so the
            // next Save (or a refresh) created a second dispatch and Delete fell through to an empty
            // save. Redirecting to ?id=N makes the URL match the page, and a refresh just reloads it.
            // Updates redirect too: re-sending an update posts the old lock_version, which would only
            // show a false "changed by someone else". A version conflict is NOT redirected - that page
            // has to keep showing what this person typed.
            if (!$conflict && session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['dispatch_flash'] = $flash;
                header('Location: /admin/dispatches/edit.php?id=' . $id);
                exit;
            }

            $stmt = $pdo->prepare('SELECT * FROM dispatches WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $dispatch = $stmt->fetch();
            // On a conflict, keep showing what this person typed (not the other editor's
            // version) so their work isn't lost; the hidden lock_version still comes from
            // $dispatch, i.e. the current DB value, so a resubmit after reviewing succeeds.
            $old = $conflict ? ['title' => $title, 'slug' => $slug, 'excerpt' => $excerpt, 'issue_number' => $issueRaw, 'author' => $author, 'body' => $body, 'status' => $status] : $dispatch;
            }
        } else {
            // Nothing was saved: say so up top (the page used to just reload with a red field, or - for the
            // excerpt - with nothing visibly wrong at all), and keep what was typed.
            $flash = ['type' => 'err', 'text' => 'Please fix the ' . (count($errors) === 1 ? 'problem' : 'problems') . ' below. Nothing was saved.'];
            $old = ['title' => $title, 'slug' => $slug, 'excerpt' => $excerpt, 'issue_number' => $issueRaw, 'author' => $author, 'body' => $body, 'status' => $status];
        }
    }
}

$formAction    = '/admin/dispatches/edit.php' . ($id > 0 ? '?id=' . $id : '');
$slugLocked    = $dispatch !== null && ($dispatch['published_at'] ?? null) !== null; // see the POST handler above
$pageTitle     = ($id > 0 ? 'Edit issue' : 'New issue') . ' | Kuronyx admin';
$robotsNoindex = true;
$activeNav     = 'dispatches';
$wideWrap      = true;
require __DIR__ . '/../../includes/layout-header.php';
?>
    <p class="doc-eyebrow">Admin · Journal</p>
    <h1 class="doc-title"><?= $id > 0 ? 'Edit issue' : 'New issue' ?></h1>

    <?php if ($flash): ?>
      <div class="alert <?= $flash['type'] === 'ok' ? 'alert-ok' : '' ?>"><?= htmlspecialchars($flash['text'], ENT_QUOTES) ?></div>
    <?php endif; ?>

    <?php // Both forms name their own URL (with ?id= once there is one) so they can never post to a different record's address. ?>
    <form method="POST" action="<?= $formAction ?>">
      <?= csrf_field() ?>
      <?= $id === 0 ? one_time_field('dispatch_create') : '' ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="expected_lock_version" value="<?= (int) ($dispatch['lock_version'] ?? 0) ?>">
      <label class="<?= field_class($errors, 'title') ?>">
        <span class="lbl">Title</span>
        <input type="text" name="title" value="<?= htmlspecialchars($old['title'] ?? '', ENT_QUOTES) ?>" required<?= field_error_attrs($errors, 'title') ?>>
        <?= field_error_html($errors, 'title') ?>
      </label>
      <div class="field-row two">
        <label class="<?= field_class($errors, 'issue_number') ?>">
          <span class="lbl">Issue number (optional, each number once)</span>
          <input type="number" name="issue_number" min="1" max="<?= JOURNAL_ISSUE_MAX ?>" value="<?= htmlspecialchars((string) ($old['issue_number'] ?? ''), ENT_QUOTES) ?>"<?= field_error_attrs($errors, 'issue_number') ?>>
          <?= field_error_html($errors, 'issue_number') ?>
        </label>
        <label class="<?= field_class($errors, 'author') ?>">
          <span class="lbl">Author line (optional, shown under the title)</span>
          <input type="text" name="author" value="<?= htmlspecialchars((string) ($old['author'] ?? ''), ENT_QUOTES) ?>" maxlength="255" placeholder="e.g. Dr. Monal Jain, K. M. Kundnani College of Pharmacy, Mumbai"<?= field_error_attrs($errors, 'author') ?>>
          <?= field_error_html($errors, 'author') ?>
        </label>
      </div>
      <label class="field">
        <span class="lbl">Slug (optional - auto-generated from title if left blank)</span>
        <input type="text" name="slug" id="slug-input" value="<?= htmlspecialchars($old['slug'] ?? '', ENT_QUOTES) ?>" placeholder="e.g. new-oral-formulation-notes"<?= $slugLocked ? ' readonly aria-describedby="slug-lock-note"' : '' ?>>
        <?php if ($slugLocked): ?>
          <span class="field-hint" id="slug-lock-note">Locked: this issue has been published, so its public address is <code>/journal/view.php?slug=<?= htmlspecialchars($dispatch['slug'], ENT_QUOTES) ?></code>. Changing the slug would break every link people already have to it.</span>
        <?php endif; ?>
      </label>
      <?php if ($slugLocked): ?>
        <div class="checkbox-field">
          <input type="checkbox" name="unlock_slug" value="1" id="unlock-slug">
          <label for="unlock-slug">Change the slug anyway (the old address will stop working)</label>
        </div>
      <?php endif; ?>
      <label class="<?= field_class($errors, 'excerpt') ?>">
        <span class="lbl">Summary (optional, shown in the Journal list)</span>
        <input type="text" name="excerpt" value="<?= htmlspecialchars($old['excerpt'] ?? '', ENT_QUOTES) ?>" maxlength="400"<?= field_error_attrs($errors, 'excerpt') ?>>
        <?= field_error_html($errors, 'excerpt') ?>
      </label>
      <label class="<?= field_class($errors, 'body') ?>">
        <span class="lbl">Body</span>
        <textarea name="body" rows="14"<?= field_error_attrs($errors, 'body') ?>><?= htmlspecialchars($old['body'] ?? '', ENT_QUOTES) ?></textarea>
        <?= field_error_html($errors, 'body') ?>
      </label>
      <label class="field">
        <span class="lbl">Status</span>
        <select name="status">
          <option value="draft" <?= ($old['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Draft</option>
          <option value="published" <?= ($old['status'] ?? '') === 'published' ? 'selected' : '' ?>>Published</option>
        </select>
      </label>
      <div class="form-actions">
        <button type="submit" class="btn-primary">Save</button>
        <a href="/admin/dispatches/" class="btn-secondary">Cancel</a>
      </div>
    </form>

    <?php if ($id > 0): ?>
      <form method="POST" action="<?= $formAction ?>" class="danger-zone" onsubmit="return confirm('Delete this issue permanently?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <button type="submit" class="btn-secondary btn-danger">Delete issue</button>
      </form>
    <?php endif; ?>
    <?php if ($slugLocked): ?>
    <script>
    // Ticking "Change the slug anyway" makes the locked slug box editable; the server only honours a
    // changed slug when that box was ticked, so this is convenience, not the guard.
    (function () {
      var box = document.getElementById('unlock-slug'), input = document.getElementById('slug-input');
      if (!box || !input) return;
      box.addEventListener('change', function () {
        input.readOnly = !box.checked;
        if (box.checked) input.focus();
      });
    })();
    </script>
    <?php endif; ?>
<?php require __DIR__ . '/../../includes/layout-footer.php'; ?>
