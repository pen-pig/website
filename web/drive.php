<?php
/**
 * 网盘页面（登录成员可用）
 * - 资源管理器样式：左侧目录树 + 右侧文件夹/文件混合列表（文件夹在前）
 * - 顶部地址栏显示当前目录/路径，可直接点击跳转
 * - 上传：单文件上传、整个文件夹上传（含子目录，webkitdirectory 递归）
 * - 下载（登录成员均可）；删除（本人或管理员/干事）
 */
require_once __DIR__ . '/includes/config.php';
require_login();
$user = current_user();

// 当前目录（相对 DRIVE_DIR，根目录为空串）
$dir = drive_norm_path(isset($_GET['dir']) ? $_GET['dir'] : '');
if ($dir === null || drive_resolve($dir) === false) {
    $dir = '';
}

$folders = jdb_collection('drive_folders', 'folders');
$files   = jdb_collection('drive', 'files');

// 网盘记录自愈：以物理目录为权威，自动补齐缺失记录（防部署覆盖 json 导致文件不显示）
$driveSync = drive_sync_records();
if ($driveSync['added_files'] > 0 || $driveSync['added_folders'] > 0) {
    $folders = jdb_collection('drive_folders', 'folders');
    $files   = jdb_collection('drive', 'files');
}

// 当前目录下的文件夹
$subFolders = array();
foreach ($folders as $f) {
    $p = isset($f['parent']) ? $f['parent'] : '';
    if ($p === $dir) {
        $subFolders[] = $f;
    }
}
usort($subFolders, function ($a, $b) {
    return strnatcmp(isset($a['name']) ? $a['name'] : '', isset($b['name']) ? $b['name'] : '');
});

// 当前目录下的文件
$curFiles = array();
foreach ($files as $f) {
    $fp = isset($f['folder']) ? $f['folder'] : '';
    if ($fp === $dir) {
        $curFiles[] = $f;
    }
}
usort($curFiles, function ($a, $b) {
    return strnatcmp(isset($a['orig']) ? $a['orig'] : (isset($a['stored']) ? $a['stored'] : ''), isset($b['orig']) ? $b['orig'] : (isset($b['stored']) ? $b['stored'] : ''));
});

// 构建目录树（parent => 子文件夹列表）
$tree = array();
foreach ($folders as $f) {
    $p = isset($f['parent']) ? $f['parent'] : '';
    $tree[$p][] = $f;
}

$used = $driveSync['used'];
$limit = DRIVE_LIMIT;
$usedPct = $limit > 0 ? min(100, round($used / $limit * 100, 2)) : 0;
$canAdmin = in_array($user['role'], array('admin', 'officer'));

// 面包屑 / 地址栏
$crumbs = array(array('name' => '全部文件', 'dir' => ''));
if ($dir !== '') {
    $segs = explode('/', $dir);
    $acc = '';
    foreach ($segs as $seg) {
        $acc = $acc === '' ? $seg : $acc . '/' . $seg;
        $crumbs[] = array('name' => $seg, 'dir' => $acc);
    }
}
$curName = $dir === '' ? '全部文件' : $segs[count($segs) - 1];

// 文件类型图标
function drive_ico($name) {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $map = array(
        'jpg' => '🖼', 'jpeg' => '🖼', 'png' => '🖼', 'gif' => '🖼', 'webp' => '🖼', 'svg' => '🖼', 'bmp' => '🖼', 'ico' => '🖼',
        'mp4' => '🎬', 'mkv' => '🎬', 'mov' => '🎬', 'avi' => '🎬', 'webm' => '🎬', 'flv' => '🎬', 'wmv' => '🎬',
        'mp3' => '🎵', 'wav' => '🎵', 'flac' => '🎵', 'ogg' => '🎵', 'm4a' => '🎵', 'aac' => '🎵',
        'zip' => '🗜', 'rar' => '🗜', '7z' => '🗜', 'tar' => '🗜', 'gz' => '🗜', 'bz2' => '🗜', 'xz' => '🗜',
        'pdf' => '📕', 'doc' => '📘', 'docx' => '📘', 'xls' => '📗', 'xlsx' => '📗', 'ppt' => '📙', 'pptx' => '📙',
        'txt' => '📄', 'md' => '📄', 'log' => '📄',
        'php' => '⚙', 'js' => '⚙', 'py' => '🐍', 'json' => '🧾', 'csv' => '🧾', 'xml' => '🧾',
        'html' => '🌐', 'htm' => '🌐', 'css' => '🎨', 'sql' => '🗄', 'apk' => '🤖', 'exe' => '💿'
    );
    return isset($map[$ext]) ? $map[$ext] : '📄';
}

// 递归渲染目录树
function drive_render_tree($tree, $parent, $dir, $level) {
    if (!isset($tree[$parent])) return;
    $items = $tree[$parent];
    usort($items, function ($a, $b) {
        return strnatcmp(isset($a['name']) ? $a['name'] : '', isset($b['name']) ? $b['name'] : '');
    });
    foreach ($items as $f) {
        $path = isset($f['path']) ? $f['path'] : '';
        $active = ($path === $dir) ? ' active' : '';
        $hasKids = isset($tree[$path]) && count($tree[$path]) > 0;
        echo '<div class="tree-item' . $active . '" style="padding-left:' . (8 + $level * 16) . 'px;">';
        echo '<a href="drive.php?dir=' . urlencode($path) . '" title="' . e($f['name']) . '">';
        echo $hasKids ? '📂 ' : '📁 ';
        echo e($f['name']);
        echo '</a></div>';
        if ($hasKids) {
            drive_render_tree($tree, $path, $dir, $level + 1);
        }
    }
}

page_header('网盘', 'drive');
?>
<style>
.drive-explorer { display: flex; flex-wrap: wrap; }
.drive-side { flex: 0 0 240px; max-width: 240px; padding-right: 16px; }
.drive-main { flex: 1 1 0; min-width: 0; }
@media (max-width: 991.98px) {
    .drive-side { flex: 0 0 100%; max-width: 100%; padding-right: 0; margin-bottom: 16px; }
    .drive-tree { max-height: 180px !important; }
}
.drive-tree { max-height: 520px; overflow-y: auto; }
.drive-tree .tree-root { display: block; padding: 7px 12px; margin-bottom: 4px; border-radius: 6px; color: var(--ink); text-decoration: none; font-weight: 600; }
.drive-tree .tree-root:hover { background: rgba(var(--main-rgb), .10); color: var(--main); }
.drive-tree .tree-root.active { background: rgba(var(--main-rgb), .88); color: #fff; }
.drive-tree .tree-item { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.drive-tree .tree-item a { display: block; padding: 5px 8px; margin-right: 4px; border-radius: 5px; color: var(--ink); text-decoration: none; font-size: 13.5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.drive-tree .tree-item a:hover { background: rgba(var(--main-rgb), .08); color: var(--main); }
.drive-tree .tree-item.active a { background: rgba(var(--main-rgb), .88); color: #fff; }
.drive-crumbs { display: inline-flex; flex-wrap: wrap; align-items: center; background: rgba(255, 255, 255, .50); border: 1px solid rgba(255, 255, 255, .60); border-radius: 6px; padding: 4px 10px; font-size: 13px; -webkit-backdrop-filter: blur(8px); backdrop-filter: blur(8px); }
.drive-crumbs a { color: var(--main); text-decoration: none; }
.drive-crumbs a:hover { text-decoration: underline; }
.drive-crumbs .sep { color: #9aa4b2; margin: 0 5px; }
.drive-crumbs .cur { color: var(--ink); font-weight: 600; }
.drive-list { margin-bottom: 0; }
.drive-list th { font-size: 12.5px; color: #667; background: rgba(255, 255, 255, .50); border-bottom: 1px solid rgba(255, 255, 255, .60) !important; }
.drive-list td { vertical-align: middle; }
.drive-list tr { cursor: default; }
.drive-list .drive-folder-row { cursor: pointer; }
.drive-list .drive-file-row { cursor: pointer; }
.drive-list .drive-folder-row:hover td { background: rgba(var(--main-rgb), .07); }
.drive-list .drive-file-row:hover td { background: rgba(255, 255, 255, .38); }
.drive-list .drive-ico { margin-right: 6px; font-size: 16px; }
.drive-list .drive-name { color: var(--ink); font-weight: 500; text-decoration: none; }
.drive-list .drive-folder-row .drive-name { color: var(--main); }
.drive-list .drive-folder-row:hover .drive-name { text-decoration: underline; }
.drive-list .drive-actions { white-space: nowrap; text-align: right; }
.drive-list .drive-actions .btn { margin-left: 4px; }
.upload-card .card-header { background: rgba(255, 255, 255, .40); }
.upload-card .quota-bar { height: 6px; margin-top: 8px; }
@media (max-width: 575.98px) {
    .drive-list th:nth-child(2), .drive-list td:nth-child(2) { display: none; }
    .drive-list th:nth-child(3), .drive-list td:nth-child(3) { display: none; }
}
</style>
<div class="row">
    <div class="col-12">
        <div class="card mb-4 upload-card">
            <div class="card-header"><strong>上传到：<?php echo e($curName); ?></strong><span class="float-right small text-muted">登录成员均可上传 · 单文件不超过 2GB，禁止上传可执行脚本</span></div>
            <div class="card-body py-2">
                <form id="uploadForm" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="folder" id="uploadFolder" value="<?php echo e($dir); ?>">
                    <div class="form-row align-items-center">
                        <div class="col-md-5 mb-2">
                            <input type="file" name="file" id="driveFile" class="form-control-file">
                        </div>
                        <div class="col-md-3 mb-2">
                            <button type="submit" class="btn btn-primary btn-block" id="uploadBtn">上传文件</button>
                        </div>
                        <div class="col-md-4 mb-2">
                            <input type="file" name="folderFiles" id="driveFolder" class="form-control-file" webkitdirectory directory multiple>
                        </div>
                    </div>
                    <div class="form-row align-items-center">
                        <div class="col-md-5 mb-2">
                            <input type="text" id="newFolderName" class="form-control" placeholder="新文件夹名称" maxlength="60">
                        </div>
                        <div class="col-md-3 mb-2">
                            <button type="button" class="btn btn-outline-primary btn-block" id="mkdirBtn">新建文件夹</button>
                        </div>
                        <div class="col-md-4 mb-2">
                            <button type="button" class="btn btn-outline-secondary btn-block" id="uploadFolderBtn">上传所选文件夹</button>
                        </div>
                    </div>
                    <div class="small text-muted">已用 <b><?php echo fmt_size($used); ?></b> / 限额 <b>20G</b>（<?php echo $usedPct; ?>%）· 上传文件夹将保留子目录结构</div>
                    <div class="progress quota-bar" role="progressbar">
                        <div class="progress-bar" style="width:<?php echo $usedPct; ?>%" aria-valuenow="<?php echo $usedPct; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="drive-explorer">
    <!-- 左侧目录树 -->
    <div class="drive-side">
        <div class="card">
            <div class="card-header py-2"><strong>目录</strong></div>
            <div class="drive-tree">
                <a href="drive.php" class="tree-root<?php echo $dir === '' ? ' active' : ''; ?>">🗂 全部文件</a>
                <?php drive_render_tree($tree, '', $dir, 0); ?>
            </div>
        </div>
    </div>

    <!-- 右侧混合列表 -->
    <div class="drive-main">
        <div class="card">
            <div class="card-header py-2">
                <span class="mr-2"><strong>当前位置</strong></span>
                <nav class="drive-crumbs">
                    <?php
                    $lastIdx = count($crumbs) - 1;
                    foreach ($crumbs as $i => $c) {
                        if ($i > 0) { echo '<span class="sep">/</span>'; }
                        if ($i === $lastIdx) {
                            echo '<span class="cur">' . e($c['name']) . '</span>';
                        } else {
                            echo '<a href="drive.php?dir=' . urlencode($c['dir']) . '">' . e($c['name']) . '</a>';
                        }
                    }
                    ?>
                </nav>
                <span class="float-right small text-muted">共 <?php echo count($subFolders) + count($curFiles); ?> 项</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover drive-list mb-0">
                    <thead>
                        <tr>
                            <th>名称</th>
                            <th style="width:110px;">大小</th>
                            <th style="width:120px;">上传者 / 创建者</th>
                            <th style="width:150px;">上传 / 创建时间</th>
                            <th class="text-right" style="width:150px;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $hasAny = (count($subFolders) > 0 || count($curFiles) > 0);
                    if (!$hasAny) {
                        echo '<tr><td colspan="5" class="text-center text-muted py-4">当前目录为空</td></tr>';
                    }

                    // 文件夹行（排前面，点击进入）
                    foreach ($subFolders as $f) {
                        $canDel = (isset($f['created_by']) && $f['created_by'] === $user['id']) || $canAdmin;
                        $link = 'drive.php?dir=' . urlencode($f['path']);
                        echo '<tr class="drive-folder-row" data-dir="' . e($f['path']) . '">';
                        echo '<td><span class="drive-ico">📁</span><a class="drive-name" href="' . $link . '">' . e($f['name']) . '</a></td>';
                        echo '<td class="text-muted small">文件夹</td>';
                        echo '<td>' . e(isset($f['creator_name']) ? $f['creator_name'] : '未知') . '</td>';
                        echo '<td class="text-muted small">' . e(isset($f['created_at']) ? $f['created_at'] : '') . '</td>';
                        echo '<td class="drive-actions">';
                        if ($canDel) {
                            echo '<button class="btn btn-outline-danger btn-sm drive-rmdir" data-path="' . e($f['path']) . '">删除</button>';
                        }
                        echo '</td></tr>';
                    }

                    // 文件行（点击下载）
                    $pvMap = array('pdf' => 1, 'jpg' => 1, 'jpeg' => 1, 'png' => 1, 'gif' => 1, 'webp' => 1,
                        'txt' => 1, 'md' => 1, 'log' => 1, 'cpp' => 1, 'h' => 1, 'hpp' => 1, 'c' => 1, 'cc' => 1,
                        'java' => 1, 'py' => 1, 'js' => 1, 'css' => 1, 'json' => 1, 'xml' => 1, 'html' => 1, 'htm' => 1, 'sql' => 1,
                        'doc' => 1, 'docx' => 1, 'xls' => 1, 'xlsx' => 1, 'ppt' => 1, 'pptx' => 1, 'epub' => 1);
                    foreach ($curFiles as $f) {
                        $canDel = (isset($f['uploaded_by']) && $f['uploaded_by'] === $user['id']) || $canAdmin;
                        $orig = isset($f['orig']) ? $f['orig'] : $f['stored'];
                        $dl = 'api/drive.php?action=download&folder=' . urlencode($dir) . '&file=' . urlencode($f['stored']);
                        echo '<tr class="drive-file-row" data-dl="' . $dl . '">';
                        echo '<td><span class="drive-ico">' . drive_ico($orig) . '</span><a class="drive-name" href="' . $dl . '">' . e($orig) . '</a></td>';
                        echo '<td>' . fmt_size(isset($f['size']) ? $f['size'] : 0) . '</td>';
                        echo '<td>' . e(isset($f['uploader_name']) ? $f['uploader_name'] : '未知') . '</td>';
                        echo '<td class="text-muted small">' . e(isset($f['created_at']) ? $f['created_at'] : '') . '</td>';
                        echo '<td class="drive-actions">';
                        if (isset($pvMap[strtolower(pathinfo($orig, PATHINFO_EXTENSION))])) {
                            $pext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                            $pv = ($pext === 'epub' ? 'epub.php' : 'preview.php') . '?type=drive&folder=' . urlencode($dir) . '&file=' . urlencode($f['stored']);
                            echo '<a class="btn btn-outline-primary btn-sm" href="' . $pv . '">预览</a>';
                        }
                        echo '<a class="btn btn-outline-primary btn-sm" href="' . $dl . '">下载</a>';
                        if ($canDel) {
                            echo '<button class="btn btn-outline-secondary btn-sm drive-rename" data-id="' . e($f['id']) . '" data-name="' . e($orig) . '">改名</button>';
                            echo '<button class="btn btn-outline-secondary btn-sm drive-move" data-id="' . e($f['id']) . '">移动</button>';
                            echo '<button class="btn btn-outline-danger btn-sm drive-del" data-id="' . e($f['id']) . '">删除</button>';
                        }
                        echo '</td></tr>';
                    }
                    ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
var DRIVE_TOKEN = '<?php echo csrf_token(); ?>';
var DRIVE_DIR = '<?php echo e($dir); ?>';

function postForm(url, fd, cb) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', url);
    xhr.onload = function () {
        var res = {};
        try { res = JSON.parse(xhr.responseText); } catch (err) {}
        if (xhr.status === 401) { location.href = 'login.php'; return; }
        cb(xhr.status, res);
    };
    xhr.onerror = function () { cb(0, { error: '网络错误，请重试' }); };
    xhr.send(fd);
}

// 行点击：文件夹进入，文件下载
document.querySelector('.drive-list tbody').addEventListener('click', function (e) {
    if (e.target.closest('a') || e.target.closest('button')) return;
    var tr = e.target.closest('tr');
    if (!tr) return;
    if (tr.classList.contains('drive-folder-row')) {
        location.href = 'drive.php?dir=' + encodeURIComponent(tr.getAttribute('data-dir'));
    } else if (tr.classList.contains('drive-file-row') && tr.getAttribute('data-dl')) {
        location.href = tr.getAttribute('data-dl');
    }
});

// 上传单文件
document.getElementById('uploadForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var input = document.getElementById('driveFile');
    if (!input.files || !input.files.length) { alert('请先选择文件'); return; }
    var fd = new FormData();
    fd.append('file', input.files[0]);
    fd.append('folder', DRIVE_DIR);
    fd.append('csrf_token', DRIVE_TOKEN);
    var btn = document.getElementById('uploadBtn');
    btn.disabled = true;
    btn.textContent = '上传中…';
    postForm('api/drive.php?action=upload', fd, function (status, res) {
        if (status === 200 && res.ok) { location.reload(); return; }
        alert((res && res.error) || '上传失败，请稍后重试');
        btn.disabled = false;
        btn.textContent = '上传文件';
    });
});

// 上传整个文件夹（webkitdirectory，保留子目录结构）
document.getElementById('uploadFolderBtn').addEventListener('click', function () {
    var input = document.getElementById('driveFolder');
    if (!input.files || !input.files.length) { alert('请先选择要上传的文件夹'); return; }
    var files = Array.prototype.slice.call(input.files);
    var btn = this;
    btn.disabled = true;
    var done = 0, failed = 0, total = files.length;
    btn.textContent = '上传中 0/' + total;
    function next() {
        if (!files.length) {
            alert('上传完成：成功 ' + (total - failed) + ' 个，失败 ' + failed + ' 个');
            location.reload();
            return;
        }
        var f = files.shift();
        var rel = f.webkitRelativePath || f.name;
        var parts = rel.split('/');
        var folderName = parts[0];
        parts.shift();
        var sub = parts.join('/');
        var fd = new FormData();
        fd.append('file', f, f.name);
        fd.append('folder', DRIVE_DIR === '' ? folderName : DRIVE_DIR + '/' + folderName);
        fd.append('subdir', sub);
        fd.append('csrf_token', DRIVE_TOKEN);
        postForm('api/drive.php?action=upload', fd, function (status, res) {
            done++;
            if (!(status === 200 && res.ok)) { failed++; }
            btn.textContent = '上传中 ' + done + '/' + total;
            next();
        });
    }
    next();
});

// 新建文件夹
document.getElementById('mkdirBtn').addEventListener('click', function () {
    var input = document.getElementById('newFolderName');
    var name = input.value.trim();
    if (!name) { alert('请输入文件夹名称'); return; }
    var btn = this;
    btn.disabled = true;
    var body = 'parent=' + encodeURIComponent(DRIVE_DIR)
        + '&name=' + encodeURIComponent(name)
        + '&csrf_token=' + encodeURIComponent(DRIVE_TOKEN);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'api/drive.php?action=mkdir');
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function () {
        var res = {};
        try { res = JSON.parse(xhr.responseText); } catch (err) {}
        if (xhr.status === 401) { location.href = 'login.php'; return; }
        if (xhr.status === 200 && res.ok) { location.reload(); return; }
        alert((res && res.error) || '创建失败');
        btn.disabled = false;
    };
    xhr.onerror = function () { alert('网络错误，请重试'); btn.disabled = false; };
    xhr.send(body);
});

// 事件委托：删除文件 / 删除文件夹
document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t.classList) return;
    if (t.classList.contains('drive-del')) {
        var id = t.getAttribute('data-id');
        if (!confirm('确定删除该文件吗？删除后不可恢复。')) return;
        t.disabled = true;
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'api/drive.php?action=delete');
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function () {
            var res = {};
            try { res = JSON.parse(xhr.responseText); } catch (err) {}
            if (xhr.status === 200 && res.ok) { location.reload(); return; }
            if (xhr.status === 401) { location.href = 'login.php'; return; }
            alert((res && res.error) || '删除失败，请稍后重试');
            t.disabled = false;
        };
        xhr.onerror = function () { alert('删除失败，请检查网络'); t.disabled = false; };
        xhr.send('file_id=' + encodeURIComponent(id) + '&csrf_token=' + encodeURIComponent(DRIVE_TOKEN));
        return;
    }
    if (t.classList.contains('drive-rmdir')) {
        var path = t.getAttribute('data-path');
        if (!confirm('确定删除该文件夹及其全部内容吗？删除后不可恢复。')) return;
        t.disabled = true;
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'api/drive.php?action=rmdir');
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function () {
            var res = {};
            try { res = JSON.parse(xhr.responseText); } catch (err) {}
            if (xhr.status === 200 && res.ok) { location.reload(); return; }
            if (xhr.status === 401) { location.href = 'login.php'; return; }
            alert((res && res.error) || '删除失败，请稍后重试');
            t.disabled = false;
        };
        xhr.onerror = function () { alert('删除失败，请检查网络'); t.disabled = false; };
        xhr.send('path=' + encodeURIComponent(path) + '&csrf_token=' + encodeURIComponent(DRIVE_TOKEN));
    }
});
// 重命名 / 移动文件（委托）
document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t.classList) return;
    var isRename = t.classList.contains('drive-rename');
    var isMove = t.classList.contains('drive-move');
    if (!isRename && !isMove) return;
    var id = t.getAttribute('data-id');
    var input;
    if (isRename) {
        input = prompt('修改显示名（物理文件名不变）：', t.getAttribute('data-name') || '');
        if (input === null) return;
        input = input.trim();
        if (!input) { alert('名称不能为空'); return; }
    } else {
        input = prompt('移动到哪个文件夹？\n留空 = 根目录；多级用 / 分隔；目标文件夹需已存在', DRIVE_DIR);
        if (input === null) return;
        input = input.trim();
    }
    t.disabled = true;
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'api/drive.php?action=' + (isRename ? 'rename' : 'move'));
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function () {
        var res = {};
        try { res = JSON.parse(xhr.responseText); } catch (err) {}
        if (xhr.status === 401) { location.href = 'login.php'; return; }
        if (xhr.status === 200 && res.ok) { location.reload(); return; }
        alert((res && res.error) || '操作失败');
        t.disabled = false;
    };
    xhr.onerror = function () { alert('网络错误，请重试'); t.disabled = false; };
    xhr.send('file_id=' + encodeURIComponent(id)
        + (isRename ? '&new_name=' : '&new_folder=') + encodeURIComponent(input)
        + '&csrf_token=' + encodeURIComponent(DRIVE_TOKEN));
});
</script>
<?php
page_footer();
