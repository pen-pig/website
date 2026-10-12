<?php
/**
 * 公共函数库：输出转义、JSON 响应、CSRF、验证码、上传、模板、工具扫描、未读数
 * 兼容 PHP 5.6
 */

/**
 * HTML 输出转义，防 XSS
 */
function e($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

/**
 * 解析 #hex 颜色为 RGB 数组（兼容 3/6 位十六进制）
 * @return array array(r, g, b)
 */
function hex_rgb($hex) {
    $hex = ltrim((string)$hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    return array($r, $g, $b);
}

/**
 * 按系数调暗/调亮 #hex 颜色（factor < 1 变暗，> 1 变亮）
 */
function hex_shade($hex, $factor) {
    list($r, $g, $b) = hex_rgb($hex);
    $r = max(0, min(255, (int)round($r * $factor)));
    $g = max(0, min(255, (int)round($g * $factor)));
    $b = max(0, min(255, (int)round($b * $factor)));
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

/**
 * 页面跳转
 */
function redirect($url) {
    header('Location: ' . $url);
    exit;
}

/**
 * 输出 JSON 并结束
 */
function json_response($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 生成唯一 ID，如 u_5f3a1b2c3d4e5
 */
function generate_id($prefix) {
    return $prefix . '_' . uniqid();
}

/**
 * 生成 CSRF token 并存入 session
 */
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(16));
    }
    return $_SESSION['csrf_token'];
}

/**
 * 输出 CSRF 隐藏域
 */
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * 校验 CSRF token（表单 POST 必须调用）
 */
function check_csrf() {
    $token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if ($token === '' && isset($_GET['token'])) $token = $_GET['token'];
    if ($token === '' || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        if (is_ajax()) {
            json_response(array('ok' => false, 'error' => 'CSRF 校验失败，请刷新页面重试'), 403);
        }
        die('CSRF 校验失败');
    }
}

/**
 * 生成算术题验证码，答案存入 session，返回题目文本
 * 例：3 + 5 = ?
 */
function arithmetic_captcha() {
    $a = rand(1, 9);
    $b = rand(1, 9);
    $op = rand(0, 1) ? '+' : '-';
    if ($op === '-' && $b > $a) {
        list($a, $b) = array($b, $a); // 保证减法结果非负
    }
    $answer = $op === '+' ? ($a + $b) : ($a - $b);
    $_SESSION['captcha_answer'] = (string)$answer;
    $_SESSION['captcha_ts'] = time();
    return $a . ' ' . $op . ' ' . $b . ' = ?';
}

/**
 * 校验算术题验证码，校验后销毁
 */
function check_captcha($input) {
    if (!isset($_SESSION['captcha_answer'])) {
        return false;
    }
    $ok = (strtolower((string)$input) === strtolower((string)$_SESSION['captcha_answer']));
    unset($_SESSION['captcha_answer'], $_SESSION['captcha_ts']);
    return $ok;
}

/**
 * 处理上传文件（POST 字段）
 * @param string $field     表单字段名
 * @param string $subdir    data/uploads/ 下的子目录：materials / homework / notices
 * @param int    $maxsize   最大字节，默认 100MB
 * @return array 成功返回 array('ok'=>true,'stored_name'=>..,'orig_name'=>..,'size'=>..)，失败返回 array('ok'=>false,'error'=>..)
 */
function handle_upload($field, $subdir, $maxsize = 104857600) {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return array('ok' => false, 'error' => '未选择文件');
    }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        return array('ok' => false, 'error' => '上传失败，错误码：' . $f['error']);
    }
    if ($f['size'] > $maxsize) {
        return array('ok' => false, 'error' => '文件超过大小限制（最大 100MB）');
    }
    // 拒绝可执行脚本（不区分大小写）
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $blocked = array('php', 'phtml', 'php5', 'php7', 'phps', 'pht', 'php3', 'php4');
    if (in_array($ext, $blocked)) {
        return array('ok' => false, 'error' => '不允许上传可执行脚本文件');
    }
    $dir = UPLOAD_DIR . '/' . $subdir;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    // 随机重命名，避免覆盖与猜测（保留扩展名用于下载时识别）
    $stored = date('YmdHis') . '_' . uniqid() . '.' . $ext;
    $dest = $dir . '/' . $stored;
    if (!@move_uploaded_file($f['tmp_name'], $dest)) {
        return array('ok' => false, 'error' => '文件保存失败，请检查目录权限');
    }
    return array(
        'ok' => true,
        'stored_name' => $stored,
        'orig_name'   => $f['name'],
        'size'        => $f['size']
    );
}

/**
 * 记录后台操作日志（写 data/json/logs.json）
 * @param string $action  动作类型（如 创建项目 / 切换项目状态）
 * @param string $detail  操作对象详情
 */
function add_log($action, $detail) {
    $logs = jdb_collection('logs', 'logs');
    $u = is_logged_in() ? current_user() : null;
    $logs[] = array(
        'id' => 'log_' . uniqid(),
        'time' => date('Y-m-d H:i:s'),
        'actor_id' => $u ? $u['id'] : '',
        'actor_name' => $u ? $u['name'] : '系统',
        'role' => $u ? $u['role'] : '',
        'action' => $action,
        'detail' => $detail
    );
    // 只保留最近 500 条，防止文件无限膨胀
    if (count($logs) > 500) {
        $logs = array_slice($logs, -500);
    }
    jdb_save_collection('logs', 'logs', $logs);
}

// 兼容别名：旧版日志函数名（新版统一为 add_log）。
// checkin/oj/paste/register 等页面仍调用 log_action，缺此函数会直接 fatal。
// $name 为旧版第三参数（补充操作者信息），拼入 detail 保留。
function log_action($action, $detail = '', $name = null) {
    if ($name !== null && $name !== '') { $detail = $detail . '（' . $name . '）'; }
    add_log($action, $detail);
}

/**
 * 扫描 web/tools/ 下的 .html 和 .php 文件，生成插件式工具列表
 * @return array array('tools'=>array(array('name'=>..,'file'=>..,'ext'=>..)), 'count'=>N)
 */
function get_tools_list() {
    $dir = WEB_DIR . '/tools';
    $tools = array();
    if (is_dir($dir)) {
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if ($ext === 'html' || $ext === 'php') {
                $stat = @stat($dir . '/' . $file);
                $tools[] = array(
                    'name' => pathinfo($file, PATHINFO_FILENAME),
                    'file' => $file,
                    'ext'  => $ext,
                    'size' => $stat ? $stat['size'] : 0,
                    'mtime'=> $stat ? date('Y-m-d H:i', $stat['mtime']) : ''
                );
            }
        }
    }
    usort($tools, function ($a, $b) { return strcmp($a['name'], $b['name']); });
    return array('tools' => $tools, 'count' => count($tools));
}

/**
 * 获取用户未读私信数量
 */
function unread_count($uid) {
    $messages = jdb_collection('messages', 'messages');
    $n = 0;
    foreach ($messages as $m) {
        if (isset($m['to_id']) && $m['to_id'] === $uid && empty($m['is_read'])) {
            $n++;
        }
    }
    return $n;
}

/**
 * 格式化时间显示
 */
function fmt_time($ts) {
    if (is_string($ts) && strtotime($ts)) {
        return date('Y-m-d H:i', strtotime($ts));
    }
    return $ts;
}

/**
 * 格式化文件大小显示（B / KB / MB / GB）
 */
function fmt_size($bytes) {
    $bytes = (float)$bytes;
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return (int)$bytes . ' B';
}

/**
 * 统计网盘当前已用空间（字节，递归扫描 DRIVE_DIR 物理文件）
 */
function drive_used_bytes() {
    return drive_dir_size(DRIVE_DIR);
}

/**
 * 递归统计目录大小（字节）
 */
function drive_dir_size($dir) {
    $total = 0;
    if (!is_dir($dir)) {
        return 0;
    }
    $dh = @opendir($dir);
    if (!$dh) {
        return 0;
    }
    while (($f = readdir($dh)) !== false) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        if (is_dir($p)) {
            $total += drive_dir_size($p);
        } elseif (is_file($p)) {
            $total += @filesize($p);
        }
    }
    closedir($dh);
    return $total;
}

/**
 * 规范化网盘相对路径（统一 / 分隔、去掉首尾斜杠、拒绝 . / .. / 空段）
 * @param string $p
 * @return string 规范化路径（根目录返回空字符串）；非法返回 null
 */
function drive_norm_path($p) {
    $p = str_replace('\\', '/', trim((string)$p));
    $p = trim($p, '/');
    if ($p === '') {
        return '';
    }
    $parts = array();
    foreach (explode('/', $p) as $seg) {
        if ($seg === '' || $seg === '.' || $seg === '..') {
            return null;
        }
        $parts[] = $seg;
    }
    return implode('/', $parts);
}

/**
 * 解析网盘相对路径到真实绝对路径，并校验在 DRIVE_DIR 白名单内
 * @param string $rel 规范化相对路径（可为空串表示根目录）
 * @return string|false 真实绝对路径；目录不存在或越界返回 false
 */
function drive_resolve($rel) {
    $root = realpath(DRIVE_DIR);
    if ($root === false) {
        return false;
    }
    $target = realpath(DRIVE_DIR . ($rel === '' ? '' : '/' . $rel));
    if ($target === false) {
        return false;
    }
    if ($target === $root || strpos($target, $root . DIRECTORY_SEPARATOR) === 0) {
        return $target;
    }
    return false;
}

/**
 * 递归删除目录（含内部文件与子目录）
 */
function drive_rrmdir($dir) {
    if (!is_dir($dir)) {
        return;
    }
    $dh = @opendir($dir);
    if (!$dh) {
        return;
    }
    while (($f = readdir($dh)) !== false) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        if (is_dir($p)) {
            drive_rrmdir($p);
        } else {
            @unlink($p);
        }
    }
    closedir($dh);
    @rmdir($dir);
}

/**
 * 网盘记录自愈：以物理目录为权威，扫描 DRIVE_DIR 并补齐缺失的文件/文件夹记录
 * 每次进入网盘页调用一次；仅当发现缺失时写入 json（写入前自动备份原文件为 .bak）
 * @return array array('added_files'=>N, 'added_folders'=>N, 'used'=>字节数)
 */
function drive_sync_records() {
    $physFiles = array();
    $physDirs  = array();
    $used = 0;
    if (is_dir(DRIVE_DIR)) {
        drive_walk(DRIVE_DIR, '', $physFiles, $physDirs, $used);
    }

    $files   = jdb_collection('drive', 'files');
    $folders = jdb_collection('drive_folders', 'folders');

    // 现有记录索引
    $knownFiles = array();
    foreach ($files as $f) {
        $fp = isset($f['folder']) ? $f['folder'] : '';
        $knownFiles[$fp === '' ? $f['stored'] : $fp . '/' . $f['stored']] = true;
    }
    $knownDirs = array();
    foreach ($folders as $f) {
        if (isset($f['path'])) $knownDirs[$f['path']] = true;
    }

    // 补齐文件夹记录（逐层，保证父级先建）
    $addedFolders = 0;
    foreach ($physDirs as $dir => $mtime) {
        if (isset($knownDirs[$dir])) continue;
        $segs = explode('/', $dir);
        $acc = '';
        $parent = '';
        foreach ($segs as $seg) {
            $acc = $acc === '' ? $seg : $acc . '/' . $seg;
            if (isset($knownDirs[$acc])) { $parent = $acc; continue; }
            $folders[] = array(
                'id'           => 'd_' . uniqid(),
                'name'         => $seg,
                'path'         => $acc,
                'parent'       => $parent,
                'created_by'   => 'unknown',
                'creator_name' => '未知（自动恢复）',
                'created_at'   => date('Y-m-d H:i:s', $mtime)
            );
            $knownDirs[$acc] = true;
            $addedFolders++;
            $parent = $acc;
        }
    }

    // 补齐文件记录（原文件名不可考，用存储名展示）
    $addedFiles = 0;
    foreach ($physFiles as $rel => $info) {
        if (isset($knownFiles[$rel])) continue;
        $parts = explode('/', $rel);
        $stored = array_pop($parts);
        $folder = implode('/', $parts);
        $files[] = array(
            'id'            => 'f_' . uniqid(),
            'stored'        => $stored,
            'orig'          => $stored,
            'size'          => $info['size'],
            'folder'        => $folder,
            'uploaded_by'   => 'unknown',
            'uploader_name' => '未知（自动恢复）',
            'created_at'    => date('Y-m-d H:i:s', $info['mtime'])
        );
        $knownFiles[$rel] = true;
        $addedFiles++;
    }

    // 有缺失才写盘（备份 .bak 后写入）
    if ($addedFiles > 0 || $addedFolders > 0) {
        $jf = JSON_DIR . '/drive.json';
        $ff = JSON_DIR . '/drive_folders.json';
        @copy($jf, $jf . '.bak');
        @copy($ff, $ff . '.bak');
        jdb_save_collection('drive', 'files', $files);
        jdb_save_collection('drive_folders', 'folders', $folders);
    }

    return array('added_files' => $addedFiles, 'added_folders' => $addedFolders, 'used' => $used);
}

/**
 * 递归遍历网盘物理目录，收集相对路径文件/文件夹与总大小
 */
function drive_walk($dir, $rel, &$physFiles, &$physDirs, &$used) {
    $dh = @opendir($dir);
    if (!$dh) return;
    while (($f = readdir($dh)) !== false) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        $r = $rel === '' ? $f : $rel . '/' . $f;
        if (is_dir($p)) {
            $physDirs[$r] = @filemtime($p);
            drive_walk($p, $r, $physFiles, $physDirs, $used);
        } elseif (is_file($p)) {
            $physFiles[$r] = array('size' => @filesize($p), 'mtime' => @filemtime($p));
            $used += @filesize($p);
        }
    }
    closedir($dh);
}

/**
 * 生成分页参数（简单版）
 */
function get_page_param($default = 1) {
    $p = isset($_GET['page']) ? intval($_GET['page']) : $default;
    return max(1, $p);
}

/**
 * 上传主题背景图到 web/assets/bg（公网可访问），成功返回相对 URL，失败返回 null
 * 仅接受图片类型（jpg/png/gif/webp），≤5MB，随机重命名；拒绝任何可执行扩展名
 */
function handle_theme_bg_upload($field, $prefix) {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;
    $f = $_FILES[$field];
    if ($f['size'] > 5 * 1024 * 1024) return null;
    $map = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp');
    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($f['tmp_name']);
    } else {
        $mime = '';
    }
    if (isset($map[$mime])) {
        $ext = $map[$mime];
    } else {
        // 兜底：按扩展名校验（仅图片）
        $e = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $e = ($e === 'jpeg') ? 'jpg' : $e;
        if (!in_array($e, array('jpg', 'png', 'gif', 'webp'))) return null;
        $ext = $e;
    }
    if (!is_dir(THEME_BG_DIR)) @mkdir(THEME_BG_DIR, 0755, true);
    $fname = preg_replace('/[^A-Za-z0-9_]/', '', $prefix) . '_' . uniqid() . '.' . $ext;
    $dest = THEME_BG_DIR . '/' . $fname;
    if (!move_uploaded_file($f['tmp_name'], $dest)) return null;
    return THEME_BG_URL . '/' . $fname;
}

/**
 * 删除主题背景图文件（仅限 /assets/bg 目录，防穿越）
 */
function remove_theme_bg($url) {
    if (preg_match('#^/assets/bg/([A-Za-z0-9_.\-]+)$#', $url, $m)) {
        $p = THEME_BG_DIR . '/' . $m[1];
        if (is_file($p)) @unlink($p);
    }
}

/**
 * 安全删除上传文件（仅限 data/uploads/ 下指定类型目录，防路径穿越）
 * 用于删除分支/项目时同步清理其下的资料、作业等物理文件，防止文件爆量
 * @param string $type   子目录名（materials / homework 等）
 * @param string $stored 记录中的存储文件名
 * @return bool 是否成功删除
 */
function remove_upload_file($type, $stored) {
    $name = basename(trim($stored));
    if ($name === '' || $name === '.' || $name === '..') return false;
    $dir = realpath(UPLOAD_DIR . '/' . $type);
    if ($dir === false || !is_dir($dir)) return false;
    $target = realpath($dir . '/' . $name);
    if ($target === false || strpos($target, $dir . DIRECTORY_SEPARATOR) !== 0) return false;
    if (!is_file($target)) return false;
    return @unlink($target);
}

/**
 * 页面统一头部（含导航栏、主色调、角色菜单、未读数徽标）
 * @param string $title  页面标题
 * @param string $active 当前菜单项标识
 */
function page_header($title, $active = '') {
    $s = $GLOBALS['SETTINGS'];
    $u = is_logged_in() ? current_user() : null;
    // 个人主题覆盖站点默认（用户自定义主色 / 背景图，个人优先于后台默认）
    $theme = ($u && isset($u['theme']) && is_array($u['theme'])) ? $u['theme'] : array();
    $color = (isset($theme['main']) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $theme['main']))
        ? $theme['main']
        : (isset($s['primary_color']) ? $s['primary_color'] : '#1e6bb8');
    $bgPat = '#^/assets/bg/[A-Za-z0-9_.\-]+$#';
    $pageBg = '';
    if (isset($theme['bg']) && $theme['bg'] !== '' && preg_match($bgPat, $theme['bg'])) {
        $pageBg = $theme['bg'];
    } elseif (isset($s['default_bg']) && $s['default_bg'] !== '' && preg_match($bgPat, $s['default_bg'])) {
        $pageBg = $s['default_bg'];
    }
    $mainDark = hex_shade($color, 0.82);
    $mainDeep = hex_shade($color, 0.60);
    $mainRgbArr = hex_rgb($color);
    $mainRgb = $mainRgbArr[0] . ',' . $mainRgbArr[1] . ',' . $mainRgbArr[2];
    $site = isset($s['site_name']) ? $s['site_name'] : '社团官网';
    $unread = $u ? unread_count($u['id']) : 0;
    $logo = isset($s['logo']) ? $s['logo'] : '';
    echo '<!DOCTYPE html>' . "\n";
    echo '<html lang="zh-CN">' . "\n";
    echo '<head>' . "\n";
    echo '<meta charset="UTF-8">' . "\n";
    echo '<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">' . "\n";
    echo '<title>' . e($title) . ' - ' . e($site) . '</title>' . "\n";
    echo '<link rel="stylesheet" href="/assets/vendor/bootstrap.min.css">' . "\n";
    echo '<link rel="stylesheet" href="/assets/css/style.css?v=11">' . "\n";
    $pageBgCss = $pageBg !== '' ? 'url("' . $pageBg . '")' : 'none';
    echo '<style>:root{--main:' . e($color) . ';--main-rgb:' . $mainRgb . ';--main-dark:' . e($mainDark) . ';--main-deep:' . e($mainDeep) . ';--accent:#d9a441;--accent-rgb:217,164,65;--accent-dark:#c18f2f;--bg:#f3f6fb;--ink:#2b3340;--muted:#7a8494;--line:#e6ebf2;--card:#ffffff;--page-bg:' . $pageBgCss . ';}</style>' . "\n";
    echo '</head>' . "\n";
    echo '<body>' . "\n";
    // 导航栏
    echo '<nav class="navbar navbar-expand-lg navbar-dark sticky-top site-nav">' . "\n";
    echo '<a class="navbar-brand" href="/index.php">';
    if ($logo !== '') { echo '<img src="' . e($logo) . '" alt="logo" style="height:26px;margin-right:6px;">'; }
    echo e($site) . '</a>' . "\n";
    echo '<button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="导航"><span class="navbar-toggler-icon"></span></button>' . "\n";
    echo '<div class="collapse navbar-collapse" id="mainNav"><ul class="navbar-nav mr-auto">' . "\n";
    echo '<li class="nav-item' . ($active === 'home' ? ' active' : '') . '"><a class="nav-link" href="/index.php">首页</a></li>' . "\n";
    echo '<li class="nav-item' . ($active === 'public' ? ' active' : '') . '"><a class="nav-link" href="/index.php?view=notices&amp;type=public">对外公告</a></li>' . "\n";
    echo '<li class="nav-item' . ($active === 'worship' ? ' active' : '') . '"><a class="nav-link" href="/worship.php">膜拜</a></li>' . "\n";
    // 自定义导航标签（网站设置中配置；外链新窗口打开，站内相对链接当前窗口）
    $navLinks = (isset($s['nav_links']) && is_array($s['nav_links'])) ? $s['nav_links'] : array();
    foreach ($navLinks as $nl) {
        $nName = isset($nl['name']) ? trim($nl['name']) : '';
        $nUrl = isset($nl['url']) ? trim($nl['url']) : '';
        if ($nName === '' || $nUrl === '') continue;
        $isExt = (strpos($nUrl, 'http://') === 0 || strpos($nUrl, 'https://') === 0);
        echo '<li class="nav-item"><a class="nav-link" href="' . e($nUrl) . '"' . ($isExt ? ' target="_blank" rel="noopener"' : '') . '>' . e($nName) . '</a></li>' . "\n";
    }
    if ($u) {
        echo '<li class="nav-item' . ($active === 'search' ? ' active' : '') . '"><a class="nav-link" href="/index.php?view=search">搜索</a></li>' . "\n";
        echo '<li class="nav-item' . ($active === 'internal' ? ' active' : '') . '"><a class="nav-link" href="/index.php?view=notices&amp;type=internal">内部通知</a></li>' . "\n";
        echo '<li class="nav-item' . ($active === 'projects' ? ' active' : '') . '"><a class="nav-link" href="/index.php?view=projects">项目与资料</a></li>' . "\n";
        echo '<li class="nav-item' . ($active === 'drive' ? ' active' : '') . '"><a class="nav-link" href="/drive.php">网盘</a></li>' . "\n";
        echo '<li class="nav-item' . ($active === 'oj' ? ' active' : '') . '"><a class="nav-link" href="/oj.php">OJ 训练</a></li>' . "\n";
        echo '<li class="nav-item' . ($active === 'tools' ? ' active' : '') . '"><a class="nav-link" href="/index.php?view=tools">工具</a></li>' . "\n";
        echo '<li class="nav-item' . ($active === 'paste' ? ' active' : '') . '"><a class="nav-link" href="/paste.php">代码分享</a></li>' . "\n";
        echo '<li class="nav-item' . ($active === 'surveys' ? ' active' : '') . '"><a class="nav-link" href="/index.php?view=surveys">问卷</a></li>' . "\n";
        echo '<li class="nav-item' . ($active === 'messages' ? ' active' : '') . '"><a class="nav-link" href="/messages.php">私信';
        if ($unread > 0) { echo ' <span class="badge badge-light" id="unread-badge">' . $unread . '</span>'; }
        else { echo ' <span class="badge badge-light d-none" id="unread-badge"></span>'; }
        echo '</a></li>' . "\n";
        echo '<li class="nav-item' . ($active === 'profile' ? ' active' : '') . '"><a class="nav-link" href="/index.php?view=profile">我的</a></li>' . "\n";
        if (in_array($u['role'], array('admin', 'officer'))) {
            echo '<li class="nav-item dropdown"><a class="nav-link dropdown-toggle" href="#" data-toggle="dropdown">后台管理</a>' . "\n";
            echo '<div class="dropdown-menu">' . "\n";
            echo '<a class="dropdown-item" href="/admin/notices.php">通知管理</a>' . "\n";
            echo '<a class="dropdown-item" href="/admin/projects.php">项目管理</a>' . "\n";
            echo '<a class="dropdown-item" href="/admin/surveys.php">问卷管理</a>' . "\n";
            echo '<a class="dropdown-item" href="/admin/tools.php">工具管理</a>' . "\n";
            echo '<a class="dropdown-item" href="/admin/logs.php">操作日志</a>' . "\n";
            if ($u['role'] === 'admin') {
                echo '<div class="dropdown-divider"></div>' . "\n";
                echo '<a class="dropdown-item" href="/admin/worship.php">膜拜管理</a>' . "\n";
                echo '<a class="dropdown-item" href="/admin/members.php">成员管理</a>' . "\n";
                echo '<a class="dropdown-item" href="/admin/settings.php">网站设置</a>' . "\n";
                echo '<a class="dropdown-item" href="/admin/backup.php">数据备份</a>' . "\n";
                echo '<a class="dropdown-item" href="/admin/messages.php">聊天记录管理</a>' . "\n";
                echo '<a class="dropdown-item" href="/filemanager.php">文件管理器</a>' . "\n";
            }
            echo '</div></li>' . "\n";
        }
        echo '<li class="nav-item"><a class="nav-link" href="/logout.php">退出</a></li>' . "\n";
    } else {
        echo '<li class="nav-item"><a class="nav-link" href="/login.php">登录</a></li>' . "\n";
    }
    echo '</ul></div></nav>' . "\n";
    echo '<div class="container py-4" id="page-main">' . "\n";
}

/**
 * 页面统一底部（含 Bootstrap JS 与未读私信 30 秒轮询）
 */
function page_footer() {
    $s = $GLOBALS['SETTINGS'];
    $site = isset($s['site_name']) ? $s['site_name'] : '社团官网';
    echo '</div>' . "\n";
    echo '<footer class="site-footer text-center py-4 mt-4"><small>© ' . date('Y') . ' ' . e($site) . ' · Club Site v1.1</small></footer>' . "\n";
    echo '<script src="/assets/vendor/jquery.min.js"></script>' . "\n";
    echo '<script src="/assets/vendor/bootstrap.bundle.min.js"></script>' . "\n";
    echo '<script src="/assets/js/main.js"></script>' . "\n";
    echo '<script src="/assets/js/mdpreview.js"></script>' . "\n";
    // 全局 Toast：把页面内的 .toast-item 收集到右上角容器，3 秒后滑出消失
    echo '<div class="toast-stack" id="toast-stack"></div>' . "\n";
    echo '<script>(function(){var st=document.getElementById("toast-stack");' .
         'document.querySelectorAll(".toast-item").forEach(function(t){' .
         'st.appendChild(t);' .
         'setTimeout(function(){t.classList.add("toast-out");},3200);' .
         'setTimeout(function(){if(t.parentNode)t.parentNode.removeChild(t);},4200);});});})();</script>' . "\n";
    echo '</body></html>' . "\n";
}

/**
 * 以 Toast 形式输出闪现消息（替代页面内 alert 条）
 */
function flash_toast($msg) {
    echo '<div class="toast-item">' . e($msg) . '</div>';
}

/**
 * 公告/通知列表渲染
 * @param array $list    通知数组（含 type/title/created_at/pinned/attachments/read_by 等字段）
 * @param bool  $isLogin 是否已登录（登录后显示已读人数）
 */
function render_notice_list($list, $isLogin = false) {
    usort($list, function ($a, $b) {
        $pa = !empty($a['pinned']) ? 1 : 0;
        $pb = !empty($b['pinned']) ? 1 : 0;
        if ($pa !== $pb) return $pb - $pa;
        return strcmp($b['created_at'], $a['created_at']);
    });
    if (count($list) === 0) {
        echo '<div class="alert alert-info">暂无通知</div>';
        return;
    }
    echo '<div class="list-group">';
    foreach ($list as $n) {
        $badges = '';
        if (!empty($n['pinned'])) $badges .= '<span class="badge badge-danger mr-1">置顶</span>';
        if (!empty($n['attachments'])) $badges .= '<span class="badge badge-info mr-1">附件 ' . count($n['attachments']) . '</span>';
        echo '<a class="list-group-item list-group-item-action" href="/index.php?view=notice&amp;id=' . urlencode($n['id']) . '">';
        echo '<div class="d-flex justify-content-between align-items-center"><div>';
        echo $badges . '<strong>' . e($n['title']) . '</strong>';
        echo '<div class="small text-muted">' . e(isset($n['creator_name']) ? $n['creator_name'] : '') . ' ｜ ' . e($n['created_at']) . '</div>';
        echo '</div>';
        if ($isLogin && !empty($n['read_by'])) {
            echo '<span class="badge badge-light">已读 ' . count($n['read_by']) . '</span>';
        }
        echo '</div></a>';
    }
    echo '</div>';
}
