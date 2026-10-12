<?php
/**
 * 本地预览全托管 router（兼容 PHP 5.6）
 * 用法: php -S 127.0.0.1:8080 -t F:/club/website/web router.php
 * 说明: 不依赖 php -S 自身的静态回落分支（5.6 下该分支对部分请求会卡死），
 *       所有请求由本脚本统一分发：PHP 文件 require 执行，静态文件 readfile 输出。
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rawurldecode($uri);
// 敏感目录拦截：data/（含 users.json 密码哈希）与 drive/（网盘文件）禁止经 Web 访问，
// 公网由 Nginx 403 保护，本地由本 router 统一返回 404
if (preg_match('#^/(data|drive)(/|$)#i', $uri)) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    return true;
}
if ($uri === '' || $uri === '/') {
    require __DIR__ . '/index.php';
    return true;
}
$file = __DIR__ . $uri;
if (is_file($file)) {
    if (preg_match('/\.php$/i', $file)) {
        require $file;
        return true;
    }
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mime = array(
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'html' => 'text/html',
        'htm'  => 'text/html',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2'=> 'font/woff2',
        'ttf'  => 'font/ttf',
        'txt'  => 'text/plain',
        'json' => 'application/json'
    );
    header('Content-Type: ' . (isset($mime[$ext]) ? $mime[$ext] : 'application/octet-stream'));
    readfile($file);
    return true;
}
http_response_code(404);
require __DIR__ . '/404.php';
return true;
