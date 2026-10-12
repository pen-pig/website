<?php
/**
 * 文件资源管理器（仅管理员）
 * - 需 admin 角色 + 独立访问密码（settings.json 的 filemanager_pass 字段，缺省初始值 20140103csc）
 * - 浏览/编辑/删除/重命名/移动站点根目录内文件，realpath 校验防路径穿越
 * - 写操作全部 POST + CSRF；仅允许编辑文本白名单扩展名，二进制/图片不可编辑
 * 兼容 PHP 5.6
 */
require_once __DIR__ . '/includes/config.php';
$admin = require_role(array('admin'));

/* ========== 工具函数 ========== */

/** 站点根目录真实路径 */
function fm_base() {
    static $base = null;
    if ($base === null) {
        $base = realpath(WEB_DIR);
        if ($base === false) $base = '';
    }
    return $base;
}

/**
 * 相对路径解析为站点根内的绝对路径
 * 越界（../ 逃逸）、不存在、非法路径一律返回 false
 */
function fm_resolve($rel) {
    $base = fm_base();
    if ($base === '') return false;
    $rel = (string)$rel;
    $rel = str_replace('\\', '/', $rel);
    if ($rel === '' || $rel === '.' || $rel === '/') return $base;
    $real = realpath($base . '/' . $rel);
    if ($real === false) return false;
    if ($real === $base || strpos($real, $base . DIRECTORY_SEPARATOR) === 0) {
        return $real;
    }
    return false;
}

/** 文本编辑白名单扩展名 */
function fm_allowed_ext($name) {
    static $exts = array('php','phtml','html','htm','js','json','txt','md','css','xml','ini','log','sql','csv','yaml','yml','conf');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return in_array($ext, $exts);
}

/** 文件大小格式化 */
function fm_size($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

/** 是否已通过文件管理器密码验证 */
function fm_unlocked() {
    return !empty($_SESSION['fm_unlocked']);
}

/** 未解锁则提示并回到密码页 */
function fm_require_unlocked() {
    if (!fm_unlocked()) {
        $_SESSION['flash'] = '请先输入文件管理器访问密码';
        redirect('filemanager.php');
    }
}

/** 读取访问密码（settings.json 字段，缺省使用初始值） */
function fm_get_pass() {
    $s = jdb_read(JSON_DIR . '/settings.json');
    if (is_array($s) && isset($s['filemanager_pass']) && $s['filemanager_pass'] !== '') {
        return (string)$s['filemanager_pass'];
    }
    return '20140103csc';
}

/** 渲染密码页（未解锁时唯一出口：错误提示不被后续跳转覆盖） */
function fm_render_password_page($msg = '') {
    page_header('文件管理器 - 访问密码', 'admin');
    if ($msg !== '') flash_toast($msg);
    echo '<div class="row justify-content-center mt-4"><div class="col-md-5">';
    echo '<div class="card"><div class="card-header"><strong>文件管理器访问密码</strong></div><div class="card-body">';
    echo '<p class="small text-muted">仅管理员可进入，请输入访问密码解锁文件管理。</p>';
    echo '<form method="post" action="filemanager.php">' . csrf_field();
    echo '<input type="hidden" name="action" value="unlock">';
    echo '<div class="form-group"><label>访问密码</label><input type="password" name="password" class="form-control" autocomplete="off" autofocus></div>';
    echo '<button class="btn btn-primary">解锁</button>';
    echo '</form></div></div></div></div>';
    page_footer();
    exit;
}

/* ========== 会话闪现 ========== */
$flash = '';
if (isset($_SESSION['flash'])) { $flash = $_SESSION['flash']; unset($_SESSION['flash']); }

/* ========== POST 处理 ========== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    // 解锁：验证访问密码（失败直接停留密码页，不被后续拦截跳转覆盖）
    if ($action === 'unlock') {
        $pwd = isset($_POST['password']) ? (string)$_POST['password'] : '';
        if (hash_equals(fm_get_pass(), $pwd)) {
            $_SESSION['fm_unlocked'] = true;
            $_SESSION['flash'] = '文件管理器已解锁';
            redirect('filemanager.php');
        }
        fm_render_password_page('密码错误，请重试');
    }

    // 其余操作必须先解锁
    fm_require_unlocked();

    if ($action === 'lock') {
        unset($_SESSION['fm_unlocked']);
        $_SESSION['flash'] = '文件管理器已锁定';
        redirect('filemanager.php');
    }

    // 修改访问密码
    if ($action === 'change_pass') {
        $old = isset($_POST['old_pass']) ? (string)$_POST['old_pass'] : '';
        $new1 = isset($_POST['new_pass']) ? (string)$_POST['new_pass'] : '';
        $new2 = isset($_POST['new_pass2']) ? (string)$_POST['new_pass2'] : '';
        if (!hash_equals(fm_get_pass(), $old)) {
            $flash = '原密码不正确';
        } elseif (strlen($new1) < 6 || strlen($new1) > 64) {
            $flash = '新密码长度需为 6-64 位';
        } elseif ($new1 !== $new2) {
            $flash = '两次输入的新密码不一致';
        } else {
            $s = jdb_read(JSON_DIR . '/settings.json');
            if (!is_array($s)) $s = array();
            $s['filemanager_pass'] = $new1;
            if (jdb_write(JSON_DIR . '/settings.json', $s)) {
                $GLOBALS['SETTINGS'] = $s;
                add_log('文件管理器', '修改访问密码');
                $_SESSION['flash'] = '文件管理器密码已更新';
                redirect('filemanager.php');
            }
            $flash = '密码保存失败，请检查 data/json 目录写入权限';
        }
    }

    // 编辑保存：临时文件 + rename 原子替换
    if ($action === 'save') {
        $rel = isset($_POST['file']) ? trim((string)$_POST['file']) : '';
        $content = isset($_POST['content']) ? (string)$_POST['content'] : '';
        $real = fm_resolve($rel);
        if ($real === false || !is_file($real)) {
            $flash = '文件不存在或路径非法，已拒绝保存';
        } elseif (!fm_allowed_ext($real)) {
            $flash = '该文件类型不在可编辑白名单内，已拒绝保存';
        } elseif (strlen($content) > 1048576) {
            $flash = '内容超过 1MB 限制，保存失败';
        } else {
            $tmp = dirname($real) . '/.' . basename($real) . '.tmp' . uniqid();
            $ok = @file_put_contents($tmp, $content);
            $ren = false;
            if ($ok !== false) {
                $ren = @rename($tmp, $real);
                if (!$ren) $ren = @copy($tmp, $real); // Windows 下 rename 偶发失败时兜底
            }
            @unlink($tmp);
            if ($ren) {
                add_log('文件管理器', '编辑保存：' . $rel . '（' . strlen($content) . ' 字节）');
                $_SESSION['flash'] = '已保存：' . $rel;
                redirect('filemanager.php?dir=' . rawurlencode(dirname($rel)));
            }
            $flash = '保存失败，请检查文件写入权限';
        }
    }

    // 删除（仅文件，目录拒绝）
    if ($action === 'delete') {
        $rel = isset($_POST['file']) ? trim((string)$_POST['file']) : '';
        $real = fm_resolve($rel);
        if ($real === false || !is_file($real)) {
            $flash = '文件不存在或路径非法；仅支持删除文件，目录不会被删除';
        } elseif (@unlink($real)) {
            add_log('文件管理器', '删除：' . $rel);
            $_SESSION['flash'] = '已删除：' . $rel;
            redirect('filemanager.php?dir=' . rawurlencode(dirname($rel)));
        } else {
            $flash = '删除失败，请检查文件权限';
        }
    }

    // 重命名（文件/目录均可，名称禁止含路径分隔符）
    if ($action === 'rename') {
        $rel = isset($_POST['file']) ? trim((string)$_POST['file']) : '';
        $new = isset($_POST['new_name']) ? trim((string)$_POST['new_name']) : '';
        $real = fm_resolve($rel);
        if ($real === false) {
            $flash = '文件或目录不存在、路径非法';
        } elseif ($new === '' || $new === '.' || $new === '..' || strlen($new) > 200
                  || strpos($new, '/') !== false || strpos($new, '\\') !== false) {
            $flash = '新名称非法（不能为空，不能包含路径分隔符）';
        } elseif (strpos($new, '.') === 0) {
            $flash = '不允许重命名为隐藏名称';
        } else {
            $dest = dirname($real) . '/' . $new;
            if (file_exists($dest)) {
                $flash = '目标名称已存在，请换一个名称';
            } elseif (@rename($real, $dest)) {
                add_log('文件管理器', '重命名：' . $rel . ' => ' . $new);
                $_SESSION['flash'] = '已重命名：' . basename($rel) . ' -> ' . $new;
                redirect('filemanager.php?dir=' . rawurlencode(dirname($rel)));
            } else {
                $flash = '重命名失败，请检查文件权限';
            }
        }
    }

    // 移动（仅文件，目标目录必须存在且在根内）
    if ($action === 'move') {
        $rel = isset($_POST['file']) ? trim((string)$_POST['file']) : '';
        $destRel = isset($_POST['dest_dir']) ? trim((string)$_POST['dest_dir']) : '';
        $real = fm_resolve($rel);
        $dest = fm_resolve($destRel);
        if ($real === false || !is_file($real)) {
            $flash = '源文件不存在或路径非法';
        } elseif ($dest === false || !is_dir($dest)) {
            $flash = '目标目录不存在或路径非法';
        } elseif ($dest === dirname($real)) {
            $flash = '文件已在该目录中，无需移动';
        } else {
            $newFull = $dest . '/' . basename($real);
            if (file_exists($newFull)) {
                $flash = '目标目录已存在同名文件，请先处理';
            } elseif (@rename($real, $newFull)) {
                add_log('文件管理器', '移动：' . $rel . ' => ' . $destRel . '/');
                $_SESSION['flash'] = '已移动：' . $rel;
                redirect('filemanager.php?dir=' . rawurlencode(dirname($rel)));
            } else {
                $flash = '移动失败，请检查目录权限';
            }
        }
    }
}

/* ========== 未解锁：仅显示密码页 ========== */
if (!fm_unlocked()) {
    fm_render_password_page($flash);
}

/* ========== 当前浏览目录（POST 保持目录用 $_POST['dir']） ========== */
$browseRel = isset($_POST['dir']) ? trim((string)$_POST['dir']) : (isset($_GET['dir']) ? trim((string)$_GET['dir']) : '');
$browseRel = ltrim(str_replace('\\', '/', $browseRel), '/');
$cur = fm_resolve($browseRel);
if ($cur === false || !is_dir($cur)) {
    $_SESSION['flash'] = '目录不存在或路径非法';
    redirect('filemanager.php');
}
$parentRel = $browseRel === '' ? '' : dirname($browseRel);
if ($parentRel === '.' || $parentRel === '/') $parentRel = '';

/* ========== 操作表单页 / 编辑页（GET） ========== */
$act = isset($_GET['act']) ? $_GET['act'] : '';
$opFile = isset($_GET['file']) ? trim((string)$_GET['file']) : '';
$editRel = isset($_GET['edit']) ? trim((string)$_GET['edit']) : '';

page_header('文件管理器', 'admin');
if ($flash !== '') flash_toast($flash);

$backLink = 'filemanager.php?dir=' . rawurlencode($browseRel);

/* --- 重命名 / 移动表单 --- */
if (($act === 'rename' || $act === 'move') && $opFile !== '') {
    $real = fm_resolve($opFile);
    if ($real === false) {
        echo '<div class="alert alert-danger">目标不存在或路径非法，无法操作。</div><a class="btn btn-secondary" href="' . e($backLink) . '">返回列表</a>';
    } else {
        echo '<div class="row"><div class="col-md-6">';
        echo '<div class="card"><div class="card-header"><strong>' . ($act === 'rename' ? '重命名' : '移动文件') . '</strong></div><div class="card-body">';
        echo '<p class="small text-muted mb-2">当前路径：<code>' . e($opFile) . '</code></p>';
        echo '<form method="post" action="filemanager.php">' . csrf_field();
        echo '<input type="hidden" name="dir" value="' . e($browseRel) . '">';
        if ($act === 'rename') {
            echo '<input type="hidden" name="action" value="rename">';
            echo '<input type="hidden" name="file" value="' . e($opFile) . '">';
            echo '<div class="form-group"><label>新名称</label><input type="text" name="new_name" class="form-control" value="' . e(basename($opFile)) . '" autofocus></div>';
            echo '<button class="btn btn-primary">确认重命名</button>';
        } else {
            echo '<input type="hidden" name="action" value="move">';
            echo '<input type="hidden" name="file" value="' . e($opFile) . '">';
            echo '<div class="form-group"><label>目标目录（相对站点根目录）</label><input type="text" name="dest_dir" class="form-control" placeholder="如 admin 或 assets/css" autofocus></div>';
            echo '<small class="form-text text-muted">填写相对路径，如 admin、includes、assets/css；不可越出站点根目录。</small>';
            echo '<button class="btn btn-primary mt-2">确认移动</button>';
        }
        echo '</form>';
        echo '<a class="btn btn-outline-secondary mt-3" href="' . e($backLink) . '">返回列表</a>';
        echo '</div></div></div></div>';
    }
    page_footer();
    exit;
}

/* --- 编辑页 --- */
if ($editRel !== '') {
    $real = fm_resolve($editRel);
    if ($real === false || !is_file($real)) {
        echo '<div class="alert alert-danger">文件不存在或路径非法。</div><a class="btn btn-secondary" href="' . e($backLink) . '">返回列表</a>';
    } elseif (!fm_allowed_ext($real)) {
        echo '<div class="alert alert-warning">该文件类型（.' . e(pathinfo($editRel, PATHINFO_EXTENSION)) . '）不在可编辑白名单内，无法在线编辑。</div><a class="btn btn-secondary" href="' . e($backLink) . '">返回列表</a>';
    } elseif (filesize($real) > 1048576) {
        echo '<div class="alert alert-warning">文件超过 1MB，出于安全考虑不允许在线编辑。</div><a class="btn btn-secondary" href="' . e($backLink) . '">返回列表</a>';
    } else {
        $content = @file_get_contents($real);
        if ($content === false) {
            echo '<div class="alert alert-danger">读取文件失败，请检查权限。</div><a class="btn btn-secondary" href="' . e($backLink) . '">返回列表</a>';
        } else {
            $lines = substr_count($content, "\n") + 1;
            $rows = max(12, min(36, $lines));
            echo '<div class="row"><div class="col-lg-9">';
            echo '<div class="card"><div class="card-header"><strong>编辑文件</strong> <code>' . e($editRel) . '</code></div><div class="card-body">';
            if (strtolower(pathinfo($editRel, PATHINFO_EXTENSION)) === 'php') {
                echo '<div class="alert alert-warning py-2 small">正在编辑 PHP 文件：保存后立即生效，请谨慎修改语法，可先使用 <code>php -l</code> 自检。</div>';
            }
            echo '<form method="post" action="filemanager.php" id="fmEditForm">' . csrf_field();
            echo '<input type="hidden" name="dir" value="' . e($browseRel) . '">';
            echo '<input type="hidden" name="action" value="save">';
            echo '<input type="hidden" name="file" value="' . e($editRel) . '">';
            echo '<div class="form-group"><textarea name="content" class="form-control" style="font-family:Consolas,Menlo,monospace;font-size:13px;white-space:pre;overflow-x:auto;" rows="' . $rows . '" spellcheck="false">' . e($content) . '</textarea></div>';
            echo '<button class="btn btn-primary" onclick="return confirm(\'确认保存修改？\')">保存</button> ';
            echo '<a class="btn btn-outline-secondary" href="' . e($backLink) . '">取消返回</a>';
            echo '</form>';
            echo '</div></div></div></div>';
        }
    }
    page_footer();
    exit;
}

/* ========== 文件列表 ========== */
echo '<div class="d-flex align-items-center mb-3">';
echo '<h5 class="mb-0 mr-3">文件管理器</h5>';
echo '<span class="text-muted small mr-auto">站点根目录：<code>' . e(WEB_DIR) . '</code></span>';
echo '<form method="post" action="filemanager.php" class="mr-2">' . csrf_field() . '<input type="hidden" name="action" value="lock"><button class="btn btn-outline-warning btn-sm">锁定</button></form>';
echo '</div>';

// 面包屑
echo '<nav class="small mb-2" aria-label="breadcrumb"><ol class="breadcrumb" style="background:#fff;border:1px solid var(--line);">';
echo '<li class="breadcrumb-item"><a href="filemanager.php">根目录</a></li>';
$crumbRel = '';
$parts = explode('/', $browseRel);
foreach ($parts as $part) {
    if ($part === '') continue;
    $crumbRel = $crumbRel === '' ? $part : $crumbRel . '/' . $part;
    echo '<li class="breadcrumb-item"><a href="filemanager.php?dir=' . rawurlencode($crumbRel) . '">' . e($part) . '</a></li>';
}
echo '</ol></nav>';

echo '<div class="card"><div class="card-body p-0">';
echo '<div class="table-responsive"><table class="table table-sm table-hover mb-0">';
echo '<thead><tr><th>名称</th><th>类型</th><th>大小</th><th>修改时间</th><th style="width:230px;">操作</th></tr></thead><tbody>';
if ($parentRel !== '') {
    echo '<tr><td colspan="5"><a href="filemanager.php?dir=' . rawurlencode($parentRel) . '">↑ 返回上级目录</a></td></tr>';
}
$items = array();
if (is_dir($cur)) {
    $entries = @scandir($cur);
    if ($entries === false) $entries = array();
    foreach ($entries as $name) {
        if ($name === '.' || $name === '..') continue;
        if (strpos($name, '.') === 0) continue; // 隐藏文件/目录不展示
        $full = $cur . '/' . $name;
        $items[] = array(
            'name'  => $name,
            'full'  => $full,
            'rel'   => $browseRel === '' ? $name : $browseRel . '/' . $name,
            'isDir' => is_dir($full)
        );
    }
}
usort($items, function ($a, $b) {
    if ($a['isDir'] !== $b['isDir']) return $a['isDir'] ? -1 : 1;
    return strcasecmp($a['name'], $b['name']);
});
foreach ($items as $it) {
    $name = $it['name'];
    $rel = $it['rel'];
    $isDir = $it['isDir'];
    $stat = @stat($it['full']);
    $size = $isDir ? '-' : fm_size($stat ? $stat['size'] : 0);
    $mtime = $stat ? date('Y-m-d H:i', $stat['mtime']) : '-';
    $type = $isDir ? '目录' : strtoupper(pathinfo($name, PATHINFO_EXTENSION));
    echo '<tr>';
    if ($isDir) {
        echo '<td><a href="filemanager.php?dir=' . rawurlencode($rel) . '"><strong>' . e($name) . '/</strong></a></td>';
        echo '<td><span class="badge badge-secondary">目录</span></td>';
    } else {
        echo '<td><a href="/' . e($rel) . '" target="_blank">' . e($name) . '</a></td>';
        echo '<td><span class="badge badge-light">' . e($type) . '</span></td>';
    }
    echo '<td>' . $size . '</td><td>' . $mtime . '</td>';
    echo '<td class="text-nowrap">';
    if (!$isDir) {
        if (fm_allowed_ext($name)) {
            echo '<a class="btn btn-sm btn-outline-primary" href="filemanager.php?edit=' . rawurlencode($rel) . '">编辑</a> ';
        }
        echo '<a class="btn btn-sm btn-outline-secondary" href="filemanager.php?act=rename&amp;file=' . rawurlencode($rel) . '">重命名</a> ';
        echo '<a class="btn btn-sm btn-outline-secondary" href="filemanager.php?act=move&amp;file=' . rawurlencode($rel) . '">移动</a> ';
        echo '<form method="post" action="filemanager.php" class="d-inline" onsubmit="return confirm(\'确定删除文件 ' . e($name) . '？删除后不可恢复。\')">' . csrf_field();
        echo '<input type="hidden" name="action" value="delete"><input type="hidden" name="file" value="' . e($rel) . '"><input type="hidden" name="dir" value="' . e($browseRel) . '">';
        echo '<button class="btn btn-sm btn-outline-danger">删除</button></form>';
    } else {
        echo '<a class="btn btn-sm btn-outline-secondary" href="filemanager.php?act=rename&amp;file=' . rawurlencode($rel) . '">重命名</a> ';
        echo '<span class="text-muted small">目录不支持删除/移动</span>';
    }
    echo '</td></tr>';
}
echo '</tbody></table></div>';
echo '</div></div>';

// 修改密码
echo '<div class="row mt-3"><div class="col-md-6">';
echo '<div class="card"><div class="card-header"><strong>修改访问密码</strong></div><div class="card-body">';
echo '<form method="post" action="filemanager.php">' . csrf_field();
echo '<input type="hidden" name="action" value="change_pass"><input type="hidden" name="dir" value="' . e($browseRel) . '">';
echo '<div class="form-group"><label>原密码</label><input type="password" name="old_pass" class="form-control" autocomplete="off"></div>';
echo '<div class="form-group"><label>新密码（6-64 位）</label><input type="password" name="new_pass" class="form-control" autocomplete="off"></div>';
echo '<div class="form-group"><label>确认新密码</label><input type="password" name="new_pass2" class="form-control" autocomplete="off"></div>';
echo '<button class="btn btn-primary">更新密码</button>';
echo '</form></div></div></div></div>';

page_footer();
