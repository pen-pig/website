<?php
/**
 * OJ 在线评测（自研）
 * - 题单：来源筛选（浅入浅出/GESP/CSP-J/CSP-S）+ 难度 + 关键字
 * - 题目：Markdown 题面 + 样例 + 在线提交（C++14 / C11）
 * - 评测：本机 g++ 自动评测；无编译器或无测试数据 → 人工评测队列
 * - 状态：提交记录列表 + 逐测试点结果详情
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/oj_lib.php';
require_login();
$user = current_user();

// ---- 提交 ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    if ($action !== 'submit') redirect('oj.php');
    $pid = isset($_POST['pid']) ? trim($_POST['pid']) : '';
    $lang = isset($_POST['lang']) ? $_POST['lang'] : 'cpp14';
    $code = isset($_POST['code']) ? $_POST['code'] : '';
    $problem = oj_find_problem($pid);
    if ($problem === null) {
        $_SESSION['flash'] = '题目不存在';
        redirect('oj.php');
    }
    if (!in_array($lang, array('cpp14', 'c'), true)) $lang = 'cpp14';
    $code = str_replace("\r\n", "\n", $code);
    if (trim($code) === '') {
        $_SESSION['flash'] = '代码不能为空';
        redirect('oj.php?view=problem&id=' . urlencode($pid));
    }
    if (strlen($code) > 65536) {
        $_SESSION['flash'] = '代码超过 64KB 上限';
        redirect('oj.php?view=problem&id=' . urlencode($pid));
    }
    $subs = jdb_collection('oj_submissions', 'submissions');
    $sid = 'sub' . date('ymd') . '_' . bin2hex(openssl_random_pseudo_bytes(4));
    $subs[] = array(
        'id' => $sid, 'pid' => $pid, 'uid' => $user['id'], 'name' => $user['name'],
        'lang' => $lang, 'code' => $code, 'verdict' => 'pending', 'score' => 0,
        'cases' => array(), 'created_at' => date('Y-m-d H:i:s')
    );
    jdb_save_collection('oj_submissions', 'submissions', $subs);
    log_action('oj_submit', '提交 ' . $pid);
    oj_judge_submission($sid);
    redirect('oj.php?view=sub&id=' . urlencode($sid));
}

$view = isset($_GET['view']) ? $_GET['view'] : '';

// ================= 题目详情 =================
if ($view === 'problem') {
    $pid = isset($_GET['id']) ? $_GET['id'] : '';
    $problem = oj_find_problem($pid);
    if ($problem === null) {
        page_header('题目不存在', 'oj');
        echo '<div class="alert alert-warning">题目不存在。</div><a class="btn btn-outline-primary" href="/oj.php">← 返回题单</a>';
        page_footer();
        exit;
    }
    page_header('OJ · ' . $problem['title'], 'oj');
    echo '<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="/oj.php">OJ 训练</a></li><li class="breadcrumb-item active">' . e($problem['id']) . '</li></ol></nav>';
    echo '<div class="row"><div class="col-lg-8">';
    echo '<div class="card mb-4"><div class="card-header d-flex justify-content-between align-items-center flex-wrap">';
    echo '<strong>' . e($problem['id']) . ' · ' . e($problem['title']) . '</strong>';
    echo '<span><span class="badge badge-light">' . e(oj_source_label($problem['source'])) . '</span> ';
    echo '<span class="badge badge-light">难度 ' . (int)$problem['diff'] . '</span> ';
    echo '<span class="badge badge-light">时限 ' . (int)$problem['time_limit'] . 'ms</span> ';
    echo '<span class="badge badge-light">' . ($problem['status'] === 'ready' ? '自动评测' : '待补测试数据') . '</span></span>';
    echo '</div><div class="card-body markdown-body">' . markdown_parse($problem['statement_md']) . '</div></div>';

    // 我的提交记录
    $mine = array();
    foreach (jdb_collection('oj_submissions', 'submissions') as $s) {
        if ($s['pid'] === $pid && $s['uid'] === $user['id']) $mine[] = $s;
    }
    usort($mine, function ($a, $b) { return strcmp($b['created_at'], $a['created_at']); });
    echo '<div class="card mb-4"><div class="card-header"><strong>我的提交（' . count($mine) . '）</strong></div><div class="card-body p-0">';
    if (count($mine) === 0) {
        echo '<div class="p-3 text-muted small">还没有提交过本题。</div>';
    } else {
        echo '<div class="list-group list-group-flush">';
        foreach (array_slice($mine, 0, 10) as $s) {
            echo '<a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="/oj.php?view=sub&amp;id=' . urlencode($s['id']) . '">';
            echo '<span class="small text-muted">' . e($s['created_at']) . '</span>';
            echo '<span class="oj-badge ' . oj_verdict_class($s['verdict']) . '">' . e(oj_verdict_label($s['verdict'])) . '</span>';
            echo '<span class="small">' . (int)$s['score'] . ' 分</span></a>';
        }
        echo '</div>';
    }
    echo '</div></div>';
    echo '</div><div class="col-lg-4">';

    // 提交表单
    echo '<div class="card"><div class="card-header"><strong>提交代码</strong></div><div class="card-body">';
    if ($problem['status'] !== 'ready') {
        echo '<div class="alert alert-info py-2 small">本题暂无测试数据，提交后进入<b>人工评测</b>队列。</div>';
    } elseif (!oj_compiler_available()) {
        echo '<div class="alert alert-warning py-2 small">服务器未检测到 g++ 编译器，暂无法自动评测，提交后进入人工评测队列。</div>';
    }
    echo '<form method="post" action="/oj.php">' . csrf_field();
    echo '<input type="hidden" name="action" value="submit">';
    echo '<input type="hidden" name="pid" value="' . e($problem['id']) . '">';
    echo '<div class="form-group"><label>语言</label><select name="lang" class="form-control">';
    echo '<option value="cpp14">C++14（g++ -O2）</option><option value="c">C11（gcc -O2）</option>';
    echo '</select></div>';
    echo '<div class="form-group"><label>代码（≤64KB）</label><textarea name="code" class="form-control font-monospace" rows="16" style="font-size:13px;background:#0d1117;color:#c9d1d9;border-radius:10px;" placeholder="#include &lt;bits/stdc++.h&gt;&#10;using namespace std;&#10;int main(){&#10;    ...&#10;    return 0;&#10;}" required></textarea></div>';
    echo '<button class="btn btn-primary btn-block">提交评测</button>';
    echo '</form></div></div>';
    echo '</div></div>';
    page_footer();
    exit;
}

// ================= 提交详情 =================
if ($view === 'sub') {
    $sid = isset($_GET['id']) ? $_GET['id'] : '';
    $sub = null;
    foreach (jdb_collection('oj_submissions', 'submissions') as $s) {
        if (isset($s['id']) && $s['id'] === $sid) { $sub = $s; break; }
    }
    if ($sub === null) {
        page_header('记录不存在', 'oj');
        echo '<div class="alert alert-warning">提交记录不存在。</div><a class="btn btn-outline-primary" href="/oj.php?view=status">← 返回状态页</a>';
        page_footer();
        exit;
    }
    // 只有本人、干事、管理员可看代码
    if ($sub['uid'] !== $user['id'] && !in_array($user['role'], array('admin', 'officer'))) {
        page_header('无权限', 'oj');
        echo '<div class="alert alert-warning">只能查看自己的提交代码。</div>';
        page_footer();
        exit;
    }
    $problem = oj_find_problem($sub['pid']);
    page_header('评测记录 · ' . $sub['pid'], 'oj');
    echo '<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="/oj.php?view=status">评测状态</a></li><li class="breadcrumb-item active">' . e($sub['id']) . '</li></ol></nav>';
    echo '<div class="card mb-4"><div class="card-header d-flex justify-content-between align-items-center flex-wrap">';
    echo '<span><strong>' . e($sub['pid']) . '</strong>';
    if ($problem !== null) echo ' · ' . e($problem['title']);
    echo ' <a class="small ml-2" href="/oj.php?view=problem&amp;id=' . urlencode($sub['pid']) . '">查看题目</a></span>';
    echo '<span><span class="oj-badge ' . oj_verdict_class($sub['verdict']) . '">' . e(oj_verdict_label($sub['verdict'])) . '</span> ';
    echo '<strong>' . (int)$sub['score'] . ' 分</strong></span>';
    echo '</div><div class="card-body">';
    echo '<p class="small text-muted mb-2">提交人：' . e($sub['name']) . ' · ' . e($sub['created_at']) . ' · ' . ($sub['lang'] === 'c' ? 'C11' : 'C++14');
    if (!empty($sub['judged_at'])) echo ' · 评测于 ' . e($sub['judged_at']);
    echo '</p>';
    if (!empty($sub['cases'])) {
        echo '<div class="mb-2">';
        foreach ($sub['cases'] as $i => $c) {
            echo '<span class="oj-badge ' . oj_verdict_class($c['v']) . '" title="' . (int)$c['ms'] . 'ms">测试点 ' . ($i + 1) . ' · ' . e(oj_verdict_label($c['v'])) . ' · ' . (int)$c['ms'] . 'ms</span> ';
        }
        echo '</div>';
    }
    if (!empty($sub['ce_msg'])) {
        echo '<pre class="small bg-light p-2 rounded" style="white-space:pre-wrap;">编译错误：' . e($sub['ce_msg']) . '</pre>';
    }
    if ($sub['verdict'] === 'manual') {
        echo '<div class="alert alert-info py-2 small">本题暂无测试数据或编译器不可用，提交已进入人工评测队列，请耐心等待管理员批改。</div>';
    }
    echo '</div></div>';
    echo '<div class="card"><div class="card-header"><strong>提交的代码</strong></div><div class="card-body p-0">';
    $lines = explode("\n", $sub['code']);
    echo '<div class="code-view"><div class="code-ln">' . e(implode("\n", range(1, count($lines)))) . '</div><pre><code class="language-' . ($sub['lang'] === 'c' ? 'c' : 'cpp') . '">' . e($sub['code']) . '</code></pre></div>';
    echo '</div></div>';
    page_footer();
    exit;
}

// ================= 评测状态 =================
if ($view === 'status') {
    page_header('评测状态', 'oj');
    $subs = jdb_collection('oj_submissions', 'submissions');
    usort($subs, function ($a, $b) { return strcmp($b['created_at'], $a['created_at']); });
    $mineOnly = !in_array($user['role'], array('admin', 'officer')) || isset($_GET['mine']);
    echo '<div class="card"><div class="card-header d-flex justify-content-between align-items-center">';
    echo '<strong>' . ($mineOnly ? '我的提交记录' : '全部提交记录') . '</strong>';
    if (in_array($user['role'], array('admin', 'officer'))) {
        echo '<a class="btn btn-sm btn-outline-secondary" href="/oj.php?view=status' . ($mineOnly ? '' : '&amp;mine=1') . '">' . ($mineOnly ? '查看全部' : '只看我的') . '</a>';
    }
    echo '</div><div class="card-body p-0">';
    if (count($subs) === 0) {
        echo '<div class="p-3 text-muted small">还没有任何提交。</div>';
    } else {
        echo '<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>#</th><th>题目</th><th>提交人</th><th>结果</th><th>分数</th><th>时间</th></tr></thead><tbody>';
        $shown = 0;
        foreach ($subs as $s) {
            if ($mineOnly && $s['uid'] !== $user['id']) continue;
            if (++$shown > 50) break;
            $p = oj_find_problem($s['pid']);
            echo '<tr>';
            echo '<td><a href="/oj.php?view=sub&amp;id=' . urlencode($s['id']) . '">' . e($s['id']) . '</a></td>';
            echo '<td>' . e($s['pid']) . ' · ' . e($p !== null ? $p['title'] : '（题目已删除）') . '</td>';
            echo '<td>' . e($s['name']) . '</td>';
            echo '<td><span class="oj-badge ' . oj_verdict_class($s['verdict']) . '">' . e(oj_verdict_label($s['verdict'])) . '</span></td>';
            echo '<td>' . (int)$s['score'] . '</td>';
            echo '<td class="small text-muted">' . e($s['created_at']) . '</td>';
            echo '</tr>';
        }
        if ($shown === 0) echo '<tr><td colspan="6" class="text-muted small p-3">暂无记录</td></tr>';
        echo '</tbody></table></div>';
    }
    echo '</div></div>';
    page_footer();
    exit;
}

// ================= 题单（默认） =================
page_header('OJ 训练', 'oj');
$srcF = isset($_GET['src']) ? $_GET['src'] : '';
$diffF = isset($_GET['diff']) ? (int)$_GET['diff'] : 0;
$q = trim(isset($_GET['q']) ? $_GET['q'] : '');
$problems = oj_load_problems();

// 统计通过情况（题目维度）
$stat = array();
foreach (jdb_collection('oj_submissions', 'submissions') as $s) {
    if (!isset($stat[$s['pid']])) $stat[$s['pid']] = array('subs' => 0, 'ac' => 0);
    $stat[$s['pid']]['subs']++;
    if ($s['verdict'] === 'AC') $stat[$s['pid']]['ac']++;
}
// 我的通过集合
$myAc = array();
foreach (jdb_collection('oj_submissions', 'submissions') as $s) {
    if ($s['uid'] === $user['id'] && $s['verdict'] === 'AC') $myAc[$s['pid']] = true;
}

// 筛选表单
echo '<div class="card mb-4"><div class="card-body py-3">';
echo '<form method="get" action="/oj.php" class="form-inline flex-wrap">';
echo '<select name="src" class="form-control mr-2 mb-1" style="max-width:140px;">';
echo '<option value="">全部来源</option>';
foreach (array('basic' => '浅入浅出', 'gesp' => 'GESP', 'cspj' => 'CSP-J', 'csps' => 'CSP-S') as $k => $label) {
    echo '<option value="' . $k . '"' . ($srcF === $k ? ' selected' : '') . '>' . $label . '</option>';
}
echo '</select>';
echo '<select name="diff" class="form-control mr-2 mb-1" style="max-width:120px;">';
echo '<option value="0">全部难度</option>';
for ($d = 1; $d <= 5; $d++) {
    echo '<option value="' . $d . '"' . ($diffF === $d ? ' selected' : '') . '>难度 ' . $d . '</option>';
}
echo '</select>';
echo '<input type="text" name="q" value="' . e($q) . '" class="form-control mr-2 mb-1" style="flex:1;min-width:160px;" placeholder="搜题号 / 题名 / 标签…">';
echo '<button class="btn btn-primary mb-1">筛选</button> ';
echo '</form>';
echo '<div class="small text-muted mt-2">共 ' . count($problems) . ' 题：100 道基础题（浅入浅出，含自动评测数据）+ 历届 CSP-J/S 真题 + GESP 真题。提交使用 C++14 / C11，由本站自研评测机编译运行判定。</div>';
if (!oj_compiler_available()) {
    echo '<div class="alert alert-warning py-2 small mt-2 mb-0">提示：当前服务器未检测到 g++，自动评测不可用，所有提交将进入人工评测队列。</div>';
}
echo '</div></div>';

// 题目表格
$list = array();
foreach ($problems as $p) {
    if ($srcF !== '' && $p['source'] !== $srcF) continue;
    if ($diffF > 0 && (int)$p['diff'] !== $diffF) continue;
    if ($q !== '' && stripos($p['id'] . ' ' . $p['title'] . ' ' . implode(' ', $p['tags']), $q) === false) continue;
    $list[] = $p;
}
usort($list, function ($a, $b) { return strcmp($a['id'], $b['id']); });

echo '<div class="card"><div class="card-header d-flex justify-content-between"><strong>题目列表（' . count($list) . '）</strong><span class="small text-muted">✓ = 你已通过</span></div><div class="card-body p-0">';
if (count($list) === 0) {
    echo '<div class="p-3 text-muted small">没有符合条件的题目。</div>';
} else {
    echo '<div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr><th>题号</th><th>题名</th><th>来源</th><th>难度</th><th>评测</th><th>通过 / 提交</th></tr></thead><tbody>';
    foreach ($list as $p) {
        $st = isset($stat[$p['id']]) ? $stat[$p['id']] : array('subs' => 0, 'ac' => 0);
        echo '<tr' . (isset($myAc[$p['id']]) ? ' style="background:rgba(40,167,69,.08);"' : '') . '>';
        echo '<td class="text-muted small">' . (isset($myAc[$p['id']]) ? '<strong style="color:var(--main);">✓</strong> ' : '') . e($p['id']) . '</td>';
        echo '<td><a href="/oj.php?view=problem&amp;id=' . urlencode($p['id']) . '">' . e($p['title']) . '</a></td>';
        echo '<td><span class="badge badge-light">' . e(oj_source_label($p['source'])) . '</span></td>';
        echo '<td>' . str_repeat('★', (int)$p['diff']) . '</td>';
        echo '<td class="small">' . ($p['status'] === 'ready' ? '自动' : '待补数据') . '</td>';
        echo '<td class="small">' . (int)$st['ac'] . ' / ' . (int)$st['subs'] . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}
echo '</div></div>';
page_footer();
