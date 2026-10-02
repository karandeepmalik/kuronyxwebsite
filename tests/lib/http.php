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
    return ['status' => $info['http_code'], 'body' => $body, 'headers' => $headerStr, 'location' => $location];
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
function extract_ott(string $html): string {
    if (!preg_match('/name="ott" value="([^"]+)"/', $html, $m)) {
        throw new RuntimeException('ott field not found in response body');
    }
    return html_entity_decode($m[1], ENT_QUOTES);
}
