<?php
/**
 * 膜拜管理后台（仅 admin 角色）— 幸运值抽奖模式
 * - 新增/编辑膜拜对象：填写姓名（+简介），上传图片（存 data/uploads/worship/，随机重命名，拒绝可执行文件，仅限图片扩展名）
 * - 删除对象（同步清理每日膜拜用户记录）
 * 数据存储在 data/json/worship.json（复用 jdb_read / jdb_write 文件锁封装）
 * 说明：膜拜幸运值记在膜拜者名下，对象仅展示今日被膜拜人次。
 */
require_once __DIR__ . '/../includes/config.php';
$user = require_role(array('admin'));

// ---------------- 保存（新增 / 编辑） ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    check_csrf();
    $id = isset($_POST['id']) ? $_POST['id'] : '';
    $name = trim(isset($_POST['name']) ? $_POST['name'] : '');
    $desc = trim(isset($_POST['desc']) ? $_POST['desc'] : '');
    if ($name === '') { $_SESSION['flash'] = '姓名不能为空'; redirect('worship.php'); }
    if (strlen($name) > 60) { $_SESSION['flash'] = '姓名过长（最多 20 个汉字）'; redirect('worship.php'); }
    if (strlen($desc) > 200) { $_SESSION['flash'] = '简介过长（最多 200 字）'; redirect('worship.php'); }

    $worship = jdb_read(JSON_DIR . '/worship.json');
    $objects = (isset($worship['objects']) && is_array($worship['objects'])) ? $worship['objects'] : array();
    $existing = null;
    foreach ($objects as $o) { if ($o['id'] === $id) { $existing = $o; break; } }

    // 图片：新增必传；编辑可选（不传则保留原图）
    $image = $existing ? $existing['image'] : '';
    if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $imgExts = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp');
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $imgExts)) { $_SESSION['flash'] = '仅支持 jpg / png / gif / webp / bmp 图片'; redirect('worship.php'); }
        $up = handle_upload('image', 'worship', 5242880); // 最大 5MB
        if (!$up['ok']) { $_SESSION['flash'] = $up['error']; redirect('worship.php'); }
        $image = $up['stored_name'];
    }
    if ($image === '') { $_SESSION['flash'] = '请上传膜拜对象图片'; redirect('worship.php'); }

    if ($existing) {
        foreach ($objects as $k => $o) {
            if ($o['id'] === $id) {
                $objects[$k]['name'] = $name;
                $objects[$k]['desc'] = $desc;
                $objects[$k]['image'] = $image;
                $objects[$k]['updated_at'] = date('Y-m-d H:i:s');
            }
        }
        $_SESSION['flash'] = '膜拜对象已更新';
    } else {
        if (count($objects) >= 1) {
            $_SESSION['flash'] = '仅支持设置 1 个膜拜对象，请先编辑现有对象或删除后重设';
            redirect('worship.php');
        }
        $objects[] = array(
            'id'         => 'w_' . uniqid(),
            'name'       => $name,
            'desc'       => $desc,
            'image'      => $image,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        );
        $_SESSION['flash'] = '膜拜对象已添加';
    }
    $worship['objects'] = $objects;
    if (!jdb_write(JSON_DIR . '/worship.json', $worship)) {
        $_SESSION['flash'] = '保存失败，请检查目录权限';
    }
    redirect('worship.php');
}

// ---------------- 删除 ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    check_csrf();
    $id = isset($_POST['id']) ? $_POST['id'] : '';
    $worship = jdb_read(JSON_DIR . '/worship.json');
    $objects = (isset($worship['objects']) && is_array($worship['objects'])) ? $worship['objects'] : array();
    $newObjects = array();
    foreach ($objects as $o) {
        if ($o['id'] !== $id) $newObjects[] = $o;
    }
    $worship['objects'] = $newObjects;
    // 同步清理每日膜拜用户记录中该对象的记录
    if (isset($worship['daily']) && is_array($worship['daily'])) {
        foreach ($worship['daily'] as $dk => $d) {
            $keep = array();
            if (isset($d['users']) && is_array($d['users'])) {
                foreach ($d['users'] as $u) {
                    if (!isset($u['object_id']) || $u['object_id'] !== $id) $keep[] = $u;
                }
            }
            $worship['daily'][$dk]['users'] = $keep;
        }
    }
    if (jdb_write(JSON_DIR . '/worship.json', $worship)) {
        $_SESSION['flash'] = '膜拜对象已删除';
    } else {
        $_SESSION['flash'] = '删除失败，请检查目录权限';
    }
    redirect('worship.php');
}

// ---------------- 列表与编辑态 ----------------
$flash = '';
if (isset($_SESSION['flash'])) { $flash = $_SESSION['flash']; unset($_SESSION['flash']); }

$worship = jdb_read(JSON_DIR . '/worship.json');
$objects = (isset($worship['objects']) && is_array($worship['objects'])) ? $worship['objects'] : array();
$today = date('Y-m-d');
$todayUsers = array();
if (isset($worship['daily']) && is_array($worship['daily'])) {
    foreach ($worship['daily'] as $d) {
        if (isset($d['date']) && $d['date'] === $today) { $todayUsers = isset($d['users']) ? $d['users'] : array(); break; }
    }
}
// 今日各对象膜拜人次映射
$todayVotes = array();
foreach ($objects as $o) { $todayVotes[$o['id']] = 0; }
foreach ($todayUsers as $u) {
    if (isset($u['object_id']) && isset($todayVotes[$u['object_id']])) $todayVotes[$u['object_id']]++;
}
$totalVotes = count($todayUsers);

$editId = isset($_GET['edit']) ? $_GET['edit'] : '';
$editObj = null;
if ($editId !== '') {
    foreach ($objects as $o) { if ($o['id'] === $editId) { $editObj = $o; break; } }
}

page_header('膜拜管理', 'admin');
if ($flash !== '') flash_toast($flash);

echo '<div class="row">';
// 左侧表单
echo '<div class="col-lg-5">';
echo '<div class="card mb-4"><div class="card-header"><strong>' . ($editObj ? '编辑膜拜对象' : '新增膜拜对象') . '</strong></div><div class="card-body">';
echo '<form method="post" action="worship.php" enctype="multipart/form-data">' . csrf_field();
echo '<input type="hidden" name="action" value="save">';
if ($editObj) echo '<input type="hidden" name="id" value="' . e($editObj['id']) . '">';
echo '<div class="form-group"><label>姓名 *</label><input type="text" name="name" class="form-control" value="' . ($editObj ? e($editObj['name']) : '') . '" required maxlength="20"></div>';
echo '<div class="form-group"><label>简介（可选，一句话介绍）</label><input type="text" name="desc" class="form-control" value="' . ($editObj ? e($editObj['desc']) : '') . '" maxlength="200" placeholder="如：本周进步之星 / 优秀社员"></div>';
echo '<div class="form-group"><label>图片 *' . ($editObj ? '（不选择则保留原图）' : '') . '</label>';
echo '<input type="file" name="image" class="form-control-file" accept="image/jpeg,image/png,image/gif,image/webp,image/bmp"' . ($editObj ? '' : ' required') . '>';
echo '<div class="small text-muted mt-1">支持 jpg / png / gif / webp / bmp，最大 5MB</div></div>';
if ($editObj) {
    echo '<div class="mb-3"><img src="img.php?file=' . urlencode($editObj['image']) . '" style="height:100px;border-radius:6px;" onerror="this.style.display=\'none\'"></div>';
}
echo '<button class="btn btn-primary" style="background-color:var(--main);border-color:var(--main);">' . ($editObj ? '保存修改' : '添加对象') . '</button> ';
if ($editObj) echo '<a class="btn btn-outline-secondary" href="worship.php">取消编辑</a>';
echo '</form></div></div></div>';

// 右侧列表
echo '<div class="col-lg-7">';
echo '<div class="card"><div class="card-header"><strong>膜拜对象列表（' . count($objects) . '）</strong><span class="float-right small text-muted">今日 ' . (int)$totalVotes . ' 人次膜拜</span></div><div class="card-body p-0">';
if (count($objects) === 0) { echo '<div class="p-3 text-muted small">暂无膜拜对象，请先在左侧添加</div>'; }
else {
    echo '<ul class="list-group list-group-flush">';
    foreach ($objects as $o) {
        $votes = isset($todayVotes[$o['id']]) ? $todayVotes[$o['id']] : 0;
        echo '<li class="list-group-item"><div class="d-flex align-items-center">';
        echo '<img src="img.php?file=' . urlencode($o['image']) . '" loading="lazy" style="width:56px;height:56px;object-fit:cover;border-radius:8px;margin-right:12px;" onerror="this.style.visibility=\'hidden\'">';
        echo '<div class="flex-fill"><strong>' . e($o['name']) . '</strong>';
        if (!empty($o['desc'])) echo '<div class="small text-muted">' . e($o['desc']) . '</div>';
        echo '<div class="small text-muted">今日 ' . (int)$votes . ' 人膜拜 ｜ 创建 ' . e(substr($o['created_at'], 0, 10)) . '</div></div>';
        echo '<div>';
        echo '<a class="btn btn-sm btn-outline-primary mr-1" href="worship.php?edit=' . urlencode($o['id']) . '">编辑</a>';
        echo '<form method="post" action="worship.php" class="d-inline">' . csrf_field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . e($o['id']) . '"><button class="btn btn-sm btn-outline-danger" onclick="return confirm(\'确定删除该膜拜对象？其今日膜拜记录也会被清理。\')">删除</button></form>';
        echo '</div></div></li>';
    }
    echo '</ul>';
}
echo '</div></div></div>';
echo '</div>';

page_footer();
