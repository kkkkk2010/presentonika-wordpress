<?php
if (!defined('ABSPATH')) { exit; }

function pnk_create_charged_presentation(
    int $user_id,
    string $title,
    string $input_text,
    string $theme,
    int $cost,
    string $request_id = ''
) {
    global $wpdb;

    $lock_name = 'user_generation_' . $user_id;
    if (!pnk_try_lock($lock_name, 45)) {
        return new WP_Error('generation_busy', 'Запрос уже обрабатывается. Подождите несколько секунд.', ['status' => 409]);
    }

    try {
        $table = pnk_table_name();
        $request_id = sanitize_text_field($request_id);
        if (preg_match('/^[a-f0-9-]{36}$/i', $request_id)) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT presentationID FROM {$table} WHERE userid=%d AND idempotency_key=%s",
                $user_id,
                $request_id
            ));
            if ($existing) return (int)$existing;
        } else {
            $request_id = wp_generate_uuid4();
        }

        $last_time = (int)get_user_meta($user_id, '_last_presentation_time', true);
        if ($last_time && (time() - $last_time) < 60) {
            return new WP_Error('generation_rate_limit', 'Подождите минуту перед новой генерацией', ['status' => 429]);
        }

        $in_progress = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE userid=%d AND status IN ('pending','processing','failing')",
            $user_id
        ));
        if ($in_progress > 0) {
            return new WP_Error('generation_exists', 'У вас уже есть генерация в процессе. Дождитесь завершения.', ['status' => 409]);
        }

        if (pnk_get_user_points_balance($user_id) < $cost) {
            return new WP_Error('insufficient_points', 'Недостаточно баллов для генерации', ['status' => 402]);
        }

        $now = current_time('mysql');
        $inserted = $wpdb->insert($table, [
            'userid' => $user_id,
            'presentationname' => $title,
            'input_text' => $input_text,
            'theme' => $theme,
            'status' => 'pending',
            'generation_id' => '',
            'attempts' => 0,
            'path' => '',
            'error_message' => '',
            'idempotency_key' => $request_id,
            'charge_state' => 'uncharged',
            'charged_amount' => $cost,
            'job_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%d','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s','%d','%d','%s','%s']);
        if (!$inserted) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT presentationID FROM {$table} WHERE userid=%d AND idempotency_key=%s",
                $user_id,
                $request_id
            ));
            if ($existing) return (int)$existing;
            return new WP_Error('generation_db_error', 'Ошибка базы данных при создании задачи', ['status' => 500]);
        }

        $presentation_id = (int)$wpdb->insert_id;
        if (!pnk_deduct_points_for_presentation($user_id, $presentation_id, $cost)) {
            $wpdb->update($table, [
                'status' => 'failed',
                'error_message' => 'Не удалось списать баллы',
                'updated_at' => current_time('mysql'),
            ], ['presentationID' => $presentation_id], ['%s','%s','%s'], ['%d']);
            return new WP_Error('points_charge_failed', 'Не удалось списать баллы', ['status' => 500]);
        }

        update_user_meta($user_id, '_last_presentation_time', time());
        return $presentation_id;
    } finally {
        pnk_release_lock($lock_name);
    }
}

function pnk_ajax_send_generation_error(WP_Error $error): void {
    $data = $error->get_error_data();
    $status = is_array($data) && isset($data['status']) ? (int)$data['status'] : 500;
    wp_send_json_error(['message' => $error->get_error_message()], $status);
}

/**
 * AJAX: generate_presentation (Gamma)
 * Action name stays the same for existing frontend JS: generate_presentation
 */
add_action('wp_ajax_generate_presentation', 'pnk_ajax_generate_presentation', 0);
function pnk_ajax_generate_presentation(): void {
    if (!check_ajax_referer('presentation_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Сессия устарела. Обновите страницу и попробуйте снова.'], 403);
    }

    if (!is_user_logged_in()) {
        wp_send_json_error([
            'message'  => 'Пожалуйста, войдите или зарегистрируйтесь',
            'redirect' => home_url('/login')
        ], 401);
    }

    $user_id = get_current_user_id();
    $cost    = 10;

    $text  = sanitize_textarea_field($_POST['presentation_text'] ?? '');
    $theme = sanitize_text_field($_POST['theme'] ?? 'default');

    $allowed_themes = ['default','dark','light'];
    if (!in_array($theme, $allowed_themes, true)) $theme = 'default';

    $text_valid = pnk_validate_topic_text($text);
    if (is_wp_error($text_valid)) wp_send_json_error(['message' => $text_valid->get_error_message()], 400);

    $title = pnk_text_substr($text, 0, 60);
    $presentation_id = pnk_create_charged_presentation(
        (int)$user_id,
        $title,
        $text,
        $theme,
        $cost,
        (string)($_POST['request_id'] ?? '')
    );
    if (is_wp_error($presentation_id)) pnk_ajax_send_generation_error($presentation_id);

    if (!pnk_schedule_single('async_presentation_start', [(int)$presentation_id], time() + 1, 'user enqueue')) {
        pnk_fail_presentation((int)$presentation_id, ['pending'], 'Не удалось поставить генерацию в очередь');
        wp_send_json_error(['message' => 'Не удалось поставить генерацию в очередь. Баллы возвращены.'], 503);
    }

    wp_send_json_success([
        'message'         => 'Презентация поставлена в очередь. Статус обновится автоматически.',
        'presentation_id' => $presentation_id,
    ]);
}

/**
 * AJAX: generate_deck_plan (Orchestrator /plans)
 */
add_action('wp_ajax_generate_deck_plan', 'pnk_ajax_generate_deck_plan', 0);
function pnk_ajax_generate_deck_plan(): void {
    if (!check_ajax_referer('presentation_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Сессия устарела. Обновите страницу и попробуйте снова.'], 403);
    }

    if (!is_user_logged_in()) {
        wp_send_json_error([
            'message'  => 'Пожалуйста, войдите или зарегистрируйтесь',
            'redirect' => home_url('/login')
        ], 401);
    }

    if (!pnk_orchestrator_is_enabled()) {
        wp_send_json_error(['message' => 'Orchestrator не настроен (ключ отсутствует).'], 500);
    }

    $user_id = get_current_user_id();
    $last_time = (int) get_user_meta($user_id, '_last_deck_plan_time', true);
    if ($last_time && (time() - $last_time) < 60) {
        wp_send_json_error(['message' => 'Подождите минуту перед повторной сборкой плана'], 429);
    }

    $text  = sanitize_textarea_field($_POST['presentation_text'] ?? '');
    $theme = sanitize_text_field($_POST['theme'] ?? 'teacher-dark');
    $theme = pnk_orchestrator_normalize_theme($theme);
    $subject = sanitize_text_field($_POST['subject'] ?? '');
    $grade = sanitize_text_field($_POST['grade'] ?? '');
    $slide_count = (int)($_POST['slide_count'] ?? 10);
    if ($slide_count < 1 || $slide_count > 20) $slide_count = 10;
    $presentation_type = pnk_orchestrator_normalize_presentation_type(sanitize_text_field($_POST['presentation_type'] ?? 'auto'));

    $text_valid = pnk_validate_topic_text($text);
    if (is_wp_error($text_valid)) wp_send_json_error(['message' => $text_valid->get_error_message()], 400);

    $payload = [
        'topic' => $text,
        'subject' => $subject,
        'grade' => $grade,
        'language' => 'ru',
        'slideCount' => $slide_count,
        'presentationType' => $presentation_type,
        'themeId' => $theme,
        'constraints' => [
            'depth' => 'school',
            'tone' => 'clear',
            'includeQuiz' => true,
            'includeHomework' => true,
        ],
    ];

    // Reserve the per-user cooldown before the external AI call.
    // This closes the race where parallel requests all passed the old post-call check.
    update_user_meta($user_id, '_last_deck_plan_time', time());

    $json = pnk_orchestrator_post_json('/plans', $payload, 90);
    if (is_wp_error($json)) {
        wp_send_json_error(['message' => 'Не удалось собрать план: ' . $json->get_error_message()], 502);
    }

    if (empty($json['deckPlan']) || !is_array($json['deckPlan'])) {
        wp_send_json_error(['message' => 'Orchestrator вернул план в неожиданном формате'], 502);
    }

    wp_send_json_success([
        'message' => 'План собран',
        'deckPlan' => $json['deckPlan'],
        'planForUi' => $json['planForUi'] ?? null,
        'diagnostics' => $json['diagnostics'] ?? null,
    ]);
}

/**
 * AJAX: generate_presentation_orchestrator (DeepSeek via Orchestrator)
 */
add_action('wp_ajax_generate_presentation_orchestrator', 'pnk_ajax_generate_presentation_orchestrator', 0);
function pnk_ajax_generate_presentation_orchestrator(): void {
    if (!check_ajax_referer('presentation_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Сессия устарела. Обновите страницу и попробуйте снова.'], 403);
    }

    if (!is_user_logged_in()) {
        wp_send_json_error([
            'message'  => 'Пожалуйста, войдите или зарегистрируйтесь',
            'redirect' => home_url('/login')
        ], 401);
    }

    if (!pnk_orchestrator_is_enabled()) {
        wp_send_json_error(['message' => 'Orchestrator не настроен (ключ отсутствует).'], 500);
    }

    $user_id = get_current_user_id();
    $cost    = 10;

    $text  = sanitize_textarea_field($_POST['presentation_text'] ?? '');
    $theme = sanitize_text_field($_POST['theme'] ?? 'teacher-dark');
    $theme = pnk_orchestrator_normalize_theme($theme);
    $deck_plan = pnk_orchestrator_decode_deck_plan_payload($_POST['deck_plan'] ?? '');
    if (is_wp_error($deck_plan)) {
        wp_send_json_error(['message' => $deck_plan->get_error_message()], 400);
    }

    $text_valid = pnk_validate_topic_text($text);
    if (is_wp_error($text_valid)) wp_send_json_error(['message' => $text_valid->get_error_message()], 400);

    $title = pnk_text_substr($text, 0, 60);
    $presentation_id = pnk_create_charged_presentation(
        (int)$user_id,
        $title,
        $text,
        $theme,
        $cost,
        (string)($_POST['request_id'] ?? '')
    );
    if (is_wp_error($presentation_id)) pnk_ajax_send_generation_error($presentation_id);

    if (is_array($deck_plan)) {
        if (!pnk_orchestrator_store_deck_plan((int)$presentation_id, (int)$user_id, $deck_plan)) {
            pnk_fail_presentation((int)$presentation_id, ['pending'], 'Не удалось сохранить план презентации');
            wp_send_json_error(['message' => 'Не удалось сохранить план презентации. Баллы возвращены.'], 503);
        }
    }
    if (!pnk_schedule_single('async_orchestrator_start', [(int)$presentation_id], time() + 1, 'user enqueue orchestrator')) {
        delete_transient(pnk_orchestrator_deck_plan_key((int)$presentation_id));
        pnk_fail_presentation((int)$presentation_id, ['pending'], 'Не удалось поставить генерацию в очередь');
        wp_send_json_error(['message' => 'Не удалось поставить генерацию в очередь. Баллы возвращены.'], 503);
    }

    wp_send_json_success([
        'message'         => 'Презентация поставлена в очередь (DeepSeek).',
        'presentation_id' => $presentation_id,
    ]);
}

/**
 * AJAX: status
 */
add_action('wp_ajax_presentation_status', 'pnk_ajax_presentation_status', 0);
function pnk_ajax_presentation_status(): void {
    if (!check_ajax_referer('presentation_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Сессия устарела. Обновите страницу и попробуйте снова.'], 403);
    }

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Авторизуйтесь'], 401);
    }

    $user_id = get_current_user_id();
    $presentation_id = (int) ($_POST['presentation_id'] ?? 0);
    if ($presentation_id <= 0) wp_send_json_error(['message' => 'Некорректный ID'], 400);

    global $wpdb;
    $table = pnk_table_name();

    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT presentationID, userid, status, path, error_message FROM {$table} WHERE presentationID=%d", $presentation_id)
    );

    if (!$row || (int)$row->userid !== (int)$user_id) wp_send_json_error(['message' => 'Не найдено'], 404);

    $data = ['status' => (string)$row->status];

    if ((string)$row->status === 'failed') {
        $data['message'] = $row->error_message ? (string)$row->error_message : 'Ошибка генерации';
    }

    wp_send_json_success($data);
}

/**
 * AJAX: bridge -> redirect to editor
 */
add_action('wp_ajax_presentation_bridge', 'pnk_ajax_presentation_bridge', 0);
function pnk_ajax_presentation_bridge(): void {
    if (!check_ajax_referer('presentation_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Сессия устарела. Обновите страницу и попробуйте снова.'], 403);
    }

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Авторизуйтесь'], 401);
    }

    $user_id = get_current_user_id();
    $presentation_id = (int) ($_POST['presentation_id'] ?? 0);
    if ($presentation_id <= 0) wp_send_json_error(['message' => 'Некорректный ID'], 400);

    global $wpdb;
    $table = pnk_table_name();

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT presentationID, userid, status, path, charge_state, job_version, presentationname
             FROM {$table} WHERE presentationID=%d",
            $presentation_id
        )
    );

    if (!$row || (int)$row->userid !== (int)$user_id) wp_send_json_error(['message' => 'Не найдено'], 404);
    if ((string)$row->status !== 'done' || empty($row->path)) wp_send_json_error(['message' => 'out.zip ещё не готов'], 409);
    if ((string)$row->charge_state !== 'charged') wp_send_json_error(['message' => 'Презентация недоступна'], 409);

    $outZipUrl = pnk_storage_resolve_read_url((string)$row->path, $presentation_id, (int)$user_id);
    if (is_wp_error($outZipUrl)) {
        pnk_log('storage.resolve_failed', [
            'pid' => $presentation_id,
            'error_code' => $outZipUrl->get_error_code(),
        ], 'error');
        wp_send_json_error(['message' => 'Не удалось подготовить презентацию к открытию'], 500);
    }

    $saveToken = pnk_generate_save_token();
    $saveKey   = pnk_save_token_key($saveToken);

        $ttl_seconds = 12 * HOUR_IN_SECONDS;
    $save_context_stored = set_transient($saveKey, [
        'uid' => (int)$user_id,
        'pid' => (int)$presentation_id,
        'job_version' => (int)$row->job_version,
        'exp' => time() + $ttl_seconds,
    ], $ttl_seconds);
    if (!$save_context_stored) {
        pnk_log('bridge.save_context_store_failed', ['pid' => $presentation_id], 'error');
        wp_send_json_error(['message' => 'Не удалось подготовить безопасное сохранение в редакторе'], 503);
    }

    $presentationTitle = trim(sanitize_text_field((string)$row->presentationname));
    $presentationTitle = function_exists('mb_substr')
        ? mb_substr($presentationTitle, 0, 200)
        : substr($presentationTitle, 0, 200);

    $saveEndpoint = pnk_get_save_endpoint_url();
    $bridge = pnk_request_bridge_job_from_outzip_url((string)$outZipUrl, [
        'presentationId'    => (string)$presentation_id,
        'presentationTitle' => $presentationTitle,
        'saveToken'         => $saveToken,
        'saveEndpoint'   => $saveEndpoint,
    ]);
    if (is_wp_error($bridge)) {
        delete_transient($saveKey);
        wp_send_json_error(['message' => 'Bridge import failed: ' . $bridge->get_error_message()], 502);
    }

    $launch_url = isset($bridge['launchUrl']) ? (string)$bridge['launchUrl'] : '';
    if (!preg_match('~^/\?launch=[A-Za-z0-9_-]{32,128}$~', $launch_url)) {
        delete_transient($saveKey);
        pnk_log('bridge.launch_context_missing', ['pid' => $presentation_id], 'error');
        wp_send_json_error(['message' => 'Редактор вернул устаревший формат запуска'], 502);
    }
    $redirectUrl = pnk_editor_base() . $launch_url;

    wp_send_json_success([
        'redirectUrl'   => $redirectUrl,
        'expiresAt'     => $bridge['expiresAt'] ?? null,
        'requestId'     => $bridge['requestId'] ?? null,
        'presentationId'=> (int)$presentation_id,
        'saveTtlSeconds'=> $ttl_seconds,
    ]);
}
