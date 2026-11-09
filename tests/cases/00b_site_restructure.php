<?php
// The site was restructured from the copy document (Kuronyx Website Copy, 6 Sept 2026): a six-item nav on every page, no separate
// Contact page (Contact is the Write to us form on Home), Dispatches became the Journal, a Pet Parents landing page in front of
// the (unchanged) prescription form, and the molecule name only behind gated surfaces. The old addresses must keep working.
return function (TestEnv $env): void {
    $get = fn (string $path, array $o = []) => http_request('GET', $env->baseUrl . $path, $o + ['cookie_jar' => $env->tmpDir . '/cookies-restructure.txt']);

    // ------------------------------------------------------------------ redirects
    run_test('old addresses redirect permanently to where the page went: Dispatches -> Journal, Contact Us -> the Home form', function () use ($get) {
        $cases = [
            '/dispatches/'                      => '/journal/',
            '/dispatches/index.php'             => '/journal/',
            '/dispatches/view.php'              => '/journal/',
            '/dispatches/view.php?slug=abc-def' => '/journal/view.php?slug=abc-def',
            '/dispatches/view.php?slug=' . urlencode('a b&c') => '/journal/view.php?slug=' . urlencode('a b&c'),
            '/dispatches/view.php?slug[]=x'     => '/journal/',   // an array-valued slug must not crash the redirect
            '/contact-us/'                      => '/#enquiry',
            '/contact-us/index.php'             => '/#enquiry',
        ];
        foreach ($cases as $from => $to) {
            $r = $get($from);
            assert_equal(301, $r['status'], "{$from} must be a PERMANENT redirect");
            assert_equal($to, $r['location'] ?? '', "{$from} must redirect to {$to}");
        }
    });

    run_test('an old article link lands on the same article in the Journal (a real published issue, followed like a browser would)', function () use ($env, $get) {
        $env->pdo()->prepare("INSERT INTO dispatches (title, slug, excerpt, body, status, published_at) VALUES ('Redirect Test Issue', 'redirect-test-issue', 'Summary line', 'Body text of the redirect test.', 'published', ?)")->execute([gmdate('Y-m-d H:i:s')]);
        $r = $get('/dispatches/view.php?slug=redirect-test-issue', ['follow' => true]);
        assert_equal(200, $r['status']);
        assert_contains('Redirect Test Issue', $r['body']);
        assert_contains('Body text of the redirect test.', $r['body']);
        $env->pdo()->exec("DELETE FROM dispatches WHERE slug = 'redirect-test-issue'");
    });

    run_test('.htaccess repeats every redirect at the server (the PHP files above are the fallback), after the https rule and with the right flags', function () use ($env) {
        $h = file_get_contents($env->root . '/public_html/.htaccess');
        $https = strpos($h, 'RewriteCond %{HTTPS} !=on');
        foreach ([
            'RewriteRule ^dispatches/?$ /journal/ [R=301,L]',
            'RewriteRule ^dispatches/index\.php$ /journal/ [R=301,L]',
            'RewriteRule ^dispatches/view\.php$ /journal/view.php [R=301,L,QSA]', // QSA: the article slug travels with it
            'RewriteRule ^contact-us/?$ /#enquiry [R=301,L,NE]',                  // NE: the # must not be escaped
            'RewriteRule ^contact-us/index\.php$ /#enquiry [R=301,L,NE]',
            'RewriteRule ^Privacy\ Policy\.html$ /privacy-policy/ [R=301,L]',    // the escaped space: the old file name has one
        ] as $rule) {
            $at = strpos($h, $rule);
            assert_true($at !== false, ".htaccess is missing: {$rule}");
            assert_true($at > $https, "{$rule} must come after the https rule so http://old-address is not a loop of redirects");
        }
    });

    run_test('the old pages are only redirects now: nothing of the old contact form or dispatch list is left behind', function () use ($env) {
        foreach (['contact-us/index.php', 'dispatches/index.php', 'dispatches/view.php'] as $rel) {
            $src = file_get_contents($env->root . '/public_html/' . $rel);
            assert_true(strlen($src) < 700, "{$rel} should be a small redirect file");
            assert_contains('301', $src);
        }
    });

    // ------------------------------------------------------------------ nav and footer
    $layoutPages = ['/about/index.php', '/for-veterinarians/index.php', '/pet-parents/index.php', '/for-cat-owners/index.php', '/journal/index.php',
        '/for-veterinarians/apply/index.php', '/for-veterinarians/login.php', '/for-veterinarians/forgot-password.php'];
    $labels = ['Home', 'About', 'Veterinarians', 'Pet Parents', 'Journal', 'Contact'];
    $anchors = function (string $html, string $navOpen): array {
        assert_true((bool) preg_match('#' . $navOpen . '(.*?)</nav>#s', $html, $nav), 'no primary nav found');
        preg_match_all('#<a href="([^"]+)"[^>]*>([^<]+)</a>#', $nav[1], $m, PREG_SET_ORDER);
        return array_map(fn ($a) => [$a[1], $a[2]], $m);
    };

    run_test('every page carries the same six-item nav (Home, About, Veterinarians, Pet Parents, Journal, Contact) and a Privacy Policy link in the footer', function () use ($get, $layoutPages, $labels, $anchors) {
        foreach ($layoutPages as $path) {
            $html = $get($path)['body'];
            $a = $anchors($html, '<nav class="site-nav" aria-label="Primary">');
            assert_equal($labels, array_column($a, 1), "{$path}: nav labels");
            assert_equal(['/', '/about/', '/for-veterinarians/', '/pet-parents/', '/journal/', '/#enquiry'], array_column($a, 0), "{$path}: nav addresses (Contact is the Home form)");
            assert_true(strpos($html, 'href="/privacy-policy/">Privacy Policy</a>') !== false, "{$path}: footer must link the Privacy Policy");
            assert_true(strpos($html, 'contact-us') === false && strpos($html, '/dispatches/') === false, "{$path}: no link to a page that moved or went away");
        }
    });

    run_test('Home has the same six labels, with Home and Contact as links on the page itself, and still links the Privacy Policy', function () use ($env, $labels, $anchors) {
        $html = file_get_contents($env->root . '/public_html/index.html');
        $a = $anchors($html, '<nav class="meta site-nav" aria-label="Primary">');
        assert_equal($labels, array_column($a, 1));
        assert_equal(['#hero', '/about/', '/for-veterinarians/', '/pet-parents/', '/journal/', '#enquiry'], array_column($a, 0));
        assert_contains('Privacy Policy</a>', $html);
        assert_contains('id="enquiry"', $html);
    });

    run_test('the right nav item is highlighted: Pet Parents for the landing page AND the form, Journal for the list and an issue', function () use ($env, $get) {
        assert_contains('<a href="/pet-parents/" class="active">Pet Parents</a>', $get('/pet-parents/index.php')['body']);
        assert_contains('<a href="/pet-parents/" class="active">Pet Parents</a>', $get('/for-cat-owners/index.php')['body']);
        assert_contains('<a href="/journal/" class="active">Journal</a>', $get('/journal/index.php')['body']);
        assert_contains('<a href="/for-veterinarians/" class="active">Veterinarians</a>', $get('/for-veterinarians/index.php')['body']);
        assert_contains('<a href="/about/" class="active">About</a>', $get('/about/index.php')['body']);
    });

    // ------------------------------------------------------------------ the pages
    run_test('About: the new copy replaces the old, the registrations table stays, the meta text is the document\'s', function () use ($get) {
        $b = $get('/about/index.php')['body'];
        assert_contains('Kuronyx did not begin as a company. It began as a gap.', $b);
        assert_contains('We are not loud about this.', $b);
        assert_contains('U21000MH2025PTC456216', $b); // CIN, in the registrations table
        assert_contains('<title>About Kuronyx | Veterinary-Only Compounding Pharmacy, India</title>', $b);
        assert_contains(htmlspecialchars("India's first veterinary-only compounding pharmacy, focused on complex feline conditions including FIP.", ENT_QUOTES), $b);
        foreach (['dark matter', 'Why this matters', 'Get in touch', 'Contact Us'] as $old) assert_true(stripos($b, $old) === false, "the old About text '{$old}' must be gone");
    });

    run_test('Veterinarians: the new copy, Apply to register and Log in point at the existing pages; no molecule, formulations, prices or FDA links', function () use ($get) {
        $b = $get('/for-veterinarians/index.php')['body'];
        assert_contains('Kuronyx works with veterinarians. Not around them.', $b);
        assert_contains('<a class="btn-primary" href="/for-veterinarians/apply/">Apply to register</a>', $b);
        assert_contains('Already registered? <a class="btn-secondary" href="/for-veterinarians/login.php">Log in</a>', $b);
        assert_contains('<title>For Veterinarians | Kuronyx Veterinary Compounding Pharmacy India</title>', $b);
        foreach (['GS-441524', 'injection', 'fixed prices', 'fda.gov', 'Prescription required', 'Available formulations'] as $old) assert_true(stripos($b, $old) === false, "'{$old}' must be gone from the Veterinarians page");
    });

    run_test('Pet Parents: a short landing page whose button goes to the form', function () use ($get) {
        $b = $get('/pet-parents/index.php')['body'];
        assert_contains('Your veterinarian has written a prescription. Bring it here.', $b);
        assert_contains('<a class="btn-primary" href="/for-cat-owners/">Upload the prescription</a>', $b);
        assert_contains('Nothing is prepared, and nothing is charged, until it is confirmed.', $b);
        assert_contains('<title>For Pet Parents | Prescription Compounding for Cats | Kuronyx India</title>', $b);
        assert_true(strpos($b, '<form') === false, 'the landing page has no form of its own');
    });

    run_test('the prescription form page is only the form: one heading, a Back link, hidden from search, and every field still there', function () use ($get) {
        $b = $get('/for-cat-owners/index.php')['body'];
        assert_contains('<h1 class="doc-title">Upload the prescription</h1>', $b);
        assert_contains('<a class="back" href="/pet-parents/"', $b);
        assert_contains('Back to Pet Parents', $b);
        assert_contains('<meta name="robots" content="noindex, nofollow">', $b);
        assert_contains('<title>Upload the prescription | Kuronyx</title>', $b);
        foreach (['GS-441524', 'Prescription required', '<p class="doc-eyebrow">', '<p class="doc-meta">', '<p class="lead">', 'You do not need to create an account', '<span class="notice-label">'] as $old) assert_true(strpos($b, $old) === false, "'{$old}' must be gone from the form page");
        // the form itself is untouched
        foreach (['owner_full_name', 'owner_email', 'owner_phone', 'owner_address', 'patient_name', 'vet_name', 'vet_clinic', 'vet_email', 'vet_phone', 'requested_formulation', 'name="prescription"', 'name="consent"', 'name="supporting_1"'] as $field) {
            assert_contains($field, $b, "the form lost {$field}");
        }
    });

    // ------------------------------------------------------------------ the molecule rule
    $publicPaths = array_merge(['/index.html', '/php/unsubscribe.php', '/sitemap.xml'], $layoutPages, ['/for-veterinarians/reset-password.php', '/for-veterinarians/activate.php']);
    run_test('no public page names the molecule (the Privacy Policy and Journal issues are the only exceptions)', function () use ($get, $publicPaths) {
        foreach ($publicPaths as $path) {
            $b = $get($path)['body'];
            assert_true(stripos($b, 'GS-441524') === false && stripos($b, 'GS441524') === false, "{$path} names the molecule");
        }
    });

    run_test('Home: the head carries FIP once in the description and once in the structured data, and no molecule, no price range; visible copy has neither', function () use ($env) {
        $html = file_get_contents($env->root . '/public_html/index.html');
        [$head, $body] = explode('</head>', $html, 2);
        assert_true((bool) preg_match('#<meta name="description" content="([^"]*)">#', $head, $d));
        assert_contains('feline infectious peritonitis (FIP)', $d[1]);
        assert_true((bool) preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $head, $j));
        $ld = json_decode($j[1], true);
        assert_true(is_array($ld), 'JSON-LD must be valid');
        assert_equal(1, count(array_filter($ld['knowsAbout'], fn ($k) => stripos($k, 'peritonitis') !== false)), 'FIP once in the structured data');
        assert_true(!array_key_exists('priceRange', $ld), 'no price range in the structured data');
        assert_true(stripos($head, 'GS-441524') === false, 'no molecule in the head');
        foreach (['<title>', 'og:title', 'og:description', 'twitter:title', 'twitter:description'] as $tag) {
            preg_match('#' . preg_quote($tag, '#') . '[^\n]*#', $head, $line);
            assert_true(stripos($line[0] ?? '', 'peritonitis') === false, "FIP appears once, in the description, not also in {$tag}");
        }
        assert_true(stripos($body, 'peritonitis') === false && stripos($body, 'GS-441524') === false, 'the visible Home copy has neither');
        assert_contains('<title>Kuronyx Sciences | Veterinary Compounding Pharmacy, Mumbai, India</title>', $head);
    });

    run_test('Home section 06 and the unsubscribe page say Journal, not Dispatches', function () use ($env) {
        $html = file_get_contents($env->root . '/public_html/index.html');
        assert_contains('<div class="heading">Journal · subscribe</div>', $html);
        assert_contains('A clinical education series for veterinarians.', $html);
        assert_true(strpos($html, 'Field dispatches') === false && strpos($html, 'Occasional dispatches') === false, 'no Dispatches wording left on Home');
        $un = file_get_contents($env->root . '/public_html/php/unsubscribe.php');
        assert_contains('Journal emails', $un);
        assert_true(strpos($un, 'Field Dispatches') === false);
    });

    // ------------------------------------------------------------------ style rules
    $visibleText = function (string $html): string {
        $html = preg_replace('#<(script|style)\b.*?</\1>#si', '', $html);
        $html = preg_replace('#<!--.*?-->#s', '', $html);
        return $html;
    };
    run_test('no em dash is visible on any public page, in its title, meta text or structured data', function () use ($env, $get, $publicPaths, $visibleText) {
        foreach (array_merge($publicPaths, ['/journal/view.php?slug=nothing-here']) as $path) {
            $html = $path === '/index.html' ? file_get_contents($env->root . '/public_html/index.html') : $get($path)['body'];
            assert_true(strpos($visibleText($html), '—') === false, "{$path} shows an em dash");
            preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $blocks);
            foreach ($blocks[1] as $block) assert_true(strpos($block, '—') === false, "{$path}: an em dash in the structured data");
        }
        $policy = file_get_contents($env->root . '/public_html/Privacy Policy.html');
        assert_true(strpos($visibleText($policy), '—') === false, 'the Privacy Policy shows an em dash');
        assert_true(strpos(preg_replace('#<!--.*?-->#s', '', $policy), 'content: "—"') === false, 'and so does its CSS content');
    });

    run_test('no source file puts an em dash into text a person can see (code comments are exempt)', function () use ($env) {
        $files = [$env->root . '/tools/setup.php'];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($env->root . '/public_html', FilesystemIterator::SKIP_DOTS)) as $f) {
            if (in_array($f->getExtension(), ['php', 'html', 'js'], true)) $files[] = $f->getPathname();
        }
        $offenders = [];
        foreach ($files as $file) {
            $inBlock = false;
            foreach (explode("\n", str_replace("\r\n", "\n", file_get_contents($file))) as $n => $line) {
                $trim = ltrim($line);
                if ($inBlock) { if (strpos($line, '*/') !== false || strpos($line, '-->') !== false) $inBlock = false; continue; }
                if (preg_match('#^(//|\*|/\*|\#\s|<!--)#', $trim)) {
                    if ((strpos($trim, '/*') === 0 && strpos($line, '*/') === false) || (strpos($trim, '<!--') === 0 && strpos($line, '-->') === false)) $inBlock = true;
                    continue;
                }
                $cut = strlen($line);
                foreach (['#(?<![:\'"\w])//#', '#<!--#', '#/\*#'] as $re) {
                    if (preg_match($re, $line, $m, PREG_OFFSET_CAPTURE)) $cut = min($cut, $m[0][1]);
                }
                if (strpos(substr($line, 0, $cut), '—') !== false) $offenders[] = str_replace($env->root . DIRECTORY_SEPARATOR, '', $file) . ':' . ($n + 1);
            }
        }
        assert_equal([], $offenders, "visible text with an em dash (use a plain hyphen):\n  - " . implode("\n  - ", $offenders));
    });

    run_test('the vet-facing pages say Log in, not Sign in, and use sentence case on their headings and buttons', function () use ($get) {
        $login = $get('/for-veterinarians/login.php')['body'];
        assert_contains('<h1 class="doc-title">Log in</h1>', $login);
        assert_contains('<title>Log in | Kuronyx</title>', $login);
        assert_true(stripos($login, 'sign in') === false, 'the login page must not say Sign in');
        $apply = $get('/for-veterinarians/apply/index.php')['body'];
        assert_contains('<h1 class="doc-title">Apply for a veterinary account</h1>', $apply);
        assert_contains('<title>Apply for a veterinary account | Kuronyx</title>', $apply);
        assert_contains('Submit application</button>', $apply);
        assert_true(stripos($apply, 'GS-441524') === false);
    });
    run_test('the Write to us form says what the message is used for, with a Privacy Policy link, right above its button', function () use ($env) {
        // A Karandeep review comment: vets type patient and owner details into the message box.
        $html = file_get_contents($env->root . '/public_html/index.html');
        $form = substr($html, (int) strpos($html, 'id="enquiryForm"'));
        $form = substr($form, 0, (int) strpos($form, '</form>'));
        assert_contains('<p class="privacy-note">We\'ll use this only to reply to you. See our <a href="/privacy-policy/">Privacy Policy</a>.</p>', $form);
        $note = strpos($form, 'class="privacy-note"');
        $button = strpos($form, 'id="enquiryBtn"');
        assert_true($note !== false && $button !== false && $note < $button, 'the note sits before the submit button');
        assert_true(strpos($form, 'name="message"') < $note, 'and after the message box it is about');
        assert_true(is_file($env->root . '/public_html/Privacy Policy.html'), 'the link goes to a page that exists');
        // The newsletter form on the same page asks for an email only, so it gets no such note.
        assert_equal(1, substr_count($html, 'class="privacy-note"'));
    });
    run_test('the sample config shows the server settings as real lines (storage, sessions, Turnstile), not hidden in comments', function () use ($env) {
        // A copy of the sample was easy to leave with the session folder and the CAPTCHA keys switched off, because
        // they were commented out. Empty values mean "not configured" in the code, so real lines change nothing locally.
        $sample = file_get_contents($env->root . '/public_html/includes/db-config.sample.php');
        foreach (['PRIVATE_STORAGE_PATH', 'SESSION_SAVE_PATH', 'TURNSTILE_SITE_KEY', 'TURNSTILE_SECRET_KEY'] as $name) {
            assert_true((bool) preg_match("/^define\\('{$name}', ''\\);\\s*$/m", $sample), "{$name} must be a real line in the sample, not a comment");
        }
        assert_contains("domains/kuronyx.in/sessions", $sample, 'the example names the sessions folder');
    });
    run_test('the Privacy Policy has a clean address, /privacy-policy/, that serves the one policy file (a Karandeep review comment)', function () use ($env, $get) {
        $r = $get('/privacy-policy/');
        assert_equal(200, $r['status']);
        assert_contains('text/html', (string) $r['headers']);
        // The same file, with only a base address and a canonical tag added on the way out (the file itself is not edited:
        // the consent "version" is a fingerprint of it).
        $file = file_get_contents($env->root . '/public_html/Privacy Policy.html');
        $served = str_replace("<head>\n<base href=\"/\">\n<link rel=\"canonical\" href=\"https://kuronyx.in/privacy-policy/\">", '<head>', $r['body']);
        assert_equal($file, $served, 'the page is the policy file, unchanged apart from the two added tags');
        assert_contains('<link rel="canonical" href="https://kuronyx.in/privacy-policy/">', $r['body']);
        // Its own relative links ("Back to site") would point inside /privacy-policy/ without the base address.
        assert_contains('<base href="/">', $r['body']);
        assert_contains('href="./index.html"', $r['body'], 'the policy still has its relative Back link, which the base address makes work');
        assert_equal(1, substr_count($r['body'], '<base '), 'exactly one base tag');
    });

    run_test('every public page links the clean Privacy Policy address, and nothing links the old one (it only redirects)', function () use ($env, $get) {
        $pages = ['/index.html', '/about/', '/for-veterinarians/', '/pet-parents/', '/journal/', '/for-cat-owners/', '/for-veterinarians/apply/index.php', '/for-veterinarians/login.php'];
        foreach ($pages as $p) {
            $html = $get($p)['body'];
            assert_true(strpos($html, 'href="/privacy-policy/"') !== false, "{$p} links /privacy-policy/");
            assert_true(strpos($html, 'Privacy%20Policy') === false && strpos($html, 'Privacy Policy.html') === false, "{$p} must not link the old address");
        }
        // Both consent checkboxes (the places the comment said it was linked from) use it too.
        foreach (['/for-cat-owners/', '/for-veterinarians/apply/index.php'] as $p) {
            assert_true((bool) preg_match('#<label for="consent">[^<]*<a href="/privacy-policy/"#', $get($p)['body']), "{$p}: the consent text links the policy");
        }
        $sitemap = file_get_contents($env->root . '/public_html/sitemap.xml');
        assert_contains('<loc>https://kuronyx.in/privacy-policy/</loc>', $sitemap);
        assert_true(strpos($sitemap, 'Privacy%20Policy') === false, 'the sitemap lists the clean address only');
    });

    run_test('the policy file itself is untouched, so the consent version fingerprint did not change', function () use ($env) {
        // privacy_policy_version() hashes the file. The page above serves it but never rewrites it.
        $file = $env->root . '/public_html/Privacy Policy.html';
        assert_true(strpos((string) file_get_contents($file), 'privacy-policy') === false, 'no clean-address tags were written into the file');
        assert_true(strpos((string) file_get_contents($file), '<base') === false);
    });
};
