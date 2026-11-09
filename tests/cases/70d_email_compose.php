<?php
// The admin email composer (gs-requests and veterinary-applications) used to REPLACE the typed subject and message the moment
// staff picked another template: no warning, no way back, and "Blank" cleared the message (a Karandeep review comment).
// js/admin-email-compose.js now keeps the last template's text, asks inline (not confirm(), which a browser can switch off)
// when the boxes were edited, and offers Undo after a replace. The logic runs under Node against a tiny pretend page.
return function (TestEnv $env): void {
    run_test('the composer asks before replacing an edited message, offers Undo, and never wipes a kept draft (runs the script under Node)', function () use ($env) {
        $node = trim((string) @shell_exec('node --version 2>&1'));
        if (!preg_match('/^v\d+/', $node)) skip_test('Node.js is not installed here: the script\'s logic is not exercised (check it in a browser instead)');

        $out = (string) shell_exec('node ' . escapeshellarg($env->root . '/tests/lib/email_compose_test.js') . ' 2>&1');
        $results = json_decode($out, true);
        assert_true(is_array($results), "the Node test did not return JSON: {$out}");
        assert_true(count($results) >= 15, 'expected every scenario to be reported');
        $failed = array_filter($results, fn ($r) => !$r['ok']);
        assert_equal([], array_values(array_map(fn ($r) => $r['name'] . ' -> ' . $r['detail'], $failed)), 'these scenarios fail');
    });

    run_test('both composer pages load the script, and it never uses confirm() (a browser can switch those dialogs off)', function () use ($env) {
        foreach (['admin/gs-requests/view.php', 'admin/veterinary-applications/view.php'] as $rel) {
            $src = file_get_contents($env->root . '/public_html/' . $rel);
            assert_contains('<script src="/js/admin-email-compose.js" defer></script>', $src, "{$rel} must load the composer script");
            assert_contains('id="email-template"', $src);
            assert_contains('id="email-subject"', $src);
            assert_contains('id="email-body"', $src);
        }
        $js = file_get_contents($env->root . '/public_html/js/admin-email-compose.js');
        assert_true(strpos($js, 'confirm(') === false, 'the script must not use confirm()');
    });
};
