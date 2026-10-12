<?php
/**
 * 认证相关接口（AJAX）
 * - GET  action=info             返回当前登录用户信息
 * - POST action=change_password  修改密码
 */
require_once __DIR__ . '/../includes/config.php';

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

if ($action === 'info') {
    if (!is_logged_in()) {
        json_response(array('ok' => false, 'error' => '未登录'), 401);
    }
    $user = current_user();
    json_response(array('ok' => true, 'user' => array(
        'id' => $user['id'],
        'username' => $user['username'],
        'name' => $user['name'],
        'role' => $user['role'],
        'created_at' => $user['created_at']
    )));
}

if ($action === 'change_password') {
    require_login();
    check_csrf();
    $user = current_user();
    $old = isset($_POST['old_password']) ? $_POST['old_password'] : '';
    $new = isset($_POST['new_password']) ? $_POST['new_password'] : '';
    if (!password_verify($old, $user['password'])) {
        json_response(array('ok' => false, 'error' => '旧密码不正确'), 400);
    }
    if (strlen($new) < 6) {
        json_response(array('ok' => false, 'error' => '新密码至少 6 位'), 400);
    }
    $users = jdb_collection('users', 'users');
    foreach ($users as $k => $u) {
        if ($u['id'] === $user['id']) {
            $users[$k]['password'] = password_hash($new, PASSWORD_BCRYPT);
        }
    }
    jdb_save_collection('users', 'users', $users);
    json_response(array('ok' => true, 'message' => '密码修改成功'));
}

json_response(array('ok' => false, 'error' => '未知操作'), 400);
