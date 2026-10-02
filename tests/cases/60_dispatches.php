<?php
return function (TestEnv $env): void {
    run_test('public dispatches list is empty before anything is published', function () use ($env) {
        $r = http_request('GET', $env->baseUrl . '/dispatches/index.php', ['cookie_jar' => $env->tmpDir . '/cookies-disp-anon.txt']);
        assert_equal(200, $r['status']);
        assert_contains('No dispatches published yet', $r['body']);
    });

    run_test('admin can create and publish a dispatch', function () use ($env) {
        $jar = $env->cookieJarStaff;
        $get = http_request('GET', $env->baseUrl . '/admin/dispatches/edit.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        $post = http_request('POST', $env->baseUrl . '/admin/dispatches/edit.php', [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => $csrf, 'action' => 'save',
                'title' => 'A New Oral Formulation', 'slug' => '', 'excerpt' => 'Notes from the bench.',
                'body' => 'Full text of the dispatch.', 'status' => 'published',
            ],
        ]);
        assert_contains('Dispatch created', $post['body']);

        $row = $env->pdo()->query("SELECT * FROM dispatches WHERE title = 'A New Oral Formulation'")->fetch();
        assert_true($row !== false);
        assert_equal('published', $row['status']);
        assert_equal('a-new-oral-formulation', $row['slug']);
        assert_true($row['published_at'] !== null);
        $env->shared['dispatchSlug'] = $row['slug'];
    });

    run_test('the published dispatch appears on the public list and its own page', function () use ($env) {
        $list = http_request('GET', $env->baseUrl . '/dispatches/index.php', ['cookie_jar' => $env->tmpDir . '/cookies-disp-anon2.txt']);
        assert_contains('A New Oral Formulation', $list['body']);

        $slug = $env->shared['dispatchSlug'];
        $view = http_request('GET', $env->baseUrl . "/dispatches/view.php?slug={$slug}", ['cookie_jar' => $env->tmpDir . '/cookies-disp-anon3.txt']);
        assert_equal(200, $view['status']);
        assert_contains('Full text of the dispatch', $view['body']);
    });

    run_test('an unknown slug 404s', function () use ($env) {
        $r = http_request('GET', $env->baseUrl . '/dispatches/view.php?slug=does-not-exist', ['cookie_jar' => $env->tmpDir . '/cookies-disp-404.txt']);
        assert_equal(404, $r['status']);
    });

    run_test('publishing a second dispatch with the same auto-generated slug gets a disambiguated one, not a raw error', function () use ($env) {
        // Regression test for the slug-collision race: the code used to check for a
        // conflicting slug first, then insert if none was found, as two separate
        // statements — now it inserts directly and only disambiguates on an actual UNIQUE
        // violation from the database. Submitting the exact same title again (so it
        // auto-generates the exact same base slug) exercises that catch-and-retry path.
        $jar = $env->cookieJarStaff;
        $get = http_request('GET', $env->baseUrl . '/admin/dispatches/edit.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        $post = http_request('POST', $env->baseUrl . '/admin/dispatches/edit.php', [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => $csrf, 'action' => 'save',
                'title' => 'A New Oral Formulation', 'slug' => '', 'excerpt' => 'A second, unrelated post.',
                'body' => 'Different body text entirely.', 'status' => 'published',
            ],
        ]);
        assert_contains('Dispatch created', $post['body']);

        $rows = $env->pdo()->query("SELECT slug FROM dispatches WHERE title = 'A New Oral Formulation'")->fetchAll(PDO::FETCH_COLUMN);
        assert_equal(2, count($rows), 'both dispatches with this title should exist');
        assert_equal(2, count(array_unique($rows)), 'the two rows must not have ended up with the same slug — found: ' . implode(', ', $rows));
        assert_true(in_array('a-new-oral-formulation', $rows, true), 'the first dispatch should keep its original slug');
        $disambiguated = array_values(array_diff($rows, ['a-new-oral-formulation']))[0];
        assert_true(str_starts_with($disambiguated, 'a-new-oral-formulation-'), 'the second should be the base slug plus a disambiguating suffix');
    });

    run_test('a title with a special character is not double-escaped in the <title> tag', function () use ($env) {
        $jar = $env->cookieJarStaff;
        $get = http_request('GET', $env->baseUrl . '/admin/dispatches/edit.php', ['cookie_jar' => $jar]);
        $csrf = extract_csrf($get['body']);
        $post = http_request('POST', $env->baseUrl . '/admin/dispatches/edit.php', [
            'cookie_jar' => $jar,
            'body' => [
                'csrf_token' => $csrf, 'action' => 'save',
                'title' => 'Dosing & Storage Notes', 'slug' => '', 'excerpt' => '',
                'body' => 'Body text.', 'status' => 'published',
            ],
        ]);
        assert_contains('Dispatch created', $post['body']);
        $slug = (string) $env->scalar("SELECT slug FROM dispatches WHERE title = 'Dosing & Storage Notes'");

        $view = http_request('GET', $env->baseUrl . "/dispatches/view.php?slug={$slug}", ['cookie_jar' => $env->tmpDir . '/cookies-disp-title.txt']);
        assert_contains('<title>Dosing &amp; Storage Notes', $view['body']);
        assert_true(strpos($view['body'], '&amp;amp;') === false, 'the title should be escaped exactly once, not twice');
    });
};
