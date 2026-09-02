<?php
if (!defined('ABSPATH')) { exit; }

function pnk_lock_option_name(string $name): string {
    return 'pnk_lock_' . preg_replace('~[^a-zA-Z0-9_\-]~', '_', $name);
}

function pnk_lock_new_token(): string {
    try {
        return bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        return wp_generate_password(32, false, false);
    }
}

function pnk_lock_payload(int $expires, string $token): string {
    return $expires . ':' . $token;
}

function pnk_lock_payload_expires(string $payload): int {
    $parts = explode(':', $payload, 2);
    return (int)($parts[0] ?? 0);
}

function pnk_lock_remember(string $key, string $payload): void {
    if (!isset($GLOBALS['pnk_lock_owners']) || !is_array($GLOBALS['pnk_lock_owners'])) {
        $GLOBALS['pnk_lock_owners'] = [];
    }
    $GLOBALS['pnk_lock_owners'][$key] = $payload;
}

/**
 * Acquire an owner-bound lock. Expired takeover uses a compare-and-swap SQL update.
 */
function pnk_try_lock(string $name, int $ttl_seconds): bool {
    global $wpdb;

    $key = pnk_lock_option_name($name);
    $now = time();
    $payload = pnk_lock_payload($now + max(1, $ttl_seconds), pnk_lock_new_token());
    if (add_option($key, $payload, '', 'no')) {
        pnk_lock_remember($key, $payload);
        return true;
    }

    $current = (string)get_option($key, '');
    if ($current === '' || pnk_lock_payload_expires($current) >= $now) return false;

    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->options} SET option_value=%s
         WHERE option_name=%s AND option_value=%s",
        $payload,
        $key,
        $current
    ));
    wp_cache_delete($key, 'options');
    if ($updated !== 1) return false;

    pnk_lock_remember($key, $payload);
    return true;
}

function pnk_release_lock(string $name): void {
    global $wpdb;

    $key = pnk_lock_option_name($name);
    $owners = isset($GLOBALS['pnk_lock_owners']) && is_array($GLOBALS['pnk_lock_owners'])
        ? $GLOBALS['pnk_lock_owners']
        : [];
    $payload = (string)($owners[$key] ?? '');
    if ($payload === '') return;

    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s",
        $key,
        $payload
    ));
    wp_cache_delete($key, 'options');
    unset($GLOBALS['pnk_lock_owners'][$key]);
}

/**
 * Limit concurrent heavy exports while retaining ownership of every slot.
 */
function pnk_export_slot_acquire(int $presentation_id, int $ttl_seconds = 900): bool {
    global $wpdb;

    $mutex = 'export_mutex';
    if (!pnk_try_lock($mutex, 10)) return false;

    try {
        $now = time();
        $like = $wpdb->esc_like(pnk_lock_option_name('export_active_')) . '%';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE %s
               AND CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) < %d",
            $like,
            $now
        ));

        $active = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
            $like
        ));
        if ($active >= (int)PR_EXPORT_CONCURRENCY) return false;

        return pnk_try_lock('export_active_' . $presentation_id, max(60, $ttl_seconds));
    } finally {
        pnk_release_lock($mutex);
    }
}

function pnk_export_slot_release(int $presentation_id): void {
    pnk_release_lock('export_active_' . $presentation_id);
}
