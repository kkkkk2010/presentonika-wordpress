<?php
if (!defined('ABSPATH')) { exit; }

function pnk_get_client_ip(): string {
    $remote = filter_var((string)($_SERVER['REMOTE_ADDR'] ?? ''), FILTER_VALIDATE_IP);
    if (defined('PRESENTONIKA_TRUST_CF_CONNECTING_IP') && PRESENTONIKA_TRUST_CF_CONNECTING_IP) {
        $forwarded = filter_var((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''), FILTER_VALIDATE_IP);
        if ($forwarded) return (string)$forwarded;
    }
    if ($remote) return (string)$remote;
    return '0.0.0.0';
}

/**
 * Minimal transient-based rate limiter.
 * Returns [allowed(bool), retry_after_seconds(int)].
 */
function pnk_rate_limit_allow(string $bucket, int $limit, int $windowSeconds): array {
    $now = time();
    $key = 'pnk_rl_' . md5($bucket);
    $lock_name = 'rate_limit_' . md5($bucket);

    if (function_exists('pnk_try_lock') && !pnk_try_lock($lock_name, 5)) {
        return [false, 1];
    }

    try {
        $data = get_transient($key);
        if (!is_array($data) || empty($data['reset']) || (int)$data['reset'] <= $now) {
            $data = ['count' => 0, 'reset' => $now + $windowSeconds];
        }

        $data['count'] = (int)$data['count'] + 1;
        if (!set_transient($key, $data, $windowSeconds + 5)) {
            $stored = get_transient($key);
            if (!is_array($stored) || (int)($stored['count'] ?? -1) !== (int)$data['count']) {
                return [false, 1];
            }
        }

        $retry = max(1, (int)$data['reset'] - $now);
        if ((int)$data['count'] > $limit) return [false, $retry];
        return [true, $retry];
    } finally {
        if (function_exists('pnk_release_lock')) pnk_release_lock($lock_name);
    }
}

function pnk_rest_rate_limit_or_429(string $bucket, int $limit, int $windowSeconds): ?WP_REST_Response {
    [$ok, $retryAfter] = pnk_rate_limit_allow($bucket, $limit, $windowSeconds);
    if ($ok) return null;

    $payload = [
        'ok' => false,
        'message' => 'Too Many Requests',
        'retryAfter' => $retryAfter,
        'v' => defined('PR_SAVE_HANDLER_VERSION') ? PR_SAVE_HANDLER_VERSION : '',
    ];

    $res = new WP_REST_Response($payload, 429);
    $res->header('Retry-After', (string)$retryAfter);
    return $res;
}
