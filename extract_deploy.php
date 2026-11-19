<?php
@ini_set('max_execution_time', '0');
@ini_set('memory_limit', '512M');
@set_time_limit(0);
header('Content-Type: text/plain; charset=UTF-8');
ignore_user_abort(true);

$zip = __DIR__ . DIRECTORY_SEPARATOR . 'POS_PHP_deploy.zip';
if (!is_file($zip)) {
    http_response_code(404);
    echo "zip missing\n";
    exit;
}

echo "zip size=" . filesize($zip) . "\n";
flush();

$za = new ZipArchive();
if ($za->open($zip) !== true) {
    echo "cannot open zip\n";
    exit(1);
}

$total = $za->numFiles;
echo "files=$total\n";
flush();

for ($i = 0; $i < $total; $i++) {
    $name = $za->getNameIndex($i);
    if ($name === false) {
        continue;
    }
    $za->extractTo(__DIR__, $name);
    if ($i > 0 && $i % 200 === 0) {
        echo "extracted $i / $total\n";
        flush();
    }
}
$za->close();
echo "Extracted OK ($total files)\n";
@unlink($zip);
@unlink(__FILE__);
echo "Cleanup done\n";
