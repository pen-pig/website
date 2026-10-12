<?php
/**
 * 认证与权限校验
 * 角色：admin（管理员）/ officer（干事）/ member（普通成员）/ guest（游客未登录）
 */

/**
 * 获取当前登录用户（从 session + users.json 实时校验，禁用账号自动失效）
 * @return array|null
 */
function current_user() {
    if (!isset($_SESSION['uid'])) {
        return null;
    }
    // 会话空闲超时：2 小时无操作强制重新登录
    if (isset($_SESSION['login_time']) && time() - (int)$_SESSION['login_time'] > 7200) {
        $_SESSION['auth_expired'] = true;
        unset($_SESSION['uid'], $_SESSION['login_time']);
        return null;
    }
    $users = jdb_collection('users', 'users');
    foreach ($users as $u) {
        if (isset($u['id']) && $u['id'] === $_SESSION['uid']) {
            if (isset($u['status']) && $u['status'] === 'active') {
                return $u;
            }
            return null; // 账号被禁用则视为未登录
        }
    }
    return null;
}

/**
 * 是否已登录
 */
function is_logged_in() {
    return current_user() !== null;
}

/**
 * 是否 AJAX 请求
 */
function is_ajax() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
}

/**
 * 要求登录，未登录则跳转登录页（AJAX 返回 401）
 */
function require_login() {
    if (!is_logged_in()) {
        if (is_ajax()) {
            json_response(array('ok' => false, 'error' => '未登录或会话已过期'), 401);
        }
        $expired = !empty($_SESSION['auth_expired']);
        unset($_SESSION['auth_expired']);
        redirect('login.php' . ($expired ? '?expired=1' : ''));
    }
}

/**
 * 要求指定角色，无权限则返回 403 / 跳转提示
 * @param array $roles 允许的角色列表，如 array('admin','officer')
 * @return array 当前用户
 */
function require_role($roles) {
    require_login();
    $user = current_user();
    if (!in_array($user['role'], $roles)) {
        if (is_ajax()) {
            json_response(array('ok' => false, 'error' => '无权限执行此操作'), 403);
        }
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>无权限</title></head><body style="font-family:sans-serif;text-align:center;padding:80px;"><h1>403 无权限</h1><p>您没有权限访问该页面。</p><p><a href="/index.php">返回首页</a></p></body></html>';
        exit;
    }
    return $user;
}

/**
 * 登录成功后重置会话 ID，防会话固定攻击
 */
function login_user($user) {
    session_regenerate_id(true);
    $_SESSION['uid'] = $user['id'];
    $_SESSION['login_time'] = time();
}

/**
 * 注销登录
 */
function logout_user() {
    $_SESSION = array();
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
