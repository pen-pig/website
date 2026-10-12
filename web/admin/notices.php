<?php
/**
 * 通知管理后台（管理员 / 干事）
 * 发布/编辑通知（Markdown + 可选附件），列表，删除
 * 表单 POST 到 /api/upload.php?action=notice 统一处理（含附件上传）
 */
require_once __DIR__ . '/../includes/config.php';
$user = require_role(array('admin', 'officer'));

// 删除通知
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    check_csrf();
    $id = isset($_POST['id']) ? $_POST['id'] : '';
    $notices = jdb_collection('notices', 'notices');
    $newList = array();
    $delTitle = '';
    foreach ($notices as $n) {
        if ($n['id'] !== $id) {
            $newList[] = $n;
        } else {
            $delTitle = isset($n['title']) ? $n['title'] : '(无标题)';
        }
    }
    jdb_save_collection('notices', 'notices', $newList);
    add_log('删除公告', '公告：' . $delTitle);
    $_SESSION['flash'] = '通知已删除';
    redirect('notices.php');
}

$flash = '';
if (isset($_SESSION['flash'])) { $flash = $_SESSION['flash']; unset($_SESSION['flash']); }

$notices = jdb_collection('notices', 'notices');
usort($notices, function ($a, $b) {
    $pa = !empty($a['pinned']) ? 1 : 0;
    $pb = !empty($b['pinned']) ? 1 : 0;
    if ($pa !== $pb) return $pb - $pa;
    return strcmp($b['created_at'], $a['created_at']);
});

// 编辑模式：读取要编辑的通知
$editN = null;
$editId = isset($_GET['edit']) ? $_GET['edit'] : '';
if ($editId !== '') {
    $editN = jdb_find_by_id($notices, $editId);
}

page_header('通知管理', 'admin');
if ($flash !== '') flash_toast($flash);

echo '<div class="row">';
echo '<div class="col-lg-5">';
echo '<div class="card mb-4"><div class="card-header"><strong>' . ($editN ? '编辑通知' : '发布通知') . '</strong></div><div class="card-body">';
echo '<form method="post" action="/api/upload.php?action=notice" enctype="multipart/form-data">' . csrf_field();
if ($editN) {
    echo '<input type="hidden" name="edit_id" value="' . e($editN['id']) . '">';
}
echo '<div class="form-group"><label>标题 *</label><input type="text" name="title" class="form-control" value="' . ($editN ? e($editN['title']) : '') . '" required></div>';
echo '<div class="form-group"><label>类型</label><select name="type" class="form-control">';
$typeOpts = array('public' => '对外公告', 'internal_activity' => '内部通知-活动', 'internal_class' => '内部通知-上课');
foreach ($typeOpts as $tv => $tl) {
    $sel = $editN && $editN['type'] === $tv ? ' selected' : '';
    echo '<option value="' . $tv . '"' . $sel . '>' . $tl . '</option>';
}
echo '</select></div>';
echo '<div class="form-group"><label>内容（支持 Markdown）</label><textarea name="content_md" class="form-control md-input" rows="8">' . ($editN ? e($editN['content_md']) : '') . '</textarea></div>';
echo '<div class="form-group"><label>附件（可选）</label><input type="file" name="attachment" class="form-control-file">';
if ($editN && !empty($editN['attachments'])) {
    echo '<div class="small text-muted mt-1">已有附件：';
    foreach ($editN['attachments'] as $a) {
        echo e($a['orig']) . '（' . round($a['size'] / 1024) . ' KB） ';
    }
    echo '</div>';
}
echo '</div>';
echo '<div class="form-check mb-3"><input type="checkbox" name="pinned" value="1" class="form-check-input" id="pinned"' . ($editN && !empty($editN['pinned']) ? ' checked' : '') . '><label class="form-check-label" for="pinned">置顶显示</label></div>';
echo '<button class="btn btn-primary">' . ($editN ? '保存修改' : '发布') . '</button> ';
if ($editN) echo '<a class="btn btn-outline-secondary" href="notices.php">取消编辑</a>';
echo '</form></div></div></div>';

echo '<div class="col-lg-7">';
echo '<div class="card"><div class="card-header"><strong>通知列表（' . count($notices) . '）</strong></div><div class="card-body p-0">';
if (count($notices) === 0) { echo '<div class="p-3 text-muted small">暂无通知</div>'; }
else {
    echo '<ul class="list-group list-group-flush">';
    foreach ($notices as $n) {
        $typeName = array('public' => '公告', 'internal_activity' => '活动', 'internal_class' => '上课');
        $tn = isset($typeName[$n['type']]) ? $typeName[$n['type']] : $n['type'];
        echo '<li class="list-group-item"><div class="d-flex justify-content-between align-items-center">';
        echo '<div><span class="badge ' . ($n['type'] === 'public' ? 'badge-primary' : 'badge-warning') . ' mr-1">' . $tn . '</span>';
        if (!empty($n['pinned'])) echo '<span class="badge badge-danger mr-1">置顶</span>';
        if (!empty($n['attachments'])) echo '<span class="badge badge-info mr-1">附件 ' . count($n['attachments']) . '</span>';
        echo '<strong>' . e($n['title']) . '</strong><div class="small text-muted">' . e($n['creator_name']) . ' ｜ ' . e($n['created_at']) . ' ｜ 已读 ' . count($n['read_by']) . ' 人</div></div>';
        echo '<div>';
        echo '<a class="btn btn-sm btn-outline-primary mr-1" href="notices.php?edit=' . urlencode($n['id']) . '">编辑</a>';
        echo '<form method="post" action="notices.php" class="d-inline">' . csrf_field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . e($n['id']) . '"><button class="btn btn-sm btn-outline-danger" onclick="return confirm(\'确定删除该通知？\')">删除</button></form>';
        echo '</div></div></li>';
    }
    echo '</ul>';
}
echo '</div></div>';
echo '</div></div>';

page_footer();
