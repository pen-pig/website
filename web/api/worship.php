<?php
/**
 * 膜拜接口（AJAX）— 幸运值抽奖模式
 * - GET  action=objects  膜拜对象列表 + 今日膜拜人数 + 我今日已膜拜集合 + 用户幸运榜（游客可看）
 * - POST action=worship  膜拜（需登录 + CSRF + 每人每天对同一对象限一次，随机获得今日幸运值 1~100，记入个人幸运榜）
 * - GET  action=rank     当日用户幸运榜（按幸运值降序，游客可看）
 * 数据按日期维度存储在 data/json/worship.json 的 daily 中，新的一天自动归零（历史保留，仅裁剪 60 天前）。
 * 说明：膜拜得分不累加到膜拜对象，而是作为膜拜者当日的"幸运值"（抽奖反馈），排行榜为用户得分排名。
 */
require_once __DIR__ . '/../includes/config.php';

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

/**
 * 取指定日期的膜拜用户明细（无则空数组）
 */
function worship_today_users($daily, $date) {
    foreach ($daily as $d) {
        if (isset($d['date']) && $d['date'] === $date && isset($d['users']) && is_array($d['users'])) {
            return $d['users'];
        }
    }
    return array();
}

/**
 * 计算用户幸运榜：按当日幸运值降序，同分先膜拜的在前
 */
function worship_rank_users($users) {
    $rank = $users;
    usort($rank, function ($a, $b) {
        $sa = (int)$a['score'];
        $sb = (int)$b['score'];
        if ($sa !== $sb) return $sb - $sa;
        return strcmp(isset($a['ts']) ? $a['ts'] : '', isset($b['ts']) ? $b['ts'] : '');
    });
    return $rank;
}

// 读取膜拜数据
$worship = jdb_read(JSON_DIR . '/worship.json');
$objects = (isset($worship['objects']) && is_array($worship['objects'])) ? $worship['objects'] : array();
$daily   = (isset($worship['daily']) && is_array($worship['daily'])) ? $worship['daily'] : array();
$today   = date('Y-m-d');
$user    = is_logged_in() ? current_user() : null;

// 对象列表 + 今日膜拜人数 + 我的已膜拜集合 + 用户幸运榜
if ($action === 'objects') {
    $todayUsers = worship_today_users($daily, $today);
    $out = array();
    foreach ($objects as $o) {
        $votes = 0;
        foreach ($todayUsers as $u) {
            if (isset($u['object_id']) && $u['object_id'] === $o['id']) $votes++;
        }
        $out[] = array(
            'id'    => $o['id'],
            'name'  => $o['name'],
            'image' => 'img.php?file=' . urlencode($o['image']),
            'desc'  => isset($o['desc']) ? $o['desc'] : '',
            'votes' => (int)$votes
        );
    }
    $mine = array();
    if ($user) {
        foreach ($todayUsers as $u) {
            if (isset($u['uid']) && $u['uid'] === $user['id']) {
                $mine[] = isset($u['object_id']) ? $u['object_id'] : '';
            }
        }
    }
    json_response(array(
        'ok' => true,
        'date' => $today,
        'logged_in' => $user ? true : false,
        'objects' => $out,
        'mine' => $mine,
        'rank' => worship_rank_users($todayUsers)
    ));
}

// 当日用户幸运榜
if ($action === 'rank') {
    $todayUsers = worship_today_users($daily, $today);
    json_response(array('ok' => true, 'date' => $today, 'rank' => worship_rank_users($todayUsers)));
}

// 膜拜动作
if ($action === 'worship') {
    require_login();
    $user = current_user();
    check_csrf();
    $objectId = isset($_POST['object_id']) ? $_POST['object_id'] : '';

    $obj = null;
    foreach ($objects as $o) {
        if ($o['id'] === $objectId) { $obj = $o; break; }
    }
    if (!$obj) {
        json_response(array('ok' => false, 'error' => '膜拜对象不存在'), 404);
    }

    // 找到今日记录（没有则新建）
    $idx = -1;
    foreach ($daily as $k => $d) {
        if (isset($d['date']) && $d['date'] === $today) { $idx = $k; break; }
    }
    if ($idx < 0) {
        $daily[] = array('date' => $today, 'users' => array());
        $idx = count($daily) - 1;
    }
    $users = &$daily[$idx]['users'];

    // 防刷：每人每天对同一对象限一次
    foreach ($users as $u) {
        if (isset($u['uid']) && $u['uid'] === $user['id'] && isset($u['object_id']) && $u['object_id'] === $objectId) {
            json_response(array('ok' => false, 'error' => '您今天已经膜拜过啦，明天再来试试手气吧'), 400);
        }
    }

    // 幸运值只记在膜拜者名下，不累加到对象
    $score = rand(1, 100);
    $users[] = array(
        'uid'       => $user['id'],
        'name'      => $user['name'],
        'object_id' => $objectId,
        'score'     => $score,
        'ts'        => date('Y-m-d H:i:s')
    );
    unset($users); // 解除引用

    // 历史归档：仅保留最近 60 天，避免 JSON 无限膨胀
    if (count($daily) > 60) {
        usort($daily, function ($a, $b) { return strcmp($b['date'], $a['date']); });
        $daily = array_slice($daily, 0, 60);
    }

    $worship['objects'] = $objects;
    $worship['daily'] = $daily;
    if (!jdb_write(JSON_DIR . '/worship.json', $worship)) {
        json_response(array('ok' => false, 'error' => '数据保存失败，请稍后重试'), 500);
    }

    // 返回本次幸运值与最新用户幸运榜
    $todayUsers = worship_today_users($daily, $today);
    json_response(array(
        'ok' => true,
        'score' => $score,
        'message' => '膜拜成功！今日幸运值 +' . $score,
        'rank' => worship_rank_users($todayUsers)
    ));
}

json_response(array('ok' => false, 'error' => '未知操作'), 400);
