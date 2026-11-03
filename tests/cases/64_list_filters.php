<?php
// The case and application lists take their filters from the URL. A value that matched nothing (a typo such
// as ?status=aproved) used to be passed to the query as-is: the list came back empty, said "No requests match
// these filters", and the dropdown read "All" - no hint why. Now only known values are accepted, anything
// else is ignored with a visible note. (Never a security issue - the value was always a bound parameter.)
return function (TestEnv $env): void {
    $jar = $env->cookieJarStaff;
    $fetch = function (string $path, string $query = '') use ($env, $jar): array {
        $r = http_request('GET', $env->baseUrl . $path . $query, ['cookie_jar' => $jar]);
        preg_match_all('#href="' . preg_quote($path, '#') . 'view\.php\?id=(\d+)"#', $r['body'], $m);
        return ['status' => $r['status'], 'body' => $r['body'], 'ids' => array_map('intval', $m[1])];
    };

    // Two 'submitted' cases of our own: by the time this file runs, earlier tests have moved every case on.
    // Throwaway PDO handles (a held connection can stall the HTTP test server on Windows SQLite).
    $seed = function () use ($env): array {
        $ids = [];
        (function () use ($env, &$ids) {
            $pdo = $env->pdo();
            $ins = $pdo->prepare("INSERT INTO gs_requests (source, owner_full_name, owner_email, owner_phone, owner_address, owner_city, owner_state, owner_pin, patient_name, requested_formulation, status)
                VALUES ('cat_owner', 'Filter Test Owner', 'filter.test@example.test', '9000000000', 'x', 'x', 'x', '000000', 'FilterCat', 'oral', 'submitted')");
            for ($i = 0; $i < 2; $i++) { $ins->execute(); $ids[] = (int) $pdo->lastInsertId(); }
        })();
        return $ids;
    };
    $unseed = function (array $ids) use ($env): void {
        (function () use ($env, $ids) {
            $env->pdo()->exec('DELETE FROM gs_requests WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
        })();
    };
    run_test('a known filter value still filters, and shows no note', function () use ($env, $fetch, $seed, $unseed) {
        $seeded = $seed();
        try {
        $r = $fetch('/admin/gs-requests/', '?status=submitted');
        assert_equal(200, $r['status']);
        $expected = array_map('intval', $env->pdo()->query("SELECT id FROM gs_requests WHERE status = 'submitted' ORDER BY created_at DESC, id DESC LIMIT 100")->fetchAll(PDO::FETCH_COLUMN));
        assert_true(count($expected) > 0, 'earlier tests leave submitted cases');
        assert_equal($expected, $r['ids'], 'only the submitted cases');
        assert_true(strpos($r['body'], 'Filter ignored') === false && strpos($r['body'], 'Filters ignored') === false, 'no note for a valid value');
        } finally { $unseed($seeded); }
    });

    run_test('a mistyped status is ignored with a note saying so, not shown as an empty list', function () use ($env, $fetch) {
        $all = $fetch('/admin/gs-requests/');
        assert_true(count($all['ids']) > 0);

        $typo = $fetch('/admin/gs-requests/', '?status=aproved');
        assert_equal(200, $typo['status']);
        assert_contains('Filter ignored', $typo['body']);
        assert_contains('The status filter &ldquo;aproved&rdquo; is not a valid status, so it was ignored and the list is not filtered by it.', $typo['body']);
        assert_true(strpos($typo['body'], 'No requests match these filters') === false, 'no misleading "no requests match"');
        assert_equal($all['ids'], $typo['ids'], 'with the bad value ignored the list is the unfiltered one');
    });

    run_test('several bad values are all reported; the good ones still apply', function () use ($env, $fetch, $seed, $unseed) {
        $seeded = $seed();
        try {
        $good = $fetch('/admin/gs-requests/', '?status=submitted');
        assert_true(count($good['ids']) > 0, 'there are submitted cases to filter to');
        $mixed = $fetch('/admin/gs-requests/', '?status=submitted&source=nope&formulation=gel');
        assert_contains('Filters ignored', $mixed['body'], 'plural heading for more than one');
        assert_contains('The source filter &ldquo;nope&rdquo; is not a valid source', $mixed['body']);
        assert_contains('The formulation filter &ldquo;gel&rdquo; is not a valid formulation', $mixed['body']);
        assert_true(strpos($mixed['body'], 'The status filter &ldquo;submitted&rdquo;') === false, 'the valid status is not reported');
        assert_equal($good['ids'], $mixed['ids'], 'the valid status filter still applied');
        } finally { $unseed($seeded); }
    });

    run_test('every real status, source and formulation is accepted (nothing valid is ever ignored)', function () use ($env, $fetch) {
        require_once $env->root . '/public_html/includes/case-statuses.php';
        foreach (GS_CASE_STATUSES as $s) {
            $r = $fetch('/admin/gs-requests/', '?status=' . $s);
            assert_equal(200, $r['status']);
            assert_true(strpos($r['body'], 'ilter ignored') === false && strpos($r['body'], 'ilters ignored') === false, "status={$s} must be accepted");
        }
        foreach (['veterinarian', 'cat_owner'] as $s) {
            assert_true(strpos($fetch('/admin/gs-requests/', "?source={$s}")['body'], 'ilter ignored') === false, "source={$s} must be accepted");
        }
        foreach (['injection', 'oral'] as $s) {
            assert_true(strpos($fetch('/admin/gs-requests/', "?formulation={$s}")['body'], 'ilter ignored') === false, "formulation={$s} must be accepted");
        }
        // A blank value is simply "All", not a mistake.
        assert_true(strpos($fetch('/admin/gs-requests/', '?status=&source=&formulation=')['body'], 'ilter ignored') === false, 'blank means no filter');
    });

    run_test('the value echoed in the note is escaped and shortened', function () use ($env, $fetch) {
        $xss = $fetch('/admin/gs-requests/', '?status=' . rawurlencode('<script>alert(1)</script>'));
        assert_equal(200, $xss['status']);
        assert_true(strpos($xss['body'], '<script>alert(1)</script>') === false, 'the typed text must not come back as live markup');
        assert_contains('&lt;script&gt;alert(1)&lt;/script&gt;', $xss['body']);

        $long = str_repeat('x', 500);
        $r = $fetch('/admin/gs-requests/', '?status=' . $long);
        assert_true(strpos($r['body'], $long) === false, 'a 500-character value is not echoed in full');
        assert_contains(str_repeat('x', 40) . '…', $r['body']);
    });

    run_test('the veterinary applications list does the same for its status filter', function () use ($env, $fetch) {
        $all = $fetch('/admin/veterinary-applications/');
        assert_true(count($all['ids']) > 0, 'earlier tests leave vet applications');

        $typo = $fetch('/admin/veterinary-applications/', '?status=aproved');
        assert_equal(200, $typo['status']);
        assert_contains('The status filter &ldquo;aproved&rdquo; is not a valid status, so it was ignored', $typo['body']);
        assert_equal($all['ids'], $typo['ids'], 'unfiltered when the bad value is ignored');

        require_once $env->root . '/public_html/includes/case-statuses.php';
        foreach (VET_APPLICATION_STATUSES as $s) {
            $r = $fetch('/admin/veterinary-applications/', '?status=' . $s);
            assert_true(strpos($r['body'], 'ilter ignored') === false, "status={$s} must be accepted");
        }
        $approved = $fetch('/admin/veterinary-applications/', '?status=approved');
        $expected = array_map('intval', $env->pdo()->query("SELECT id FROM vet_applications WHERE status = 'approved' ORDER BY created_at DESC, id DESC LIMIT 100")->fetchAll(PDO::FETCH_COLUMN));
        assert_equal($expected, $approved['ids'], 'a valid status still filters');
    });

    run_test('the GS requests list is the admin home, so it has no Back button; the other admin pages still link to it', function () use ($env) {
        $get = fn (string $path) => http_request('GET', $env->baseUrl . $path, ['cookie_jar' => $env->cookieJarStaff])['body'];
        // The element, not the text: the stylesheet mentions ".back" on every page.
        assert_true(strpos($get('/admin/gs-requests/'), '<a class="back"') === false, 'no Back button on the page it would point back to');
        foreach (['/admin/audit-log/', '/admin/staff/', '/admin/veterinary-applications/', '/admin/data-erasure/'] as $path) {
            assert_true((bool) preg_match('#<a class="back" href="/admin/gs-requests/"#', $get($path)), "{$path} links back to the requests list");
        }
    });
};