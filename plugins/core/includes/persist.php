<?php
if (!defined('ABSPATH')) { exit; }

function pnk_add_cache_bust(string $url): string {
    return add_query_arg('v', (string)time(), $url);
}

function pnk_check_zip_signature(string $filepath): bool {
    if (!is_readable($filepath)) return false;
    $handle = fopen($filepath, 'rb');
    if (!$handle) return false;
    $signature = fread($handle, 4);
    $size = (int)@filesize($filepath);
    if ($signature !== "PK\x03\x04" || $size < 22) {
        fclose($handle);
        return false;
    }

    $tail_size = min($size, 65557);
    if (fseek($handle, -$tail_size, SEEK_END) !== 0) {
        fclose($handle);
        return false;
    }
    $tail = fread($handle, $tail_size);
    fclose($handle);
    if (!is_string($tail)) return false;
    $eocd_offset = strrpos($tail, "PK\x05\x06");
    if ($eocd_offset === false || strlen($tail) < $eocd_offset + 22) return false;
    $comment_length = unpack('vlength', substr($tail, $eocd_offset + 20, 2));
    if (!is_array($comment_length) || $eocd_offset + 22 + (int)$comment_length['length'] !== strlen($tail)) return false;

    if (!class_exists('ZipArchive')) return true;

    $zip = new ZipArchive();
    $opened = $zip->open($filepath, ZipArchive::CHECKCONS);
    if ($opened !== true) return false;

    $has_document = false;
    $uncompressed_bytes = 0;
    $valid = $zip->numFiles > 0 && $zip->numFiles <= 5000;
    for ($index = 0; $valid && $index < $zip->numFiles; $index++) {
        $entry = $zip->statIndex($index);
        if (!is_array($entry) || !isset($entry['name'])) {
            $valid = false;
            break;
        }

        $name = str_replace('\\', '/', (string)$entry['name']);
        $segments = explode('/', trim($name, '/'));
        if ($name === '' || str_starts_with($name, '/') || in_array('..', $segments, true) || str_contains($name, "\0")) {
            $valid = false;
            break;
        }
        if ($name === 'doc.json') $has_document = true;

        $entry_size = max(0, (int)($entry['size'] ?? 0));
        if ($entry_size > 100 * 1024 * 1024) {
            $valid = false;
            break;
        }
        $uncompressed_bytes += $entry_size;
        if ($uncompressed_bytes > 500 * 1024 * 1024) {
            $valid = false;
            break;
        }
    }
    $zip->close();
    return $valid && $has_document;
}

function pnk_make_tmp_file(string $prefix = 'presentonika_tmp_'): string {
    $tmp_directory = function_exists('get_temp_dir') ? get_temp_dir() : sys_get_temp_dir();
    $path = trailingslashit($tmp_directory) . $prefix . wp_generate_password(16, false, false) . '.zip';
    $handle = @fopen($path, 'wb');
    if (!$handle) return '';
    fclose($handle);
    return $path;
}

function pnk_persist_outzip_file(int $presentation_id, int $user_id, string $local_tmp_file, string $source = '') {
    $lock_name = 'persist_' . $presentation_id;
    if (!pnk_try_lock($lock_name, 120)) {
        return new WP_Error('save_busy', 'Presentation is already being saved');
    }

    try {
    $size = (int)@filesize($local_tmp_file);
    if ($size <= 0) return new WP_Error('empty', 'Empty file');
    if ($size > (int)PRESENTONIKA_MAX_OUTZIP_BYTES) return new WP_Error('too_large', 'Presentation archive is too large');
    if (!pnk_check_zip_signature($local_tmp_file)) return new WP_Error('bad_zip', 'Invalid ZIP signature');

    global $wpdb;
    $table = pnk_table_name();
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT presentationID, userid, status, charge_state FROM {$table} WHERE presentationID=%d",
        $presentation_id
    ));
    if (!$row || (int)$row->userid !== $user_id) {
        return new WP_Error('not_found', 'Presentation not found or not owned by token user');
    }
    if (!in_array((string)$row->status, ['processing', 'done'], true) || (string)$row->charge_state !== 'charged') {
        return new WP_Error('invalid_state', 'Presentation cannot be saved in its current state');
    }

    $storage_key = pnk_storage_store_file($user_id, $presentation_id, $local_tmp_file);
    if (is_wp_error($storage_key)) return $storage_key;

    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$table}
         SET path=%s, status='done', error_message='', updated_at=%s
         WHERE presentationID=%d AND userid=%d
           AND status IN ('processing','done') AND charge_state='charged'",
        $storage_key,
        current_time('mysql'),
        $presentation_id,
        $user_id
    ));
    if ($updated !== 1) {
        pnk_storage_delete((string)$storage_key);
        return new WP_Error('save_race', 'Presentation state changed while saving');
    }

    pnk_storage_cleanup_versions($user_id, $presentation_id, 2, (string)$storage_key);
    pnk_log('storage.persisted', [
        'source' => $source,
        'pid' => $presentation_id,
        'uid' => $user_id,
        'size_bytes' => $size,
    ]);

    return pnk_storage_signed_url($presentation_id, $user_id, (string)$storage_key);
    } finally {
        pnk_release_lock($lock_name);
    }
}

/**
 * Download out.zip from the configured editor and persist it privately.
 */
function pnk_save_outzip_to_uploads(string $editor_outzip_url, int $presentation_id, int $user_id) {
    $full_url = $editor_outzip_url;
    if (!preg_match('/^https?:\/\//i', $full_url)) {
        $full_url = pnk_editor_base() . '/' . ltrim($full_url, '/');
    }

    $editor = wp_parse_url(pnk_editor_base());
    $target = wp_parse_url($full_url);
    if (!is_array($editor) || !is_array($target)
        || strtolower((string)($target['scheme'] ?? '')) !== 'https'
        || strtolower((string)($target['host'] ?? '')) !== strtolower((string)($editor['host'] ?? ''))
        || (int)($target['port'] ?? 443) !== (int)($editor['port'] ?? 443)) {
        return new WP_Error('outzip_url_denied', 'Editor archive URL is not allowed');
    }

    $full_url = pnk_add_cache_bust($full_url);
    $tmp = pnk_make_tmp_file('presentonika_download_');
    if ($tmp === '') return new WP_Error('tmp_failed', 'Cannot allocate temporary file');

    $response = wp_safe_remote_get($full_url, [
        'timeout' => 300,
        'redirection' => 0,
        'stream' => true,
        'filename' => $tmp,
        'limit_response_size' => (int)PRESENTONIKA_MAX_OUTZIP_BYTES + 1,
        'headers' => [
            'Cache-Control' => 'no-cache',
            'Pragma' => 'no-cache',
            'Accept' => 'application/zip,application/octet-stream',
        ],
    ]);

    if (is_wp_error($response)) {
        @unlink($tmp);
        return new WP_Error('outzip_download_failed', 'Editor archive download failed');
    }

    $status = (int)wp_remote_retrieve_response_code($response);
    if ($status < 200 || $status >= 300) {
        @unlink($tmp);
        return new WP_Error('outzip_download_http', 'Editor archive returned HTTP ' . $status);
    }

    $result = pnk_persist_outzip_file($presentation_id, $user_id, $tmp, 'gamma-finalize');
    @unlink($tmp);
    return $result;
}
