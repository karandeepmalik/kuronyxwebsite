<?php
return function (TestEnv $env): void {
    run_test('GET / returns 200', function () use ($env) {
        $r = http_request('GET', $env->baseUrl . '/index.html');
        assert_equal(200, $r['status']);
        assert_contains('Kuronyx', $r['body']);
    });

    $pages = [
        '/about/index.php'              => 'Kuronyx did not begin as a company',
        '/for-veterinarians/index.php'  => 'Kuronyx works with veterinarians',
        '/pet-parents/index.php'        => 'Your veterinarian has written a prescription',
        '/for-cat-owners/index.php'     => 'Upload the prescription',
        '/journal/index.php'            => 'A clinical education series',
    ];
    foreach ($pages as $path => $needle) {
        run_test("GET {$path} returns 200", function () use ($env, $path, $needle) {
            $r = http_request('GET', $env->baseUrl . $path);
            assert_equal(200, $r['status'], "unexpected status for {$path}");
            assert_contains($needle, $r['body']);
        });
    }

    // Each of these pages is a folder with an index.php, and the real server (LiteSpeed on
    // Hostinger - checked live: /assets, /php, /js all 301 to the trailing-slash form) redirects
    // /about to /about/. The built-in test server doesn't, so the tests above can't see it; this
    // checks the published addresses statically instead: canonical, sitemap, nav and internal
    // links must all use the trailing-slash form so none of them point at a redirecting URL.
    run_test('canonicals, sitemap, nav and internal links use the trailing-slash form of folder pages', function () use ($env) {
        $root    = $env->root . '/public_html';
        $folders = ['about', 'for-veterinarians', 'for-veterinarians/apply', 'for-cat-owners', 'pet-parents', 'journal', 'request-received'];

        foreach (['about', 'for-veterinarians', 'for-veterinarians/apply', 'for-cat-owners', 'pet-parents', 'journal'] as $f) {
            $src = file_get_contents("{$root}/{$f}/index.php");
            assert_true((bool) preg_match('/\$canonical\s*=\s*\'([^\']+)\'/', $src, $m), "{$f} has no canonical");
            assert_equal("https://kuronyx.in/{$f}/", $m[1], "{$f} canonical must be the trailing-slash URL");
        }

        $sitemap = file_get_contents($root . '/sitemap.xml');
        preg_match_all('#<loc>([^<]+)</loc>#', $sitemap, $locs);
        foreach ($locs[1] as $loc) {
            foreach ($folders as $f) {
                assert_true($loc !== "https://kuronyx.in/{$f}", "sitemap lists {$loc} without the trailing slash");
            }
        }
        foreach (['about', 'for-veterinarians', 'pet-parents', 'journal'] as $f) {
            assert_true(in_array("https://kuronyx.in/{$f}/", $locs[1], true), "sitemap is missing https://kuronyx.in/{$f}/");
        }
        // The form page is hidden from search (the Pet Parents page is the front door), and the pages that moved or went away
        // must not be advertised any more.
        foreach (['for-cat-owners', 'dispatches', 'contact-us'] as $gone) {
            assert_true(!in_array("https://kuronyx.in/{$gone}/", $locs[1], true), "the sitemap must not list /{$gone}/");
        }

        // No published link or redirect target may name a folder page without its trailing slash.
        $offenders = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!in_array($file->getExtension(), ['php', 'html'], true)) continue;
            $text = file_get_contents($file->getPathname());
            foreach ($folders as $f) {
                // /about" or /about' or /about<  (also https://kuronyx.in/about"), but not /about/ or /about/index.php
                if (preg_match('#(?:href="|\'|Location: |kuronyx\.in)/' . preg_quote($f, '#') . '["\'<]#', $text)
                    || preg_match('#kuronyx\.in/' . preg_quote($f, '#') . '["\'<]#', $text)) {
                    $offenders[] = str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname()) . " -> /{$f}";
                }
            }
        }
        assert_equal([], $offenders, "these still link to a folder page without its trailing slash:\n  - " . implode("\n  - ", $offenders));
    });

    // The homepage (static HTML) and the About page both carry JSON-LD for the company, with the
    // same @id so search engines treat them as one entity. They are written by hand in two places,
    // so this fails if the names, registration numbers or address ever drift apart.
    run_test('the homepage and About page JSON-LD describe the same company (same @id, names, numbers, address)', function () use ($env) {
        $ld = function (string $html): array {
            assert_true((bool) preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m), 'no JSON-LD block found');
            $data = json_decode($m[1], true);
            assert_true(is_array($data), 'JSON-LD is not valid JSON: ' . json_last_error_msg());
            return $data;
        };
        $home  = $ld(file_get_contents($env->root . '/public_html/index.html'));
        $about = $ld(http_request('GET', $env->baseUrl . '/about/index.php')['body']);

        assert_equal('Organization', $about['@type']);
        assert_equal('Kuronyx Sciences Pvt. Ltd.', $home['legalName'], 'homepage legalName');
        foreach (['@id', 'name', 'legalName', 'alternateName', 'url', 'logo', 'email', 'telephone', 'address', 'sameAs'] as $key) {
            assert_equal($home[$key], $about[$key], "JSON-LD '{$key}' differs between the homepage and the About page");
        }
        $ids = fn (array $d) => array_column($d['identifier'], 'value', 'propertyID');
        assert_equal($ids($home), $ids($about), 'registration numbers differ between the homepage and the About page');
        assert_equal('U21000MH2025PTC456216', $ids($about)['CIN']);
        assert_equal($home['hasCredential']['identifier'], $about['hasCredential']['identifier'], 'ISO certificate number differs');
        assert_equal($home['hasCredential']['recognizedBy']['name'], $about['hasCredential']['recognizedBy']['name'], 'ISO certifying body differs');

        // Every number in the structured data is also shown to people on the About page.
        $page = http_request('GET', $env->baseUrl . '/about/index.php')['body'];
        foreach ($ids($about) as $value) assert_contains($value, $page);
        assert_contains($about['hasCredential']['identifier'], $page);
    });

    run_test('.htaccess redirects www to the bare domain, before the https rule so it is a single hop', function () use ($env) {
        $htaccess = file_get_contents($env->root . '/public_html/.htaccess');
        $www   = strpos($htaccess, 'RewriteCond %{HTTP_HOST} ^www\.kuronyx\.in$ [NC]');
        $https = strpos($htaccess, 'RewriteCond %{HTTPS} !=on');
        assert_true($www !== false, '.htaccess has no www redirect rule');
        assert_true($https !== false && $www < $https, 'the www rule must come before the https rule');
        assert_contains('RewriteRule ^ https://kuronyx.in%{REQUEST_URI} [L,R=301]', $htaccess);
    });
};
