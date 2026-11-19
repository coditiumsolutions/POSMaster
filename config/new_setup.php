<?php

return [
    'enabled' => env('NEW_SETUP_ENABLED', true),

    // Plesk XML API (same server hosting lipsip)
    'plesk_url' => env('NEW_SETUP_PLESK_URL', 'https://127.0.0.1:8443'),
    'plesk_user' => env('NEW_SETUP_PLESK_USER', ''),
    'plesk_password' => env('NEW_SETUP_PLESK_PASSWORD', ''),

    'webspace_id' => (int) env('NEW_SETUP_WEBSPACE_ID', 2894),
    'webspace_name' => env('NEW_SETUP_WEBSPACE_NAME', 'e-billingbahriatownlahore.com'),
    'ip_address' => env('NEW_SETUP_IP', '192.185.10.247'),
    'base_domain' => env('NEW_SETUP_BASE_DOMAIN', 'coditium.com'),
    'sites_root' => env('NEW_SETUP_SITES_ROOT', 'D:\\InetPub\\vhosts\\e-billingbahriatownlahore.com'),

    // FTP used to copy files into sibling site folders (PHP process cannot write outside its site NTFS ACL)
    'ftp_host' => env('NEW_SETUP_FTP_HOST', '127.0.0.1'),
    'ftp_port' => (int) env('NEW_SETUP_FTP_PORT', 21),
    'ftp_user' => env('NEW_SETUP_FTP_USER', ''),
    'ftp_password' => env('NEW_SETUP_FTP_PASSWORD', ''),
    'ftp_passive' => filter_var(env('NEW_SETUP_FTP_PASSIVE', true), FILTER_VALIDATE_BOOLEAN),

    // Never allow cloning over these
    'reserved_subdomains' => [
        'lipsip', 'www', 'mail', 'ftp', 'webmail', 'cpanel', 'posphp', 'resturant', 'services',
    ],
];
