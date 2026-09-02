<?php
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/rate_limit.php';
require_once __DIR__ . '/scheduler.php';
require_once __DIR__ . '/locks.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mycred.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/gamma.php';
require_once __DIR__ . '/orchestrator.php';
require_once __DIR__ . '/bridge.php';
require_once __DIR__ . '/storage-yandex.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/persist.php';
require_once __DIR__ . '/cron.php';
require_once __DIR__ . '/ajax.php';
require_once __DIR__ . '/rest.php';
require_once __DIR__ . '/image-search.php';
require_once __DIR__ . '/doctor.php';
function pnk_core_activate(): void {
    pnk_db_ensure_schema();
}
function pnk_core_deactivate(): void {
    // We intentionally do NOT drop tables.
    // Best-effort: clear transients created by save tokens is not feasible here.
}
