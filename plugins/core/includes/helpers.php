<?php
if (!defined('ABSPATH')) { exit; }

function pnk_text_length(string $value): int {
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function pnk_text_substr(string $value, int $start, int $length): string {
    return function_exists('mb_substr') ? mb_substr($value, $start, $length) : substr($value, $start, $length);
}

function pnk_validate_topic_text(string $text) {
    $length = pnk_text_length($text);
    if ($length < 10) return new WP_Error('topic_too_short', 'Текст слишком короткий');

    $maximum = defined('PRESENTONIKA_MAX_TOPIC_CHARS') ? max(1000, (int)PRESENTONIKA_MAX_TOPIC_CHARS) : 20000;
    if ($length > $maximum) {
        return new WP_Error('topic_too_large', 'Текст слишком большой. Сократите его до ' . $maximum . ' символов.');
    }
    return true;
}

function pnk_save_token_key(string $token): string {
    return 'pnk_save_' . preg_replace('~[^a-zA-Z0-9_\-]~', '', $token);
}

function pnk_generate_save_token(): string {
    return wp_generate_password(48, false, false);
}

function pnk_get_save_endpoint_url(): string {
    $base = rtrim(site_url(), '/');
    return $base . '/wp-json/presentonika/v1/save-outzip-from-url';
}

function pnk_detect_presentation_id_from_request(): int {
    $qid = isset($_GET['presentation_id']) ? (int)$_GET['presentation_id'] : 0;
    if ($qid > 0) return $qid;

    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (preg_match('~\/presentation\/(\d+)\/?~', $uri, $m)) {
        return (int)$m[1];
    }
    return 0;
}
