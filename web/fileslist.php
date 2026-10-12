<?php
// 1. 禁止直接输出源码、关闭错误泄露
error_reporting(0);
ini_set('display_errors', 0);
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("Content-Security-Policy: default-src 'self';");

// 目录根限制，防止跨目录穿越
$rootPath = __DIR__;
$requestPath = isset($_GET['dir']) ? trim($_GET['dir']) : '';
$realCurrent = realpath($rootPath . DIRECTORY_SEPARATOR . $requestPath);
$realRoot = realpath($rootPath);
if (!$realCurrent || strpos($realCurrent, $realRoot) !== 0) {
    http_response_code(403);
    die("403 禁止访问");
}
$scanDir = $realCurrent;

// 格式化文件大小 兼容低版本PHP
function formatSize($bytes)
{
    if ($bytes < 1024) return $bytes . " B";
    if ($bytes < 1048576) return round($bytes / 1024, 2) . " KB";
    if ($bytes < 1073741824) return round($bytes / 1048576, 2) . " MB";
    return round($bytes / 1073741824, 2) . " GB";
}

// 本地时间
function formatLocalTime($timestamp)
{
    return date("Y-m-d H:i:s", $timestamp);
}

$files = scandir($scanDir);
$list = array();
foreach ($files as $file) {
    // 过滤 . 、.. 、index.php，页面完全不展示
    if ($file === '.' || $file === '..' || $file === '3333index.php' || $file === 'fileslist.php' || $file === 'upload.php' || $file === 'chunks_tmp') continue;
    $fullPath = $scanDir . DIRECTORY_SEPARATOR . $file;
    $isDir = is_dir($fullPath);
    $mTime = filemtime($fullPath);
    $size = $isDir ? "-" : filesize($fullPath);

    $subDir = $requestPath ? $requestPath . "/" . $file : $file;
    $link = "?dir=" . urlencode($subDir);

    // 修复文件URL：目录部分不整体编码，只编码文件名，保留正常/分隔符
    if ($requestPath !== '') {
        $fileUrl = $requestPath . '/' . rawurlencode($file);
    } else {
        $fileUrl = rawurlencode($file);
    }

    $list[] = array(
        'name' => $file,
        'link' => $link,
        'fileurl' => $fileUrl,
        'is_dir' => $isDir,
        'size' => $size === "-" ? "-" : formatSize($size),
        'mtime' => formatLocalTime($mTime)
    );
}

// 文件夹在前排序
usort($list, function ($a, $b) {
    if ($a['is_dir'] && !$b['is_dir']) return -1;
    if (!$a['is_dir'] && $b['is_dir']) return 1;
    return strcmp($a['name'], $b['name']);
});
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>文件目录</title>
    <style>
        * { font-family: Consolas, monospace; font-size: 14px; user-select: none; -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none; }
        table { border-collapse: collapse; width: 100%; margin-top: 10px; }
        td, th { padding: 6px 10px; border: 1px solid #eee; }
        th { background: #f5f5f5; text-align: left; }
        a { color: #0066cc; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .folder { color: #c45200; font-weight: bold; }
    </style>
</head>
<!-- JS 防右键、防复制、防F12查看源码 -->
<body oncontextmenu="return false;" oncopy="return false;" oncut="return false;" onpaste="return false;">
<script>
// 禁用F12、Ctrl+U、Ctrl+S、Ctrl+Shift+I
document.onkeydown = function(e) {
    if (e.keyCode == 123) return false;
    if (e.ctrlKey && e.keyCode == 85) return false;
    if (e.ctrlKey && e.keyCode == 83) return false;
    if (e.ctrlKey && e.shiftKey && e.keyCode == 73) return false;
}
// 拦截调试器
setInterval(function(){
    debugger;
}, 1000);
</script>

<h3>当前目录：<?php echo htmlspecialchars($requestPath ?: "根目录"); ?></h3>
<?php
// 上级目录跳转逻辑
if (!empty($requestPath)) {
    $parent = dirname($requestPath);
    if ($parent === '.' || $parent === '/') {
        $parentUrl = './';
    } else {
        $parentUrl = "?dir=" . urlencode($parent);
    }
    echo '<p><a href="' . $parentUrl . '">← 返回上级目录</a></p>';
}
?>
<table>
    <tr>
        <th>文件名</th>
        <th>大小</th>
        <th>修改时间(本地时间)</th>
    </tr>
    <?php foreach ($list as $item): ?>
    <tr>
        <td>
            <?php if ($item['is_dir']): ?>
                <!-- 文件夹跳转目录列表 -->
                <a class="folder" href="<?php echo $item['link']; ?>"><?php echo htmlspecialchars($item['name']); ?>/</a>
            <?php else: ?>
                <!-- 文件路径斜杠正常，仅文件名编码，不会出现%2F -->
                <a href="<?php echo htmlspecialchars($item['fileurl']); ?>"><?php echo htmlspecialchars($item['name']); ?></a>
            <?php endif; ?>
        </td>
        <td><?php echo $item['size']; ?></td>
        <td><?php echo $item['mtime']; ?></td>
    </tr>
    <?php endforeach; ?>
</table>
</body>
</html>