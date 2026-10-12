<?php
/**
 * 膜拜页面（对外展示 + 成员互动）— 幸运值抽奖模式
 * - 展示膜拜对象列表（管理员上传的图片 + 姓名），每个对象下方有「膜拜」按钮
 * - 点击膜拜产生爱心/粒子特效并随机获得今日幸运值（1~100），幸运值记在膜拜者名下（抽奖反馈形式）
 * - 页面右侧显示「今日膜拜幸运榜」（按幸运值降序），膜拜后自动刷新
 * - 每人每天对同一对象限膜拜一次（后端强制，见 api/worship.php）；游客可查看对象与排名，点击膜拜时引导登录
 */
require_once __DIR__ . '/includes/config.php';

$user = is_logged_in() ? current_user() : null;

$worship = jdb_read(JSON_DIR . '/worship.json');
$objects = (isset($worship['objects']) && is_array($worship['objects'])) ? $worship['objects'] : array();
$today = date('Y-m-d');

// 取今日膜拜用户明细（用于首屏渲染幸运榜与已膜拜状态）
$todayUsers = array();
if (isset($worship['daily']) && is_array($worship['daily'])) {
    foreach ($worship['daily'] as $d) {
        if (isset($d['date']) && $d['date'] === $today && isset($d['users']) && is_array($d['users'])) {
            $todayUsers = $d['users'];
            break;
        }
    }
}

// 首屏用户幸运榜（PHP 侧渲染，与 API rank 一致：幸运值降序，同分先膜拜在前）
$rank = $todayUsers;
usort($rank, function ($a, $b) {
    $sa = (int)$a['score'];
    $sb = (int)$b['score'];
    if ($sa !== $sb) return $sb - $sa;
    return strcmp(isset($a['ts']) ? $a['ts'] : '', isset($b['ts']) ? $b['ts'] : '');
});

// 各对象今日膜拜人数
$votesMap = array();
foreach ($objects as $o) { $votesMap[$o['id']] = 0; }
foreach ($todayUsers as $u) { if (isset($votesMap[$u['object_id']])) $votesMap[$u['object_id']]++; }

// 我今日已膜拜的对象 id 集合
$mine = array();
if ($user) {
    foreach ($todayUsers as $u) {
        if (isset($u['uid']) && $u['uid'] === $user['id']) $mine[] = isset($u['object_id']) ? $u['object_id'] : '';
    }
}

page_header('膜拜', 'worship');

$s = $GLOBALS['SETTINGS'];
$orgName = isset($s['org_name']) ? $s['org_name'] : '社团';
?>
<style>
    .worship-card .btn { transition: transform .15s ease; }
    .worship-card .btn:active { transform: scale(.94); }
</style>

<?php if ($user && $user['role'] === 'admin') { ?>
<div class="mb-3">
    <a class="btn btn-outline-primary btn-sm" href="/admin/worship.php">管理膜拜对象</a>
</div>
<?php } ?>

<div class="row">
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header"><strong>今日膜拜对象</strong><span class="float-right small text-muted"><?php echo e($today); ?> · 每人每天每对象限一次</span></div>
            <div class="card-body">
                <?php if (count($objects) === 0) { ?>
                    <div class="text-center text-muted py-4">
                        <svg class="worship-empty-icon" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="32" cy="32" r="27" opacity=".35" stroke-dasharray="4 4"/><path d="M32 19c-4.2-5.2-12-4.1-12 3.1 0 6.4 12 15.4 12 15.4s12-9 12-15.4c0-7.2-7.8-8.3-12-3.1z"/></svg>
                        <p class="mb-1">管理员暂未设置膜拜对象，敬请期待。</p>
                    </div>
                <?php } else { ?>
                    <div class="row">
                    <?php $objCol = count($objects) === 1 ? 'col-md-8 col-lg-6 mx-auto' : 'col-md-6 col-lg-4 mb-3';
                    foreach ($objects as $o) {
                        $votes = isset($votesMap[$o['id']]) ? $votesMap[$o['id']] : 0;
                        $hasWorshipped = in_array($o['id'], $mine);
                        echo '<div class="' . $objCol . '">';
                        echo '<div class="card worship-card h-100 text-center">';
                        echo '<img class="card-img-top worship-img" src="img.php?file=' . urlencode($o['image']) . '" alt="' . e($o['name']) . '" loading="lazy" onerror="this.onerror=null;this.src=\'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22300%22 height=%22200%22%3E%3Cdefs%3E%3ClinearGradient id=%22g%22 x1=%220%22 y1=%220%22 x2=%221%22 y2=%221%22%3E%3Cstop offset=%220%22 stop-color=%22%23e8eef5%22/%3E%3Cstop offset=%221%22 stop-color=%22%23d8e2ee%22/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width=%22300%22 height=%22200%22 fill=%22url(%23g)%22/%3E%3Ccircle cx=%22150%22 cy=%22100%22 r=%2232%22 fill=%22none%22 stroke=%22%23a9bdd4%22 stroke-width=%223%22 opacity=%22.7%22/%3E%3C/svg%3E\')">';
                        echo '<div class="card-body">';
                        echo '<h5 class="card-title mb-1">' . e($o['name']) . '</h5>';
                        if (!empty($o['desc'])) { echo '<p class="text-muted small mb-2">' . e($o['desc']) . '</p>'; }
                        echo '<p class="text-muted small mb-2">今日 <b style="color:var(--main);">' . (int)$votes . '</b> 人膜拜</p>';
                        if ($hasWorshipped) {
                            echo '<button class="btn btn-secondary btn-block" disabled>今日已膜拜</button>';
                        } else {
                            echo '<button class="btn btn-primary btn-block worship-btn" onclick="doWorship(\'' . e($o['id']) . '\', this)">膜拜抽幸运值</button>';
                        }
                        echo '</div></div></div>';
                    } ?>
                    </div>
                <?php } ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-header"><strong>今日膜拜幸运榜</strong><span class="float-right small text-muted">每日 00:00 自动清零</span></div>
            <ul class="list-group list-group-flush" id="rankList">
                <?php if (count($rank) === 0) { ?>
                    <li class="list-group-item rank-empty">今日还没有膜拜记录，快来送出第一拜，抽今日幸运值～</li>
                <?php } else {
                    foreach ($rank as $i => $r) {
                        $topClass = $i === 0 ? ' rank-top1' : ($i === 1 ? ' rank-top2' : ($i === 2 ? ' rank-top3' : ''));
                        echo '<li class="list-group-item">';
                        echo '<div class="d-flex justify-content-between align-items-center">';
                        echo '<span class="rank-badge' . $topClass . '">' . ($i + 1) . '</span> <strong class="rank-name">' . e(isset($r['name']) ? $r['name'] : '') . '</strong>';
                        echo '<span class="rank-score">' . (int)$r['score'] . ' 幸运值</span>';
                        echo '</div>';
                        echo '</li>';
                    }
                } ?>
            </ul>
        </div>
        <div class="alert alert-light border small text-muted mb-4" style="border-color:var(--line) !important;border-radius:12px;">
            玩法说明：每天为喜爱的人送出一「膜拜」，立即随机获得今日幸运值（1~100）。幸运值只属于你自己，不累加到对方；按幸运值高低排名，次日 00:00 自动清零重新抽。
        </div>
    </div>
</div>

<script>
var IS_LOGIN = <?php echo $user ? 'true' : 'false'; ?>;
var WORSHIP_TOKEN = '<?php echo csrf_token(); ?>';

// 膜拜动作
function doWorship(id, btn) {
    if (!IS_LOGIN) {
        if (confirm('膜拜需要登录后才能参与，现在去登录吗？')) { location.href = 'login.php'; }
        return;
    }
    var $btn = $(btn);
    if ($btn.prop('disabled')) return;
    $btn.prop('disabled', true);
    $.post('api/worship.php?action=worship', { object_id: id, csrf_token: WORSHIP_TOKEN }, function (res) {
        if (res.ok) {
            spawnFx($btn, res.score);
            $btn.text('今日已膜拜').addClass('btn-secondary').removeClass('btn-primary').prop('disabled', true);
            renderRank(res.rank);
            // 同步更新对象卡片上的今日膜拜人数
            var $card = $btn.closest('.worship-card');
            if ($card.length && res.rank) {
                var cnt = 0;
                for (var k = 0; k < res.rank.length; k++) {
                    if (res.rank[k].object_id === id) cnt++;
                }
                $card.find('p.text-muted.small.mb-2').html('今日 <b style="color:var(--main);">' + cnt + '</b> 人膜拜');
            }
        } else {
            $btn.prop('disabled', false);
            alert(res.error || '操作失败');
        }
    }).fail(function (xhr) {
        $btn.prop('disabled', false);
        var r = xhr.responseJSON || {};
        if (xhr.status === 401) { location.href = 'login.php'; return; }
        alert(r.error || '操作失败，请稍后重试');
    });
}

// 特效：光点粒子 + 幸运值飘字
function spawnFx($btn, score) {
    var rect = $btn[0].getBoundingClientRect();
    var cx = rect.left + rect.width / 2;
    var cy = rect.top + rect.height / 2;
    var colors = ['rgba(255,107,107,.95)', 'rgba(242,169,59,.95)', 'rgba(88,154,255,.95)', 'rgba(217,164,65,.95)'];
    var sizes = [5, 7, 9];
    for (var i = 0; i < 14; i++) {
        var size = sizes[Math.floor(Math.random() * sizes.length)];
        var $p = $('<span class="worship-particle"></span>');
        var drift = (Math.random() * 70 - 35);
        $p.css({
            left: (cx + (Math.random() * 130 - 65)) + 'px',
            top: (cy + (Math.random() * 44 - 22)) + 'px',
            width: size + 'px',
            height: size + 'px',
            color: colors[Math.floor(Math.random() * colors.length)],
            '--drift-x': drift + 'px'
        });
        $('body').append($p);
        (function (el) { setTimeout(function () { el.remove(); }, 1250); })($p);
    }
    var $s = $('<span class="score-pop">+ ' + score + '</span>').css({ left: cx + 'px', top: cy + 'px' });
    $('body').append($s);
    setTimeout(function () { $s.remove(); }, 1450);
}

// 刷新今日膜拜幸运榜（用户幸运值降序）
function renderRank(rank) {
    var $ul = $('#rankList').empty();
    if (!rank || !rank.length) {
        $ul.append('<li class="list-group-item rank-empty">今日还没有膜拜记录，快来送出第一拜，抽今日幸运值～</li>');
        return;
    }
    for (var i = 0; i < rank.length; i++) {
        var r = rank[i];
        var topClass = i === 0 ? ' rank-top1' : (i === 1 ? ' rank-top2' : (i === 2 ? ' rank-top3' : ''));
        var $li = $('<li class="list-group-item"></li>');
        $li.append('<div class="d-flex justify-content-between align-items-center">'
            + '<span class="rank-badge' + topClass + '">' + (i + 1) + '</span>'
            + '<strong class="rank-name"></strong>'
            + '<span class="rank-score">' + r.score + ' 幸运值</span></div>');
        $li.find('.rank-name').text(r.name);
        $ul.append($li);
    }
}
</script>
<?php
page_footer();
