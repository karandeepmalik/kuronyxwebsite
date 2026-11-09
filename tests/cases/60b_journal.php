<?php
// The Journal is a series of numbered issues with a named author. Entries live in the `dispatches` table (nothing public uses
// that name), which gained two columns: issue_number (optional, UNIQUE) and author (a byline). The public list shows
// "Issue 04 · title", the summary, the author and the date, highest issue number first.
return function (TestEnv $env): void {
    $jar = $env->cookieJarStaff;
    // Saves an issue through the admin form, as the browser does. Returns the first response after the POST (a redirect on success).
    $save = function (array $fields, int $id = 0) use ($env, $jar): array {
        $url = '/admin/dispatches/edit.php' . ($id > 0 ? "?id={$id}" : '');
        $get = http_request('GET', $env->baseUrl . $url, ['cookie_jar' => $jar]);
        $body = array_merge(['csrf_token' => extract_csrf($get['body']), 'action' => 'save', 'slug' => '', 'excerpt' => '', 'status' => 'draft'], $fields);
        if ($id === 0) $body['ott'] = extract_ott($get['body']);
        if ($id > 0) $body['expected_lock_version'] = (string) $env->scalar('SELECT lock_version FROM dispatches WHERE id = ?', [$id]);
        return http_request('POST', $env->baseUrl . $url, ['cookie_jar' => $jar, 'body' => $body]);
    };
    $idOf = fn (string $title) => (int) $env->scalar('SELECT id FROM dispatches WHERE title = ?', [$title]);
    $journal = fn () => http_request('GET', $env->baseUrl . '/journal/index.php', ['cookie_jar' => $env->tmpDir . '/cookies-journal-anon.txt'])['body'];

    run_test('the editor has Issue number and Author fields, the admin menu says Journal, and the list has an Issue column', function () use ($env, $jar) {
        $page = http_request('GET', $env->baseUrl . '/admin/dispatches/edit.php', ['cookie_jar' => $jar])['body'];
        assert_contains('name="issue_number"', $page);
        assert_contains('name="author"', $page);
        assert_contains('Issue number (optional, each number once)', $page);
        assert_contains('Author line (optional, shown under the title)', $page);
        assert_contains('<h1 class="doc-title">New issue</h1>', $page);
        $list = http_request('GET', $env->baseUrl . '/admin/dispatches/', ['cookie_jar' => $jar])['body'];
        assert_contains('<h1 class="doc-title">Journal</h1>', $list);
        assert_contains('<th>Issue</th>', $list);
        assert_contains('<th>Created by</th>', $list);
        assert_contains('>New issue</a>', $list);
        assert_true((bool) preg_match('#<a [^>]*href="/admin/dispatches/"[^>]*>Journal</a>#', $list), 'the admin menu item is called Journal');
    });

    run_test('an issue is saved with its number and author, and the audit entry records the number', function () use ($env, $save, $idOf) {
        $r = $save(['title' => 'Journal Issue Two', 'issue_number' => '2', 'author' => 'Dr. Gloraine Carvalho with Dr. Adele M. Austin', 'excerpt' => 'Mechanism, evidence, and the difference between two molecules.', 'body' => 'Body two.', 'status' => 'published']);
        assert_equal(302, $r['status']);
        $row = $env->pdo()->query("SELECT issue_number, author, status FROM dispatches WHERE title = 'Journal Issue Two'")->fetch();
        assert_equal(2, (int) $row['issue_number']);
        assert_equal('Dr. Gloraine Carvalho with Dr. Adele M. Austin', $row['author']);
        $meta = json_decode((string) $env->scalar("SELECT metadata_json FROM audit_log WHERE action = 'dispatch_created' AND entity_id = ?", [$idOf('Journal Issue Two')]), true);
        assert_equal(2, $meta['issue_number']);
    });

    run_test('an issue number can be used only once: the second try says so under the field, keeps what was typed and saves nothing', function () use ($env, $save) {
        $r = $save(['title' => 'Journal Issue Two Again', 'issue_number' => '2', 'author' => 'Someone Else', 'body' => 'Another body.']);
        assert_equal(200, $r['status'], 'the form is shown again, not redirected');
        assert_field_error($r['body'], 'issue_number', 'Issue 02 already exists. Each issue number can be used once; pick another or leave it empty.', 'journal');
        assert_contains('Please fix the problem below. Nothing was saved.', $r['body']);
        assert_contains('value="Journal Issue Two Again"', $r['body']);
        assert_contains('Someone Else', $r['body']);
        assert_equal(0, (int) $env->scalar("SELECT COUNT(*) FROM dispatches WHERE title = 'Journal Issue Two Again'"));
    });

    run_test('changing an issue to a number someone else holds is refused too; saving it with its own number is fine', function () use ($env, $save, $idOf) {
        $save(['title' => 'Journal Issue Ten', 'issue_number' => '10', 'body' => 'Body ten.', 'status' => 'published']);
        $id = $idOf('Journal Issue Ten');
        $r = $save(['title' => 'Journal Issue Ten', 'issue_number' => '2', 'body' => 'Body ten.', 'status' => 'published'], $id);
        assert_equal(200, $r['status']);
        assert_field_error($r['body'], 'issue_number', 'Issue 02 already exists. Each issue number can be used once; pick another or leave it empty.', 'journal edit');
        assert_equal(10, (int) $env->scalar('SELECT issue_number FROM dispatches WHERE id = ?', [$id]), 'the saved number is untouched');
        $ok = $save(['title' => 'Journal Issue Ten', 'issue_number' => '10', 'body' => 'Body ten, edited.', 'status' => 'published'], $id);
        assert_equal(302, $ok['status'], 'keeping its own number is not a clash');
    });

    run_test('an issue number is optional: several entries may have none; a bad value is a message under the field', function () use ($env, $save) {
        foreach (['No Number One', 'No Number Two'] as $t) {
            $r = $save(['title' => $t, 'issue_number' => '', 'body' => 'A body.', 'status' => 'published']);
            assert_equal(302, $r['status'], "{$t} must save without a number");
        }
        assert_equal(2, (int) $env->scalar("SELECT COUNT(*) FROM dispatches WHERE title IN ('No Number One','No Number Two') AND issue_number IS NULL"));
        foreach (['abc', '0', '-3', '10000', '1.5'] as $bad) {
            $r = $save(['title' => "Bad Number {$bad}", 'issue_number' => $bad, 'body' => 'x']);
            assert_equal(200, $r['status'], "'{$bad}' must be refused");
            assert_field_error($r['body'], 'issue_number', 'Enter a whole number from 1 to 9999, or leave it empty.', "number {$bad}");
        }
        $long = $save(['title' => 'Long Author', 'author' => str_repeat('a', 256), 'body' => 'x']);
        assert_field_error($long['body'], 'author', 'The author line is too long: 255 characters at most, and yours is 256.', 'author');
    });

    run_test('the public Journal lists issues highest number first, numberless ones after, each with number, summary, author and date', function () use ($env, $journal) {
        $b = $journal();
        $pos = fn (string $needle) => strpos($b, $needle);
        $ten = $pos('Issue 10 · Journal Issue Ten');
        $two = $pos('Issue 02 · Journal Issue Two');
        $none = $pos('No Number One');
        assert_true($ten !== false && $two !== false && $none !== false, 'all three entries are listed');
        assert_true($ten < $two, 'Issue 10 comes before Issue 02');
        assert_true($two < $none, 'numbered issues come before entries with no number');
        assert_contains('Mechanism, evidence, and the difference between two molecules.', $b);
        assert_contains('Dr. Gloraine Carvalho with Dr. Adele M. Austin', $b);
        assert_true((bool) preg_match('#class="note-meta">[^<]*20\d\d#', $b), 'each entry shows its date');
        assert_true(strpos($b, 'The first issue is on its way') === false, 'the empty-state message is only for an empty Journal');
    });

    run_test('an issue page shows Journal · Issue 02, the author under the title and the date; the page title names the Journal', function () use ($env) {
        $slug = (string) $env->scalar("SELECT slug FROM dispatches WHERE title = 'Journal Issue Two'");
        $b = http_request('GET', $env->baseUrl . '/journal/view.php?slug=' . urlencode($slug), ['cookie_jar' => $env->tmpDir . '/cookies-journal-issue.txt'])['body'];
        assert_contains('<p class="doc-eyebrow">Journal · Issue 02</p>', $b);
        assert_contains('<h1 class="doc-title">Journal Issue Two</h1>', $b);
        assert_contains('<p class="doc-meta">Dr. Gloraine Carvalho with Dr. Adele M. Austin<span class="sep">·</span>', $b);
        assert_contains('<title>Journal Issue Two | Journal | Kuronyx</title>', $b);
        assert_contains('Back to Journal', $b);
    });

    run_test('a draft is not public: not listed and its address is a 404', function () use ($env, $save, $journal) {
        $save(['title' => 'Journal Secret Draft', 'issue_number' => '99', 'body' => 'Not yet.', 'status' => 'draft']);
        $slug = (string) $env->scalar("SELECT slug FROM dispatches WHERE title = 'Journal Secret Draft'");
        assert_true(strpos($journal(), 'Journal Secret Draft') === false, 'a draft must not be listed');
        assert_equal(404, http_request('GET', $env->baseUrl . '/journal/view.php?slug=' . urlencode($slug), ['cookie_jar' => $env->tmpDir . '/cookies-journal-draft.txt'])['status']);
    });

    run_test('the Journal page has the document\'s copy, the subscribe form, and no leftover Dispatches wording', function () use ($journal) {
        $b = $journal();
        assert_contains('<h1 class="doc-title">A clinical education series.</h1>', $b);
        assert_contains('Written by veterinarians, scientists and pharmacologists, for the people who treat the animal.', $b);
        assert_contains('Each issue takes one question and stays with it.', $b);
        assert_contains('New issues arrive monthly.', $b);
        assert_contains('<title>Journal | Veterinary Clinical Education Series | Kuronyx India</title>', $b);
        assert_contains('id="subscribeForm"', $b);
        foreach (['Dispatches', 'Field notes', 'formulary'] as $old) assert_true(strpos($b, $old) === false, "'{$old}' must be gone");
    });

    run_test('the public Journal list does not break when the author or summary hold markup', function () use ($env, $save, $journal) {
        $save(['title' => 'Journal Markup', 'issue_number' => '11', 'author' => '<b>Dr. X</b> & "Y"', 'excerpt' => '<script>alert(1)</script>', 'body' => 'x', 'status' => 'published']);
        $b = $journal();
        assert_true(strpos($b, '<script>alert(1)</script>') === false, 'the summary must be escaped');
        assert_contains('&lt;b&gt;Dr. X&lt;/b&gt; &amp; &quot;Y&quot;', $b);
    });
};
