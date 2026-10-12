<?php
/**
 * 在线预览出口（浏览器原生预览 PDF / 图片）
 * preview.php?type=materials|homework|notices|drive&file=<存储文件名>[&folder=<网盘子目录>]
 * 鉴权规则与 download.php 完全一致，仅放行 PDF 与常见图片格式，输出 inline 流供 iframe/新标签页内嵌。
 */
require_once __DIR__ . '/includes/config.php';

require_login();
$user = current_user();

$type = isset($_GET['type']) ? $_GET['type'] : '';
$file = isset($_GET['file']) ? $_GET['file'] : '';

$allowedTypes = array('materials', 'homework', 'notices', 'drive');
if (!in_array($type, $allowedTypes)) {
    http_response_code(400);
    die('非法的预览类型');
}

// 防路径穿越：只保留 basename
$stored = basename($file);
if ($stored === '' || $stored === '.' || $stored === '..') {
    http_response_code(400);
    die('非法的文件名');
}

// 放行类型：图片 / PDF / 文本源码 / Office 文档
$ext = strtolower(pathinfo($stored, PATHINFO_EXTENSION));
$mimeMap = array(
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'txt'  => 'text/plain',
    'md'   => 'text/plain',
    'log'  => 'text/plain',
    'cpp'  => 'text/plain',
    'h'    => 'text/plain',
    'hpp'  => 'text/plain',
    'c'    => 'text/plain',
    'cc'   => 'text/plain',
    'java' => 'text/plain',
    'py'   => 'text/plain',
    'js'   => 'text/plain',
    'css'  => 'text/plain',
    'json' => 'text/plain',
    'xml'  => 'text/plain',
    'html' => 'text/plain',
    'htm'  => 'text/plain',
    'sql'  => 'text/plain',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt'  => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'epub' => 'application/epub+zip'
);
if (!isset($mimeMap[$ext])) {
    http_response_code(400);
    die('该文件类型不支持在线预览，请直接下载');
}

// EPUB 电子书：转独立阅读页（服务端 ZipArchive 解包渲染），此处已通过鉴权与路径定位
// 网盘文件：存储于 DRIVE_DIR，支持子目录，用 drive_resolve 做白名单校验
$folder = '';
if ($type === 'drive') {
    $folder = drive_norm_path(isset($_GET['folder']) ? $_GET['folder'] : '');
    if ($folder === null) {
        http_response_code(400);
        die('非法的目录路径');
    }
    $relFile = $folder === '' ? $stored : $folder . '/' . $stored;
    $path = drive_resolve($relFile);
    if ($path === false || !is_file($path)) {
        http_response_code(404);
        die('文件不存在或已被清理');
    }
} else {
    // 作业仅管理员/干事可预览
    if ($type === 'homework' && !in_array($user['role'], array('admin', 'officer'))) {
        http_response_code(403);
        die('无权限预览作业文件');
    }

    $path = UPLOAD_DIR . '/' . $type . '/' . $stored;
    if (!file_exists($path)) {
        http_response_code(404);
        die('文件不存在或已被清理');
    }
}

// 与下载一致的封闭项目拦截：资料文件仅在项目封闭且非管理员/干事时禁止
if ($type === 'materials') {
    $branches = jdb_collection('projects', 'branches');
    foreach ($branches as $br) {
        if (empty($br['projects']) || !is_array($br['projects'])) continue;
        foreach ($br['projects'] as $p) {
            $pClosed = (isset($p['status']) ? $p['status'] : 'open') === 'closed';
            if (!$pClosed) continue;
            if (!empty($p['materials'])) {
                foreach ($p['materials'] as $m) {
                    if (isset($m['stored']) && $m['stored'] === $stored) {
                        if (!in_array($user['role'], array('admin', 'officer'))) {
                            http_response_code(403);
                            die('该项目暂未开放');
                        }
                        break 3;
                    }
                }
            }
        }
    }
}

// EPUB 电子书：转独立阅读页（服务端 ZipArchive 解包渲染）
if ($ext === 'epub') {
    header('Location: epub.php?type=' . urlencode($type) . '&folder=' . urlencode($folder) . '&file=' . urlencode($stored));
    exit;
}

// raw=1：输出原生 inline 流（供预览页 iframe/img 引用，保持鉴权）
if (isset($_GET['raw']) && $_GET['raw'] === '1') {
    header('Content-Type: ' . $mimeMap[$ext]);
    header('Content-Disposition: inline; filename="' . rawurlencode($stored) . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

// 默认：渲染独立预览页面（点击文件旁“预览”跳到本页）
$isImage = in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp'));
$isText = ($mimeMap[$ext] === 'text/plain');
$isOffice = in_array($ext, array('doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'));
$crumbHome = ($type === 'drive') ? '<a href="drive.php">网盘</a>' : '<a href="index.php">首页</a>';
$downloadUrl = ($type === 'drive')
    ? 'api/drive.php?action=download&amp;folder=' . urlencode($folder) . '&amp;file=' . urlencode($stored)
    : '/download.php?type=' . urlencode($type) . '&amp;file=' . urlencode($stored);
page_header('文件预览', $type === 'drive' ? 'drive' : 'projects');
echo '<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">' . $crumbHome . '</li><li class="breadcrumb-item active">文件预览</li></ol></nav>';
echo '<div class="card mb-3"><div class="card-body d-flex align-items-center justify-content-between flex-wrap">';
echo '<div><strong>' . e($stored) . '</strong> <span class="text-muted small ml-2">' . strtoupper($ext) . ' ｜ ' . round(filesize($path) / 1024) . ' KB</span></div>';
echo '<div><a class="btn btn-sm btn-outline-secondary mr-1" href="javascript:history.back()">返回</a>';
echo '<a class="btn btn-sm btn-primary" href="' . $downloadUrl . '">下载</a></div></div></div>';
$src = 'preview.php?type=' . urlencode($type) . '&amp;folder=' . urlencode($folder) . '&amp;file=' . urlencode($stored) . '&amp;raw=1';
if ($isImage) {
    echo '<div class="card"><div class="card-body text-center"><img src="' . $src . '" style="max-width:100%;" alt="' . e($stored) . '"></div></div>';
} elseif ($isText) {
    // 文本 / 源码：内联 iframe 直接显示纯文本（不以 HTML 执行）
    echo '<div class="card"><div class="card-body p-0"><iframe src="' . $src . '" style="width:100%;height:75vh;border:0;background:#fff;"></iframe></div></div>';
} elseif ($isOffice) {
    // Office 文档：微软 Office Online 在线查看器（文件需公网可访问，上限约 10MB）
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    $rawUrl = $scheme . '://' . $host . '/preview.php?type=' . urlencode($type) . '&folder=' . urlencode($folder) . '&file=' . urlencode($stored) . '&raw=1';
    $viewer = 'https://view.officeapps.live.com/op/view.aspx?src=' . urlencode($rawUrl);
    echo '<div class="card mb-3"><div class="card-body py-2 small text-muted">Office 文档通过微软 Office Online 在线查看器预览（需公网可访问，单文件约 10MB 以内）；如需排版保留请直接下载。</div></div>';
    echo '<div class="card"><div class="card-body p-0"><iframe src="' . $viewer . '" style="width:100%;height:75vh;border:0;background:#fff;"></iframe></div></div>';
} else {
    echo '<div class="card"><div class="card-body p-0"><iframe src="' . $src . '" style="width:100%;height:75vh;border:0;background:#fff;"></iframe></div></div>';
}
page_footer();
exit;
