<?php
require_once __DIR__ . '/../lib/schema.php';

// The test suite runs on SQLite but production is MySQL, so db/schema.sqlite.sql is a
// hand-maintained translation of db/schema.sql. These checks fail the moment the two (or the
// migrations) drift apart, instead of the difference surfacing as a production-only bug.
return function (TestEnv $env): void {
    $root   = $env->root;
    $mysql  = schema_parse(file_get_contents($root . '/db/schema.sql'), 'mysql');
    $sqlite = schema_parse(file_get_contents($root . '/db/schema.sqlite.sql'), 'sqlite');

    run_test('schema.sql and schema.sqlite.sql define the same tables, columns, NOT NULLs, UNIQUEs, allowed values, indexes and foreign keys', function () use ($mysql, $sqlite) {
        $problems = schema_diff($mysql, $sqlite);
        assert_true(count($mysql) >= 15, 'sanity check: the parser should have found the tables');
        assert_equal([], $problems, "the two schema files have drifted apart:\n  - " . implode("\n  - ", $problems));
    });

    run_test('every column, index, enum and table a migration adds is also in schema.sql', function () use ($mysql, $root) {
        $problems = schema_migration_problems($mysql, $root . '/db/migrations');
        assert_equal([], $problems, "migrations and schema.sql disagree:\n  - " . implode("\n  - ", $problems));
    });

    run_test('the parity check itself catches drift (a column, an enum value and an index removed from a copy)', function () use ($root) {
        $mysqlSql = file_get_contents($root . '/db/schema.sql');
        $broken = str_replace("ENUM('staff','system','public','vet')", "ENUM('staff','system','public')", $mysqlSql);
        $broken = str_replace("    erased_at                DATETIME NULL,\n", '', str_replace("\r\n", "\n", $broken));
        $broken = str_replace('    INDEX idx_gsreq_status (status),', '', $broken);
        $problems = schema_diff(
            schema_parse($broken, 'mysql'),
            schema_parse(file_get_contents($root . '/db/schema.sqlite.sql'), 'sqlite')
        );
        $joined = implode("\n", $problems);
        assert_contains('gs_requests.erased_at is missing from schema.sql', $joined);
        assert_contains('allowed values differ', $joined);
        assert_contains('index on (status) is missing from schema.sql', $joined);
    });
};
