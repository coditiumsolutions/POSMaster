<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class HostingerApiClient
{
    protected string $queueDir;

    public function __construct(
        protected string $baseUrl,
        protected string $apiToken,
        ?string $queueDir = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->queueDir = $queueDir ?: base_path('.secrets/hostinger-queue');
    }

    public function configured(): bool
    {
        return $this->apiToken !== '' || $this->bridgeEnabled();
    }

    public function usesBridge(): bool
    {
        return $this->apiToken === '' && $this->bridgeEnabled();
    }

    protected function bridgeEnabled(): bool
    {
        // Cursor MCP queue only works on a local PC with .secrets/ — never on Hostinger web PHP.
        if (HostingerSshClient::detectLocalMode()) {
            return false;
        }

        $flag = env('NEW_SETUP_HOSTINGER_MCP_BRIDGE');
        $enabled = $flag === null || $flag === ''
            ? true
            : filter_var($flag, FILTER_VALIDATE_BOOLEAN);

        return $enabled && is_dir(dirname($this->queueDir));
    }

    /**
     * @return array<string,mixed>
     */
    public function listWebsites(?string $domain = null): array
    {
        $params = ['per_page' => 100];
        if ($domain) {
            $params['domain'] = $domain;
        }

        return $this->call('hosting_websites_list', $params, 'GET', '/api/hosting/v1/websites', $params);
    }

    public function websiteExists(string $domain): bool
    {
        $res = $this->listWebsites($domain);
        $items = $res['data'] ?? $res;
        if (!is_array($items)) {
            return false;
        }
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (strcasecmp((string) ($row['domain'] ?? ''), $domain) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,mixed>
     */
    public function startWebsiteSetup(int $orderId, string $domain): array
    {
        return $this->call(
            'hosting_websites_create',
            ['order_id' => $orderId, 'domain' => $domain],
            'POST',
            '/api/hosting/v1/websites',
            [],
            ['order_id' => $orderId, 'domain' => $domain]
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listWebsiteSetups(?string $domain = null): array
    {
        $params = [];
        if ($domain) {
            $params['domain'] = $domain;
        }
        // Current Hostinger API: GET /api/hosting/v1/onboardings (not /websites/setups)
        $res = $this->call(
            'hosting_websites_list-setups',
            $params,
            'GET',
            '/api/hosting/v1/onboardings',
            $params
        );
        if (isset($res[0]) || $res === []) {
            return array_values(array_filter($res, 'is_array'));
        }

        return array_values(array_filter($res['data'] ?? [], 'is_array'));
    }

    public function waitForWebsiteReady(string $domain, int $timeoutSeconds = 360): void
    {
        $deadline = time() + $timeoutSeconds;
        $last = 'unknown';
        $seenInList = false;

        while (time() < $deadline) {
            $setups = [];
            try {
                $setups = $this->listWebsiteSetups($domain);
            } catch (RuntimeException $e) {
                // Fall back to website list polling if onboardings endpoint changes again
                $last = 'onboardings_unavailable';
            }

            foreach ($setups as $setup) {
                if (strcasecmp((string) ($setup['domain'] ?? ''), $domain) !== 0) {
                    continue;
                }
                $last = (string) ($setup['status'] ?? '');
                if ($last === 'completed') {
                    return;
                }
                if ($last === 'failed') {
                    throw new RuntimeException("Hostinger website setup failed for {$domain}.");
                }
            }

            if ($this->websiteExists($domain)) {
                $seenInList = true;
                // Setup list can drop entries after completion; accept once listed and not failed.
                if ($last === 'completed' || $setups === [] || $last === 'onboardings_unavailable' || $last === 'unknown') {
                    return;
                }
            }

            sleep(12);
        }

        if ($seenInList && $last !== 'failed') {
            return;
        }

        throw new RuntimeException("Timed out waiting for Hostinger website {$domain} (last status: {$last}).");
    }

    /**
     * @return array<string,mixed>
     */
    public function listDatabases(string $username, ?string $search = null): array
    {
        $params = ['username' => $username, 'per_page' => 100];
        if ($search) {
            $params['search'] = $search;
        }
        $query = ['per_page' => 100];
        if ($search) {
            $query['search'] = $search;
        }

        return $this->call(
            'hosting_databases_list',
            $params,
            'GET',
            "/api/hosting/v1/accounts/{$username}/databases",
            $query
        );
    }

    public function databaseExists(string $username, string $fullDbName): bool
    {
        $res = $this->listDatabases($username, $fullDbName);
        $items = $res['data'] ?? $res;
        if (!is_array($items)) {
            return false;
        }
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (strcasecmp((string) ($row['name'] ?? ''), $fullDbName) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,mixed>
     */
    public function createDatabase(
        string $username,
        string $name,
        string $user,
        string $password,
        string $websiteDomain
    ): array {
        $params = [
            'username' => $username,
            'name' => $name,
            'user' => $user,
            'password' => $password,
            'website_domain' => $websiteDomain,
        ];

        return $this->call(
            'hosting_databases_create',
            $params,
            'POST',
            "/api/hosting/v1/accounts/{$username}/databases",
            [],
            [
                'name' => $name,
                'user' => $user,
                'password' => $password,
                'website_domain' => $websiteDomain,
            ]
        );
    }

    public function allowRemoteDatabaseAccess(string $username, string $fullDbName, string $ip = '%'): void
    {
        $this->call(
            'hosting_databases_create-remote-connection',
            ['username' => $username, 'name' => $fullDbName, 'ip' => $ip],
            'POST',
            "/api/hosting/v1/accounts/{$username}/databases/" . rawurlencode($fullDbName) . '/remote-connections',
            [],
            ['ip' => $ip]
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function deployGitRepository(
        string $username,
        string $domain,
        string $repositoryUrl,
        string $branch,
        string $directory = ''
    ): array {
        $params = [
            'username' => $username,
            'domain' => $domain,
            'repository_url' => $repositoryUrl,
            'branch' => $branch,
        ];
        $body = [
            'repository_url' => $repositoryUrl,
            'branch' => $branch,
        ];
        if ($directory !== '') {
            $params['directory'] = $directory;
            $body['directory'] = $directory;
        }

        return $this->call(
            'hosting_git_deploy-website-repository',
            $params,
            'POST',
            "/api/hosting/v1/accounts/{$username}/websites/" . rawurlencode($domain) . '/git/repositories/deploy',
            [],
            $body
        );
    }

    public function resolveFullDatabaseName(string $username, string $shortName): string
    {
        $shortName = preg_replace('/[^a-zA-Z0-9_]/', '', $shortName) ?? '';
        $prefixed = $username . '_' . $shortName;
        if ($this->databaseExists($username, $prefixed)) {
            return $prefixed;
        }
        if ($this->databaseExists($username, $shortName)) {
            return $shortName;
        }

        return $prefixed;
    }

    /**
     * @param  array<string,mixed>  $mcpParams
     * @param  array<string,mixed>  $query
     * @param  array<string,mixed>|null  $body
     * @return array<string,mixed>
     */
    protected function call(
        string $mcpOperation,
        array $mcpParams,
        string $httpMethod,
        string $path,
        array $query = [],
        ?array $body = null
    ): array {
        if ($this->apiToken !== '') {
            return $this->httpRequest(strtolower($httpMethod), $path, $query, $body);
        }

        if (!$this->bridgeEnabled()) {
            throw new RuntimeException(
                'Hostinger API token is not configured. Set NEW_SETUP_HOSTINGER_API_TOKEN or place it in .secrets/hostinger-api.token'
            );
        }

        return $this->bridgeRequest($mcpOperation, $mcpParams);
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    protected function bridgeRequest(string $operation, array $params): array
    {
        if (!is_dir($this->queueDir) && !@mkdir($this->queueDir, 0775, true) && !is_dir($this->queueDir)) {
            throw new RuntimeException('Unable to create Hostinger MCP bridge queue directory.');
        }

        $id = str_replace('.', '', uniqid('op', true));
        $reqPath = $this->queueDir . DIRECTORY_SEPARATOR . $id . '.req.json';
        $resPath = $this->queueDir . DIRECTORY_SEPARATOR . $id . '.res.json';

        $payload = [
            'id' => $id,
            'operation' => $operation,
            'params' => $params,
            'created_at' => date('c'),
        ];
        if (file_put_contents($reqPath, json_encode($payload, JSON_PRETTY_PRINT)) === false) {
            throw new RuntimeException('Unable to queue Hostinger MCP bridge request.');
        }

        $deadline = time() + 420;
        while (time() < $deadline) {
            if (is_file($resPath)) {
                $raw = file_get_contents($resPath);
                @unlink($reqPath);
                @unlink($resPath);
                $decoded = json_decode((string) $raw, true);
                if (!is_array($decoded)) {
                    throw new RuntimeException('Invalid Hostinger MCP bridge response.');
                }
                if (!empty($decoded['error'])) {
                    throw new RuntimeException('Hostinger MCP bridge error: ' . (string) $decoded['error']);
                }
                $result = $decoded['result'] ?? [];
                return is_array($result) ? $result : [];
            }
            usleep(400000);
        }

        @unlink($reqPath);
        throw new RuntimeException(
            'Timed out waiting for Hostinger MCP bridge. Keep the Cursor agent running, or set NEW_SETUP_HOSTINGER_API_TOKEN.'
        );
    }

    /**
     * @param  array<string,mixed>  $query
     * @param  array<string,mixed>|null  $body
     * @return array<string,mixed>
     */
    protected function httpRequest(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $url = $this->baseUrl . $path;
        $pending = Http::withToken($this->apiToken)
            ->acceptJson()
            ->timeout(180)
            ->withHeaders(['Content-Type' => 'application/json']);

        $response = $method === 'get'
            ? $pending->get($url, $query)
            : $pending->post($url, $body ?? []);

        if ($response->status() === 401 || $response->status() === 403) {
            throw new RuntimeException('Hostinger API authentication failed. Check NEW_SETUP_HOSTINGER_API_TOKEN.');
        }

        if ($response->failed()) {
            $message = $response->json('message')
                ?? $response->json('error.message')
                ?? $response->body();
            throw new RuntimeException('Hostinger API error (' . $response->status() . '): ' . $message);
        }

        $json = $response->json();
        return is_array($json) ? $json : [];
    }
}
