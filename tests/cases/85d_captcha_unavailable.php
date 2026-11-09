<?php
// If Cloudflare's Turnstile script cannot load (an ad blocker or privacy extension, a clinic network, a flaky connection) no
// widget ever appears, and the server refuses the form without a token. js/captcha.js used to switch `enabled` on BEFORE the
// script had loaded, so the visitor met "Please complete the verification check" for a check they could not see, for good
// (a Karandeep review comment). Now it is only enabled once the widget is really there; a failed, silent or fake script, or a
// widget error, shows "Verification couldn't load. Please email hello@kuronyx.in directly." and the submit handlers stop on
// KuronyxCaptcha.blocked. The script runs under Node against a pretend page; the two forms are checked statically.
return function (TestEnv $env): void {
    run_test('the check is only enabled once Cloudflare\'s script has loaded; failures show the way forward (runs js/captcha.js under Node)', function () use ($env) {
        $node = trim((string) @shell_exec('node --version 2>&1'));
        if (!preg_match('/^v\d+/', $node)) skip_test('Node.js is not installed here: the script\'s logic is not exercised (check it in a browser instead)');

        $out = (string) shell_exec('node ' . escapeshellarg($env->root . '/tests/lib/captcha_test.js') . ' 2>&1');
        $results = json_decode($out, true);
        assert_true(is_array($results), "the Node test did not return JSON: {$out}");
        assert_true(count($results) >= 12, 'expected every scenario to be reported');
        $failed = array_filter($results, fn ($r) => !$r['ok']);
        assert_equal([], array_values(array_map(fn ($r) => $r['name'] . ' -> ' . $r['detail'], $failed)), 'these scenarios fail');
    });

    run_test('both forms stop on KuronyxCaptcha.blocked before sending, and say so in their own status line (static check)', function () use ($env) {
        $home = file_get_contents($env->root . '/public_html/index.html');
        $sub  = file_get_contents($env->root . '/public_html/js/subscribe.js');
        foreach (['index.html' => $home, 'js/subscribe.js' => $sub] as $file => $src) {
            // The real guard (an `if` on the property), not just the word: the message line below also contains it.
            assert_true((bool) preg_match('/if \(window\.KuronyxCaptcha && KuronyxCaptcha\.blocked\)\s*\{/', $src, $m, PREG_OFFSET_CAPTURE), "{$file} must stop when KuronyxCaptcha.blocked is set");
            $at = $m[0][1];
            $send = strpos($src, 'KuronyxPost.send(');
            assert_true($send !== false && $at < $send, "{$file}: the blocked check must come before the request is sent");
            assert_true(strpos($src, '.textContent = KuronyxCaptcha.blocked') !== false, "{$file}: the message must be shown in the form's status line");
        }
        // The script is still loaded where the forms are, before the form scripts that read it.
        assert_true(strpos($home, 'js/captcha.js') !== false);
        assert_true(strpos($home, 'js/captcha.js') < strpos($home, 'js/subscribe.js'), 'captcha.js loads before subscribe.js');
    });
};
