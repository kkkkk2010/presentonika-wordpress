<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

final class WP_Error {
    public function __construct(private string $code, private string $message) {}
    public function get_error_code(): string { return $this->code; }
}

function is_wp_error($value): bool { return $value instanceof WP_Error; }

$GLOBALS['vkid_meta'] = [];
$GLOBALS['vkid_users'] = [
    10 => (object)['ID' => 10, 'user_pass' => password_hash('known-password', PASSWORD_DEFAULT)],
    20 => (object)['ID' => 20, 'user_pass' => password_hash('other-password', PASSWORD_DEFAULT)],
];

final class VkidWpdbMock {
    public string $usermeta = 'wp_usermeta';
    public function prepare(string $query, ...$args): array { return $args; }
    public function get_var(array $prepared): int {
        $vkId = (string)($prepared[1] ?? '');
        foreach ($GLOBALS['vkid_meta'] as $userId => $meta) {
            if (($meta['vk_id'] ?? '') === $vkId) return (int)$userId;
        }
        return 0;
    }
}
$GLOBALS['wpdb'] = new VkidWpdbMock();

function pnk_try_lock(string $name, int $ttl): bool { return true; }
function pnk_release_lock(string $name): void {}
function get_user_meta(int $userId, string $key, bool $single = true) { return $GLOBALS['vkid_meta'][$userId][$key] ?? ''; }
function update_user_meta(int $userId, string $key, string $value) { $GLOBALS['vkid_meta'][$userId][$key] = $value; return true; }
function delete_user_meta(int $userId, string $key): void { unset($GLOBALS['vkid_meta'][$userId][$key]); }
function get_user_by(string $field, int $userId) { return $GLOBALS['vkid_users'][$userId] ?? false; }
function wp_check_password(string $password, string $hash, int $userId): bool { return password_verify($password, $hash); }
function apply_filters(string $name, bool $value, object $user): bool { return $value; }

require dirname(__DIR__) . '/plugins/vk-id-auth/includes/account-linking.php';

$fail = static function (string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(1);
};

$linked = pnk_vkid_link_account(10, '123456789');
if ($linked !== true || get_user_meta(10, 'vk_id', true) !== '123456789') $fail('New link failed.');
if (pnk_vkid_link_account(10, '123456789') !== true) $fail('Idempotent link failed.');
$conflict = pnk_vkid_link_account(20, '123456789');
if (!is_wp_error($conflict) || $conflict->get_error_code() !== 'vkid_link_conflict') $fail('Conflict was not blocked.');
$denied = pnk_vkid_unlink_account(10, 'wrong-password');
if (!is_wp_error($denied) || get_user_meta(10, 'vk_id', true) === '') $fail('Unsafe unlink was allowed.');
if (pnk_vkid_unlink_account(10, 'known-password') !== true || get_user_meta(10, 'vk_id', true) !== '') $fail('Verified unlink failed.');

$pluginSource = file_get_contents(dirname(__DIR__) . '/plugins/vk-id-auth/vk-id-auth.php');
$accountPageSource = file_get_contents(dirname(__DIR__) . '/plugins/ui/includes/product-pages.php');
if (!is_string($pluginSource) || !str_contains($pluginSource, "add_shortcode('vkid_account_link'")) {
    $fail('VK account control shortcode is not registered.');
}
if (!is_string($accountPageSource) || !str_contains($accountPageSource, "do_shortcode('[vkid_account_link]')")) {
    $fail('VK account control is not mounted on the live account page.');
}

fwrite(STDOUT, "VK ID link invariants OK\n");
