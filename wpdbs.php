<?php
/**
 * WordPress Database Manager (Adminer-like)
 * - Table list, data browsing, sorting, filtering, pagination
 * - Row edit, delete, insert
 * - Table structure view
 * - AES-encrypted SQL console
 */

function find_wp_load() {
    $dir = __DIR__;
    while ($dir !== dirname($dir)) {
        if (file_exists($dir . '/wp-load.php')) {
            return $dir . '/wp-load.php';
        }
        $dir = dirname($dir);
    }
    return false;
}

$wp_load_path = find_wp_load();
if (!$wp_load_path) {
    die('Cannot locate wp-load.php');
}

require_once $wp_load_path;
global $wpdb, $table_prefix;

define('AES_SECRET', 'your-password-or-secret-here');

function get_aes_key() {
    return md5(AES_SECRET, true);
}

function decrypt_sql_payload($encryptedBase64, $ivBase64) {
    $key = get_aes_key();
    $cipherRaw = base64_decode($encryptedBase64, true);
    $iv = base64_decode($ivBase64, true);

    if ($cipherRaw === false || $iv === false || strlen($iv) !== 16) {
        return false;
    }

    return openssl_decrypt($cipherRaw, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);
}

function generate_sql_iv() {
    try {
        return random_bytes(16);
    } catch (Exception $e) {
        return openssl_random_pseudo_bytes(16);
    }
}

function encrypt_sql_text($sql) {
    $sql = trim((string) $sql);
    if ($sql === '') {
        return null;
    }

    $key = get_aes_key();
    $iv = generate_sql_iv();
    $cipherRaw = openssl_encrypt($sql, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);

    if ($cipherRaw === false) {
        return null;
    }

    return [
        'cipher' => base64_encode($cipherRaw),
        'iv' => base64_encode($iv),
        'length' => strlen($sql),
    ];
}

function track_sql_statement($label, $sql) {
    global $sql_trace_entries;

    $payload = encrypt_sql_text($sql);
    if ($payload === null) {
        return null;
    }

    $entry = [
        'label' => (string) $label,
        'cipher' => $payload['cipher'],
        'iv' => $payload['iv'],
        'length' => $payload['length'],
    ];

    $sql_trace_entries[] = $entry;
    return $entry;
}

function get_last_sql_trace() {
    global $sql_trace_entries;

    if (empty($sql_trace_entries)) {
        return null;
    }

    return $sql_trace_entries[count($sql_trace_entries) - 1];
}

function redact_sql_error_message($message) {
    $message = trim((string) $message);
    if ($message === '') {
        return 'SQL operation failed.';
    }

    if (preg_match('/\b(select|insert|update|delete|replace|alter|drop|create|truncate|show|describe|with)\b/i', $message)) {
        return 'SQL operation failed. The statement details were hidden and are only available in encrypted form.';
    }

    return $message;
}

function request_value($key, $default = null) {
    if (array_key_exists($key, $_POST)) {
        return $_POST[$key];
    }

    return $default;
}

function request_array_value($key, $default = []) {
    $value = request_value($key, $default);
    return is_array($value) ? $value : $default;
}

function is_probable_sql_text($value) {
    $value = trim((string) $value);
    if ($value === '' || strlen($value) < 12) {
        return false;
    }

    return preg_match('/^\s*(select|insert|update|delete|replace|alter|drop|create|truncate|with|show|describe|call)\b/i', $value) === 1;
}

function clip_text($value, $limit = 88) {
    $value = (string) $value;
    if (strlen($value) <= $limit) {
        return $value;
    }

    return substr($value, 0, $limit) . '...';
}

function render_masked_value($value) {
    $value = (string) $value;

    if (!is_probable_sql_text($value)) {
        return h($value);
    }

    $payload = encrypt_sql_text($value);
    if ($payload === null) {
        return '<span class="sql-inline-label">Encrypted SQL hidden</span>';
    }

    return '<div class="sql-inline-mask">'
        . '<span class="sql-inline-label">Encrypted SQL</span>'
        . '<code class="sql-inline-code" title="' . h($payload['cipher']) . '">' . h(clip_text($payload['cipher'], 120)) . '</code>'
        . '<span class="sql-inline-meta">IV ' . h(clip_text($payload['iv'], 36)) . '</span>'
        . '</div>';
}

function h($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function render_hidden_fields($fields, $prefix = '') {
    foreach ($fields as $name => $value) {
        $input_name = $prefix === '' ? $name : $prefix . '[' . $name . ']';

        if (is_array($value)) {
            render_hidden_fields($value, $input_name);
            continue;
        }

        if ($value === null) {
            continue;
        }

        echo '<input type="hidden" name="' . h($input_name) . '" value="' . h($value) . '">';
    }
}

function build_browse_post_fields($table, $where_conditions = [], $order_by = null, $order_dir = 'ASC', $page = 1) {
    $fields = [
        'action' => 'browse',
        'table' => $table,
        'p' => max(1, (int) $page),
    ];

    if ($order_by) {
        $fields['order_by'] = $order_by;
        $fields['order_dir'] = strtoupper((string) $order_dir) === 'DESC' ? 'DESC' : 'ASC';
    }

    if (!empty($where_conditions)) {
        $fields['where'] = $where_conditions;
    }

    return $fields;
}

function build_action_post_fields($action, $table = null, $extra = [], $browse_state = []) {
    $fields = ['action' => $action];

    if ($table !== null && $table !== '') {
        $fields['table'] = $table;
    }

    foreach ($extra as $key => $value) {
        $fields[$key] = $value;
    }

    return array_merge($fields, $browse_state);
}

function display_config_value($value) {
    $value = (string) $value;
    return $value === '' ? '(empty)' : $value;
}

function normalize_export_format($value) {
    $format = strtolower(trim((string) $value));
    return in_array($format, ['csv', 'json', 'sql'], true) ? $format : 'csv';
}

function sanitize_export_filename_segment($value, $fallback = 'export') {
    $sanitized = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $value);
    $sanitized = trim($sanitized !== null ? $sanitized : '', '._-');
    return $sanitized === '' ? $fallback : $sanitized;
}

function export_sql_identifier($value) {
    return '`' . str_replace('`', '``', (string) $value) . '`';
}

function export_sql_literal($value) {
    if ($value === null) {
        return 'NULL';
    }

    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    $value = str_replace(
        ["\\", "\0", "\n", "\r", "'", "\x1a"],
        ["\\\\", "\\0", "\\n", "\\r", "\\'", "\\Z"],
        (string) $value
    );

    return "'" . $value . "'";
}

function normalize_export_sql_columns(array $columns) {
    $normalized = [];
    $used = [];

    foreach (array_values($columns) as $index => $column) {
        $candidate = preg_replace('/[^A-Za-z0-9_]+/', '_', strtolower((string) $column));
        $candidate = trim($candidate !== null ? $candidate : '', '_');

        if ($candidate === '') {
            $candidate = 'column_' . ($index + 1);
        }

        if (preg_match('/^[0-9]/', $candidate)) {
            $candidate = 'col_' . $candidate;
        }

        $base = $candidate;
        $suffix = 2;
        while (isset($used[$candidate])) {
            $candidate = $base . '_' . $suffix;
            $suffix++;
        }

        $used[$candidate] = true;
        $normalized[$column] = $candidate;
    }

    return $normalized;
}

function render_export_form($post_target, array $hidden_fields, $scope, $label = 'Export current result') {
    echo '<form method="post" action="' . h($post_target) . '" class="actions export-form">';
    render_hidden_fields($hidden_fields);
    echo '<input type="hidden" name="export_scope" value="' . h($scope) . '">';
    echo '<span class="export-label">' . h($label) . '</span>';
    echo '<select name="export_format">';
    echo '<option value="csv">CSV</option>';
    echo '<option value="json">JSON</option>';
    echo '<option value="sql">SQL</option>';
    echo '</select>';
    echo '<button type="submit">Export</button>';
    echo '</form>';
}

function download_export_file($format, $filename, array $rows, array $columns, $table_name = null, $use_generic_sql_schema = false) {
    if (function_exists('ob_get_level')) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        echo "\xEF\xBB\xBF";

        $output = fopen('php://output', 'w');
        if ($output !== false) {
            if (!empty($columns)) {
                fputcsv($output, $columns);
            }

            foreach ($rows as $row) {
                $line = [];
                foreach ($columns as $column) {
                    $line[] = array_key_exists($column, $row) ? $row[$column] : null;
                }
                fputcsv($output, $line);
            }

            fclose($output);
        }

        exit;
    }

    if ($format === 'json') {
        header('Content-Type: application/json; charset=UTF-8');
        $json = json_encode(
            $rows,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        echo $json === false ? '[]' : $json;
        exit;
    }

    header('Content-Type: application/sql; charset=UTF-8');

    $lines = [
        '-- Exported by Database Manager on ' . gmdate('Y-m-d H:i:s') . ' UTC',
        'SET NAMES utf8mb4;',
        '',
    ];

    $target_table = $table_name ?: 'exported_result';
    $column_map = [];

    if ($use_generic_sql_schema) {
        $table_token = preg_replace('/[^A-Za-z0-9_]+/', '_', (string) $target_table);
        $table_token = trim($table_token !== null ? $table_token : '', '_');
        if ($table_token === '' || preg_match('/^[0-9]/', $table_token)) {
            $table_token = 'exported_result';
        }
        $target_table = $table_token;
        $column_map = normalize_export_sql_columns($columns);

        if (!empty($columns)) {
            $create_columns = [];
            foreach ($columns as $column) {
                $create_columns[] = '  ' . export_sql_identifier($column_map[$column]) . ' LONGTEXT NULL';
            }

            $lines[] = 'DROP TABLE IF EXISTS ' . export_sql_identifier($target_table) . ';';
            $lines[] = 'CREATE TABLE ' . export_sql_identifier($target_table) . ' (';
            $lines[] = implode(',' . PHP_EOL, $create_columns);
            $lines[] = ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;';
            $lines[] = '';
        }
    } else {
        foreach ($columns as $column) {
            $column_map[$column] = $column;
        }
    }

    if (empty($rows)) {
        $lines[] = '-- Query returned 0 rows.';
        echo implode(PHP_EOL, $lines);
        exit;
    }

    foreach ($rows as $row) {
        $column_sql = [];
        $value_sql = [];

        foreach ($columns as $column) {
            $target_column = array_key_exists($column, $column_map) ? $column_map[$column] : $column;
            $column_sql[] = export_sql_identifier($target_column);
            $value_sql[] = export_sql_literal(array_key_exists($column, $row) ? $row[$column] : null);
        }

        $lines[] = 'INSERT INTO ' . export_sql_identifier($target_table)
            . ' (' . implode(', ', $column_sql) . ') VALUES (' . implode(', ', $value_sql) . ');';
    }

    echo implode(PHP_EOL, $lines);
    exit;
}

function db_get_results_secure($sql, $output = OBJECT, $label = 'SQL Query') {
    global $wpdb;
    track_sql_statement($label, $sql);
    return $wpdb->get_results($sql, $output);
}

function db_get_row_secure($sql, $output = OBJECT, $label = 'SQL Query') {
    global $wpdb;
    track_sql_statement($label, $sql);
    return $wpdb->get_row($sql, $output);
}

function db_get_var_secure($sql, $x = 0, $y = 0, $label = 'SQL Query') {
    global $wpdb;
    track_sql_statement($label, $sql);
    return $wpdb->get_var($sql, $x, $y);
}

function db_get_col_secure($sql, $x = 0, $label = 'SQL Query') {
    global $wpdb;
    track_sql_statement($label, $sql);
    return $wpdb->get_col($sql, $x);
}

function db_query_secure($sql, $label = 'SQL Query') {
    global $wpdb;
    track_sql_statement($label, $sql);
    return $wpdb->query($sql);
}

function get_primary_key($table) {
    $res = db_get_results_secure("SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY'", ARRAY_A, 'Primary key lookup');
    return $res ? $res[0]['Column_name'] : null;
}

function get_table_columns($table) {
    return db_get_results_secure("DESCRIBE `$table`", ARRAY_A, 'Table structure lookup');
}

$sql_trace_entries = [];
$tables = db_get_col_secure("SHOW TABLES", 0, 'Table list lookup');

$db_name = defined('DB_NAME') ? DB_NAME : '';
$db_host = defined('DB_HOST') ? DB_HOST : '';
$db_user = defined('DB_USER') ? DB_USER : '';
$db_password = defined('DB_PASSWORD') ? DB_PASSWORD : '';
$db_prefix = isset($table_prefix) && $table_prefix !== '' ? $table_prefix : $wpdb->prefix;

$post_target = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH);
if (!is_string($post_target) || $post_target === '') {
    $post_target = isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '';
}

$request_method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
$requested_where = request_array_value('where', []);
$page = max(1, intval(request_value('p', 1)));
$order_by = request_value('order_by');
$order_dir = strtoupper((string) request_value('order_dir', 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
$action = request_value('action');
$current_table = request_value('table');

if ($action === null || $action === '') {
    $action = $current_table ? 'browse' : 'tables';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_browse'])) {
    $action = 'browse';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_filters'])) {
    $action = 'browse';
    $requested_where = [];
    $page = 1;
    $order_by = null;
    $order_dir = 'ASC';
}

$message = '';
$error = '';
$sql_input = '';
$sql_result = null;
$sql_error = null;
$sql_executed = false;
$sql_export_payload = null;

$where_conditions = [];
$columns = [];
$indexes = [];
$rows = [];
$row = null;
$pk = null;
$total = null;
$column_list = [];
$browse_export_sql = null;
$browse_export_columns = [];

if ($request_method === 'POST' && !empty($_POST['data']) && !empty($_POST['iv'])) {
    $decrypted = decrypt_sql_payload($_POST['data'], $_POST['iv']);
    if ($decrypted === false) {
        $error = 'AES decrypt failed.';
    } else {
        $sql_input = '';
        $sql_executed = true;
        $submitted_sql = trim($decrypted);
        $sql_export_payload = encrypt_sql_text($submitted_sql);

        try {
            $result = db_get_results_secure($submitted_sql, ARRAY_A, 'Console execution');
            if ($wpdb->last_error) {
                $sql_error = redact_sql_error_message($wpdb->last_error);
            } else {
                $sql_result = $result;
            }
        } catch (Exception $e) {
            $sql_error = redact_sql_error_message($e->getMessage());
        }

        $action = 'sql';
    }
}

if ($action === 'edit' && $current_table && request_value('pk') !== null && request_value('pk_value') !== null) {
    $pk = request_value('pk');
    $pk_value = request_value('pk_value');
    $columns = get_table_columns($current_table);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
        $set = [];
        $vals = [];

        foreach ($columns as $col) {
            $field = $col['Field'];
            if ($field === $pk) {
                continue;
            }
            if (isset($_POST[$field])) {
                $set[] = "`$field` = %s";
                $vals[] = $_POST[$field];
            }
        }

        $vals[] = $pk_value;
        $sql = $wpdb->prepare(
            "UPDATE `$current_table` SET " . implode(',', $set) . " WHERE `$pk` = %s",
            $vals
        );

        if (db_query_secure($sql, 'Row update') !== false) {
            $message = 'Row updated.';
            $action = 'browse';
        } else {
            $error = redact_sql_error_message($wpdb->last_error);
        }
    }

    $row = db_get_row_secure(
        $wpdb->prepare("SELECT * FROM `$current_table` WHERE `$pk` = %s", $pk_value),
        ARRAY_A,
        'Edit form row lookup'
    );

    if (!$row) {
        $error = 'Row not found.';
        $action = 'browse';
    }
} elseif ($action === 'delete' && $current_table && request_value('pk') !== null && request_value('pk_value') !== null) {
    $pk = request_value('pk');
    $pk_value = request_value('pk_value');

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm'])) {
        $sql = $wpdb->prepare("DELETE FROM `$current_table` WHERE `$pk` = %s", $pk_value);
        if (db_query_secure($sql, 'Row delete') !== false) {
            $message = 'Row deleted.';
            $action = 'browse';
        } else {
            $error = redact_sql_error_message($wpdb->last_error);
        }
    } else {
        $row = db_get_row_secure(
            $wpdb->prepare("SELECT * FROM `$current_table` WHERE `$pk` = %s", $pk_value),
            ARRAY_A,
            'Delete confirmation row lookup'
        );
        if (!$row) {
            $error = 'Row not found.';
            $action = 'browse';
        }
    }
} elseif ($action === 'insert' && $current_table) {
    $columns = get_table_columns($current_table);
    $pk = get_primary_key($current_table);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['insert'])) {
        $fields = [];
        $placeholders = [];
        $vals = [];

        foreach ($columns as $col) {
            $field = $col['Field'];
            if ($field === $pk && $col['Extra'] === 'auto_increment') {
                continue;
            }
            if (isset($_POST[$field])) {
                $fields[] = "`$field`";
                $placeholders[] = '%s';
                $vals[] = $_POST[$field];
            }
        }

        $sql = $wpdb->prepare(
            "INSERT INTO `$current_table` (" . implode(',', $fields) . ") VALUES (" . implode(',', $placeholders) . ")",
            $vals
        );

        if (db_query_secure($sql, 'Row insert') !== false) {
            $message = 'New row inserted.';
            $action = 'browse';
        } else {
            $error = redact_sql_error_message($wpdb->last_error);
        }
    }
} elseif ($action === 'structure' && $current_table) {
    $columns = get_table_columns($current_table);
    $indexes = db_get_results_secure("SHOW INDEX FROM `$current_table`", ARRAY_A, 'Index lookup');
} elseif ($action === 'browse' && $current_table) {
    $all_columns = get_table_columns($current_table);
    $column_list = array_column($all_columns, 'Field');

    if (!empty($requested_where)) {
        foreach ($requested_where as $cond) {
            if (!empty($cond['col']) && !empty($cond['op'])) {
                $col = $cond['col'];
                $op = $cond['op'];
                $val = isset($cond['val']) ? $cond['val'] : '';

                if (in_array($op, ['IS NULL', 'IS NOT NULL'], true)) {
                    $where_conditions[] = ['col' => $col, 'op' => $op, 'val' => null];
                } else {
                    $where_conditions[] = ['col' => $col, 'op' => $op, 'val' => $val];
                }
            }
        }
    }

    $per_page = 20;
    $offset = ($page - 1) * $per_page;

    $where_clause = '';
    $params = [];

    if (!empty($where_conditions)) {
        $wheres = [];

        foreach ($where_conditions as $cond) {
            $col = $cond['col'];
            $op = $cond['op'];
            $val = $cond['val'];

            switch ($op) {
                case '=':
                    $wheres[] = "`$col` = %s";
                    $params[] = $val;
                    break;
                case '>':
                    $wheres[] = "`$col` > %s";
                    $params[] = $val;
                    break;
                case '<':
                    $wheres[] = "`$col` < %s";
                    $params[] = $val;
                    break;
                case '>=':
                    $wheres[] = "`$col` >= %s";
                    $params[] = $val;
                    break;
                case '<=':
                    $wheres[] = "`$col` <= %s";
                    $params[] = $val;
                    break;
                case '!=':
                    $wheres[] = "`$col` != %s";
                    $params[] = $val;
                    break;
                case 'LIKE':
                    $wheres[] = "`$col` LIKE %s";
                    $params[] = '%' . $wpdb->esc_like($val) . '%';
                    break;
                case 'NOT LIKE':
                    $wheres[] = "`$col` NOT LIKE %s";
                    $params[] = '%' . $wpdb->esc_like($val) . '%';
                    break;
                case 'IN':
                    $vals = array_map('trim', explode(',', $val));
                    $placeholders = implode(',', array_fill(0, count($vals), '%s'));
                    $wheres[] = "`$col` IN ($placeholders)";
                    $params = array_merge($params, $vals);
                    break;
                case 'IS NULL':
                    $wheres[] = "`$col` IS NULL";
                    break;
                case 'IS NOT NULL':
                    $wheres[] = "`$col` IS NOT NULL";
                    break;
            }
        }

        if (!empty($wheres)) {
            $where_clause = ' WHERE ' . implode(' AND ', $wheres);
        }
    }

    $count_sql = "SELECT COUNT(*) FROM `$current_table`$where_clause";
    $total = empty($params)
        ? db_get_var_secure($count_sql, 0, 0, 'Browse row count')
        : db_get_var_secure($wpdb->prepare($count_sql, $params), 0, 0, 'Browse row count');

    $order_sql = '';
    if ($order_by && in_array($order_by, $column_list, true)) {
        $order_sql = " ORDER BY `$order_by` $order_dir";
    }

    $browse_export_sql = "SELECT * FROM `$current_table`$where_clause$order_sql";
    if (!empty($params)) {
        $browse_export_sql = $wpdb->prepare($browse_export_sql, $params);
    }

    $browse_export_columns = $column_list;
    $sql = $browse_export_sql . " LIMIT $per_page OFFSET $offset";
    $rows = db_get_results_secure($sql, ARRAY_A, 'Browse row fetch');

    $pk = get_primary_key($current_table);
}

if ($request_method === 'POST' && isset($_POST['export_scope'], $_POST['export_format'])) {
    $export_scope = (string) $_POST['export_scope'];
    $export_format = normalize_export_format($_POST['export_format']);

    if ($export_scope === 'browse') {
        if (!$current_table || $browse_export_sql === null) {
            $error = 'Nothing to export for the current table view.';
        } else {
            $export_rows = db_get_results_secure($browse_export_sql, ARRAY_A, 'Browse export');

            if ($wpdb->last_error) {
                $error = redact_sql_error_message($wpdb->last_error);
            } else {
                $base_name = sanitize_export_filename_segment($db_name, 'database')
                    . '_' . sanitize_export_filename_segment($current_table, 'result')
                    . '.' . $export_format;

                download_export_file(
                    $export_format,
                    $base_name,
                    is_array($export_rows) ? $export_rows : [],
                    $browse_export_columns,
                    $current_table,
                    false
                );
            }
        }
    } elseif ($export_scope === 'sql') {
        $decrypted_export_sql = decrypt_sql_payload(request_value('export_data', ''), request_value('export_iv', ''));
        $sql_executed = true;
        $action = 'sql';

        if ($decrypted_export_sql === false) {
            $sql_error = 'Unable to restore the SQL query for export.';
        } else {
            $decrypted_export_sql = trim($decrypted_export_sql);
            $sql_export_payload = encrypt_sql_text($decrypted_export_sql);

            try {
                $export_rows = db_get_results_secure($decrypted_export_sql, ARRAY_A, 'Console export');

                if ($wpdb->last_error) {
                    $sql_error = redact_sql_error_message($wpdb->last_error);
                } elseif (!is_array($export_rows)) {
                    $sql_error = 'Only SQL queries that return rows can be exported.';
                } else {
                    $export_columns = !empty($export_rows) ? array_keys($export_rows[0]) : [];
                    $base_name = sanitize_export_filename_segment($db_name, 'database')
                        . '_sql_result.' . $export_format;

                    download_export_file(
                        $export_format,
                        $base_name,
                        $export_rows,
                        $export_columns,
                        'sql_result_export',
                        true
                    );
                }
            } catch (Exception $e) {
                $sql_error = redact_sql_error_message($e->getMessage());
            }
        }
    }
}

$hero_title = $current_table ? $current_table : $db_name;
$hero_note = 'Browse tables, inspect structure, and work with data through one calmer admin workspace.';

if ($action === 'browse' && $current_table) {
    $hero_note = 'Inspect rows, stack filters, sort columns, and move into edits without losing your place.';
} elseif ($action === 'structure' && $current_table) {
    $hero_note = 'Review fields, defaults, indexes, and metadata with a cleaner scan path.';
} elseif ($action === 'edit' && $current_table) {
    $hero_note = 'Update a single record with better spacing and a steadier form layout.';
} elseif ($action === 'insert' && $current_table) {
    $hero_note = 'Add a new row with a more focused input layout for larger schemas.';
} elseif ($action === 'delete' && $current_table) {
    $hero_note = 'Double-check record details before making destructive changes.';
} elseif ($action === 'sql') {
    $hero_note = 'Run encrypted SQL in a console that is easier to read and less visually noisy.';
}

$hero_metrics = [
    ['label' => 'Database', 'value' => display_config_value($db_name)],
    ['label' => 'Mode', 'value' => ucfirst($action)],
    ['label' => 'Tables', 'value' => number_format(count($tables))],
];

$connection_details = [
    ['label' => 'Database Name', 'value' => display_config_value($db_name)],
    ['label' => 'Server Address', 'value' => display_config_value($db_host)],
    ['label' => 'Username', 'value' => display_config_value($db_user)],
    ['label' => 'Password', 'value' => display_config_value($db_password)],
    ['label' => 'Table Prefix', 'value' => display_config_value($db_prefix)],
];

if ($current_table) {
    $hero_metrics[] = ['label' => 'Table', 'value' => $current_table];
}

if ($action === 'browse' && $total !== null) {
    $hero_metrics[] = ['label' => 'Rows', 'value' => number_format($total)];
    if (!empty($where_conditions)) {
        $hero_metrics[] = ['label' => 'Filters', 'value' => number_format(count($where_conditions))];
    }
}

$browse_state = [];
if ($current_table) {
    $browse_state = build_browse_post_fields($current_table, $where_conditions, $order_by, $order_dir, $page);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Database Manager - <?= h($db_name) ?></title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
        :root {
            --bg: #f4f7fb;
            --panel: #ffffff;
            --panel-muted: #f8fafc;
            --line: #dbe3ec;
            --line-strong: #c4d0dc;
            --text: #1f2937;
            --muted: #5f6b7a;
            --sidebar-bg: #f7f9fc;
            --primary: #2563eb;
            --primary-strong: #1d4ed8;
            --primary-soft: #e8f0ff;
            --danger: #dc2626;
            --success: #15803d;
            --radius-lg: 18px;
            --radius-md: 14px;
            --radius-sm: 10px;
            --mono: "JetBrains Mono", "Cascadia Code", Consolas, monospace;
            --ui: "IBM Plex Sans", "Segoe UI", Tahoma, sans-serif;
            --display: "Space Grotesk", "IBM Plex Sans", "Segoe UI", Tahoma, sans-serif;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html {
            min-height: 100%;
        }

        body {
            min-height: 100vh;
            padding: 14px;
            font-family: var(--ui);
            color: var(--text);
            background: var(--bg);
        }

        body::before {
            display: none;
        }

        a {
            color: inherit;
        }

        .shell {
            width: 100%;
            max-width: none;
            margin: 0;
            position: relative;
            z-index: 1;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 18px;
            padding: 20px 24px;
            color: var(--text);
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .brand-mark {
            width: 50px;
            height: 50px;
            display: grid;
            place-items: center;
            border-radius: 14px;
            font-family: var(--display);
            font-size: 0.95rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            color: #ffffff;
            background: var(--primary);
        }

        .eyebrow {
            margin-bottom: 6px;
            color: #6b7280;
            font-size: 0.74rem;
            font-weight: 600;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }

        .header h1 {
            margin: 0;
            font-family: var(--display);
            font-size: clamp(1.45rem, 2vw, 2rem);
            letter-spacing: -0.03em;
        }

        .header-subtitle {
            color: var(--muted);
            font-size: 0.95rem;
        }

        .header-nav {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .header-nav form,
        .sidebar li form,
        .row-actions form,
        .pagination form,
        .inline-form {
            margin: 0;
        }

        .nav-button,
        .sidebar-button,
        .row-link,
        .page-button,
        .text-link,
        .sort-button {
            border: 0;
            cursor: pointer;
            font: inherit;
        }

        .nav-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 0 16px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: #ffffff;
            color: var(--muted);
            text-decoration: none;
            font-weight: 600;
            transition: background-color 0.12s ease, color 0.12s ease, border-color 0.12s ease;
        }

        .nav-button:hover,
        .nav-button.is-current {
            background: var(--primary-soft);
            border-color: #bfd3ff;
            color: var(--primary);
        }

        .workspace {
            display: grid;
            grid-template-columns: minmax(220px, var(--sidebar-width, 300px)) 10px minmax(0, 1fr);
            min-height: calc(100vh - 114px);
            margin-top: 14px;
            overflow: visible;
            border: 1px solid var(--line);
            border-radius: 20px;
            background: var(--panel);
        }

        .sidebar {
            overflow: visible;
            min-width: 0;
            padding: 18px 14px 18px 18px;
            color: var(--text);
            background: var(--sidebar-bg);
        }

        .sidebar-resizer {
            position: relative;
            cursor: col-resize;
            background: #eef3f8;
            border-left: 1px solid var(--line);
            border-right: 1px solid var(--line);
            user-select: none;
        }

        .sidebar-resizer::before {
            content: "";
            position: absolute;
            top: 50%;
            left: 50%;
            width: 4px;
            height: 52px;
            border-radius: 999px;
            background: #c7d3df;
            transform: translate(-50%, -50%);
            transition: background-color 0.12s ease;
        }

        .sidebar-resizer:hover::before,
        .sidebar-resizer.is-dragging::before {
            background: var(--primary);
        }

        .sidebar-card {
            margin-bottom: 18px;
            padding: 18px;
            border-radius: 16px;
            border: 1px solid var(--line);
            background: #ffffff;
        }

        .sidebar-label {
            color: #6b7280;
            font-size: 0.74rem;
            font-weight: 600;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }

        .sidebar h3 {
            margin: 8px 0 6px;
            font-family: var(--display);
            font-size: 1.28rem;
            letter-spacing: -0.03em;
        }

        .sidebar-meta {
            color: var(--muted);
            line-height: 1.6;
            font-size: 0.92rem;
        }

        .sidebar ul {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .sidebar-button {
            display: block;
            width: 100%;
            padding: 11px 13px;
            border-radius: 10px;
            color: #334155;
            text-decoration: none;
            background: transparent;
            border: 1px solid transparent;
            text-align: left;
            transition: background-color 0.12s ease, border-color 0.12s ease, color 0.12s ease;
        }

        .sidebar-button:hover,
        .sidebar-button.active {
            background: #ffffff;
            border-color: var(--line);
            color: var(--primary);
        }

        .content {
            overflow: visible;
            min-width: 0;
            padding: 20px 22px 24px;
            background: #ffffff;
        }

        .hero {
            display: flex;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 18px;
            padding: 18px 20px;
            border-radius: 16px;
            border: 1px solid var(--line);
            background: var(--panel-muted);
        }

        .hero .eyebrow {
            color: #64748b;
        }

        .hero h2 {
            margin: 0 0 10px;
            font-family: var(--display);
            font-size: clamp(1.85rem, 2.8vw, 2.7rem);
            line-height: 1.05;
            letter-spacing: -0.05em;
        }

        .hero-copy {
            max-width: none;
            color: var(--muted);
            line-height: 1.7;
        }

        .hero-metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 12px;
            min-width: min(420px, 100%);
            align-self: stretch;
        }

        .metric {
            padding: 14px 16px;
            border-radius: 12px;
            border: 1px solid var(--line);
            background: #ffffff;
        }

        .metric-label {
            display: block;
            margin-bottom: 8px;
            color: var(--muted);
            font-size: 0.72rem;
            font-weight: 600;
            letter-spacing: 0.14em;
            text-transform: uppercase;
        }

        .metric strong {
            display: block;
            font-family: var(--mono);
            font-size: 0.98rem;
            color: var(--text);
            word-break: break-word;
        }

        .connection-panel {
            margin-bottom: 18px;
            padding: 18px 20px;
            border-radius: 16px;
            border: 1px solid var(--line);
            background: #ffffff;
        }

        .connection-panel h3 {
            margin: 0 0 8px;
        }

        .connection-panel p {
            margin: 0 0 16px;
        }

        .connection-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
        }

        .connection-item {
            padding: 14px 16px;
            border-radius: 12px;
            border: 1px solid var(--line);
            background: var(--panel-muted);
        }

        .connection-item strong {
            display: block;
            font-family: var(--mono);
            font-size: 0.98rem;
            color: var(--text);
            word-break: break-word;
        }

        h2 {
            margin: 0 0 16px;
            font-family: var(--display);
            font-size: 1.48rem;
            letter-spacing: -0.03em;
        }

        h3 {
            margin: 22px 0 12px;
            font-family: var(--display);
            font-size: 1.06rem;
            letter-spacing: -0.02em;
        }

        p {
            color: var(--muted);
            line-height: 1.7;
        }

        .actions {
            margin-bottom: 18px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }

        .actions a,
        .actions button,
        .danger-btn,
        .remove-condition {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 0 16px;
            border: 1px solid transparent;
            border-radius: 999px;
            background: var(--primary);
            color: #fffaf5;
            text-decoration: none;
            font-family: inherit;
            font-size: 0.94rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.12s ease, border-color 0.12s ease, color 0.12s ease;
        }

        .actions a:hover,
        .actions button:hover,
        .danger-btn:hover,
        .remove-condition:hover {
            background: var(--primary-strong);
        }

        .export-form {
            width: fit-content;
            padding: 12px 14px;
            border: 1px solid var(--line);
            border-radius: 16px;
            background: var(--panel);
        }

        .export-label {
            color: var(--muted);
            font-size: 0.92rem;
            font-weight: 600;
        }

        .export-form select {
            min-width: 120px;
            min-height: 42px;
            padding: 0 14px;
            border: 1px solid var(--line);
            border-radius: 999px;
            background: #ffffff;
            color: var(--text);
            font: inherit;
        }

        .export-form select:focus {
            outline: none;
            border-color: #93c5fd;
        }

        .danger-btn,
        .remove-condition {
            background: var(--danger);
        }

        .danger-btn:hover,
        .remove-condition:hover {
            background: #973321;
        }

        .message,
        .error {
            margin-bottom: 16px;
            padding: 14px 18px;
            border-radius: 12px;
            border: 1px solid transparent;
        }

        .message {
            background: rgba(31, 122, 87, 0.1);
            color: #195842;
            border-color: rgba(31, 122, 87, 0.22);
        }

        .error {
            background: rgba(182, 66, 47, 0.1);
            color: #7f2f21;
            border-color: rgba(182, 66, 47, 0.22);
        }

        .info-bar {
            margin: 0 0 16px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 38px;
            padding: 0 14px;
            border-radius: 999px;
            background: #eef4ff;
            color: #48607c;
            font-size: 0.9rem;
            font-weight: 600;
            border: 1px solid #d9e6ff;
        }

        .sql-inline-meta {
            color: var(--muted);
            font-size: 0.82rem;
        }

        .sql-inline-code {
            display: block;
            width: 100%;
            overflow-wrap: anywhere;
            word-break: break-all;
            font-family: var(--mono);
            font-size: 0.82rem;
            line-height: 1.6;
        }

        .sql-inline-mask {
            display: grid;
            gap: 6px;
        }

        .sql-inline-label {
            display: inline-flex;
            align-items: center;
            width: fit-content;
            min-height: 28px;
            padding: 0 10px;
            border-radius: 999px;
            background: #eef4ff;
            color: #355272;
            font-size: 0.76rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .sql-inline-code {
            color: #315176;
        }

        .condition-builder,
        .edit-form {
            padding: 18px;
            border-radius: 16px;
            border: 1px solid var(--line);
            background: var(--panel);
        }

        .condition-builder {
            margin-bottom: 22px;
        }

        .condition-row {
            display: grid;
            grid-template-columns: minmax(160px, 1.2fr) minmax(140px, 1fr) minmax(220px, 1.6fr) auto;
            gap: 12px;
            align-items: center;
            margin-bottom: 12px;
        }

        .condition-builder .actions {
            margin-top: 14px;
            margin-bottom: 0;
        }

        .remove-condition {
            width: 42px;
            min-width: 42px;
            padding: 0;
        }

        .condition-row select,
        .condition-row input,
        .edit-form input,
        .edit-form textarea,
        #sql_input {
            width: 100%;
            min-height: 48px;
            padding: 12px 14px;
            border: 1px solid var(--line);
            border-radius: var(--radius-sm);
            background: #ffffff;
            color: var(--text);
            font: inherit;
            transition: border-color 0.12s ease, background-color 0.12s ease;
        }

        .condition-row select:focus,
        .condition-row input:focus,
        .edit-form input:focus,
        .edit-form textarea:focus,
        #sql_input:focus {
            outline: none;
            border-color: #93c5fd;
            background: #ffffff;
        }

        .condition-row input:disabled {
            background: #f1f5f9;
            color: #94a3b8;
            cursor: not-allowed;
        }

        .edit-form {
            max-width: 920px;
        }

        .edit-form label {
            display: block;
            margin-bottom: 16px;
            color: var(--muted);
            line-height: 1.55;
        }

        .edit-form strong {
            display: inline-block;
            margin-bottom: 6px;
            color: var(--text);
        }

        .edit-form textarea,
        #sql_input {
            min-height: 140px;
            resize: vertical;
            font-family: var(--mono);
        }

        #sql_input {
            min-height: 220px;
        }

        .table-wrapper {
            overflow-x: auto;
            overflow-y: visible;
            border: 1px solid var(--line);
            border-radius: 16px;
            background: #ffffff;
        }

        table {
            width: max-content;
            min-width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        th,
        td {
            padding: 14px 16px;
            text-align: left;
            vertical-align: top;
            border-bottom: 1px solid var(--line);
            word-break: normal;
            white-space: nowrap;
        }

        td.cell-wrap,
        th.cell-wrap {
            white-space: normal;
            word-break: break-word;
            min-width: 220px;
        }

        thead th {
            position: static;
            background: #f8fafc;
            color: #4e5a64;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }

        .sort-button {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 0;
            color: inherit;
            text-decoration: none;
            background: transparent;
        }

        thead a {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: inherit;
            text-decoration: none;
        }

        thead a:hover,
        .sort-button:hover {
            color: var(--primary-strong);
        }

        tbody tr:hover {
            background: rgba(203, 99, 51, 0.06);
        }

        tbody tr:last-child td,
        tbody tr:last-child th {
            border-bottom: none;
        }

        tbody td a,
        tbody th a {
            color: var(--primary-strong);
            text-decoration: none;
            font-weight: 600;
        }

        tbody td a:hover,
        tbody th a:hover {
            color: var(--primary);
        }

        .row-actions {
            white-space: nowrap;
            min-width: 110px;
        }

        .row-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 32px;
            padding: 0 10px;
            margin: 0 8px 8px 0;
            border-radius: 999px;
            background: var(--primary-soft);
            color: var(--primary);
        }

        .row-link:hover {
            background: var(--primary);
            color: #fffaf5;
        }

        .pagination {
            margin-top: 20px;
            display: flex;
            justify-content: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .pagination span,
        .page-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 42px;
            height: 42px;
            padding: 0 12px;
            border: 1px solid var(--line);
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.88);
            color: var(--text);
            text-decoration: none;
        }

        .page-button:hover {
            border-color: rgba(203, 99, 51, 0.34);
            color: var(--primary-strong);
        }

        .pagination .current {
            background: var(--primary);
            color: #fffaf5;
            border-color: transparent;
        }

        .empty-state {
            padding: 30px 22px;
            border: 1px dashed var(--line-strong);
            border-radius: 16px;
            background: #f8fafc;
            text-align: center;
            color: var(--muted);
        }

        .sort-indicator {
            color: var(--primary);
            font-size: 0.95rem;
            line-height: 1;
        }

        .text-link {
            padding: 0;
            color: var(--primary);
            text-decoration: none;
            background: transparent;
            font-weight: 600;
        }

        .text-link:hover {
            color: var(--primary-strong);
        }

        .section-gap {
            margin-top: 16px;
        }

        @media (max-width: 1120px) {
            .workspace {
                grid-template-columns: 1fr;
            }

            .sidebar {
                max-height: 320px;
            }

            .sidebar-resizer {
                display: none;
            }
        }

        @media (max-width: 860px) {
            body {
                padding: 10px;
            }

            .header,
            .hero {
                flex-direction: column;
                align-items: flex-start;
            }

            .hero-metrics {
                min-width: 0;
                width: 100%;
            }

            .condition-row {
                grid-template-columns: 1fr;
            }

            .remove-condition {
                width: 100%;
            }

            .content {
                padding: 16px;
            }
        }

        @media (max-width: 640px) {
            .header {
                padding: 14px;
            }

            .content {
                padding: 12px;
            }

            th,
            td {
                padding: 10px 12px;
            }
        }
    </style>
</head>
<body>
<div class="shell">
    <div class="header">
        <div class="brand">
            <div class="brand-mark">DB</div>
            <div>
                <div class="eyebrow">WordPress Data Workspace</div>
                <h1><?= h($db_name) ?></h1>
                <div class="header-subtitle">Manage rows, structure, and encrypted SQL from one polished control surface.</div>
            </div>
        </div>
        <div class="header-nav">
            <form method="post" action="<?= h($post_target) ?>" class="inline-form">
                <button type="submit" name="action" value="tables" class="nav-button <?= $action === 'sql' ? '' : 'is-current' ?>">Tables</button>
            </form>
            <form method="post" action="<?= h($post_target) ?>" class="inline-form">
                <button type="submit" name="action" value="sql" class="nav-button <?= $action === 'sql' ? 'is-current' : '' ?>">SQL Console</button>
            </form>
        </div>
    </div>

    <div class="workspace">
        <div class="sidebar">
            <div class="sidebar-card">
                <div class="sidebar-label">Navigator</div>
                <h3>Tables</h3>
                <div class="sidebar-meta"><?= number_format(count($tables)) ?> tables ready to browse.</div>
            </div>
            <ul>
                <?php foreach ($tables as $tbl): ?>
                    <li>
                        <form method="post" action="<?= h($post_target) ?>" class="inline-form">
                            <input type="hidden" name="action" value="browse">
                            <input type="hidden" name="table" value="<?= h($tbl) ?>">
                            <button type="submit" class="sidebar-button <?= $current_table === $tbl ? 'active' : '' ?>"><?= h($tbl) ?></button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="sidebar-resizer" id="sidebarResizer" aria-hidden="true"></div>

        <div class="content">
            <?php if ($message): ?><div class="message"><?= h($message) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>

            <div class="hero">
                <div>
                    <div class="eyebrow">Database Manager</div>
                    <h2><?= h($hero_title) ?></h2>
                    <div class="hero-copy"><?= h($hero_note) ?></div>
                </div>
                <div class="hero-metrics">
                    <?php foreach ($hero_metrics as $metric): ?>
                        <div class="metric">
                            <span class="metric-label"><?= h($metric['label']) ?></span>
                            <strong><?= h($metric['value']) ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="connection-panel">
                <div class="eyebrow">Connection</div>
                <h3>Manual Connection Details</h3>
                <p>Use these saved values when you need to connect from another database client later.</p>
                <div class="connection-grid">
                    <?php foreach ($connection_details as $detail): ?>
                        <div class="connection-item">
                            <span class="metric-label"><?= h($detail['label']) ?></span>
                            <strong><?= h($detail['value']) ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($action === 'tables'): ?>
                <h2>All Tables (<?= count($tables) ?>)</h2>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Table Name</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($tables as $tbl): ?>
                            <tr>
                                <td><?= h($tbl) ?></td>
                                <td class="row-actions">
                                    <form method="post" action="<?= h($post_target) ?>" class="inline-form">
                                        <input type="hidden" name="action" value="browse">
                                        <input type="hidden" name="table" value="<?= h($tbl) ?>">
                                        <button type="submit" class="row-link">Browse</button>
                                    </form>
                                    <form method="post" action="<?= h($post_target) ?>" class="inline-form">
                                        <input type="hidden" name="action" value="structure">
                                        <input type="hidden" name="table" value="<?= h($tbl) ?>">
                                        <button type="submit" class="row-link">Structure</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif ($action === 'structure' && $current_table): ?>
                <h2>Structure of `<?= h($current_table) ?>`</h2>
                <div class="actions">
                    <form method="post" action="<?= h($post_target) ?>" class="inline-form">
                        <?php render_hidden_fields(build_action_post_fields('browse', $current_table)); ?>
                        <button type="submit">Browse</button>
                    </form>
                    <form method="post" action="<?= h($post_target) ?>" class="inline-form">
                        <?php render_hidden_fields(build_action_post_fields('insert', $current_table)); ?>
                        <button type="submit">Insert</button>
                    </form>
                    <form method="post" action="<?= h($post_target) ?>" class="inline-form">
                        <?php render_hidden_fields(build_action_post_fields('tables')); ?>
                        <button type="submit">Tables</button>
                    </form>
                </div>

                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Field</th>
                                <th>Type</th>
                                <th>Null</th>
                                <th>Key</th>
                                <th>Default</th>
                                <th>Extra</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($columns as $col): ?>
                            <tr>
                                <td><?= h($col['Field']) ?></td>
                                <td><?= h($col['Type']) ?></td>
                                <td><?= h($col['Null']) ?></td>
                                <td><?= h($col['Key']) ?></td>
                                <td><?= h($col['Default']) ?></td>
                                <td><?= h($col['Extra']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (!empty($indexes)): ?>
                    <h3>Indexes</h3>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Key name</th>
                                    <th>Column</th>
                                    <th>Unique</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($indexes as $idx): ?>
                                <tr>
                                    <td><?= h($idx['Key_name']) ?></td>
                                    <td><?= h($idx['Column_name']) ?></td>
                                    <td><?= $idx['Non_unique'] == 0 ? 'YES' : '' ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

            <?php elseif ($action === 'browse' && $current_table && isset($rows)): ?>
                <h2>Browse: <?= h($current_table) ?></h2>
                <div class="actions">
                    <form method="post" action="<?= h($post_target) ?>" class="inline-form">
                        <?php render_hidden_fields(build_action_post_fields('structure', $current_table)); ?>
                        <button type="submit">Structure</button>
                    </form>
                    <form method="post" action="<?= h($post_target) ?>" class="inline-form">
                        <?php render_hidden_fields(build_action_post_fields('insert', $current_table, [], $browse_state)); ?>
                        <button type="submit">Insert</button>
                    </form>
                    <form method="post" action="<?= h($post_target) ?>" class="inline-form">
                        <?php render_hidden_fields(build_action_post_fields('tables')); ?>
                        <button type="submit">Tables</button>
                    </form>
                </div>

                <div class="condition-builder">
                    <form method="POST" action="<?= h($post_target) ?>" id="filterForm">
                        <input type="hidden" name="action" value="browse">
                        <input type="hidden" name="table" value="<?= h($current_table) ?>">
                        <div id="conditions-container">
                            <?php if (!empty($where_conditions)): ?>
                                <?php foreach ($where_conditions as $idx => $cond): ?>
                                    <?php
                                    $col = $cond['col'];
                                    $op = $cond['op'];
                                    $val = $cond['val'] ?? '';
                                    ?>
                                    <div class="condition-row">
                                        <select name="where[<?= $idx ?>][col]">
                                            <option value="">-- column --</option>
                                            <?php foreach ($column_list as $c): ?>
                                                <option value="<?= h($c) ?>" <?= $c == $col ? 'selected' : '' ?>><?= h($c) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <select name="where[<?= $idx ?>][op]">
                                            <option value="=" <?= $op == '=' ? 'selected' : '' ?>>=</option>
                                            <option value=">" <?= $op == '>' ? 'selected' : '' ?>>></option>
                                            <option value="<" <?= $op == '<' ? 'selected' : '' ?>><</option>
                                            <option value=">=" <?= $op == '>=' ? 'selected' : '' ?>>>=</option>
                                            <option value="<=" <?= $op == '<=' ? 'selected' : '' ?>><=</option>
                                            <option value="!=" <?= $op == '!=' ? 'selected' : '' ?>>!=</option>
                                            <option value="LIKE" <?= $op == 'LIKE' ? 'selected' : '' ?>>LIKE</option>
                                            <option value="NOT LIKE" <?= $op == 'NOT LIKE' ? 'selected' : '' ?>>NOT LIKE</option>
                                            <option value="IN" <?= $op == 'IN' ? 'selected' : '' ?>>IN (comma list)</option>
                                            <option value="IS NULL" <?= $op == 'IS NULL' ? 'selected' : '' ?>>IS NULL</option>
                                            <option value="IS NOT NULL" <?= $op == 'IS NOT NULL' ? 'selected' : '' ?>>IS NOT NULL</option>
                                        </select>
                                        <input
                                            type="text"
                                            name="where[<?= $idx ?>][val]"
                                            value="<?= h($val) ?>"
                                            placeholder="value"
                                            <?= in_array($op, ['IS NULL', 'IS NOT NULL'], true) ? 'disabled' : '' ?>
                                        >
                                        <button type="button" class="remove-condition">&times;</button>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="condition-row">
                                    <select name="where[0][col]">
                                        <option value="">-- column --</option>
                                        <?php foreach ($column_list as $c): ?>
                                            <option value="<?= h($c) ?>"><?= h($c) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select name="where[0][op]">
                                        <option value="=">=</option>
                                        <option value=">">></option>
                                        <option value="<"><</option>
                                        <option value=">=">>=</option>
                                        <option value="<="><=</option>
                                        <option value="!=">!=</option>
                                        <option value="LIKE">LIKE</option>
                                        <option value="NOT LIKE">NOT LIKE</option>
                                        <option value="IN">IN</option>
                                        <option value="IS NULL">IS NULL</option>
                                        <option value="IS NOT NULL">IS NOT NULL</option>
                                    </select>
                                    <input type="text" name="where[0][val]" placeholder="value">
                                    <button type="button" class="remove-condition">&times;</button>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="actions">
                            <button type="button" id="add-condition">+ Add condition</button>
                            <button type="submit" name="apply_filter" value="1">Apply filters</button>
                            <button type="submit" name="clear_filters" value="1">Clear all</button>
                        </div>
                    </form>
                </div>

                <?php if (!empty($where_conditions)): ?>
                    <div class="info-bar">Filtered <?= number_format($total) ?> rows<?= $total == 1 ? '' : 's' ?> total.</div>
                <?php else: ?>
                    <div class="info-bar">Total <?= number_format($total) ?> rows.</div>
                <?php endif; ?>

                <?php
                render_export_form(
                    $post_target,
                    build_browse_post_fields($current_table, $where_conditions, $order_by, $order_dir, $page),
                    'browse',
                    'Export filtered result'
                );
                ?>

                <?php if (empty($rows)): ?>
                    <div class="empty-state">No rows found for the current selection.</div>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <?php if ($pk): ?><th style="width: 90px;">Actions</th><?php endif; ?>
                                    <?php foreach (array_keys($rows[0]) as $col): ?>
                                        <th>
                                            <form method="post" action="<?= h($post_target) ?>" class="inline-form">
                                                <?php
                                                $sort_fields = build_browse_post_fields(
                                                    $current_table,
                                                    $where_conditions,
                                                    $col,
                                                    ($order_by === $col && $order_dir === 'ASC') ? 'DESC' : 'ASC',
                                                    $page
                                                );
                                                render_hidden_fields($sort_fields);
                                                ?>
                                                <button type="submit" class="sort-button">
                                                <?= h($col) ?>
                                                <?php if ($order_by === $col): ?>
                                                    <span class="sort-indicator"><?= $order_dir === 'ASC' ? '&uarr;' : '&darr;' ?></span>
                                                <?php endif; ?>
                                                </button>
                                            </form>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $data_row): ?>
                                    <tr>
                                        <?php if ($pk): ?>
                                            <td class="row-actions">
                                                <form method="post" action="<?= h($post_target) ?>" class="inline-form">
                                                    <?php render_hidden_fields(build_action_post_fields('edit', $current_table, ['pk' => $pk, 'pk_value' => $data_row[$pk]], $browse_state)); ?>
                                                    <button type="submit" class="row-link">Edit</button>
                                                </form>
                                                <form method="post" action="<?= h($post_target) ?>" class="inline-form" onsubmit="return confirm('Delete this row?')">
                                                    <?php render_hidden_fields(build_action_post_fields('delete', $current_table, ['pk' => $pk, 'pk_value' => $data_row[$pk]], $browse_state)); ?>
                                                    <button type="submit" class="row-link">Del</button>
                                                </form>
                                            </td>
                                        <?php endif; ?>
                                        <?php foreach ($data_row as $val): ?>
                                            <?php
                                            $val_str = (string) $val;
                                            $is_long_value = strlen($val_str) > 120 || strpos($val_str, "\n") !== false;
                                            ?>
                                            <td class="<?= $is_long_value ? 'cell-wrap' : '' ?>"><?= render_masked_value($val) ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="pagination">
                        <?php
                        $total_pages = ceil($total / $per_page);
                        for ($i = 1; $i <= $total_pages; $i++) {
                            if ($i == $page) {
                                echo "<span class='current'>$i</span>";
                            } else {
                                echo '<form method="post" action="' . h($post_target) . '" class="inline-form">';
                                render_hidden_fields(build_browse_post_fields($current_table, $where_conditions, $order_by, $order_dir, $i));
                                echo '<button type="submit" class="page-button">' . intval($i) . '</button>';
                                echo '</form>';
                            }
                        }
                        ?>
                    </div>
                <?php endif; ?>

            <?php elseif ($action === 'edit' && isset($row)): ?>
                <h2>Edit Row: <?= h($current_table) ?></h2>
                <form method="POST" action="<?= h($post_target) ?>" class="edit-form">
                    <?php render_hidden_fields(build_action_post_fields('edit', $current_table, ['pk' => $pk, 'pk_value' => $pk_value], $browse_state)); ?>
                    <?php foreach ($columns as $col): ?>
                        <?php $field = $col['Field']; ?>
                        <label>
                            <strong><?= h($field) ?></strong> (<?= h($col['Type']) ?>):<br>
                            <?php if ($field === $pk): ?>
                                <input type="text" value="<?= h($row[$field]) ?>" disabled>
                                <input type="hidden" name="<?= h($field) ?>" value="<?= h($row[$field]) ?>">
                            <?php elseif (stripos($col['Type'], 'text') !== false): ?>
                                <textarea name="<?= h($field) ?>" rows="4"><?= h($row[$field]) ?></textarea>
                            <?php else: ?>
                                <input type="text" name="<?= h($field) ?>" value="<?= h($row[$field]) ?>">
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                    <div class="actions">
                        <button type="submit" name="save">Save</button>
                        <button type="submit" name="cancel_browse" value="1">Cancel</button>
                    </div>
                </form>

            <?php elseif ($action === 'insert' && $current_table): ?>
                <h2>Insert into <?= h($current_table) ?></h2>
                <form method="POST" action="<?= h($post_target) ?>" class="edit-form">
                    <?php render_hidden_fields(build_action_post_fields('insert', $current_table, [], $browse_state)); ?>
                    <?php $pk = get_primary_key($current_table); ?>
                    <?php foreach (get_table_columns($current_table) as $col): ?>
                        <?php $field = $col['Field']; ?>
                        <?php if ($field === $pk && $col['Extra'] === 'auto_increment') continue; ?>
                        <label>
                            <strong><?= h($field) ?></strong> (<?= h($col['Type']) ?>):<br>
                            <?php if (stripos($col['Type'], 'text') !== false): ?>
                                <textarea name="<?= h($field) ?>" rows="4"></textarea>
                            <?php else: ?>
                                <input type="text" name="<?= h($field) ?>">
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                    <div class="actions">
                        <button type="submit" name="insert">Insert</button>
                        <button type="submit" name="cancel_browse" value="1">Cancel</button>
                    </div>
                </form>

            <?php elseif ($action === 'delete' && isset($row)): ?>
                <h2>Delete Row</h2>
                <p>Are you sure you want to delete this row from `<?= h($current_table) ?>`?</p>
                <div class="table-wrapper section-gap">
                    <table>
                        <thead>
                            <tr>
                                <th>Column</th>
                                <th>Value</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($row as $k => $v): ?>
                            <tr>
                                <th><?= h($k) ?></th>
                                <td><?= render_masked_value($v) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <form method="POST" action="<?= h($post_target) ?>" class="actions section-gap">
                    <?php render_hidden_fields(build_action_post_fields('delete', $current_table, ['pk' => $pk, 'pk_value' => $pk_value], $browse_state)); ?>
                    <button type="submit" name="confirm" class="danger-btn">Confirm Delete</button>
                    <button type="submit" name="cancel_browse" value="1">Cancel</button>
                </form>

            <?php elseif ($action === 'sql'): ?>
                <h2>SQL Console</h2>
                <form method="POST" action="<?= h($post_target) ?>" id="sqlForm" class="edit-form">
                    <textarea id="sql_input" placeholder="SQL text is encrypted in the browser before execution."></textarea>
                    <input type="hidden" name="data" id="data">
                    <input type="hidden" name="iv" id="iv">
                    <div class="actions section-gap">
                        <button type="submit">Execute</button>
                        <button type="button" onclick="document.getElementById('sql_input').value=''">Clear</button>
                    </div>
                </form>

                <?php if ($sql_executed): ?>
                    <h3>Result</h3>
                    <?php if ($sql_error): ?>
                        <div class="error"><?= h($sql_error) ?></div>
                    <?php elseif (is_array($sql_result)): ?>
                        <?php if ($sql_export_payload): ?>
                            <?php
                            render_export_form(
                                $post_target,
                                [
                                    'action' => 'sql',
                                    'export_data' => $sql_export_payload['cipher'],
                                    'export_iv' => $sql_export_payload['iv'],
                                ],
                                'sql',
                                'Export SQL result'
                            );
                            ?>
                        <?php endif; ?>
                        <?php if (!empty($sql_result)): ?>
                            <div class="table-wrapper">
                                <table>
                                    <thead>
                                        <tr><?php foreach (array_keys($sql_result[0]) as $col): ?><th><?= h($col) ?></th><?php endforeach; ?></tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($sql_result as $sql_row): ?>
                                        <tr>
                                            <?php foreach ($sql_row as $val): ?>
                                                <?php
                                                $val_str = (string) $val;
                                                $is_long_value = strlen($val_str) > 120 || strpos($val_str, "\n") !== false;
                                                ?>
                                                <td class="<?= $is_long_value ? 'cell-wrap' : '' ?>"><?= render_masked_value($val) ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                        <div class="info-bar"><?= count($sql_result) ?> rows returned.</div>
                    <?php else: ?>
                        <div class="info-bar">Query OK. <?= intval($wpdb->rows_affected) ?> rows affected.</div>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/crypto-js/4.2.0/crypto-js.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('conditions-container');
    const addBtn = document.getElementById('add-condition');
    const workspace = document.querySelector('.workspace');
    const resizer = document.getElementById('sidebarResizer');

    function clampSidebarWidth(width) {
        const min = 220;
        const max = Math.max(320, window.innerWidth * 0.55);
        return Math.min(Math.max(width, min), max);
    }

    function applySidebarWidth(width) {
        if (!workspace) {
            return;
        }
        workspace.style.setProperty('--sidebar-width', clampSidebarWidth(width) + 'px');
    }

    try {
        const savedWidth = parseInt(window.localStorage.getItem('dbSidebarWidth') || '', 10);
        if (!isNaN(savedWidth)) {
            applySidebarWidth(savedWidth);
        }
    } catch (e) {
    }

    if (workspace && resizer) {
        let dragging = false;

        function updateWidth(clientX) {
            const bounds = workspace.getBoundingClientRect();
            const nextWidth = clientX - bounds.left;
            applySidebarWidth(nextWidth);
            try {
                window.localStorage.setItem('dbSidebarWidth', String(clampSidebarWidth(nextWidth)));
            } catch (e) {
            }
        }

        resizer.addEventListener('mousedown', function(e) {
            dragging = true;
            resizer.classList.add('is-dragging');
            document.body.style.cursor = 'col-resize';
            document.body.style.userSelect = 'none';
            e.preventDefault();
        });

        window.addEventListener('mousemove', function(e) {
            if (!dragging) {
                return;
            }
            updateWidth(e.clientX);
        });

        window.addEventListener('mouseup', function() {
            if (!dragging) {
                return;
            }
            dragging = false;
            resizer.classList.remove('is-dragging');
            document.body.style.cursor = '';
            document.body.style.userSelect = '';
        });
    }

    function toggleValueInput(selectEl, inputEl) {
        if (!selectEl || !inputEl) {
            return;
        }

        const value = selectEl.value;
        if (value === 'IS NULL' || value === 'IS NOT NULL') {
            inputEl.disabled = true;
            inputEl.value = '';
        } else {
            inputEl.disabled = false;
        }
    }

    function attachRemoveEvent(btn) {
        if (!btn) {
            return;
        }

        btn.addEventListener('click', function() {
            const row = this.closest('.condition-row');
            if (row) {
                row.remove();
            }
        });
    }

    function attachOperatorBehavior(row) {
        if (!row) {
            return;
        }

        const select = row.querySelector('select[name$="[op]"]');
        const input = row.querySelector('input[name$="[val]"]');
        if (!select || !input) {
            return;
        }

        toggleValueInput(select, input);
        select.addEventListener('change', function() {
            toggleValueInput(select, input);
        });
    }

    if (addBtn && container) {
        addBtn.addEventListener('click', function() {
            const idx = container.children.length;
            const newRow = document.createElement('div');
            newRow.className = 'condition-row';
            newRow.innerHTML = `
                <select name="where[${idx}][col]">
                    <option value="">-- column --</option>
                    <?php foreach ($column_list as $c): ?>
                    <option value="<?= h($c) ?>"><?= h($c) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="where[${idx}][op]">
                    <option value="=">=</option>
                    <option value=">">></option>
                    <option value="<"><</option>
                    <option value=">=">>=</option>
                    <option value="<="><=</option>
                    <option value="!=">!=</option>
                    <option value="LIKE">LIKE</option>
                    <option value="NOT LIKE">NOT LIKE</option>
                    <option value="IN">IN</option>
                    <option value="IS NULL">IS NULL</option>
                    <option value="IS NOT NULL">IS NOT NULL</option>
                </select>
                <input type="text" name="where[${idx}][val]" placeholder="value">
                <button type="button" class="remove-condition">&times;</button>
            `;
            container.appendChild(newRow);
            attachRemoveEvent(newRow.querySelector('.remove-condition'));
            attachOperatorBehavior(newRow);
        });
    }

    document.querySelectorAll('.condition-row').forEach(function(row) {
        attachRemoveEvent(row.querySelector('.remove-condition'));
        attachOperatorBehavior(row);
    });
});

const AES_SECRET = <?= json_encode(AES_SECRET) ?>;

function md5ToWordArray(str) {
    return CryptoJS.MD5(str);
}

const sqlForm = document.getElementById('sqlForm');
if (sqlForm) {
    sqlForm.addEventListener('submit', function(e) {
        const sql = document.getElementById('sql_input').value.trim();
        if (!sql) {
            e.preventDefault();
            alert('SQL cannot be empty');
            return;
        }

        const key = md5ToWordArray(AES_SECRET);
        const iv = CryptoJS.lib.WordArray.random(16);
        const encrypted = CryptoJS.AES.encrypt(sql, key, {
            iv: iv,
            mode: CryptoJS.mode.CBC,
            padding: CryptoJS.pad.Pkcs7
        });

        document.getElementById('data').value = CryptoJS.enc.Base64.stringify(encrypted.ciphertext);
        document.getElementById('iv').value = CryptoJS.enc.Base64.stringify(iv);
        document.getElementById('sql_input').removeAttribute('name');
    });
}
</script>
</body>
</html>
