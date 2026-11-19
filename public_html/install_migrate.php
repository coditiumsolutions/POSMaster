<?php
@ini_set('max_execution_time', '0');
@set_time_limit(0);
header('Content-Type: text/plain; charset=UTF-8');
$secret = $_GET['key'] ?? '';
if ($secret !== 'PosPhpInstall2026') {
    http_response_code(403);
    echo "forbidden\n";
    exit;
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$app = require_once $root . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "PHP " . PHP_VERSION . "\n";
echo "Laravel boot OK\n";
echo "base=" . $root . "\n";

try {
    DB::connection()->getPdo();
    echo "DB OK: " . DB::connection()->getDatabaseName() . "\n";
} catch (Throwable $e) {
    echo "DB FAIL: " . $e->getMessage() . "\n";
    exit(1);
}

$exit = Artisan::call('migrate', ['--force' => true]);
echo Artisan::output();
echo "migrate exit=$exit\n";

$exitSeed = Artisan::call('db:seed', ['--force' => true]);
echo Artisan::output();
echo "seed exit=$exitSeed\n";

$exit2 = Artisan::call('storage:link');
echo Artisan::output();
echo "storage:link exit=$exit2\n";

@unlink(__FILE__);
echo "installer removed\n";
echo "DONE\n";
