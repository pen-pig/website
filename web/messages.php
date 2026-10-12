<?php
/**
 * 站内私信页面（洛谷式：左侧会话列表 + 右侧聊天窗）
 * - 会话按对方聚合，显示未读数与最后一条消息
 * - 普通成员只能向管理员、干事发送私信（后端强制拦截）
 * - 管理员/干事可新建会话时多选（群发）
 */
require_once __DIR__ . '/includes/config.php';
require_login();
$user = current_user();

$view = isset($_GET['view']) ? $_GET['view'] : 'conversation';

// ---------------- 发送私信 ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send') {
    check_csrf();
    $content = trim(isset($_POST['content']) ? $_POST['content'] : '');
    $toIds = isset($_POST['to_ids']) && is_array($_POST['to_ids']) ? $_POST['to_ids'] : array();
    $toIds = array_values(array_unique($toIds));
    $backWith = isset($_POST['back_with']) ? $_POST['back_with'] : '';
    $hasImage = isset($_FILES['image']) && is_array($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK;

    if (!$hasImage && $content === '') {
        $_SESSION['flash'] = '私信内容不能为空';
    } elseif (count($toIds) === 0) {
        $_SESSION['flash'] = '请选择收件人';
    } else {
        $users = jdb_collection('users', 'users');
        // 权限过滤：普通成员只能发给 admin/officer
        $allowed = array();
        $isStaff = in_array($user['role'], array('admin', 'officer'));
        foreach ($users as $u) {
            if (!in_array($u['id'], $toIds)) continue;
            if ($u['status'] !== 'active') continue;
            if (!$isStaff && !in_array($u['role'], array('admin', 'officer'))) continue; // 普通成员不能给普通成员发
            $allowed[] = $u;
        }
        if (count($allowed) === 0) {
            $_SESSION['flash'] = '没有可发送的收件人（普通成员只能给管理员/干事发送私信）';
        } else {
            $messages = jdb_collection('messages', 'messages');
            // 图片上传：仅当通过权限校验后处理，避免越权请求残留文件
            $imgUrl = '';
            if ($hasImage) {
                $imgRes = handle_msg_image($_FILES['image']);
                if (!$imgRes['ok']) {
                    $_SESSION['flash'] = $imgRes['error'];
                    redirect('messages.php?view=conversation&with=' . urlencode($backWith));
                }
                $imgUrl = $imgRes['url'];
            }
            foreach ($allowed as $target) {
                $messages[] = array(
                    'id'         => 'm_' . uniqid(),
                    'from_id'    => $user['id'],
                    'from_name'  => $user['name'],
                    'to_id'      => $target['id'],
                    'to_name'    => $target['name'],
                    'content'    => $hasImage ? $imgUrl : $content,
                    'created_at' => date('Y-m-d H:i:s'),
                    'is_read'    => false,
                    'read_at'    => null,
                    'type'       => $hasImage ? 'image' : (count($allowed) > 1 ? 'broadcast' : 'single')
                );
            }
            jdb_save_collection('messages', 'messages', $messages);
            $_SESSION['flash'] = '私信已发送（' . count($allowed) . ' 人）';
            if (count($allowed) === 1 && $allowed[0]['id'] !== $backWith) {
                $backWith = $allowed[0]['id'];
            }
        }
    }
    redirect('messages.php?view=conversation&with=' . urlencode($backWith));
}

$flash = '';
if (isset($_SESSION['flash'])) { $flash = $_SESSION['flash']; unset($_SESSION['flash']); }

// ---------------- 数据准备 ----------------
$messages = jdb_collection('messages', 'messages');
$users = jdb_collection('users', 'users');
$uid = $user['id'];
$isStaff = in_array($user['role'], array('admin', 'officer'));

// 会话聚合：按对方 id 分组
$convs = array(); // peer_id => array('peer'=>.., 'unread'=>N, 'last'=>.., 'time'=>..)
foreach ($messages as $m) {
    if ($m['from_id'] === $uid) {
        $peerId = $m['to_id'];
        $peerName = $m['to_name'];
    } elseif ($m['to_id'] === $uid) {
        $peerId = $m['from_id'];
        $peerName = $m['from_name'];
    } else {
        continue;
    }
    if (!isset($convs[$peerId])) {
        $convs[$peerId] = array(
            'peer'   => array('id' => $peerId, 'name' => $peerName, 'role' => ''),
            'unread' => 0,
            'last'   => '',
            'time'   => ''
        );
    }
    // 取对方用户信息（角色等）
    foreach ($users as $u) {
        if ($u['id'] === $peerId) { $convs[$peerId]['peer'] = $u; break; }
    }
    if ($m['to_id'] === $uid && empty($m['is_read'])) $convs[$peerId]['unread']++;
    if ($m['created_at'] >= $convs[$peerId]['time']) {
        $convs[$peerId]['last'] = (isset($m['type']) && $m['type'] === 'image') ? '[图片]' : $m['content'];
        $convs[$peerId]['time'] = $m['created_at'];
    }
}
// 按最后消息时间倒序
uasort($convs, function ($a, $b) { return strcmp($b['time'], $a['time']); });

$withId = isset($_GET['with']) ? $_GET['with'] : '';
// 默认选中最近会话
if ($withId === '' && count($convs) > 0) {
    $first = reset($convs);
    $withId = $first['peer']['id'];
}
$withUser = $withId !== '' ? jdb_find_by_id($users, $withId) : null;

// 打开会话时标记对方发来的消息为已读
if ($withUser) {
    $changed = false;
    foreach ($messages as $k => $m) {
        if ($m['from_id'] === $withId && $m['to_id'] === $uid && empty($m['is_read'])) {
            $messages[$k]['is_read'] = true;
            $messages[$k]['read_at'] = date('Y-m-d H:i:s');
            $changed = true;
        }
    }
    if ($changed) jdb_save_collection('messages', 'messages', $messages);
}

page_header('站内私信', 'messages');
if ($flash !== '') flash_toast($flash);

echo '<div class="row msg-wrap">';

// ---------------- 左侧：会话列表 ----------------
echo '<div class="col-lg-4 col-md-5">';
echo '<div class="card msg-pane"><div class="card-header d-flex justify-content-between align-items-center">';
echo '<strong>会话</strong><a class="btn btn-sm btn-primary" href="messages.php?view=new">写私信</a>';
echo '</div><div class="list-group list-group-flush msg-list" style="max-height:72vh;overflow:auto;">';
if (count($convs) === 0) {
    echo '<div class="p-3 text-muted small">暂无会话，点击右上角「写私信」发起。</div>';
} else {
    foreach ($convs as $cid => $c) {
        $active = ($cid === $withId) ? ' active' : '';
        $peer = $c['peer'];
        $roleBadge = in_array($peer['role'], array('admin', 'officer')) ? '<span class="msg-role ' . ($peer['role'] === 'admin' ? 'role-admin' : 'role-officer') . '">' . e($peer['role'] === 'admin' ? '管理员' : '干事') . '</span>' : '';
        echo '<a class="list-group-item list-group-item-action msg-item' . $active . '" href="messages.php?view=conversation&amp;with=' . urlencode($cid) . '">';
        echo '<div class="d-flex align-items-center">';
        echo '<span class="msg-avatar">' . e(mb_substr($peer['name'], 0, 1)) . '</span>';
        echo '<div class="ml-2 flex-grow-1" style="min-width:0;">';
        echo '<div class="d-flex justify-content-between"><span class="msg-name">' . e($peer['name']) . '</span><span class="msg-time">' . e(fmt_msg_time($c['time'])) . '</span></div>';
        echo '<div class="msg-last">' . e(mb_substr($c['last'], 0, 28)) . (mb_strlen($c['last']) > 28 ? '…' : '') . '</div>';
        echo '</div>';
        if ($c['unread'] > 0) echo '<span class="msg-unread ml-1">' . $c['unread'] . '</span>';
        echo '</div>';
        if ($roleBadge !== '') echo '<div class="mt-1">' . $roleBadge . '</div>';
        echo '</a>';
    }
}
echo '</div></div></div>';

// ---------------- 右侧：聊天窗 ----------------
echo '<div class="col-lg-8 col-md-7">';

if ($view === 'new') {
    // 新建会话
    echo '<div class="card"><div class="card-header"><strong>发起新会话</strong></div><div class="card-body">';
    echo '<form method="post" action="messages.php" enctype="multipart/form-data">' . csrf_field();
    echo '<input type="hidden" name="action" value="send">';
    if ($isStaff) {
        echo '<div class="form-group"><label>收件人（可多选，群发）</label><div style="max-height:200px;overflow:auto;border:1px solid var(--line);border-radius:6px;padding:10px;">';
        foreach ($users as $u) {
            if ($u['id'] === $uid || $u['status'] !== 'active') continue;
            echo '<div class="form-check"><input class="form-check-input" type="checkbox" name="to_ids[]" value="' . e($u['id']) . '" id="to_' . e($u['id']) . '"><label class="form-check-label" for="to_' . e($u['id']) . '">' . e($u['name']) . '（' . e($u['role']) . '）</label></div>';
        }
        echo '</div>';
        echo '<button type="button" class="btn btn-sm btn-outline-secondary mt-1 mr-1" onclick="$(\'input[name=\\\'to_ids[]\\\']\').prop(\'checked\',true)">全选</button>';
        echo '<button type="button" class="btn btn-sm btn-outline-secondary mt-1" onclick="$(\'input[name=\\\'to_ids[]\\\']\').prop(\'checked\',false)">清空</button></div>';
    } else {
        echo '<div class="form-group"><label>收件人（管理员/干事）</label><select name="to_ids[]" class="form-control">';
        foreach ($users as $u) {
            if ($u['id'] === $uid || $u['status'] !== 'active') continue;
            if (in_array($u['role'], array('admin', 'officer'))) {
                echo '<option value="' . e($u['id']) . '">' . e($u['name']) . '（' . e($u['role']) . '）</option>';
            }
        }
        echo '</select></div>';
    }
    echo '<div class="form-group"><label>内容</label><textarea name="content" class="form-control" rows="4"></textarea></div>';
    echo '<div class="form-group"><label>附带图片（可选，JPG/PNG/GIF/WebP，5MB 内）</label><input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp" class="form-control-file"></div>';
    echo '<button class="btn btn-primary">发送</button> <a class="btn btn-outline-secondary" href="messages.php">取消</a></form>';
    echo '</div></div>';
} elseif (!$withUser) {
    echo '<div class="card"><div class="card-body text-muted small">请选择左侧会话，或发起新会话。</div></div>';
} else {
    // 聊天窗
    $conv = array();
    foreach ($messages as $m) {
        if (($m['from_id'] === $uid && $m['to_id'] === $withId) || ($m['from_id'] === $withId && $m['to_id'] === $uid)) {
            $conv[] = $m;
        }
    }
    usort($conv, function ($a, $b) { return strcmp($a['created_at'], $b['created_at']); });

    // 所有登录用户均可回复（不再限制仅 admin/officer）

    echo '<div class="card"><div class="card-header d-flex justify-content-between align-items-center">';
    echo '<div class="d-flex align-items-center"><span class="msg-avatar mr-2">' . e(mb_substr($withUser['name'], 0, 1)) . '</span><strong>' . e($withUser['name']) . '</strong>';
    echo in_array($withUser['role'], array('admin', 'officer')) ? '<span class="msg-role ml-2 ' . ($withUser['role'] === 'admin' ? 'role-admin' : 'role-officer') . '">' . e($withUser['role'] === 'admin' ? '管理员' : '干事') . '</span>' : '';
    echo '</div><a class="btn btn-sm btn-outline-secondary" href="messages.php?view=new">发新私信</a></div>';

    echo '<div class="card-body msg-chat" style="max-height:60vh;overflow:auto;">';
    if (count($conv) === 0) { echo '<div class="text-muted small text-center py-4">暂无往来消息，发送第一条吧</div>'; }
    $lastDate = '';
    foreach ($conv as $m) {
        $mine = $m['from_id'] === $uid;
        $d = substr($m['created_at'], 0, 10);
        if ($d !== $lastDate) {
            $lastDate = $d;
            echo '<div class="text-center my-2"><span class="msg-date">' . e($d) . '</span></div>';
        }
        echo '<div class="d-flex mb-3 ' . ($mine ? 'justify-content-end' : 'justify-content-start') . '">';
        if (!$mine) {
            echo '<span class="msg-avatar mr-2">' . e(mb_substr($m['from_name'], 0, 1)) . '</span>';
        }
        echo '<div class="msg-bubble-wrap ' . ($mine ? 'text-right' : '') . '" style="max-width:70%;">';
        if (isset($m['type']) && $m['type'] === 'image') {
            echo '<div class="msg-bubble ' . ($mine ? 'bubble-mine' : 'bubble-peer') . '" style="padding:4px;">';
            echo '<a href="' . e($m['content']) . '" target="_blank" title="点击查看原图">';
            echo '<img src="' . e($m['content']) . '" alt="图片消息" style="max-width:260px;max-height:240px;border-radius:8px;display:block;">';
            echo '</a></div>';
        } else {
            echo '<div class="msg-bubble ' . ($mine ? 'bubble-mine' : 'bubble-peer') . '">' . nl2br(e($m['content'])) . '</div>';
        }
        echo '<div class="msg-meta">' . e(substr($m['created_at'], 11, 5)) . ($m['type'] === 'broadcast' ? ' · 群发' : '');
        if (!$mine) {
            echo ' <a href="javascript:;" class="msg-del text-danger" data-id="' . e($m['id']) . '" title="删除这条私信" style="text-decoration:none;font-weight:bold;">×</a>';
        }
        echo '</div>';
        echo '</div>';
        if ($mine) {
            echo '<span class="msg-avatar ml-2">' . e(mb_substr($user['name'], 0, 1)) . '</span>';
        }
        echo '</div>';
    }
    echo '</div>';

    // 底部输入框（所有登录用户均可回复，含图片发送入口）
    echo '<div class="card-footer">';
    echo '<form method="post" action="messages.php" enctype="multipart/form-data">' . csrf_field();
    echo '<input type="hidden" name="action" value="send">';
    echo '<input type="hidden" name="to_ids[]" value="' . e($withId) . '">';
    echo '<input type="hidden" name="back_with" value="' . e($withId) . '">';
    echo '<div class="d-flex">';
    echo '<textarea name="content" class="form-control mr-2" rows="2" placeholder="输入私信内容…（可直接发图片）" style="resize:none;"></textarea>';
    echo '<label class="btn btn-outline-secondary mr-2 mb-0" style="cursor:pointer;white-space:nowrap;">图片<input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none;" onchange="if(this.value){this.parentElement.classList.add(\'btn-primary\');this.parentElement.classList.remove(\'btn-outline-secondary\');}"></label>';
    echo '<button class="btn btn-primary px-4" style="white-space:nowrap;">发送</button>';
    echo '</div></form>';
    echo '</div>';
    echo '</div>';
}

echo '</div></div>';

// 私信删除（仅收件人可删，confirm 后走 api 删除并刷新）
echo '<script>
document.addEventListener("click", function (e) {
    var t = e.target;
    if (!t.classList || !t.classList.contains("msg-del")) return;
    if (!confirm("确定删除这条私信？删除后不可恢复。")) return;
    var xhr = new XMLHttpRequest();
    xhr.open("POST", "api/messages.php?action=delete");
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    xhr.onload = function () {
        var res = {};
        try { res = JSON.parse(xhr.responseText); } catch (err) {}
        if (xhr.status === 401) { location.href = "login.php"; return; }
        if (xhr.status === 200 && res.ok) { location.reload(); return; }
        alert((res && res.error) || "删除失败");
    };
    xhr.onerror = function () { alert("网络错误，请重试"); };
    xhr.send("id=" + encodeURIComponent(t.getAttribute("data-id")) + "&csrf_token=' . csrf_token() . '");
});
</script>';

page_footer();

/**
 * 私信图片上传处理：白名单扩展名 + 5MB 限制 + 随机命名存入 data/img/msg/
 * 返回：ok=true 时 url 为可访问图片地址（img.php?type=msg&file=...）
 */
function handle_msg_image($f) {
    $maxSize = 5 * 1024 * 1024;
    if ($f['error'] !== UPLOAD_ERR_OK) {
        return array('ok' => false, 'error' => '图片上传失败，请重试');
    }
    if ($f['size'] <= 0 || $f['size'] > $maxSize) {
        return array('ok' => false, 'error' => '图片大小需在 5MB 以内');
    }
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $allowed = array('jpg', 'jpeg', 'png', 'gif', 'webp');
    if (!in_array($ext, $allowed)) {
        return array('ok' => false, 'error' => '仅支持 JPG/PNG/GIF/WebP 图片');
    }
    $dir = DATA_DIR . '/img/msg';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $stored = date('YmdHis') . '_' . uniqid() . '.' . $ext;
    if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $stored)) {
        return array('ok' => false, 'error' => '图片保存失败，请检查 data/img/msg 目录权限');
    }
    return array('ok' => true, 'url' => 'img.php?type=msg&file=' . rawurlencode($stored));
}

/**
 * 会话列表时间格式化：今天显示 HH:MM，昨天显示 昨天，更早显示 M-D
 */
function fmt_msg_time($ts) {
    if ($ts === '') return '';
    $t = strtotime($ts);
    if ($t === false) return $ts;
    $today = strtotime(date('Y-m-d'));
    $diff = $today - strtotime(date('Y-m-d', $t));
    if ($diff === 0) return date('H:i', $t);
    if ($diff === 86400) return '昨天';
    return date('m-d', $t);
}
