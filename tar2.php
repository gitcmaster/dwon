<?php
/**
 * 网站源码一键压缩备份工具（无 7z 版）
 *
 * 兼容性：PHP 5.6+ / 7.x / 8.x 全系列
 *
 * 策略：
 *   - 优先后台 shell：tar + gz（使用 nohup / setsid 脱离终端,nohup不存在也可以）
 *   - 纯 PHP ZipArchive 兜底（当命令执行函数均被禁用时）
 *   - 自适应超时，大站压缩不中断
 */

// ======================== PHP 版本兼容 ========================
if (!defined('SIGKILL')) {
    define('SIGKILL', 9);
}

// ======================== 基础设置 ========================
header('Content-Type: text/html; charset=utf-8');
set_time_limit(0);
ignore_user_abort(true);
$api_action = isset($_GET['action']) ? $_GET['action'] : '';
if ($api_action === 'status' || $api_action === 'backup' || $api_action === 'reset') {
    @ini_set('display_errors', '0');
}

// ======================== 可配置参数 ========================
$MAX_COMPRESSED_MB = 300;      // 压缩包上限（MB）
$MAX_RUNTIME      = 3600;     // 软超时（秒）
$STALL_TIMEOUT   = 60;       // 卡死判定
$POLL_INTERVAL   = 3;        // 前端轮询间隔
$backup_dir      = 'site_backups';  // 备份目录

$INCLUDE_PATTERNS = '*.php,*.html,*.htm,*.js,*.json,*.xml,*.yml,*.yaml,*.ini,*.conf,.env,*.config,*.txt,*.md';
$EXCLUDE_PATTERNS = 'wp-content/uploads/*,wp-content/cache/*,wp-content/upgrade/*,wp-content/backups/*,wp-content/ai1wm-backups/*,wp-content/wflogs/*';

// ======================== 路径初始化 ========================
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) ? 'https://' : 'http://';
$host     = $_SERVER['HTTP_HOST'];
$base_url = $protocol . $host . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/';
$backup_web_base = $protocol . $host . '/' . trim($backup_dir, '/');
$root_path = realpath($_SERVER['DOCUMENT_ROOT']);
if (!$root_path) { $root_path = getcwd(); }
$domain   = explode(':', $host)[0];

$backup_dir_full = rtrim($root_path, '/\\') . '/' . $backup_dir;
if (!is_dir($backup_dir_full)) {
    @mkdir($backup_dir_full, 0755, true);
}
$status_file = $backup_dir_full . '/.backup_status.json';

// ======================== 工具函数 ========================
function getDisabledFunctions() {
    $str = ini_get('disable_functions');
    if (!$str) return array();
    return array_map('trim', explode(',', $str));
}

function appendLogLines($path, $lines) {
    if (empty($path) || empty($lines)) return;
    if (!is_array($lines)) $lines = array($lines);
    @file_put_contents($path, implode("\n", $lines) . "\n", FILE_APPEND);
}

function readStatus($file) {
    if (!file_exists($file)) return array('status' => 'idle', 'message' => '');
    $content = file_get_contents($file);
    if ($content === false) return array('status' => 'idle', 'message' => '');
    $data = json_decode($content, true);
    return ($data && isset($data['status'])) ? $data : array('status' => 'idle', 'message' => '');
}

function writeStatus($file, $status) {
    $status['updated_at'] = date('Y-m-d H:i:s');
    file_put_contents($file, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function isProcessAlive($pid) {
    if (empty($pid) || !is_numeric($pid)) return false;
    $pid = (int)$pid;
    if (function_exists('posix_kill')) return @posix_kill($pid, 0);
    if (file_exists("/proc/{$pid}")) return true;
    $check = trim(@shell_exec("ps -p {$pid} -o pid= 2>/dev/null"));
    if (empty($check)) $check = trim(@shell_exec("/bin/ps -p {$pid} -o pid= 2>/dev/null"));
    return !empty($check);
}

function killProcess($pid) {
    if (empty($pid) || !is_numeric($pid)) return false;
    $pid = (int)$pid;
    if (function_exists('posix_kill')) return @posix_kill($pid, SIGKILL);
    $out = @shell_exec("kill -9 {$pid} >/dev/null 2>&1; echo $?");
    return ($out !== null && trim($out) === '0');
}

function formatSeconds($sec) {
    if ($sec < 60)   return $sec . ' 秒';
    if ($sec < 3600) return floor($sec / 60) . ' 分 ' . ($sec % 60) . ' 秒';
    return floor($sec / 3600) . ' 小时 ' . floor(($sec % 3600) / 60) . ' 分';
}

define('BACKUP_STARTUP_GRACE_SEC', 12);

function cleanupWrapper($path, $pid_path = '') {
    if (!empty($path) && file_exists($path)) @unlink($path);
    if (!empty($pid_path) && file_exists($pid_path)) @unlink($pid_path);
}

function resolveMonitorPid($st) {
    if (!empty($st['pid_path']) && is_readable($st['pid_path'])) {
        $from_file = trim((string)@file_get_contents($st['pid_path']));
        if ($from_file !== '' && ctype_digit($from_file)) return (int)$from_file;
    }
    return isset($st['pid']) && $st['pid'] !== '' && $st['pid'] !== null ? (int)$st['pid'] : null;
}

function finalizeBackupSuccess($status_file, &$st, $bp, $elapsed, $wp, $pid_path = '') {
    clearstatcache(true, $bp);
    $actual_size = filesize($bp);
    $st['status']      = 'done';
    $st['actual_size'] = round($actual_size / 1024 / 1024, 2);
    $st['elapsed']     = $elapsed;
    $st['message']     = '压缩完成，耗时约 ' . formatSeconds($elapsed) . '，文件大小 ' . $st['actual_size'] . ' MB';
    writeStatus($status_file, $st);
    cleanupWrapper($wp, $pid_path);
}

function finalizeBackupUnexpectedExit($status_file, &$st, $elapsed, $wp, $log_path, $pid_path, $fail_marker) {
    $extra = '';
    if (!empty($fail_marker) && file_exists($fail_marker)) {
        $code = trim((string)@file_get_contents($fail_marker));
        if ($code !== '') $extra .= ' (exit code ' . $code . ')';
    }
    if (!empty($log_path) && is_readable($log_path)) {
        $tail = readLogTail($log_path, 12);
        if ($tail !== '') $st['log_tail'] = $tail;
    }
    $st['status']  = 'error';
    $st['elapsed'] = $elapsed;
    $st['message'] = '备份进程已退出但未生成备份文件' . $extra . '。请查看日志，检查 tar/find 可用性及目录权限。';
    writeStatus($status_file, $st);
    cleanupWrapper($wp, $pid_path);
}

function requestStop($stop_marker, $reason) {
    if (empty($stop_marker)) return;
    @file_put_contents($stop_marker, $reason . ' @ ' . date('Y-m-d H:i:s'));
}

function readLogTail($path, $lines) {
    if (empty($path) || !file_exists($path) || !is_readable($path)) return '';
    $content = @file_get_contents($path);
    if ($content === false || $content === '') return '';
    $arr = preg_split("/\r\n|\n|\r/", $content);
    if (!$arr) return '';
    $lines = max(1, (int)$lines);
    $tail = array_slice($arr, -$lines);
    return trim(implode("\n", $tail));
}

function csvPatternsToArray($patterns_csv) {
    $result = array();
    $patterns = explode(',', (string)$patterns_csv);
    foreach ($patterns as $p) {
        $p = trim($p);
        if ($p !== '') $result[] = $p;
    }
    return $result;
}

function normalizeRelativePath($path) {
    $path = str_replace('\\', '/', trim((string)$path));
    while (strpos($path, './') === 0) $path = substr($path, 2);
    while (substr($path, 0, 1) === '/') $path = substr($path, 1);
    return $path;
}

function matchSinglePattern($value, $pattern) {
    if (function_exists('fnmatch')) return @fnmatch($pattern, $value);
    $regex = '/^' . str_replace(array('\*', '\?'), array('.*', '.'), preg_quote($pattern, '/')) . '$/i';
    return (bool)preg_match($regex, $value);
}

function matchPathByPatterns($path, $patterns_csv) {
    $path = normalizeRelativePath($path);
    foreach (csvPatternsToArray($patterns_csv) as $p) {
        $p = normalizeRelativePath($p);
        if ($p !== '' && matchSinglePattern($path, $p)) return true;
    }
    return false;
}

function resolveBackupArtifact($st) {
    $candidates = array();
    if (!empty($st['backup_file']) || !empty($st['backup_path'])) {
        $candidates[] = array('file' => isset($st['backup_file']) ? $st['backup_file'] : '', 'path' => isset($st['backup_path']) ? $st['backup_path'] : '');
    }
    if (empty($candidates)) return array('file' => '', 'path' => '');
    foreach ($candidates as $c) {
        if (!empty($c['path']) && file_exists($c['path']) && filesize($c['path']) > 0) return $c;
    }
    foreach ($candidates as $c) {
        if (!empty($c['path']) && file_exists($c['path'])) return $c;
    }
    return $candidates[0];
}

function syncBackupArtifactState(&$st) {
    $a = resolveBackupArtifact($st);
    if (!empty($a['file'])) $st['backup_file'] = $a['file'];
    if (!empty($a['path'])) $st['backup_path'] = $a['path'];
    return $a;
}

function decorateStatus($st, $backup_web_base) {
    if (!is_array($st)) return $st;
    $a = syncBackupArtifactState($st);
    if (!isset($st['file']) && !empty($a['file'])) $st['file'] = $a['file'];
    if (!isset($st['download_url']) && !empty($st['file'])) $st['download_url'] = $backup_web_base . '/' . rawurlencode($st['file']);
    if (!empty($st['log_path'])) {
        if (!isset($st['log_download_url'])) $st['log_download_url'] = $backup_web_base . '/' . rawurlencode(basename($st['log_path']));
        if (isset($st['status']) && $st['status'] === 'error' && !isset($st['log_tail'])) {
            $tail = readLogTail($st['log_path'], 20);
            if ($tail !== '') $st['log_tail'] = $tail;
        }
    }
    return $st;
}

function matchByPatterns($filename, $patterns_csv) {
    foreach (csvPatternsToArray($patterns_csv) as $p) {
        if (matchSinglePattern($filename, $p)) return true;
    }
    return false;
}

function compressWithPhpZip($root_path, $backup_dir_name, $backup_path, $patterns_csv, $exclude_patterns_csv, $max_bytes, $log_path) {
    $log = array();
    $log[] = '[' . date('Y-m-d H:i:s') . '] PHP zip mode start';
    if (!class_exists('ZipArchive')) {
        $log[] = 'ZipArchive extension is not available.';
        @file_put_contents($log_path, implode("\n", $log) . "\n");
        return array(false, '服务器未安装 ZipArchive 扩展，无法使用纯 PHP 压缩。', 0);
    }
    if (file_exists($backup_path)) @unlink($backup_path);
    $zip = new ZipArchive();
    $open_ret = $zip->open($backup_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($open_ret !== true) {
        $log[] = 'Zip open failed: ' . $open_ret;
        @file_put_contents($log_path, implode("\n", $log) . "\n");
        return array(false, '创建压缩包失败（ZipArchive::open 返回 ' . $open_ret . '）。', 0);
    }
    $root_real = realpath($root_path);
    $backup_real = realpath(rtrim($root_path, '/\\') . '/' . $backup_dir_name);
    $added = 0;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root_real, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $fileInfo) {
            if (!$fileInfo->isFile()) continue;
            $full = $fileInfo->getPathname();
            if ($backup_real && strpos($full, $backup_real . DIRECTORY_SEPARATOR) === 0) continue;
            $base = $fileInfo->getFilename();
            if (!matchByPatterns($base, $patterns_csv)) continue;
            $rel = substr($full, strlen($root_real) + 1);
            $rel = str_replace('\\', '/', $rel);
            if ($rel === '' || $rel === false) continue;
            if (matchPathByPatterns($rel, $exclude_patterns_csv)) continue;
            if (@$zip->addFile($full, $rel)) $added++;
            if ($added % 20 === 0) {
                clearstatcache(true, $backup_path);
                $size_now = file_exists($backup_path) ? (int)filesize($backup_path) : 0;
                if ($size_now > $max_bytes) {
                    $zip->close();
                    $log[] = 'Size limit exceeded: ' . $size_now . ' bytes';
                    @file_put_contents($log_path, implode("\n", $log) . "\n");
                    return array(false, '压缩文件超过上限，已终止（当前 ' . round($size_now / 1024 / 1024, 2) . ' MB）。', $size_now);
                }
            }
        }
        $zip->close();
    } catch (Exception $e) {
        @file_put_contents($log_path, implode("\n", $log) . "\nException: " . $e->getMessage() . "\n");
        return array(false, '纯 PHP 压缩异常：' . $e->getMessage(), 0);
    }
    clearstatcache(true, $backup_path);
    $final_size = file_exists($backup_path) ? (int)filesize($backup_path) : 0;
    $log[] = 'Added files: ' . $added;
    $log[] = 'Final size: ' . $final_size . ' bytes';
    @file_put_contents($log_path, implode("\n", $log) . "\n");
    if ($final_size <= 0) return array(false, '压缩完成但文件为空，请检查目录权限。', 0);
    if ($final_size > $max_bytes) return array(false, '压缩完成但超过上限（' . round($final_size / 1024 / 1024, 2) . ' MB）。', $final_size);
    return array(true, 'ok', $final_size);
}

function buildFindArgs($patterns) {
    $name_args = array();
    foreach (csvPatternsToArray($patterns) as $p) {
        $name_args[] = '-name "' . addcslashes($p, '"\\$`') . '"';
    }
    return implode(' -o ', $name_args);
}

function buildFindExcludeArgs($patterns_csv, $backup_dir_name) {
    $exclude_args = array();
    $backup_rel = normalizeRelativePath($backup_dir_name);
    if ($backup_rel !== '') {
        $exclude_args[] = '! -path ' . escapeshellarg('./' . $backup_rel);
        $exclude_args[] = '! -path ' . escapeshellarg('./' . $backup_rel . '/*');
    }
    foreach (csvPatternsToArray($patterns_csv) as $p) {
        $p = normalizeRelativePath($p);
        if ($p === '') continue;
        $exclude_args[] = '! -path ' . escapeshellarg('./' . $p);
    }
    return implode(' ', $exclude_args);
}

function canLaunchBackground() {
    $disabled = getDisabledFunctions();
    $funcs = array('shell_exec', 'exec', 'system', 'passthru', 'proc_open', 'popen');
    foreach ($funcs as $f) {
        if (function_exists($f) && !in_array($f, $disabled)) return true;
    }
    return false;
}

function launchBackground($cmd) {
    $disabled = getDisabledFunctions();
    // 优先用 shell_exec 获取 PID
    if (function_exists('shell_exec') && !in_array('shell_exec', $disabled)) {
        $output = @shell_exec($cmd);
        if ($output !== null) {
            $pid = trim($output);
            return is_numeric($pid) && (int)$pid > 0 ? array(true, (int)$pid) : array(true, null);
        }
    }
    // 回退方案
    $fallback_funcs = array('exec', 'system', 'passthru', 'proc_open', 'popen');
    foreach ($fallback_funcs as $func) {
        if (function_exists($func) && !in_array($func, $disabled)) {
            $silent_cmd = $cmd . ' > /dev/null 2>&1';
            if ($func === 'exec') { @exec($silent_cmd, $out, $ret); return array(true, null); }
            if ($func === 'system' || $func === 'passthru') { @system($silent_cmd, $ret); return array(true, null); }
            if ($func === 'proc_open') {
                $descriptors = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
                $process = @proc_open($silent_cmd, $descriptors, $pipes);
                if (is_resource($process)) { proc_close($process); return array(true, null); }
            }
            if ($func === 'popen') {
                $handle = @popen($silent_cmd, 'r');
                if ($handle) { pclose($handle); return array(true, null); }
            }
        }
    }
    return array(false, null);
}

function buildBackgroundLaunchCommand($wrapper_path, $launch_log) {
    $launch_target = 'sh ' . escapeshellarg($wrapper_path) . ' >>' . escapeshellarg($launch_log) . ' 2>&1 < /dev/null';
    $inner = 'if command -v nohup >/dev/null 2>&1; then nohup ' . $launch_target . ' & '
           . 'elif command -v setsid >/dev/null 2>&1; then setsid ' . $launch_target . ' & '
           . 'else ' . $launch_target . ' & '
           . 'fi; echo $!';
    return 'sh -c ' . escapeshellarg($inner);
}

// ======================== API: 状态查询 ========================
if (isset($_GET['action']) && $_GET['action'] === 'status') {
    header('Content-Type: application/json; charset=utf-8');
    $st = decorateStatus(readStatus($status_file), $backup_web_base);

    if ($st['status'] === 'running') {
        $started_at = isset($st['started_at']) ? $st['started_at'] : 'now';
        $started = strtotime($started_at);
        $elapsed = time() - $started;
        $artifact = syncBackupArtifactState($st);
        $bp = isset($artifact['path']) ? $artifact['path'] : '';
        $wp = isset($st['wrapper_path']) ? $st['wrapper_path'] : '';
        $done_marker = isset($st['done_marker']) ? $st['done_marker'] : '';
        $fail_marker = isset($st['fail_marker']) ? $st['fail_marker'] : '';
        $log_path    = isset($st['log_path']) ? $st['log_path'] : '';
        $stop_marker = isset($st['stop_marker']) ? $st['stop_marker'] : '';
        $pid_path    = isset($st['pid_path']) ? $st['pid_path'] : '';
        $mode        = isset($st['mode']) ? $st['mode'] : '';

        if (!isset($st['file']) && !empty($artifact['file'])) {
            $st['file'] = $artifact['file'];
        }

        // 完成/失败标记优先
        if (!empty($done_marker) && file_exists($done_marker)) {
            if (!empty($bp) && file_exists($bp) && filesize($bp) > 0) {
                finalizeBackupSuccess($status_file, $st, $bp, $elapsed, $wp, $pid_path);
            } else {
                $st['status'] = 'error';
                $st['elapsed'] = $elapsed;
                $st['message'] = '压缩已结束，但备份文件不存在或大小为 0。';
                if (!empty($log_path)) {
                    $tail = readLogTail($log_path, 12);
                    if ($tail !== '') $st['log_tail'] = $tail;
                }
                writeStatus($status_file, $st);
                cleanupWrapper($wp, $pid_path);
            }
        }
        elseif (!empty($fail_marker) && file_exists($fail_marker)) {
            $st['status'] = 'error';
            $st['elapsed'] = $elapsed;
            $st['message'] = '压缩失败（脚本返回非 0）。';
            if (!empty($log_path)) {
                $tail = readLogTail($log_path, 12);
                if ($tail !== '') $st['log_tail'] = $tail;
            }
            writeStatus($status_file, $st);
            cleanupWrapper($wp, $pid_path);
        }
        if ($st['status'] !== 'running') {
            $st = decorateStatus($st, $backup_web_base);
            echo json_encode($st, JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 进程存活检测
        $monitor_pid = resolveMonitorPid($st);
        $json_pid = (isset($st['pid']) && $st['pid'] !== null && $st['pid'] !== '') ? (int)$st['pid'] : null;
        if ($monitor_pid !== null) {
            $alive = isProcessAlive($monitor_pid); $kill_id = $monitor_pid;
        } elseif ($json_pid !== null) {
            $alive = isProcessAlive($json_pid); $kill_id = $json_pid;
        } elseif ($mode === 'php') {
            $alive = true; $kill_id = null;
        } elseif ($elapsed < BACKUP_STARTUP_GRACE_SEC && $mode === 'shell') {
            $alive = true; $kill_id = $json_pid;
        } else {
            $alive = false; $kill_id = $json_pid;
        }

        if (!$alive) {
            if (!empty($fail_marker) && file_exists($fail_marker)) {
                $st['status'] = 'error'; $st['elapsed'] = $elapsed;
                $st['message'] = '压缩失败（脚本返回非 0）。';
                writeStatus($status_file, $st);
                cleanupWrapper($wp, $pid_path);
            } elseif (!empty($bp) && file_exists($bp) && filesize($bp) > 0) {
                finalizeBackupSuccess($status_file, $st, $bp, $elapsed, $wp, $pid_path);
            } else {
                finalizeBackupUnexpectedExit($status_file, $st, $elapsed, $wp, $log_path, $pid_path, $fail_marker);
            }
        } else {
            clearstatcache(true, $bp);
            $current_file_size = (!empty($bp) && file_exists($bp)) ? filesize($bp) : 0;
            $current_size_mb = round($current_file_size / 1024 / 1024, 2);

            if ($current_file_size > $MAX_COMPRESSED_MB * 1024 * 1024) {
                requestStop($stop_marker, 'size_limit_exceeded');
                if ($kill_id !== null) killProcess($kill_id);
                $st['status'] = 'error'; $st['elapsed'] = $elapsed;
                $st['actual_size'] = $current_size_mb;
                $st['message'] = '压缩文件已超过 ' . $MAX_COMPRESSED_MB . ' MB 上限（当前 ' . $current_size_mb . ' MB），已终止。';
                writeStatus($status_file, $st);
                cleanupWrapper($wp, $pid_path);
            } else {
                $last_size = isset($st['last_file_size']) ? (int)$st['last_file_size'] : 0;
                $last_active_at = isset($st['last_active_at']) ? $st['last_active_at'] : $started_at;
                $last_active_sec = time() - strtotime($last_active_at);
                $file_growing = ($current_file_size > $last_size);
                if ($file_growing) {
                    $st['last_file_size'] = $current_file_size;
                    $st['last_active_at'] = date('Y-m-d H:i:s');
                    $last_active_sec = 0;
                }
                if ($mode !== 'php' && $last_active_sec >= $STALL_TIMEOUT && $elapsed > 60) {
                    requestStop($stop_marker, 'stall_timeout');
                    if ($kill_id !== null) killProcess($kill_id);
                    $st['status'] = 'error'; $st['elapsed'] = $elapsed;
                    $st['message'] = '压缩卡死：文件连续 ' . formatSeconds($last_active_sec) . ' 未增长，已终止。';
                    writeStatus($status_file, $st);
                    cleanupWrapper($wp, $pid_path);
                } else {
                    $st['elapsed'] = $elapsed;
                    $st['current_size'] = $current_size_mb;
                    $st['file_growing'] = $file_growing;
                    $st['stall_seconds'] = $last_active_sec;
                    $st['over_soft_limit'] = ($elapsed > $MAX_RUNTIME);
                    $st['size_limit_mb'] = $MAX_COMPRESSED_MB;
                    $st['size_percent'] = ($MAX_COMPRESSED_MB > 0) ? min(100, round($current_size_mb / $MAX_COMPRESSED_MB * 100, 1)) : 0;
                    if ($elapsed > $MAX_RUNTIME && $file_growing) {
                        $st['message'] = '压缩已超过软限制，但文件仍在增长中，继续等待。';
                    } elseif ($elapsed > $MAX_RUNTIME && !$file_growing) {
                        $st['message'] = '压缩已超过软限制，文件未增长，若持续 ' . formatSeconds($STALL_TIMEOUT) . ' 将自动终止。';
                    } elseif ($current_size_mb > $MAX_COMPRESSED_MB * 0.8) {
                        $st['message'] = '压缩文件已 ' . $current_size_mb . ' MB，接近上限 ' . $MAX_COMPRESSED_MB . ' MB。';
                    } else {
                        $st['message'] = '压缩任务正在运行';
                    }
                    writeStatus($status_file, $st);
                }
            }
        }
    }

    $st = decorateStatus($st, $backup_web_base);
    echo json_encode($st, JSON_UNESCAPED_UNICODE);
    exit;
}

// ======================== API: 重置状态 ========================
if (isset($_GET['action']) && $_GET['action'] === 'reset') {
    header('Content-Type: application/json; charset=utf-8');
    $st = readStatus($status_file);
    if ($st['status'] === 'running') {
        $wp = isset($st['wrapper_path']) ? $st['wrapper_path'] : '';
        $pid_path = isset($st['pid_path']) ? $st['pid_path'] : '';
        $stop_marker = isset($st['stop_marker']) ? $st['stop_marker'] : '';
        requestStop($stop_marker, 'manual_reset');
        $kill_pid = resolveMonitorPid($st);
        if ($kill_pid === null && isset($st['pid']) && $st['pid'] !== '' && $st['pid'] !== null) $kill_pid = (int)$st['pid'];
        if ($kill_pid !== null) killProcess($kill_pid);
        cleanupWrapper($wp, $pid_path);
    }
    writeStatus($status_file, array('status' => 'idle', 'message' => '状态已手动重置'));
    echo json_encode(array('status' => 'idle', 'message' => '状态已重置'), JSON_UNESCAPED_UNICODE);
    exit;
}

// ======================== API: 发起备份 ========================
if (isset($_GET['action']) && $_GET['action'] === 'backup') {
    header('Content-Type: application/json; charset=utf-8');

    $current = readStatus($status_file);
    if ($current['status'] === 'running') {
        $started_at = isset($current['started_at']) ? $current['started_at'] : 'now';
        $elapsed = time() - strtotime($started_at);
        $artifact = resolveBackupArtifact($current);
        echo json_encode(array(
            'status' => 'running',
            'message' => '已有备份任务正在运行（已运行 ' . formatSeconds($elapsed) . '），请先重置。',
            'file' => isset($artifact['file']) ? $artifact['file'] : ''
        ), JSON_UNESCAPED_UNICODE);
        exit;
    }

    $timestamp = date('Ymd_His');
    $use_shell_mode = canLaunchBackground() && DIRECTORY_SEPARATOR !== '\\';
    $wrapper_path = $backup_dir_full . '/._backup_' . $timestamp . '.sh';
    $done_marker  = $backup_dir_full . '/._backup_' . $timestamp . '.done';
    $fail_marker  = $backup_dir_full . '/._backup_' . $timestamp . '.fail';
    $stop_marker  = $backup_dir_full . '/._backup_' . $timestamp . '.stop';
    $log_path     = $backup_dir_full . '/backup_' . $timestamp . '.log';
    $pid_path     = $backup_dir_full . '/._backup_' . $timestamp . '.pid';
    $launch_log   = $backup_dir_full . '/._backup_' . $timestamp . '.launch.log';
    $backup_file  = $domain . '_' . $timestamp . ($use_shell_mode ? '.tar.gz' : '.zip');
    $backup_path  = $backup_dir_full . '/' . $backup_file;

    writeStatus($status_file, array(
        'status' => 'running',
        'backup_file' => $backup_file,
        'backup_path' => $backup_path,
        'wrapper_path' => $wrapper_path,
        'pid_path' => $pid_path,
        'done_marker' => $done_marker,
        'fail_marker' => $fail_marker,
        'stop_marker' => $stop_marker,
        'log_path' => $log_path,
        'size_limit_mb' => $MAX_COMPRESSED_MB,
        'mode' => $use_shell_mode ? 'shell' : 'php',
        'engine' => $use_shell_mode ? 'tar' : 'php-zip',
        'started_at' => date('Y-m-d H:i:s'),
        'pid' => null,
        'message' => '备份任务已启动。',
    ));

    if ($use_shell_mode) {
        $find_args = buildFindArgs($INCLUDE_PATTERNS);
        $find_exclude_args = buildFindExcludeArgs($EXCLUDE_PATTERNS, $backup_dir);
        $tar_cmd = "find . -type f \\( " . $find_args . " \\) " . $find_exclude_args . " -print0 | tar --null -T - -czf " . escapeshellarg($backup_path);

        $cleanup_targets = escapeshellarg($done_marker) . ' ' . escapeshellarg($fail_marker) . ' ' . escapeshellarg($stop_marker) . ' ' . escapeshellarg($backup_path);
        $wrapper_cmd = "umask 022\n"
            . "trap '' HUP\n"
            . "echo \$\$ > " . escapeshellarg($pid_path) . "\n"
            . "cd " . escapeshellarg($root_path) . " || { echo 1 > " . escapeshellarg($fail_marker) . "; exit 1; }\n"
            . "rm -f " . $cleanup_targets . "\n"
            . "(" . $tar_cmd . ") >" . escapeshellarg($log_path) . " 2>&1 &\n"
            . "task_pid=\$!\n"
            . "while kill -0 \$task_pid 2>/dev/null; do\n"
            . "  if [ -f " . escapeshellarg($stop_marker) . " ]; then\n"
            . "    kill -9 \$task_pid >/dev/null 2>&1 || true\n"
            . "    wait \$task_pid >/dev/null 2>&1 || true\n"
            . "    echo 124 > " . escapeshellarg($fail_marker) . "\n"
            . "    exit 124\n"
            . "  fi\n"
            . "  sleep 1\n"
            . "done\n"
            . "wait \$task_pid\n"
            . "rc=\$?\n"
            . "if [ \$rc -ne 0 ]; then echo \$rc > " . escapeshellarg($fail_marker) . "; exit \$rc; fi\n"
            . "touch " . escapeshellarg($done_marker) . "\n"
            . "exit 0\n";

        file_put_contents($wrapper_path, $wrapper_cmd);
        chmod($wrapper_path, 0755);

        $launch_cmd = buildBackgroundLaunchCommand($wrapper_path, $launch_log);
        $result = launchBackground($launch_cmd);
        $success = $result[0];
        $pid = $result[1];

        if ($success) {
            if ($pid !== null) {
                $st = readStatus($status_file);
                $st['pid'] = $pid;
                writeStatus($status_file, $st);
            }
            echo json_encode(array(
                'status' => 'running',
                'message' => '备份任务已提交到后台。',
                'file' => $backup_file,
                'download_url' => $backup_web_base . '/' . rawurlencode($backup_file),
                'size_limit_mb' => $MAX_COMPRESSED_MB,
                'engine' => 'tar',
            ), JSON_UNESCAPED_UNICODE);
        } else {
            writeStatus($status_file, array(
                'status' => 'error',
                'message' => '后台压缩启动失败，请检查命令执行权限。',
                'log_path' => $log_path,
            ));
            echo json_encode(array('status' => 'error', 'message' => '后台压缩启动失败。'), JSON_UNESCAPED_UNICODE);
            cleanupWrapper($wrapper_path, $pid_path);
        }
    } else {
        // 纯 PHP Zip 兜底
        $max_bytes = $MAX_COMPRESSED_MB * 1024 * 1024;
        $ret = compressWithPhpZip($root_path, $backup_dir, $backup_path, $INCLUDE_PATTERNS, $EXCLUDE_PATTERNS, $max_bytes, $log_path);
        $ok = $ret[0]; $msg = $ret[1]; $final_size = $ret[2];
        $st_now = readStatus($status_file);
        $started_at = isset($st_now['started_at']) ? $st_now['started_at'] : date('Y-m-d H:i:s');
        $elapsed = max(0, time() - strtotime($started_at));
        if ($ok) {
            $st_now['status'] = 'done';
            $st_now['actual_size'] = round($final_size / 1024 / 1024, 2);
            $st_now['elapsed'] = $elapsed;
            $st_now['message'] = '纯 PHP 压缩完成，文件大小 ' . $st_now['actual_size'] . ' MB';
            writeStatus($status_file, $st_now);
            echo json_encode(decorateStatus($st_now, $backup_web_base), JSON_UNESCAPED_UNICODE);
        } else {
            $st_now['status'] = 'error';
            $st_now['actual_size'] = round($final_size / 1024 / 1024, 2);
            $st_now['elapsed'] = $elapsed;
            $st_now['message'] = $msg;
            writeStatus($status_file, $st_now);
            echo json_encode(decorateStatus($st_now, $backup_web_base), JSON_UNESCAPED_UNICODE);
        }
    }
    exit;
}

// ======================== 渲染 HTML 页面 ========================
$initial_status = decorateStatus(readStatus($status_file), $backup_web_base);
function sortFilesByTime($a, $b) { return filemtime($b) - filemtime($a); }
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>网站源码压缩备份工具</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; background: #f0f2f5; color: #1a1a2e; min-height: 100vh; display: flex; flex-direction: column; align-items: center; padding: 40px 16px; }
        .container { max-width: 860px; width: 100%; background: #fff; border-radius: 16px; box-shadow: 0 4px 24px rgba(0,0,0,0.08); padding: 40px; margin-bottom: 40px; }
        h1 { font-size: 24px; font-weight: 700; margin-bottom: 24px; }
        h2 { font-size: 18px; font-weight: 600; margin: 28px 0 14px; }
        .info-grid { display: grid; grid-template-columns: auto 1fr; gap: 8px 16px; background: #f8f9fa; padding: 20px; border-radius: 10px; font-size: 14px; line-height: 1.8; }
        .info-grid .label { font-weight: 600; color: #555; white-space: nowrap; }
        .status-card { padding: 20px 24px; border-radius: 10px; margin: 20px 0; display: none; line-height: 1.7; font-size: 14px; }
        .status-card.visible { display: block; }
        .status-card.running { background: #e8f4fd; border: 1px solid #b3d9f2; color: #0c5460; }
        .status-card.done { background: #d4edda; border: 1px solid #a3d9b1; color: #155724; }
        .status-card.error { background: #f8d7da; border: 1px solid #f0a0a8; color: #721c24; }
        .status-card .title { font-weight: 700; font-size: 15px; margin-bottom: 6px; display: flex; align-items: center; gap: 6px; }
        .status-card .detail { font-size: 13px; opacity: 0.85; }
        .progress-bar { width: 100%; height: 6px; background: #cce5ff; border-radius: 3px; margin-top: 12px; overflow: hidden; }
        .progress-bar .fill { height: 100%; background: #0288d1; border-radius: 3px; width: 0%; transition: width 1s linear; }
        .btn-group { display: flex; gap: 12px; margin: 24px 0; flex-wrap: wrap; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 14px 32px; font-size: 16px; font-weight: 600; border: none; border-radius: 10px; cursor: pointer; text-decoration: none; transition: all 0.2s; }
        .btn-primary { background: #28a745; color: #fff; }
        .btn-primary:hover { background: #218838; }
        .btn-primary:disabled { background: #94d3a0; cursor: not-allowed; opacity: 0.7; }
        .btn-outline { background: transparent; color: #555; border: 1px solid #ddd; }
        .btn-outline:hover { background: #f5f5f5; }
        .btn-sm { padding: 8px 16px; font-size: 13px; border-radius: 8px; }
        .file-list { list-style: none; }
        .file-item { display: flex; align-items: center; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #f0f0f0; font-size: 14px; }
        .file-item:last-child { border-bottom: none; }
        .file-item a { color: #0366d6; text-decoration: none; font-family: monospace; word-break: break-all; }
        .file-item a:hover { text-decoration: underline; }
        .file-item .meta { color: #888; font-size: 12px; white-space: nowrap; margin-left: 12px; }
        .empty-text { color: #999; font-size: 14px; padding: 12px 0; }
        .notes { margin-top: 28px; padding-top: 20px; border-top: 1px solid #eee; font-size: 13px; color: #666; line-height: 1.8; }
        .notes code { background: #f4f4f4; padding: 2px 6px; border-radius: 4px; font-size: 12px; color: #c7254e; }
        @media (max-width: 600px) { .container { padding: 24px 18px; } h1 { font-size: 20px; } .btn { padding: 12px 20px; font-size: 14px; } .file-item { flex-direction: column; align-items: flex-start; gap: 4px; } }
    </style>
</head>
<body>
<div class="container">
    <h1>🚀 网站源码压缩备份工具</h1>
    <div class="info-grid">
        <span class="label">基础网址：</span><span><?php echo htmlspecialchars($base_url); ?></span>
        <span class="label">网站路径：</span><span><?php echo htmlspecialchars($root_path); ?></span>
        <span class="label">域名：</span><span><?php echo htmlspecialchars($domain); ?></span>
        <span class="label">压缩上限：</span><span><?php echo $MAX_COMPRESSED_MB; ?> MB</span>
        <span class="label">软超时：</span><span><?php echo formatSeconds($MAX_RUNTIME); ?></span>
        <span class="label">卡死判定：</span><span><?php echo formatSeconds($STALL_TIMEOUT); ?> 不增长</span>
        <span class="label">PHP 版本：</span><span><?php echo PHP_VERSION; ?></span>
    </div>

    <div id="statusCard" class="status-card"></div>

    <div class="btn-group">
        <button id="btnBackup" class="btn btn-primary" onclick="startBackup()">🚀 开始压缩备份</button>
        <button id="btnReset" class="btn btn-outline btn-sm" onclick="resetStatus()" style="display:none;">🔄 重置状态</button>
    </div>

    <h2>📦 已生成的备份文件</h2>
    <?php
    $show_files = array();
    $entries = @scandir($backup_dir_full);
    if ($entries !== false) {
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $f = $backup_dir_full . '/' . $entry;
            if (!is_file($f)) continue;
            if (strpos($entry, '._backup_') === 0) continue;
            $show_files[] = $f;
        }
    }
    if (!empty($show_files)) {
        usort($show_files, 'sortFilesByTime');
        echo '<ul class="file-list" id="fileList">';
        foreach ($show_files as $f) {
            $name = basename($f);
            $size_mb = round(filesize($f) / 1024 / 1024, 2);
            $mtime = date('Y-m-d H:i', filemtime($f));
            $download_url = $backup_web_base . '/' . rawurlencode($name);
            echo '<li class="file-item"><div><a href="' . htmlspecialchars($download_url) . '">' . htmlspecialchars($name) . '</a></div><span class="meta">' . $size_mb . ' MB · ' . $mtime . '</span></li>';
        }
        echo '</ul>';
    } else {
        echo '<p class="empty-text" id="fileList">备份目录暂无文件。</p>';
    }
    ?>
    <div class="notes">
        <strong>说明：</strong><br>
        • 后台使用 <code>nohup</code> / <code>setsid</code> 防止终端关闭中断<br>
        • 自动排除备份目录本身，避免重复打包<br>
        • 压缩过程中实时监测大小，超过限制自动终止并保留已压缩部分<br>
        • 超过软超时后不立即终止，只要文件还在增长就会继续<br>
        • 文件连续 <?php echo formatSeconds($STALL_TIMEOUT); ?> 不增长才判定为卡死并终止
    </div>
</div>

<script>
(function() {
    var POLL_INTERVAL = <?php echo $POLL_INTERVAL; ?> * 1000;
    var MAX_RUNTIME = <?php echo $MAX_RUNTIME; ?>;
    var STALL_TIMEOUT = <?php echo $STALL_TIMEOUT; ?>;
    var MAX_COMPRESSED_MB = <?php echo $MAX_COMPRESSED_MB; ?>;
    var pollTimer = null;

    var btnBackup = document.getElementById('btnBackup');
    var btnReset  = document.getElementById('btnReset');
    var card      = document.getElementById('statusCard');

    function renderCard(data) {
        if (!data || data.status === 'idle') {
            card.className = 'status-card';
            card.innerHTML = '';
            btnReset.style.display = 'none';
            btnBackup.disabled = false;
            btnBackup.innerHTML = '🚀 开始压缩备份';
            return;
        }
        var html = '', cls = data.status;
        if (cls === 'running') {
            var elapsed = (data.elapsed || 0);
            var stallSec = (data.stall_seconds || 0);
            var growing = data.file_growing;
            var overLimit = data.over_soft_limit;
            var growIcon = growing ? '✅' : '⏸️';
            var growText = growing ? '正在写入' : '暂时停顿';
            var sizeLimitMb = data.size_limit_mb || MAX_COMPRESSED_MB;
            var pct = 0;
            if (data.current_size && sizeLimitMb > 0) {
                pct = Math.min(99, (data.current_size / sizeLimitMb) * 100);
            }
            var sizePercent = data.size_percent || 0;
            var sizeInfo = data.current_size ? '已生成 <strong>' + data.current_size + ' MB</strong> / ' + sizeLimitMb + ' MB（' + sizePercent.toFixed(1) + '%）' : '尚未生成文件';
            var barColor = '#0288d1';
            if (sizePercent > 95) barColor = '#dc3545';
            else if (sizePercent > 80) barColor = '#f0ad4e';
            var warnHtml = '';
            if (sizePercent > 95 && growing) {
                warnHtml = '<div style="background:#f8d7da;color:#721c24;padding:8px 12px;border-radius:6px;margin-top:10px;font-size:12px;">🔴 即将达到上限，超限将自动终止。</div>';
            } else if (sizePercent > 80) {
                warnHtml = '<div style="background:#fff3cd;color:#856404;padding:8px 12px;border-radius:6px;margin-top:10px;font-size:12px;">⏰ 已接近上限。</div>';
            } else if (overLimit && growing) {
                warnHtml = '<div style="background:#fff3cd;color:#856404;padding:8px 12px;border-radius:6px;margin-top:10px;font-size:12px;">⏰ 已超过软限制，但文件仍在增长，继续等待。</div>';
            } else if (overLimit && !growing) {
                var stallPct = Math.min(100, (stallSec / STALL_TIMEOUT) * 100);
                warnHtml = '<div style="background:#f8d7da;color:#721c24;padding:8px 12px;border-radius:6px;margin-top:10px;font-size:12px;">⚠️ 文件已停顿 ' + formatSec(stallSec) + '（' + formatSec(STALL_TIMEOUT) + ' 不增长将终止）<div style="background:rgba(0,0,0,0.1);border-radius:3px;height:4px;margin-top:6px;"><div style="background:#dc3545;height:100%;border-radius:3px;width:' + stallPct + '%;"></div></div></div>';
            }
            html = '<div class="title">⏳ 正在压缩中...</div><div class="detail">文件：<strong>' + escHtml(data.file || '') + '</strong><br>' + sizeInfo + '（' + growIcon + ' ' + growText + '）<br>已运行：' + formatSec(elapsed) + '</div><div class="progress-bar"><div class="fill" style="width:' + pct + '%;background:' + barColor + ';"></div></div>' + warnHtml;
            btnBackup.disabled = true;
            btnBackup.innerHTML = '⏳ 压缩进行中...';
            btnReset.style.display = 'inline-flex';
        } else if (cls === 'done') {
            var doneSize = (data.actual_size || '?');
            var downloadHtml = data.download_url ? '<br>下载：<a href="' + escHtml(data.download_url) + '" target="_blank">' + escHtml(data.download_url) + '</a>' : '';
            html = '<div class="title">✅ 压缩成功</div><div class="detail">文件：<strong>' + escHtml(data.file || '') + '</strong><br>压缩大小：<strong>' + doneSize + ' MB</strong><br>耗时：' + formatSec(data.elapsed || 0) + downloadHtml + '</div>';
            btnBackup.disabled = false;
            btnBackup.innerHTML = '🚀 再次备份';
            btnReset.style.display = 'inline-flex';
            stopPolling();
            refreshFileList();
        } else if (cls === 'error') {
            var logHtml = '';
            if (data.log_download_url) logHtml += '<br>日志：<a href="' + escHtml(data.log_download_url) + '" target="_blank">' + escHtml(data.log_download_url) + '</a>';
            if (data.log_tail) logHtml += '<div style="margin-top:10px;background:#fff5f5;border:1px solid #f1b0b7;border-radius:8px;padding:10px;"><div style="font-weight:600;">日志摘要</div><pre style="white-space:pre-wrap;word-break:break-word;margin:0;font-size:12px;line-height:1.5;">' + escHtml(data.log_tail) + '</pre></div>';
            html = '<div class="title">❌ 压缩失败</div><div class="detail">' + (data.message || '未知错误') + logHtml + '</div>';
            btnBackup.disabled = false;
            btnBackup.innerHTML = '🚀 重新备份';
            btnReset.style.display = 'inline-flex';
            stopPolling();
        }
        card.className = 'status-card visible ' + cls;
        card.innerHTML = html;
    }

    function startPolling() { stopPolling(); pollTimer = setInterval(checkStatus, POLL_INTERVAL); }
    function stopPolling() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }

    function checkStatus() {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', '?action=status&_t=' + Date.now(), true);
        xhr.timeout = 10000;
        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4) return;
            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    renderCard(data);
                    if (data.status === 'done' || data.status === 'error') stopPolling();
                } catch (e) { console.error(e); }
            }
        };
        xhr.send(null);
    }

    window.startBackup = function() {
        btnBackup.disabled = true;
        btnBackup.innerHTML = '⏳ 提交中...';

        function recoverByStatus() {
            var s = new XMLHttpRequest();
            s.open('GET', '?action=status&_t=' + Date.now(), true);
            s.timeout = 8000;
            s.onreadystatechange = function() {
                if (s.readyState !== 4) return;
                if (s.status === 200) {
                    try {
                        var st = JSON.parse(s.responseText);
                        if (st && st.status === 'running') { renderCard(st); startPolling(); return; }
                    } catch (e) {}
                }
                btnBackup.disabled = false;
                btnBackup.innerHTML = '🚀 开始压缩备份';
            };
            s.send(null);
        }

        var xhr = new XMLHttpRequest();
        xhr.open('GET', '?action=backup&_t=' + Date.now(), true);
        xhr.timeout = 30000;
        xhr.ontimeout = recoverByStatus;
        xhr.onerror = recoverByStatus;
        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4) return;
            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    renderCard(data);
                    if (data.status === 'running') startPolling();
                } catch (e) { recoverByStatus(); }
            } else { recoverByStatus(); }
        };
        xhr.send(null);
    };

    window.resetStatus = function() {
        if (!confirm('确定要重置状态吗？如果正在压缩将会终止进程。')) return;
        var xhr = new XMLHttpRequest();
        xhr.open('GET', '?action=reset&_t=' + Date.now(), true);
        xhr.onreadystatechange = function() { if (xhr.readyState === 4) { stopPolling(); renderCard({status:'idle'}); } };
        xhr.send(null);
    };

    function refreshFileList() {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', '?_t=' + Date.now(), true);
        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4 || xhr.status !== 200) return;
            try {
                var parser = new DOMParser();
                var doc = parser.parseFromString(xhr.responseText, 'text/html');
                var newList = doc.getElementById('fileList');
                if (newList) { var old = document.getElementById('fileList'); if (old) old.outerHTML = newList.outerHTML; }
            } catch (e) {}
        };
        xhr.send(null);
    }

    function escHtml(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    function formatSec(s) {
        s = Math.max(0, Math.round(s));
        if (s < 60) return s + ' 秒';
        if (s < 3600) return Math.floor(s / 60) + ' 分 ' + (s % 60) + ' 秒';
        return Math.floor(s / 3600) + ' 小时 ' + Math.floor((s % 3600) / 60) + ' 分';
    }

    var initialData = <?php echo json_encode($initial_status, JSON_UNESCAPED_UNICODE); ?>;
    renderCard(initialData);
    if (initialData && initialData.status === 'running') startPolling();
})();
</script>
</body>
</html>