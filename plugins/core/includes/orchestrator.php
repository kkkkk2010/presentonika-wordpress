<?php
if (!defined('ABSPATH')) { exit; }

function pnk_orchestrator_base(): string {
    $b = defined('PRESENTONIKA_ORCHESTRATOR_BASE') ? (string)PRESENTONIKA_ORCHESTRATOR_BASE : '';
    return rtrim($b, '/');
}

function pnk_orchestrator_is_enabled(): bool {
    $k = defined('PRESENTONIKA_ORCHESTRATOR_KEY') ? trim((string)PRESENTONIKA_ORCHESTRATOR_KEY) : '';
    return $k !== '';
}

function pnk_orchestrator_headers(string $request_id = ''): array {
    $h = ['Content-Type' => 'application/json'];
    $k = defined('PRESENTONIKA_ORCHESTRATOR_KEY') ? trim((string)PRESENTONIKA_ORCHESTRATOR_KEY) : '';
    if ($k !== '') $h['X-Orchestrator-Key'] = $k;
    if ($request_id !== '') $h['X-Request-Id'] = $request_id;
    return $h;
}

function pnk_orchestrator_allowed_themes(): array {
    $raw = defined('PRESENTONIKA_ORCHESTRATOR_THEMES') ? (string)PRESENTONIKA_ORCHESTRATOR_THEMES : '';
    $parts = array_filter(array_map('trim', explode(',', $raw)));
    return $parts ?: ['teacher-dark','teacher-light','teacher-bright'];
}

function pnk_orchestrator_normalize_theme(string $theme): string {
    $theme = trim($theme);
    $allowed = pnk_orchestrator_allowed_themes();
    if (!in_array($theme, $allowed, true)) return $allowed[0];
    return $theme;
}

function pnk_orchestrator_post_json(string $path, array $payload, int $timeout = 60) {
    $base = pnk_orchestrator_base();
    if ($base === '') return new WP_Error('orchestrator_not_configured', 'Orchestrator base not configured');
    if (!pnk_orchestrator_is_enabled()) return new WP_Error('orchestrator_not_configured', 'Orchestrator key not configured');

    $url = $base . '/' . ltrim($path, '/');
    $request_id = isset($payload['requestId']) && is_string($payload['requestId'])
        ? $payload['requestId']
        : pnk_log_request_id();
    $resp = wp_remote_post($url, [
        'headers' => pnk_orchestrator_headers($request_id),
        'body'    => wp_json_encode($payload),
        'timeout' => $timeout,
    ]);

    if (is_wp_error($resp)) {
        pnk_log('orchestrator.request_failed', ['path' => $path, 'error_code' => $resp->get_error_code()], 'error');
        return new WP_Error('orchestrator_request_failed', 'Orchestrator request failed');
    }

    $code = (int) wp_remote_retrieve_response_code($resp);
    $body = (string) wp_remote_retrieve_body($resp);
    $json = json_decode($body, true);

    if ($code < 200 || $code >= 300 || !is_array($json)) {
        pnk_log('orchestrator.bad_response', ['path' => $path, 'http_status' => $code], 'error');
        return new WP_Error('orchestrator_bad_response', 'Orchestrator returned an invalid response');
    }
    return $json;
}

function pnk_orchestrator_deck_plan_key(int $presentation_id): string {
    return 'pnk_deck_plan_' . (int)$presentation_id;
}

function pnk_orchestrator_normalize_presentation_type(string $presentation_type): string {
    $presentation_type = trim($presentation_type);
    $allowed = [
        'auto',
        'historical_overview',
        'overview',
        'lesson',
        'causes_consequences',
        'biography_contribution',
        'literary_analysis',
        'law_formula',
        'process',
        'comparison',
    ];
    return in_array($presentation_type, $allowed, true) ? $presentation_type : 'auto';
}

function pnk_orchestrator_decode_deck_plan_payload($raw) {
    if ($raw === null || $raw === '') return null;
    if (!is_string($raw)) return new WP_Error('bad_deck_plan', 'Некорректный формат DeckPlan');

    $raw = wp_unslash($raw);
    if (strlen($raw) > 220000) {
        return new WP_Error('deck_plan_too_large', 'DeckPlan слишком большой');
    }

    $plan = json_decode($raw, true);
    if (!is_array($plan)) {
        return new WP_Error('bad_deck_plan_json', 'DeckPlan не является корректным JSON');
    }

    if ((int)($plan['version'] ?? 0) !== 1) {
        return new WP_Error('bad_deck_plan_version', 'DeckPlan version должен быть 1');
    }

    if (empty($plan['centralQuestion']) || empty($plan['thesis']) || empty($plan['slides']) || !is_array($plan['slides'])) {
        return new WP_Error('bad_deck_plan_shape', 'DeckPlan должен содержать centralQuestion, thesis и slides');
    }

    $slide_count = (int)($plan['slideCount'] ?? count($plan['slides']));
    if ($slide_count <= 0 || $slide_count > 50 || count($plan['slides']) !== $slide_count) {
        return new WP_Error('bad_deck_plan_slide_count', 'Некорректное количество слайдов в DeckPlan');
    }

    $plan['source'] = 'user_edited';
    if (empty($plan['language'])) $plan['language'] = 'ru';
    return $plan;
}

function pnk_orchestrator_store_deck_plan(int $presentation_id, int $user_id, array $deck_plan): bool {
    $deck_plan['source'] = 'user_edited';
    $key = pnk_orchestrator_deck_plan_key($presentation_id);
    $payload = [
        'uid' => (int)$user_id,
        'pid' => (int)$presentation_id,
        'deckPlan' => $deck_plan,
        'createdAt' => time(),
    ];
    if (set_transient($key, $payload, 2 * HOUR_IN_SECONDS)) return true;

    $existing = get_transient($key);
    return is_array($existing)
        && (int)($existing['uid'] ?? 0) === $user_id
        && (int)($existing['pid'] ?? 0) === $presentation_id
        && ($existing['deckPlan'] ?? null) === $deck_plan;
}

function pnk_orchestrator_get_deck_plan_for_presentation(int $presentation_id, int $user_id) {
    $stored = get_transient(pnk_orchestrator_deck_plan_key($presentation_id));
    if (!is_array($stored)) return null;
    if ((int)($stored['uid'] ?? 0) !== (int)$user_id) return null;
    if ((int)($stored['pid'] ?? 0) !== (int)$presentation_id) return null;
    $deck_plan = $stored['deckPlan'] ?? null;
    return is_array($deck_plan) ? $deck_plan : null;
}

function pnk_orchestrator_get_json(string $path, int $timeout = 30) {
    $base = pnk_orchestrator_base();
    if ($base === '') return new WP_Error('orchestrator_not_configured', 'Orchestrator base not configured');
    if (!pnk_orchestrator_is_enabled()) return new WP_Error('orchestrator_not_configured', 'Orchestrator key not configured');

    $url = $base . '/' . ltrim($path, '/');
    $resp = wp_remote_get($url, [
        'headers' => pnk_orchestrator_headers(pnk_log_request_id()),
        'timeout' => $timeout,
    ]);

    if (is_wp_error($resp)) {
        pnk_log('orchestrator.request_failed', ['path' => $path, 'error_code' => $resp->get_error_code()], 'error');
        return new WP_Error('orchestrator_request_failed', 'Orchestrator request failed');
    }

    $code = (int) wp_remote_retrieve_response_code($resp);
    $body = (string) wp_remote_retrieve_body($resp);
    $json = json_decode($body, true);

    if ($code < 200 || $code >= 300 || !is_array($json)) {
        pnk_log('orchestrator.bad_response', ['path' => $path, 'http_status' => $code], 'error');
        return new WP_Error('orchestrator_bad_response', 'Orchestrator returned an invalid response');
    }
    return $json;
}
