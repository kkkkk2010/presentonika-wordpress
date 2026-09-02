<?php
if (!defined('ABSPATH')) { exit; }

function pnk_claim_generation_start(int $presentation_id): bool {
    global $wpdb;
    $table = pnk_table_name();
    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$table}
         SET status='processing', attempts=0, updated_at=%s
         WHERE presentationID=%d AND status='pending' AND charge_state='charged'",
        current_time('mysql'),
        $presentation_id
    ));
    return $updated === 1;
}

function pnk_fail_presentation(int $presentation_id, array $expected_statuses, string $message): bool {
    global $wpdb;
    $table = pnk_table_name();
    $expected_statuses = array_values(array_intersect($expected_statuses, ['pending', 'processing']));
    if (!$expected_statuses) return false;

    $placeholders = implode(',', array_fill(0, count($expected_statuses), '%s'));
    $arguments = array_merge([
        pnk_text_substr($message, 0, 1000),
        current_time('mysql'),
        $presentation_id,
    ], $expected_statuses);
    $sql = "UPDATE {$table}
            SET status='failing', error_message=%s, updated_at=%s
            WHERE presentationID=%d AND status IN ({$placeholders})";
    $claimed = $wpdb->query($wpdb->prepare($sql, ...$arguments));

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT userid, status, charge_state, charged_amount FROM {$table} WHERE presentationID=%d",
        $presentation_id
    ));
    if (!$row) return false;
    if ($claimed !== 1 && !in_array((string)$row->status, ['failing', 'failed'], true)) return false;

    if (!pnk_schedule_single('pnk_async_billing_reconcile', [$presentation_id], time() + 30, 'billing safety reconcile')) {
        pnk_log('billing.reconcile_schedule_failed', ['pid' => $presentation_id], 'critical');
    }

    $amount = max(0, (int)$row->charged_amount);
    $refunded = (string)$row->charge_state === 'refunded'
        || (string)$row->charge_state === 'uncharged'
        || pnk_refund_points_for_presentation((int)$row->userid, $presentation_id, $amount ?: 10);

    if (!$refunded) {
        pnk_log('presentation.refund_pending', ['pid' => $presentation_id, 'uid' => (int)$row->userid], 'critical');
    }

    $wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET status='failed', updated_at=%s
         WHERE presentationID=%d AND status='failing'",
        current_time('mysql'),
        $presentation_id
    ));
    pnk_log('presentation.failed', ['pid' => $presentation_id, 'refund_ok' => $refunded], 'warning');
    return true;
}

add_action('pnk_async_billing_reconcile', 'pnk_async_billing_reconcile', 10, 1);
function pnk_async_billing_reconcile($presentation_id): void {
    global $wpdb;
    $presentation_id = (int)$presentation_id;
    $table = pnk_table_name();
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT userid, status, charge_state, charged_amount FROM {$table} WHERE presentationID=%d",
        $presentation_id
    ));
    if (!$row || !in_array((string)$row->status, ['failing', 'failed'], true)) return;

    if ((string)$row->charge_state === 'charged') {
        $ok = pnk_refund_points_for_presentation(
            (int)$row->userid,
            $presentation_id,
            max(1, (int)$row->charged_amount)
        );
        if (!$ok) {
            pnk_log('billing.reconcile.retry', ['pid' => $presentation_id], 'error');
            if (!pnk_schedule_single('pnk_async_billing_reconcile', [$presentation_id], time() + 120, 'billing reconcile retry')) {
                pnk_log('billing.reconcile_schedule_failed', ['pid' => $presentation_id], 'critical');
            }
            return;
        }
    }

    $wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET status='failed', updated_at=%s
         WHERE presentationID=%d AND status='failing'",
        current_time('mysql'),
        $presentation_id
    ));
}
