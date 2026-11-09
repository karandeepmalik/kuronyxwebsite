<?php
// Minimal curl-based HTTP client for the integration tests. Each test passes a
// per-actor cookie jar file path so sessions (and CSRF tokens tied to them)
// persist across requests the way a real browser would.
function http_request(string $method, string $url, array $opts = []): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => $opts['headers'] ?? [],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    if (!empty($opts['cookie_jar'])) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $opts['cookie_jar']);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $opts['cookie_jar']);
    }
    if (array_key_exists('body', $opts)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("curl error for {$method} {$url}: {$err}");
    }
    $info = curl_getinfo($ch);
    curl_close($ch);

    $headerStr = substr($raw, 0, $info['header_size']);
    $body      = substr($raw, $info['header_size']);
    $location  = null;
    foreach (preg_split('/\r\n/', $headerStr) as $line) {
        if (stripos($line, 'Location:') === 0) $location = trim(substr($line, 9));
    }
    $response = ['status' => $info['http_code'], 'body' => $body, 'headers' => $headerStr, 'location' => $location];

    // 'follow' => true: what a browser does after a Post/Redirect/Get answer - GET the Location with the same
    // cookies and return THAT page (the redirect itself stays in ['post']). Pages that answer a POST with a
    // redirect (the veterinary-application review actions, data erasure, ...) show their result on the next GET.
    if (!empty($opts['follow']) && in_array($response['status'], [301, 302, 303], true) && $location !== null) {
        $target = preg_match('#^https?://#i', $location) ? $location : (preg_replace('#^(https?://[^/]+).*$#i', '$1', $url) . $location);
        $next = http_request('GET', $target, array_intersect_key($opts, ['cookie_jar' => 1, 'headers' => 1]));
        return $next + ['post' => $response];
    }
    return $response;
}

function extract_csrf(string $html): string {
    if (!preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m)) {
        throw new RuntimeException('csrf_token field not found in response body');
    }
    return html_entity_decode($m[1], ENT_QUOTES);
}

// Extracts the one-time-token field (one_time_field() / consume_one_time_token() in
// auth.php) a form embeds to guard against a double-click/resubmit actually firing an
// external side effect (an email) twice. Unlike CSRF tokens, a given one-time token is
// only ever valid for one submission — a fresh one is embedded on every render.
// The status an application's review form was loaded with (the hidden expected_status field on
// admin/veterinary-applications/view.php): approve/reject/suspend/request-info are refused if the status has changed.
function extract_expected_status(string $html): string {
    if (!preg_match('/name="expected_status" value="([^"]*)"/', $html, $m)) {
        throw new RuntimeException('expected_status field not found in response body');
    }
    return html_entity_decode($m[1], ENT_QUOTES);
}

function extract_ott(string $html): string {
    if (!preg_match('/name="ott" value="([^"]+)"/', $html, $m)) {
        throw new RuntimeException('ott field not found in response body');
    }
    return html_entity_decode($m[1], ENT_QUOTES);
}
