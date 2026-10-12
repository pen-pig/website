<?php
/**
 * 问卷管理后台（管理员 / 干事）
 * - 管理员：管理所有问卷（删除/开关/导出）
 * - 干事：仅管理自己创建的问卷
 */
require_once __DIR__ . '/../includes/config.php';
$user = require_role(array('admin', 'officer'));
$isAdmin = $user['role'] === 'admin';

// ---------------- POST 处理 ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $sid = isset($_POST['survey_id']) ? $_POST['survey_id'] : '';
    $surveys = jdb_collection('surveys', 'surveys');

    if ($action === 'toggle') {
        foreach ($surveys as $k => $sv) {
            $mine = $sv['created_by'] === $user['id'];
            if ($sv['id'] === $sid && ($mine || $isAdmin)) {
                $surveys[$k]['status'] = $sv['status'] === 'open' ? 'closed' : 'open';
                add_log('切换问卷状态', '问卷：' . (isset($sv['title']) ? $sv['title'] : '(无标题)') . ' → ' . ($surveys[$k]['status'] === 'open' ? '开放' : '关闭'));
                $_SESSION['flash'] = $surveys[$k]['status'] === 'open' ? '问卷已重新开放' : '问卷已关闭';
                break;
            }
        }
    }
    if ($action === 'delete') {
        $newList = array();
        $delTitle = '';
        foreach ($surveys as $sv) {
            $mine = $sv['created_by'] === $user['id'];
            if (!($sv['id'] === $sid && ($mine || $isAdmin))) {
                $newList[] = $sv;
            } else {
                $delTitle = isset($sv['title']) ? $sv['title'] : '(无标题)';
            }
        }
        if (count($newList) !== count($surveys)) {
            add_log('删除问卷', '问卷：' . $delTitle);
            $_SESSION['flash'] = '问卷已删除';
        } else {
            $_SESSION['flash'] = '无权操作该问卷';
        }
        $surveys = $newList;
    }
    jdb_save_collection('surveys', 'surveys', $surveys);
    redirect('surveys.php');
}

// 导出 CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    check_csrf();
    $sid = isset($_GET['id']) ? $_GET['id'] : '';
    $surveys = jdb_collection('surveys', 'surveys');
    $sv = jdb_find_by_id($surveys, $sid);
    $mine = $sv && $sv['created_by'] === $user['id'];
    if (!$sv || (!($mine || $isAdmin))) { die('无权导出'); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="survey_' . $sv['id'] . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $head = array('序号', '提交时间');
    if ($sv['name_field']) $head[] = '姓名';
    foreach ($sv['questions'] as $q) $head[] = $q['title'];
    fputcsv($out, $head);
    $i = 1;
    foreach ($sv['votes'] as $v) {
        $row = array($i, $v['created_at']);
        if ($sv['name_field']) $row[] = $v['name'];
        foreach ($sv['questions'] as $q) {
            $val = isset($v['answers'][$q['id']]) ? $v['answers'][$q['id']] : '';
            if (is_array($val)) $val = implode(' / ', $val);
            $row[] = $val;
        }
        fputcsv($out, $row);
        $i++;
    }
    fclose($out);
    exit;
}

$flash = '';
if (isset($_SESSION['flash'])) { $flash = $_SESSION['flash']; unset($_SESSION['flash']); }

$surveys = jdb_collection('surveys', 'surveys');
usort($surveys, function ($a, $b) { return strcmp($b['created_at'], $a['created_at']); });

page_header('问卷管理', 'admin');
if ($flash !== '') flash_toast($flash);

echo '<div class="card"><div class="card-header"><strong>问卷列表（' . count($surveys) . '）</strong></div><div class="card-body p-0">';
if (count($surveys) === 0) { echo '<div class="p-3 text-muted small">暂无问卷，成员可在前台创建问卷。</div>'; }
else {
    echo '<div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>标题</th><th>创建者</th><th>状态</th><th>参与</th><th>创建时间</th><th style="width:300px;">操作</th></tr></thead><tbody>';
    foreach ($surveys as $sv) {
        $expired = $sv['deadline'] !== '' && strtotime($sv['deadline']) < time();
        if ($sv['status'] === 'closed') {
            $st = '<span class="badge badge-secondary">已关闭</span>';
        } elseif ($expired) {
            $st = '<span class="badge badge-warning">已截止</span>';
        } else {
            $st = '<span class="badge badge-success">进行中</span>';
        }
        $mine = $sv['created_by'] === $user['id'];
        $canManage = $mine || $isAdmin;
        echo '<tr><td><strong>' . e($sv['title']) . '</strong><div class="small text-muted">' . ($sv['anonymous'] ? '匿名' : '实名') . ' ｜ 截止 ' . e($sv['deadline'] !== '' ? $sv['deadline'] : '不限') . '</div></td>';
        echo '<td>' . e($sv['creator_name']) . ($mine ? ' <span class="badge badge-light">我的</span>' : '') . '</td><td>' . $st . '</td><td>' . count($sv['votes']) . ' 人</td><td class="small text-muted">' . e($sv['created_at']) . '</td><td>';
        echo '<a class="btn btn-sm btn-outline-primary mr-1" href="../index.php?view=survey&amp;id=' . urlencode($sv['id']) . '">查看</a>';
        if ($canManage) {
            echo '<form method="post" action="surveys.php" class="d-inline mr-1">' . csrf_field() . '<input type="hidden" name="action" value="toggle"><input type="hidden" name="survey_id" value="' . e($sv['id']) . '"><button class="btn btn-sm btn-outline-warning">' . ($sv['status'] === 'open' ? '关闭' : '重开') . '</button></form>';
            echo '<a class="btn btn-sm btn-outline-success mr-1" href="surveys.php?export=csv&amp;id=' . urlencode($sv['id']) . '&amp;token=' . urlencode($_SESSION['csrf_token']) . '">导出</a>';
            echo '<form method="post" action="surveys.php" class="d-inline">' . csrf_field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="survey_id" value="' . e($sv['id']) . '"><button class="btn btn-sm btn-outline-danger" onclick="return confirm(\'确定删除该问卷？\')">删除</button></form>';
        } else {
            echo '<span class="text-muted small">仅创建者可管理</span>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</div></div>';

page_footer();
