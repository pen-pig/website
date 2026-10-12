<?php
/**
 * 成员管理（仅管理员）
 * 功能：创建、删除、改角色、重置密码、禁用/启用、搜索、批量导入（粘贴名单/CSV）
 * 批量导入格式：用户名,密码,角色,姓名（角色：admin/officer/member 或 管理员/干事/成员）
 */
require_once __DIR__ . '/../includes/config.php';
$admin = require_role(array('admin'));

// ---------------- POST 处理 ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $users = jdb_collection('users', 'users');

    // 创建账号
    if ($action === 'create') {
        $username = trim(isset($_POST['username']) ? $_POST['username'] : '');
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        $role = isset($_POST['role']) ? $_POST['role'] : 'member';
        $name = trim(isset($_POST['name']) ? $_POST['name'] : '');
        $exists = false;
        foreach ($users as $u) {
            if ($u['username'] === $username) { $exists = true; break; }
        }
        if ($username === '' || $password === '') {
            $_SESSION['flash'] = '用户名和密码不能为空';
        } elseif ($exists) {
            $_SESSION['flash'] = '用户名已存在';
        } elseif (strlen($password) < 6) {
            $_SESSION['flash'] = '密码至少 6 位';
        } else {
            $users[] = array(
                'id' => 'u_' . uniqid(),
                'username' => $username,
                'password' => password_hash($password, PASSWORD_BCRYPT),
                'name' => $name !== '' ? $name : $username,
                'role' => in_array($role, array('admin', 'officer', 'member')) ? $role : 'member',
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s')
            );
            jdb_save_collection('users', 'users', $users);
            $_SESSION['flash'] = '账号已创建';
        }
    }

    // 修改角色
    if ($action === 'role') {
        $uid = isset($_POST['user_id']) ? $_POST['user_id'] : '';
        $role = isset($_POST['role']) ? $_POST['role'] : 'member';
        if ($uid === $admin['id']) {
            $_SESSION['flash'] = '不能修改自己的角色';
        } elseif (in_array($role, array('admin', 'officer', 'member'))) {
            foreach ($users as $k => $u) {
                if ($u['id'] === $uid) { $users[$k]['role'] = $role; }
            }
            jdb_save_collection('users', 'users', $users);
            $_SESSION['flash'] = '角色已修改';
        }
    }

    // 重置密码
    if ($action === 'reset_pwd') {
        $uid = isset($_POST['user_id']) ? $_POST['user_id'] : '';
        $newPwd = isset($_POST['new_password']) ? $_POST['new_password'] : '';
        if (strlen($newPwd) < 6) {
            $_SESSION['flash'] = '新密码至少 6 位';
        } else {
            foreach ($users as $k => $u) {
                if ($u['id'] === $uid) { $users[$k]['password'] = password_hash($newPwd, PASSWORD_BCRYPT); }
            }
            jdb_save_collection('users', 'users', $users);
            $_SESSION['flash'] = '密码已重置';
        }
    }

    // 禁用/启用
    if ($action === 'toggle_status') {
        $uid = isset($_POST['user_id']) ? $_POST['user_id'] : '';
        if ($uid === $admin['id']) {
            $_SESSION['flash'] = '不能禁用自己';
        } else {
            foreach ($users as $k => $u) {
                if ($u['id'] === $uid) {
                    $users[$k]['status'] = $u['status'] === 'active' ? 'disabled' : 'active';
                }
            }
            jdb_save_collection('users', 'users', $users);
            $_SESSION['flash'] = '账号状态已更新';
        }
    }

    // 删除账号
    if ($action === 'delete') {
        $uid = isset($_POST['user_id']) ? $_POST['user_id'] : '';
        if ($uid === $admin['id']) {
            $_SESSION['flash'] = '不能删除自己的账号';
        } else {
            $newUsers = array();
            foreach ($users as $u) {
                if ($u['id'] !== $uid) $newUsers[] = $u;
            }
            jdb_save_collection('users', 'users', $newUsers);
            $_SESSION['flash'] = '账号已删除';
        }
    }

    // 批量导入（粘贴名单 或 CSV 上传）
    if ($action === 'import') {
        $text = isset($_POST['import_text']) ? $_POST['import_text'] : '';
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
            $csvContent = file_get_contents($_FILES['csv_file']['tmp_name']);
            // 去掉 UTF-8 BOM
            $csvContent = preg_replace('/^\xEF\xBB\xBF/', '', $csvContent);
            $text .= "\n" . $csvContent;
        }
        $lines = preg_split('/\r\n|\r|\n/', trim($text));
        $added = 0;
        $skipped = 0;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $parts = str_getcsv($line);
            if (count($parts) < 4) { $skipped++; continue; }
            $username = trim($parts[0]);
            $password = $parts[1];
            $role = strtolower(trim($parts[2]));
            $name = trim($parts[3]);
            // 角色中文化映射
            $roleMap = array('管理员' => 'admin', '干事' => 'officer', '成员' => 'member');
            if (isset($roleMap[$role])) $role = $roleMap[$role];
            if (!in_array($role, array('admin', 'officer', 'member'))) { $skipped++; continue; }
            if ($username === '' || $password === '') { $skipped++; continue; }
            $exists = false;
            foreach ($users as $u) {
                if ($u['username'] === $username) { $exists = true; break; }
            }
            if ($exists) { $skipped++; continue; }
            $users[] = array(
                'id' => 'u_' . uniqid(),
                'username' => $username,
                'password' => password_hash($password, PASSWORD_BCRYPT),
                'name' => $name !== '' ? $name : $username,
                'role' => $role,
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s')
            );
            $added++;
        }
        jdb_save_collection('users', 'users', $users);
        $_SESSION['flash'] = '批量导入完成：成功 ' . $added . ' 条，跳过 ' . $skipped . ' 条';
    }

    // 生成注册邀请码（一次性，可设有效期）
    if ($action === 'invite_create') {
        $days = intval(isset($_POST['days']) ? $_POST['days'] : 7);
        if ($days < 1 || $days > 365) $days = 7;
        $invites = jdb_collection('invites', 'invites');
        $code = strtoupper(bin2hex(openssl_random_pseudo_bytes(4)));
        $invites[] = array(
            'id'           => 'iv_' . uniqid(),
            'code'         => $code,
            'created_by'   => $admin['id'],
            'creator_name' => $admin['name'],
            'created_at'   => date('Y-m-d H:i:s'),
            'expires_at'   => date('Y-m-d H:i:s', time() + $days * 86400),
            'used_by'      => '',
            'used_name'    => '',
            'used_at'      => ''
        );
        jdb_save_collection('invites', 'invites', $invites);
        $_SESSION['flash'] = '邀请码已生成：' . $code . '（' . $days . ' 天内有效，使用一次后作废）';
        log_action('邀请码', '生成邀请码 ' . $code);
    }

    // 删除邀请码
    if ($action === 'invite_delete') {
        $ivid = isset($_POST['invite_id']) ? $_POST['invite_id'] : '';
        $invites = jdb_collection('invites', 'invites');
        $kept = array();
        foreach ($invites as $iv) {
            if ($iv['id'] !== $ivid) $kept[] = $iv;
        }
        jdb_save_collection('invites', 'invites', $kept);
        $_SESSION['flash'] = '邀请码已删除';
    }

    redirect('members.php');
}

$flash = '';
if (isset($_SESSION['flash'])) { $flash = $_SESSION['flash']; unset($_SESSION['flash']); }

// ---------------- 页面渲染 ----------------
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$users = jdb_collection('users', 'users');
$list = array();
foreach ($users as $u) {
    if ($q !== '') {
        if (stripos($u['username'], $q) === false && stripos($u['name'], $q) === false) continue;
    }
    $list[] = $u;
}
// 按创建时间倒序
usort($list, function ($a, $b) { return strcmp($b['created_at'], $a['created_at']); });

page_header('成员管理', 'admin');
if ($flash !== '') flash_toast($flash);

echo '<div class="row">';
// 左侧：创建 + 批量导入
echo '<div class="col-lg-4">';
echo '<div class="card mb-4"><div class="card-header"><strong>创建账号</strong></div><div class="card-body">';
echo '<form method="post" action="members.php">' . csrf_field();
echo '<input type="hidden" name="action" value="create">';
echo '<div class="form-group"><label>用户名 *</label><input type="text" name="username" class="form-control" required></div>';
echo '<div class="form-group"><label>密码 *（至少 6 位）</label><input type="text" name="password" class="form-control" required></div>';
echo '<div class="form-group"><label>姓名</label><input type="text" name="name" class="form-control"></div>';
echo '<div class="form-group"><label>角色</label><select name="role" class="form-control"><option value="member">普通成员</option><option value="officer">干事</option><option value="admin">管理员</option></select></div>';
echo '<button class="btn btn-primary btn-block">创建</button></form>';
echo '</div></div>';

echo '<div class="card"><div class="card-header"><strong>批量导入</strong></div><div class="card-body">';
echo '<form method="post" action="members.php" enctype="multipart/form-data">' . csrf_field();
echo '<input type="hidden" name="action" value="import">';
echo '<div class="form-group"><label>粘贴名单（每行：用户名,密码,角色,姓名）</label><textarea name="import_text" class="form-control" rows="4" placeholder="zhangsan,123456,member,张三&#10;lisi,123456,officer,李四"></textarea></div>';
echo '<div class="form-group"><label>或上传 CSV 文件</label><input type="file" name="csv_file" class="form-control-file" accept=".csv,.txt"></div>';
echo '<button class="btn btn-success btn-block">导入</button></form>';
echo '</div></div>';

// 注册邀请码
echo '<div class="card mt-4"><div class="card-header"><strong>注册邀请码</strong></div><div class="card-body">';
echo '<p class="text-muted small mb-2">新人在登录页「凭邀请码注册」自助开通账号（默认成员角色），邀请码一次性使用。</p>';
echo '<form method="post" action="members.php" class="form-inline mb-3">' . csrf_field() . '<input type="hidden" name="action" value="invite_create">';
echo '<select name="days" class="form-control form-control-sm mr-2"><option value="1">1 天有效</option><option value="3">3 天有效</option><option value="7" selected>7 天有效</option><option value="30">30 天有效</option></select>';
echo '<button class="btn btn-sm btn-primary">生成邀请码</button></form>';
$invites = jdb_collection('invites', 'invites');
if (count($invites) === 0) {
    echo '<div class="text-muted small">暂无邀请码</div>';
} else {
    echo '<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>邀请码</th><th>状态</th><th>过期时间</th><th></th></tr></thead><tbody>';
    foreach ($invites as $iv) {
        $expired = isset($iv['expires_at']) && $iv['expires_at'] !== '' && strtotime($iv['expires_at']) < time();
        if (!empty($iv['used_by'])) {
            $st = '<span class="badge badge-secondary">已用：' . e(isset($iv['used_name']) ? $iv['used_name'] : '') . '</span>';
        } elseif ($expired) {
            $st = '<span class="badge badge-danger">已过期</span>';
        } else {
            $st = '<span class="badge badge-success">可用</span>';
        }
        echo '<tr><td><code>' . e($iv['code']) . '</code></td><td>' . $st . '</td><td class="small text-muted">' . e(substr(isset($iv['expires_at']) ? $iv['expires_at'] : '', 0, 10)) . '</td>';
        echo '<td><form method="post" action="members.php" class="d-inline">' . csrf_field() . '<input type="hidden" name="action" value="invite_delete"><input type="hidden" name="invite_id" value="' . e($iv['id']) . '"><button class="btn btn-sm btn-outline-danger">删</button></form></td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</div></div>';
echo '</div>';

// 右侧：成员列表
echo '<div class="col-lg-8">';
echo '<div class="card"><div class="card-header d-flex justify-content-between align-items-center"><strong>成员列表（' . count($list) . '）</strong>';
echo '<form method="get" action="members.php" class="form-inline"><input type="text" name="q" value="' . e($q) . '" class="form-control form-control-sm mr-1" placeholder="搜索用户名/姓名"><button class="btn btn-sm btn-outline-primary">搜索</button></form>';
echo '</div><div class="card-body p-0">';
if (count($list) === 0) { echo '<div class="p-3 text-muted small">暂无成员</div>'; }
else {
    echo '<div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>用户名</th><th>姓名</th><th>角色</th><th>状态</th><th>创建时间</th><th style="width:280px;">操作</th></tr></thead><tbody>';
    foreach ($list as $u) {
        $roleName = array('admin' => '管理员', 'officer' => '干事', 'member' => '成员');
        $r = isset($roleName[$u['role']]) ? $roleName[$u['role']] : $u['role'];
        $st = $u['status'] === 'active' ? '<span class="badge badge-success">正常</span>' : '<span class="badge badge-secondary">禁用</span>';
        echo '<tr><td>' . e($u['username']) . '</td><td>' . e($u['name']) . '</td><td>' . e($r) . '</td><td>' . $st . '</td><td class="small text-muted">' . e(substr($u['created_at'], 0, 16)) . '</td><td>';
        // 角色修改
        echo '<form method="post" action="members.php" class="d-inline">' . csrf_field() . '<input type="hidden" name="action" value="role"><input type="hidden" name="user_id" value="' . e($u['id']) . '"><select name="role" class="form-control form-control-sm d-inline-block mr-1" style="width:90px;" onchange="this.form.submit()"><option value="member"' . ($u['role'] === 'member' ? ' selected' : '') . '>成员</option><option value="officer"' . ($u['role'] === 'officer' ? ' selected' : '') . '>干事</option><option value="admin"' . ($u['role'] === 'admin' ? ' selected' : '') . '>管理员</option></select></form>';
        // 重置密码
        echo '<form method="post" action="members.php" class="d-inline mr-1">' . csrf_field() . '<input type="hidden" name="action" value="reset_pwd"><input type="hidden" name="user_id" value="' . e($u['id']) . '"><input type="text" name="new_password" class="form-control form-control-sm d-inline-block mr-1" style="width:110px;" placeholder="新密码" required><button class="btn btn-sm btn-outline-warning">重置</button></form><br>';
        // 禁用/启用 + 删除
        if ($u['id'] !== $admin['id']) {
            echo '<form method="post" action="members.php" class="d-inline mr-1">' . csrf_field() . '<input type="hidden" name="action" value="toggle_status"><input type="hidden" name="user_id" value="' . e($u['id']) . '"><button class="btn btn-sm btn-outline-secondary">' . ($u['status'] === 'active' ? '禁用' : '启用') . '</button></form>';
            echo '<form method="post" action="members.php" class="d-inline">' . csrf_field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="user_id" value="' . e($u['id']) . '"><button class="btn btn-sm btn-outline-danger" onclick="return confirm(\'确定删除该账号？\')">删除</button></form>';
        } else {
            echo '<span class="text-muted small">当前账号</span>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</div></div>';
echo '</div></div>';

page_footer();
