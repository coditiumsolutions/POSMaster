<?php

$tokenFromSecrets = '';
$secretsTokenPath = base_path('.secrets/hostinger-api.token');
if (is_file($secretsTokenPath)) {
    $tokenFromSecrets = trim((string) file_get_contents($secretsTokenPath));
}

$sshFromFile = [];
$sshEnvPath = base_path('.secrets/hostinger-ssh.env');
if (is_file($sshEnvPath)) {
    foreach (file($sshEnvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $sshFromFile[trim($k)] = trim($v, " \t\"'");
    }
}

$sshKeyDefault = $sshFromFile['HOSTINGER_SSH_IDENTITY_FILE']
    ?? (str_replace('\\', '/', (string) env('USERPROFILE', env('HOME', ''))) . '/.ssh/id_ed25519');

return [
    'enabled' => filter_var(env('NEW_SETUP_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    // hostinger | plesk
    'provider' => strtolower((string) env('NEW_SETUP_PROVIDER', 'hostinger')),

    'base_domain' => env('NEW_SETUP_BASE_DOMAIN', 'coditium.com'),

    // Fixed credentials for every new client copy (not collected on the form)
    'default_db_password' => env('NEW_SETUP_DB_PASSWORD', 'Coditium$Solutions@2020'),
    'default_admin_username' => env('NEW_SETUP_ADMIN_USERNAME', 'Admin'),
    'default_admin_password' => env('NEW_SETUP_ADMIN_PASSWORD', 'Coditium$2020'),

    // Never allow cloning over these
    'reserved_subdomains' => [
        'lipsip', 'lipsipp', 'lipsippp', 'www', 'mail', 'ftp', 'webmail', 'cpanel',
        'posphp', 'posmaster', 'resturant', 'services', 'msg', 'ride',
    ],

    'hostinger' => [
        'api_base' => env('NEW_SETUP_HOSTINGER_API_BASE', 'https://developers.hostinger.com'),
        'api_token' => env('NEW_SETUP_HOSTINGER_API_TOKEN', env('HOSTINGER_API_TOKEN', $tokenFromSecrets)),
        'username' => env('NEW_SETUP_HOSTINGER_USERNAME', $sshFromFile['HOSTINGER_SSH_USER'] ?? 'u627902364'),
        'order_id' => (int) env('NEW_SETUP_HOSTINGER_ORDER_ID', $sshFromFile['HOSTINGER_ORDER_ID'] ?? 1010329424),
        'db_host_external' => env('NEW_SETUP_HOSTINGER_DB_HOST', 'srv547.hstgr.io'),
        'db_port' => (int) env('NEW_SETUP_HOSTINGER_DB_PORT', 3306),
        'git_repo' => env(
            'NEW_SETUP_HOSTINGER_GIT_REPO',
            'git@github.com:' . ($sshFromFile['HOSTINGER_GIT_REPOSITORY'] ?? 'coditiumsolutions/POSMaster') . '.git'
        ),
        'git_branch' => env('NEW_SETUP_HOSTINGER_GIT_BRANCH', $sshFromFile['HOSTINGER_GIT_BRANCH'] ?? 'main'),
        'ssh_host' => env('NEW_SETUP_HOSTINGER_SSH_HOST', $sshFromFile['HOSTINGER_SSH_HOST'] ?? 'sg-nme-web547.main-hosting.eu'),
        'ssh_port' => (int) env('NEW_SETUP_HOSTINGER_SSH_PORT', $sshFromFile['HOSTINGER_SSH_PORT'] ?? 65002),
        'ssh_user' => env('NEW_SETUP_HOSTINGER_SSH_USER', $sshFromFile['HOSTINGER_SSH_USER'] ?? 'u627902364'),
        'ssh_identity_file' => env('NEW_SETUP_HOSTINGER_SSH_KEY', $sshKeyDefault),
    ],

    // Legacy Plesk XML API (kept for fallback)
    'plesk_url' => env('NEW_SETUP_PLESK_URL', 'https://127.0.0.1:8443'),
    'plesk_user' => env('NEW_SETUP_PLESK_USER', ''),
    'plesk_password' => env('NEW_SETUP_PLESK_PASSWORD', ''),
    'webspace_id' => (int) env('NEW_SETUP_WEBSPACE_ID', 2894),
    'webspace_name' => env('NEW_SETUP_WEBSPACE_NAME', 'e-billingbahriatownlahore.com'),
    'ip_address' => env('NEW_SETUP_IP', '192.185.10.247'),
    'sites_root' => env('NEW_SETUP_SITES_ROOT', 'D:\\InetPub\\vhosts\\e-billingbahriatownlahore.com'),
    'ftp_host' => env('NEW_SETUP_FTP_HOST', '127.0.0.1'),
    'ftp_port' => (int) env('NEW_SETUP_FTP_PORT', 21),
    'ftp_user' => env('NEW_SETUP_FTP_USER', ''),
    'ftp_password' => env('NEW_SETUP_FTP_PASSWORD', ''),
    'ftp_passive' => filter_var(env('NEW_SETUP_FTP_PASSIVE', true), FILTER_VALIDATE_BOOLEAN),
];
