<?php
if (!defined('ABSPATH')) { exit; }

const PNK_DB_SCHEMA_VERSION = '2';

function pnk_table_name(): string {
    $table = defined('PRESENTONIKA_TABLE_NAME') ? trim((string)PRESENTONIKA_TABLE_NAME) : '';
    if ($table === '') $table = (string)apply_filters('presentonika_table_name', 'wkwa_presentations');
    return preg_match('/^[a-zA-Z0-9_]+$/', $table) ? $table : '';
}

function pnk_points_ledger_table_name(): string {
    global $wpdb;
    return $wpdb->prefix . 'pnk_points_ledger';
}

function pnk_db_require_innodb(string $table): bool {
    global $wpdb;

    $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name=%s', $table));
    if (!$status) return false;
    if (strcasecmp((string)$status->Engine, 'InnoDB') === 0) return true;

    if ($wpdb->query("ALTER TABLE {$table} ENGINE=InnoDB") === false) {
        pnk_log('db.innodb_conversion_failed', [
            'table' => $table,
            'db_error' => (string)$wpdb->last_error,
        ], 'critical');
        return false;
    }
    $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name=%s', $table));
    return $status && strcasecmp((string)$status->Engine, 'InnoDB') === 0;
}

function pnk_db_has_unique_index(string $table, string $index_name, array $expected_columns): bool {
    global $wpdb;

    $rows = $wpdb->get_results("SHOW INDEX FROM {$table}");
    $columns = [];
    $is_unique = false;
    foreach ((array)$rows as $row) {
        if ((string)$row->Key_name !== $index_name) continue;
        $is_unique = (int)$row->Non_unique === 0;
        $columns[(int)$row->Seq_in_index] = (string)$row->Column_name;
    }
    ksort($columns);
    return $is_unique && array_values($columns) === array_values($expected_columns);
}

function pnk_db_schema_is_ready(string $table, string $ledger): bool {
    global $wpdb;

    $presentation_columns = (array)$wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
    $ledger_columns = (array)$wpdb->get_col("SHOW COLUMNS FROM {$ledger}", 0);
    $required_presentation_columns = [
        'presentationID', 'userid', 'status', 'path', 'idempotency_key',
        'charge_state', 'charged_amount', 'job_version', 'created_at', 'updated_at',
    ];
    $required_ledger_columns = [
        'id', 'presentation_id', 'user_id', 'operation', 'amount', 'state',
        'mycred_reference', 'created_at', 'updated_at',
    ];

    return !array_diff($required_presentation_columns, $presentation_columns)
        && !array_diff($required_ledger_columns, $ledger_columns)
        && pnk_db_has_unique_index($table, 'user_idempotency', ['userid', 'idempotency_key'])
        && pnk_db_has_unique_index($ledger, 'presentation_operation', ['presentation_id', 'operation']);
}

function pnk_db_ensure_schema(): bool {
    global $wpdb;

    $table = pnk_table_name();
    if ($table === '') return false;
    $ledger = pnk_points_ledger_table_name();
    $charset_collate = $wpdb->get_charset_collate();
    $previous_version = (string)get_option('pnk_db_schema_version', '');

    $presentations_sql = "CREATE TABLE {$table} (
        presentationID BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        userid BIGINT(20) UNSIGNED NOT NULL,
        presentationname VARCHAR(255) NOT NULL DEFAULT '',
        input_text LONGTEXT NOT NULL,
        theme VARCHAR(64) NOT NULL DEFAULT '',
        status VARCHAR(32) NOT NULL DEFAULT 'pending',
        generation_id VARCHAR(128) NOT NULL DEFAULT '',
        attempts INT(11) NOT NULL DEFAULT 0,
        path TEXT NOT NULL,
        error_message TEXT NOT NULL,
        idempotency_key CHAR(36) NULL DEFAULT NULL,
        charge_state VARCHAR(24) NOT NULL DEFAULT 'uncharged',
        charged_amount INT(11) NOT NULL DEFAULT 0,
        job_version INT(11) UNSIGNED NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (presentationID),
        UNIQUE KEY user_idempotency (userid,idempotency_key),
        KEY userid (userid),
        KEY status (status),
        KEY user_status (userid,status),
        KEY user_created (userid,created_at),
        KEY created_at (created_at)
    ) ENGINE=InnoDB {$charset_collate};";

    $ledger_sql = "CREATE TABLE {$ledger} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        presentation_id BIGINT(20) UNSIGNED NOT NULL,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        operation VARCHAR(16) NOT NULL,
        amount INT(11) NOT NULL,
        state VARCHAR(16) NOT NULL DEFAULT 'pending',
        mycred_reference VARCHAR(64) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY presentation_operation (presentation_id,operation),
        KEY user_created (user_id,created_at),
        KEY state (state)
    ) ENGINE=InnoDB {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($presentations_sql);
    dbDelta($ledger_sql);

    if (!pnk_db_require_innodb($table) || !pnk_db_require_innodb($ledger) || !pnk_db_schema_is_ready($table, $ledger)) {
        pnk_log('db.schema_validation_failed', [
            'presentations_table' => $table,
            'ledger_table' => $ledger,
            'db_error' => (string)$wpdb->last_error,
        ], 'critical');
        return false;
    }

    if ($previous_version !== PNK_DB_SCHEMA_VERSION) {
        // Existing rows predate charge_state. Preserve their already-settled billing outcome.
        $charged_backfill = $wpdb->query(
            "UPDATE {$table}
             SET charge_state='charged', charged_amount=10
             WHERE charge_state='uncharged' AND status IN ('pending','processing','done')"
        );
        $refunded_backfill = $wpdb->query(
            "UPDATE {$table}
             SET charge_state='refunded', charged_amount=10
             WHERE charge_state='uncharged' AND status='failed'"
        );
        if ($charged_backfill === false || $refunded_backfill === false) {
            pnk_log('db.billing_backfill_failed', ['db_error' => (string)$wpdb->last_error], 'critical');
            return false;
        }
    }

    update_option('pnk_db_schema_version', PNK_DB_SCHEMA_VERSION, false);
    return true;
}

function pnk_db_maybe_upgrade(): void {
    if ((string)get_option('pnk_db_schema_version', '') === PNK_DB_SCHEMA_VERSION) return;
    if (!pnk_try_lock('db_schema_upgrade', 120)) return;
    try {
        pnk_db_ensure_schema();
    } finally {
        pnk_release_lock('db_schema_upgrade');
    }
}
add_action('init', 'pnk_db_maybe_upgrade', 1);
