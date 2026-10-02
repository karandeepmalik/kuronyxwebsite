<?php
// Parsers for db/schema.sql (MySQL, what production runs) and db/schema.sqlite.sql (the
// hand-maintained mirror the test suite runs on), used by tests/cases/01_schema_parity.php to
// catch the two drifting apart. Deliberately small: it understands only the CREATE TABLE /
// CREATE INDEX shapes these two files actually use, and fails loudly on anything else.

// Splits $s on commas that are not inside parentheses or single-quoted strings.
function schema_split_top_level(string $s): array {
    $parts = []; $depth = 0; $buf = ''; $quote = false;
    for ($i = 0, $n = strlen($s); $i < $n; $i++) {
        $c = $s[$i];
        if ($c === "'") $quote = !$quote;
        if (!$quote) {
            if ($c === '(') $depth++;
            if ($c === ')') $depth--;
            if ($c === ',' && $depth === 0) { $parts[] = trim($buf); $buf = ''; continue; }
        }
        $buf .= $c;
    }
    if (trim($buf) !== '') $parts[] = trim($buf);
    return $parts;
}

function schema_strip_comments(string $sql): string {
    return preg_replace('/--[^\n]*/', '', $sql);
}

function schema_enum_values(string $text): ?array {
    // ENUM('a','b')  or  CHECK (col IN ('a','b'))
    if (!preg_match("/(?:ENUM\\s*\\(|\\bIN\\s*\\()\\s*((?:'[^']*'\\s*,?\\s*)+)\\)/i", $text, $m)) return null;
    preg_match_all("/'([^']*)'/", $m[1], $vals);
    sort($vals[1]);
    return $vals[1];
}

// Returns ['tables' => [name => ['columns' => [col => ['notnull'=>bool,'unique'=>bool,'enum'=>?array]],
//          'indexes' => [ "col1,col2" => true ], 'fks' => [ "col->table" => true ]]]]
function schema_parse(string $sql, string $dialect): array {
    $sql = schema_strip_comments($sql);
    $tables = [];
    if (!preg_match_all('/CREATE\s+TABLE\s+(\w+)\s*\((.*?)\)\s*(?:ENGINE[^;]*)?;/is', $sql, $tm, PREG_SET_ORDER)) {
        throw new RuntimeException("no CREATE TABLE found ({$dialect})");
    }
    foreach ($tm as $t) {
        [$all, $name, $body] = $t;
        $tables[$name] = ['columns' => [], 'indexes' => [], 'fks' => []];
        foreach (schema_split_top_level($body) as $piece) {
            if ($piece === '') continue;
            if (preg_match('/^(?:CONSTRAINT\s+\w+\s+)?FOREIGN\s+KEY\s*\((\w+)\)\s*REFERENCES\s+(\w+)/i', $piece, $m)) {
                $tables[$name]['fks']["{$m[1]}->{$m[2]}"] = true;
            } elseif (preg_match('/^(?:INDEX|KEY)\s+\w+\s*\(([^)]*)\)/i', $piece, $m)) {
                $tables[$name]['indexes'][preg_replace('/\s+/', '', $m[1])] = true;
            } elseif (preg_match('/^(PRIMARY\s+KEY|UNIQUE|CHECK|CONSTRAINT)\b/i', $piece)) {
                continue;
            } else {
                preg_match('/^(\w+)\s+(.*)$/s', $piece, $m);
                $col = $m[1]; $def = $m[2];
                $isPk = (bool) preg_match('/PRIMARY\s+KEY/i', $def);
                $tables[$name]['columns'][$col] = [
                    'notnull' => $isPk || (bool) preg_match('/NOT\s+NULL/i', $def),
                    'unique'  => (bool) preg_match('/\bUNIQUE\b/i', $def),
                    'enum'    => schema_enum_values($def),
                ];
                if (preg_match('/REFERENCES\s+(\w+)/i', $def, $fk)) {
                    $tables[$name]['fks']["{$col}->{$fk[1]}"] = true;
                }
            }
        }
    }
    // SQLite declares indexes outside the table: CREATE INDEX idx ON table (cols);
    if (preg_match_all('/CREATE\s+INDEX\s+\w+\s+ON\s+(\w+)\s*\(([^)]*)\)/i', $sql, $im, PREG_SET_ORDER)) {
        foreach ($im as $i) {
            $tables[$i[1]]['indexes'][preg_replace('/\s+/', '', $i[2])] = true;
        }
    }
    return $tables;
}

// Differences between the MySQL schema and its SQLite mirror, as readable strings (empty = in sync).
function schema_diff(array $mysql, array $sqlite): array {
    $problems = [];
    foreach (array_diff_key($mysql, $sqlite) as $t => $_) $problems[] = "table {$t} is in schema.sql but missing from schema.sqlite.sql";
    foreach (array_diff_key($sqlite, $mysql) as $t => $_) $problems[] = "table {$t} is in schema.sqlite.sql but missing from schema.sql";
    foreach (array_intersect_key($mysql, $sqlite) as $t => $my) {
        $lite = $sqlite[$t];
        foreach (array_diff_key($my['columns'], $lite['columns']) as $c => $_) $problems[] = "{$t}.{$c} is missing from schema.sqlite.sql";
        foreach (array_diff_key($lite['columns'], $my['columns']) as $c => $_) $problems[] = "{$t}.{$c} is missing from schema.sql";
        foreach (array_intersect_key($my['columns'], $lite['columns']) as $c => $def) {
            $other = $lite['columns'][$c];
            if ($def['notnull'] !== $other['notnull']) $problems[] = "{$t}.{$c}: NOT NULL differs between the two schemas";
            if ($def['unique'] !== $other['unique'])   $problems[] = "{$t}.{$c}: UNIQUE differs between the two schemas";
            if ($def['enum'] !== $other['enum'])       $problems[] = "{$t}.{$c}: allowed values differ (" . json_encode($def['enum']) . ' vs ' . json_encode($other['enum']) . ')';
        }
        foreach (array_diff_key($my['indexes'], $lite['indexes']) as $i => $_) $problems[] = "{$t}: index on ({$i}) is missing from schema.sqlite.sql";
        foreach (array_diff_key($lite['indexes'], $my['indexes']) as $i => $_) $problems[] = "{$t}: index on ({$i}) is missing from schema.sql";
        foreach (array_diff_key($my['fks'], $lite['fks']) as $f => $_) $problems[] = "{$t}: foreign key {$f} is missing from schema.sqlite.sql";
        foreach (array_diff_key($lite['fks'], $my['fks']) as $f => $_) $problems[] = "{$t}: foreign key {$f} is missing from schema.sql";
    }
    return $problems;
}

// Every column / index / enum / table a migration adds or changes must also be in schema.sql, or
// a fresh install (schema.sql) would differ from an upgraded one (schema.sql + migrations).
function schema_migration_problems(array $mysql, string $migrationsDir): array {
    $problems = [];
    foreach (glob($migrationsDir . '/*.sql') as $file) {
        $base = basename($file);
        $sql = schema_strip_comments(file_get_contents($file));
        if (preg_match_all('/ALTER\s+TABLE\s+(\w+)\s+ADD\s+COLUMN\s+(\w+)/i', $sql, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                if (!isset($mysql[$x[1]]['columns'][$x[2]])) $problems[] = "{$base}: adds {$x[1]}.{$x[2]}, which schema.sql does not have";
            }
        }
        if (preg_match_all('/ALTER\s+TABLE\s+(\w+)\s+ADD\s+(?:INDEX|KEY)\s+\w+\s*\(([^)]*)\)/i', $sql, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                if (!isset($mysql[$x[1]]['indexes'][preg_replace('/\s+/', '', $x[2])])) $problems[] = "{$base}: adds an index on {$x[1]}({$x[2]}), which schema.sql does not have";
            }
        }
        if (preg_match_all('/ALTER\s+TABLE\s+(\w+)\s+MODIFY\s+COLUMN\s+(\w+)\s+([^;]*);/i', $sql, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $col = $mysql[$x[1]]['columns'][$x[2]] ?? null;
                if ($col === null) { $problems[] = "{$base}: modifies {$x[1]}.{$x[2]}, which schema.sql does not have"; continue; }
                $enum = schema_enum_values($x[3]);
                if ($enum !== null && $enum !== $col['enum']) $problems[] = "{$base}: sets {$x[1]}.{$x[2]} values to " . json_encode($enum) . ' but schema.sql has ' . json_encode($col['enum']);
                $nn = (bool) preg_match('/NOT\s+NULL/i', $x[3]);
                if ($nn !== $col['notnull']) $problems[] = "{$base}: {$x[1]}.{$x[2]} NOT NULL differs from schema.sql";
            }
        }
        if (preg_match_all('/CREATE\s+TABLE\s+(\w+)/i', $sql, $m)) {
            foreach ($m[1] as $t) {
                if (!isset($mysql[$t])) $problems[] = "{$base}: creates table {$t}, which schema.sql does not have";
            }
        }
    }
    return $problems;
}
