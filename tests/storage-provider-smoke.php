<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('PRESENTONIKA_STORAGE_PROVIDER', 'yandex_object_storage');
define('PRESENTONIKA_YANDEX_STORAGE_BUCKET', 'presentonika-private-test');
define('PRESENTONIKA_YANDEX_STORAGE_ACCESS_KEY_ID', 'placeholder-access');
define('PRESENTONIKA_YANDEX_STORAGE_SECRET_ACCESS_KEY', 'placeholder-secret');

final class WP_Error {
    public function __construct(public string $code, public string $message) {}
}

function wp_parse_url(string $url, int $component) { return parse_url($url, $component); }
function wp_generate_password(int $length): string { return str_repeat('a', $length); }
function pnk_log(string $event, array $context = [], string $level = 'info'): void {}

require dirname(__DIR__) . '/plugins/core/includes/storage-yandex.php';

$fail = static function (string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(1);
};

if (pnk_storage_provider() !== 'yandex_object_storage') $fail('Provider selection failed.');
if (pnk_yandex_storage_query(['z' => 'a b', 'a' => '/']) !== 'a=%2F&z=a%20b') $fail('Canonical query failed.');
$key = pnk_yandex_storage_key('presentations/12/34/20260821-aaaaaaaaaa.out.zip');
$parsed = pnk_yandex_storage_parse_key($key);
if (!$parsed || $parsed['user_id'] !== 12 || $parsed['presentation_id'] !== 34) $fail('Cloud key roundtrip failed.');
if (pnk_yandex_storage_parse_key('yandex:v1/../secret') !== null) $fail('Unsafe cloud key accepted.');

$derived = bin2hex(pnk_yandex_storage_signing_key(
    'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
    '20150830',
    'us-east-1'
));
if ($derived !== '32f78051dcde24c552811d654f4a769112bb834b03975cdd6b1fd7d16248c269') {
    $fail('Signature V4 key derivation failed.');
}

fwrite(STDOUT, "Storage provider invariants OK\n");
