<?php
/**
 * 通知接口（AJAX）
 * - GET action=list&type=public|internal  通知列表（内部需登录）
 * - GET action=view&id=xxx                通知详情（内部需登录并标记已读）
 * - POST action=mark_read&id=xxx          标记已读
 */
require_once __DIR__ . '/../includes/config.php';

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

// 通知列表
if ($action === 'list') {
    $type = isset($_GET['type']) && $_GET['type'] === 'internal' ? 'internal' : 'public';
    if ($type === 'internal') {
        require_login();
    }
    $notices = jdb_collection('notices', 'notices');
    $list = array();
    foreach ($notices as $n) {
        if ($type === 'internal') {
            if ($n['type'] === 'internal_activity' || $n['type'] === 'internal_class' || $n['type'] === 'internal') {
                $list[] = $n;
            }
        } else {
            if ($n['type'] === 'public') $list[] = $n;
        }
    }
    // 置顶 + 时间倒序
    usort($list, function ($a, $b) {
        $pa = !empty($a['pinned']) ? 1 : 0;
        $pb = !empty($b['pinned']) ? 1 : 0;
        if ($pa !== $pb) return $pb - $pa;
        return strcmp($b['created_at'], $a['created_at']);
    });
    $out = array();
    foreach ($list as $n) {
        $out[] = array(
            'id' => $n['id'],
            'title' => $n['title'],
            'type' => $n['type'],
            'pinned' => !empty($n['pinned']),
            'created_at' => $n['created_at'],
            'creator_name' => isset($n['creator_name']) ? $n['creator_name'] : ''
        );
    }
    json_response(array('ok' => true, 'notices' => $out));
}

// 通知详情
if ($action === 'view') {
    $id = isset($_GET['id']) ? $_GET['id'] : '';
    $notices = jdb_collection('notices', 'notices');
    $n = jdb_find_by_id($notices, $id);
    if (!$n) { json_response(array('ok' => false, 'error' => '通知不存在'), 404); }
    if ($n['type'] !== 'public') {
        require_login();
        // 标记已读
        $user = current_user();
        if (!in_array($user['id'], $n['read_by'])) {
            foreach ($notices as $k => $nn) {
                if ($nn['id'] === $id) {
                    $notices[$k]['read_by'][] = $user['id'];
                    jdb_save_collection('notices', 'notices', $notices);
                }
            }
        }
    }
    json_response(array('ok' => true, 'notice' => $n));
}

// 标记已读
if ($action === 'mark_read') {
    require_login();
    check_csrf();
    $id = isset($_POST['id']) ? $_POST['id'] : '';
    $user = current_user();
    $notices = jdb_collection('notices', 'notices');
    foreach ($notices as $k => $n) {
        if ($n['id'] === $id && !in_array($user['id'], $n['read_by'])) {
            $notices[$k]['read_by'][] = $user['id'];
        }
    }
    jdb_save_collection('notices', 'notices', $notices);
    json_response(array('ok' => true));
}

json_response(array('ok' => false, 'error' => '未知操作'), 400);
