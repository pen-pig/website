<?php
/**
 * 工具管理后台（管理员 / 干事）
 * 自动扫描 web/tools/ 目录下的 .html / .php 文件并展示
 * 添加新工具：直接把文件放到 web/tools/ 下即可自动出现
 */
require_once __DIR__ . '/../includes/config.php';
$user = require_role(array('admin', 'officer'));

// ---------------- 上传处理（仅干事 / 管理员） ----------------
$flash = '';
if (isset($_SESSION['flash'])) { $flash = $_SESSION['flash']; unset($_SESSION['flash']); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    check_csrf();
    $file = isset($_POST['file']) ? basename($_POST['file']) : '';
    if ($file === '' || $file === '.' || $file === '..') {
        $_SESSION['flash'] = '删除失败：文件名无效';
    } else {
        $toolsDir = realpath(WEB_DIR . '/tools');
        $target = realpath(WEB_DIR . '/tools/' . $file);
        if ($toolsDir === false || $target === false || strpos($target, $toolsDir . DIRECTORY_SEPARATOR) !== 0) {
            $_SESSION['flash'] = '删除失败：文件路径不合法';
        } elseif (!is_file($target)) {
            $_SESSION['flash'] = '删除失败：文件不存在';
        } elseif (@unlink($target)) {
            $_SESSION['flash'] = '已删除工具：' . $file;
        } else {
            $_SESSION['flash'] = '删除失败：文件被占用或无写入权限';
        }
    }
    redirect('tools.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload') {
    check_csrf();
    $allowed = array('html', 'htm', 'php');
    if (!isset($_FILES['tool_file']) || $_FILES['tool_file']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['flash'] = '上传失败：未选择文件或文件上传出错（错误码 ' . (isset($_FILES['tool_file']) ? intval($_FILES['tool_file']['error']) : '?') . '）';
    } else {
        $f = $_FILES['tool_file'];
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            $_SESSION['flash'] = '上传失败：仅允许 .html / .htm / .php 文件';
        } elseif ($f['size'] > 20 * 1024 * 1024) {
            $_SESSION['flash'] = '上传失败：文件不能超过 20MB';
        } else {
            $dir = WEB_DIR . '/tools';
            if (!is_dir($dir)) @mkdir($dir, 0777, true);
            $base = pathinfo($f['name'], PATHINFO_FILENAME);
            $dest = $dir . '/' . $f['name'];
            $n = 1;
            while (file_exists($dest)) {
                $dest = $dir . '/' . $base . '_' . $n . '.' . $ext;
                $n++;
            }
            if (move_uploaded_file($f['tmp_name'], $dest)) {
                log_action('工具', '上传工具 ' . basename($dest));
                $_SESSION['flash'] = '上传成功：' . basename($dest) . ' 已发布到工具列表';
            } else {
                $_SESSION['flash'] = '上传失败：服务器写入文件时出错';
            }
        }
    }
    redirect('tools.php');
}

$info = get_tools_list();

page_header('工具管理', 'admin');

if ($flash !== '') flash_toast($flash);

echo '<div class="alert alert-info"><strong>使用说明：</strong>上传 <code>.html</code> / <code>.php</code> 工具文件后系统自动扫描发现（iframe 嵌入调用），无需修改任何代码。仅干事/管理员可上传。单文件不超过 20MB，同名文件自动加序号避免覆盖。</div>';

// 上传表单
echo '<div class="card mb-4"><div class="card-header"><strong>上传新工具</strong></div><div class="card-body">';
echo '<form method="post" action="tools.php" enctype="multipart/form-data" class="form-inline">' . csrf_field();
echo '<input type="hidden" name="action" value="upload">';
echo '<div class="input-group"><div class="custom-file" style="max-width:420px"><input type="file" name="tool_file" class="custom-file-input" id="toolFileInput" accept=".html,.htm,.php"><label class="custom-file-label" for="toolFileInput">选择 .html / .php 文件</label></div><div class="input-group-append"><button class="btn btn-primary">上传工具</button></div></div>';
echo '</form></div></div>';

echo '<div class="card"><div class="card-header"><strong>已发现工具（' . $info['count'] . ' 个）</strong></div><div class="card-body p-0">';
if ($info['count'] === 0) {
    echo '<div class="p-3 text-muted small">web/tools/ 目录下暂无工具文件。</div>';
} else {
    echo '<div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>文件名</th><th>类型</th><th>大小</th><th>修改时间</th><th>前台入口</th><th>操作</th></tr></thead><tbody>';
    foreach ($info['tools'] as $t) {
        echo '<tr><td>' . e($t['file']) . '</td><td><span class="badge badge-light">.' . e($t['ext']) . '</span></td>';
        echo '<td>' . round($t['size'] / 1024) . ' KB</td><td class="small text-muted">' . e($t['mtime']) . '</td>';
        echo '<td><a class="btn btn-sm btn-outline-primary" href="../index.php?view=tool&amp;file=' . urlencode($t['file']) . '">打开</a></td>';
        echo '<td><form method="post" action="tools.php" class="d-inline" onsubmit="return confirm(\'确定删除工具 ' . addslashes($t['file']) . ' 吗？删除后不可恢复。\');">' . csrf_field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="file" value="' . e($t['file']) . '"><button class="btn btn-sm btn-outline-danger">删除</button></form></td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</div></div>';

page_footer();
