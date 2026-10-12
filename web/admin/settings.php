<?php
/**
 * 网站设置（仅管理员）
 * settings.json 为平铺对象，直接 jdb_read / jdb_write 读写
 * 主色调修改后全站即时生效（config.php 读取 $GLOBALS['SETTINGS']）
 */
require_once __DIR__ . '/../includes/config.php';
$admin = require_role(array('admin'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    // 一键清除全部背景图（所有成员个人背景 + 站点默认背景），防止文件爆量
    if (isset($_POST['clear_all_bg']) && $_POST['clear_all_bg']) {
        $deletedFiles = 0;
        if (is_dir(THEME_BG_DIR)) {
            foreach (glob(THEME_BG_DIR . '/*') as $f) {
                if (is_file($f) && @unlink($f)) $deletedFiles++;
            }
        }
        // 清空所有成员的个人背景引用，避免前端显示失效图片
        $users = jdb_collection('users', 'users');
        $clearedUsers = 0;
        foreach ($users as $uk => $uu) {
            if (!empty($uu['theme']['bg'])) {
                unset($users[$uk]['theme']['bg']);
                if (empty($users[$uk]['theme'])) unset($users[$uk]['theme']);
                $clearedUsers++;
            }
        }
        if ($clearedUsers > 0) jdb_save_collection('users', 'users', $users);
        // 清空站点默认背景引用
        $settings = jdb_read(JSON_DIR . '/settings.json');
        if (is_array($settings)) {
            unset($settings['default_bg']);
            jdb_write(JSON_DIR . '/settings.json', $settings);
            $GLOBALS['SETTINGS'] = $settings;
        }
        add_log('清除全部背景图', '删除 ' . $deletedFiles . ' 个背景文件，重置 ' . $clearedUsers . ' 位成员个人背景');
        $_SESSION['flash'] = '已清除全部背景图：删除 ' . $deletedFiles . ' 个背景文件，重置 ' . $clearedUsers . ' 位成员个人背景，默认背景已移除';
        redirect('settings.php');
    }

    $settings = jdb_read(JSON_DIR . '/settings.json');
    if (empty($settings) || !is_array($settings)) {
        $settings = array();
    }
    $settings['site_name'] = trim(isset($_POST['site_name']) ? $_POST['site_name'] : (isset($settings['site_name']) ? $settings['site_name'] : '社团官网'));
    $settings['org_name'] = trim(isset($_POST['org_name']) ? $_POST['org_name'] : (isset($settings['org_name']) ? $settings['org_name'] : '社团'));
    $settings['logo'] = trim(isset($_POST['logo']) ? $_POST['logo'] : (isset($settings['logo']) ? $settings['logo'] : ''));
    $settings['login_image'] = trim(isset($_POST['login_image']) ? $_POST['login_image'] : (isset($settings['login_image']) ? $settings['login_image'] : ''));
    $color = isset($_POST['primary_color']) ? trim($_POST['primary_color']) : (isset($settings['primary_color']) ? $settings['primary_color'] : '#1e6bb8');
    // 校验颜色格式，防止非法 CSS 注入
    if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) {
        $settings['primary_color'] = $color;
    } else {
        $_SESSION['flash'] = '主色调格式不正确，已保留原值';
    }
    // 默认背景图上传（全站固定背景）
    if (isset($_FILES['default_bg']) && $_FILES['default_bg']['error'] === UPLOAD_ERR_OK) {
        $url = handle_theme_bg_upload('default_bg', 'default');
        if ($url) {
            if (!empty($settings['default_bg'])) remove_theme_bg($settings['default_bg']);
            $settings['default_bg'] = $url;
        } else {
            $_SESSION['flash'] = '默认背景图上传失败（仅支持 jpg/png/gif/webp，≤5MB）';
        }
    }
    if (isset($_POST['clear_bg']) && $_POST['clear_bg']) {
        if (!empty($settings['default_bg'])) remove_theme_bg($settings['default_bg']);
        unset($settings['default_bg']);
    }
    $settings['home_intro'] = isset($_POST['home_intro']) ? $_POST['home_intro'] : (isset($settings['home_intro']) ? $settings['home_intro'] : '');
    $settings['teacher'] = trim(isset($_POST['teacher']) ? $_POST['teacher'] : (isset($settings['teacher']) ? $settings['teacher'] : ''));
    $settings['contact'] = trim(isset($_POST['contact']) ? $_POST['contact'] : (isset($settings['contact']) ? $settings['contact'] : ''));
    // 活动倒计时：收集表单行（时间为必填、名称为空时默认"活动"），按 target 升序保存
    $cdNames = isset($_POST['cd_name']) ? $_POST['cd_name'] : array();
    $cdTargets = isset($_POST['cd_target']) ? $_POST['cd_target'] : array();
    $cdEvents = array();
    $cdCount = min(count($cdNames), count($cdTargets));
    for ($i = 0; $i < $cdCount; $i++) {
        $cdName = trim(isset($cdNames[$i]) ? $cdNames[$i] : '');
        $cdTarget = trim(isset($cdTargets[$i]) ? $cdTargets[$i] : '');
        if ($cdTarget === '') continue; // 未填时间视为空行
        $ts = strtotime(str_replace('T', ' ', $cdTarget));
        if ($ts === false) continue; // 时间无法解析则跳过
        if ($cdName === '') $cdName = '活动';
        $cdEvents[] = array(
            'id' => 'cd_' . uniqid(),
            'name' => $cdName,
            'target' => date('Y-m-d H:i:s', $ts),
            'created_at' => date('Y-m-d H:i:s')
        );
    }
    usort($cdEvents, function ($a, $b) { return strcmp($a['target'], $b['target']); });
    $settings['countdown_events'] = $cdEvents;
    // 自定义导航标签：收集表单行，名称与链接均必填才保留；过滤伪协议；无协议地址补 http://
    $nlNames = isset($_POST['nl_name']) ? $_POST['nl_name'] : array();
    $nlUrls = isset($_POST['nl_url']) ? $_POST['nl_url'] : array();
    $navLinks = array();
    $nlCount = min(count($nlNames), count($nlUrls));
    for ($i = 0; $i < $nlCount; $i++) {
        $nlName = trim(isset($nlNames[$i]) ? $nlNames[$i] : '');
        $nlUrl = trim(isset($nlUrls[$i]) ? $nlUrls[$i] : '');
        if ($nlName === '' || $nlUrl === '') continue;
        if (preg_match('#^(javascript|data|vbscript):#i', $nlUrl)) continue;
        if (!preg_match('#^https?://#i', $nlUrl) && $nlUrl[0] !== '/') {
            $nlUrl = 'http://' . $nlUrl;
        }
        $navLinks[] = array(
            'id' => 'nl_' . uniqid(),
            'name' => $nlName,
            'url' => $nlUrl
        );
    }
    $settings['nav_links'] = $navLinks;
    jdb_write(JSON_DIR . '/settings.json', $settings);
    $GLOBALS['SETTINGS'] = $settings;
    $_SESSION['flash'] = '设置已保存，刷新页面即可看到新主色调';
    redirect('settings.php');
}

$flash = '';
if (isset($_SESSION['flash'])) { $flash = $_SESSION['flash']; unset($_SESSION['flash']); }

$s = jdb_read(JSON_DIR . '/settings.json');
if (empty($s) || !is_array($s)) {
    $s = $GLOBALS['SETTINGS'];
}

page_header('网站设置', 'admin');
if ($flash !== '') flash_toast($flash);

echo '<div class="row"><div class="col-lg-7">';
echo '<div class="card"><div class="card-header"><strong>网站设置</strong></div><div class="card-body">';
echo '<form method="post" action="settings.php" enctype="multipart/form-data">' . csrf_field();
echo '<div class="form-group"><label>网站标题</label><input type="text" name="site_name" class="form-control" value="' . e(isset($s['site_name']) ? $s['site_name'] : '') . '"></div>';
echo '<div class="form-group"><label>社团名称</label><input type="text" name="org_name" class="form-control" value="' . e(isset($s['org_name']) ? $s['org_name'] : '') . '"></div>';
echo '<div class="form-group"><label>Logo URL（可留空）</label><input type="text" name="logo" class="form-control" value="' . e(isset($s['logo']) ? $s['logo'] : '') . '"></div>';
echo '<div class="form-group"><label>登录页右侧图片 URL（可留空，留空显示站点名占位图）</label><input type="text" name="login_image" class="form-control" value="' . e(isset($s['login_image']) ? $s['login_image'] : '') . '"><small class="form-text text-muted">建议宽高比约 3:2，如 http://…/login.png 或 /data/uploads/… 路径</small></div>';
echo '<div class="form-group"><label>主色调</label><div class="input-group"><input type="color" name="primary_color" class="form-control" style="max-width:70px;height:38px;padding:2px;" value="' . e(isset($s['primary_color']) ? $s['primary_color'] : '#1e6bb8') . '"><input type="text" class="form-control" value="' . e(isset($s['primary_color']) ? $s['primary_color'] : '#1e6bb8') . '" disabled></div><small class="form-text text-muted">修改后全站按钮/导航/链接颜色即时生效</small></div>';
echo '<div class="form-group"><label>首页简介（Markdown）</label><textarea name="home_intro" class="form-control md-input" rows="5">' . e(isset($s['home_intro']) ? $s['home_intro'] : '') . '</textarea></div>';
echo '<div class="form-group"><label>默认背景图（全站固定背景，留空则用纯色渐变；支持 jpg/png/gif/webp，≤5MB）</label>';
if (!empty($s['default_bg'])) echo '<div class="mb-2"><img src="' . e($s['default_bg']) . '" style="max-height:120px;border-radius:12px;border:1px solid var(--line);"></div>';
echo '<input type="file" name="default_bg" class="form-control-file" accept="image/*"></div>';
echo '<div class="form-group form-check"><label class="form-check-label"><input type="checkbox" name="clear_bg" value="1"> 清除默认背景图</label></div>';
echo '<div class="form-group"><label>指导老师</label><input type="text" name="teacher" class="form-control" value="' . e(isset($s['teacher']) ? $s['teacher'] : '') . '"></div>';
echo '<div class="form-group"><label>联系方式</label><input type="text" name="contact" class="form-control" value="' . e(isset($s['contact']) ? $s['contact'] : '') . '"></div>';
// 活动倒计时（手动添加，首页倒计时组件指向最近一场未到期活动/截止日）
$cdEvents = (isset($s['countdown_events']) && is_array($s['countdown_events'])) ? $s['countdown_events'] : array();
echo '<div class="form-group"><label>活动倒计时（首页展示距离最近一场的倒计时，可添加多个）</label>';
echo '<div id="countdownRows">';
if (count($cdEvents) === 0) {
    echo '<div class="input-group mb-2"><input type="text" name="cd_name[]" class="form-control" placeholder="活动/截止名称，如：编程大赛决赛"><input type="datetime-local" name="cd_target[]" class="form-control" style="max-width:230px;"><div class="input-group-append"><button type="button" class="btn btn-outline-danger cd-del">删除</button></div></div>';
} else {
    foreach ($cdEvents as $ev) {
        $dt = str_replace(' ', 'T', substr(isset($ev['target']) ? $ev['target'] : '', 0, 16));
        echo '<div class="input-group mb-2"><input type="text" name="cd_name[]" class="form-control" value="' . e(isset($ev['name']) ? $ev['name'] : '') . '" placeholder="活动/截止名称"><input type="datetime-local" name="cd_target[]" class="form-control" style="max-width:230px;" value="' . e($dt) . '"><div class="input-group-append"><button type="button" class="btn btn-outline-danger cd-del">删除</button></div></div>';
    }
}
echo '</div>';
echo '<button type="button" class="btn btn-sm btn-outline-primary" id="cdAdd">+ 添加一行</button>';
echo '<small class="form-text text-muted">保存后首页自动倒计时指向最近一场未到期活动；活动到期后请删除该行或修改时间。</small></div>';
echo '<script>$(function(){function cdRow(v){return \'<div class="input-group mb-2"><input type="text" name="cd_name[]" class="form-control" value="\'+(v||\'\')+\'" placeholder="活动/截止名称"><input type="datetime-local" name="cd_target[]" class="form-control" style="max-width:230px;"><div class="input-group-append"><button type="button" class="btn btn-outline-danger cd-del">删除</button></div></div>\';}$(\'#cdAdd\').on(\'click\',function(){$(\'#countdownRows\').append(cdRow());});$(document).on(\'click\',\'.cd-del\',function(){$(this).closest(\'.input-group\').remove();});});</script>';
// 自定义导航标签（顶部导航"膜拜"之后追加显示）
$navLinks = (isset($s['nav_links']) && is_array($s['nav_links'])) ? $s['nav_links'] : array();
echo '<div class="form-group"><label>自定义导航标签（顶部导航栏在"膜拜"之后追加显示，可添加多条）</label>';
echo '<div id="navLinkRows">';
$nlIdx = 0;
if (count($navLinks) === 0) {
    echo '<div class="input-group mb-2 nl-row" draggable="true"><span class="input-group-text nl-grip" title="拖拽排序">&#8801;</span><span class="input-group-text nl-num">1</span><input type="text" name="nl_name[]" class="form-control" placeholder="标签名称，如：社团 BBS"><input type="text" name="nl_url[]" class="form-control" placeholder="跳转链接，如 https://… 或 /tools.php"><div class="input-group-append"><button type="button" class="btn btn-sm btn-outline-secondary nl-up" title="上移">↑</button><button type="button" class="btn btn-sm btn-outline-secondary nl-down" title="下移">↓</button><button type="button" class="btn btn-outline-danger nl-del">删除</button></div></div>';
} else {
    foreach ($navLinks as $nl) {
        $nlIdx++;
        echo '<div class="input-group mb-2 nl-row" draggable="true"><span class="input-group-text nl-grip" title="拖拽排序">&#8801;</span><span class="input-group-text nl-num">' . $nlIdx . '</span><input type="text" name="nl_name[]" class="form-control" value="' . e(isset($nl['name']) ? $nl['name'] : '') . '" placeholder="标签名称"><input type="text" name="nl_url[]" class="form-control" value="' . e(isset($nl['url']) ? $nl['url'] : '') . '" placeholder="跳转链接"><div class="input-group-append"><button type="button" class="btn btn-sm btn-outline-secondary nl-up" title="上移">↑</button><button type="button" class="btn btn-sm btn-outline-secondary nl-down" title="下移">↓</button><button type="button" class="btn btn-outline-danger nl-del">删除</button></div></div>';
    }
}
echo '</div>';
echo '<button type="button" class="btn btn-sm btn-outline-primary" id="nlAdd">+ 添加一行</button>';
echo '<small class="form-text text-muted">拖拽行首手柄可调整顺序（手机端请用每行 ↑/↓ 按钮）；外部网址建议填完整 http(s):// 地址（新窗口打开）；站内相对路径如 /tools.php（当前窗口打开）。名称或链接留空的行保存时自动丢弃。</small></div>';
echo '<style>.nl-row{cursor:grab}.nl-row.dragging{opacity:.5}.nl-grip{min-width:30px;justify-content:center;background:#fff}.nl-num{min-width:26px;justify-content:center;background:#f8f9fa;color:#6c757d;font-weight:600}</style>';
echo '<script>$(function(){function nlRow(n,v){return \'<div class="input-group mb-2 nl-row" draggable="true"><span class="input-group-text nl-grip" title="拖拽排序">&#8801;</span><span class="input-group-text nl-num"></span><input type="text" name="nl_name[]" class="form-control" value="\'+(n||\'\')+\'" placeholder="标签名称"><input type="text" name="nl_url[]" class="form-control" value="\'+(v||\'\')+\'" placeholder="跳转链接"><div class="input-group-append"><button type="button" class="btn btn-sm btn-outline-secondary nl-up" title="上移">↑</button><button type="button" class="btn btn-sm btn-outline-secondary nl-down" title="下移">↓</button><button type="button" class="btn btn-outline-danger nl-del">删除</button></div></div>\';}function nlReindex(){$(\'#navLinkRows .nl-row\').each(function(i){$(this).find(\'.nl-num\').text(i+1);});}var dragRow=null,dropTarget=null,dropPos=\'after\';$(\'#navLinkRows\').on(\'dragstart\',\'.nl-row\',function(e){dragRow=this;$(this).addClass(\'dragging\');try{e.originalEvent.dataTransfer.setData(\'text/plain\',\'x\');e.originalEvent.dataTransfer.effectAllowed=\'move\';}catch(err){}}).on(\'dragend\',\'.nl-row\',function(){$(this).removeClass(\'dragging\');dragRow=null;dropTarget=null;}).on(\'dragenter dragover\',function(e){e.preventDefault();}).on(\'dragover\',\'.nl-row\',function(e){e.preventDefault();e.originalEvent.dataTransfer.dropEffect=\'move\';var rect=this.getBoundingClientRect();dropTarget=this;dropPos=(e.originalEvent.clientY-rect.top<rect.height/2)?\'before\':\'after\';}).on(\'drop\',function(e){e.preventDefault();if(dragRow&&dropTarget&&dropTarget!==dragRow){if(dropPos===\'before\'){$(dropTarget).before(dragRow);}else{$(dropTarget).after(dragRow);}nlReindex();}dragRow=null;dropTarget=null;});$(\'#nlAdd\').on(\'click\',function(){$(\'#navLinkRows\').append(nlRow());nlReindex();});$(document).on(\'click\',\'.nl-del\',function(){$(this).closest(\'.nl-row\').remove();nlReindex();});$(document).on(\'click\',\'.nl-up\',function(){var r=$(this).closest(\'.nl-row\');if(r.prev(\'.nl-row\').length){r.insertBefore(r.prev(\'.nl-row\'));nlReindex();}});$(document).on(\'click\',\'.nl-down\',function(){var r=$(this).closest(\'.nl-row\');if(r.next(\'.nl-row\').length){r.insertAfter(r.next(\'.nl-row\'));nlReindex();}});nlReindex();});</script>';
echo '<button class="btn btn-primary">保存设置</button></form>';
echo '</div></div>';
echo '<div class="card mt-4 border-danger"><div class="card-header text-danger"><strong>危险操作：清除全部背景图</strong></div><div class="card-body">';
echo '<p class="small text-muted mb-2">一键删除所有成员上传的个人背景图与站点默认背景图（<code>assets/bg</code> 下全部文件），并重置所有用户的背景设置。该操作不可恢复。</p>';
echo '<form method="post" action="settings.php">' . csrf_field() . '<input type="hidden" name="clear_all_bg" value="1"><button class="btn btn-danger" onclick="return confirm(\'确定删除所有人的背景图片（含默认背景与所有成员个人背景）？删除后不可恢复。\')">删除所有背景图片</button></form>';
echo '</div></div></div>';

echo '<div class="col-lg-5">';
echo '<div class="card"><div class="card-header"><strong>当前配置预览</strong></div><div class="card-body">';
echo '<table class="table table-sm">';
echo '<tr><td>网站标题</td><td>' . e(isset($s['site_name']) ? $s['site_name'] : '') . '</td></tr>';
echo '<tr><td>社团名称</td><td>' . e(isset($s['org_name']) ? $s['org_name'] : '') . '</td></tr>';
echo '<tr><td>主色调</td><td><span style="display:inline-block;width:18px;height:18px;background:' . e(isset($s['primary_color']) ? $s['primary_color'] : '#1e6bb8') . ';border-radius:3px;vertical-align:middle;"></span> <code>' . e(isset($s['primary_color']) ? $s['primary_color'] : '#1e6bb8') . '</code></td></tr>';
echo '<tr><td>默认背景图</td><td>' . (isset($s['default_bg']) && $s['default_bg'] !== '' ? '<a href="' . e($s['default_bg']) . '" target="_blank">' . e($s['default_bg']) . '</a>' : '<span class="text-muted">未设置（使用纯色）</span>') . '</td></tr>';
echo '<tr><td>指导老师</td><td>' . e(isset($s['teacher']) ? $s['teacher'] : '') . '</td></tr>';
echo '<tr><td>联系方式</td><td>' . e(isset($s['contact']) ? $s['contact'] : '') . '</td></tr>';
echo '</table>';
echo '</div></div></div></div>';

page_footer();
