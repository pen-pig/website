<?php
/**
 * OJ 管理（干事：人工评测；管理员：题目与测试数据编辑）
 * - tab=judge  人工评测队列（verdict=manual 的提交，标记 AC/WA/自定义分数）
 * - tab=problems 题目编辑（标题/难度/题面）+ 测试数据增删（补录后自动转"自动评测"）
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/oj_lib.php';
require_role(array('admin', 'officer'));
$user = current_user();
$isAdmin = $user['role'] === 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    // ---- 人工评测打分（干事即可） ----
    if ($action === 'grade') {
        $sid = isset($_POST['sid']) ? $_POST['sid'] : '';
        $v = isset($_POST['verdict']) ? $_POST['verdict'] : '';
        $score = isset($_POST['score']) ? max(0, min(100, (int)$_POST['score'])) : 0;
        if (!in_array($v, array('AC', 'WA'), true)) redirect('admin/oj.php');
        $subs = jdb_collection('oj_submissions', 'submissions');
        foreach ($subs as $i => $s) {
            if (isset($s['id']) && $s['id'] === $sid) {
                $subs[$i]['verdict'] = $v;
                $subs[$i]['score'] = ($v === 'AC') ? 100 : $score;
                $subs[$i]['judged_at'] = date('Y-m-d H:i:s');
                $subs[$i]['grader'] = $user['name'];
                break;
            }
        }
        jdb_save_collection('oj_submissions', 'submissions', $subs);
        log_action('oj_grade', '人工评测 ' . $sid . ' → ' . $v . '（' . $score . ' 分）');
        $_SESSION['flash'] = '已批改';
        redirect('admin/oj.php?tab=judge');
    }

    // ---- 以下操作仅管理员 ----
    if (!$isAdmin) redirect('admin/oj.php');

    if ($action === 'save_problem') {
        $pid = isset($_POST['pid']) ? trim($_POST['pid']) : '';
        $list = oj_load_problems();
        foreach ($list as $i => $p) {
            if ($p['id'] === $pid) {
                if (isset($_POST['title'])) $list[$i]['title'] = mb_substr(trim($_POST['title']), 0, 80, 'UTF-8');
                if (isset($_POST['diff'])) $list[$i]['diff'] = max(1, min(5, (int)$_POST['diff']));
                if (isset($_POST['time_limit'])) $list[$i]['time_limit'] = max(200, min(10000, (int)$_POST['time_limit']));
                if (isset($_POST['statement_md'])) {
                    $list[$i]['statement_md'] = str_replace("\r\n", "\n", $_POST['statement_md']);
                }
                break;
            }
        }
        jdb_save_collection('oj_problems', 'problems', $list);
        log_action('oj_edit_problem', '编辑题目 ' . $pid);
        $_SESSION['flash'] = '题目已保存';
        redirect('admin/oj.php?tab=problems&pid=' . urlencode($pid));
    }

    if ($action === 'add_test') {
        $pid = isset($_POST['pid']) ? trim($_POST['pid']) : '';
        $in = isset($_POST['tin']) ? str_replace("\r\n", "\n", $_POST['tin']) : '';
        $out = isset($_POST['tout']) ? str_replace("\r\n", "\n", $_POST['tout']) : '';
        $problem = oj_find_problem($pid);
        if ($problem === null || $in === '' || !isset($out[0])) {
            $_SESSION['flash'] = '输入不能为空';
            redirect('admin/oj.php?tab=problems&pid=' . urlencode($pid));
        }
        if (strlen($in) > 102400 || strlen($out) > 102400) {
            $_SESSION['flash'] = '单组测试数据不能超过 100KB';
            redirect('admin/oj.php?tab=problems&pid=' . urlencode($pid));
        }
        $n = (int)$problem['ntests'] + 1;
        if ($n > 50) {
            $_SESSION['flash'] = '每题最多 50 组测试数据';
            redirect('admin/oj.php?tab=problems&pid=' . urlencode($pid));
        }
        $d = DATA_DIR . '/oj/tests/' . $pid;
        if (!is_dir($d)) @mkdir($d, 0755, true);
        file_put_contents($d . '/t' . $n . '.in', $in . (substr($in, -1) === "\n" ? '' : "\n"));
        file_put_contents($d . '/t' . $n . '.out', $out . (substr($out, -1) === "\n" ? '' : "\n"));
        $list = oj_load_problems();
        foreach ($list as $i => $p) {
            if ($p['id'] === $pid) {
                $list[$i]['ntests'] = $n;
                $list[$i]['status'] = 'ready';
                break;
            }
        }
        jdb_save_collection('oj_problems', 'problems', $list);
        log_action('oj_add_test', '题目 ' . $pid . ' 新增测试点 ' . $n);
        $_SESSION['flash'] = '测试点 ' . $n . ' 已添加，本题已开启自动评测';
        redirect('admin/oj.php?tab=problems&pid=' . urlencode($pid));
    }

    if ($action === 'del_test') {
        $pid = isset($_POST['pid']) ? trim($_POST['pid']) : '';
        $t = (int)$_POST['t'];
        $problem = oj_find_problem($pid);
        if ($problem !== null && $t >= 1 && $t <= (int)$problem['ntests']) {
            $d = DATA_DIR . '/oj/tests/' . $pid;
            // 删除第 t 组，其余前移重编号
            $files = array();
            for ($i = 1; $i <= (int)$problem['ntests']; $i++) {
                if ($i === $t) continue;
                $files[] = array($d . '/t' . $i . '.in', $d . '/t' . $i . '.out');
            }
            foreach (glob($d . '/t*.in') ?: array() as $f) @unlink($f);
            foreach (glob($d . '/t*.out') ?: array() as $f) @unlink($f);
            foreach ($files as $ni => $pair) {
                @rename($pair[0], $d . '/t' . ($ni + 1) . '.in.tmp');
                @rename($pair[1], $d . '/t' . ($ni + 1) . '.out.tmp');
            }
            foreach (glob($d . '/*.tmp') ?: array() as $f) {
                @rename($f, substr($f, 0, -4));
            }
            $n = count($files);
            $list = oj_load_problems();
            foreach ($list as $i => $p) {
                if ($p['id'] === $pid) {
                    $list[$i]['ntests'] = $n;
                    $list[$i]['status'] = $n > 0 ? 'ready' : 'manual';
                    break;
                }
            }
            jdb_save_collection('oj_problems', 'problems', $list);
            log_action('oj_del_test', '题目 ' . $pid . ' 删除测试点 ' . $t);
            $_SESSION['flash'] = '测试点已删除';
        }
        redirect('admin/oj.php?tab=problems&pid=' . urlencode($pid));
    }
    redirect('admin/oj.php');
}

$tab = isset($_GET['tab']) ? $_GET['tab'] : 'judge';
page_header('OJ 管理', 'oj');
echo '<ul class="nav nav-tabs mb-4">';
echo '<li class="nav-item"><a class="nav-link' . ($tab === 'judge' ? ' active' : '') . '" href="/admin/oj.php?tab=judge">人工评测队列</a></li>';
if ($isAdmin) echo '<li class="nav-item"><a class="nav-link' . ($tab === 'problems' ? ' active' : '') . '" href="/admin/oj.php?tab=problems">题目与测试数据</a></li>';
echo '</ul>';

if ($tab === 'problems' && $isAdmin) {
    // ---------- 题目与测试数据 ----------
    $problems = oj_load_problems();
    usort($problems, function ($a, $b) { return strcmp($a['id'], $b['id']); });
    $cur = null;
    $pid = isset($_GET['pid']) ? $_GET['pid'] : '';
    if ($pid !== '') $cur = oj_find_problem($pid);
    echo '<div class="row"><div class="col-lg-4">';
    echo '<div class="card"><div class="card-header"><strong>选择题目</strong></div><div class="card-body p-0">';
    echo '<div class="list-group list-group-flush" style="max-height:560px;overflow-y:auto;">';
    foreach ($problems as $p) {
        $sel = ($cur !== null && $p['id'] === $cur['id']);
        echo '<a class="list-group-item list-group-item-action small d-flex justify-content-between' . ($sel ? ' active' : '') . '" href="/admin/oj.php?tab=problems&amp;pid=' . urlencode($p['id']) . '">';
        echo '<span>' . e($p['id']) . ' ' . e(mb_substr($p['title'], 0, 18, 'UTF-8')) . '</span>';
        echo '<span class="badge badge-light">' . ($p['status'] === 'ready' ? (int)$p['ntests'] . ' 点' : '无') . '</span></a>';
    }
    echo '</div></div></div>';
    echo '</div><div class="col-lg-8">';
    if ($cur === null) {
        echo '<div class="alert alert-info">从左侧选择一道题目进行编辑。</div>';
    } else {
        echo '<div class="card mb-4"><div class="card-header"><strong>编辑题目 · ' . e($cur['id']) . '</strong></div><div class="card-body">';
        echo '<form method="post" action="/admin/oj.php">' . csrf_field();
        echo '<input type="hidden" name="action" value="save_problem">';
        echo '<input type="hidden" name="pid" value="' . e($cur['id']) . '">';
        echo '<div class="form-row"><div class="form-group col-md-6"><label>题名</label><input type="text" name="title" class="form-control" value="' . e($cur['title']) . '"></div>';
        echo '<div class="form-group col-md-3"><label>难度 1-5</label><input type="number" name="diff" class="form-control" min="1" max="5" value="' . (int)$cur['diff'] . '"></div>';
        echo '<div class="form-group col-md-3"><label>时限 ms</label><input type="number" name="time_limit" class="form-control" min="200" max="10000" step="100" value="' . (int)$cur['time_limit'] . '"></div></div>';
        echo '<div class="form-group"><label>题面（Markdown，支持 ```text 代码块写样例）</label><textarea name="statement_md" class="form-control font-monospace" rows="14" style="font-size:13px;">' . e($cur['statement_md']) . '</textarea></div>';
        echo '<button class="btn btn-primary">保存题面</button>';
        echo '</form></div></div>';

        // 测试数据
        echo '<div class="card"><div class="card-header d-flex justify-content-between"><strong>测试数据（' . (int)$cur['ntests'] . ' 组）</strong><span class="badge badge-light">' . ($cur['status'] === 'ready' ? '自动评测' : '人工评测') . '</span></div><div class="card-body">';
        if ($cur['ntests'] > 0) {
            echo '<div class="mb-3">';
            for ($i = 1; $i <= (int)$cur['ntests']; $i++) {
                echo '<span class="border rounded px-2 py-1 mr-2 mb-2 d-inline-block small">测试点 ' . $i;
                echo ' <form method="post" action="/admin/oj.php" style="display:inline;" onsubmit="return confirm(\'删除测试点 ' . $i . '？\')">' . csrf_field();
                echo '<input type="hidden" name="action" value="del_test"><input type="hidden" name="pid" value="' . e($cur['id']) . '"><input type="hidden" name="t" value="' . $i . '">';
                echo '<button class="btn btn-link btn-sm p-0 text-danger">删除</button></form></span>';
            }
            echo '</div>';
        } else {
            echo '<p class="small text-muted">尚无测试数据。补录第一组后，本题自动切换为<b>自动评测</b>。</p>';
        }
        echo '<form method="post" action="/admin/oj.php">' . csrf_field();
        echo '<input type="hidden" name="action" value="add_test">';
        echo '<input type="hidden" name="pid" value="' . e($cur['id']) . '">';
        echo '<div class="form-row"><div class="form-group col-md-6"><label>输入（t.in）</label><textarea name="tin" class="form-control font-monospace" rows="6" style="font-size:13px;" required></textarea></div>';
        echo '<div class="form-group col-md-6"><label>期望输出（t.out）</label><textarea name="tout" class="form-control font-monospace" rows="6" style="font-size:13px;" required></textarea></div></div>';
        echo '<button class="btn btn-outline-primary btn-sm">追加测试点</button>';
        echo '</form>';
        echo '</div></div>';
    }
    echo '</div></div>';
} else {
    // ---------- 人工评测队列 ----------
    $subs = jdb_collection('oj_submissions', 'submissions');
    usort($subs, function ($a, $b) { return strcmp($b['created_at'], $a['created_at']); });
    echo '<div class="card"><div class="card-header"><strong>待人工评测（提交后无测试数据/编译器的）</strong></div><div class="card-body p-0">';
    $queue = array();
    foreach ($subs as $s) {
        if ($s['verdict'] === 'manual') $queue[] = $s;
    }
    if (count($queue) === 0) {
        echo '<div class="p-3 text-muted small">队列为空，没有待批改的提交。</div>';
    } else {
        foreach ($queue as $s) {
            $p = oj_find_problem($s['pid']);
            echo '<div class="border-bottom p-3">';
            echo '<div class="d-flex justify-content-between align-items-center flex-wrap mb-2">';
            echo '<span><strong>' . e($s['pid']) . '</strong> · ' . e($p !== null ? $p['title'] : '') . ' — <span class="text-muted small">' . e($s['name']) . ' · ' . e($s['created_at']) . '</span>';
            echo ' <a class="small" href="/oj.php?view=sub&amp;id=' . urlencode($s['id']) . '" target="_blank">查看代码 ↗</a></span>';
            echo '<span class="oj-badge vj-manual">' . e(oj_verdict_label('manual')) . '</span>';
            echo '</div>';
            echo '<form method="post" action="/admin/oj.php" class="form-inline">' . csrf_field();
            echo '<input type="hidden" name="action" value="grade"><input type="hidden" name="sid" value="' . e($s['id']) . '">';
            echo '<select name="verdict" class="form-control form-control-sm mr-2"><option value="AC">通过 AC</option><option value="WA">不通过 WA</option></select>';
            echo '<input type="number" name="score" class="form-control form-control-sm mr-2" min="0" max="100" value="0" style="max-width:90px;" title="WA 时的分数">';
            echo '<button class="btn btn-sm btn-primary">提交批改</button>';
            echo '</form></div>';
        }
    }
    echo '</div></div>';
}
page_footer();
