<?php
// The forms on the case page (case details, status, assignment, internal note) must keep what was typed when
// their submit FAILS - validation error, lock conflict, expired session. They used to refill from the freshly
// re-fetched row, so a long note over the limit came back as "too long" and an empty box. A lock conflict also
// says exactly which fields saving again would change, because the re-rendered form carries the new lock_version.
return function (TestEnv $env): void {
    $id  = $env->shared['gsRequestId'];
    $jar = $env->cookieJarStaff;
    $url = $env->baseUrl . "/admin/gs-requests/view.php?id={$id}";
    $cols = ['final_formulation', 'final_concentration', 'final_quantity', 'final_price', 'courier', 'tracking_number', 'dispatch_date', 'closure_reason'];

    $page = fn (): string => http_request('GET', $url, ['cookie_jar' => $jar])['body'];
    // POSTs an action with the page's current csrf token; $lock = which lock_version to claim the page had.
    $post = function (string $action, array $fields, $lock = null, ?string $csrf = null) use ($env, $id, $jar, $url, $page): string {
        $csrf ??= extract_csrf($page());
        $lock ??= $env->scalar('SELECT lock_version FROM gs_requests WHERE id = ?', [$id]);
        return http_request('POST', $url, ['follow' => true, 'cookie_jar' => $jar, 'body' => array_merge(['csrf_token' => $csrf, 'action' => $action, 'expected_lock_version' => $lock], $fields)])['body'];
    };
    $inputValue = function (string $html, string $name): ?string {
        return preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES) : null;
    };
    $noteBox = fn (string $html): ?string => preg_match('#<textarea name="content"[^>]*>(.*?)</textarea>#s', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES) : null;
    $selectedStatus = fn (string $html): ?string => preg_match('#<select name="new_status">.*?<option value="([a-z_]+)" selected>#s', $html, $m) ? $m[1] : null;
    $formLock = fn (string $html, string $action): ?int => preg_match('#name="action" value="' . $action . '">\s*<input type="hidden" name="expected_lock_version" value="(\d+)">#', $html, $m) ? (int) $m[1] : null;
    $savedRow = function () use ($env, $id, $cols): array {
        $s = $env->pdo()->prepare('SELECT ' . implode(', ', $cols) . ', status, lock_version, assigned_staff_id FROM gs_requests WHERE id = ?');
        $s->execute([$id]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        $s->closeCursor();
        return $r;
    };
    $count = fn (string $table): int => (int) $env->scalar("SELECT COUNT(*) FROM {$table} WHERE gs_request_id = ?", [$id]);
    $typedAll = ['final_formulation' => 'Typed formulation', 'final_concentration' => '12 mg/ml', 'final_quantity' => '45 ml', 'final_price' => '3100', 'courier' => 'Typed Courier', 'tracking_number' => 'TYPED-77', 'dispatch_date' => '2026-03-04', 'closure_reason' => 'Typed reason'];

    run_test('case details: a validation error keeps every typed field, saves nothing, and the saved values come back on a plain reload', function () use ($post, $page, $inputValue, $savedRow, $typedAll, $cols) {
        $before = $savedRow();
        // A bad price (the other seven fields are fine and must survive).
        $bad = $post('update_final', array_merge($typedAll, ['final_price' => 'abc']));
        assert_contains('Price must be', $bad);
        foreach ($cols as $c) {
            assert_equal($c === 'final_price' ? 'abc' : $typedAll[$c], $inputValue($bad, $c), "{$c} must keep what was typed");
        }
        // A bad date, and an over-long field.
        $badDate = $post('update_final', array_merge($typedAll, ['dispatch_date' => '2026-02-30']));
        assert_contains('valid date', $badDate);
        assert_equal('2026-02-30', $inputValue($badDate, 'dispatch_date'));
        assert_equal('Typed formulation', $inputValue($badDate, 'final_formulation'));
        $long = str_repeat('c', 101);
        $tooLong = $post('update_final', array_merge($typedAll, ['courier' => $long]));
        assert_contains('too long', $tooLong);
        assert_equal($long, $inputValue($tooLong, 'courier'));

        assert_equal($before, $savedRow(), 'a failed save changes nothing');
        // The draft is only in that one response: the next page load shows the saved values again.
        $reloaded = $page();
        assert_equal((string) ($before['courier'] ?? ''), $inputValue($reloaded, 'courier'));
        assert_true($inputValue($reloaded, 'final_formulation') !== 'Typed formulation');
    });

    run_test('case details: typed text is shown escaped, never as markup', function () use ($post, $typedAll) {
        $evil = '"><script>alert(1)</script>';
        $html = $post('update_final', array_merge($typedAll, ['final_price' => 'abc', 'courier' => $evil]));
        assert_true(strpos($html, '<script>alert(1)</script>') === false, 'the typed value must be escaped');
        assert_contains('&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    });

    run_test('status: a too-long note is kept in the box with the chosen status selected; nothing is saved', function () use ($env, $id, $post, $inputValue, $selectedStatus, $savedRow, $count) {
        $current = $savedRow()['status'];
        $chosen = $current === 'under_review' ? 'awaiting_information' : 'under_review';
        $historyBefore = $count('case_status_history');
        $note = str_repeat('n', 10001);
        $html = $post('change_status', ['new_status' => $chosen, 'status_note' => $note]);
        assert_contains('The status note is too long', $html);
        assert_equal($note, $inputValue($html, 'status_note'), 'the long note must come back');
        assert_equal($chosen, $selectedStatus($html), 'the status the user picked stays selected, not the current one');
        assert_equal($current, $savedRow()['status'], 'status unchanged');
        assert_equal($historyBefore, $count('case_status_history'), 'no history row');
        // A plain load shows the current status selected again.
        assert_equal($current, $selectedStatus(http_request('GET', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", ['cookie_jar' => $env->cookieJarStaff])['body']));
    });

    run_test('internal note: an empty or over-long note keeps what was typed; a successful one clears the box', function () use ($post, $noteBox, $count) {
        $notesBefore = $count('case_internal_notes');
        $long = str_repeat('word ', 2001); // 10,005 characters
        $tooLong = $post('add_note', ['content' => $long]);
        assert_contains('The note is too long', $tooLong);
        assert_equal($long, $noteBox($tooLong), 'the whole long note comes back');
        assert_equal($notesBefore, $count('case_internal_notes'));

        $blank = $post('add_note', ['content' => "   \n  "]);
        assert_contains('Note cannot be empty', $blank);

        $ok = $post('add_note', ['content' => 'Draft-keeping test note.']);
        assert_contains('Note added', $ok);
        assert_equal('', $noteBox($ok), 'after a successful save the box is empty');
        assert_equal($notesBefore + 1, $count('case_internal_notes'));
    });

    run_test('an expired session (bad CSRF token) keeps the typed note and case details too', function () use ($post, $noteBox, $inputValue, $typedAll) {
        $note = "A long note that took a while to write.\nSecond line.";
        $html = $post('add_note', ['content' => $note], null, 'not-a-real-token');
        assert_contains('Your session expired', $html);
        assert_equal($note, $noteBox($html));
        $html2 = $post('update_final', $typedAll, null, 'not-a-real-token');
        assert_contains('Your session expired', $html2);
        assert_equal('Typed Courier', $inputValue($html2, 'courier'));
    });

    run_test('a lock conflict keeps the typed case details, says what saving again would change, and the form then carries the NEW version', function () use ($env, $id, $post, $inputValue, $formLock, $savedRow, $typedAll, $cols) {
        $original = $savedRow();
        $stale = (int) $original['lock_version'];
        try {
            // Someone else saves between this person loading the page and submitting: courier changes, version bumps.
            $env->pdo()->prepare("UPDATE gs_requests SET courier = 'OtherPersonCourier', lock_version = lock_version + 1 WHERE id = ?")->execute([$id]);
            $newVersion = $stale + 1;

            $html = $post('update_final', $typedAll, $stale);
            assert_contains('changed by someone else', $html);
            assert_contains('What you typed is kept below', $html);
            foreach ($cols as $c) {
                assert_equal($typedAll[$c], $inputValue($html, $c), "{$c}: typed value kept on a conflict");
            }
            // The consequences are two short lists, not one run-on sentence: fields that hold saved data
            // (saving REPLACES them) and fields that are empty now (saving only fills them in).
            assert_true(substr_count($html, '<ul class="alert-list">') === 2, 'two lists: replace and fill in');
            assert_true((bool) preg_match('#Saving again would replace what is saved now:</p>\s*<ul class="alert-list">(.*?)</ul>#s', $html, $replace), 'the replace list');
            assert_true((bool) preg_match('#and would fill in these empty fields:</p>\s*<ul class="alert-list">(.*?)</ul>#s', $html, $fill), 'the fill-in list');
            assert_contains('<li>Courier: “OtherPersonCourier” would become “Typed Courier”</li>', $replace[1], 'the field the other person changed is shown as old -> new');
            assert_contains('<li>Final formulation: “', $replace[1], 'a field that holds saved data is in the replace list');
            assert_contains('<li>Dispatch date: “2026-03-04”</li>', $fill[1], 'a field that is empty now is only filled in');
            assert_contains('<li>Closure reason: “Typed reason”</li>', $fill[1]);
            assert_true(strpos($fill[1], 'Courier') === false && strpos($replace[1], 'Closure reason') === false, 'each field is in exactly one list');
            assert_equal('OtherPersonCourier', $env->scalar('SELECT courier FROM gs_requests WHERE id = ?', [$id]), 'nothing was overwritten by the conflicting submit');
            // The re-rendered form carries the current version, so saving again (after reading the message) works.
            assert_equal($newVersion, $formLock($html, 'update_final'), 'the form must carry the new lock_version');
            $again = $post('update_final', $typedAll, $newVersion);
            assert_contains('Case details updated', $again);
            assert_equal('Typed Courier', $env->scalar('SELECT courier FROM gs_requests WHERE id = ?', [$id]));
        } finally {
            // Put the case back exactly as the other tests expect it.
            $set = implode(', ', array_map(fn ($c) => "{$c} = ?", $cols));
            $env->pdo()->prepare("UPDATE gs_requests SET {$set} WHERE id = ?")->execute([...array_values(array_intersect_key($original, array_flip($cols))), $id]);
        }
    });

    run_test('a lock conflict where nothing typed differs from what is saved says so (and 1200 equals 1200.00)', function () use ($env, $id, $post, $savedRow, $cols) {
        $saved = $savedRow();
        $stale = (int) $saved['lock_version'];
        $env->pdo()->prepare('UPDATE gs_requests SET lock_version = lock_version + 1 WHERE id = ?')->execute([$id]); // e.g. someone changed the assignee
        // Type exactly what is saved, with the price written differently.
        $same = [];
        foreach ($cols as $c) $same[$c] = (string) ($saved[$c] ?? '');
        // Three decimals is the same number as what is saved but never the same TEXT (SQLite hands back 4200,
        // MariaDB 4200.00), so this fails if the comparison is done as text.
        assert_true(is_numeric($same['final_price']), 'an earlier test leaves a price on this case');
        $same['final_price'] = number_format((float) $same['final_price'], 3, '.', '');
        $html = $post('update_final', $same, $stale);
        assert_contains('changed by someone else', $html);
        assert_contains('Nothing you typed differs from what is saved now', $html);
        assert_true(strpos($html, '<ul class="alert-list">') === false, 'no lists when nothing would change');
    });

    run_test('a lock conflict where the typed field was emptied says the saved value would be cleared', function () use ($env, $id, $post, $savedRow, $cols) {
        $original = $savedRow();
        try {
            $env->pdo()->prepare("UPDATE gs_requests SET courier = 'SavedCourier' WHERE id = ?")->execute([$id]);
            $saved = $savedRow();
            $stale = (int) $saved['lock_version'];
            $env->pdo()->prepare('UPDATE gs_requests SET lock_version = lock_version + 1 WHERE id = ?')->execute([$id]);
            $typed = [];
            foreach ($cols as $c) $typed[$c] = (string) ($saved[$c] ?? '');
            $typed['final_price'] = $typed['final_price'] === '' ? '' : number_format((float) $typed['final_price'], 3, '.', '');
            $typed['courier'] = ''; // they cleared it
            $html = $post('update_final', $typed, $stale);
            assert_contains('<li>Courier: “SavedCourier” would be cleared</li>', $html);
            assert_equal(1, substr_count($html, '<li>'), 'only the cleared field is listed');
        } finally {
            $env->pdo()->prepare('UPDATE gs_requests SET courier = ? WHERE id = ?')->execute([$original['courier'], $id]);
        }
    });

    run_test('a lock conflict on the status form keeps the note and chosen status and says what the status is now', function () use ($env, $id, $post, $inputValue, $selectedStatus, $savedRow) {
        $original = $savedRow();
        $stale = (int) $original['lock_version'];
        try {
            $env->pdo()->prepare("UPDATE gs_requests SET status = 'communication_in_progress', lock_version = lock_version + 1 WHERE id = ?")->execute([$id]);
            $html = $post('change_status', ['new_status' => 'compounding', 'status_note' => 'Moving this on after the call.'], $stale);
            assert_contains('changed by someone else', $html);
            assert_contains('The status is now &quot;Communication In Progress&quot;', $html);
            assert_equal('Moving this on after the call.', $inputValue($html, 'status_note'));
            assert_equal('compounding', $selectedStatus($html), 'the status they picked stays selected');
            assert_equal('communication_in_progress', $env->scalar('SELECT status FROM gs_requests WHERE id = ?', [$id]), 'not applied');
        } finally {
            $env->pdo()->prepare('UPDATE gs_requests SET status = ? WHERE id = ?')->execute([$original['status'], $id]);
        }
    });

    run_test('a lock conflict on the assignment form keeps the chosen person and says who it is assigned to now', function () use ($env, $id, $post, $savedRow) {
        $original = $savedRow();
        $stale = (int) $original['lock_version'];
        $adminId = (int) $env->scalar("SELECT id FROM staff_users WHERE email = 'admin@example.test'");
        $env->pdo()->prepare('UPDATE gs_requests SET assigned_staff_id = NULL, lock_version = lock_version + 1 WHERE id = ?')->execute([$id]);
        try {
            $html = $post('assign_staff', ['assigned_staff_id' => $adminId], $stale);
            assert_contains('changed by someone else', $html);
            assert_contains('The case is now unassigned', $html);
            assert_true((bool) preg_match('#<option value="' . $adminId . '" selected>#', $html), 'the person they picked stays selected');
            assert_equal(null, $env->scalar('SELECT assigned_staff_id FROM gs_requests WHERE id = ?', [$id]) ?: null, 'not applied');
        } finally {
            $env->pdo()->prepare('UPDATE gs_requests SET assigned_staff_id = ? WHERE id = ?')->execute([$original['assigned_staff_id'], $id]);
        }
    });

    // ---- A deactivated assignee: shown (labelled), and saving without a change is not an error ----
    // The dropdown always listed the current assignee even if deactivated, but saving it unchanged failed with
    // "Choose an active staff member." and no reason. Now the option says "(deactivated)", an unchanged save is a
    // no-op that tells them to pick someone else, and picking a DIFFERENT deactivated person is still refused.
    run_test('a deactivated assignee is labelled, an unchanged save is a no-op that says why, and nothing is written', function () use ($env, $id, $post, $savedRow) {
        $original = $savedRow();
        $newStaff = function (string $name, string $email, int $active) use ($env): int {
            $env->pdo()->prepare("INSERT INTO staff_users (name, email, password_hash, role, active) VALUES (?, ?, ?, 'pharmacy_staff', ?)")
                ->execute([$name, $email, password_hash('assign-test-password-1', PASSWORD_DEFAULT), $active]);
            // ($env->pdo() is a fresh connection each call, so lastInsertId() would be empty: look it up.)
            return (int) $env->scalar('SELECT id FROM staff_users WHERE email = ?', [$email]);
        };
        $goneId = $newStaff('Gone Assignee', 'gone.assignee@example.test', 0);
        $otherGoneId = $newStaff('Other Gone Person', 'other.gone@example.test', 0);
        $activeId = $newStaff('Active Taker', 'active.taker@example.test', 1);
        $audits = fn (): int => (int) $env->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'case_assigned' AND entity_type = 'gs_request' AND entity_id = ?", [$id]);
        try {
            $env->pdo()->prepare('UPDATE gs_requests SET assigned_staff_id = ? WHERE id = ?')->execute([$goneId, $id]);
            $before = $savedRow();
            $auditsBefore = $audits();

            // The dropdown shows them, selected, with the label; an active person has no label.
            $page = http_request('GET', $env->baseUrl . "/admin/gs-requests/view.php?id={$id}", ['cookie_jar' => $env->cookieJarStaff])['body'];
            assert_true((bool) preg_match('#<option value="' . $goneId . '" selected>Gone Assignee \(deactivated\)</option>#', $page), 'the deactivated assignee is selected and labelled');
            assert_true((bool) preg_match('#<option value="' . $activeId . '" >Active Taker</option>#', $page), 'an active person has no label');
            assert_true(strpos($page, 'Other Gone Person') === false, 'a deactivated person who is NOT the assignee is not offered');

            // Save without changing anything: not an error about "active", an explanation instead; nothing written.
            $same = $post('assign_staff', ['assigned_staff_id' => $goneId]);
            assert_contains('Assignment unchanged. Gone Assignee is deactivated, so choose someone else to take this case.', $same);
            assert_true(strpos($same, 'Choose an active staff member') === false, 'no confusing error');
            assert_equal($before, $savedRow(), 'an unchanged save writes nothing (the version does not move)');
            assert_equal($auditsBefore, $audits(), 'and adds no audit entry');

            // A crafted request naming a DIFFERENT deactivated person is still refused.
            $crafted = $post('assign_staff', ['assigned_staff_id' => $otherGoneId]);
            assert_contains('Choose an active staff member.', $crafted);
            assert_equal($before, $savedRow());

            // The no-op only applies when the page is up to date. With an out-of-date page the deactivated person is
            // simply not a valid choice any more.
            assert_contains('Choose an active staff member.', $post('assign_staff', ['assigned_staff_id' => $goneId], (int) $before['lock_version'] - 1));
            assert_equal($before, $savedRow());

            // Reassigning to an active person works, bumps the version once and is audited.
            $moved = $post('assign_staff', ['assigned_staff_id' => $activeId]);
            assert_contains('Assignment updated.', $moved);
            $after = $savedRow();
            assert_equal($activeId, (int) $after['assigned_staff_id']);
            assert_equal((int) $before['lock_version'] + 1, (int) $after['lock_version']);
            assert_equal($auditsBefore + 1, $audits());

            // An "unchanged" save from an out-of-date page is a conflict, not a silent no-op: someone else touched
            // the case since the page loaded (the lock_version in the form is older than the saved one).
            $stale = $post('assign_staff', ['assigned_staff_id' => $activeId], (int) $after['lock_version'] - 1);
            assert_contains('changed by someone else', $stale);
            assert_equal($after, $savedRow(), 'the conflicting save changed nothing');

            // Saving that active person unchanged is the friendly no-op too, with no "deactivated" warning.
            $again = $post('assign_staff', ['assigned_staff_id' => $activeId]);
            assert_contains('Assignment unchanged.', $again);
            assert_true(strpos($again, 'deactivated') === false);
            assert_equal($after, $savedRow());
            // And so is leaving a case unassigned.
            $env->pdo()->prepare('UPDATE gs_requests SET assigned_staff_id = NULL WHERE id = ?')->execute([$id]);
            assert_contains('Assignment unchanged.', $post('assign_staff', ['assigned_staff_id' => '']));
        } finally {
            $env->pdo()->prepare('UPDATE gs_requests SET assigned_staff_id = ? WHERE id = ?')->execute([$original['assigned_staff_id'], $id]);
            $env->pdo()->exec("DELETE FROM staff_users WHERE email IN ('gone.assignee@example.test', 'other.gone@example.test', 'active.taker@example.test')");
        }
    });
};
