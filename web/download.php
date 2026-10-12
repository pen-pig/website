<?php
/**
 * 统一文件下载入口（鉴权）
 * 所有文件下载必须经过本页：download.php?type=materials|homework|notices&file=<存储文件名>
 * - 必须登录
 * - materials / notices：所有登录成员可下载
 * - homework：仅管理员 / 干事可下载（作业默认仅管理员/干事可见）
 * - basename 处理防止路径穿越
 */
require_once __DIR__ . '/includes/config.php';

require_login();
$user = current_user();

$type = isset($_GET['type']) ? $_GET['type'] : '';
$file = isset($_GET['file']) ? $_GET['file'] : '';

$allowedTypes = array('materials', 'homework', 'notices');
if (!in_array($type, $allowedTypes)) {
    http_response_code(400);
    die('非法的下载类型');
}

// 防路径穿越：只保留 basename
$stored = basename($file);
if ($stored === '' || $stored === '.' || $stored === '..') {
    http_response_code(400);
    die('非法的文件名');
}

// 作业仅管理员/干事可下载
if ($type === 'homework' && !in_array($user['role'], array('admin', 'officer'))) {
    http_response_code(403);
    die('无权限下载作业文件');
}

$path = UPLOAD_DIR . '/' . $type . '/' . $stored;
if (!file_exists($path)) {
    http_response_code(404);
    die('文件不存在或已被清理');
}

// 从 JSON 记录中查找原始文件名（用于下载时的显示名）
$origName = $stored;
$notices = jdb_collection('notices', 'notices');
foreach ($notices as $n) {
    if (!empty($n['attachments']) && is_array($n['attachments'])) {
        foreach ($n['attachments'] as $a) {
            if (isset($a['stored']) && $a['stored'] === $stored) {
                $origName = $a['orig'];
                break 2;
            }
        }
    }
}
if ($origName === $stored) {
    $branches = jdb_collection('projects', 'branches');
    $closedProj = false;
    foreach ($branches as $br) {
        if (empty($br['projects']) || !is_array($br['projects'])) continue;
        foreach ($br['projects'] as $p) {
            $pClosed = (isset($p['status']) ? $p['status'] : 'open') === 'closed';
            if (!empty($p['materials'])) {
                foreach ($p['materials'] as $m) {
                    if (isset($m['stored']) && $m['stored'] === $stored) { $origName = $m['orig']; if ($pClosed) $closedProj = true; break 3; }
                }
            }
            if (!empty($p['homework'])) {
                foreach ($p['homework'] as $h) {
                    if (isset($h['stored']) && $h['stored'] === $stored) { $origName = $h['orig']; break 3; }
                }
            }
        }
    }
    // 封闭项目：资料仅管理员/干事可下载（防止提前看内容）
    if ($type === 'materials' && $closedProj && !in_array($user['role'], array('admin', 'officer'))) {
        http_response_code(403);
        die('该项目暂未开放');
    }
}

// 输出文件
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . rawurlencode($origName) . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
