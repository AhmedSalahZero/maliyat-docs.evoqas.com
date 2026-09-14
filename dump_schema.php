<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$tables = DB::select('SHOW TABLES');
$output = '';

foreach ($tables as $t) {
    $tname = array_values((array)$t)[0];
    $output .= "\n=== $tname ===\n";
    $cols = DB::select('SHOW FULL COLUMNS FROM `' . $tname . '`');
    foreach ($cols as $c) {
        $null    = $c->Null    === 'YES' ? 'NULL' : 'NOT NULL';
        $default = $c->Default !== null  ? " DEFAULT '{$c->Default}'" : '';
        $output .= "  {$c->Field}  |  {$c->Type}  |  {$null}{$default}\n";
    }
}

file_put_contents(__DIR__ . '/schema_full.txt', $output);
echo "Done — " . count($tables) . " tables, " . strlen($output) . " bytes written.\n";