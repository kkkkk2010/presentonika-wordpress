<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Structured JSONL logger for first-party Presentonika code.
 * Files are written outside public_html by default and contain no raw secrets.
 */
function pnk_log_level_value(string $level): int {
    $levels = ['debug' => 10, 'info' => 20, 'warning' => 30, 'error' => 40, 'critical' => 50];
    return $levels[strtolower($level)] ?? 20;
}

function pnk_log_truncate(string $value, int $length): string {
    return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
}

function pnk_log_request_id(): string {
    static $request_id = null;
    if (is_string($request_id)) return $request_id;

    $candidate = (string)($_SERVER['HTTP_X_REQUEST_ID'] ?? '');
    if ($candidate !== '' && preg_match('/^[a-zA-Z0-9._:-]{8,96}$/', $candidate)) {
        $request_id = $candidate;
    } else {
        try {
            $request_id = bin2hex(random_bytes(12));
        } catch (Throwable $e) {
            $request_id = wp_generate_password(24, false, false);
        }
    }
    return $request_id;
}

function pnk_log_sanitize_url(string $value): string {
    $parts = wp_parse_url($value);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return $value;

    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    $path = isset($parts['path']) ? (string)$parts['path'] : '/';
    return strtolower((string)$parts['scheme']) . '://' . strtolower((string)$parts['host']) . $port . $path;
}

function pnk_log_sanitize_string(string $value): string {
    $value = str_replace(["\r", "\n", "\0"], ['\\r', '\\n', ''], $value);
    $value = preg_replace_callback(
        '~https?://[^\s<>"\']+~i',
        static fn(array $match): string => pnk_log_sanitize_url($match[0]),
        $value
    ) ?? $value;
    $value = preg_replace('/\bBearer\s+[^\s,;]+/i', 'Bearer [redacted]', $value) ?? $value;
    $value = preg_replace(
        '/\b[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{10,}\b/',
        '[redacted-jwt]',
        $value
    ) ?? $value;
    $value = preg_replace('/\b[^\s@]+@[^\s@]+\.[^\s@]+\b/', '[redacted-email]', $value) ?? $value;
    $value = preg_replace(
        '/((?:access_token|refresh_token|id_token|saveToken|codeVerifier|client_secret|password)\s*[=:]\s*["\']?)[^\s,"\']+/i',
        '$1[redacted]',
        $value
    ) ?? $value;
    if (strlen($value) > 2000) return pnk_log_truncate($value, 2000) . '[truncated]';
    return $value;
}

function pnk_log_redact_value($value, string $key = '', int $depth = 0) {
    if ($depth > 6) return '[max-depth]';

    if (preg_match('/(?:^|_)(?:ip|remote_addr|vk_id)(?:$|_)/i', $key) && is_scalar($value)) {
        return 'hash:' . substr(hash_hmac('sha256', (string)$value, wp_salt('nonce')), 0, 16);
    }

    $sensitive_key = preg_match(
        '/(?:token|secret|password|authorization|cookie|verifier|email|first_name|last_name|device_id|state|id_token|access_token|refresh_token)/i',
        $key
    );
    if ($sensitive_key) return '[redacted]';

    if (is_array($value)) {
        $safe = [];
        $count = 0;
        foreach ($value as $child_key => $child_value) {
            if ($count++ >= 100) {
                $safe['_truncated'] = true;
                break;
            }
            $safe[$child_key] = pnk_log_redact_value($child_value, (string)$child_key, $depth + 1);
        }
        return $safe;
    }

    if (is_object($value)) {
        if ($value instanceof Throwable) {
            return [
                'type' => get_class($value),
                'message' => pnk_log_truncate(pnk_log_sanitize_string((string)$value->getMessage()), 500),
                'code' => (string)$value->getCode(),
            ];
        }
        return pnk_log_redact_value(get_object_vars($value), $key, $depth + 1);
    }

    if (!is_string($value)) return $value;

    return pnk_log_sanitize_string($value);
}

function pnk_log_directory(): string {
    if (defined('PRESENTONIKA_LOG_DIR') && trim((string)PRESENTONIKA_LOG_DIR) !== '') {
        return rtrim((string)PRESENTONIKA_LOG_DIR, '/\\');
    }
    return dirname(ABSPATH) . '/presentonika-private/logs';
}

function pnk_log_cleanup(string $directory): void {
    try {
        if (random_int(1, 100) !== 1) return;
    } catch (Throwable $e) {
        return;
    }

    $retention_days = defined('PR_LOG_RETENTION_DAYS') ? max(1, (int)PR_LOG_RETENTION_DAYS) : 30;
    $cutoff = time() - ($retention_days * DAY_IN_SECONDS);
    $files = glob(trailingslashit($directory) . 'presentonika-*.jsonl');
    if (!is_array($files)) return;

    foreach ($files as $file) {
        if (is_file($file) && (int)@filemtime($file) < $cutoff) @unlink($file);
    }
}

function pnk_log_harden_directory(string $directory): void {
    $deny_file = trailingslashit($directory) . '.htaccess';
    if (!is_file($deny_file)) {
        $deny_rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
        @file_put_contents($deny_file, $deny_rules, LOCK_EX);
        @chmod($deny_file, 0600);
    }
    $index_file = trailingslashit($directory) . 'index.html';
    if (!is_file($index_file)) {
        @file_put_contents($index_file, '', LOCK_EX);
        @chmod($index_file, 0600);
    }
}

function pnk_log_set_forward_status(bool $attempted, bool $delivered, int $code = 0): void {
    $GLOBALS['pnk_log_forward_status'] = [
        'attempted' => $attempted,
        'delivered' => $delivered,
        'code' => $code,
    ];
}

function pnk_log_forward_status(): array {
    $status = $GLOBALS['pnk_log_forward_status'] ?? null;
    return is_array($status) ? $status : ['attempted' => false, 'delivered' => false, 'code' => 0];
}

function pnk_log_forward_operational_event(array $record): void {
    pnk_log_set_forward_status(false, false);
    if (!function_exists('wp_remote_post') || !function_exists('pnk_orchestrator_base')) return;
    $level = (string)($record['level'] ?? 'info');
    $event = (string)($record['event'] ?? '');
    $forward_prefixes = ['generation.', 'billing.', 'bridge.', 'storage.', 'editor.', 'php.fatal'];
    $should_forward = pnk_log_level_value($level) >= pnk_log_level_value('error');
    foreach ($forward_prefixes as $prefix) {
        if (strpos($event, $prefix) === 0) $should_forward = true;
    }
    if (!$should_forward) return;
    if (pnk_orchestrator_base() === '' || !pnk_orchestrator_is_enabled()) return;

    $context = is_array($record['context'] ?? null) ? $record['context'] : [];
    $presentation_id = (int)($context['presentation_id'] ?? ($context['pid'] ?? 0));
    $payload = [
        'service' => 'wordpress',
        'event' => $event,
        'level' => in_array($level, ['debug', 'info', 'warning', 'error', 'critical'], true) ? $level : 'info',
        'requestId' => (string)($record['request_id'] ?? ''),
        // The orchestrator accepts canonical UTC datetimes. PHP's gmdate('c')
        // emits a +00:00 offset, while the strict event schema expects Z.
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    if ($presentation_id > 0) $payload['presentationId'] = $presentation_id;
    if (isset($context['stage']) && is_string($context['stage']) && trim($context['stage']) !== '') {
        $payload['stage'] = $context['stage'];
    }
    if (isset($context['error_code']) && is_string($context['error_code']) && trim($context['error_code']) !== '') {
        $payload['errorCode'] = $context['error_code'];
    }
    if (isset($context['duration_ms']) && is_numeric($context['duration_ms'])) $payload['durationMs'] = (float)$context['duration_ms'];
    if (isset($context['queue_age_ms']) && is_numeric($context['queue_age_ms'])) $payload['queueAgeMs'] = (float)$context['queue_age_ms'];

    // WordPress HTTP requests are not backed by a persistent event loop. A
    // fire-and-forget request can be discarded as soon as the PHP request
    // finishes, which makes the central audit trail silently incomplete.
    // Keep the timeout short, but wait for the ingest response so delivery is
    // deterministic and observable on shared hosting.
    $response = wp_remote_post(pnk_orchestrator_base() . '/observability/events', [
        'headers' => pnk_orchestrator_headers((string)$payload['requestId']),
        'body' => wp_json_encode($payload),
        'timeout' => 1.5,
        'blocking' => true,
        'redirection' => 0,
    ]);
    if (is_wp_error($response)) {
        pnk_log_set_forward_status(true, false);
        return;
    }
    $code = (int)wp_remote_retrieve_response_code($response);
    pnk_log_set_forward_status(true, $code >= 200 && $code < 300, $code);
}

if (!function_exists('pnk_log')) {
    function pnk_log(string $event, array $context = [], string $level = 'info'): void {
        $enabled = defined('PR_LOG_ENABLED') ? (bool)PR_LOG_ENABLED : true;
        if (!$enabled) return;

        $level = strtolower($level);
        $minimum = defined('PR_LOG_LEVEL') ? strtolower((string)PR_LOG_LEVEL) : 'info';
        if (pnk_log_level_value($level) < pnk_log_level_value($minimum)) return;

        $record = [
            'timestamp' => gmdate('c'),
            'level' => $level,
            'event' => preg_replace('/[^a-zA-Z0-9._:-]+/', '_', trim($event)),
            'request_id' => pnk_log_request_id(),
            'user_id' => function_exists('get_current_user_id') ? (int)get_current_user_id() : 0,
            'context' => pnk_log_redact_value($context),
            'app_version' => defined('PRESENTONIKA_CORE_VERSION') ? PRESENTONIKA_CORE_VERSION : '',
            'memory_bytes' => function_exists('memory_get_usage') ? memory_get_usage(true) : 0,
        ];
        $line = wp_json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($line)) return;

        $directory = pnk_log_directory();
        if (!is_dir($directory) && !wp_mkdir_p($directory)) {
            error_log('[PNK] ' . $line);
            return;
        }
        pnk_log_harden_directory($directory);

        $path = trailingslashit($directory) . 'presentonika-' . gmdate('Y-m-d-H') . '.jsonl';
        $written = @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            error_log('[PNK] ' . $line);
            return;
        }
        @chmod($path, 0600);
        pnk_log_cleanup($directory);
        pnk_log_forward_operational_event($record);
    }
}

function pnk_log_exception(string $event, Throwable $error, array $context = []): void {
    $context['exception'] = $error;
    pnk_log($event, $context, 'error');
}

function pnk_log_shutdown_error(): void {
    $error = error_get_last();
    if (!is_array($error) || !in_array((int)$error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;

    pnk_log('php.fatal', [
        'type' => (int)$error['type'],
        'message' => (string)$error['message'],
        'file' => (string)$error['file'],
        'line' => (int)$error['line'],
    ], 'critical');
}
register_shutdown_function('pnk_log_shutdown_error');
