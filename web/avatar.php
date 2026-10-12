<?php
/**
 * 用户头像输出（登录可见）
 * - 用法：avatar.php?f=<存储文件名>
 * - 严格文件名格式 + 只读 avatars 目录
 */
require_once __DIR__ . '/includes/config.php';
require_login();

$f = basename(isset($_GET['f']) ? $_GET['f'] : '');
if ($f === '' || !preg_match('/^[0-9]{14}_[A-Za-z0-9]+\.(jpg|png|gif|webp)$/', $f)) {
    http_response_code(400);
    die('非法参数');
}

$path = UPLOAD_DIR . '/avatars/' . $f;
if (!is_file($path)) {
    http_response_code(404);
    die('头像不存在');
}

$mime = array('jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp');
$ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
header('Content-Type: ' . $mime[$ext]);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
