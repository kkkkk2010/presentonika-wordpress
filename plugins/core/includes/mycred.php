<?php
if (!defined('ABSPATH')) { exit; }

/**
 * A generation refund returns points that were already charged for the same
 * presentation. It must not depend on the optional Central Deposit account
 * having a positive balance. myCred exposes this allow-list specifically for
 * system operations that should bypass Central Banking.
 */
function pnk_mycred_central_banking_ignore(array $references): array {
    $references[] = 'presentation_generation_refund';
    return array_values(array_unique($references));
}
add_filter('mycred_central_banking_ignore', 'pnk_mycred_central_banking_ignore');

function pnk_get_user_points_balance(int $user_id): int {
    if (function_exists('mycred_get_users_balance')) {
        return (int)mycred_get_users_balance($user_id, 'points');
    }
    if (class_exists('WC_Points_Rewards_Manager')) {
        return (int)WC_Points_Rewards_Manager::get_users_points($user_id);
    }
    return 0;
}

function pnk_points_operation(int $user_id, int $presentation_id, int $amount, string $operation): bool {
    global $wpdb;

    $amount = max(0, $amount);
    if ($amount === 0) return true;
    if (!in_array($operation, ['charge', 'refund'], true) || !function_exists('mycred')) return false;

    $lock_name = 'billing_' . $presentation_id;
    if (!pnk_try_lock($lock_name, 60)) {
        pnk_log('billing.lock_busy', ['pid' => $presentation_id, 'operation' => $operation], 'warning');
        return false;
    }

    $table = pnk_table_name();
    $ledger = pnk_points_ledger_table_name();
    $mycred = mycred('points');
    if (!method_exists($mycred, 'has_entry')) {
        pnk_log('billing.mycred_idempotency_unavailable', ['pid' => $presentation_id], 'critical');
        pnk_release_lock($lock_name);
        return false;
    }
    $reference = $operation === 'charge' ? 'presentation_generation' : 'presentation_generation_refund';
    $delta = $operation === 'charge' ? -$amount : $amount;
    $target_state = $operation === 'charge' ? 'charged' : 'refunded';
    $entry = $operation === 'charge'
        ? 'Генерация презентации #' . $presentation_id
        : 'Возврат за неудачную генерацию #' . $presentation_id;

    try {
        $wpdb->query('START TRANSACTION');
        $presentation = $wpdb->get_row($wpdb->prepare(
            "SELECT presentationID, userid, charge_state, charged_amount
             FROM {$table} WHERE presentationID=%d FOR UPDATE",
            $presentation_id
        ));
        if (!$presentation || (int)$presentation->userid !== $user_id) {
            $wpdb->query('ROLLBACK');
            return false;
        }

        $current_state = (string)$presentation->charge_state;
        if ($current_state === $target_state) {
            $wpdb->query('COMMIT');
            return true;
        }
        if ($operation === 'charge' && $current_state === 'refunded') {
            $wpdb->query('ROLLBACK');
            return false;
        }
        if ($operation === 'refund' && $current_state === 'uncharged') {
            $wpdb->query('COMMIT');
            return true;
        }

        $ledger_row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, state FROM {$ledger}
             WHERE presentation_id=%d AND operation=%s FOR UPDATE",
            $presentation_id,
            $operation
        ));
        if (!$ledger_row) {
            $inserted = $wpdb->insert($ledger, [
                'presentation_id' => $presentation_id,
                'user_id' => $user_id,
                'operation' => $operation,
                'amount' => $amount,
                'state' => 'pending',
                'mycred_reference' => $reference,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ], ['%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s']);
            if (!$inserted) throw new RuntimeException('Cannot create points ledger row');
            $ledger_id = (int)$wpdb->insert_id;
        } else {
            $ledger_id = (int)$ledger_row->id;
        }

        $already_applied = $mycred->has_entry($reference, $presentation_id, $user_id, null, 'points');
        if (!$already_applied) {
            if ($operation === 'charge' && pnk_get_user_points_balance($user_id) < $amount) {
                $wpdb->query('ROLLBACK');
                return false;
            }

            $applied = $mycred->add_creds(
                $reference,
                $user_id,
                $delta,
                $entry,
                $presentation_id,
                ['ref_type' => 'presentation'],
                'points'
            );
            if (!$applied) throw new RuntimeException('myCred rejected points operation');
            if (!$mycred->has_entry($reference, $presentation_id, $user_id, null, 'points')) {
                throw new RuntimeException('myCred operation was not persisted');
            }
        }

        $ledger_updated = $wpdb->update($ledger, [
            'state' => 'applied',
            'updated_at' => current_time('mysql'),
        ], ['id' => $ledger_id], ['%s', '%s'], ['%d']);
        if ($ledger_updated === false) throw new RuntimeException('Cannot finalize points ledger row');

        $presentation_updated = $wpdb->update($table, [
            'charge_state' => $target_state,
            'charged_amount' => $amount,
            'updated_at' => current_time('mysql'),
        ], ['presentationID' => $presentation_id], ['%s', '%d', '%s'], ['%d']);
        if ($presentation_updated !== 1) throw new RuntimeException('Cannot finalize presentation billing state');
        if ($wpdb->query('COMMIT') === false) throw new RuntimeException('Cannot commit points operation');

        pnk_log('billing.' . $operation . '.applied', [
            'pid' => $presentation_id,
            'uid' => $user_id,
            'amount' => $amount,
            'recovered_existing_entry' => $already_applied,
        ]);
        return true;
    } catch (Throwable $error) {
        $wpdb->query('ROLLBACK');
        if (function_exists('clean_user_cache')) clean_user_cache($user_id);
        if (function_exists('wp_cache_delete')) wp_cache_delete($user_id, 'user_meta');
        pnk_log_exception('billing.' . $operation . '.failed', $error, [
            'pid' => $presentation_id,
            'uid' => $user_id,
            'amount' => $amount,
        ]);
        return false;
    } finally {
        pnk_release_lock($lock_name);
    }
}

function pnk_deduct_points_for_presentation(int $user_id, int $presentation_id, int $amount = 10): bool {
    return pnk_points_operation($user_id, $presentation_id, $amount, 'charge');
}

function pnk_refund_points_for_presentation(int $user_id, int $presentation_id, int $amount = 10): bool {
    return pnk_points_operation($user_id, $presentation_id, $amount, 'refund');
}
