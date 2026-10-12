<?php
/**
 * 问卷接口（AJAX）
 * - GET  action=list           问卷列表（需登录）
 * - GET  action=view&id=xxx    问卷详情（需登录）
 * - POST action=vote           提交投票（需登录，校验截止时间与状态）
 */
require_once __DIR__ . '/../includes/config.php';
require_login();
$user = current_user();

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

if ($action === 'list') {
    $surveys = jdb_collection('surveys', 'surveys');
    usort($surveys, function ($a, $b) { return strcmp($b['created_at'], $a['created_at']); });
    $out = array();
    foreach ($surveys as $sv) {
        $expired = $sv['deadline'] !== '' && strtotime($sv['deadline']) < time();
        $out[] = array(
            'id' => $sv['id'],
            'title' => $sv['title'],
            'description' => $sv['description'],
            'anonymous' => $sv['anonymous'],
            'status' => $sv['status'],
            'expired' => $expired,
            'created_at' => $sv['created_at'],
            'creator_name' => $sv['creator_name'],
            'votes' => count($sv['votes'])
        );
    }
    json_response(array('ok' => true, 'surveys' => $out));
}

if ($action === 'view') {
    $id = isset($_GET['id']) ? $_GET['id'] : '';
    $surveys = jdb_collection('surveys', 'surveys');
    $sv = jdb_find_by_id($surveys, $id);
    if (!$sv) { json_response(array('ok' => false, 'error' => '问卷不存在'), 404); }
    json_response(array('ok' => true, 'survey' => $sv));
}

if ($action === 'vote') {
    check_csrf();
    $sid = isset($_POST['survey_id']) ? $_POST['survey_id'] : '';
    $surveys = jdb_collection('surveys', 'surveys');
    foreach ($surveys as $k => $sv) {
        if ($sv['id'] !== $sid) continue;
        if ($sv['status'] !== 'open') { json_response(array('ok' => false, 'error' => '问卷已关闭'), 400); }
        if ($sv['deadline'] !== '' && strtotime($sv['deadline']) < time()) { json_response(array('ok' => false, 'error' => '问卷已过截止时间'), 400); }
        if (!$sv['anonymous']) {
            foreach ($sv['votes'] as $v) {
                if (isset($v['user_id']) && $v['user_id'] === $user['id']) { json_response(array('ok' => false, 'error' => '您已参与过该问卷'), 400); }
            }
        }
        $answers = array();
        foreach ($sv['questions'] as $q) {
            $val = isset($_POST['q_' . $q['id']]) ? $_POST['q_' . $q['id']] : '';
            $answers[$q['id']] = $val;
        }
        $name = '';
        if ($sv['name_field']) {
            $name = trim(isset($_POST['respondent_name']) ? $_POST['respondent_name'] : '');
        }
        $surveys[$k]['votes'][] = array(
            'user_id' => $sv['anonymous'] ? null : $user['id'],
            'name' => $name,
            'answers' => $answers,
            'created_at' => date('Y-m-d H:i:s')
        );
        jdb_save_collection('surveys', 'surveys', $surveys);
        json_response(array('ok' => true, 'message' => '投票成功'));
    }
    json_response(array('ok' => false, 'error' => '问卷不存在'), 404);
}

json_response(array('ok' => false, 'error' => '未知操作'), 400);
