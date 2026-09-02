<?php
if (!defined('ABSPATH')) { exit; }

function pnk_storage_root(): string {
    $root = defined('PRESENTONIKA_STORAGE_DIR') ? (string)PRESENTONIKA_STORAGE_DIR : '';
    if (trim($root) === '') $root = dirname(ABSPATH) . '/presentonika-private/presentations';
    return rtrim(wp_normalize_path($root), '/');
}

function pnk_storage_ensure_directory(string $directory): bool {
    if (is_dir($directory)) return true;
    if (!wp_mkdir_p($directory) || !is_dir($directory)) return false;
    @chmod($directory, 0700);
    return true;
}

function pnk_storage_harden_root(): void {
    $root = pnk_storage_root();
    if (!pnk_storage_ensure_directory($root)) return;

    $deny_file = $root . '/.htaccess';
    if (!is_file($deny_file)) {
        $deny_rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
        @file_put_contents($deny_file, $deny_rules, LOCK_EX);
        @chmod($deny_file, 0600);
    }
    $index_file = $root . '/index.html';
    if (!is_file($index_file)) {
        @file_put_contents($index_file, '', LOCK_EX);
        @chmod($index_file, 0600);
    }
}

function pnk_storage_key(int $user_id, int $presentation_id, string $filename): string {
    // WordPress treats `.out.zip` as a suspicious double extension and can
    // rewrite it to `.out_.zip`. Storage keys use a strict generated format,
    // so validate that format directly instead of mutating the filename.
    $filename = basename(str_replace('\\', '/', $filename));
    if (!preg_match('~^outzip-[a-zA-Z0-9._-]+\.out\.zip$~', $filename)) {
        return '';
    }
    return 'private:v1/' . $user_id . '/' . $presentation_id . '/' . $filename;
}

function pnk_storage_parse_key(string $storage_key): ?array {
    if (!preg_match('~^private:v1/(\d+)/(\d+)/(outzip-[a-zA-Z0-9._-]+\.out\.zip)$~', $storage_key, $matches)) {
        return null;
    }
    return [
        'user_id' => (int)$matches[1],
        'presentation_id' => (int)$matches[2],
        'filename' => $matches[3],
    ];
}

function pnk_storage_parse_any_key(string $storage_key): ?array {
    $local = pnk_storage_parse_key($storage_key);
    if ($local) {
        $local['provider'] = 'local';
        return $local;
    }
    $cloud = pnk_yandex_storage_parse_key($storage_key);
    if ($cloud) {
        $cloud['provider'] = 'yandex_object_storage';
        return $cloud;
    }
    return null;
}

function pnk_storage_file_path(string $storage_key): ?string {
    $parsed = pnk_storage_parse_key($storage_key);
    if (!$parsed) return null;

    $root = pnk_storage_root();
    $path = $root . '/' . $parsed['user_id'] . '/' . $parsed['presentation_id'] . '/' . $parsed['filename'];
    $normalized = wp_normalize_path($path);
    if (strpos($normalized, $root . '/') !== 0) return null;
    return $normalized;
}

function pnk_storage_store_file(int $user_id, int $presentation_id, string $source_file) {
    if (pnk_storage_provider() === 'yandex_object_storage') {
        return pnk_yandex_storage_store_file($user_id, $presentation_id, $source_file);
    }
    if (!is_readable($source_file)) return new WP_Error('storage_source_missing', 'Storage source is not readable');

    $directory = pnk_storage_root() . '/' . $user_id . '/' . $presentation_id;
    if (!pnk_storage_ensure_directory($directory)) {
        return new WP_Error('storage_directory_failed', 'Cannot create private storage directory');
    }
    pnk_storage_harden_root();

    $filename = 'outzip-' . gmdate('YmdHis') . '-' . wp_generate_password(8, false, false) . '.out.zip';
    $storage_key = pnk_storage_key($user_id, $presentation_id, $filename);
    $target = pnk_storage_file_path($storage_key);
    if (!$target || @copy($source_file, $target) !== true) {
        return new WP_Error('storage_write_failed', 'Cannot write private presentation archive');
    }
    @chmod($target, 0600);

    return $storage_key;
}

function pnk_storage_delete(string $storage_key): void {
    $cloud = pnk_yandex_storage_parse_key($storage_key);
    if ($cloud) {
        pnk_yandex_storage_delete_object((string)$cloud['object_name']);
        return;
    }
    $path = pnk_storage_file_path($storage_key);
    if ($path && is_file($path)) @unlink($path);
}

function pnk_storage_cleanup_versions(
    int $user_id,
    int $presentation_id,
    int $keep = 2,
    string $preserve_storage_key = ''
): void {
    $cloud = pnk_yandex_storage_parse_key($preserve_storage_key);
    if ($cloud) {
        pnk_yandex_storage_cleanup_versions($user_id, $presentation_id, $keep, $preserve_storage_key);
        return;
    }
    $directory = pnk_storage_root() . '/' . $user_id . '/' . $presentation_id;
    $files = glob($directory . '/outzip-*.out.zip');
    if (!is_array($files)) return;

    $preserve_path = null;
    if ($preserve_storage_key !== '') {
        $parsed = pnk_storage_parse_key($preserve_storage_key);
        if ($parsed
            && (int)$parsed['user_id'] === $user_id
            && (int)$parsed['presentation_id'] === $presentation_id) {
            $candidate = pnk_storage_file_path($preserve_storage_key);
            if ($candidate && is_file($candidate)) $preserve_path = wp_normalize_path($candidate);
        }
    }

    $files = array_values(array_filter($files, static function (string $file) use ($preserve_path): bool {
        return $preserve_path === null || wp_normalize_path($file) !== $preserve_path;
    }));

    usort($files, static function (string $left, string $right): int {
        $mtime_order = ((int)@filemtime($right)) <=> ((int)@filemtime($left));
        return $mtime_order !== 0 ? $mtime_order : strcmp(basename($right), basename($left));
    });
    $other_versions_to_keep = max(0, max(1, $keep) - ($preserve_path !== null ? 1 : 0));
    foreach (array_slice($files, $other_versions_to_keep) as $file) {
        if (is_file($file)) @unlink($file);
    }
}

function pnk_storage_signing_key(): string {
    if (defined('PRESENTONIKA_DOWNLOAD_SIGNING_KEY') && trim((string)PRESENTONIKA_DOWNLOAD_SIGNING_KEY) !== '') {
        return (string)PRESENTONIKA_DOWNLOAD_SIGNING_KEY;
    }
    return wp_salt('auth');
}

function pnk_storage_signature(int $presentation_id, int $user_id, int $expires, string $storage_key): string {
    $payload = implode('|', [
        $presentation_id,
        $user_id,
        $expires,
        hash('sha256', $storage_key),
        strtolower((string)wp_parse_url(home_url('/'), PHP_URL_HOST)),
    ]);
    return hash_hmac('sha256', $payload, pnk_storage_signing_key());
}

function pnk_storage_signed_url(int $presentation_id, int $user_id, string $storage_key, ?int $ttl = null) {
    $cloud = pnk_yandex_storage_parse_key($storage_key);
    if ($cloud) {
        if ((int)$cloud['user_id'] !== $user_id || (int)$cloud['presentation_id'] !== $presentation_id) {
            return new WP_Error('storage_key_mismatch', 'Invalid cloud storage key');
        }
        return pnk_yandex_storage_signed_url($storage_key, $ttl ?? 300);
    }
    $parsed = pnk_storage_parse_key($storage_key);
    if (!$parsed || $parsed['user_id'] !== $user_id || $parsed['presentation_id'] !== $presentation_id) {
        return new WP_Error('storage_key_mismatch', 'Invalid private storage key');
    }

    $ttl = $ttl ?? (defined('PRESENTONIKA_DOWNLOAD_URL_TTL') ? (int)PRESENTONIKA_DOWNLOAD_URL_TTL : 300);
    $ttl = min(900, max(60, $ttl));
    $expires = time() + $ttl;
    $signature = pnk_storage_signature($presentation_id, $user_id, $expires, $storage_key);

    return add_query_arg([
        'expires' => $expires,
        'signature' => $signature,
    ], rest_url('presentonika/v1/presentations/' . $presentation_id . '/outzip'));
}

function pnk_storage_legacy_file_from_url(string $url, int $user_id, int $presentation_id): ?string {
    $uploads = wp_upload_dir(null, false);
    if (!empty($uploads['error'])) return null;

    $url_path = (string)wp_parse_url($url, PHP_URL_PATH);
    $base_path = rtrim((string)wp_parse_url((string)$uploads['baseurl'], PHP_URL_PATH), '/');
    $prefix = $base_path . '/presentations-outzip/' . $user_id . '/' . $presentation_id . '/';
    if ($url_path === '' || strpos($url_path, $prefix) !== 0) return null;

    $relative = ltrim(substr($url_path, strlen($base_path)), '/');
    $candidate = wp_normalize_path(trailingslashit((string)$uploads['basedir']) . $relative);
    $legacy_root = wp_normalize_path(trailingslashit((string)$uploads['basedir']) . 'presentations-outzip');
    $real = realpath($candidate);
    if (!$real) return null;
    $real = wp_normalize_path($real);
    if (strpos($real, $legacy_root . '/') !== 0 || !is_file($real)) return null;
    return $real;
}

function pnk_storage_migrate_legacy_url(string $legacy_url, int $presentation_id, int $user_id) {
    $legacy_file = pnk_storage_legacy_file_from_url($legacy_url, $user_id, $presentation_id);
    if (!$legacy_file) return new WP_Error('legacy_storage_unavailable', 'Legacy archive is not available locally');
    if (function_exists('pnk_check_zip_signature') && !pnk_check_zip_signature($legacy_file)) {
        return new WP_Error('legacy_storage_invalid', 'Legacy archive is invalid');
    }

    $storage_key = pnk_storage_store_file($user_id, $presentation_id, $legacy_file);
    if (is_wp_error($storage_key)) return $storage_key;

    global $wpdb;
    $table = pnk_table_name();
    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET path=%s, updated_at=%s
         WHERE presentationID=%d AND userid=%d AND path=%s AND status='done'",
        $storage_key,
        current_time('mysql'),
        $presentation_id,
        $user_id,
        $legacy_url
    ));
    if ($updated !== 1) {
        pnk_storage_delete($storage_key);
        return new WP_Error('legacy_storage_race', 'Presentation storage changed during migration');
    }

    @unlink($legacy_file);
    pnk_storage_cleanup_versions($user_id, $presentation_id, 2, (string)$storage_key);
    pnk_log('storage.legacy_migrated', ['pid' => $presentation_id, 'uid' => $user_id]);
    return $storage_key;
}

function pnk_storage_migrate_legacy_batch(int $after_id = 0, int $limit = 50): array {
    global $wpdb;

    $limit = min(500, max(1, $limit));
    $table = pnk_table_name();
    $like = '%/' . $wpdb->esc_like('presentations-outzip') . '/%';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT presentationID, userid, path
         FROM {$table}
         WHERE presentationID>%d AND status='done' AND path LIKE %s
         ORDER BY presentationID ASC LIMIT %d",
        max(0, $after_id),
        $like,
        $limit
    ));

    $result = [
        'scanned' => 0,
        'migrated' => 0,
        'failed' => 0,
        'last_id' => max(0, $after_id),
        'errors' => [],
    ];
    foreach ((array)$rows as $row) {
        $presentation_id = (int)$row->presentationID;
        $result['scanned']++;
        $result['last_id'] = $presentation_id;
        $migrated = pnk_storage_migrate_legacy_url(
            (string)$row->path,
            $presentation_id,
            (int)$row->userid
        );
        if (is_wp_error($migrated)) {
            $result['failed']++;
            $result['errors'][] = [
                'presentation_id' => $presentation_id,
                'code' => $migrated->get_error_code(),
            ];
            pnk_log('storage.legacy_migration_failed', [
                'pid' => $presentation_id,
                'error_code' => $migrated->get_error_code(),
            ], 'error');
            continue;
        }
        $result['migrated']++;
    }
    return $result;
}

function pnk_storage_legacy_reference_count(): int {
    global $wpdb;

    $table = pnk_table_name();
    $like = '%/' . $wpdb->esc_like('presentations-outzip') . '/%';
    return (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE path LIKE %s",
        $like
    ));
}

function pnk_storage_quarantine_legacy_files(): array {
    $result = ['moved' => 0, 'failed' => 0, 'bytes' => 0, 'directory' => ''];
    if (pnk_storage_legacy_reference_count() > 0) {
        $result['failed'] = 1;
        return $result;
    }

    $uploads = wp_upload_dir(null, false);
    if (!empty($uploads['error'])) {
        $result['failed'] = 1;
        return $result;
    }

    $legacy_root = realpath(trailingslashit((string)$uploads['basedir']) . 'presentations-outzip');
    if (!$legacy_root || !is_dir($legacy_root)) return $result;
    $legacy_root = rtrim(wp_normalize_path($legacy_root), '/');

    $private_root = defined('PRESENTONIKA_PRIVATE_DIR')
        ? rtrim(wp_normalize_path((string)PRESENTONIKA_PRIVATE_DIR), '/')
        : dirname(pnk_storage_root());
    $quarantine = $private_root . '/legacy-quarantine/' . gmdate('Ymd-His') . '-' . wp_generate_password(6, false, false);
    if (!pnk_storage_ensure_directory($quarantine)) {
        $result['failed'] = 1;
        return $result;
    }
    $result['directory'] = $quarantine;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($legacy_root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $file_info) {
        if (!$file_info->isFile()) continue;
        $source = wp_normalize_path($file_info->getPathname());
        if (strpos($source, $legacy_root . '/') !== 0) {
            $result['failed']++;
            continue;
        }

        $relative = ltrim(substr($source, strlen($legacy_root)), '/');
        if ($relative === '' || in_array('..', explode('/', $relative), true)) {
            $result['failed']++;
            continue;
        }
        $target = $quarantine . '/' . $relative;
        if (!pnk_storage_ensure_directory(dirname($target))) {
            $result['failed']++;
            continue;
        }

        $size = max(0, (int)$file_info->getSize());
        $moved = @rename($source, $target);
        if (!$moved && @copy($source, $target)) {
            @chmod($target, 0600);
            $moved = @unlink($source);
        }
        if (!$moved) {
            if (is_file($target)) @unlink($target);
            $result['failed']++;
            continue;
        }
        @chmod($target, 0600);
        $result['moved']++;
        $result['bytes'] += $size;
    }
    return $result;
}

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('presentonika storage-migrate', static function (array $args, array $assoc_args): void {
        $limit = isset($assoc_args['batch-size']) ? (int)$assoc_args['batch-size'] : 100;
        $limit = min(500, max(1, $limit));
        $cursor = 0;
        $totals = ['scanned' => 0, 'migrated' => 0, 'failed' => 0];

        do {
            $batch = pnk_storage_migrate_legacy_batch($cursor, $limit);
            $cursor = (int)$batch['last_id'];
            foreach (array_keys($totals) as $key) {
                $totals[$key] += (int)$batch[$key];
            }
            foreach ($batch['errors'] as $error) {
                WP_CLI::warning(sprintf(
                    'Presentation #%d was not migrated (%s).',
                    (int)$error['presentation_id'],
                    (string)$error['code']
                ));
            }
        } while ((int)$batch['scanned'] === $limit);

        WP_CLI::log(sprintf(
            'Scanned: %d; migrated: %d; failed: %d.',
            $totals['scanned'],
            $totals['migrated'],
            $totals['failed']
        ));
        if ($totals['failed'] > 0) {
            WP_CLI::error('Migration finished with errors. Public legacy files must remain blocked until they are resolved.');
        }
        $remaining_references = pnk_storage_legacy_reference_count();
        if ($remaining_references > 0) {
            WP_CLI::error(sprintf(
                '%d database row(s) still reference public presentation archives.',
                $remaining_references
            ));
        }

        $quarantine = pnk_storage_quarantine_legacy_files();
        WP_CLI::log(sprintf(
            'Quarantined files: %d; bytes: %d; failed: %d.',
            (int)$quarantine['moved'],
            (int)$quarantine['bytes'],
            (int)$quarantine['failed']
        ));
        if ((int)$quarantine['failed'] > 0) {
            WP_CLI::error('Legacy files could not be fully quarantined. Keep the public path blocked.');
        }
        WP_CLI::success('Legacy presentation archives are private and unreferenced public files are quarantined.');
    });
}

function pnk_storage_resolve_read_url(string $path, int $presentation_id, int $user_id) {
    if (pnk_storage_parse_any_key($path)) {
        return pnk_storage_signed_url($presentation_id, $user_id, $path);
    }

    $legacy_file = pnk_storage_legacy_file_from_url($path, $user_id, $presentation_id);
    if ($legacy_file) {
        $migrated = pnk_storage_migrate_legacy_url($path, $presentation_id, $user_id);
        if (is_wp_error($migrated)) return $migrated;
        return pnk_storage_signed_url($presentation_id, $user_id, (string)$migrated);
    }

    // Compatibility for archives already stored by an external provider.
    if (wp_http_validate_url($path)) {
        pnk_log('storage.external_legacy_url', ['pid' => $presentation_id, 'url' => $path], 'warning');
        return $path;
    }
    return new WP_Error('storage_path_invalid', 'Presentation archive path is invalid');
}

function pnk_storage_rest_download(WP_REST_Request $request) {
    $presentation_id = (int)$request->get_param('id');
    $expires = (int)$request->get_param('expires');
    $signature = strtolower((string)$request->get_param('signature'));
    $now = time();
    if ($presentation_id <= 0 || $expires < ($now - 30) || $expires > ($now + 900) || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
        return new WP_Error('download_denied', 'Download link is invalid or expired', ['status' => 403]);
    }

    global $wpdb;
    $table = pnk_table_name();
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT presentationID, userid, status, path, charge_state FROM {$table} WHERE presentationID=%d",
        $presentation_id
    ));
    if (!$row || (string)$row->status !== 'done' || (string)$row->charge_state !== 'charged') {
        return new WP_Error('download_denied', 'Download link is invalid or expired', ['status' => 403]);
    }

    $expected = pnk_storage_signature($presentation_id, (int)$row->userid, $expires, (string)$row->path);
    if (!hash_equals($expected, $signature)) {
        return new WP_Error('download_denied', 'Download link is invalid or expired', ['status' => 403]);
    }

    $file = pnk_storage_file_path((string)$row->path);
    if (!$file || !is_readable($file)) {
        pnk_log('storage.download_missing', ['pid' => $presentation_id], 'error');
        return new WP_Error('download_missing', 'Presentation archive is unavailable', ['status' => 404]);
    }

    $size = (int)filesize($file);
    while (ob_get_level() > 0) ob_end_clean();
    status_header(200);
    header('Content-Type: application/zip');
    header('Content-Length: ' . $size);
    header('Content-Disposition: attachment; filename="presentation-' . $presentation_id . '.out.zip"');
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    readfile($file);
    exit;
}

add_action('rest_api_init', static function (): void {
    register_rest_route('presentonika/v1', '/presentations/(?P<id>\d+)/outzip', [
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'pnk_storage_rest_download',
        'permission_callback' => '__return_true',
        'args' => [
            'id' => ['required' => true, 'validate_callback' => static fn($value): bool => (int)$value > 0],
            'expires' => ['required' => true],
            'signature' => ['required' => true],
        ],
    ]);
});
