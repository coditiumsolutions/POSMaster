<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Throwable;

class NewClientSetupService
{
    protected PleskApiClient $plesk;

    public function __construct(?PleskApiClient $plesk = null)
    {
        $this->plesk = $plesk ?: new PleskApiClient(
            (string) config('new_setup.plesk_url'),
            (string) config('new_setup.plesk_user'),
            (string) config('new_setup.plesk_password'),
        );
    }

    /**
     * Create a separate POS copy + blank database.
     * Never writes to the current (source) database.
     *
     * @param  array{
     *   project_name:string,
     *   subdomain:string,
     *   database_name:string,
     *   db_password:string,
     *   admin_username:string,
     *   admin_password:string
     * }  $input
     * @return array<string,mixed>
     */
    public function provision(array $input): array
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '1024M');

        if (!config('new_setup.enabled')) {
            throw new RuntimeException('New Setup is disabled.');
        }
        if (!$this->plesk->configured()) {
            throw new RuntimeException('New Setup is not configured. Ask Software to set NEW_SETUP_PLESK_* in .env.');
        }

        $projectName = trim($input['project_name']);
        $subdomain = strtolower(trim($input['subdomain']));
        $dbName = preg_replace('/[^a-zA-Z0-9_]/', '', $input['database_name']) ?? '';
        $dbPassword = (string) $input['db_password'];
        $adminUser = trim($input['admin_username']);
        $adminPassword = (string) $input['admin_password'];

        $this->validateNames($subdomain, $dbName, $projectName, $adminUser);

        $baseDomain = (string) config('new_setup.base_domain');
        $fullDomain = $subdomain . '.' . $baseDomain;
        $folderName = $subdomain . '.' . $baseDomain;
        $sitesRoot = $this->normalizePath(rtrim((string) config('new_setup.sites_root'), "\\/"));
        $targetRoot = $this->normalizePath($sitesRoot . DIRECTORY_SEPARATOR . $folderName);
        $targetPublic = $this->normalizePath($targetRoot . DIRECTORY_SEPARATOR . 'public');
        $sourceRoot = $this->normalizePath(base_path());

        if ($this->samePath($sourceRoot, $targetRoot)) {
            throw new RuntimeException('Refusing to overwrite the current live project folder.');
        }

        // Avoid filesystem probes before Plesk creates the site (open_basedir can block sibling paths).
        if ($this->siteExists($fullDomain)) {
            throw new RuntimeException("Site already exists: {$fullDomain}");
        }
        if ($this->databaseExists($dbName)) {
            throw new RuntimeException("Database already exists: {$dbName}");
        }

        $steps = [];
        $dbId = null;
        $dbLogin = $dbName;

        // 1) Create blank MySQL database + user (new DB only)
        $steps[] = 'Creating MySQL database via Plesk…';
        $dbId = $this->createDatabase($dbName);
        $steps[] = "Database created: {$dbName} (id {$dbId})";

        $steps[] = 'Creating database user…';
        $this->createDatabaseUser($dbId, $dbLogin, $dbPassword);
        $steps[] = "Database user created: {$dbLogin}";

        // 2) Create site / subdomain in Plesk (creates folder under webspace)
        $steps[] = 'Creating website/subdomain in Plesk…';
        $wwwRoot = $targetPublic;
        $this->createSite($fullDomain, $wwwRoot);
        $steps[] = "Site created: https://{$fullDomain}";

        // 3) Copy application files via FTP (PHP cannot write sibling folders due to NTFS ACL)
        $steps[] = 'Copying application files via FTP…';
        $this->copyApplicationViaFtp($sourceRoot, $folderName);
        $steps[] = 'Application files copied.';

        // 4) Write new .env for the copy only
        $steps[] = 'Writing new .env for the copy…';
        $appKey = 'base64:' . base64_encode(random_bytes(32));
        $this->writeEnvViaFtp($folderName, [
            'APP_NAME' => $projectName,
            'APP_URL' => 'https://' . $fullDomain,
            'APP_KEY' => $appKey,
            'DB_DATABASE' => $dbName,
            'DB_USERNAME' => $dbLogin,
            'DB_PASSWORD' => $dbPassword,
        ]);
        $steps[] = '.env written for new project.';

        // 5) Blank schema into NEW database only (structure from live, no customer data)
        $steps[] = 'Importing blank schema into new database…';
        $schemaSql = $this->buildBlankSchemaSql();
        $this->importSqlIntoNewDatabase($dbName, $dbLogin, $dbPassword, $schemaSql);
        $steps[] = 'Blank schema imported.';

        // 6) Seed minimal blank data into NEW database only
        $steps[] = 'Seeding blank starter data…';
        $this->seedBlankDatabase($dbName, $dbLogin, $dbPassword, $projectName, $adminUser, $adminPassword);
        $steps[] = 'Blank starter data ready.';

        return [
            'project_name' => $projectName,
            'domain' => $fullDomain,
            'url' => 'https://' . $fullDomain,
            'folder' => $folderName,
            'target_root' => $targetRoot,
            'database' => $dbName,
            'db_user' => $dbLogin,
            'admin_username' => $adminUser,
            'steps' => $steps,
            'notes' => [
                'Live LipSip files and database were not modified.',
                'Point DNS A record for ' . $fullDomain . ' to the server IP if it is not already on this hosting.',
                'Issue SSL (Let\'s Encrypt) for the new domain in Plesk if HTTPS is required.',
            ],
        ];
    }

    protected function validateNames(string $subdomain, string $dbName, string $projectName, string $adminUser): void
    {
        if ($projectName === '' || strlen($projectName) > 80) {
            throw new RuntimeException('Project name is required (max 80 characters).');
        }
        if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,40}[a-z0-9])?$/', $subdomain)) {
            throw new RuntimeException('Subdomain must be lowercase letters, numbers, and hyphens only.');
        }
        $reserved = array_map('strtolower', (array) config('new_setup.reserved_subdomains', []));
        if (in_array($subdomain, $reserved, true)) {
            throw new RuntimeException('That subdomain is reserved and cannot be used.');
        }
        if ($dbName === '' || strlen($dbName) < 3 || strlen($dbName) > 40) {
            throw new RuntimeException('Database name must be 3–40 letters, numbers, or underscores.');
        }
        if (strcasecmp($dbName, (string) config('database.connections.mysql.database')) === 0) {
            throw new RuntimeException('Database name cannot be the same as the live LipSip database.');
        }
        if ($adminUser === '' || strlen($adminUser) > 50) {
            throw new RuntimeException('Admin username is required.');
        }
    }

    protected function createDatabase(string $dbName): int
    {
        $webspaceId = (int) config('new_setup.webspace_id');
        $packet = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<packet>'
            . '<database><add-db>'
            . '<webspace-id>' . $webspaceId . '</webspace-id>'
            . '<name>' . htmlspecialchars($dbName, ENT_XML1) . '</name>'
            . '<type>mysql</type>'
            . '</add-db></database>'
            . '</packet>';

        $res = $this->plesk->request($packet);
        if (!$res['ok'] || !$res['xml']) {
            throw new RuntimeException('Create database failed: ' . ($res['error'] ?: 'unknown'));
        }
        $this->plesk->assertOk($res['xml'], 'Create database');
        $id = (int) ($res['xml']->database->{'add-db'}->result->id ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Create database failed: missing database id.');
        }

        return $id;
    }

    protected function createDatabaseUser(int $dbId, string $login, string $password): void
    {
        $packet = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<packet>'
            . '<database><add-db-user>'
            . '<db-id>' . $dbId . '</db-id>'
            . '<login>' . htmlspecialchars($login, ENT_XML1) . '</login>'
            . '<password>' . htmlspecialchars($password, ENT_XML1) . '</password>'
            . '</add-db-user></database>'
            . '</packet>';

        $res = $this->plesk->request($packet);
        if (!$res['ok'] || !$res['xml']) {
            throw new RuntimeException('Create database user failed: ' . ($res['error'] ?: 'unknown'));
        }
        $this->plesk->assertOk($res['xml'], 'Create database user');
    }

    protected function createSite(string $fullDomain, string $wwwRoot): void
    {
        $webspaceId = (int) config('new_setup.webspace_id');
        $ip = (string) config('new_setup.ip_address');

        $packet = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<packet>'
            . '<site><add>'
            . '<gen_setup>'
            . '<name>' . htmlspecialchars($fullDomain, ENT_XML1) . '</name>'
            . '<webspace-id>' . $webspaceId . '</webspace-id>'
            . '</gen_setup>'
            . '<hosting><vrt_hst>'
            . '<property><name>www_root</name><value>' . htmlspecialchars($wwwRoot, ENT_XML1) . '</value></property>'
            . '<property><name>php</name><value>true</value></property>'
            . '<property><name>php_handler_id</name><value>fastcgi-8.3</value></property>'
            . '<property><name>ssl</name><value>true</value></property>'
            . '<ip_address>' . htmlspecialchars($ip, ENT_XML1) . '</ip_address>'
            . '</vrt_hst></hosting>'
            . '</add></site>'
            . '</packet>';

        $res = $this->plesk->request($packet);
        if (!$res['ok'] || !$res['xml']) {
            throw new RuntimeException('Create site failed: ' . ($res['error'] ?: 'unknown'));
        }
        $this->plesk->assertOk($res['xml'], 'Create site');
    }

    protected function normalizePath(string $path): string
    {
        return rtrim(str_replace('/', DIRECTORY_SEPARATOR, $path), "\\/");
    }

    protected function samePath(string $a, string $b): bool
    {
        return strcasecmp($this->normalizePath($a), $this->normalizePath($b)) === 0;
    }

    protected function siteExists(string $fullDomain): bool
    {
        $packet = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<packet><site><get><filter><name>' . htmlspecialchars($fullDomain, ENT_XML1) . '</name></filter>'
            . '<dataset><gen_info/></dataset></get></site></packet>';
        $res = $this->plesk->request($packet);
        if (!$res['ok'] || !$res['xml']) {
            return false;
        }
        $status = strtolower((string) ($res['xml']->site->get->result->status ?? ''));

        return $status === 'ok';
    }

    protected function databaseExists(string $dbName): bool
    {
        $webspaceId = (int) config('new_setup.webspace_id');
        $packet = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<packet><database><get-db><filter><webspace-id>' . $webspaceId . '</webspace-id></filter>'
            . '</get-db></database></packet>';
        $res = $this->plesk->request($packet);
        if (!$res['ok'] || !$res['xml']) {
            return false;
        }
        foreach ($res['xml']->xpath('//database/get-db/result') ?: [] as $row) {
            if (strcasecmp((string) ($row->name ?? ''), $dbName) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return resource
     */
    protected function ftpConnect()
    {
        $host = (string) config('new_setup.ftp_host');
        $port = (int) config('new_setup.ftp_port', 21);
        $user = (string) config('new_setup.ftp_user');
        $pass = (string) config('new_setup.ftp_password');
        if ($user === '' || $pass === '') {
            throw new RuntimeException('FTP credentials for New Setup are not configured (NEW_SETUP_FTP_USER / NEW_SETUP_FTP_PASSWORD).');
        }

        $conn = @ftp_connect($host, $port, 30);
        if ($conn === false) {
            // Fallback to public IP if localhost FTP is blocked inside PHP
            $conn = @ftp_connect((string) config('new_setup.ip_address'), $port, 30);
        }
        if ($conn === false) {
            throw new RuntimeException('Could not connect to FTP host for file copy.');
        }
        if (!@ftp_login($conn, $user, $pass)) {
            ftp_close($conn);
            throw new RuntimeException('FTP login failed for New Setup copy.');
        }
        if (config('new_setup.ftp_passive')) {
            @ftp_pasv($conn, true);
        }

        return $conn;
    }

    protected function ftpEnsureDir($conn, string $remoteDir): void
    {
        $remoteDir = trim(str_replace('\\', '/', $remoteDir), '/');
        if ($remoteDir === '') {
            return;
        }
        $parts = explode('/', $remoteDir);
        $path = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $path .= '/' . $part;
            if (@ftp_chdir($conn, $path)) {
                @ftp_chdir($conn, '/');
                continue;
            }
            if (!@ftp_mkdir($conn, $path)) {
                // Ignore "already exists" races
                if (!@ftp_chdir($conn, $path)) {
                    throw new RuntimeException('FTP could not create folder: ' . $path);
                }
                @ftp_chdir($conn, '/');
            }
        }
    }

    protected function copyApplicationViaFtp(string $sourceRoot, string $folderName): void
    {
        $conn = $this->ftpConnect();
        $remoteRoot = trim(str_replace('\\', '/', $folderName), '/');
        $this->ftpEnsureDir($conn, $remoteRoot);

        $skipNames = [
            '.env' => true,
            '.git' => true,
            'node_modules' => true,
            '.idea' => true,
            '.vscode' => true,
        ];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $relative = substr($item->getPathname(), strlen($sourceRoot) + 1);
            $relative = str_replace('\\', '/', $relative);
            $parts = explode('/', $relative);
            if (isset($skipNames[$parts[0]])) {
                continue;
            }
            if (str_starts_with($relative, 'storage/logs/')) {
                continue;
            }
            if (str_starts_with($relative, 'storage/framework/cache/')) {
                continue;
            }
            if (str_starts_with($relative, 'storage/framework/sessions/')) {
                continue;
            }
            if (str_starts_with($relative, 'storage/framework/views/')) {
                continue;
            }

            $remotePath = $remoteRoot . '/' . $relative;
            if ($item->isDir()) {
                $this->ftpEnsureDir($conn, $remotePath);
            } else {
                $this->ftpEnsureDir($conn, dirname($remotePath));
                if (!@ftp_put($conn, $remotePath, $item->getPathname(), FTP_BINARY)) {
                    ftp_close($conn);
                    throw new RuntimeException('FTP failed copying file: ' . $relative);
                }
            }
        }

        foreach ([
            'storage/app/public',
            'storage/framework/cache',
            'storage/framework/sessions',
            'storage/framework/views',
            'storage/logs',
            'bootstrap/cache',
        ] as $dir) {
            $this->ftpEnsureDir($conn, $remoteRoot . '/' . $dir);
        }

        ftp_close($conn);
    }

    /**
     * @param  array<string,string>  $overrides
     */
    protected function writeEnvViaFtp(string $folderName, array $overrides): void
    {
        $sourceEnv = base_path('.env');
        if (!is_file($sourceEnv)) {
            throw new RuntimeException('Source .env not found; cannot build new project env.');
        }

        $lines = file($sourceEnv, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('Unable to read source .env.');
        }

        $map = $overrides;
        $out = [];
        $seen = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*([A-Z0-9_]+)\s*=/', $line, $m)) {
                $key = $m[1];
                if (array_key_exists($key, $map)) {
                    $out[] = $key . '=' . $this->envValue($map[$key]);
                    $seen[$key] = true;
                    continue;
                }
            }
            $out[] = $line;
        }

        foreach ($map as $key => $value) {
            if (!isset($seen[$key])) {
                $out[] = $key . '=' . $this->envValue($value);
            }
        }

        $forced = [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'NEW_SETUP_ENABLED' => 'false',
            'NEW_SETUP_PLESK_PASSWORD' => '',
            'NEW_SETUP_FTP_PASSWORD' => '',
        ];
        $final = [];
        $forcedSeen = [];
        foreach ($out as $line) {
            if (preg_match('/^\s*([A-Z0-9_]+)\s*=/', $line, $m) && array_key_exists($m[1], $forced)) {
                $final[] = $m[1] . '=' . $this->envValue($forced[$m[1]]);
                $forcedSeen[$m[1]] = true;
                continue;
            }
            $final[] = $line;
        }
        foreach ($forced as $key => $value) {
            if (!isset($forcedSeen[$key])) {
                $final[] = $key . '=' . $this->envValue($value);
            }
        }

        $content = implode("\n", $final) . "\n";
        $tmp = tempnam(sys_get_temp_dir(), 'env');
        if ($tmp === false || file_put_contents($tmp, $content) === false) {
            throw new RuntimeException('Could not stage new .env content.');
        }

        $conn = $this->ftpConnect();
        $remoteRoot = trim(str_replace('\\', '/', $folderName), '/');
        $this->ftpEnsureDir($conn, $remoteRoot);
        $ok = @ftp_put($conn, $remoteRoot . '/.env', $tmp, FTP_BINARY);
        ftp_close($conn);
        @unlink($tmp);
        if (!$ok) {
            throw new RuntimeException('FTP failed writing new .env file.');
        }
    }

    protected function envValue(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (preg_match('/\s|"|\'|#/', $value)) {
            return '"' . str_replace('"', '\"', $value) . '"';
        }

        return $value;
    }

    /**
     * READ-ONLY against live DB: export CREATE TABLE statements only (no data).
     */
    protected function buildBlankSchemaSql(): string
    {
        $tables = DB::select('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'');
        if ($tables === []) {
            throw new RuntimeException('No tables found to build blank schema.');
        }

        $key = array_key_first((array) $tables[0]);
        $sql = "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $row) {
            $arr = (array) $row;
            $name = (string) reset($arr);
            if ($name === '') {
                continue;
            }
            $createRows = DB::select('SHOW CREATE TABLE `' . str_replace('`', '``', $name) . '`');
            if ($createRows === []) {
                continue;
            }
            $createArr = (array) $createRows[0];
            $createSql = (string) ($createArr['Create Table'] ?? $createArr['Create table'] ?? '');
            if ($createSql === '') {
                // Fallback: second column
                $vals = array_values($createArr);
                $createSql = (string) ($vals[1] ?? '');
            }
            if ($createSql === '') {
                throw new RuntimeException('Could not read CREATE TABLE for ' . $name);
            }
            $sql .= 'DROP TABLE IF EXISTS `' . str_replace('`', '``', $name) . "`;\n";
            $sql .= $createSql . ";\n\n";
        }

        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

        return $sql;
    }

    protected function newPdo(string $dbName, string $user, string $password): PDO
    {
        $host = (string) config('database.connections.mysql.host', '127.0.0.1');
        $port = (string) config('database.connections.mysql.port', '3306');
        $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        return $pdo;
    }

    protected function importSqlIntoNewDatabase(string $dbName, string $user, string $password, string $sql): void
    {
        $pdo = $this->newPdo($dbName, $user, $password);
        // Split on ;\n boundaries carefully enough for CREATE TABLE statements
        $parts = preg_split('/;\s*\n/', $sql) ?: [];
        foreach ($parts as $part) {
            $stmt = trim($part);
            if ($stmt === '' || str_starts_with($stmt, '--')) {
                continue;
            }
            $pdo->exec($stmt);
        }
    }

    protected function seedBlankDatabase(
        string $dbName,
        string $user,
        string $password,
        string $projectName,
        string $adminUser,
        string $adminPassword
    ): void {
        $pdo = $this->newPdo($dbName, $user, $password);
        $now = date('Y-m-d H:i:s');
        $hash = password_hash($adminPassword, PASSWORD_BCRYPT);

        $has = function (string $table) use ($pdo): bool {
            $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
            return $stmt && $stmt->fetchColumn() !== false;
        };

        if ($has('branches')) {
            $pdo->exec("INSERT INTO branches (branch_code, branch_name, address, city, contact_person, mobile_no, is_active, created_date)
                VALUES ('MAIN', 'Main Branch', 'Main Street', 'City', 'Admin', '0300-0000000', 1, " . $pdo->quote($now) . ")");
        }

        if ($has('users')) {
            $stmt = $pdo->prepare("INSERT INTO users (user_name, full_name, password_hash, email, role_name, branch, is_active, created_date)
                VALUES (?, ?, ?, ?, ?, ?, 1, ?)");
            $stmt->execute([
                $adminUser,
                $projectName . ' Admin',
                $hash,
                'admin@' . Str::slug($projectName, '') . '.local',
                AppRoles::Software,
                'Main Branch',
                $now,
            ]);
            $userId = (int) $pdo->lastInsertId();
            if ($userId > 0 && $has('user_roles')) {
                $role = $pdo->prepare('INSERT INTO user_roles (user_uid, role_name, created_date) VALUES (?, ?, ?)');
                $role->execute([$userId, AppRoles::Software, $now]);
            }
        }

        if ($has('warehouses')) {
            $pdo->exec("INSERT INTO warehouses (warehouse_code, warehouse_name, location, contact_person, mobile_no, is_active, created_date)
                VALUES ('WH01', 'Main Warehouse', 'Main Branch', 'Admin', '0300-0000000', 1, " . $pdo->quote($now) . ")");
        }

        if ($has('terminals')) {
            $pdo->exec("INSERT INTO terminals (terminal_code, terminal_name, location, is_active, created_date)
                VALUES ('T01', 'Terminal 1', 'Counter 1', 1, " . $pdo->quote($now) . ")");
        }

        if ($has('payment_methods')) {
            foreach (['Cash', 'Credit Card', 'Bank Transfer', 'Mobile Payment'] as $method) {
                $stmt = $pdo->prepare('INSERT INTO payment_methods (payment_method_name, is_active) VALUES (?, 1)');
                $stmt->execute([$method]);
            }
        }

        if ($has('product_units')) {
            $units = [
                ['PC', 'Piece'], ['KG', 'Kilogram'], ['LTR', 'Liter'], ['BOX', 'Box'],
            ];
            $stmt = $pdo->prepare('INSERT INTO product_units (unit_code, unit_name, is_active) VALUES (?, ?, 1)');
            foreach ($units as [$code, $name]) {
                $stmt->execute([$code, $name]);
            }
        }

        if ($has('product_categories')) {
            $pdo->exec("INSERT INTO product_categories (category_code, category_name, description, is_active, created_date)
                VALUES ('GEN', 'General', 'General Products', 1, " . $pdo->quote($now) . ")");
        }

        if ($has('configurations')) {
            $configs = [
                ['system.top_navbar_title', $projectName],
                ['system.print_slip_heading', $projectName],
                ['system.print_slip_phone', ''],
                ['system.print_slip_image', ''],
            ];
            $stmt = $pdo->prepare('INSERT INTO configurations (config_key, config_value) VALUES (?, ?)');
            foreach ($configs as [$key, $value]) {
                try {
                    $stmt->execute([$key, $value]);
                } catch (Throwable $e) {
                    // ignore duplicate / schema variance
                }
            }
        }
    }
}
