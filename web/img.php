<?php
/**
 * 图片输出（公开展示用）
 * - 用法：img.php?file=<存储文件名>（默认 type=worship）
 * - type=worship：膜拜对象图片（data/uploads/worship/，游客可看）
 * - type=msg：私信图片（data/img/msg/，消息内容中的图片地址）
 * - 扩展名白名单 + basename 防路径穿越
 * 说明：膜拜对象图片属于对外展示内容，游客可看；与 download.php（内部文件鉴权下载）职责分开。
 */
require_once __DIR__ . '/includes/config.php';

$file = isset($_GET['file']) ? $_GET['file'] : '';
$type = isset($_GET['type']) ? $_GET['type'] : 'worship';
if (!in_array($type, array('worship', 'msg'))) {
    http_response_code(400);
    die('非法类型');
}

// 防路径穿越：只保留 basename
$stored = basename($file);
if ($stored === '' || $stored === '.' || $stored === '..' || strpos($stored, '/') !== false || strpos($stored, '\\') !== false) {
    http_response_code(400);
    die('非法文件名');
}

$ext = strtolower(pathinfo($stored, PATHINFO_EXTENSION));
$mime = array(
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'bmp'  => 'image/bmp'
);
if (!isset($mime[$ext])) {
    http_response_code(400);
    die('非法文件类型');
}

if ($type === 'msg') {
    $path = DATA_DIR . '/img/msg/' . $stored;
} else {
    $path = UPLOAD_DIR . '/worship/' . $stored;
}
if (!file_exists($path)) {
    http_response_code(404);
    die('图片不存在或已被清理');
}

header('Content-Type: ' . $mime[$ext]);
header('Content-Disposition: inline; filename="' . $stored . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
