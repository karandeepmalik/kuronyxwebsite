<?php
// The status lists live in ONE PHP file (includes/case-statuses.php); everything in PHP builds off it. The two
// SQL schema files can't (they are SQL), so these tests are what stops the copies drifting: add a status in one
// place and forget another, and the suite says which.
return function (TestEnv $env): void {
    // The include is constants only (and refuses a direct request), so it is safe to load in the test process.
    require_once $env->root . '/public_html/includes/case-statuses.php';

    $quoted = function (string $text): array {
        preg_match_all("/'([a-z_]+)'/", $text, $m);
        return $m[1];
    };
    $mysqlEnum = function (string $table) use ($env, $quoted): array {
        $sql = file_get_contents($env->root . '/db/schema.sql');
        assert_true((bool) preg_match('/CREATE TABLE ' . $table . '\b.*?status\s+ENUM\(([^)]*)\)/s', $sql, $m), "{$table}.status ENUM in schema.sql");
        return $quoted($m[1]);
    };
    $sqliteCheck = function (string $table) use ($env, $quoted): array {
        $sql = file_get_contents($env->root . '/db/schema.sqlite.sql');
        assert_true((bool) preg_match('/CREATE TABLE ' . $table . '\b.*?status\s+TEXT[^()]*(?:DEFAULT\s+\'[a-z_]+\'\s+)?CHECK\s*\(\s*status\s+IN\s*\(([^)]*)\)/s', $sql, $m), "{$table}.status CHECK in schema.sqlite.sql");
        return $quoted($m[1]);
    };

    run_test('GS_CASE_STATUSES equals the status enum in both schema files, in the same order', function () use ($mysqlEnum, $sqliteCheck) {
        assert_equal(GS_CASE_STATUSES, $mysqlEnum('gs_requests'), 'db/schema.sql gs_requests.status ENUM differs from GS_CASE_STATUSES (a status added in one place only?)');
        assert_equal(GS_CASE_STATUSES, $sqliteCheck('gs_requests'), 'db/schema.sqlite.sql gs_requests.status CHECK differs from GS_CASE_STATUSES');
    });

    run_test('VET_APPLICATION_STATUSES equals the vet_applications status enum in both schema files', function () use ($mysqlEnum, $sqliteCheck) {
        assert_equal(VET_APPLICATION_STATUSES, $mysqlEnum('vet_applications'), 'db/schema.sql vet_applications.status ENUM differs');
        assert_equal(VET_APPLICATION_STATUSES, $sqliteCheck('vet_applications'), 'db/schema.sqlite.sql vet_applications.status CHECK differs');
    });

    run_test('the lists have no duplicates, and the named subsets stay inside the full list', function () {
        assert_equal(count(GS_CASE_STATUSES), count(array_unique(GS_CASE_STATUSES)), 'no status listed twice');
        assert_equal(count(VET_APPLICATION_STATUSES), count(array_unique(VET_APPLICATION_STATUSES)));
        foreach (['GS_TERMINAL_STATUSES' => GS_TERMINAL_STATUSES, 'GS_FULFILMENT_STATUSES' => GS_FULFILMENT_STATUSES] as $name => $subset) {
            assert_equal([], array_values(array_diff($subset, GS_CASE_STATUSES)), "{$name} contains a status that is not a case status");
            assert_equal(count($subset), count(array_unique($subset)), "{$name} lists a status twice");
        }
        // The two meanings overlap only where they should: a finished case is both terminal and past fulfilment.
        assert_equal(['finished'], array_values(array_intersect(GS_TERMINAL_STATUSES, GS_FULFILMENT_STATUSES)));
        // Application erasure: everything except 'approved' (suspend an approved vet's account first).
        assert_equal([], array_values(array_diff(VET_APPLICATION_ERASABLE_STATUSES, VET_APPLICATION_STATUSES)), 'VET_APPLICATION_ERASABLE_STATUSES contains a status that is not an application status');
        assert_equal(count(VET_APPLICATION_ERASABLE_STATUSES), count(array_unique(VET_APPLICATION_ERASABLE_STATUSES)));
        assert_equal(['approved'], array_values(array_diff(VET_APPLICATION_STATUSES, VET_APPLICATION_ERASABLE_STATUSES)), 'only an approved application is not directly erasable');
        // The review actions only move between real application statuses, and never "to" a status they take "from".
        foreach (VET_APPLICATION_ACTIONS as $action => $rule) {
            assert_true(in_array($rule['to'], VET_APPLICATION_STATUSES, true), "{$action} moves to a status that is not an application status");
            assert_equal([], array_values(array_diff($rule['from'], VET_APPLICATION_STATUSES)), "{$action} may be taken from a status that is not an application status");
            // The page applies each action as a conditional UPDATE and reads rowCount() as "still the status I checked";
            // MariaDB counts only CHANGED rows, so an action that set the status it already has would look like a lost race.
            assert_true(!in_array($rule['to'], $rule['from'], true), "{$action} must not be allowed on an application that already has its target status");
        }
        assert_equal(['approved'], VET_APPLICATION_ACTIONS['suspend']['from'], 'only an approved application can be suspended');
        // The decisions that change who may send prescriptions need a recorded reason; asking for more information does not.
        $needNote = array_keys(array_filter(VET_APPLICATION_ACTIONS, fn ($r) => $r['note']));
        sort($needNote);
        assert_equal(['approve', 'reject', 'suspend'], $needNote, 'approve, reject and suspend require a note');
    });

    run_test('the erasure code, the case list and case page, and the vet portal all build off the shared constants', function () use ($env) {
        $read = fn (string $p) => file_get_contents($env->root . '/public_html/' . $p);
        assert_contains('const ERASABLE_CASE_STATUSES = GS_TERMINAL_STATUSES;', $read('includes/data-protection.php'));
        assert_contains('$statuses = GS_CASE_STATUSES;', $read('admin/gs-requests/index.php'));
        assert_contains('$statuses = GS_CASE_STATUSES;', $read('admin/gs-requests/view.php'));
        assert_contains('$statuses = VET_APPLICATION_STATUSES;', $read('admin/veterinary-applications/index.php'));
        assert_contains('in_array($case[\'status\'], GS_FULFILMENT_STATUSES, true)', $read('for-veterinarians/portal/view.php'));
    });

    run_test('no PHP file retypes the status list: the rarest status name appears in exactly one place', function () use ($env) {
        // 'formulation_discussion' is in no email template and no subset, so any second hand-typed list shows up.
        $found = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($env->root . '/public_html', FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() === 'php' && strpos(file_get_contents($file->getPathname()), "'formulation_discussion'") !== false) {
                $found[] = str_replace('\\', '/', substr($file->getPathname(), strlen($env->root) + 1));
            }
        }
        assert_equal(['public_html/includes/case-statuses.php'], $found, 'a status list was typed out again somewhere else');
    });

    // ---- Badge colours and labels follow the lists too ----

    run_test('every status has a badge tone, and the tone map names no status that does not exist', function () {
        $known = array_values(array_unique(array_merge(GS_CASE_STATUSES, VET_APPLICATION_STATUSES, ['published'])));
        $missing = array_values(array_diff($known, array_keys(STATUS_TONES)));
        assert_equal([], $missing, 'these statuses have no colour decision in STATUS_TONES - add them (good / bad / neutral)');
        $stray = array_values(array_diff(array_keys(STATUS_TONES), $known));
        assert_equal([], $stray, 'STATUS_TONES names a status that is in no list');
        foreach (STATUS_TONES as $status => $tone) {
            assert_true(in_array($tone, ['good', 'bad', 'neutral'], true), "{$status} has an invalid tone '{$tone}'");
        }
        // A status shared by two lists is coloured the same in both, because there is one tone per name.
        assert_equal('good', STATUS_TONES['approved']);
        assert_equal('bad', STATUS_TONES['rejected']);
    });

    run_test('the badge CSS the pages serve is generated from the tone map: teal for good, red for bad, nothing for neutral', function () use ($env) {
        $css = http_request('GET', $env->baseUrl . '/admin/gs-requests/', ['cookie_jar' => $env->cookieJarStaff])['body'];
        $served = ['good' => [], 'bad' => []];
        preg_match_all('/((?:\.status-pill\.status-[a-z_]+,?)+)\{ border-color:var\(--(teal|err)\); color:var\(--(?:teal|err)\); \}/', $css, $rules, PREG_SET_ORDER);
        foreach ($rules as $rule) {
            preg_match_all('/\.status-pill\.status-([a-z_]+)/', $rule[1], $names);
            $served[$rule[2] === 'teal' ? 'good' : 'bad'] = array_merge($served[$rule[2] === 'teal' ? 'good' : 'bad'], $names[1]);
        }
        $want = ['good' => [], 'bad' => []];
        foreach (STATUS_TONES as $status => $tone) {
            if ($tone !== 'neutral') $want[$tone][] = $status;
        }
        sort($want['good']); sort($want['bad']); sort($served['good']); sort($served['bad']);
        assert_equal($want['good'], $served['good'], 'the teal badges served differ from STATUS_TONES');
        assert_equal($want['bad'], $served['bad'], 'the red badges served differ from STATUS_TONES');
        assert_true(strpos(file_get_contents($env->root . '/public_html/includes/layout-header.php'), '.status-pill.status-approved') === false, 'the stylesheet must not hand-write the badge colours again');
    });

    run_test('status_label() turns "under_review" into "Under Review"', function () {
        assert_equal('Under Review', status_label('under_review'));
        assert_equal('Ready For Dispatch', status_label('ready_for_dispatch'));
        assert_equal('Communication In Progress', status_label('communication_in_progress'));
        assert_equal('Approved', status_label('approved'));
        foreach (array_merge(GS_CASE_STATUSES, VET_APPLICATION_STATUSES) as $s) {
            assert_true(strpos(status_label($s), '_') === false, "the label for {$s} must not contain an underscore");
        }
    });

    run_test('the application list and its filter show "Under Review", never "Under_review"', function () use ($env) {
        (function () use ($env) {
            $env->pdo()->exec("INSERT INTO vet_applications (full_name, professional_email, mobile, registration_number, registration_state, qualification, year_qualified, practice_type, clinic_name, clinic_address, clinic_city, clinic_state, clinic_pin, clinic_phone, status)
                VALUES ('Label Check Vet', 'label.check@example.test', '9000000000', 'LBL', 'MH', 'BVSc', 2020, 'x', 'Label Clinic', 'x', 'x', 'x', '000000', '9000000000', 'under_review')");
        })();
        try {
            $page = http_request('GET', $env->baseUrl . '/admin/veterinary-applications/', ['cookie_jar' => $env->cookieJarStaff])['body'];
            assert_contains('>Under Review</option>', $page, 'the filter dropdown option');
            assert_true((bool) preg_match('#<span class="status-pill status-under_review">Under Review</span>#', $page), 'the badge on the row');
            assert_true(strpos($page, 'Under_review') === false, 'no "Under_review" anywhere');
        } finally {
            (function () use ($env) { $env->pdo()->exec("DELETE FROM vet_applications WHERE professional_email = 'label.check@example.test'"); })();
        }
    });

    run_test('no page hand-rolls a status label any more: status_label() is the only formatter (the staff ROLE label is the one exception)', function () use ($env) {
        $offenders = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($env->root . '/public_html', FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') continue;
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($env->root . '/public_html') + 1));
            if ($rel === 'includes/case-statuses.php' || $rel === 'admin/staff/index.php') continue;
            if (strpos(file_get_contents($file->getPathname()), 'ucwords(') !== false) $offenders[] = $rel;
        }
        assert_equal([], $offenders, 'these format a label by hand (use status_label() for statuses)');
    });

    run_test('every email template on the case page is keyed by a real status (or "blank")', function () use ($env) {
        $src = file_get_contents($env->root . '/public_html/admin/gs-requests/view.php');
        assert_true((bool) preg_match('/\$emailTemplates = \[(.*?)\n\];/s', $src, $m), 'the $emailTemplates array');
        preg_match_all("/^    '([a-z_]+)' => \[/m", $m[1], $keys);
        $unknown = array_values(array_diff($keys[1], array_merge(['blank'], GS_CASE_STATUSES)));
        assert_equal([], $unknown, 'a template keyed by something that is not a case status can never be picked');
        assert_true(count($keys[1]) >= 6, 'the templates were found');
    });
};
