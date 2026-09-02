<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Defaults. Prefer defining secrets in wp-config.php.
 */
if (!defined('PRESENTONIKA_EDITOR_BASE')) {
    define('PRESENTONIKA_EDITOR_BASE', 'https://editor.presentonika.ru');
}
if (!defined('PRESENTONIKA_BRIDGE_TOKEN')) {
    define('PRESENTONIKA_BRIDGE_TOKEN', '');
}
if (!defined('PRESENTONIKA_SAVE_VALIDATE_BEARER')) {
    define('PRESENTONIKA_SAVE_VALIDATE_BEARER', '');
}
if (!defined('PRESENTONIKA_MAX_OUTZIP_BYTES')) {
    define('PRESENTONIKA_MAX_OUTZIP_BYTES', 50 * 1024 * 1024);
}
if (!defined('PRESENTONIKA_MAX_TOPIC_CHARS')) {
    define('PRESENTONIKA_MAX_TOPIC_CHARS', 20000);
}
if (!defined('PR_SAVE_HANDLER_VERSION')) {
    define('PR_SAVE_HANDLER_VERSION', '2026-08-05-p0-hardening');
}

if (!defined('PRESENTONIKA_ORCHESTRATOR_BASE')) {
    define('PRESENTONIKA_ORCHESTRATOR_BASE', 'https://editor.presentonika.ru/orchestrator');
}
if (!defined('PRESENTONIKA_ORCHESTRATOR_KEY')) {
    define('PRESENTONIKA_ORCHESTRATOR_KEY', '');
}
if (!defined('PRESENTONIKA_ORCHESTRATOR_THEMES')) {
    define('PRESENTONIKA_ORCHESTRATOR_THEMES', 'teacher-dark,teacher-light,teacher-bright');
}

if (!defined('PR_EXPORT_CONCURRENCY')) {
    define('PR_EXPORT_CONCURRENCY', 2);
}
if (!defined('PRESENTONIKA_TRUST_CF_CONNECTING_IP')) {
    define('PRESENTONIKA_TRUST_CF_CONNECTING_IP', false);
}

if (!defined('PR_LOG_ENABLED')) {
    define('PR_LOG_ENABLED', true);
}
if (!defined('PR_LOG_LEVEL')) {
    define('PR_LOG_LEVEL', 'info');
}
if (!defined('PR_LOG_RETENTION_DAYS')) {
    define('PR_LOG_RETENTION_DAYS', 30);
}

if (!defined('PRESENTONIKA_PRIVATE_DIR')) {
    define('PRESENTONIKA_PRIVATE_DIR', dirname(ABSPATH) . '/presentonika-private');
}
if (!defined('PRESENTONIKA_LOG_DIR')) {
    define('PRESENTONIKA_LOG_DIR', rtrim((string)PRESENTONIKA_PRIVATE_DIR, '/\\') . '/logs');
}
if (!defined('PRESENTONIKA_STORAGE_DIR')) {
    define('PRESENTONIKA_STORAGE_DIR', rtrim((string)PRESENTONIKA_PRIVATE_DIR, '/\\') . '/presentations');
}
if (!defined('PRESENTONIKA_DOWNLOAD_URL_TTL')) {
    define('PRESENTONIKA_DOWNLOAD_URL_TTL', 300);
}

if (!defined('PRESENTONIKA_TABLE_NAME')) {
    // Optional override in wp-config.php
    define('PRESENTONIKA_TABLE_NAME', '');
}

function pnk_editor_base(): string {
    return rtrim((string)PRESENTONIKA_EDITOR_BASE, '/');
}
