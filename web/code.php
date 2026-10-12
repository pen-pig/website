<?php
/**
 * 代码/文本文件在线预览（highlight.js 高亮 + 行号）
 * 用法: code.php?type=materials|notices|homework&file=<存储文件名>
 * 权限与 download.php 一致；仅白名单文本扩展名，≤1MB（过长截断显示前一部分）
 */
require_once __DIR__ . '/includes/config.php';
require_login();
$user = current_user();

$type = isset($_GET['type']) ? $_GET['type'] : '';
$file = isset($_GET['file']) ? $_GET['file'] : '';

$allowedTypes = array('materials', 'homework', 'notices');
if (!in_array($type, $allowedTypes)) {
    http_response_code(400);
    die('非法的预览类型');
}

$stored = basename($file);
if ($stored === '' || $stored === '.' || $stored === '..') {
    http_response_code(400);
    die('非法的文件名');
}

if ($type === 'homework' && !in_array($user['role'], array('admin', 'officer'))) {
    http_response_code(403);
    die('无权限查看作业文件');
}

if (!is_viewable_code_ext($stored)) {
    http_response_code(400);
    die('该文件类型不支持在线查看，请下载后打开');
}

$path = UPLOAD_DIR . '/' . $type . '/' . $stored;
if (!file_exists($path)) {
    http_response_code(404);
    die('文件不存在或已被清理');
}

$size = filesize($path);
if ($size > 1048576) {
    http_response_code(400);
    die('文件过大（超过 1MB），不支持在线查看，请下载后打开');
}

$content = file_get_contents($path);
// 去 UTF-8 BOM
$content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
$truncated = false;
$lines = explode("\n", $content);
if (count($lines) > 5000) {
    $lines = array_slice($lines, 0, 5000);
    $truncated = true;
}
$content = implode("\n", $lines);
$lineCount = count($lines);
$lnHtml = implode("\n", range(1, $lineCount));

$ext = strtolower(pathinfo($stored, PATHINFO_EXTENSION));
$langMap = array(
    'c' => 'c', 'cpp' => 'cpp', 'h' => 'cpp', 'hpp' => 'cpp', 'cc' => 'cpp', 'cxx' => 'cpp',
    'py' => 'python', 'js' => 'javascript', 'ts' => 'typescript', 'java' => 'java', 'php' => 'php',
    'md' => 'markdown', 'json' => 'json', 'go' => 'go', 'rs' => 'rust', 'sql' => 'sql',
    'sh' => 'bash', 'bat' => 'dos', 'html' => 'xml', 'htm' => 'xml', 'css' => 'css',
    'txt' => 'plaintext', 'asm' => 'x86asm'
);
$hl = isset($langMap[$ext]) ? $langMap[$ext] : 'plaintext';

page_header('代码预览：' . $stored, '');
echo '<div class="card">';
echo '<div class="card-header d-flex justify-content-between align-items-center flex-wrap">';
echo '<strong>' . e($stored) . '</strong>';
echo '<span class="small text-muted">' . fmt_size($size) . ' · ' . strtoupper(e($ext)) . ' · ' . $lineCount . ' 行';
if ($truncated) { echo ' · <span class="text-danger">内容过长，仅显示前 5000 行</span>'; }
echo '</span>';
echo '<a class="btn btn-sm btn-primary" href="/download.php?type=' . e($type) . '&amp;file=' . urlencode($stored) . '">下载原文件</a>';
echo '</div>';
echo '<div class="card-body p-0">';
echo '<div class="code-view"><div class="code-ln">' . $lnHtml . '</div><pre><code class="language-' . e($hl) . '">' . e($content) . '</code></pre></div>';
echo '</div>';
echo '</div>';
page_footer();
