<?php
/**
 * WooCommerce recent order export tool.
 *
 * Place this file in the WordPress root directory and open it while logged in
 * as an administrator or shop manager.
 *
 * Default:
 *   /woo-last-7-days-orders-export.php
 *
 * Single day:
 *   /woo-last-7-days-orders-export.php?date=2026-01-07
 *   /woo-last-7-days-orders-export.php?date=20260107
 *   /woo-last-7-days-orders-export.php?date=2026107
 *
 * Date range:
 *   /woo-last-7-days-orders-export.php?start=2026-01-01&end=2026-01-07
 *
 * CSV:
 *   /woo-last-7-days-orders-export.php?date=20260107&format=csv
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    $wp_load = __DIR__ . '/wp-load.php';

    if (!is_file($wp_load)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'wp-load.php was not found. Put this file in the WordPress root directory.';
        exit;
    }

    require_once $wp_load;
}

if (!function_exists('wc_get_orders')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'WooCommerce is not active or wc_get_orders() is unavailable.';
    exit;
}


@set_time_limit(300);

$format = isset($_POST['f']) ? strtolower(sanitize_key((string) wp_unslash($_POST['f']))) : 'json';
$format = in_array($format, array('json', 'csv'), true) ? $format : 'json';

$timezone = wp_timezone();
$range = woo_recent_orders_export_range($timezone);
$start = $range['start'];
$end = $range['end'];

$orders = woo_recent_orders_export_collect($start, $end);

if ($format === 'csv') {
    woo_recent_orders_export_csv($orders, $start, $end);
}

woo_recent_orders_export_json($orders, $start, $end, $range['mode']);

/**
 * Resolve the export range from URL parameters.
 *
 * @return array{start: DateTimeImmutable, end: DateTimeImmutable, mode: string}
 */
function woo_recent_orders_export_range(DateTimeZone $timezone): array
{
    $now = new DateTimeImmutable('now', $timezone);
    $date_value = woo_recent_orders_export_param(array('date', 'day', 'd'));

    if ($date_value !== '') {
        $date = woo_recent_orders_export_parse_date_or_die($date_value, $timezone, 'date', 0, 0, 0);

        return array(
            'start' => $date->setTime(0, 0, 0),
            'end' => $date->setTime(23, 59, 59),
            'mode' => 'single_day',
        );
    }

    $start_value = woo_recent_orders_export_param(array('start', 'from', 'start_date'));
    $end_value = woo_recent_orders_export_param(array('end', 'to', 'end_date'));

    if ($start_value !== '' || $end_value !== '') {
        $start = $start_value !== ''
            ? woo_recent_orders_export_parse_date_or_die($start_value, $timezone, 'start', 0, 0, 0)
            : $now->sub(new DateInterval('P7D'));
        $end = $end_value !== ''
            ? woo_recent_orders_export_parse_date_or_die($end_value, $timezone, 'end', 23, 59, 59)
            : $now;

        if ($end->getTimestamp() < $start->getTimestamp()) {
            wp_die('The end date must be greater than or equal to the start date.', 'Invalid date range', array('response' => 400));
        }

        return array(
            'start' => $start,
            'end' => $end,
            'mode' => 'custom_range',
        );
    }

    return array(
        'start' => $now->sub(new DateInterval('P7D')),
        'end' => $now,
        'mode' => 'last_7_days',
    );
}

/**
 * @param array<int, string> $names
 */
function woo_recent_orders_export_param(array $names): string
{
    foreach ($names as $name) {
        if (isset($_POST[$name]) && $_POST[$name] !== '') {
            return trim(sanitize_text_field((string) wp_unslash($_POST[$name])));
        }
    }

    return '';
}

function woo_recent_orders_export_parse_date_or_die(
    string $value,
    DateTimeZone $timezone,
    string $field,
    int $default_hour,
    int $default_minute,
    int $default_second
): DateTimeImmutable {
    $date = woo_recent_orders_export_parse_date($value, $timezone, $default_hour, $default_minute, $default_second);

    if (!$date) {
        wp_die(
            sprintf(
                'Invalid %s value. Supported examples: 2026-01-07, 2026/1/7, 2026.1.7, 20260107, 2026107.',
                esc_html($field)
            ),
            'Invalid date',
            array('response' => 400)
        );
    }

    return $date;
}

function woo_recent_orders_export_parse_date(
    string $value,
    DateTimeZone $timezone,
    int $default_hour,
    int $default_minute,
    int $default_second
) {
    $value = trim($value);
    $digits = preg_replace('/\D+/', '', $value);

    if (
        preg_match(
            '/^(\d{4})\D+(\d{1,2})\D+(\d{1,2})(?:\D+(\d{1,2})(?:\D?(\d{1,2}))?(?:\D?(\d{1,2}))?)?\D*$/',
            $value,
            $matches
        )
    ) {
        return woo_recent_orders_export_make_date(
            (int) $matches[1],
            (int) $matches[2],
            (int) $matches[3],
            woo_recent_orders_export_match_int($matches, 4, $default_hour),
            woo_recent_orders_export_match_int($matches, 5, $default_minute),
            woo_recent_orders_export_match_int($matches, 6, $default_second),
            $timezone
        );
    }

    if ($digits === $value && in_array(strlen($digits), array(6, 7, 8), true)) {
        return woo_recent_orders_export_parse_compact_date($digits, $timezone, $default_hour, $default_minute, $default_second);
    }

    if ($digits === $value) {
        return null;
    }

    try {
        $date = new DateTimeImmutable($value, $timezone);
    } catch (Exception $e) {
        return null;
    }

    if (!preg_match('/\d{1,2}:\d{2}|T\d{1,2}/', $value)) {
        $date = $date->setTime($default_hour, $default_minute, $default_second);
    }

    return $date;
}

/**
 * @param array<int, string> $matches
 */
function woo_recent_orders_export_match_int(array $matches, int $index, int $default): int
{
    return isset($matches[$index]) && $matches[$index] !== '' ? (int) $matches[$index] : $default;
}

function woo_recent_orders_export_parse_compact_date(
    string $digits,
    DateTimeZone $timezone,
    int $default_hour,
    int $default_minute,
    int $default_second
) {
    $length = strlen($digits);
    $candidates = array();

    if ($length === 8) {
        $candidates[] = array(substr($digits, 0, 4), substr($digits, 4, 2), substr($digits, 6, 2));
    } elseif ($length === 7) {
        $candidates[] = array(substr($digits, 0, 4), substr($digits, 4, 1), substr($digits, 5, 2));
        $candidates[] = array(substr($digits, 0, 4), substr($digits, 4, 2), substr($digits, 6, 1));
    } elseif ($length === 6) {
        $candidates[] = array(substr($digits, 0, 4), substr($digits, 4, 1), substr($digits, 5, 1));
    }

    foreach ($candidates as $candidate) {
        $date = woo_recent_orders_export_make_date(
            (int) $candidate[0],
            (int) $candidate[1],
            (int) $candidate[2],
            $default_hour,
            $default_minute,
            $default_second,
            $timezone
        );

        if ($date) {
            return $date;
        }
    }

    return null;
}

function woo_recent_orders_export_make_date(
    int $year,
    int $month,
    int $day,
    int $hour,
    int $minute,
    int $second,
    DateTimeZone $timezone
) {
    if ($year < 1000 || $year > 9999 || !checkdate($month, $day, $year)) {
        return null;
    }

    if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 || $second < 0 || $second > 59) {
        return null;
    }

    return new DateTimeImmutable(
        sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second),
        $timezone
    );
}

/**
 * Collect all WooCommerce orders in the requested date range.
 *
 * @return array<int, array<string, mixed>>
 */
function woo_recent_orders_export_collect(DateTimeImmutable $start, DateTimeImmutable $end): array
{
    $results = array();
    $page = 1;
    $limit = 100;

    do {
        $query = wc_get_orders(
            array(
                'type' => 'shop_order',
                'status' => array_keys(wc_get_order_statuses()),
                'limit' => $limit,
                'page' => $page,
                'paginate' => true,
                'orderby' => 'date',
                'order' => 'DESC',
                'date_created' => $start->getTimestamp() . '...' . $end->getTimestamp(),
            )
        );

        foreach ($query->orders as $order) {
            if ($order instanceof WC_Order) {
                $results[] = woo_recent_orders_export_clean_value(woo_recent_orders_export_format_order($order));
            }
        }

        $page++;
    } while ($page <= (int) $query->max_num_pages);

    return $results;
}

/**
 * Convert a WC_Order object into a portable array.
 *
 * @return array<string, mixed>
 */
function woo_recent_orders_export_format_order(WC_Order $order): array
{
    $line_items = array();

    foreach ($order->get_items('line_item') as $item) {
        $product = $item->get_product();
        $line_items[] = array(
            'item_id' => $item->get_id(),
            'product_id' => $item->get_product_id(),
            'variation_id' => $item->get_variation_id(),
            'sku' => $product ? $product->get_sku() : '',
            'name' => $item->get_name(),
            'quantity' => $item->get_quantity(),
            'subtotal' => $item->get_subtotal(),
            'total' => $item->get_total(),
            'tax_total' => $item->get_total_tax(),
            'meta' => woo_recent_orders_export_item_meta($item),
        );
    }

    $shipping_lines = array();
    foreach ($order->get_items('shipping') as $item) {
        $shipping_lines[] = array(
            'method_id' => $item->get_method_id(),
            'method_title' => $item->get_method_title(),
            'total' => $item->get_total(),
            'tax_total' => $item->get_total_tax(),
        );
    }

    return array(
        'order_id' => $order->get_id(),
        'order_number' => $order->get_order_number(),
        'status' => $order->get_status(),
        'created_at' => woo_recent_orders_export_date($order->get_date_created()),
        'paid_at' => woo_recent_orders_export_date($order->get_date_paid()),
        'completed_at' => woo_recent_orders_export_date($order->get_date_completed()),
        'currency' => $order->get_currency(),
        'total' => $order->get_total(),
        'subtotal' => $order->get_subtotal(),
        'discount_total' => $order->get_discount_total(),
        'shipping_total' => $order->get_shipping_total(),
        'tax_total' => $order->get_total_tax(),
        'payment_method' => $order->get_payment_method(),
        'payment_method_title' => $order->get_payment_method_title(),
        'transaction_id' => $order->get_transaction_id(),
        'customer_id' => $order->get_customer_id(),
        'customer_note' => $order->get_customer_note(),
        'billing' => array(
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
            'full_name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'company' => $order->get_billing_company(),
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
            'address_1' => $order->get_billing_address_1(),
            'address_2' => $order->get_billing_address_2(),
            'city' => $order->get_billing_city(),
            'state' => $order->get_billing_state(),
            'postcode' => $order->get_billing_postcode(),
            'country' => $order->get_billing_country(),
        ),
        'shipping' => array(
            'first_name' => $order->get_shipping_first_name(),
            'last_name' => $order->get_shipping_last_name(),
            'full_name' => trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()),
            'company' => $order->get_shipping_company(),
            'phone' => method_exists($order, 'get_shipping_phone') ? $order->get_shipping_phone() : '',
            'address_1' => $order->get_shipping_address_1(),
            'address_2' => $order->get_shipping_address_2(),
            'city' => $order->get_shipping_city(),
            'state' => $order->get_shipping_state(),
            'postcode' => $order->get_shipping_postcode(),
            'country' => $order->get_shipping_country(),
        ),
        'shipping_lines' => $shipping_lines,
        'items' => $line_items,
    );
}

/**
 * @return array<int, array<string, string>>
 */
function woo_recent_orders_export_item_meta(WC_Order_Item_Product $item): array
{
    $meta = array();

    foreach ($item->get_formatted_meta_data('') as $entry) {
        $meta[] = array(
            'key' => wp_strip_all_tags((string) $entry->display_key),
            'value' => wp_strip_all_tags((string) $entry->display_value),
        );
    }

    return $meta;
}

function woo_recent_orders_export_date(?WC_DateTime $date): string
{
    if (!$date) {
        return '';
    }

    return $date->date_i18n('Y-m-d H:i:s');
}

/**
 * Preserve valid multilingual UTF-8 text while removing bytes/control chars
 * that can break JSON or spreadsheet import.
 *
 * @param mixed $value
 *
 * @return mixed
 */
function woo_recent_orders_export_clean_value($value)
{
    if (is_array($value)) {
        foreach ($value as $key => $child) {
            $value[$key] = woo_recent_orders_export_clean_value($child);
        }

        return $value;
    }

    if (is_string($value)) {
        return woo_recent_orders_export_clean_text($value);
    }

    return $value;
}

function woo_recent_orders_export_clean_text(string $value): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = wp_strip_all_tags($value);
    $value = wp_check_invalid_utf8($value, true);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);

    return $value === null ? '' : $value;
}

/**
 * @param array<int, array<string, mixed>> $orders
 */
function woo_recent_orders_export_json(array $orders, DateTimeImmutable $start, DateTimeImmutable $end, string $mode): void
{
    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');

    $json_flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $json_flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }

    echo wp_json_encode(
        array(
            'generated_at' => wp_date('Y-m-d H:i:s'),
            'range_mode' => $mode,
            'range_start' => $start->format('Y-m-d H:i:s'),
            'range_end' => $end->format('Y-m-d H:i:s'),
            'count' => count($orders),
            'orders' => $orders,
        ),
        $json_flags
    );
    exit;
}

/**
 * @param array<int, array<string, mixed>> $orders
 */
function woo_recent_orders_export_csv(array $orders, DateTimeImmutable $start, DateTimeImmutable $end): void
{
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="woo-orders-' . $start->format('Ymd-His') . '-to-' . $end->format('Ymd-His') . '.csv"');

    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");

    $headers = array(
        'order_id',
        'order_number',
        'status',
        'created_at',
        'paid_at',
        'completed_at',
        'currency',
        'total',
        'subtotal',
        'discount_total',
        'shipping_total',
        'tax_total',
        'payment_method_title',
        'transaction_id',
        'customer_id',
        'billing_name',
        'billing_email',
        'billing_phone',
        'billing_company',
        'billing_address',
        'shipping_name',
        'shipping_phone',
        'shipping_company',
        'shipping_address',
        'shipping_methods',
        'customer_note',
        'items',
    );

    fputcsv($out, $headers);

    foreach ($orders as $order) {
        fputcsv(
            $out,
            woo_recent_orders_export_clean_csv_row(array(
                $order['order_id'],
                $order['order_number'],
                $order['status'],
                $order['created_at'],
                $order['paid_at'],
                $order['completed_at'],
                $order['currency'],
                $order['total'],
                $order['subtotal'],
                $order['discount_total'],
                $order['shipping_total'],
                $order['tax_total'],
                $order['payment_method_title'],
                $order['transaction_id'],
                $order['customer_id'],
                $order['billing']['full_name'],
                $order['billing']['email'],
                $order['billing']['phone'],
                $order['billing']['company'],
                woo_recent_orders_export_address($order['billing']),
                $order['shipping']['full_name'],
                $order['shipping']['phone'],
                $order['shipping']['company'],
                woo_recent_orders_export_address($order['shipping']),
                woo_recent_orders_export_shipping_methods($order['shipping_lines']),
                $order['customer_note'],
                woo_recent_orders_export_items_text($order['items']),
            ))
        );
    }

    fclose($out);
    exit;
}

/**
 * @param array<int, mixed> $row
 *
 * @return array<int, string>
 */
function woo_recent_orders_export_clean_csv_row(array $row): array
{
    foreach ($row as $index => $value) {
        $row[$index] = woo_recent_orders_export_clean_text((string) $value);
    }

    return $row;
}

/**
 * @param array<string, mixed> $address
 */
function woo_recent_orders_export_address(array $address): string
{
    return implode(
        ', ',
        array_filter(
            array(
                $address['address_1'] ?? '',
                $address['address_2'] ?? '',
                $address['city'] ?? '',
                $address['state'] ?? '',
                $address['postcode'] ?? '',
                $address['country'] ?? '',
            ),
            static fn($value): bool => $value !== ''
        )
    );
}

/**
 * @param array<int, array<string, mixed>> $shipping_lines
 */
function woo_recent_orders_export_shipping_methods(array $shipping_lines): string
{
    $methods = array();

    foreach ($shipping_lines as $line) {
        $methods[] = trim((string) $line['method_title'] . ' ' . (string) $line['total']);
    }

    return implode(' | ', array_filter($methods));
}

/**
 * @param array<int, array<string, mixed>> $items
 */
function woo_recent_orders_export_items_text(array $items): string
{
    $lines = array();

    foreach ($items as $item) {
        $lines[] = sprintf(
            '%s x%s SKU:%s total:%s',
            (string) $item['name'],
            (string) $item['quantity'],
            (string) $item['sku'],
            (string) $item['total']
        );
    }

    return implode(' | ', $lines);
}
