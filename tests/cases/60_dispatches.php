<?php
return function (TestEnv $env): void {
    // Creates a dispatch the way the form does: GET the blank form (it carries the CSRF token and a
    // single-use token), POST it, and follow the redirect to the new dispatch's edit page. Returns
    // that page's response; the POST response itself is in ['post'].
    $create = function (array $fields) use ($env): array {
        $jar = $env->cookieJarStaff;
        $get = http_request('GET', $env->baseUrl . '/admin/dispatches/edit.php', ['cookie_jar' => $jar]);
        $post = http_request('POST', $env->baseUrl . '/admin/dispatches/edit.php', ['cookie_jar' => $jar, 'body' => array_merge(
            ['csrf_token' => extract_csrf($get['body']), 'ott' => extract_ott($get['body']), 'action' => 'save', 'slug' => '', 'excerpt' => ''],
            $fields
        )]);
        $followed = $post['status'] === 302 ? http_request('GET', $env->baseUrl . $post['location'], ['cookie_jar' => $jar]) : $post;
        return $followed + ['post' => $post];
    };

    run_test('public dispatches list is empty before anything is published', function () use ($env) {
        $r = http_request('GET', $env->baseUrl . '/journal/index.php', ['cookie_jar' => $env->tmpDir . '/cookies-disp-anon.txt']);
        assert_equal(200, $r['status']);
        assert_contains('The first issue is on its way', $r['body']);
    });

    run_test('admin can create and publish a dispatch', function () use ($env, $create) {
        $post = $create([
            'title' => 'A New Oral Formulation', 'excerpt' => 'Notes from the bench.',
            'body' => 'Full text of the dispatch.', 'status' => 'published',
        ]);
        assert_contains('Issue created', $post['body']);

        $row = $env->pdo()->query("SELECT * FROM dispatches WHERE title = 'A New Oral Formulation'")->fetch();
        assert_true($row !== false);
        assert_equal('published', $row['status']);
        assert_equal('a-new-oral-formulation', $row['slug']);
        assert_true($row['published_at'] !== null);
        $env->shared['dispatchSlug'] = $row['slug'];
    });

    run_test('the published dispatch appears on the public list and its own page', function () use ($env) {
        $list = http_request('GET', $env->baseUrl . '/journal/index.php', ['cookie_jar' => $env->tmpDir . '/cookies-disp-anon2.txt']);
        assert_contains('A New Oral Formulation', $list['body']);

        $slug = $env->shared['dispatchSlug'];
        $view = http_request('GET', $env->baseUrl . "/journal/view.php?slug={$slug}", ['cookie_jar' => $env->tmpDir . '/cookies-disp-anon3.txt']);
        assert_equal(200, $view['status']);
        assert_contains('Full text of the dispatch', $view['body']);
    });

    run_test('an unknown slug 404s', function () use ($env) {
        $r = http_request('GET', $env->baseUrl . '/journal/view.php?slug=does-not-exist', ['cookie_jar' => $env->tmpDir . '/cookies-disp-404.txt']);
        assert_equal(404, $r['status']);
    });

    run_test('publishing a second dispatch with the same auto-generated slug gets a disambiguated one, not a raw error', function () use ($env, $create) {
        // Regression test for the slug-collision race: the code used to check for a
        // conflicting slug first, then insert if none was found, as two separate
        // statements — now it inserts directly and only disambiguates on an actual UNIQUE
        // violation from the database. Submitting the exact same title again (so it
        // auto-generates the exact same base slug) exercises that catch-and-retry path.
        $post = $create([
            'title' => 'A New Oral Formulation', 'excerpt' => 'A second, unrelated post.',
            'body' => 'Different body text entirely.', 'status' => 'published',
        ]);
        assert_contains('Issue created', $post['body']);

        $rows = $env->pdo()->query("SELECT slug FROM dispatches WHERE title = 'A New Oral Formulation'")->fetchAll(PDO::FETCH_COLUMN);
        assert_equal(2, count($rows), 'both dispatches with this title should exist');
        assert_equal(2, count(array_unique($rows)), 'the two rows must not have ended up with the same slug — found: ' . implode(', ', $rows));
        assert_true(in_array('a-new-oral-formulation', $rows, true), 'the first dispatch should keep its original slug');
        $disambiguated = array_values(array_diff($rows, ['a-new-oral-formulation']))[0];
        assert_true(str_starts_with($disambiguated, 'a-new-oral-formulation-'), 'the second should be the base slug plus a disambiguating suffix');
    });

    run_test('a title with a special character is not double-escaped in the <title> tag', function () use ($env, $create) {
        $post = $create([
            'title' => 'Dosing & Storage Notes',
            'body' => 'Body text.', 'status' => 'published',
        ]);
        assert_contains('Issue created', $post['body']);
        $slug = (string) $env->scalar("SELECT slug FROM dispatches WHERE title = 'Dosing & Storage Notes'");

        $view = http_request('GET', $env->baseUrl . "/journal/view.php?slug={$slug}", ['cookie_jar' => $env->tmpDir . '/cookies-disp-title.txt']);
        assert_contains('<title>Dosing &amp; Storage Notes', $view['body']);
        assert_true(strpos($view['body'], '&amp;amp;') === false, 'the title should be escaped exactly once, not twice');
    });

    // ---- Creating a dispatch must not leave the page in a state where the next click duplicates it ----
    // Before: after the INSERT the page re-rendered as "Edit issue" but the browser stayed on the
    // id-less URL and the form had no action, so Save (or a refresh) posted there with id 0 and INSERTed
    // again, and Delete fell through to an empty save. Now: redirect to ?id=N, explicit form actions,
    // and a single-use token so a double-click on the blank form can't create two.

    run_test('creating a dispatch redirects to its edit page, shows the message once, and refreshing does not repeat it', function () use ($env, $create) {
        $r = $create(['title' => 'PRG Create Test', 'body' => 'Body one.', 'status' => 'draft']);
        $id = (int) $env->scalar("SELECT id FROM dispatches WHERE title = 'PRG Create Test'");
        assert_true($id > 0);

        assert_equal(302, $r['post']['status'], 'the create POST must be answered with a redirect');
        assert_equal("/admin/dispatches/edit.php?id={$id}", $r['post']['location']);
        assert_true(strpos($r['post']['body'], 'Issue created') === false, 'the POST response itself must not carry the page');

        assert_equal(200, $r['status']);
        assert_contains('Issue created.', $r['body']);
        assert_contains('Edit issue', $r['body']);

        // A refresh of that page (a plain GET) shows no message, and creates nothing.
        $again = http_request('GET', $env->baseUrl . "/admin/dispatches/edit.php?id={$id}", ['cookie_jar' => $env->cookieJarStaff]);
        assert_true(strpos($again['body'], 'Issue created') === false, 'the message is shown once');
        assert_equal(1, (int) $env->scalar("SELECT COUNT(*) FROM dispatches WHERE title = 'PRG Create Test'"));

        // The audit entry says what was created, not just "dispatch #N".
        $meta = json_decode((string) $env->scalar("SELECT metadata_json FROM audit_log WHERE action = 'dispatch_created' AND entity_id = ?", [$id]), true);
        assert_equal(['title' => 'PRG Create Test', 'slug' => 'prg-create-test', 'status' => 'draft', 'issue_number' => null], $meta);
    });

    run_test('after creating, Save updates the same dispatch instead of making a second one, and Delete really deletes it', function () use ($env, $create) {
        $jar = $env->cookieJarStaff;
        $page = $create(['title' => 'No Duplicate Test', 'body' => 'First body.', 'status' => 'draft']);
        $id = (int) $env->scalar("SELECT id FROM dispatches WHERE title = 'No Duplicate Test'");
        assert_true($id > 0);

        // Both forms on the post-create page post to this dispatch's own URL, and the edit form has no
        // creation token (only the blank form does).
        assert_equal(2, substr_count($page['body'], 'action="/admin/dispatches/edit.php?id=' . $id . '"'), 'both forms name the ?id= URL');
        assert_true(strpos($page['body'], 'name="ott"') === false, 'an existing dispatch\'s form needs no creation token');

        // Submit that page's Save form exactly as a browser would, to its own action URL.
        $save = http_request('POST', $env->baseUrl . "/admin/dispatches/edit.php?id={$id}", ['cookie_jar' => $jar, 'body' => [
            'csrf_token' => extract_csrf($page['body']), 'action' => 'save', 'title' => 'No Duplicate Test', 'slug' => '', 'excerpt' => '',
            'body' => 'Second body.', 'status' => 'draft', 'expected_lock_version' => $env->scalar('SELECT lock_version FROM dispatches WHERE id = ?', [$id]),
        ]]);
        assert_equal(302, $save['status'], 'a successful update redirects too');
        assert_equal(1, (int) $env->scalar("SELECT COUNT(*) FROM dispatches WHERE title = 'No Duplicate Test'"), 'Save must not create a second dispatch');
        assert_equal('Second body.', $env->scalar('SELECT body FROM dispatches WHERE id = ?', [$id]));
        $updated = json_decode((string) $env->scalar("SELECT metadata_json FROM audit_log WHERE action = 'dispatch_updated' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$id]), true);
        assert_equal(['title' => 'No Duplicate Test', 'slug' => 'no-duplicate-test', 'status' => 'draft', 'issue_number' => null], $updated, 'the update entry names what was saved');

        // And the update's message is shown once on the page it redirects to.
        $shown = http_request('GET', $env->baseUrl . $save['location'], ['cookie_jar' => $jar]);
        assert_contains('Issue saved.', $shown['body']);
        assert_true(strpos(http_request('GET', $env->baseUrl . $save['location'], ['cookie_jar' => $jar])['body'], 'Issue saved.') === false, 'shown once');

        // Delete from that same page works (it used to fall through to an empty save).
        $del = http_request('POST', $env->baseUrl . "/admin/dispatches/edit.php?id={$id}", ['cookie_jar' => $jar, 'body' => [
            'csrf_token' => extract_csrf($shown['body']), 'action' => 'delete',
        ]]);
        assert_equal(302, $del['status']);
        assert_equal('/admin/dispatches/', $del['location']);
        assert_equal(0, (int) $env->scalar('SELECT COUNT(*) FROM dispatches WHERE id = ?', [$id]), 'the dispatch is deleted');

        // The audit entry says WHAT was deleted - once the row is gone "dispatch #N" alone is meaningless.
        $meta = json_decode((string) $env->scalar("SELECT metadata_json FROM audit_log WHERE action = 'dispatch_deleted' AND entity_id = ?", [$id]), true);
        assert_equal(['title' => 'No Duplicate Test', 'slug' => 'no-duplicate-test', 'status' => 'draft'], $meta, 'the deletion entry names the title, slug and status');
        assert_equal(1, (int) $env->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'dispatch_deleted' AND entity_id = ?", [$id]));

        // The list it redirects to confirms the delete once.
        $list = http_request('GET', $env->baseUrl . $del['location'], ['cookie_jar' => $jar]);
        assert_contains('Issue deleted.', $list['body']);
        assert_true(strpos(http_request('GET', $env->baseUrl . $del['location'], ['cookie_jar' => $jar])['body'], 'Issue deleted.') === false, 'shown once');

        // Deleting it again (a double-click, a stale tab) finds nothing and must not log a second deletion.
        $again = http_request('POST', $env->baseUrl . "/admin/dispatches/edit.php?id={$id}", ['cookie_jar' => $jar, 'body' => [
            'csrf_token' => extract_csrf($list['body']), 'action' => 'delete',
        ]]);
        assert_equal(404, $again['status']);
        assert_equal(1, (int) $env->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'dispatch_deleted' AND entity_id = ?", [$id]), 'no second deletion entry');
    });

    run_test('a delete that removes no row (the row vanished or changed under it) is not logged as a deletion', function () use ($env, $create) {
        // Two deletes can both load the row before either runs (two sessions, two devices); the one that
        // loses removes nothing. A SQLite trigger that silently ignores the DELETE reproduces exactly that
        // for one id: the page loads the row fine, the DELETE matches 0 rows.
        if ($env->isMysql) skip_test('SQLite-only: the trigger that makes the DELETE match no row');
        $jar = $env->cookieJarStaff;
        $page = $create(['title' => 'Race Delete Test', 'body' => 'Body.', 'status' => 'draft']);
        $id = (int) $env->scalar("SELECT id FROM dispatches WHERE title = 'Race Delete Test'");
        assert_true($id > 0);

        (function () use ($env, $id) {
            $env->pdo()->exec("CREATE TRIGGER trg_test_ignore_dispatch_delete BEFORE DELETE ON dispatches WHEN OLD.id = {$id} BEGIN SELECT RAISE(IGNORE); END");
        })();
        try {
            $del = http_request('POST', $env->baseUrl . "/admin/dispatches/edit.php?id={$id}", ['cookie_jar' => $jar, 'body' => [
                'csrf_token' => extract_csrf($page['body']), 'action' => 'delete',
            ]]);
            assert_equal(302, $del['status']);
            $list = http_request('GET', $env->baseUrl . $del['location'], ['cookie_jar' => $jar]);
            assert_contains('had already been deleted, so nothing was changed', $list['body'], 'the person is told, not shown a false "deleted"');
            assert_true(strpos($list['body'], 'Issue deleted.') === false);
            assert_equal(0, (int) $env->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'dispatch_deleted' AND entity_id = ?", [$id]), 'nothing was deleted, so nothing may be logged as deleted');
        } finally {
            (function () use ($env, $id) {
                $pdo = $env->pdo();
                $pdo->exec('DROP TRIGGER IF EXISTS trg_test_ignore_dispatch_delete');
                $pdo->prepare('DELETE FROM dispatches WHERE id = ?')->execute([$id]);
            })();
        }
    });

    run_test('the old failure path: posting a save to the id-less URL again does not make another copy when the token is spent', function () use ($env) {
        // The exact thing the bug did: after creating, the browser kept posting to the id-less URL. That
        // POST is a create, so it needs a live single-use token; the one from the blank form is spent.
        $jar = $env->cookieJarStaff;
        $get = http_request('GET', $env->baseUrl . '/admin/dispatches/edit.php', ['cookie_jar' => $jar]);
        $fields = ['csrf_token' => extract_csrf($get['body']), 'ott' => extract_ott($get['body']), 'action' => 'save', 'title' => 'Resubmit Test', 'slug' => '', 'excerpt' => '', 'body' => 'Once only.', 'status' => 'draft'];

        $first = http_request('POST', $env->baseUrl . '/admin/dispatches/edit.php', ['cookie_jar' => $jar, 'body' => $fields]);
        assert_equal(302, $first['status']);
        assert_equal(1, (int) $env->scalar("SELECT COUNT(*) FROM dispatches WHERE title = 'Resubmit Test'"));

        // A double-click's second POST, or a refresh's re-send: the same form again, same token.
        $second = http_request('POST', $env->baseUrl . '/admin/dispatches/edit.php', ['cookie_jar' => $jar, 'body' => $fields]);
        assert_equal(200, $second['status'], 'it is refused in place, not redirected');
        assert_contains('already submitted', $second['body']);
        assert_contains('Once only.', $second['body'], 'what was typed is kept on the page, not lost');
        assert_equal(1, (int) $env->scalar("SELECT COUNT(*) FROM dispatches WHERE title = 'Resubmit Test'"), 'a repeat must not create a second dispatch');

        // A repeat with no token at all is refused as well.
        unset($fields['ott']);
        $none = http_request('POST', $env->baseUrl . '/admin/dispatches/edit.php', ['cookie_jar' => $jar, 'body' => $fields]);
        assert_contains('already submitted', $none['body']);
        assert_equal(1, (int) $env->scalar("SELECT COUNT(*) FROM dispatches WHERE title = 'Resubmit Test'"));
    });

    run_test('a validation error on a new dispatch keeps what was typed and the re-rendered form still works with its fresh token', function () use ($env) {
        $jar = $env->cookieJarStaff;
        $get = http_request('GET', $env->baseUrl . '/admin/dispatches/edit.php', ['cookie_jar' => $jar]);
        $bad = http_request('POST', $env->baseUrl . '/admin/dispatches/edit.php', ['cookie_jar' => $jar, 'body' => [
            'csrf_token' => extract_csrf($get['body']), 'ott' => extract_ott($get['body']), 'action' => 'save',
            'title' => 'Needs A Body', 'slug' => '', 'excerpt' => '', 'body' => '', 'status' => 'draft',
        ]]);
        assert_equal(200, $bad['status'], 'a validation error is shown in place');
        assert_contains('has-error', $bad['body']);
        assert_contains('Needs A Body', $bad['body'], 'the typed title is kept');
        assert_equal(0, (int) $env->scalar("SELECT COUNT(*) FROM dispatches WHERE title = 'Needs A Body'"));

        // The page it re-rendered carries a fresh token, so fixing the mistake and saving works.
        $ok = http_request('POST', $env->baseUrl . '/admin/dispatches/edit.php', ['cookie_jar' => $jar, 'body' => [
            'csrf_token' => extract_csrf($bad['body']), 'ott' => extract_ott($bad['body']), 'action' => 'save',
            'title' => 'Needs A Body', 'slug' => '', 'excerpt' => '', 'body' => 'Now it has one.', 'status' => 'draft',
        ]]);
        assert_equal(302, $ok['status']);
        assert_equal(1, (int) $env->scalar("SELECT COUNT(*) FROM dispatches WHERE title = 'Needs A Body'"));
    });

    run_test('the blank form names its own URL and carries a creation token', function () use ($env) {
        $page = http_request('GET', $env->baseUrl . '/admin/dispatches/edit.php', ['cookie_jar' => $env->cookieJarStaff])['body'];
        assert_contains('action="/admin/dispatches/edit.php"', $page);
        assert_contains('New issue', $page);
        assert_true((bool) preg_match('/name="ott" value="[0-9a-f]{32}"/', $page), 'the blank form carries a single-use token');
    });

    // ---- Publishing and deleting public content is admin-only ----
    // The dispatch pages used require_login() (any signed-in staff member), unlike the audit log, data
    // erasure and staff pages, which use require_role(['admin']). The only roles are admin and
    // pharmacy_staff, so there is no editor role to fall back on.
    run_test('pharmacy_staff cannot list, open, create, edit or delete dispatches, and is not shown a link to them; admins still can', function () use ($env) {
        $id = (int) $env->scalar('SELECT id FROM dispatches WHERE slug = ?', [$env->shared['dispatchSlug']]);
        assert_true($id > 0);
        $titleBefore = $env->scalar('SELECT title FROM dispatches WHERE id = ?', [$id]);
        $countBefore = (int) $env->scalar('SELECT COUNT(*) FROM dispatches');

        // A pharmacy_staff account with a known password (a throwaway PDO that goes out of scope at once:
        // holding one across http_request() can stall the HTTP test server's writes on Windows).
        (function () use ($env) {
            $env->pdo()->prepare("INSERT INTO staff_users (name, email, password_hash, role) VALUES ('Dispatch Staff', 'dispatchstaff@example.test', ?, 'pharmacy_staff')")
                ->execute([password_hash('dispatch-staff-password', PASSWORD_DEFAULT)]);
        })();
        $jar = $env->tmpDir . '/cookies-dispatch-staff.txt';
        $get = http_request('GET', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $jar]);
        $login = http_request('POST', $env->baseUrl . '/admin/login.php', ['cookie_jar' => $jar, 'body' => [
            'csrf_token' => extract_csrf($get['body']), 'email' => 'dispatchstaff@example.test', 'password' => 'dispatch-staff-password',
        ]]);
        assert_equal(302, $login['status'], 'the pharmacy_staff account can sign in');

        // Every dispatch page is a 403 for them.
        assert_equal(403, http_request('GET', $env->baseUrl . '/admin/dispatches/', ['cookie_jar' => $jar])['status'], 'the list');
        assert_equal(403, http_request('GET', $env->baseUrl . '/admin/dispatches/edit.php', ['cookie_jar' => $jar])['status'], 'the new-dispatch form');
        assert_equal(403, http_request('GET', $env->baseUrl . "/admin/dispatches/edit.php?id={$id}", ['cookie_jar' => $jar])['status'], 'an existing dispatch');

        // A valid CSRF token from a page they can open still doesn't let a crafted POST through.
        $home = http_request('GET', $env->baseUrl . '/admin/gs-requests/', ['cookie_jar' => $jar]);
        assert_equal(200, $home['status']);
        $csrf = extract_csrf($home['body']);
        $create = http_request('POST', $env->baseUrl . '/admin/dispatches/edit.php', ['cookie_jar' => $jar, 'body' => [
            'csrf_token' => $csrf, 'ott' => 'x', 'action' => 'save', 'title' => 'Sneaky Publish', 'slug' => '', 'excerpt' => '', 'body' => 'Should never land.', 'status' => 'published',
        ]]);
        assert_equal(403, $create['status'], 'publishing');
        $update = http_request('POST', $env->baseUrl . "/admin/dispatches/edit.php?id={$id}", ['cookie_jar' => $jar, 'body' => [
            'csrf_token' => $csrf, 'action' => 'save', 'title' => 'Hijacked Title', 'slug' => '', 'excerpt' => '', 'body' => 'x', 'status' => 'published', 'expected_lock_version' => 0,
        ]]);
        assert_equal(403, $update['status'], 'editing');
        $delete = http_request('POST', $env->baseUrl . "/admin/dispatches/edit.php?id={$id}", ['cookie_jar' => $jar, 'body' => ['csrf_token' => $csrf, 'action' => 'delete']]);
        assert_equal(403, $delete['status'], 'deleting');

        assert_equal($countBefore, (int) $env->scalar('SELECT COUNT(*) FROM dispatches'), 'nothing may have been created or deleted');
        assert_equal($titleBefore, $env->scalar('SELECT title FROM dispatches WHERE id = ?', [$id]), 'nothing may have been edited');

        // The admin nav only offers them pages they can open (previously: Dispatches and Audit Log, both dead links).
        foreach (['/admin/dispatches/', '/admin/audit-log/', '/admin/staff/', '/admin/data-erasure/'] as $adminOnly) {
            assert_true(strpos($home['body'], 'href="' . $adminOnly . '"') === false, "no link to {$adminOnly} for pharmacy_staff");
        }
        assert_contains('href="/admin/veterinary-applications/"', $home['body'], 'the pages they do have are still linked');

        // Admins still see every link, and the dispatch pages still work for them.
        $adminHome = http_request('GET', $env->baseUrl . '/admin/gs-requests/', ['cookie_jar' => $env->cookieJarStaff]);
        foreach (['/admin/dispatches/', '/admin/audit-log/', '/admin/staff/', '/admin/data-erasure/'] as $adminOnly) {
            assert_contains('href="' . $adminOnly . '"', $adminHome['body'], "admins keep the link to {$adminOnly}");
        }
        assert_equal(200, http_request('GET', $env->baseUrl . '/admin/dispatches/', ['cookie_jar' => $env->cookieJarStaff])['status']);
        assert_equal(200, http_request('GET', $env->baseUrl . "/admin/dispatches/edit.php?id={$id}", ['cookie_jar' => $env->cookieJarStaff])['status']);
    });

    // ---- Validation messages: printed under each field, with the limit, plus a banner ----
    // Before: $errors['title'] etc. were set but never printed (a red field and no words), the excerpt
    // didn't even get the red class, and a failed validation showed no banner at all - so an over-long
    // excerpt (crafted request) looked like the save silently did nothing. "Too long" never said how long.

    $countAll = fn (): int => (int) $env->scalar('SELECT COUNT(*) FROM dispatches');

    run_test('an empty title and body each get a message under their field, a banner, and nothing is saved', function () use ($env, $create, $countAll) {
        $before = $countAll();
        $r = $create(['title' => '', 'body' => '', 'excerpt' => 'Kept on the page.', 'status' => 'draft']);
        assert_equal(200, $r['status'], 'shown in place, not redirected');

        assert_contains('Please fix the problems below. Nothing was saved.', $r['body']);
        assert_contains('<span class="field-error" id="err-title">Enter a title.</span>', $r['body']);
        assert_contains('<span class="field-error" id="err-body">Enter the body text.</span>', $r['body']);
        // Tied to their fields for screen readers.
        assert_true((bool) preg_match('/<input[^>]*name="title"[^>]*aria-invalid="true"[^>]*aria-describedby="err-title"/', $r['body']));
        assert_true((bool) preg_match('/<textarea[^>]*name="body"[^>]*aria-invalid="true"[^>]*aria-describedby="err-body"/', $r['body']));
        assert_contains('Kept on the page.', $r['body'], 'what was typed is kept');
        assert_equal($before, $countAll(), 'nothing may be saved');
    });

    run_test('a single mistake says "problem", and a clean form shows no error text at all', function () use ($env, $create) {
        $r = $create(['title' => 'Has a title', 'body' => '', 'status' => 'draft']);
        assert_contains('Please fix the problem below. Nothing was saved.', $r['body']);
        assert_true(strpos($r['body'], 'id="err-title"') === false, 'only the failing field gets a message');

        $ok = http_request('GET', $env->baseUrl . '/admin/dispatches/edit.php', ['cookie_jar' => $env->cookieJarStaff])['body'];
        assert_true(strpos($ok, 'field-error"') === false && strpos($ok, 'aria-invalid') === false, 'a fresh form shows no errors');
    });

    run_test('an over-long title, body (characters and bytes) and excerpt each say what the limit is and what theirs was', function () use ($env, $create, $countAll) {
        $before = $countAll();

        $title = $create(['title' => str_repeat('t', 201), 'body' => 'Fine body.', 'status' => 'draft']);
        assert_contains('The title is too long: 200 characters at most, and yours is 201.', $title['body']);

        $chars = $create(['title' => 'Long body', 'body' => str_repeat('a', 60001), 'status' => 'draft']);
        assert_contains('The body is too long: 60,000 characters at most, and yours is 60,001.', $chars['body']);

        // 25,000 three-byte characters: well under 60,000 characters, but 75,000 bytes - over the column's 65,535.
        $bytes = $create(['title' => 'Long body', 'body' => str_repeat('अ', 25000), 'status' => 'draft']);
        assert_contains('The body is too long to store: the limit is 65,535 bytes (about 64 KB) and yours is 75,000.', $bytes['body']);
        assert_contains('Accented and non-English text takes more than one byte per character', $bytes['body']);

        // The excerpt can only exceed 400 through a crafted request (the input has maxlength); it must not
        // fail silently: red field, message and banner.
        $excerpt = $create(['title' => 'Long excerpt', 'excerpt' => str_repeat('e', 401), 'body' => 'Fine body.', 'status' => 'draft']);
        assert_contains('The excerpt is too long: 400 characters at most, and yours is 401.', $excerpt['body']);
        assert_true((bool) preg_match('/<label class="field has-error">\s*<span class="lbl">Summary/', $excerpt['body']), 'the excerpt field is highlighted too');
        assert_contains('Please fix the problem below', $excerpt['body']);

        assert_equal($before, $countAll(), 'none of these may have been saved');
    });

    run_test('editing an existing dispatch with a mistake shows the messages and leaves the saved version untouched', function () use ($env, $create) {
        $jar = $env->cookieJarStaff;
        $page = $create(['title' => 'Edit Errors Test', 'body' => 'Original body.', 'status' => 'draft']);
        $id = (int) $env->scalar("SELECT id FROM dispatches WHERE title = 'Edit Errors Test'");
        assert_true($id > 0);

        $bad = http_request('POST', $env->baseUrl . "/admin/dispatches/edit.php?id={$id}", ['cookie_jar' => $jar, 'body' => [
            'csrf_token' => extract_csrf($page['body']), 'action' => 'save', 'title' => str_repeat('x', 250), 'slug' => '', 'excerpt' => '',
            'body' => 'Changed body.', 'status' => 'draft', 'expected_lock_version' => $env->scalar('SELECT lock_version FROM dispatches WHERE id = ?', [$id]),
        ]]);
        assert_equal(200, $bad['status']);
        assert_contains('The title is too long: 200 characters at most, and yours is 250.', $bad['body']);
        assert_contains('Please fix the problem below. Nothing was saved.', $bad['body']);
        assert_contains('Changed body.', $bad['body'], 'the typed text stays in the form');
        assert_equal('Original body.', $env->scalar('SELECT body FROM dispatches WHERE id = ?', [$id]), 'the saved version is untouched');
        assert_equal('Edit Errors Test', $env->scalar('SELECT title FROM dispatches WHERE id = ?', [$id]));
    });

    // ---- Slugs: say when one was taken, and don't let a published address change by accident ----
    // A slug is the dispatch's public address (/dispatches/view.php?slug=...). Two silent behaviours: a taken
    // slug got a random -xxxx suffix while the message still said just "Issue saved.", and editing the
    // slug of a published dispatch broke its old link without a word.

    // Updates a dispatch the way the form does (GET for the tokens, POST to its own URL, follow the redirect).
    $update = function (int $id, array $fields) use ($env): array {
        $jar = $env->cookieJarStaff;
        $get = http_request('GET', $env->baseUrl . "/admin/dispatches/edit.php?id={$id}", ['cookie_jar' => $jar]);
        $post = http_request('POST', $env->baseUrl . "/admin/dispatches/edit.php?id={$id}", ['cookie_jar' => $jar, 'body' => array_merge(
            ['csrf_token' => extract_csrf($get['body']), 'action' => 'save', 'excerpt' => '', 'status' => 'draft',
             'expected_lock_version' => $env->scalar('SELECT lock_version FROM dispatches WHERE id = ?', [$id])],
            $fields
        )]);
        $followed = $post['status'] === 302 ? http_request('GET', $env->baseUrl . $post['location'], ['cookie_jar' => $jar]) : $post;
        return $followed + ['post' => $post];
    };
    $flashOf = function (string $html): string {
        return preg_match('#<div class="alert[^"]*">([^<]*)</div>#', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES) : '';
    };
    $slugOf = fn (int $id) => (string) $env->scalar('SELECT slug FROM dispatches WHERE id = ?', [$id]);
    $idOf   = fn (string $title) => (int) $env->scalar('SELECT id FROM dispatches WHERE title = ? ORDER BY id DESC LIMIT 1', [$title]);
    $pubStatus = fn (string $slug) => http_request('GET', $env->baseUrl . '/journal/view.php?slug=' . urlencode($slug), ['cookie_jar' => $env->tmpDir . '/cookies-disp-slugcheck.txt'])['status'];

    run_test('a taken slug is reported in the message with the slug that was asked for and the one that was saved', function () use ($env, $create, $update, $flashOf, $slugOf, $idOf) {
        $first = $create(['title' => 'Slug Taken Test', 'body' => 'One.', 'status' => 'draft']);
        assert_equal('Issue created.', $flashOf($first['body']), 'no slug note when the slug went through as asked');

        $second = $create(['title' => 'Slug Taken Test', 'body' => 'Two.', 'status' => 'draft']);
        $id2 = (int) $env->scalar("SELECT MAX(id) FROM dispatches WHERE title = 'Slug Taken Test'");
        $saved = $slugOf($id2);
        assert_true($saved !== 'slug-taken-test' && str_starts_with($saved, 'slug-taken-test-'), 'the second got a suffix: ' . $saved);
        assert_true((bool) preg_match('/^slug-taken-test-[0-9a-f]{4}$/', $saved), 'the suffix is exactly four hex characters: ' . $saved);
        assert_equal("Issue created. The slug \"slug-taken-test\" was already taken, so it was saved as \"{$saved}\".", $flashOf($second['body']));

        // Also when an update asks for a slug another dispatch already has.
        $other = $create(['title' => 'Slug Taken Other', 'body' => 'Three.', 'status' => 'draft']);
        $idO = $idOf('Slug Taken Other');
        $r = $update($idO, ['title' => 'Slug Taken Other', 'slug' => 'slug-taken-test', 'body' => 'Three.']);
        $now = $slugOf($idO);
        assert_equal("Issue saved. The slug \"slug-taken-test\" was already taken, so it was saved as \"{$now}\".", $flashOf($r['body']));
    });

    run_test('once a dispatch is published its slug is locked: the form shows it, and a changed slug is ignored', function () use ($env, $create, $update, $flashOf, $slugOf, $idOf) {
        $page = $create(['title' => 'Locked Slug Test', 'body' => 'Body.', 'status' => 'published']);
        $id = $idOf('Locked Slug Test');
        $slug = $slugOf($id);
        assert_equal('locked-slug-test', $slug);

        // The edit form shows the lock and the deliberate way around it.
        assert_true((bool) preg_match('/<input[^>]*name="slug"[^>]*readonly/', $page['body']), 'the slug box is read-only');
        assert_contains('Locked: this issue has been published', $page['body']);
        assert_contains('/journal/view.php?slug=locked-slug-test', $page['body'], 'the hint shows the public address');
        assert_contains('name="unlock_slug"', $page['body']);

        // A normal save (the form re-posts the same slug) says nothing about the slug.
        $ok = $update($id, ['title' => 'Locked Slug Test', 'slug' => $slug, 'body' => 'Edited.', 'status' => 'published']);
        assert_equal('Issue saved.', $flashOf($ok['body']));

        // A crafted request with a different slug, or with the slug blanked (which would regenerate it from a
        // new title), is ignored - and the person is told.
        $changed = $update($id, ['title' => 'Locked Slug Test', 'slug' => 'something-else', 'body' => 'Edited.', 'status' => 'published']);
        assert_equal($slug, $slugOf($id), 'a different slug must not be applied without unlocking');
        assert_contains('The slug was left as "locked-slug-test" because this issue has been published', $flashOf($changed['body']));

        $blank = $update($id, ['title' => 'A Completely New Title', 'slug' => '', 'body' => 'Edited.', 'status' => 'published']);
        assert_equal($slug, $slugOf($id), 'a blanked slug must not be regenerated from the new title');
        assert_equal('A Completely New Title', $env->scalar('SELECT title FROM dispatches WHERE id = ?', [$id]), 'the title itself still changes');
        $update($id, ['title' => 'Locked Slug Test', 'slug' => $slug, 'body' => 'Edited.', 'status' => 'published']);
    });

    run_test('unpublishing does not unlock the slug (the address was already public); a never-published draft is free to change', function () use ($env, $create, $update, $flashOf, $slugOf, $idOf) {
        $create(['title' => 'Was Published Test', 'body' => 'Body.', 'status' => 'published']);
        $id = $idOf('Was Published Test');
        $back = $update($id, ['title' => 'Was Published Test', 'slug' => 'was-published-test', 'body' => 'Body.', 'status' => 'draft']);
        assert_equal('draft', $env->scalar('SELECT status FROM dispatches WHERE id = ?', [$id]));
        assert_contains('Locked: this issue has been published', $back['body'], 'still locked as a draft: published_at is set');
        $update($id, ['title' => 'Was Published Test', 'slug' => 'new-address', 'body' => 'Body.', 'status' => 'draft']);
        assert_equal('was-published-test', $slugOf($id));

        $create(['title' => 'Never Published Test', 'body' => 'Body.', 'status' => 'draft']);
        $idD = $idOf('Never Published Test');
        $page = http_request('GET', $env->baseUrl . "/admin/dispatches/edit.php?id={$idD}", ['cookie_jar' => $env->cookieJarStaff])['body'];
        assert_true(strpos($page, 'readonly') === false && strpos($page, 'unlock_slug') === false, 'a draft that was never published has no lock');
        $r = $update($idD, ['title' => 'Never Published Test', 'slug' => 'fresh-draft-slug', 'body' => 'Body.', 'status' => 'draft']);
        assert_equal('fresh-draft-slug', $slugOf($idD), 'its slug can be changed freely');
        assert_equal('Issue saved.', $flashOf($r['body']), 'and no slug warning is shown');
    });

    run_test('"Change the slug anyway" changes a published slug on purpose, and the message says the old address no longer works', function () use ($env, $create, $update, $flashOf, $slugOf, $idOf, $pubStatus) {
        $create(['title' => 'Unlock Slug Test', 'body' => 'Body.', 'status' => 'published']);
        $id = $idOf('Unlock Slug Test');
        assert_equal(200, $pubStatus('unlock-slug-test'), 'the old address works while it is the slug');

        $r = $update($id, ['title' => 'Unlock Slug Test', 'slug' => 'renamed-on-purpose', 'unlock_slug' => '1', 'body' => 'Body.', 'status' => 'published']);
        assert_equal('renamed-on-purpose', $slugOf($id));
        assert_equal('Issue saved. The old address (slug "unlock-slug-test") no longer works.', $flashOf($r['body']));
        assert_equal(404, $pubStatus('unlock-slug-test'), 'the old public address now 404s');
        assert_equal(200, $pubStatus('renamed-on-purpose'), 'the new one works');
    });

    // ---- The list labels a taken-down dispatch "Unpublished", not "Draft" with a publication date ----
    run_test('the admin list calls a published dispatch "Published", a taken-down one "Unpublished", and one never published "Draft"', function () use ($env, $create, $update, $idOf) {
        $create(['title' => 'Label Published Test', 'body' => 'Body.', 'status' => 'published']);
        $create(['title' => 'Label Unpublished Test', 'body' => 'Body.', 'status' => 'published']);
        $create(['title' => 'Label Draft Test', 'body' => 'Body.', 'status' => 'draft']);
        $idU = $idOf('Label Unpublished Test');
        $update($idU, ['title' => 'Label Unpublished Test', 'slug' => 'label-unpublished-test', 'body' => 'Body.', 'status' => 'draft']);
        assert_equal('draft', $env->scalar('SELECT status FROM dispatches WHERE id = ?', [$idU]));
        assert_true($env->scalar('SELECT published_at FROM dispatches WHERE id = ?', [$idU]) !== null, 'published_at is kept after unpublishing');

        $list = http_request('GET', $env->baseUrl . '/admin/dispatches/', ['cookie_jar' => $env->cookieJarStaff])['body'];
        $pill = function (string $title) use ($list): string {
            // The title cell may also hold a "View live" link, so allow anything up to the end of that cell.
            $pattern = '#<a href="/admin/dispatches/edit\.php\?id=\d+">' . preg_quote($title, '#') . '</a>(?:(?!</td>).)*</td>\s*<td>[^<]*</td>\s*<td><span class="status-pill[^"]*">([^<]*)</span>#s';
            assert_true((bool) preg_match($pattern, $list, $m), "no list row found for {$title}");
            return $m[1];
        };
        assert_equal('Published', $pill('Label Published Test'));
        // The published pill has its own class (it used to borrow the GS-request "approved" one), styled in the shared stylesheet.
        assert_true((bool) preg_match('#<span class="status-pill status-published">Published</span>#', $list), 'published rows use status-published');
        assert_true(strpos($list, 'class="status-pill status-approved"') === false, 'no dispatch row borrows the GS-request status class (checked on the element: the word is also in the stylesheet text)');
        // (The badge rules are generated from STATUS_TONES, so look in the CSS the page actually serves.)
        assert_true((bool) preg_match('/\.status-pill\.status-published[^{}]*\{ border-color:var\(--teal\)/', $list), 'the published badge is styled teal in the served CSS');
        assert_equal('Unpublished', $pill('Label Unpublished Test'));
        assert_equal('Draft', $pill('Label Draft Test'));
    });

    run_test('a published dispatch gets a "View live" link in the admin list; drafts and unpublished ones do not (their public page is a 404)', function () use ($env, $create, $update, $idOf) {
        $create(['title' => 'Live Link Published', 'body' => 'Body.', 'status' => 'published']);
        $create(['title' => 'Live Link Unpublished', 'body' => 'Body.', 'status' => 'published']);
        $create(['title' => 'Live Link Draft', 'body' => 'Body.', 'status' => 'draft']);
        $update($idOf('Live Link Unpublished'), ['title' => 'Live Link Unpublished', 'slug' => 'live-link-unpublished', 'body' => 'Body.', 'status' => 'draft']);

        $list = http_request('GET', $env->baseUrl . '/admin/dispatches/', ['cookie_jar' => $env->cookieJarStaff])['body'];
        $cell = function (string $title) use ($list): string {
            assert_true((bool) preg_match('#<td>\s*<a href="/admin/dispatches/edit\.php\?id=\d+">' . preg_quote($title, '#') . '</a>(.*?)</td>#s', $list, $m), "no list row found for {$title}");
            return $m[1];
        };

        $live = $cell('Live Link Published');
        assert_true((bool) preg_match('#<a class="live-link" href="/journal/view\.php\?slug=live-link-published" target="_blank" rel="noopener"[^>]*>View live#', $live), 'the published row links to its public page, in a new tab with rel=noopener');
        assert_contains('on the live site (opens in a new tab)', $live, 'with an accessible name saying where it goes');
        assert_true(strpos($cell('Live Link Unpublished'), 'live-link') === false, 'an unpublished dispatch has no public page, so no link');
        assert_true(strpos($cell('Live Link Draft'), 'live-link') === false, 'neither does a draft');

        // And the link really leads somewhere: the public page for that slug loads.
        assert_equal(200, http_request('GET', $env->baseUrl . '/journal/view.php?slug=live-link-published', ['cookie_jar' => $env->tmpDir . '/cookies-disp-livelink.txt'])['status']);
        assert_equal(404, http_request('GET', $env->baseUrl . '/journal/view.php?slug=live-link-unpublished', ['cookie_jar' => $env->tmpDir . '/cookies-disp-livelink.txt'])['status'], 'the unpublished one would have been a dead link');
    });

    run_test('the public dispatch page and its "not found" page end with a real Back button, not a text link', function () use ($env, $create) {
        $create(['title' => 'Back Button Test', 'body' => 'Body.', 'status' => 'published']);
        $jar = $env->tmpDir . '/cookies-disp-backbtn.txt';

        $article = http_request('GET', $env->baseUrl . '/journal/view.php?slug=back-button-test', ['cookie_jar' => $jar]);
        assert_equal(200, $article['status']);
        $notFound = http_request('GET', $env->baseUrl . '/journal/view.php?slug=no-such-dispatch-anywhere', ['cookie_jar' => $jar]);
        assert_equal(404, $notFound['status']);

        foreach (['article' => $article['body'], 'not-found' => $notFound['body']] as $which => $html) {
            assert_true((bool) preg_match('#<a class="back" href="/journal/"><span class="arr">←</span> Back to Journal</a>#', $html), "{$which}: the Back button (the layout's boxed .back style, with its arrow)");
            assert_true(strpos($html, 'style="color:var(--paper); border-bottom') === false, "{$which}: no inline underlined-link styling left");
        }
        // The not-found message no longer carries the inline text link inside its sentence.
        assert_contains("This issue doesn't exist or hasn't been published.</p>", $notFound['body']);
        assert_true(strpos(file_get_contents($env->root . '/public_html/dispatches/view.php'), 'style="') === false, 'view.php has no inline style attributes');
    });

    // ---- The admin list pages (Newer / Older) instead of silently stopping at 200 ----
    run_test('the admin dispatch list pages through every dispatch: none repeated or missing, ties in a fixed order, Newer/Older only where they lead somewhere', function () use ($env) {
        // 230 drafts sharing one updated_at (a burst, as a bulk import would make): ties are exactly where
        // LIMIT/OFFSET paging repeats or drops rows unless the order is fully determined.
        $authorId = (int) $env->scalar('SELECT id FROM staff_users ORDER BY id LIMIT 1');
        $seeded = [];
        (function () use ($env, $authorId, &$seeded) {
            $pdo = $env->pdo();
            $pdo->beginTransaction();
            $ins = $pdo->prepare("INSERT INTO dispatches (title, slug, body, status, author_staff_id, updated_at) VALUES (?, ?, 'x', 'draft', ?, '2001-01-01 00:00:00')");
            for ($i = 1; $i <= 230; $i++) {
                $n = str_pad((string) $i, 3, '0', STR_PAD_LEFT);
                $ins->execute(["Paging Test {$n}", "paging-test-{$n}", $authorId]);
                $seeded[] = (int) $pdo->lastInsertId();
            }
            $pdo->commit();
        })();

        try {
            $jar = $env->cookieJarStaff;
            $fetch = function (string $query = '') use ($env, $jar) {
                $r = http_request('GET', $env->baseUrl . '/admin/dispatches/' . $query, ['cookie_jar' => $jar]);
                preg_match_all('#href="/admin/dispatches/edit\.php\?id=(\d+)"#', $r['body'], $m);
                return ['status' => $r['status'], 'body' => $r['body'], 'ids' => array_map('intval', $m[1])];
            };

            // Walk every page the way a person would, by following "Older" until it stops being offered.
            $all = [];
            $pages = 0;
            for ($p = 1; $p <= 10; $p++) {
                $r = $fetch($p === 1 ? '' : "?page={$p}");
                assert_equal(200, $r['status']);
                assert_true(count($r['ids']) <= 100, "page {$p} holds at most 100 rows, got " . count($r['ids']));
                assert_equal($p > 1, strpos($r['body'], 'Newer') !== false, "page {$p}: Newer is offered only after page 1");
                $pages++;
                $all = array_merge($all, $r['ids']);
                if (strpos($r['body'], 'Older') === false) break;
            }
            assert_true($pages >= 3, '230 extra dispatches must span at least three pages, got ' . $pages);
            assert_true($pages < 10, 'Older must stop being offered on the last page');

            assert_equal(count($all), count(array_unique($all)), 'no dispatch may appear on two pages');
            $total = (int) $env->scalar('SELECT COUNT(*) FROM dispatches');
            assert_equal($total, count($all), 'every dispatch must be reachable - the oldest used to vanish past 200');
            assert_equal([], array_values(array_diff($seeded, $all)), 'every seeded dispatch is listed somewhere');

            // The tied rows (same updated_at) come out highest id first, continuously across the page breaks.
            $tied = array_values(array_filter($all, fn ($id) => in_array($id, $seeded, true)));
            $expected = $seeded;
            rsort($expected);
            assert_equal($expected, $tied, 'ties must be ordered by id DESC, identically on every page');

            // Odd page numbers behave: junk and non-positive mean page 1; far beyond the end is an empty page
            // that still offers a way back; an absurdly large number is not a server error.
            $first = $fetch('');
            foreach (['?page=0', '?page=-3', '?page=abc', '?page='] as $q) {
                assert_equal($first['ids'], $fetch($q)['ids'], "{$q} shows page 1");
            }
            $past = $fetch('?page=9999');
            assert_equal(200, $past['status']);
            assert_equal([], $past['ids']);
            assert_contains('There are no issues on this page.', $past['body']);
            assert_contains('Newer', $past['body']);
            assert_equal(200, $fetch('?page=9999999999999999999')['status'], 'a huge page number must not be a 500');
            // list_page() is shared, so every paged list must survive it (they all used to 500).
            foreach (['/admin/gs-requests/', '/admin/veterinary-applications/', '/admin/audit-log/'] as $other) {
                assert_equal(200, http_request('GET', $env->baseUrl . $other . '?page=9999999999999999999', ['cookie_jar' => $jar])['status'], "{$other} with a huge page number");
            }
        } finally {
            (function () use ($env) {
                $env->pdo()->exec("DELETE FROM dispatches WHERE slug LIKE 'paging-test-%'");
            })();
        }
    });

    run_test('a form re-rendered after a validation error still shows the slug lock and does not change the slug', function () use ($env, $create, $update, $slugOf, $idOf) {
        $create(['title' => 'Lock After Error', 'body' => 'Body.', 'status' => 'published']);
        $id = $idOf('Lock After Error');
        $bad = $update($id, ['title' => 'Lock After Error', 'slug' => 'lock-after-error', 'body' => '', 'status' => 'published']);
        assert_equal(200, $bad['status']);
        assert_contains('Enter the body text.', $bad['body']);
        assert_contains('Locked: this issue has been published', $bad['body']);
        assert_equal('lock-after-error', $slugOf($id));
    });
};
