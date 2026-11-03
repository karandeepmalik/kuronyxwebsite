<?php
// slugify() moved out of admin/dispatches/edit.php into includes/slug.php (a function defined inside a page
// is a fatal "cannot redeclare" error the moment a second file includes that page or defines the same
// name), and the dispatch pages' last inline styles became shared classes.
return function (TestEnv $env): void {
    run_test('slugify() turns titles into URL-safe slugs and never returns an empty one', function () use ($env) {
        // No side effects: the file only defines the function (and refuses to run if requested directly).
        require_once $env->root . '/public_html/includes/slug.php';

        $cases = [
            'A New Oral Formulation'      => 'a-new-oral-formulation',
            'Dosing & Storage Notes'      => 'dosing-storage-notes',
            '  Leading and trailing  '    => 'leading-and-trailing',
            'Already-a-slug'              => 'already-a-slug',
            'UPPER lower 123'             => 'upper-lower-123',
            "Tabs\tand\nnewlines"         => 'tabs-and-newlines',
            'Many   ---   separators'     => 'many-separators',
            "O'Brien's \"quoted\" note"   => 'o-brien-s-quoted-note',
            'Café au lait'                => 'caf-au-lait',   // non a-z characters are dropped, not transliterated
            ''                            => 'dispatch',       // nothing left: a fallback, never an empty slug
            '   '                         => 'dispatch',
            '!!!???'                      => 'dispatch',
            'अगला अध्याय'                  => 'dispatch',       // a title wholly in a non-Latin script
        ];
        foreach ($cases as $input => $expected) {
            assert_equal($expected, slugify((string) $input), 'slugify(' . json_encode((string) $input, JSON_UNESCAPED_UNICODE) . ')');
        }
        // Idempotent: re-slugifying a slug changes nothing (the edit page does this to an already-saved slug).
        foreach (['a-new-oral-formulation', 'dispatch', 'x-1-y'] as $slug) {
            assert_equal($slug, slugify($slug));
        }
    });

    run_test('slugify() is defined in exactly one place, includes/slug.php, and that file cannot be requested directly', function () use ($env) {
        $defs = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($env->root . '/public_html', FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() === 'php' && preg_match('/^\s*function\s+slugify\s*\(/m', file_get_contents($file->getPathname()))) {
                $defs[] = str_replace('\\', '/', substr($file->getPathname(), strlen($env->root) + 1));
            }
        }
        assert_equal(['public_html/includes/slug.php'], $defs, 'a second definition anywhere would be a redeclare crash');

        assert_equal(403, http_request('GET', $env->baseUrl . '/includes/slug.php', ['cookie_jar' => $env->tmpDir . '/cookies-slug-direct.txt'])['status']);
    });

    run_test('the dispatch pages use shared classes, not inline styles, and still render their controls', function () use ($env) {
        $header = file_get_contents($env->root . '/public_html/includes/layout-header.php');
        foreach (['.form-actions{', '.danger-zone{', '.page-action{'] as $rule) {
            assert_contains($rule, $header, "{$rule} must be defined in the shared stylesheet");
        }
        // (The staff page has other inline styles of its own that this change did not touch; only its
        // "New Staff Account" button shares the class.)
        foreach (['/admin/dispatches/edit.php', '/admin/dispatches/index.php'] as $page) {
            assert_true(strpos(file_get_contents($env->root . '/public_html' . $page), 'style="') === false, "{$page} has no inline style=\"\"");
        }

        // The rendered pages carry the classes on the real elements.
        $jar = $env->cookieJarStaff;
        $list = http_request('GET', $env->baseUrl . '/admin/dispatches/', ['cookie_jar' => $jar])['body'];
        assert_true((bool) preg_match('#<a href="/admin/dispatches/edit\.php" class="btn-primary page-action">New issue</a>#', $list), 'the New issue button');
        $staff = http_request('GET', $env->baseUrl . '/admin/staff/', ['cookie_jar' => $jar])['body'];
        assert_true((bool) preg_match('#class="btn-primary page-action">New Staff Account</a>#', $staff), 'the New Staff Account button');

        $id = (int) $env->scalar('SELECT id FROM dispatches ORDER BY id LIMIT 1');
        assert_true($id > 0);
        $edit = http_request('GET', $env->baseUrl . "/admin/dispatches/edit.php?id={$id}", ['cookie_jar' => $jar])['body'];
        assert_contains('<div class="form-actions">', $edit);
        assert_true((bool) preg_match('#<form method="POST" action="[^"]*" class="danger-zone"#', $edit), 'the delete form sits in the danger zone');
        assert_contains('Delete issue', $edit);
        $blank = http_request('GET', $env->baseUrl . '/admin/dispatches/edit.php', ['cookie_jar' => $jar])['body'];
        assert_contains('<div class="form-actions">', $blank);
        assert_true(strpos($blank, 'class="danger-zone"') === false, 'a new dispatch has nothing to delete, so no danger-zone form (the word is in the stylesheet on every page, so check the element)');
    });
};
