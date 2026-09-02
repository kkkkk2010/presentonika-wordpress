<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$required = [
    'plugins/core/presentonika-core.php',
    'plugins/core/includes/storage.php',
    'plugins/core/includes/doctor.php',
    'plugins/core/includes/logger.php',
    'plugins/core/includes/locks.php',
    'plugins/core/includes/bridge.php',
    'plugins/vk-id-auth/vk-id-auth.php',
    'plugins/vk-id-auth/assets/js/vkid-sdk-2.6.1.js',
    'plugins/ui/presentonika-ui.php',
];

foreach ($required as $relative) {
    if (!is_file($root . '/' . $relative)) {
        fwrite(STDERR, "Missing required P0 file: {$relative}\n");
        exit(1);
    }
}

$storage = file_get_contents($root . '/plugins/core/includes/storage.php');
$vk = file_get_contents($root . '/plugins/vk-id-auth/vk-id-auth.php');
$rest = file_get_contents($root . '/plugins/core/includes/rest.php');
$logger = file_get_contents($root . '/plugins/core/includes/logger.php');
$assertions = [
    'private storage key' => is_string($storage) && str_contains($storage, 'private:v1/'),
    'signed download' => is_string($storage) && str_contains($storage, 'pnk_storage_signed_url'),
    'storage CAS' => is_string($storage) && str_contains($storage, 'storage changed during migration'),
    'PKCE transaction' => is_string($vk) && str_contains($vk, 'code_verifier'),
    'one-time OAuth state' => is_string($vk) && str_contains($vk, 'consume_oauth_transaction'),
    'bridge validation' => is_string($rest) && str_contains($rest, 'PRESENTONIKA_SAVE_VALIDATE_BEARER'),
    'blocking observability delivery' => is_string($logger)
        && str_contains($logger, "'blocking' => true")
        && str_contains($logger, "'/observability/events'")
        && str_contains($logger, 'wp_remote_retrieve_response_code')
        && str_contains($logger, "gmdate('Y-m-d\\TH:i:s\\Z')")
        && str_contains($logger, "trim(\$context['error_code']) !== ''"),
    'observability delivery status' => is_string($rest)
        && str_contains($rest, 'pnk_log_forward_status')
        && str_contains($rest, "'centralized'"),
];

foreach ($assertions as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "P0 invariant missing: {$label}\n");
        exit(1);
    }
}

fwrite(STDOUT, 'P0 invariants OK (' . count($assertions) . ")\n");
