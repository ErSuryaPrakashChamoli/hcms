<?php

// Read-only SQL against the TRAINING database only (for the people writing the training content).
//   php docs/training/organisation-admin/tools/q.php "SELECT code, name FROM salary_components LIMIT 20"
// Refuses anything that is not a single SELECT / SHOW / DESCRIBE, and refuses any database but hcm_orgadmin_showcase.
// Does not need tools/env.sh: it connects to the training database explicitly.

$sql = trim($argv[1] ?? '');
if ($sql === '' || ! preg_match('/^(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $sql) || preg_match('/;\s*\S/', $sql)
    || preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|TRUNCATE|REPLACE|GRANT|LOCK|CALL|INTO\s+OUTFILE)\b/i', $sql)) {
    fwrite(STDERR, "Only one read-only SELECT/SHOW/DESCRIBE statement is allowed.\n");
    exit(1);
}
$env = [];
foreach (file(__DIR__.'/../../../../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (preg_match('/^(DB_HOST|DB_PORT|DB_USERNAME|DB_PASSWORD)=(.*)$/', $line, $m)) {
        $env[$m[1]] = trim($m[2], "\"'");
    }
}
$pdo = new PDO('mysql:host='.($env['DB_HOST'] ?? '127.0.0.1').';port='.($env['DB_PORT'] ?? '3306').';dbname=hcm_orgadmin_showcase;charset=utf8mb4',
    $env['DB_USERNAME'] ?? 'root', $env['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'hcm_orgadmin_showcase') {
    fwrite(STDERR, "Refusing: not the training database.\n");
    exit(1);
}
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$pdo->beginTransaction();
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$pdo->rollBack();
$limit = 200;
echo json_encode(array_slice($rows, 0, $limit), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
if (count($rows) > $limit) {
    echo '... '.(count($rows) - $limit)." more rows\n";
}
