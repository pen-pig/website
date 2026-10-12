<?php
/**
 * 私信后端接口（AJAX）
 * - GET  action=unread            未读私信数
 * - GET  action=list&box=inbox|sent  列表（供前端刷新用）
 * - GET  action=conversation&with=用户ID  对话消息
 * - POST action=send              发送私信（含批量）
 * - POST action=mark_read         标记单条已读
 * 所有操作必须校验登录与角色权限，后端强制拦截越权发送
 */
require_once __DIR__ . '/../includes/config.php';
require_login();
$user = current_user();
$uid = $user['id'];
$isStaff = in_array($user['role'], array('admin', 'officer'));

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

// 未读数
if ($action === 'unread') {
    json_response(array('ok' => true, 'count' => unread_count($uid)));
}

// 消息列表
if ($action === 'list') {
    $box = isset($_GET['box']) ? $_GET['box'] : 'inbox';
    $messages = jdb_collection('messages', 'messages');
    $list = array();
    foreach ($messages as $m) {
        if ($box === 'sent' && $m['from_id'] === $uid) $list[] = $m;
        if ($box === 'inbox' && $m['to_id'] === $uid) $list[] = $m;
    }
    usort($list, function ($a, $b) { return strcmp($b['created_at'], $a['created_at']); });
    json_response(array('ok' => true, 'messages' => array_slice($list, 0, 100)));
}

// 对话
if ($action === 'conversation') {
    $withId = isset($_GET['with']) ? $_GET['with'] : '';
    $messages = jdb_collection('messages', 'messages');
    $conv = array();
    foreach ($messages as $m) {
        if (($m['from_id'] === $uid && $m['to_id'] === $withId) || ($m['from_id'] === $withId && $m['to_id'] === $uid)) {
            $conv[] = $m;
        }
    }
    usort($conv, function ($a, $b) { return strcmp($a['created_at'], $b['created_at']); });
    json_response(array('ok' => true, 'messages' => $conv));
}

// 发送私信（POST）
if ($action === 'send') {
    check_csrf();
    $content = trim(isset($_POST['content']) ? $_POST['content'] : '');
    $toIds = isset($_POST['to_ids']) && is_array($_POST['to_ids']) ? $_POST['to_ids'] : (isset($_POST['to_id']) ? array($_POST['to_id']) : array());
    $toIds = array_values(array_unique($toIds));
    if ($content === '') { json_response(array('ok' => false, 'error' => '内容不能为空'), 400); }
    if (count($toIds) === 0) { json_response(array('ok' => false, 'error' => '请选择收件人'), 400); }

    $users = jdb_collection('users', 'users');
    $allowed = array();
    foreach ($users as $u) {
        if (!in_array($u['id'], $toIds)) continue;
        if ($u['status'] !== 'active') continue;
        // 普通成员不能给普通成员发送（后端拦截）
        if (!$isStaff && !in_array($u['role'], array('admin', 'officer'))) continue;
        $allowed[] = $u;
    }
    if (count($allowed) === 0) {
        json_response(array('ok' => false, 'error' => '没有可发送的收件人'), 403);
    }
    $messages = jdb_collection('messages', 'messages');
    foreach ($allowed as $target) {
        $messages[] = array(
            'id'         => 'm_' . uniqid(),
            'from_id'    => $uid,
            'from_name'  => $user['name'],
            'to_id'      => $target['id'],
            'to_name'    => $target['name'],
            'content'    => $content,
            'created_at' => date('Y-m-d H:i:s'),
            'is_read'    => false,
            'read_at'    => null,
            'type'       => count($allowed) > 1 ? 'broadcast' : 'single'
        );
    }
    jdb_save_collection('messages', 'messages', $messages);
    json_response(array('ok' => true, 'sent' => count($allowed)));
}

// 标记已读
if ($action === 'mark_read') {
    check_csrf();
    $mid = isset($_POST['id']) ? $_POST['id'] : '';
    $messages = jdb_collection('messages', 'messages');
    foreach ($messages as $k => $m) {
        if ($m['id'] === $mid && $m['to_id'] === $uid && empty($m['is_read'])) {
            $messages[$k]['is_read'] = true;
            $messages[$k]['read_at'] = date('Y-m-d H:i:s');
        }
    }
    jdb_save_collection('messages', 'messages', $messages);
    json_response(array('ok' => true));
}

// 删除自己收到的私信
if ($action === 'delete') {
    check_csrf();
    $mid = isset($_POST['id']) ? $_POST['id'] : '';
    $messages = jdb_collection('messages', 'messages');
    $kept = array();
    $found = false;
    foreach ($messages as $m) {
        if ($m['id'] === $mid && $m['to_id'] === $uid) { $found = true; continue; }
        $kept[] = $m;
    }
    if ($found) jdb_save_collection('messages', 'messages', $kept);
    json_response(array('ok' => $found, 'error' => $found ? '' : '消息不存在或无权删除'));
}

json_response(array('ok' => false, 'error' => '未知操作'), 400);
