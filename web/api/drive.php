<?php
/**
 * 网盘接口（AJAX / 表单）
 * - action=upload    上传文件/上传文件夹（登录成员均可），存储于 DRIVE_DIR，总限额 20G
 * - action=mkdir     新建文件夹（登录成员均可），记录创建者
 * - action=rmdir     删除文件夹（创建者本人 / 管理员 / 干事），递归清理物理目录与记录
 * - action=delete    删除文件（上传者本人 / 管理员 / 干事）
 * - action=download  下载文件（登录成员均可），realpath 白名单校验防路径穿越
 * 安全：拒绝可执行脚本扩展名、随机重命名、CSRF 校验、路径逐段规范化 + 目录边界校验
 */
require_once __DIR__ . '/../includes/config.php';
require_login();
$user = current_user();
$action = isset($_GET['action']) ? $_GET['action'] : '';

// ---------------- 上传（文件或文件夹内文件） ----------------
if ($action === 'upload') {
    check_csrf();
    $folder = drive_norm_path(isset($_POST['folder']) ? $_POST['folder'] : '');
    $subdir = drive_norm_path(isset($_POST['subdir']) ? $_POST['subdir'] : '');
    if ($folder === null || $subdir === null) {
        json_response(array('ok' => false, 'error' => '非法的目录路径'), 400);
    }
    $rel = $folder === '' ? $subdir : ($subdir === '' ? $folder : $folder . '/' . $subdir);
    // 创建目标目录（含子目录），再校验边界
    $targetDir = DRIVE_DIR . ($rel === '' ? '' : '/' . $rel);
    if (!is_dir($targetDir)) {
        if (!@mkdir($targetDir, 0755, true)) {
            json_response(array('ok' => false, 'error' => '目录创建失败，请检查权限'), 500);
        }
    }
    if (drive_resolve($rel) === false) {
        json_response(array('ok' => false, 'error' => '非法的目录路径'), 400);
    }
    // 文件夹上传场景：自动把涉及的各级目录补登记到 folders 记录，保证层级可浏览
    if ($rel !== '') {
        $folders = jdb_collection('drive_folders', 'folders');
        $segs = explode('/', $rel);
        $acc = '';
        foreach ($segs as $seg) {
            $acc = $acc === '' ? $seg : $acc . '/' . $seg;
            $found = false;
            foreach ($folders as $f) {
                if (isset($f['path']) && $f['path'] === $acc) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $parts = explode('/', $acc);
                $name = $parts[count($parts) - 1];
                $parent = count($parts) > 1 ? implode('/', array_slice($parts, 0, -1)) : '';
                $folders[] = array(
                    'id'           => 'd_' . uniqid(),
                    'name'         => $name,
                    'path'         => $acc,
                    'parent'       => $parent,
                    'created_by'   => $user['id'],
                    'creator_name' => $user['name'],
                    'created_at'   => date('Y-m-d H:i:s')
                );
            }
        }
        jdb_save_collection('drive_folders', 'folders', $folders);
    }
    if (!isset($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
        json_response(array('ok' => false, 'error' => '未选择文件'), 400);
    }
    $f = $_FILES['file'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        json_response(array('ok' => false, 'error' => '上传失败，错误码：' . $f['error']), 400);
    }
    if ($f['size'] <= 0) {
        json_response(array('ok' => false, 'error' => '文件内容为空'), 400);
    }
    // 单文件上限 2GB
    if ($f['size'] > 2147483648) {
        json_response(array('ok' => false, 'error' => '单文件不能超过 2GB'), 400);
    }
    // 拒绝可执行脚本（不区分大小写）
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $blocked = array('php', 'phtml', 'php5', 'php7', 'phps', 'pht', 'php3', 'php4');
    if (in_array($ext, $blocked)) {
        json_response(array('ok' => false, 'error' => '不允许上传可执行脚本文件'), 400);
    }
    // 20G 总限额（递归统计物理文件）
    if (drive_used_bytes() + $f['size'] > DRIVE_LIMIT) {
        json_response(array('ok' => false, 'error' => '网盘空间不足（总限额 20G），请联系管理员清理'), 400);
    }
    // 随机重命名，避免覆盖与猜测（保留扩展名用于识别）
    $stored = date('YmdHis') . '_' . uniqid() . '.' . $ext;
    $dest = $targetDir . '/' . $stored;
    if (!@move_uploaded_file($f['tmp_name'], $dest)) {
        json_response(array('ok' => false, 'error' => '文件保存失败，请检查目录权限'), 500);
    }
    $files = jdb_collection('drive', 'files');
    $files[] = array(
        'id'            => 'f_' . uniqid(),
        'stored'        => $stored,
        'orig'          => $f['name'],
        'size'          => $f['size'],
        'folder'        => $rel,
        'uploaded_by'   => $user['id'],
        'uploader_name' => $user['name'],
        'created_at'    => date('Y-m-d H:i:s')
    );
    jdb_save_collection('drive', 'files', $files);
    json_response(array('ok' => true, 'message' => '上传成功'));
}

// ---------------- 新建文件夹 ----------------
if ($action === 'mkdir') {
    check_csrf();
    $parent = drive_norm_path(isset($_POST['parent']) ? $_POST['parent'] : '');
    $name = trim(isset($_POST['name']) ? $_POST['name'] : '');
    if ($parent === null) {
        json_response(array('ok' => false, 'error' => '非法的目录路径'), 400);
    }
    if ($name === '' || $name === '.' || $name === '..'
        || strpos($name, '/') !== false || strpos($name, '\\') !== false) {
        json_response(array('ok' => false, 'error' => '文件夹名称不合法'), 400);
    }
    if (strlen($name) > 60) {
        json_response(array('ok' => false, 'error' => '文件夹名称过长（最多 60 字符）'), 400);
    }
    $path = $parent === '' ? $name : $parent . '/' . $name;
    $target = DRIVE_DIR . ($path === '' ? '' : '/' . $path);
    if (is_dir($target)) {
        json_response(array('ok' => false, 'error' => '同名文件夹已存在'), 400);
    }
    $parentReal = drive_resolve($parent);
    if ($parentReal === false || !is_dir($parentReal)) {
        json_response(array('ok' => false, 'error' => '上级目录不存在'), 400);
    }
    if (!@mkdir($target, 0755)) {
        json_response(array('ok' => false, 'error' => '文件夹创建失败，请检查权限'), 500);
    }
    $folders = jdb_collection('drive_folders', 'folders');
    $folders[] = array(
        'id'           => 'd_' . uniqid(),
        'name'         => $name,
        'path'         => $path,
        'parent'       => $parent,
        'created_by'   => $user['id'],
        'creator_name' => $user['name'],
        'created_at'   => date('Y-m-d H:i:s')
    );
    jdb_save_collection('drive_folders', 'folders', $folders);
    json_response(array('ok' => true, 'message' => '文件夹已创建'));
}

// ---------------- 删除文件夹 ----------------
if ($action === 'rmdir') {
    check_csrf();
    $path = drive_norm_path(isset($_POST['path']) ? $_POST['path'] : '');
    if ($path === null || $path === '') {
        json_response(array('ok' => false, 'error' => '非法的目录路径'), 400);
    }
    $folders = jdb_collection('drive_folders', 'folders');
    $idx = -1;
    foreach ($folders as $k => $f) {
        if (isset($f['path']) && $f['path'] === $path) {
            $idx = $k;
            break;
        }
    }
    if ($idx === -1) {
        json_response(array('ok' => false, 'error' => '文件夹记录不存在'), 404);
    }
    $rec = $folders[$idx];
    // 删除权限：创建者本人或管理员 / 干事
    $can = (isset($rec['created_by']) && $rec['created_by'] === $user['id'])
        || in_array($user['role'], array('admin', 'officer'));
    if (!$can) {
        json_response(array('ok' => false, 'error' => '只能删除自己创建的文件夹'), 403);
    }
    $targetReal = drive_resolve($path);
    if ($targetReal === false || !is_dir($targetReal)) {
        json_response(array('ok' => false, 'error' => '文件夹不存在'), 404);
    }
    drive_rrmdir($targetReal);
    // 清理记录：文件夹本身及其子文件夹、其下及子文件夹下的所有文件
    $keepF = array();
    foreach ($folders as $f) {
        if ($f['path'] === $path || strpos($f['path'], $path . '/') === 0) {
            continue;
        }
        $keepF[] = $f;
    }
    $files = jdb_collection('drive', 'files');
    $keepFiles = array();
    foreach ($files as $f) {
        $fp = isset($f['folder']) ? $f['folder'] : '';
        if ($fp === $path || strpos($fp, $path . '/') === 0) {
            continue;
        }
        $keepFiles[] = $f;
    }
    jdb_save_collection('drive_folders', 'folders', $keepF);
    jdb_save_collection('drive', 'files', $keepFiles);
    add_log('删除网盘文件夹', '路径：/' . $path . '（由 ' . (isset($rec['creator_name']) ? $rec['creator_name'] : '未知') . ' 创建）');
    json_response(array('ok' => true, 'message' => '文件夹已删除'));
}

// ---------------- 删除文件 ----------------
if ($action === 'delete') {
    check_csrf();
    $id = isset($_POST['file_id']) ? trim($_POST['file_id']) : '';
    if ($id === '') {
        json_response(array('ok' => false, 'error' => '缺少文件标识'), 400);
    }
    $files = jdb_collection('drive', 'files');
    $idx = -1;
    foreach ($files as $k => $f) {
        if (isset($f['id']) && $f['id'] === $id) {
            $idx = $k;
            break;
        }
    }
    if ($idx === -1) {
        json_response(array('ok' => false, 'error' => '文件记录不存在'), 404);
    }
    $rec = $files[$idx];
    // 删除权限：上传者本人或管理员 / 干事
    $can = (isset($rec['uploaded_by']) && $rec['uploaded_by'] === $user['id'])
        || in_array($user['role'], array('admin', 'officer'));
    if (!$can) {
        json_response(array('ok' => false, 'error' => '只能删除自己上传的文件'), 403);
    }
    $folder = isset($rec['folder']) ? $rec['folder'] : '';
    $relFile = $folder === '' ? $rec['stored'] : $folder . '/' . $rec['stored'];
    $targetReal = drive_resolve($relFile);
    if ($targetReal === false || !is_file($targetReal)) {
        json_response(array('ok' => false, 'error' => '文件不存在或已被清理'), 404);
    }
    @unlink($targetReal);
    array_splice($files, $idx, 1);
    jdb_save_collection('drive', 'files', $files);
    add_log('删除网盘文件', '路径：/' . ($folder === '' ? $rec['orig'] : $folder . '/' . $rec['orig']));
    json_response(array('ok' => true, 'message' => '删除成功'));
}

// ---------------- 下载 ----------------
if ($action === 'rename') {
    check_csrf();
    $id = isset($_POST['file_id']) ? trim($_POST['file_id']) : '';
    $newName = trim(isset($_POST['new_name']) ? $_POST['new_name'] : '');
    if ($id === '' || $newName === '') {
        json_response(array('ok' => false, 'error' => '缺少参数'), 400);
    }
    if (mb_strlen($newName) > 100 || strpos($newName, '/') !== false || strpos($newName, '\\') !== false) {
        json_response(array('ok' => false, 'error' => '文件名不合法（不能含斜杠，≤100 字）'), 400);
    }
    $files = jdb_collection('drive', 'files');
    $idx = -1;
    foreach ($files as $k => $f) {
        if (isset($f['id']) && $f['id'] === $id) { $idx = $k; break; }
    }
    if ($idx === -1) { json_response(array('ok' => false, 'error' => '文件记录不存在'), 404); }
    $rec = $files[$idx];
    $can = (isset($rec['uploaded_by']) && $rec['uploaded_by'] === $user['id']) || in_array($user['role'], array('admin', 'officer'));
    if (!$can) { json_response(array('ok' => false, 'error' => '只能重命名自己上传的文件'), 403); }
    $files[$idx]['orig'] = $newName;
    jdb_save_collection('drive', 'files', $files);
    log_action('网盘', '重命名 ' . $rec['orig'] . ' → ' . $newName);
    json_response(array('ok' => true, 'message' => '已重命名'));
}

if ($action === 'move') {
    check_csrf();
    $id = isset($_POST['file_id']) ? trim($_POST['file_id']) : '';
    $newFolder = drive_norm_path(isset($_POST['new_folder']) ? $_POST['new_folder'] : '');
    if ($id === '' || $newFolder === null) {
        json_response(array('ok' => false, 'error' => '缺少参数或目录不合法'), 400);
    }
    $files = jdb_collection('drive', 'files');
    $idx = -1;
    foreach ($files as $k => $f) {
        if (isset($f['id']) && $f['id'] === $id) { $idx = $k; break; }
    }
    if ($idx === -1) { json_response(array('ok' => false, 'error' => '文件记录不存在'), 404); }
    $rec = $files[$idx];
    $can = (isset($rec['uploaded_by']) && $rec['uploaded_by'] === $user['id']) || in_array($user['role'], array('admin', 'officer'));
    if (!$can) { json_response(array('ok' => false, 'error' => '只能移动自己上传的文件'), 403); }

    $oldFolder = isset($rec['folder']) ? $rec['folder'] : '';
    if ($oldFolder === $newFolder) {
        json_response(array('ok' => true, 'message' => '位置未变化'));
    }
    $oldReal = drive_resolve($oldFolder === '' ? $rec['stored'] : $oldFolder . '/' . $rec['stored']);
    $newDirReal = drive_resolve($newFolder);
    if ($oldReal === false || !is_file($oldReal)) { json_response(array('ok' => false, 'error' => '物理文件不存在'), 404); }
    if ($newDirReal === false || !is_dir($newDirReal)) { json_response(array('ok' => false, 'error' => '目标文件夹不存在（请先创建）'), 404); }
    if (!@rename($oldReal, $newDirReal . '/' . $rec['stored'])) {
        json_response(array('ok' => false, 'error' => '移动文件失败，请检查权限'), 500);
    }
    $files[$idx]['folder'] = $newFolder;
    jdb_save_collection('drive', 'files', $files);
    log_action('网盘', '移动 ' . $rec['orig'] . ' → /' . $newFolder);
    json_response(array('ok' => true, 'message' => '已移动'));
}

if ($action === 'download') {
    $folder = drive_norm_path(isset($_GET['folder']) ? $_GET['folder'] : '');
    $stored = basename(isset($_GET['file']) ? $_GET['file'] : '');
    if ($folder === null || $stored === '' || $stored === '.' || $stored === '..') {
        http_response_code(400);
        die('非法的文件名');
    }
    $relFile = $folder === '' ? $stored : $folder . '/' . $stored;
    $targetReal = drive_resolve($relFile);
    if ($targetReal === false || !is_file($targetReal)) {
        http_response_code(404);
        die('文件不存在或已被清理');
    }
    // 从记录中查找原始文件名（用于下载显示名）
    $orig = $stored;
    $files = jdb_collection('drive', 'files');
    foreach ($files as $f) {
        $fp = isset($f['folder']) ? $f['folder'] : '';
        if ($fp === $folder && isset($f['stored']) && $f['stored'] === $stored) {
            $orig = isset($f['orig']) && $f['orig'] !== '' ? $f['orig'] : $stored;
            break;
        }
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . rawurlencode($orig) . '"');
    header('Content-Length: ' . filesize($targetReal));
    header('X-Content-Type-Options: nosniff');
    readfile($targetReal);
    exit;
}

json_response(array('ok' => false, 'error' => '未知操作'), 400);
