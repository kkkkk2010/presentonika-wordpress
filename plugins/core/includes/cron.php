<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Gamma pipeline
 */
add_action('async_presentation_start', 'pnk_async_presentation_start', 10, 1);
function pnk_async_presentation_start($presentation_id): void {
    $presentation_id = (int)$presentation_id;
    pnk_log('RUN start ENTER', ['pid'=>$presentation_id]);

    global $wpdb;
    $table = pnk_table_name();

    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE presentationID=%d", $presentation_id));
    pnk_log('RUN start fetched', ['pid'=>$presentation_id,'row'=>$row?'1':'0','status'=>$row?(string)$row->status:'']);
    if (!$row) return;
    if (!in_array((string)$row->status, ['pending'], true)) return;
    if (!pnk_claim_generation_start($presentation_id)) return;

    try {
        $gamma = new PNK_Gamma_API();
        $start = $gamma->createPresentation((string)$row->input_text, (string)$row->theme);
    } catch (Throwable $error) {
        pnk_log_exception('gamma.start_exception', $error, ['pid' => $presentation_id]);
        pnk_fail_presentation($presentation_id, ['processing'], 'Gamma: не удалось запустить генерацию');
        return;
    }
    pnk_log('GAMMA createPresentation', ['pid'=>$presentation_id,'uid'=>(int)$row->userid,'ok'=>($start && !empty($start['generationId']))?'1':'0']);

    if (!$start || empty($start['generationId'])) {
        pnk_fail_presentation($presentation_id, ['processing'], 'Gamma: не удалось запустить генерацию');
        return;
    }

    $generationId = (string)$start['generationId'];

    $generation_saved = $wpdb->update(
        $table,
        ['generation_id'=>$generationId,'attempts'=>0,'updated_at'=>current_time('mysql')],
        ['presentationID'=>(int)$presentation_id, 'status'=>'processing'],
        ['%s','%d','%s'],
        ['%d','%s']
    );
    if ($generation_saved !== 1) {
        pnk_log('gamma.generation_id_store_failed', ['pid' => $presentation_id], 'error');
        pnk_fail_presentation($presentation_id, ['processing'], 'Не удалось сохранить идентификатор генерации');
        return;
    }

    if (!pnk_schedule_single('async_presentation_poll', [(int)$presentation_id], time() + 10, 'start schedule poll')) {
        pnk_fail_presentation($presentation_id, ['processing'], 'Не удалось запланировать проверку генерации');
    }
}

add_action('async_presentation_poll', 'pnk_async_presentation_poll', 10, 1);
function pnk_async_presentation_poll($presentation_id): void {
    $presentation_id = (int)$presentation_id;
    pnk_log('RUN poll ENTER', ['pid'=>$presentation_id]);

    $lock_name = 'pid_' . $presentation_id . '_gamma_poll';
    if (!pnk_try_lock($lock_name, 30)) return;

    try {

    global $wpdb;
    $table = pnk_table_name();

    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE presentationID=%d", $presentation_id));
    pnk_log('RUN poll fetched', ['pid'=>$presentation_id,'row'=>$row?'1':'0','status'=>$row?(string)$row->status:'','attempts'=>$row?(int)$row->attempts:0,'has_generation_id'=>$row && !empty($row->generation_id)?'1':'0']);
    if (!$row) return;
    if (!in_array((string)$row->status, ['processing'], true)) return;

    $max_attempts = 20;
    $attempts = (int)$row->attempts;

    if ($attempts >= $max_attempts) {
        pnk_fail_presentation($presentation_id, ['processing'], 'Gamma: превышено время ожидания экспорта');
        return;
    }

    $gamma  = new PNK_Gamma_API();
    $status = $gamma->getResult((string)$row->generation_id);
    pnk_log('GAMMA getResult', ['pid'=>$presentation_id,'uid'=>(int)$row->userid,'ok'=>$status?'1':'0','has_exportUrl'=>($status && !empty($status['exportUrl']))?'1':'0']);

    $attempts++;
    $wpdb->update(
        $table,
        ['attempts' => $attempts, 'updated_at' => current_time('mysql')],
        ['presentationID' => (int)$presentation_id],
        ['%d','%s'],
        ['%d']
    );

    if (!$status) {
        if (!pnk_schedule_single('async_presentation_poll', [(int)$presentation_id], time() + 10, 'poll net err')) {
            pnk_fail_presentation($presentation_id, ['processing'], 'Не удалось продолжить проверку генерации');
        }
        return;
    }

    if (!empty($status['exportUrl'])) {
        $exportUrl = (string)$status['exportUrl'];

        // Avoid duplicate export scheduling if poll fires twice close together
        $marker = 'pnk_export_scheduled_' . (int)$presentation_id;
        if (!get_transient($marker)) {
            set_transient($marker, 1, 15 * MINUTE_IN_SECONDS);
            pnk_log('POLL found exportUrl -> schedule export', ['pid'=>(int)$presentation_id]);
            if (!pnk_schedule_single('async_presentation_export', [(int)$presentation_id, $exportUrl], time() + 1, 'poll found exportUrl')) {
                delete_transient($marker);
                pnk_fail_presentation($presentation_id, ['processing'], 'Не удалось запланировать экспорт презентации');
            }
        } else {
            pnk_log('POLL export already scheduled', ['pid'=>(int)$presentation_id]);
        }
        return;
    }

    if (!pnk_schedule_single('async_presentation_poll', [(int)$presentation_id], time() + 10, 'poll continue')) {
        pnk_fail_presentation($presentation_id, ['processing'], 'Не удалось продолжить проверку генерации');
    }
    } catch (Throwable $error) {
        pnk_log_exception('gamma.poll_exception', $error, ['pid' => $presentation_id]);
        if (!pnk_schedule_single('async_presentation_poll', [$presentation_id], time() + 15, 'poll exception retry')) {
            pnk_fail_presentation($presentation_id, ['processing'], 'Не удалось продолжить проверку генерации');
        }
    } finally {
        pnk_release_lock($lock_name);
    }
}

/**
 * EXPORT worker: Bridge convert + save out.zip to uploads.
 */
add_action('async_presentation_export', 'pnk_async_presentation_export', 10, 2);
function pnk_async_presentation_export($presentation_id, $exportUrl): void {
    $presentation_id = (int)$presentation_id;
    $exportUrl = (string)$exportUrl;

    pnk_log('RUN export ENTER', ['pid'=>$presentation_id]);

    global $wpdb;
    $table = pnk_table_name();

    // Per-presentation lock to prevent duplicate exports for same pid
    $lockName = 'pid_' . $presentation_id . '_export';
    if (!pnk_try_lock($lockName, 600)) {
        pnk_log('RUN export lock busy -> reschedule', ['pid'=>$presentation_id]);
        if (!pnk_schedule_single('async_presentation_export', [$presentation_id, $exportUrl], time() + 15, 'export lock busy')) {
            pnk_fail_presentation($presentation_id, ['processing'], 'Не удалось продолжить экспорт презентации');
        }
        return;
    }

    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE presentationID=%d", $presentation_id));
    pnk_log('RUN export fetched', ['pid'=>$presentation_id,'row'=>$row?'1':'0','status'=>$row?(string)$row->status:'']);

    if (!$row) { pnk_release_lock($lockName); return; }
    if ((string)$row->status !== 'processing') { pnk_release_lock($lockName); return; }

    // Acquire global export slot (queue, do not reject)
    if (!pnk_export_slot_acquire($presentation_id, 900)) {
        pnk_log('RUN export no slot -> reschedule', ['pid'=>$presentation_id,'lim'=>(int)PR_EXPORT_CONCURRENCY]);
        if (!pnk_schedule_single('async_presentation_export', [$presentation_id, $exportUrl], time() + 20, 'export slot busy')) {
            pnk_fail_presentation($presentation_id, ['processing'], 'Не удалось продолжить экспорт презентации');
        }
        pnk_release_lock($lockName);
        return;
    }

    pnk_log('RUN export slot acquired', ['pid'=>$presentation_id]);

    try {
        $bridge = pnk_request_bridge_outzip_from_pptx_url($exportUrl);
        if (is_wp_error($bridge)) {
            pnk_log('bridge.convert_failed', [
                'pid' => $presentation_id,
                'error_code' => $bridge->get_error_code(),
            ], 'error');
            pnk_fail_presentation($presentation_id, ['processing'], 'Не удалось преобразовать презентацию');
            return;
        }

        $saved_url = pnk_save_outzip_to_uploads(
            (string)$bridge['outZipUrl'],
            $presentation_id,
            (int)$row->userid
        );
        if (is_wp_error($saved_url)) {
            pnk_log('storage.gamma_save_failed', [
                'pid' => $presentation_id,
                'error_code' => $saved_url->get_error_code(),
            ], 'error');
            pnk_fail_presentation($presentation_id, ['processing'], 'Не удалось сохранить готовую презентацию');
            return;
        }

        pnk_log('RUN export DONE', ['pid'=>$presentation_id]);
    } catch (Throwable $error) {
        pnk_log_exception('presentation.export_exception', $error, ['pid' => $presentation_id]);
        pnk_fail_presentation($presentation_id, ['processing'], 'Внутренняя ошибка экспорта презентации');
    } finally {
        pnk_export_slot_release($presentation_id);
        pnk_release_lock($lockName);
    }
}

/**
 * Orchestrator pipeline (DeepSeek)
 */
add_action('async_orchestrator_start', 'pnk_async_orchestrator_start', 10, 1);
function pnk_async_orchestrator_start($presentation_id): void {
    $presentation_id = (int)$presentation_id;
    pnk_log('ORCH start ENTER', ['pid'=>$presentation_id]);

    $lockName = 'pid_' . $presentation_id . '_orch_start';
    if (!pnk_try_lock($lockName, 600)) {
        pnk_log('ORCH start lock busy -> reschedule', ['pid'=>$presentation_id]);
        if (!pnk_schedule_single('async_orchestrator_start', [$presentation_id], time() + 10, 'orch lock busy')) {
            pnk_fail_presentation($presentation_id, ['pending'], 'Не удалось продолжить запуск генерации');
        }
        return;
    }

    $saveKey = '';
    try {

    global $wpdb;
    $table = pnk_table_name();
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE presentationID=%d", $presentation_id));
    if (!$row) return;
    if (!in_array((string)$row->status, ['pending'], true)) return;

    if (!pnk_orchestrator_is_enabled()) {
        pnk_fail_presentation($presentation_id, ['pending'], 'Сервис генерации не настроен');
        return;
    }
    if (!pnk_claim_generation_start($presentation_id)) {
        return;
    }

    $themeId = pnk_orchestrator_normalize_theme((string)$row->theme);

    // Create saveToken for orchestrator -> WP save-outzip-from-url
    $saveToken = pnk_generate_save_token();
    $saveKey   = pnk_save_token_key($saveToken);
    $ttl_seconds = 30 * 60;

    $save_context_stored = set_transient($saveKey, [
        'uid' => (int)$row->userid,
        'pid' => (int)$presentation_id,
        'job_version' => (int)$row->job_version,
        'exp' => time() + $ttl_seconds,
    ], $ttl_seconds);
    if (!$save_context_stored) {
        pnk_log('orchestrator.save_context_store_failed', ['pid' => $presentation_id], 'error');
        pnk_fail_presentation($presentation_id, ['processing'], 'Не удалось подготовить сохранение презентации');
        return;
    }

    $payload = [
        'presentationId' => (int)$presentation_id,
        'userId' => (int)$row->userid,
        'requestId' => pnk_log_request_id(),
        'topic' => (string)$row->input_text,
        'themeId' => $themeId,
        'language' => 'ru',
        'save' => [
            'endpoint' => pnk_get_save_endpoint_url(),
            'presentationId' => (int)$presentation_id,
            'saveToken' => $saveToken,
        ],
    ];
    $deckPlan = pnk_orchestrator_get_deck_plan_for_presentation((int)$presentation_id, (int)$row->userid);
    if (is_array($deckPlan)) {
        $payload['deckPlan'] = $deckPlan;
    }

    $json = pnk_orchestrator_post_json('/jobs', $payload, 30);
    if (is_wp_error($json) || empty($json['jobId'])) {
        pnk_log('orchestrator.start_failed', [
            'pid' => $presentation_id,
            'error_code' => is_wp_error($json) ? $json->get_error_code() : 'bad_response',
        ], 'error');
        pnk_fail_presentation($presentation_id, ['processing'], 'Не удалось запустить генерацию');
        delete_transient($saveKey);
        delete_transient(pnk_orchestrator_deck_plan_key((int)$presentation_id));
        return;
    }
    delete_transient(pnk_orchestrator_deck_plan_key((int)$presentation_id));

    $jobId = (string)$json['jobId'];
    $generation_saved = $wpdb->update($table,
        ['generation_id'=>$jobId,'attempts'=>0,'updated_at'=>current_time('mysql')],
        ['presentationID'=>$presentation_id, 'status'=>'processing'],
        ['%s','%d','%s'],
        ['%d','%s']
    );
    if ($generation_saved !== 1) {
        pnk_log('orchestrator.generation_id_store_failed', ['pid' => $presentation_id], 'error');
        delete_transient($saveKey);
        pnk_fail_presentation($presentation_id, ['processing'], 'Не удалось сохранить идентификатор генерации');
        return;
    }

    if (!pnk_schedule_single('async_orchestrator_poll', [$presentation_id], time() + 4, 'orch poll')) {
        delete_transient($saveKey);
        pnk_fail_presentation($presentation_id, ['processing'], 'Не удалось запланировать проверку генерации');
    }
    } catch (Throwable $error) {
        pnk_log_exception('orchestrator.start_exception', $error, ['pid' => $presentation_id]);
        if ($saveKey !== '') delete_transient($saveKey);
        pnk_fail_presentation($presentation_id, ['pending', 'processing'], 'Внутренняя ошибка запуска генерации');
    } finally {
        pnk_release_lock($lockName);
    }
}

add_action('async_orchestrator_poll', 'pnk_async_orchestrator_poll', 10, 1);
function pnk_async_orchestrator_poll($presentation_id): void {
    $presentation_id = (int)$presentation_id;
    pnk_log('ORCH poll ENTER', ['pid'=>$presentation_id]);

    $lock_name = 'pid_' . $presentation_id . '_orchestrator_poll';
    if (!pnk_try_lock($lock_name, 30)) return;

    try {

    global $wpdb;
    $table = pnk_table_name();
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE presentationID=%d", $presentation_id));
    if (!$row) return;

    // if already done by save-outzip callback
    if ((string)$row->status === 'done' && !empty($row->path)) return;
    if ((string)$row->status !== 'processing') return;

    $jobId = (string)($row->generation_id ?? '');
    if ($jobId === '') {
        pnk_fail_presentation($presentation_id, ['processing'], 'Сервис генерации не вернул идентификатор задачи');
        return;
    }

    $max_attempts = 120;
    $attempts = (int)$row->attempts;
    if ($attempts >= $max_attempts) {
        pnk_fail_presentation($presentation_id, ['processing'], 'Превышено время ожидания генерации');
        return;
    }

    $json = pnk_orchestrator_get_json('/jobs/' . rawurlencode($jobId), 20);
    if (is_wp_error($json)) {
        $attempts++;
        $wpdb->update($table, ['attempts'=>$attempts,'updated_at'=>current_time('mysql')], ['presentationID'=>$presentation_id], ['%d','%s'], ['%d']);
        if (!pnk_schedule_single('async_orchestrator_poll', [$presentation_id], time() + 5, 'orch poll (net err)')) {
            pnk_fail_presentation($presentation_id, ['processing'], 'Не удалось продолжить проверку генерации');
        }
        return;
    }

    $state = (string)($json['state'] ?? $json['status'] ?? '');
    if ($state === 'failed') {
        pnk_log('orchestrator.job_failed', [
            'pid' => $presentation_id,
            'reason' => (string)($json['failedReason'] ?? 'failed'),
        ], 'error');
        pnk_fail_presentation($presentation_id, ['processing'], 'Сервис генерации завершил задачу с ошибкой');
        return;
    }

    if ($state === 'completed') {
        // WP callback should have saved out.zip and set path. If not yet, wait a bit.
        $fresh = $wpdb->get_row($wpdb->prepare("SELECT status, path FROM {$table} WHERE presentationID=%d", $presentation_id));
        if ($fresh && (string)$fresh->status === 'done' && !empty($fresh->path)) return;

        $attempts++;
        $wpdb->update($table, ['attempts'=>$attempts,'updated_at'=>current_time('mysql')], ['presentationID'=>$presentation_id], ['%d','%s'], ['%d']);
        if (!pnk_schedule_single('async_orchestrator_poll', [$presentation_id], time() + 3, 'orch poll (waiting wp save)')) {
            pnk_fail_presentation($presentation_id, ['processing'], 'Не удалось дождаться сохранения презентации');
        }
        return;
    }

    // still running
    $attempts++;
    $wpdb->update($table, ['attempts'=>$attempts,'updated_at'=>current_time('mysql')], ['presentationID'=>$presentation_id], ['%d','%s'], ['%d']);
    if (!pnk_schedule_single('async_orchestrator_poll', [$presentation_id], time() + 3, 'orch poll')) {
        pnk_fail_presentation($presentation_id, ['processing'], 'Не удалось продолжить проверку генерации');
    }
    } catch (Throwable $error) {
        pnk_log_exception('orchestrator.poll_exception', $error, ['pid' => $presentation_id]);
        if (!pnk_schedule_single('async_orchestrator_poll', [$presentation_id], time() + 10, 'orch poll exception retry')) {
            pnk_fail_presentation($presentation_id, ['processing'], 'Не удалось продолжить проверку генерации');
        }
    } finally {
        pnk_release_lock($lock_name);
    }
}

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('presentonika recover-stuck', static function (array $args, array $assoc_args): void {
        global $wpdb;
        $minutes = min(1440, max(5, (int)($assoc_args['older-than-minutes'] ?? 30)));
        $limit = min(500, max(1, (int)($assoc_args['limit'] ?? 100)));
        $apply = array_key_exists('apply', $assoc_args);
        $cutoff = gmdate('Y-m-d H:i:s', time() - $minutes * MINUTE_IN_SECONDS);
        $table = pnk_table_name();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT presentationID, status, generation_id, attempts, updated_at
             FROM {$table}
             WHERE status IN ('pending','processing') AND updated_at<%s
             ORDER BY updated_at ASC LIMIT %d",
            $cutoff,
            $limit
        ));

        $report = [];
        foreach ((array)$rows as $row) {
            $presentation_id = (int)$row->presentationID;
            $action = (string)$row->status === 'pending'
                ? 'schedule_start'
                : ((string)$row->generation_id !== '' ? 'schedule_poll' : 'fail_and_reconcile');
            $report[] = [
                'presentation_id' => $presentation_id,
                'status' => (string)$row->status,
                'attempts' => (int)$row->attempts,
                'updated_at' => (string)$row->updated_at,
                'action' => $action,
            ];
            if (!$apply) continue;
            if ($action === 'schedule_start') {
                pnk_schedule_single('async_orchestrator_start', [$presentation_id], time() + 1, 'manual stuck recovery');
            } elseif ($action === 'schedule_poll') {
                pnk_schedule_single('async_orchestrator_poll', [$presentation_id], time() + 1, 'manual stuck recovery');
            } else {
                pnk_fail_presentation($presentation_id, ['processing'], 'Задача восстановлена после зависания');
            }
            pnk_log('generation.stuck_recovered', ['pid' => $presentation_id, 'stage' => $action], 'warning');
        }

        if ($report) WP_CLI\Utils\format_items('table', $report, ['presentation_id', 'status', 'attempts', 'updated_at', 'action']);
        WP_CLI::success(sprintf('%s: %d stuck presentation(s).', $apply ? 'Applied' : 'Dry-run', count($report)));
    });
}
