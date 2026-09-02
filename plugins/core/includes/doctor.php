<?php
if (!defined('ABSPATH')) { exit; }

function pnk_doctor_item(string $id, string $status, string $message): array {
    return [
        'id' => $id,
        'status' => in_array($status, ['ok', 'warning', 'error'], true) ? $status : 'error',
        'message' => $message,
    ];
}

function pnk_doctor_directory_file_count(string $directory): int {
    if (!is_dir($directory)) return 0;

    $count = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $file_info) {
        if ($file_info->isFile()) $count++;
    }
    return $count;
}

function pnk_doctor_checks(): array {
    global $wpdb;

    $checks = [];
    $checks[] = pnk_doctor_item(
        'php.version',
        version_compare(PHP_VERSION, '8.1.0', '>=') ? 'ok' : 'error',
        'PHP ' . PHP_VERSION . ' (required: 8.1+)'
    );

    foreach (['json', 'hash', 'openssl', 'mbstring', 'zip', 'curl'] as $extension) {
        $checks[] = pnk_doctor_item(
            'php.extension.' . $extension,
            extension_loaded($extension) ? 'ok' : 'error',
            extension_loaded($extension) ? 'loaded' : 'missing'
        );
    }

    $private_root = defined('PRESENTONIKA_PRIVATE_DIR')
        ? rtrim(wp_normalize_path((string)PRESENTONIKA_PRIVATE_DIR), '/')
        : rtrim(wp_normalize_path(dirname(ABSPATH) . '/presentonika-private'), '/');
    $private_ready = pnk_storage_ensure_directory($private_root);
    $private_real = $private_ready ? realpath($private_root) : false;
    $document_real = realpath(ABSPATH);
    $private_outside_webroot = $private_real && $document_real
        && strpos(
            rtrim(wp_normalize_path((string)$private_real), '/') . '/',
            rtrim(wp_normalize_path((string)$document_real), '/') . '/'
        ) !== 0;
    $checks[] = pnk_doctor_item(
        'storage.private_root',
        $private_ready && is_writable($private_root) && $private_outside_webroot ? 'ok' : 'error',
        $private_ready && is_writable($private_root) && $private_outside_webroot
            ? 'writable and outside public_html'
            : 'must be writable and outside public_html'
    );

    $provider = pnk_storage_provider();
    $checks[] = pnk_doctor_item(
        'storage.provider',
        in_array($provider, ['local', 'yandex_object_storage'], true) ? 'ok' : 'error',
        $provider
    );
    if ($provider === 'yandex_object_storage') {
        $cloud = pnk_yandex_storage_doctor();
        $checks[] = pnk_doctor_item(
            'storage.cloud.configuration',
            $cloud['configured'] ? 'ok' : 'error',
            $cloud['configured'] ? 'credentials and bucket configured' : 'configuration is incomplete'
        );
        $checks[] = pnk_doctor_item(
            'storage.cloud.connectivity',
            $cloud['connected'] ? 'ok' : 'error',
            $cloud['connected'] ? 'private bucket reachable' : 'bucket is not reachable with configured service account'
        );
        $checks[] = pnk_doctor_item(
            'storage.cloud.public_acl',
            $cloud['private'] ? 'ok' : 'error',
            $cloud['private'] ? 'no public ACL grants detected' : 'bucket ACL could not be proven private'
        );
    }

    $table = pnk_table_name();
    $ledger = pnk_points_ledger_table_name();
    $schema_ready = $table !== '' && pnk_db_schema_is_ready($table, $ledger);
    $checks[] = pnk_doctor_item(
        'database.schema',
        $schema_ready ? 'ok' : 'error',
        $schema_ready ? 'schema v' . PNK_DB_SCHEMA_VERSION . ' is ready' : 'run schema upgrade and inspect db logs'
    );

    foreach ([$table, $ledger] as $checked_table) {
        if ($checked_table === '') continue;
        $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name=%s', $checked_table));
        $is_innodb = $status && strcasecmp((string)$status->Engine, 'InnoDB') === 0;
        $checks[] = pnk_doctor_item(
            'database.innodb.' . $checked_table,
            $is_innodb ? 'ok' : 'error',
            $is_innodb ? 'InnoDB' : 'table is missing or not InnoDB'
        );
    }

    $required_constants = [
        'PRESENTONIKA_BRIDGE_TOKEN',
        'PRESENTONIKA_SAVE_VALIDATE_BEARER',
        'PRESENTONIKA_ORCHESTRATOR_KEY',
    ];
    foreach ($required_constants as $constant_name) {
        $configured = defined($constant_name) && trim((string)constant($constant_name)) !== '';
        $checks[] = pnk_doctor_item(
            'config.' . strtolower($constant_name),
            $configured ? 'ok' : 'error',
            $configured ? 'configured' : 'missing'
        );
    }

    foreach ([
        'editor' => pnk_editor_base(),
        'orchestrator' => defined('PRESENTONIKA_ORCHESTRATOR_BASE') ? (string)PRESENTONIKA_ORCHESTRATOR_BASE : '',
    ] as $service => $url) {
        $is_https = strtolower((string)wp_parse_url($url, PHP_URL_SCHEME)) === 'https'
            && (string)wp_parse_url($url, PHP_URL_HOST) !== '';
        $checks[] = pnk_doctor_item(
            'config.' . $service . '_https',
            $is_https ? 'ok' : 'error',
            $is_https ? 'HTTPS endpoint configured' : 'valid HTTPS endpoint required'
        );
    }

    $debug_disabled = !defined('WP_DEBUG') || WP_DEBUG === false;
    $checks[] = pnk_doctor_item(
        'wordpress.debug',
        $debug_disabled ? 'ok' : 'error',
        $debug_disabled ? 'disabled' : 'WP_DEBUG must be false'
    );

    $has_action_scheduler = function_exists('as_schedule_single_action');
    $wp_cron_available = !defined('DISABLE_WP_CRON') || DISABLE_WP_CRON === false;
    $scheduler_ok = $has_action_scheduler || $wp_cron_available;
    $checks[] = pnk_doctor_item(
        'scheduler.runner',
        $scheduler_ok ? 'ok' : 'error',
        $has_action_scheduler
            ? 'Action Scheduler available'
            : ($wp_cron_available ? 'WP-Cron fallback available' : 'no scheduler runner detected')
    );

    $legacy_references = $schema_ready ? pnk_storage_legacy_reference_count() : -1;
    $checks[] = pnk_doctor_item(
        'storage.legacy_references',
        $legacy_references === 0 ? 'ok' : ($legacy_references > 0 ? 'warning' : 'error'),
        $legacy_references >= 0 ? $legacy_references . ' database reference(s)' : 'not checked because schema is unavailable'
    );

    $uploads = wp_upload_dir(null, false);
    $legacy_directory = empty($uploads['error'])
        ? trailingslashit((string)$uploads['basedir']) . 'presentations-outzip'
        : '';
    $legacy_files = $legacy_directory !== '' ? pnk_doctor_directory_file_count($legacy_directory) : -1;
    $checks[] = pnk_doctor_item(
        'storage.legacy_public_files',
        $legacy_files === 0 ? 'ok' : ($legacy_files > 0 ? 'warning' : 'error'),
        $legacy_files >= 0 ? $legacy_files . ' public file(s)' : 'uploads directory unavailable'
    );

    foreach (['save.php', 'vk-auth.php'] as $legacy_entrypoint) {
        $absent = !is_file(trailingslashit(ABSPATH) . $legacy_entrypoint);
        $checks[] = pnk_doctor_item(
            'webroot.' . $legacy_entrypoint,
            $absent ? 'ok' : 'error',
            $absent ? 'absent' : 'legacy executable still exists'
        );
    }

    return $checks;
}

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('presentonika doctor', static function (): void {
        $checks = pnk_doctor_checks();
        $errors = 0;
        $warnings = 0;

        foreach ($checks as $check) {
            $status = strtoupper((string)$check['status']);
            WP_CLI::log(sprintf('[%s] %s: %s', $status, $check['id'], $check['message']));
            if ($check['status'] === 'error') $errors++;
            if ($check['status'] === 'warning') $warnings++;
        }

        WP_CLI::log(sprintf('Summary: %d error(s), %d warning(s).', $errors, $warnings));
        if ($errors > 0) WP_CLI::error('Presentonika is not ready for beta deployment.');
        WP_CLI::success('P0 checks passed. Review warnings before enabling traffic.');
    });
}
