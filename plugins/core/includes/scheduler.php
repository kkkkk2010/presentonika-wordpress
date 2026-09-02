<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Prefer Action Scheduler (WooCommerce) when available.
 * Fallback to wp_schedule_single_event.
 */
function pnk_schedule_single(string $hook, array $args, int $timestamp, string $why = ''): bool {
    if (function_exists('as_schedule_single_action')) {
        $action_id = as_schedule_single_action($timestamp, $hook, $args, 'presentonika');
        $ok = (is_numeric($action_id) && (int)$action_id > 0);
        pnk_log('SCHEDULE AS', ['hook'=>$hook,'args'=>$args,'ts'=>$timestamp,'ok'=>$ok?'1':'0','why'=>$why,'action_id'=>$action_id]);
        return $ok;
    }

    $ok = wp_schedule_single_event($timestamp, $hook, $args);
    pnk_log('SCHEDULE CRON', ['hook'=>$hook,'args'=>$args,'ts'=>$timestamp,'ok'=>$ok?'1':'0','why'=>$why]);
    return (bool)$ok;
}
