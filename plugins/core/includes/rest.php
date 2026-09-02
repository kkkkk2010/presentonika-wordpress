<?php
if (!defined('ABSPATH')) { exit; }

function pnk_rest_get_save_params(WP_REST_Request $request): array {
    $json = $request->get_json_params();
    $body = $request->get_body_params();
    $json = is_array($json) ? $json : [];
    $body = is_array($body) ? $body : [];

    $presentationId = (int)($json['presentationId'] ?? $body['presentationId'] ?? 0);
    $saveToken = (string)($json['saveToken'] ?? $body['saveToken'] ?? '');

    if (!$presentationId) $presentationId = (int) ($request->get_header('x-presentation-id') ?: 0);
    if (!$saveToken)      $saveToken      = (string) ($request->get_header('x-save-token') ?: '');

    return [$presentationId, $saveToken];
}

function pnk_rest_get_presentation_title(WP_REST_Request $request) {
    $json = $request->get_json_params();
    $body = $request->get_body_params();
    $raw = null;

    if (is_array($json) && array_key_exists('presentationTitle', $json)) {
        $raw = $json['presentationTitle'];
    } elseif (is_array($body) && array_key_exists('presentationTitle', $body)) {
        $raw = $body['presentationTitle'];
    }

    if ($raw === null) return null;
    if (!is_scalar($raw)) {
        return new WP_Error('bad_title', 'Invalid presentationTitle');
    }

    $title = trim(sanitize_text_field((string)$raw));
    if ($title === '') {
        return new WP_Error('bad_title', 'presentationTitle must not be empty');
    }

    $length = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);
    if ($length > 200) {
        return new WP_Error('bad_title', 'presentationTitle is too long');
    }

    return $title;
}

function pnk_update_presentation_title_after_save(int $presentationId, int $userId, $title) {
    if ($title === null) return true;

    global $wpdb;
    $updated = $wpdb->update(
        pnk_table_name(),
        ['presentationname' => $title],
        ['presentationID' => $presentationId, 'userid' => $userId],
        ['%s'],
        ['%d', '%d']
    );

    if ($updated === false) {
        return new WP_Error('title_update_failed', 'Failed to update presentation title');
    }

    return true;
}

function pnk_get_authorization_bearer(): string {
    $h = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $h = (string)$_SERVER['HTTP_AUTHORIZATION'];
    } elseif (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $h = (string)($headers['Authorization'] ?? $headers['authorization'] ?? '');
    }
    if (preg_match('/^\s*Bearer\s+(.+)\s*$/i', $h, $m)) {
        return trim((string)$m[1]);
    }
    return '';
}

function pnk_check_validate_bearer(): bool {
    $expected = defined('PRESENTONIKA_SAVE_VALIDATE_BEARER')
        ? trim((string)PRESENTONIKA_SAVE_VALIDATE_BEARER)
        : '';

    // If not set — fail closed. Configure PRESENTONIKA_SAVE_VALIDATE_BEARER in wp-config.php.
    if ($expected === '') return false;

    $got = pnk_get_authorization_bearer();
    return hash_equals($expected, $got);
}

function pnk_validate_save_token(int $presentationId, string $saveToken): array {
    if ($presentationId <= 0 || !$saveToken) {
        return [new WP_Error('bad_params', 'Missing presentationId/saveToken'), null];
    }

    $saveKey = pnk_save_token_key($saveToken);
    $meta = get_transient($saveKey);

    if (!is_array($meta) || empty($meta['uid']) || empty($meta['pid']) || empty($meta['exp'])) {
        return [new WP_Error('bad_token', 'Invalid or expired saveToken'), null];
    }

    if ((int)$meta['pid'] !== (int)$presentationId) {
        return [new WP_Error('token_pid_mismatch', 'saveToken does not match presentationId'), null];
    }

    if (time() > (int)$meta['exp']) {
        delete_transient($saveKey);
        return [new WP_Error('expired', 'Expired saveToken'), null];
    }

    global $wpdb;
    $table = pnk_table_name();
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT userid, status, charge_state, job_version FROM {$table} WHERE presentationID=%d",
        $presentationId
    ));
    if (!$row || (int)$row->userid !== (int)$meta['uid']) {
        return [new WP_Error('token_owner_mismatch', 'saveToken owner mismatch'), null];
    }
    if (!in_array((string)$row->status, ['processing', 'done'], true) || (string)$row->charge_state !== 'charged') {
        return [new WP_Error('token_state_mismatch', 'Presentation is not writable'), null];
    }
    if (isset($meta['job_version']) && (int)$meta['job_version'] !== (int)$row->job_version) {
        return [new WP_Error('token_version_mismatch', 'saveToken is no longer valid'), null];
    }

    return [null, ['saveKey' => $saveKey, 'meta' => $meta, 'user_id' => (int)$meta['uid'], 'row' => $row]];
}

function pnk_rest_validate_save_token(WP_REST_Request $request) {
    try {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            return new WP_REST_Response(['ok' => true, 'v' => PR_SAVE_HANDLER_VERSION], 200);
        }

        if (!pnk_check_validate_bearer()) {
            pnk_log('rest.validate_save_token.denied', [], 'warning');
            return new WP_REST_Response(['ok' => false], 401);
        }

        $ip = pnk_get_client_ip();
        if ($rl = pnk_rest_rate_limit_or_429('validate-save-token:' . $ip, 120, 60)) return $rl;

        [$presentationId, $saveToken] = pnk_rest_get_save_params($request);

        if ($presentationId <= 0 || $saveToken === '') {
            return new WP_REST_Response(['ok' => false], 200);
        }

        [$error, $validated] = pnk_validate_save_token($presentationId, $saveToken);
        if ($error) return new WP_REST_Response(['ok' => false], 200);
        $meta = $validated['meta'];
        $exp = (int)$meta['exp'];

        return new WP_REST_Response([
            'ok'             => true,
            'presentationId' => (string)(int)$meta['pid'],
            'userId'         => (string)(int)$meta['uid'],
            'expiresAt'      => gmdate('c', $exp),
        ], 200);

    } catch (Throwable $e) {
        pnk_log_exception('rest.validate_save_token.exception', $e);
        return new WP_REST_Response(['ok' => false], 500);
    }
}

function pnk_rest_editor_observability(WP_REST_Request $request) {
    if (!pnk_check_validate_bearer()) {
        return new WP_REST_Response(['ok' => false], 401);
    }
    $ip = pnk_get_client_ip();
    if ($rl = pnk_rest_rate_limit_or_429('editor-observability:' . $ip, 120, 60)) return $rl;
    $body = $request->get_json_params();
    if (!is_array($body)) return new WP_REST_Response(['ok' => false], 400);

    $event = sanitize_key((string)($body['event'] ?? ''));
    $level = strtolower((string)($body['level'] ?? 'error'));
    if ($event === '' || !in_array($level, ['info', 'warning', 'error', 'critical'], true)) {
        return new WP_REST_Response(['ok' => false], 400);
    }
    $request_id = sanitize_text_field((string)($body['requestId'] ?? ''));
    if ($request_id !== '' && !preg_match('/^[a-zA-Z0-9._:-]{8,96}$/', $request_id)) {
        return new WP_REST_Response(['ok' => false], 400);
    }
    $context = [
        'pid' => max(0, (int)($body['presentationId'] ?? 0)),
        'error_code' => sanitize_key((string)($body['errorCode'] ?? '')),
        'stage' => sanitize_key((string)($body['stage'] ?? '')),
    ];
    pnk_log('editor.' . $event, $context, $level);
    $forward = pnk_log_forward_status();
    return new WP_REST_Response([
        'ok' => true,
        'centralized' => !empty($forward['delivered']),
        'centralStatus' => (int)($forward['code'] ?? 0),
    ], 202);
}

/**
 * Multipart fallback (kept)
 */
function pnk_rest_save_outzip(WP_REST_Request $request) {
    try {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            return new WP_REST_Response(['ok' => true, 'v' => PR_SAVE_HANDLER_VERSION], 200);
        }

        [$presentationId, $saveToken] = pnk_rest_get_save_params($request);

        $ip = pnk_get_client_ip();
        if ($rl = pnk_rest_rate_limit_or_429('save-outzip:' . $ip . ':' . (int)$presentationId, 30, 60)) return $rl;

        $files = $request->get_file_params();
        pnk_log('rest.save_outzip.enter', ['mode' => 'multipart', 'pid' => $presentationId]);

        if ($presentationId <= 0 || !$saveToken) {
            return new WP_REST_Response(['ok'=>false,'message'=>'Missing presentationId/saveToken','v'=>PR_SAVE_HANDLER_VERSION], 400);
        }

        [$err, $tok] = pnk_validate_save_token($presentationId, $saveToken);
        if ($err) return new WP_REST_Response(['ok'=>false,'message'=>$err->get_error_message()], 403);
        $user_id = (int)$tok['user_id'];
        $presentationTitle = pnk_rest_get_presentation_title($request);
        if (is_wp_error($presentationTitle)) return new WP_REST_Response(['ok'=>false,'message'=>$presentationTitle->get_error_message()], 400);

        $candidate_keys = ['file', 'outzip', 'outZip', 'zip', 'out_zip'];
        $f = null;

        foreach ($candidate_keys as $k) {
            if (!empty($files[$k]) && !empty($files[$k]['tmp_name'])) { $f = $files[$k]; break; }
        }
        if (!$f) {
            foreach ($candidate_keys as $k) {
                if (!empty($_FILES[$k]) && !empty($_FILES[$k]['tmp_name'])) { $f = $_FILES[$k]; break; }
            }
        }
        if (!$f) return new WP_REST_Response(['ok'=>false, 'message'=>'Missing file'], 400);

        if (!empty($f['error'])) return new WP_REST_Response(['ok'=>false, 'message'=>'Upload error: ' . (int)$f['error']], 400);

        $tmp = (string)($f['tmp_name'] ?? '');
        if (!$tmp || !is_readable($tmp)) return new WP_REST_Response(['ok'=>false, 'message'=>'Cannot read tmp file'], 500);

        $downloadUrl = pnk_persist_outzip_file($presentationId, $user_id, $tmp, 'multipart');
        if (is_wp_error($downloadUrl)) return new WP_REST_Response(['ok'=>false,'message'=>'Не удалось сохранить презентацию'], 500);

        $titleUpdated = pnk_update_presentation_title_after_save($presentationId, $user_id, $presentationTitle);
        if (is_wp_error($titleUpdated)) return new WP_REST_Response(['ok'=>false,'message'=>'Не удалось сохранить название презентации'], 500);

        return new WP_REST_Response([
            'ok' => true,
            'v'  => PR_SAVE_HANDLER_VERSION,
            'presentationId' => (int)$presentationId,
            'url' => (string)$downloadUrl,
            'updatedAt' => current_time('mysql'),
            'presentationTitle' => $presentationTitle,
            'mode' => 'multipart',
        ], 200);

    } catch (Throwable $e) {
        pnk_log_exception('rest.save_outzip.exception', $e, ['mode' => 'multipart']);
        return new WP_REST_Response(['ok'=>false, 'message'=>'Внутренняя ошибка сохранения'], 500);
    }
}

/**
 * JSON from-url primary
 */
function pnk_rest_save_outzip_from_url(WP_REST_Request $request) {
    try {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            return new WP_REST_Response(['ok' => true, 'v' => PR_SAVE_HANDLER_VERSION], 200);
        }

        [$presentationId, $saveToken] = pnk_rest_get_save_params($request);

        $ip = pnk_get_client_ip();
        if ($rl = pnk_rest_rate_limit_or_429('save-outzip-from-url:' . $ip . ':' . (int)$presentationId, 30, 60)) return $rl;

        $body = $request->get_json_params();
        $outZipUrl = '';
        if (is_array($body) && !empty($body['outZipUrl'])) $outZipUrl = (string)$body['outZipUrl'];
        pnk_log('rest.save_outzip.enter', ['mode' => 'from-url', 'pid' => $presentationId, 'url' => $outZipUrl]);

        if ($presentationId <= 0 || !$saveToken) return new WP_REST_Response(['ok'=>false,'message'=>'Missing presentationId/saveToken','v'=>PR_SAVE_HANDLER_VERSION], 400);
        if (!$outZipUrl) return new WP_REST_Response(['ok'=>false,'message'=>'Missing outZipUrl','v'=>PR_SAVE_HANDLER_VERSION], 400);

        [$err, $tok] = pnk_validate_save_token($presentationId, $saveToken);
        if ($err) return new WP_REST_Response(['ok'=>false,'message'=>$err->get_error_message()], 403);
        $user_id = (int)$tok['user_id'];
        $presentationTitle = pnk_rest_get_presentation_title($request);
        if (is_wp_error($presentationTitle)) return new WP_REST_Response(['ok'=>false,'message'=>$presentationTitle->get_error_message()], 400);

        $downloadUrl = pnk_save_outzip_to_uploads($outZipUrl, $presentationId, $user_id);
        if (is_wp_error($downloadUrl)) {
            pnk_log('rest.save_outzip.download_failed', [
                'pid' => $presentationId,
                'error_code' => $downloadUrl->get_error_code(),
            ], 'error');
            return new WP_REST_Response(['ok'=>false,'message'=>'Не удалось получить или сохранить архив редактора'], 502);
        }

        $titleUpdated = pnk_update_presentation_title_after_save($presentationId, $user_id, $presentationTitle);
        if (is_wp_error($titleUpdated)) return new WP_REST_Response(['ok'=>false,'message'=>'Не удалось сохранить название презентации'], 500);

        return new WP_REST_Response([
            'ok' => true,
            'v'  => PR_SAVE_HANDLER_VERSION,
            'presentationId' => (int)$presentationId,
            'url' => (string)$downloadUrl,
            'updatedAt' => current_time('mysql'),
            'presentationTitle' => $presentationTitle,
            'mode' => 'from-url',
        ], 200);

    } catch (Throwable $e) {
        pnk_log_exception('rest.save_outzip.exception', $e, ['mode' => 'from-url']);
        return new WP_REST_Response(['ok'=>false, 'message'=>'Внутренняя ошибка сохранения'], 500);
    }
}

add_action('rest_api_init', function() {
    $namespaces = ['presentonika/v1','ka/v1','_ka/v1'];

    foreach ($namespaces as $ns) {
        register_rest_route($ns, '/save-outzip', [
            'methods'  => ['POST', 'OPTIONS'],
            'permission_callback' => '__return_true',
            'callback' => 'pnk_rest_save_outzip',
        ]);

        register_rest_route($ns, '/save-outzip-from-url', [
            'methods'  => ['POST', 'OPTIONS'],
            'permission_callback' => '__return_true',
            'callback' => 'pnk_rest_save_outzip_from_url',
        ]);

        register_rest_route($ns, '/ping', [
            'methods'  => ['GET', 'OPTIONS'],
            'permission_callback' => '__return_true',
            'callback' => function() use ($ns) {
                return new WP_REST_Response([
                    'ok' => true,
                    'ns' => $ns,
                    'v'  => PR_SAVE_HANDLER_VERSION,
                    't'  => time(),
                ], 200);
            },
        ]);
    }

    register_rest_route('presentonika/v1', '/validate-save-token', [
        'methods'  => ['POST', 'OPTIONS'],
        'permission_callback' => '__return_true',
        'callback' => 'pnk_rest_validate_save_token',
    ]);
    register_rest_route('presentonika/v1', '/editor-observability', [
        'methods'  => ['POST', 'OPTIONS'],
        'permission_callback' => '__return_true',
        'callback' => 'pnk_rest_editor_observability',
    ]);
});

/**
 * CORS for editor
 */
add_action('rest_api_init', function () {
    add_filter('rest_pre_serve_request', function ($served, $result, $request, $server) {

        $origin = isset($_SERVER['HTTP_ORIGIN']) ? (string)$_SERVER['HTTP_ORIGIN'] : '';
        $allowed = [ rtrim(pnk_editor_base(), '/') ];

        if ($origin && in_array(rtrim($origin, '/'), $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
            header('Access-Control-Allow-Methods: POST, OPTIONS, GET');
            header('Access-Control-Allow-Headers: Content-Type,Accept,Authorization,X-Save-Token,X-Presentation-Id,X-Request-Id');
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Max-Age: 86400');
        }

        $status = 200;
        if (is_wp_error($result)) {
            $status = 500;
        } elseif ($result instanceof WP_REST_Response) {
            $status = (int) $result->get_status();
        } elseif (is_array($result) && isset($result['status'])) {
            $status = (int) $result['status'];
        }

        if ($status >= 400) {
            pnk_log('rest.error', [
                'status' => $status,
                'method' => $request->get_method(),
                'route' => $request->get_route(),
                'content_type' => (string)($_SERVER['CONTENT_TYPE'] ?? ''),
                'content_length' => (int)($_SERVER['CONTENT_LENGTH'] ?? 0),
            ], $status >= 500 ? 'error' : 'warning');
        }

        return $served;
    }, 10, 4);
});
