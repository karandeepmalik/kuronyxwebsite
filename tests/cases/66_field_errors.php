<?php
// Forms that set `$errors['field'] = 'message'` must also PRINT the message under the field. Several (new staff
// account, both password resets, vet activation, first-admin setup) set the messages and only coloured the label,
// so the user saw a red box and had to guess. includes/form-errors.php is the one place that renders it; these
// tests cover the helper, the rendered staff form, and - statically - that no page sets a message it never shows.
return function (TestEnv $env): void {
    // Loading the helper must print nothing. (A PHP closing tag inside a // comment ends PHP mode and dumps the rest
    // of the comment into every page that includes the file - it did, once, in a usage example.)
    ob_start();
    require_once $env->root . '/public_html/includes/form-errors.php';
    $printedOnLoad = ob_get_clean();

    run_test('the helper file prints nothing when loaded and has no PHP closing tag (a closing tag in a comment leaks the rest of it into every page)', function () use ($env, $printedOnLoad) {
        assert_equal('', $printedOnLoad, 'form-errors.php printed output when loaded');
        assert_true(strpos(file_get_contents($env->root . '/public_html/includes/form-errors.php'), '?' . '>') === false, 'form-errors.php must not contain a PHP closing tag');
    });

    $pages = [
        'admin/staff/new.php', 'admin/reset-password.php', 'for-veterinarians/reset-password.php',
        'for-veterinarians/activate.php', 'admin/dispatches/edit.php', 'admin/veterinary-applications/view.php',
        // The public intake forms print their messages too (a worried cat owner needs them most).
        'for-cat-owners/index.php', 'for-veterinarians/apply/index.php', 'for-veterinarians/portal/new-request.php',
    ];

    run_test('the helper prints a message, marks the label and the input, and is silent for a field with no error', function () {
        $errors = ['email' => 'Enter a valid email address'];
        assert_equal('field has-error', field_class($errors, 'email'));
        assert_equal('field', field_class($errors, 'name'));
        assert_equal(' aria-invalid="true" aria-describedby="err-email"', field_error_attrs($errors, 'email'));
        assert_equal('', field_error_attrs($errors, 'name'));
        assert_equal('<span class="field-error" id="err-email">Enter a valid email address</span>', field_error_html($errors, 'email'));
        assert_equal('', field_error_html($errors, 'name'));
        assert_equal('', field_error_html([], 'email'));
    });

    run_test('old_value() refills a field with what was typed, escaped, and is empty when there is nothing; the forms share it instead of carrying their own copies', function () use ($env) {
        assert_equal('', old_value([], 'name'));
        assert_equal('', old_value(['name' => null], 'name'));
        assert_equal('Asha', old_value(['name' => 'Asha'], 'name'));
        assert_equal('&lt;b&gt;&quot;x&quot; &amp; &#039;y&#039;', old_value(['name' => '<b>"x" & \'y\''], 'name'));
        // The cat-owner form, the vet application and the vet-portal request each defined their own copy (ov() twice,
        // old_val() once, fc() twice) under different names. A page-local helper of that kind is a copy waiting to drift.
        $dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($env->root . '/public_html', FilesystemIterator::SKIP_DOTS));
        foreach ($dir as $file) {
            if ($file->getExtension() !== 'php') continue;
            $src = file_get_contents($file->getPathname());
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($env->root . '/public_html') + 1));
            assert_true(!preg_match('/function\s+(ov|old_val|fc)\s*\(/', $src), "{$rel} defines its own ov()/old_val()/fc(): use old_value() and field_class() from includes/form-errors.php");
        }
        foreach (['for-cat-owners/index.php', 'for-veterinarians/apply/index.php', 'for-veterinarians/portal/new-request.php'] as $rel) {
            $src = file_get_contents($env->root . '/public_html/' . $rel);
            assert_contains('old_value($old,', $src, "{$rel} must refill its fields with old_value()");
            assert_true(!preg_match('/(?<![A-Za-z0-9_])(ov|old_val)\(/', $src), "{$rel} still calls a private copy of the refill helper");
        }
    });

    run_test('the helper escapes the message, so a value that ends up in it can never inject markup', function () {
        $html = field_error_html(['name' => '<script>alert(1)</script> "quoted" \'single\''], 'name');
        assert_true(strpos($html, '<script>') === false, 'the message is escaped');
        assert_contains('&lt;script&gt;alert(1)&lt;/script&gt; &quot;quoted&quot; &#039;single&#039;', $html);
    });

    run_test('the new-staff form shows a message under every field that is wrong, and only those', function () use ($env) {
        $jar = $env->cookieJarStaff;
        $get = http_request('GET', $env->baseUrl . '/admin/staff/new.php', ['cookie_jar' => $jar]);
        // A clean form shows no error at all.
        foreach (['name', 'email'] as $f) {
            assert_no_field_error($get['body'], $f, 'a fresh form');
        }
        assert_true(strpos($get['body'], 'aria-invalid') === false, 'a fresh form marks nothing invalid');

        $post = http_request('POST', $env->baseUrl . '/admin/staff/new.php', ['cookie_jar' => $jar, 'body' => [
            'csrf_token' => extract_csrf($get['body']), 'ott' => extract_ott($get['body']), 'name' => '', 'email' => 'not-an-email', 'role' => 'admin',
        ]]);
        assert_equal(200, $post['status']);
        assert_field_error($post['body'], 'name', 'Required', 'staff/new.php');
        assert_field_error($post['body'], 'email', 'Enter a valid email address', 'staff/new.php');
        // Typed values come back, including the chosen role.
        assert_contains('value="not-an-email"', $post['body']);
        assert_true((bool) preg_match('/<option value="admin" selected>/', $post['body']), 'the chosen role is kept');
        // The label of a wrong field is highlighted, the label of a fine one is not.
        assert_true((bool) preg_match('/<label class="field has-error">\s*<span class="lbl">Name/', $post['body']), 'Name label highlighted');
        assert_true((bool) preg_match('/<label class="field">\s*<span class="lbl">Role/', $post['body']), 'Role label not highlighted');
        assert_equal(0, (int) $env->scalar("SELECT COUNT(*) FROM staff_users WHERE email = 'not-an-email'"));
    });

    run_test('no page sets a field message it never prints: every $errors[\'field\'] key is rendered with field_error_html()', function () use ($env, $pages) {
        foreach ($pages as $rel) {
            $src = file_get_contents($env->root . '/public_html/' . $rel);
            assert_true((bool) preg_match_all("/\\\$errors\\['([a-z_]+)'\\]\\s*=/", $src, $m), "{$rel}: expected to set field errors");
            foreach (array_unique($m[1]) as $key) {
                if ($key === '_form') continue; // the form-wide banner is printed as its own alert
                assert_contains("field_error_html(\$errors, '{$key}')", $src, "{$rel} sets \$errors['{$key}'] but never prints it");
                assert_contains("field_error_attrs(\$errors, '{$key}')", $src, "{$rel}: the '{$key}' input is not marked aria-invalid");
                assert_contains("field_class(\$errors, '{$key}'", $src, "{$rel}: the '{$key}' label is not highlighted");
            }
            assert_true(strpos($src, "'has-error' : ''") === false, "{$rel} hand-rolls the has-error class again (use field_class())");
        }
        // Fields whose key is chosen at run time (the $required loop, the length checks) are invisible to the scan above: every
        // label that is highlighted must also print its message, and every required key must have such a label.
        foreach ($pages as $rel) {
            $src = file_get_contents($env->root . '/public_html/' . $rel);
            preg_match_all('/field_class\(\$errors, \'([a-z_]+)\'/', $src, $labels);
            foreach (array_unique($labels[1]) as $key) {
                assert_contains("field_error_html(\$errors, '{$key}')", $src, "{$rel}: the '{$key}' label is highlighted but its message is never printed");
            }
            if (preg_match('/\$required = \[(.*?)\];/s', $src, $req)) {
                preg_match_all("/'([a-z_]+)'/", $req[1], $keys);
                foreach ($keys[1] as $key) {
                    assert_contains("field_class(\$errors, '{$key}'", $src, "{$rel}: '{$key}' is required but has no highlighted, message-printing label");
                }
            }
        }
        // tools/setup.php is not under public_html (never deployed) but is the same form.
        $setup = file_get_contents($env->root . '/tools/setup.php');
        foreach (['name', 'email', 'password', 'password_confirm'] as $key) {
            assert_contains("field_error_html(\$errors, '{$key}')", $setup, "tools/setup.php sets \$errors['{$key}'] but never prints it");
        }
    });
};
