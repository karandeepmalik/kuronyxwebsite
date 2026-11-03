<?php
// Provokes each kind of integrity error against the REAL tables of whichever database the suite is
// running on (SQLite, or MySQL/MariaDB when KURONYX_TEST_MYSQL is set) and records what
// is_unique_violation() says about it. The point: SQLSTATE 23000 is shared by duplicate keys, foreign
// keys and NOT NULL, and the two engines word them differently - so the helper has to be checked
// against the engines' own messages, not against what the code assumes they say.
//
// Runs in a separate process (own connection, released on exit - a connection held by the test process
// can stall the HTTP test server on a Windows SQLite file). Prints one JSON object.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$_SERVER['SCRIPT_NAME'] = '/unique_violation_worker.php';
require __DIR__ . '/../../public_html/includes/auth.php';

$out = ['engine' => null, 'cases' => [], 'messages' => []];
$pdo = db();
$out['engine'] = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$made = ['vet_accounts' => [], 'vet_applications' => [], 'dispatches' => [], 'staff_users' => []];

// Runs $sql, expecting the database to refuse it; records the helper's verdict for each ($table, $column)
// pair asked about, plus the engine's own message (kept for diagnosing a failure).
$provoke = function (string $name, string $sql, array $params, array $asks) use ($pdo, &$out) {
    try {
        $pdo->prepare($sql)->execute($params);
        $out['cases'][$name] = 'NO ERROR (the database accepted a statement that should have been refused)';
        return;
    } catch (PDOException $e) {
        $out['messages'][$name] = ($e->errorInfo[0] ?? '') . ' / ' . ($e->errorInfo[1] ?? '') . ' / ' . ($e->errorInfo[2] ?? $e->getMessage());
        $verdicts = [];
        foreach ($asks as $ask) {
            [$table, $column] = $ask;
            $verdicts["{$table}.{$column}"] = is_unique_violation($e, $table, $column, $ask[2] ?? false);
        }
        $out['cases'][$name] = ['sqlstate' => $e->getCode(), 'verdicts' => $verdicts];
    }
};

try {
    $staffId = (int) $pdo->query('SELECT id FROM staff_users ORDER BY id LIMIT 1')->fetchColumn();
    $tag = bin2hex(random_bytes(4));

    // dispatches: slug is UNIQUE, author_staff_id is a foreign key, title is NOT NULL.
    $ins = $pdo->prepare('INSERT INTO dispatches (title, slug, body, status, author_staff_id) VALUES (?,?,?,?,?)');
    $ins->execute(["UV {$tag}", "uv-{$tag}", 'x', 'draft', $staffId]);
    $made['dispatches'][] = (int) $pdo->lastInsertId();
    $dup = 'INSERT INTO dispatches (title, slug, body, status, author_staff_id) VALUES (?,?,?,?,?)';
    $provoke('dispatch duplicate slug', $dup, ['Other title', "uv-{$tag}", 'x', 'draft', $staffId], [['dispatches', 'slug'], ['dispatches', 'title'], ['staff_users', 'email']]);
    $provoke('dispatch foreign key', $dup, ['FK title', "uv-fk-{$tag}", 'x', 'draft', 999999999], [['dispatches', 'slug']]);
    $provoke('dispatch NOT NULL', $dup, [null, "uv-nn-{$tag}", 'x', 'draft', $staffId], [['dispatches', 'slug']]);

    // staff_users: email is UNIQUE.
    $mk = $pdo->prepare("INSERT INTO staff_users (name, email, password_hash, role) VALUES (?,?,?, 'pharmacy_staff')");
    $mk->execute(['UV Staff', "uv-{$tag}@example.test", 'x']);
    $made['staff_users'][] = (int) $pdo->lastInsertId();
    $provoke('staff duplicate email', "INSERT INTO staff_users (name, email, password_hash, role) VALUES ('Dup', ?, 'x', 'pharmacy_staff')", ["uv-{$tag}@example.test"], [['staff_users', 'email'], ['staff_users', 'name']]);

    // vet_accounts has TWO unique columns (vet_application_id and email); each must be told apart.
    $app = "INSERT INTO vet_applications (full_name, professional_email, mobile, registration_number, registration_state, qualification, year_qualified, practice_type, clinic_name, clinic_address, clinic_city, clinic_state, clinic_pin, clinic_phone)
            VALUES ('UV Vet', ?, '9000000000', 'UV', 'MH', 'BVSc', 2020, 'x', 'UV Clinic', 'x', 'x', 'x', '000000', '9000000000')";
    foreach (['a', 'b'] as $k) {
        $pdo->prepare($app)->execute(["uv-vet-{$k}-{$tag}@example.test"]);
        $made['vet_applications'][$k] = (int) $pdo->lastInsertId();
    }
    $acct = "INSERT INTO vet_accounts (vet_application_id, email, status) VALUES (?, ?, 'pending_activation')";
    $pdo->prepare($acct)->execute([$made['vet_applications']['a'], "uv-acct-{$tag}@example.test"]);
    $made['vet_accounts'][] = (int) $pdo->lastInsertId();
    $asks = [['vet_accounts', 'vet_application_id'], ['vet_accounts', 'email']];
    $provoke('vet account same email, different application', $acct, [$made['vet_applications']['b'], "uv-acct-{$tag}@example.test"], $asks);
    $provoke('vet account same application, different email', $acct, [$made['vet_applications']['a'], "uv-other-{$tag}@example.test"], $asks);

    // setup_lock: the single fixed PRIMARY KEY row (id = 1). MySQL names that key 'PRIMARY'.
    $hasLock = (int) $pdo->query('SELECT COUNT(*) FROM setup_lock')->fetchColumn() > 0;
    if (!$hasLock) {
        $pdo->exec('INSERT INTO setup_lock (id) VALUES (1)');
    }
    $provoke('setup_lock primary key', 'INSERT INTO setup_lock (id) VALUES (1)', [], [['setup_lock', 'id', true], ['setup_lock', 'nope', true]]);
    if (!$hasLock) {
        $pdo->exec('DELETE FROM setup_lock WHERE id = 1');
    }
} catch (Throwable $e) {
    $out['error'] = get_class($e) . ': ' . $e->getMessage();
} finally {
    foreach ($made['vet_accounts'] as $id) { $pdo->exec('DELETE FROM vet_accounts WHERE id = ' . (int) $id); }
    foreach ($made['vet_applications'] as $id) { $pdo->exec('DELETE FROM vet_applications WHERE id = ' . (int) $id); }
    foreach ($made['dispatches'] as $id) { $pdo->exec('DELETE FROM dispatches WHERE id = ' . (int) $id); }
    foreach ($made['staff_users'] as $id) { $pdo->exec('DELETE FROM staff_users WHERE id = ' . (int) $id); }
}
echo json_encode($out);
