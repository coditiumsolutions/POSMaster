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

    public function provider(): string
    {
        return strtolower((string) config('new_setup.provider', 'hostinger'));
    }

    public function isConfigured(): bool
    {
        if (!config('new_setup.enabled')) {
            return false;
        }

        if ($this->provider() === 'hostinger') {
            $api = $this->hostingerApi();
            $ssh = $this->hostingerSsh();

            // On Hostinger web PHP, API token is required (MCP bridge is local-only).
            if ($ssh->isLocalMode() && (string) config('new_setup.hostinger.api_token', '') === '') {
                return false;
            }

            return $api->configured() && $ssh->configured();
        }

        return $this->plesk->configured();
    }

    /**
     * Human-readable reason when New Setup is not ready (shown on the form).
     */
    public function configurationHint(): string
    {
        if ($this->provider() !== 'hostinger') {
            return 'Software must set NEW_SETUP_PLESK_USER and NEW_SETUP_PLESK_PASSWORD in .env.';
        }

        $ssh = $this->hostingerSsh();
        if ($ssh->isLocalMode()) {
            return 'Paste a Hostinger API token below (create one at https://hpanel.hostinger.com/api). '
                . 'SSH is not required on this server — provisioning uses the API and local files.';
        }

        if (!$ssh->configured()) {
            return 'Set Hostinger SSH key path (NEW_SETUP_HOSTINGER_SSH_KEY or .secrets/hostinger-ssh.env).';
        }

        return 'Set NEW_SETUP_HOSTINGER_API_TOKEN (or .secrets/hostinger-api.token), '
            . 'or keep the Cursor Hostinger MCP bridge running on your local PC.';
    }

    public function needsHostingerApiToken(): bool
    {
        return $this->provider() === 'hostinger'
            && (string) config('new_setup.hostinger.api_token', '') === '';
    }

    /**
     * Persist API token into .env (and optional .secrets file) then refresh config.
     */
    public function saveHostingerApiToken(string $token): void
    {
        if (!config('new_setup.enabled')) {
            throw new RuntimeException('Hostinger API token can only be saved on the master POSMaster site.');
        }

        $token = trim($token);
        if (strlen($token) < 20) {
            throw new RuntimeException('API token looks too short.');
        }

        $envPath = base_path('.env');
        if (!is_file($envPath) || !is_writable($envPath)) {
            throw new RuntimeException('Cannot write .env on this server.');
        }

        $contents = file_get_contents($envPath);
        if ($contents === false) {
            throw new RuntimeException('Unable to read .env.');
        }

        $pairs = [
            'NEW_SETUP_PROVIDER' => 'hostinger',
            'NEW_SETUP_HOSTINGER_LOCAL' => 'true',
            'NEW_SETUP_HOSTINGER_MCP_BRIDGE' => 'false',
            'NEW_SETUP_HOSTINGER_USERNAME' => (string) config('new_setup.hostinger.username', 'u627902364'),
            'NEW_SETUP_HOSTINGER_ORDER_ID' => (string) ((int) config('new_setup.hostinger.order_id', 1010329424)),
            'NEW_SETUP_HOSTINGER_API_TOKEN' => $token,
        ];

        foreach ($pairs as $key => $value) {
            $row = $key . '=' . $this->envValue($value);
            if (preg_match('/^' . preg_quote($key, '/') . '=.*$/m', $contents)) {
                $contents = preg_replace('/^' . preg_quote($key, '/') . '=.*$/m', $row, $contents, 1);
            } else {
                $contents = rtrim($contents) . "\n" . $row . "\n";
            }
        }

        if (file_put_contents($envPath, $contents) === false) {
            throw new RuntimeException('Failed writing API token to .env.');
        }

        $secretsDir = base_path('.secrets');
        if (is_dir($secretsDir) && is_writable($secretsDir)) {
            @file_put_contents($secretsDir . '/hostinger-api.token', $token . "\n");
        }

        config([
            'new_setup.provider' => 'hostinger',
            'new_setup.hostinger.api_token' => $token,
        ]);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($envPath, true);
        }
    }

    protected function hostingerApi(): HostingerApiClient
    {
        $cfg = (array) config('new_setup.hostinger', []);

        return new HostingerApiClient(
            (string) ($cfg['api_base'] ?? 'https://developers.hostinger.com'),
            (string) ($cfg['api_token'] ?? ''),
        );
    }

    protected function hostingerSsh(): HostingerSshClient
    {
        $cfg = (array) config('new_setup.hostinger', []);
        $user = (string) ($cfg['username'] ?? $cfg['ssh_user'] ?? '');
        $local = HostingerSshClient::detectLocalMode($user);

        return new HostingerSshClient(
            (string) ($cfg['ssh_host'] ?? ''),
            (int) ($cfg['ssh_port'] ?? 65002),
            $user !== '' ? $user : (string) ($cfg['ssh_user'] ?? ''),
            (string) ($cfg['ssh_identity_file'] ?? ''),
            $local,
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

        if ($this->provider() === 'hostinger') {
            return $this->provisionOnHostinger($input);
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

    /**
     * Hostinger: create addon website + MySQL DB via API/MCP, then deploy POS over SSH.
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
    protected function provisionOnHostinger(array $input): array
    {
        $api = $this->hostingerApi();
        $ssh = $this->hostingerSsh();

        if (!$api->configured()) {
            throw new RuntimeException(
                'Hostinger API is not configured. Set NEW_SETUP_HOSTINGER_API_TOKEN (or .secrets/hostinger-api.token), or keep the Cursor MCP bridge running.'
            );
        }
        if (!$ssh->configured()) {
            throw new RuntimeException(
                'Hostinger SSH is not configured. Check NEW_SETUP_HOSTINGER_SSH_* / .secrets/hostinger-ssh.env identity file.'
            );
        }

        $cfg = (array) config('new_setup.hostinger', []);
        $projectName = trim($input['project_name']);
        $subdomain = strtolower(trim($input['subdomain']));
        $dbNameShort = preg_replace('/[^a-zA-Z0-9_]/', '', $input['database_name']) ?? '';
        $dbPassword = (string) $input['db_password'];
        $adminUser = trim($input['admin_username']);
        $adminPassword = (string) $input['admin_password'];

        $this->validateNames($subdomain, $dbNameShort, $projectName, $adminUser);
        // Validate DB password BEFORE creating the Hostinger website (Hostinger rejects leaked passwords with 422)
        $this->validateHostingerDbPassword($dbPassword);

        $baseDomain = (string) config('new_setup.base_domain', 'coditium.com');
        $fullDomain = $subdomain . '.' . $baseDomain;
        $username = (string) ($cfg['username'] ?? '');
        $orderId = (int) ($cfg['order_id'] ?? 0);
        $gitRepo = (string) ($cfg['git_repo'] ?? 'git@github.com:coditiumsolutions/POSMaster.git');
        $gitBranch = (string) ($cfg['git_branch'] ?? 'main');
        $domainRoot = '/home/' . $username . '/domains/' . $fullDomain;
        $dbUserShort = $dbNameShort;

        if ($username === '' || $orderId <= 0) {
            throw new RuntimeException('Hostinger username/order_id missing in config.');
        }

        $steps = [];
        $createdWebsiteThisRun = false;

        try {
        // 1) Website — create new, or resume an incomplete empty shell from a previous failed attempt
        if ($api->websiteExists($fullDomain)) {
            if ($this->isCompleteHostingerPosSite($domainRoot)) {
                throw new RuntimeException(
                    "Hostinger website already exists and looks fully deployed: {$fullDomain}. Choose another subdomain."
                );
            }
            $steps[] = "Resuming incomplete Hostinger website {$fullDomain} (empty shell from a previous attempt)…";
            $api->waitForWebsiteReady($fullDomain);
            $steps[] = "Website ready: https://{$fullDomain}";
        } else {
            $steps[] = "Creating Hostinger website {$fullDomain}…";
            $api->startWebsiteSetup($orderId, $fullDomain);
            $createdWebsiteThisRun = true;
            $steps[] = 'Website setup started; waiting until ready…';
            $api->waitForWebsiteReady($fullDomain);
            $steps[] = "Website ready: https://{$fullDomain}";
        }

        // 2) Database
        $prefixedGuess = $username . '_' . $dbNameShort;
        $fullDbName = $prefixedGuess;
        $fullDbUser = $username . '_' . $dbUserShort;
        if ($api->databaseExists($username, $prefixedGuess) || $api->databaseExists($username, $dbNameShort)) {
            $fullDbName = $api->resolveFullDatabaseName($username, $dbNameShort);
            $fullDbUser = $username . '_' . $dbUserShort;
            $steps[] = "Reusing existing database: {$fullDbName}";
        } else {
            $steps[] = 'Creating MySQL database on Hostinger…';
            try {
                $api->createDatabase($username, $dbNameShort, $dbUserShort, $dbPassword, $fullDomain);
            } catch (Throwable $e) {
                // Roll back empty website so the same subdomain can be retried cleanly
                throw $e;
            }
            $fullDbName = $api->resolveFullDatabaseName($username, $dbNameShort);
            $fullDbUser = $username . '_' . $dbUserShort;
            if (!$api->databaseExists($username, $fullDbName)) {
                $fullDbName = $prefixedGuess;
                $fullDbUser = $username . '_' . $dbUserShort;
            }
            $steps[] = "Database created: {$fullDbName}";
        }

        try {
            $api->allowRemoteDatabaseAccess($username, $fullDbName, '%');
            $steps[] = 'Remote DB access allowed (for schema seed).';
        } catch (Throwable $e) {
            $steps[] = 'Remote DB access skipped: ' . $e->getMessage();
        }

        // 3) Deploy code (API git on Hostinger local mode; SSH git clone from a PC)
        $steps[] = $ssh->isLocalMode()
            ? 'Deploying POSMaster via Hostinger Git API…'
            : 'Deploying POSMaster code over SSH…';
        $this->hostingerDeployCode($api, $ssh, $username, $fullDomain, $domainRoot, $gitRepo, $gitBranch);
        $steps[] = 'Application files deployed.';

        // 4) Write .env
        $steps[] = 'Writing .env for new project…';
        $appKey = 'base64:' . base64_encode(random_bytes(32));
        $envContent = $this->buildHostingerEnvContent([
            'APP_NAME' => $projectName,
            'APP_URL' => 'https://' . $fullDomain,
            'APP_KEY' => $appKey,
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => $fullDbName,
            'DB_USERNAME' => $fullDbUser,
            'DB_PASSWORD' => $dbPassword,
            // Client copies are never the master New Setup host
            'NEW_SETUP_ENABLED' => 'false',
            'NEW_SETUP_PROVIDER' => 'hostinger',
            'NEW_SETUP_HOSTINGER_API_TOKEN' => '',
            'NEW_SETUP_HOSTINGER_LOCAL' => 'false',
            'NEW_SETUP_HOSTINGER_MCP_BRIDGE' => 'false',
            'NEW_SETUP_HOSTINGER_SSH_KEY' => '',
            'NEW_SETUP_PLESK_PASSWORD' => '',
            'NEW_SETUP_FTP_PASSWORD' => '',
        ]);
        $ssh->uploadString($domainRoot . '/.env', $envContent);
        $steps[] = '.env written.';

        // 5) Schema + seed into NEW database only (prefer external MySQL host from this machine)
        $externalHost = (string) ($cfg['db_host_external'] ?? 'srv547.hstgr.io');
        $externalPort = (int) ($cfg['db_port'] ?? 3306);
        // From Hostinger itself, localhost MySQL is preferred
        if ($ssh->isLocalMode()) {
            $externalHost = '127.0.0.1';
        }
        $steps[] = 'Importing blank schema into new database…';
        $schemaSql = $this->buildBlankSchemaSql();
        $this->importSqlIntoHostingerDatabase(
            $ssh,
            $fullDbName,
            $fullDbUser,
            $dbPassword,
            $schemaSql,
            $externalHost,
            $externalPort
        );
        $steps[] = 'Blank schema imported.';

        $steps[] = 'Seeding blank starter data…';
        $this->seedBlankDatabaseViaHostinger(
            $fullDbName,
            $fullDbUser,
            $dbPassword,
            $projectName,
            $adminUser,
            $adminPassword,
            $externalHost,
            $externalPort
        );
        $steps[] = 'Blank starter data ready.';

        // 6) Laravel post-install
        $steps[] = 'Preparing storage directories…';
        $ssh->ensureDirs([
            $domainRoot . '/storage/framework/cache',
            $domainRoot . '/storage/framework/sessions',
            $domainRoot . '/storage/framework/views',
            $domainRoot . '/storage/logs',
            $domainRoot . '/bootstrap/cache',
            $domainRoot . '/storage/app/public',
        ]);
        if (!$ssh->isLocalMode()) {
            $post = 'cd ' . $this->bashQuote($domainRoot)
                . ' && php artisan storage:link >/dev/null 2>&1 || true'
                . ' && php artisan config:clear >/dev/null 2>&1 || true'
                . ' && php artisan route:clear >/dev/null 2>&1 || true'
                . ' && php artisan view:clear >/dev/null 2>&1 || true';
            $ssh->run('bash -lc ' . $this->bashQuote($post), 180);
        }
        $steps[] = 'Storage directories ready.';

        return [
            'project_name' => $projectName,
            'domain' => $fullDomain,
            'url' => 'https://' . $fullDomain,
            'folder' => $domainRoot,
            'target_root' => $domainRoot,
            'database' => $fullDbName,
            'db_user' => $fullDbUser,
            'admin_username' => $adminUser,
            'provider' => 'hostinger',
            'steps' => $steps,
            'notes' => [
                'Live POSMaster / LipSip files and database were not modified.',
                'DNS for ' . $fullDomain . ' should already point at this Hostinger account (same as other *.coditium.com sites).',
                'SSL is usually issued automatically by Hostinger for the new addon domain.',
            ],
        ];
        } catch (Throwable $e) {
            // If this run (or an incomplete prior shell) never got a working POS, remove the empty website
            // so the same subdomain can be used again without "already exists".
            $shouldRollback = $createdWebsiteThisRun || !$this->isCompleteHostingerPosSite($domainRoot);
            if ($shouldRollback && $api->websiteExists($fullDomain) && !$this->isCompleteHostingerPosSite($domainRoot)) {
                try {
                    $api->deleteWebsite($fullDomain);
                } catch (Throwable $rollbackError) {
                    throw new RuntimeException(
                        $e->getMessage()
                        . ' Also failed to remove empty website '
                        . $fullDomain
                        . ': '
                        . $rollbackError->getMessage()
                        . ' Delete it in hPanel, then retry.'
                    );
                }
            }

            $extra = $shouldRollback
                ? ' The empty Hostinger website was removed so you can retry the same subdomain with a stronger DB password.'
                : '';
            throw new RuntimeException($e->getMessage() . $extra, 0, $e);
        }
    }

    /**
     * Hostinger rejects weak/leaked DB passwords with HTTP 422 after the website is already created.
     * Validate up-front so we do not leave orphan empty domains.
     */
    protected function validateHostingerDbPassword(string $password): void
    {
        if (strlen($password) < 10) {
            throw new RuntimeException('Database password must be at least 10 characters.');
        }
        if (!preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
            throw new RuntimeException(
                'Database password must include uppercase, lowercase, and a number (Hostinger requirement).'
            );
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            throw new RuntimeException('Database password must include at least one symbol (e.g. ! @ # $).');
        }

        // Have I Been Pwned k-anonymity check (same class of filter Hostinger uses)
        try {
            $sha1 = strtoupper(sha1($password));
            $prefix = substr($sha1, 0, 5);
            $suffix = substr($sha1, 5);
            $response = \Illuminate\Support\Facades\Http::timeout(8)
                ->withHeaders(['Add-Padding' => 'true'])
                ->get('https://api.pwnedpasswords.com/range/' . $prefix);
            if ($response->successful()) {
                foreach (preg_split('/\R/', (string) $response->body()) ?: [] as $line) {
                    $parts = explode(':', trim($line));
                    if (isset($parts[0]) && strtoupper($parts[0]) === $suffix) {
                        throw new RuntimeException(
                            'That database password has appeared in a data leak. Choose a different, unique password.'
                        );
                    }
                }
            }
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            // If the breach API is unreachable, continue — Hostinger will still enforce server-side.
        }
    }

    /**
     * True when POS files are present (not just Hostinger's default Coming Soon page).
     */
    protected function isCompleteHostingerPosSite(string $domainRoot): bool
    {
        if (is_file($domainRoot . '/artisan') && is_file($domainRoot . '/.env')) {
            return true;
        }
        // Symlinked layout
        if (is_file($domainRoot . '/public/index.php') && is_file($domainRoot . '/artisan')) {
            return true;
        }

        return false;
    }

    protected function hostingerDeployCode(
        HostingerApiClient $api,
        HostingerSshClient $ssh,
        string $username,
        string $fullDomain,
        string $domainRoot,
        string $gitRepo,
        string $gitBranch
    ): void {
        if ($ssh->isLocalMode()) {
            $publicHtml = $domainRoot . '/public_html';
            // Git deploy requires an empty/missing document root (or the same repo).
            if (is_dir($publicHtml)) {
                $ssh->deletePath($publicHtml);
            }
            if (!is_dir($publicHtml) && !@mkdir($publicHtml, 0755, true) && !is_dir($publicHtml)) {
                throw new RuntimeException('Could not recreate public_html for git deploy.');
            }

            $deploy = $api->deployGitRepository($username, $fullDomain, $gitRepo, $gitBranch, '');
            if (isset($deploy['is_success']) && $deploy['is_success'] === false) {
                $log = (string) ($deploy['log'] ?? json_encode($deploy));
                throw new RuntimeException('Hostinger git deploy failed: ' . $log);
            }

            $this->restructureLaravelDocumentRoot($domainRoot);

            return;
        }

        $script = <<<'BASH'
set -euo pipefail
DOMAIN_ROOT=__DOMAIN_ROOT__
REPO=__REPO__
BRANCH=__BRANCH__

mkdir -p "$DOMAIN_ROOT"
cd "$DOMAIN_ROOT"

if [ -d public_html ] && [ ! -L public_html ]; then
  rm -rf _panel_public_html_bak
  mv public_html _panel_public_html_bak
fi

rm -rf _pos_repo
GIT_SSH_COMMAND="ssh -o StrictHostKeyChecking=accept-new" git clone --depth 1 --branch "$BRANCH" "$REPO" _pos_repo

shopt -s dotglob nullglob
mv _pos_repo/* .
rm -rf _pos_repo

rm -rf public_html
ln -sfn public public_html

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache || true

if [ -f composer.json ]; then
  if [ ! -d vendor ]; then
    composer install --no-dev --optimize-autoloader --no-interaction 2>&1 || composer2 install --no-dev --optimize-autoloader --no-interaction 2>&1 || true
  fi
fi

echo DEPLOY_OK
BASH;

        $script = str_replace(
            ['__DOMAIN_ROOT__', '__REPO__', '__BRANCH__'],
            [$domainRoot, $gitRepo, $gitBranch],
            $script
        );

        $remotePath = '/tmp/posmaster_deploy_' . substr(sha1($domainRoot), 0, 10) . '.sh';
        $ssh->uploadString($remotePath, $script);
        $out = $ssh->run('bash ' . escapeshellarg($remotePath) . '; rm -f ' . escapeshellarg($remotePath), 900);
        if (!str_contains($out, 'DEPLOY_OK')) {
            throw new RuntimeException('Hostinger code deploy did not complete cleanly: ' . trim($out));
        }
    }

    /**
     * After git clones into public_html, move Laravel to domain root and publish public/ as a real public_html
     * (Hostinger PHP disables symlink()).
     */
    protected function restructureLaravelDocumentRoot(string $domainRoot): void
    {
        $publicHtml = $domainRoot . '/public_html';
        if (!is_dir($publicHtml . '/artisan') && !is_file($publicHtml . '/artisan')) {
            // Already restructured, or unexpected layout
            if (is_file($domainRoot . '/artisan') && is_dir($domainRoot . '/public')) {
                $this->publishPublicToPublicHtml($domainRoot);

                return;
            }
            throw new RuntimeException('Git deploy did not place Laravel files in public_html.');
        }

        $skip = ['.', '..'];
        $items = scandir($publicHtml);
        if ($items === false) {
            throw new RuntimeException('Unable to read public_html after git deploy.');
        }
        foreach ($items as $item) {
            if (in_array($item, $skip, true)) {
                continue;
            }
            $from = $publicHtml . '/' . $item;
            $to = $domainRoot . '/' . $item;
            if (file_exists($to)) {
                $this->hostingerSsh()->deletePath($to);
            }
            if (!@rename($from, $to)) {
                throw new RuntimeException('Failed moving ' . $item . ' out of public_html.');
            }
        }
        @rmdir($publicHtml);
        $this->publishPublicToPublicHtml($domainRoot);
    }

    protected function publishPublicToPublicHtml(string $domainRoot): void
    {
        $public = $domainRoot . '/public';
        $publicHtml = $domainRoot . '/public_html';
        if (!is_dir($public)) {
            throw new RuntimeException('Laravel public/ folder missing after deploy.');
        }
        if (is_link($publicHtml) || is_file($publicHtml)) {
            @unlink($publicHtml);
        }
        if (is_dir($publicHtml)) {
            $this->hostingerSsh()->deletePath($publicHtml);
        }
        if (!@mkdir($publicHtml, 0755, true) && !is_dir($publicHtml)) {
            throw new RuntimeException('Could not create public_html directory.');
        }
        $this->copyDirectory($public, $publicHtml);
    }

    protected function copyDirectory(string $src, string $dst): void
    {
        if (!is_dir($dst) && !@mkdir($dst, 0755, true) && !is_dir($dst)) {
            throw new RuntimeException('Could not create directory: ' . $dst);
        }
        $items = scandir($src);
        if ($items === false) {
            throw new RuntimeException('Could not read directory: ' . $src);
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $from = $src . '/' . $item;
            $to = $dst . '/' . $item;
            if (is_dir($from)) {
                $this->copyDirectory($from, $to);
            } else {
                if (!@copy($from, $to)) {
                    throw new RuntimeException('Could not copy file: ' . $from);
                }
            }
        }
    }

    /**
     * @param  array<string,string>  $overrides
     */
    protected function buildHostingerEnvContent(array $overrides): string
    {
        $sourceEnv = base_path('.env');
        $lines = is_file($sourceEnv) ? file($sourceEnv, FILE_IGNORE_NEW_LINES) : [];
        if ($lines === false) {
            $lines = [];
        }

        // Never copy master provisioning secrets into client site .env
        $masterOnlyDefaults = [
            'NEW_SETUP_ENABLED' => 'false',
            'NEW_SETUP_HOSTINGER_API_TOKEN' => '',
            'NEW_SETUP_HOSTINGER_LOCAL' => 'false',
            'NEW_SETUP_HOSTINGER_MCP_BRIDGE' => 'false',
            'NEW_SETUP_HOSTINGER_SSH_KEY' => '',
            'NEW_SETUP_PLESK_PASSWORD' => '',
            'NEW_SETUP_FTP_PASSWORD' => '',
        ];
        $map = array_merge($masterOnlyDefaults, $overrides);
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

        return implode("\n", $out) . "\n";
    }

    protected function importSqlIntoHostingerDatabase(
        HostingerSshClient $ssh,
        string $dbName,
        string $dbUser,
        string $dbPassword,
        string $sql,
        string $externalHost,
        int $externalPort
    ): void {
        try {
            $dsn = "mysql:host={$externalHost};port={$externalPort};dbname={$dbName};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPassword, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 30,
            ]);
            $parts = preg_split('/;\s*\n/', $sql) ?: [];
            foreach ($parts as $part) {
                $stmt = trim($part);
                if ($stmt === '' || str_starts_with($stmt, '--')) {
                    continue;
                }
                $pdo->exec($stmt);
            }

            return;
        } catch (Throwable $e) {
            // Fall back to server-side mysql with defaults file + --skip-sandbox
            // (Hostinger CLI defaults to sandbox which blocks file redirects.)
        }

        $remoteSql = '/tmp/posmaster_schema_' . substr(sha1($dbName), 0, 10) . '.sql';
        $remoteCnf = '/tmp/posmaster_my_' . substr(sha1($dbName), 0, 10) . '.cnf';
        $ssh->uploadString($remoteSql, $sql);
        $cnf = "[client]\nhost=127.0.0.1\nport=3306\nuser={$dbUser}\npassword={$dbPassword}\n";
        $ssh->uploadString($remoteCnf, $cnf);
        $safe = 'mysql --defaults-extra-file=' . $this->bashQuote($remoteCnf)
            . ' --skip-sandbox --protocol=TCP '
            . $this->bashQuote($dbName)
            . ' < ' . $this->bashQuote($remoteSql)
            . '; ec=$?; rm -f ' . $this->bashQuote($remoteSql) . ' ' . $this->bashQuote($remoteCnf)
            . '; exit $ec';
        try {
            $ssh->run('bash -lc ' . $this->bashQuote($safe), 600);
        } catch (Throwable $e2) {
            throw new RuntimeException(
                'Could not import schema into Hostinger database. '
                . 'External PDO failed and SSH mysql failed: ' . $e2->getMessage()
            );
        }
    }

    protected function bashQuote(string $value): string
    {
        return "'" . str_replace("'", "'\"'\"'", $value) . "'";
    }

    protected function seedBlankDatabaseViaHostinger(
        string $dbName,
        string $user,
        string $password,
        string $projectName,
        string $adminUser,
        string $adminPassword,
        string $externalHost,
        int $externalPort
    ): void {
        // Prefer external host (remote access). Fall back to localhost via SSH-imported seed SQL if PDO fails.
        try {
            $dsn = "mysql:host={$externalHost};port={$externalPort};dbname={$dbName};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 20,
            ]);
            $this->seedBlankDatabaseWithPdo($pdo, $projectName, $adminUser, $adminPassword);
            return;
        } catch (Throwable $e) {
            // Fall through to local connection using same credentials if app DB host already is Hostinger
        }

        try {
            $this->seedBlankDatabase($dbName, $user, $password, $projectName, $adminUser, $adminPassword);
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Could not seed new Hostinger database. Ensure remote MySQL access is allowed. Detail: ' . $e->getMessage()
            );
        }
    }

    protected function seedBlankDatabaseWithPdo(
        PDO $pdo,
        string $projectName,
        string $adminUser,
        string $adminPassword
    ): void {
        $now = date('Y-m-d H:i:s');
        $hash = password_hash($adminPassword, PASSWORD_BCRYPT);

        $has = function (string $table) use ($pdo): bool {
            $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
            return $stmt && $stmt->fetchColumn() !== false;
        };

        if ($has('branches')) {
            $pdo->exec("INSERT INTO branches (branch_code, branch_name, address, city, contact_person, mobile_no, is_active, created_date)
                VALUES ('MAIN', 'Main Branch', 'Main Street', 'City', 'Admin', '0300-0000000', 1, " . $pdo->quote($now) . ')');
        }

        if ($has('users')) {
            $stmt = $pdo->prepare('INSERT INTO users (user_name, full_name, password_hash, email, role_name, branch, is_active, created_date)
                VALUES (?, ?, ?, ?, ?, ?, 1, ?)');
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
                VALUES ('WH01', 'Main Warehouse', 'Main Branch', 'Admin', '0300-0000000', 1, " . $pdo->quote($now) . ')');
        }

        if ($has('terminals')) {
            $pdo->exec("INSERT INTO terminals (terminal_code, terminal_name, location, is_active, created_date)
                VALUES ('T01', 'Terminal 1', 'Counter 1', 1, " . $pdo->quote($now) . ')');
        }

        if ($has('payment_methods')) {
            foreach (['Cash', 'Credit Card', 'Bank Transfer', 'Mobile Payment'] as $method) {
                $stmt = $pdo->prepare('INSERT INTO payment_methods (payment_method_name, is_active) VALUES (?, 1)');
                $stmt->execute([$method]);
            }
        }

        if ($has('product_units')) {
            $units = [['PC', 'Piece'], ['KG', 'Kilogram'], ['LTR', 'Liter'], ['BOX', 'Box']];
            $stmt = $pdo->prepare('INSERT INTO product_units (unit_code, unit_name, is_active) VALUES (?, ?, 1)');
            foreach ($units as [$code, $name]) {
                $stmt->execute([$code, $name]);
            }
        }

        if ($has('product_categories')) {
            $pdo->exec("INSERT INTO product_categories (category_code, category_name, description, is_active, created_date)
                VALUES ('GEN', 'General', 'General Products', 1, " . $pdo->quote($now) . ')');
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
