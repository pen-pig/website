<?php
/**
 * EPUB 电子书阅读器（在线预览）
 * epub.php?type=drive|materials|homework|notices&file=<存储文件名>[&folder=<网盘子目录>][&chapter=N]
 * - 无 chapter / chapter=1：渲染阅读页框架 + 章节列表 + 第 1 章正文
 * - 服务端 ZipArchive 解包解析（container.xml → content.opf → spine），输出纯文本正文，无外部依赖
 * 鉴权规则与 preview.php / download.php 一致。
 */
require_once __DIR__ . '/includes/config.php';

require_login();
$user = current_user();

$type = isset($_GET['type']) ? $_GET['type'] : '';
$file = isset($_GET['file']) ? $_GET['file'] : '';

$allowedTypes = array('materials', 'homework', 'notices', 'drive');
if (!in_array($type, $allowedTypes)) {
    http_response_code(400);
    die('非法的预览类型');
}

// 防路径穿越：只保留 basename
$stored = basename($file);
if ($stored === '' || $stored === '.' || $stored === '..') {
    http_response_code(400);
    die('非法的文件名');
}

// 定位物理文件（与 preview.php 同规则）
$folder = '';
if ($type === 'drive') {
    $folder = drive_norm_path(isset($_GET['folder']) ? $_GET['folder'] : '');
    if ($folder === null) {
        http_response_code(400);
        die('非法的目录路径');
    }
    $relFile = $folder === '' ? $stored : $folder . '/' . $stored;
    $path = drive_resolve($relFile);
    if ($path === false || !is_file($path)) {
        http_response_code(404);
        die('文件不存在或已被清理');
    }
} else {
    // 作业仅管理员/干事可预览
    if ($type === 'homework' && !in_array($user['role'], array('admin', 'officer'))) {
        http_response_code(403);
        die('无权限预览作业文件');
    }
    $path = UPLOAD_DIR . '/' . $type . '/' . $stored;
    if (!file_exists($path)) {
        http_response_code(404);
        die('文件不存在或已被清理');
    }
    // 与下载一致的封闭项目拦截：资料文件仅在项目封闭且非管理员/干事时禁止
    if ($type === 'materials') {
        $branches = jdb_collection('projects', 'branches');
        foreach ($branches as $br) {
            if (empty($br['projects']) || !is_array($br['projects'])) continue;
            foreach ($br['projects'] as $p) {
                $pClosed = (isset($p['status']) ? $p['status'] : 'open') === 'closed';
                if (!$pClosed) continue;
                if (!empty($p['materials'])) {
                    foreach ($p['materials'] as $m) {
                        if (isset($m['stored']) && $m['stored'] === $stored) {
                            if (!in_array($user['role'], array('admin', 'officer'))) {
                                http_response_code(403);
                                die('该项目暂未开放');
                            }
                            break 3;
                        }
                    }
                }
            }
        }
    }
}

// ---------------- EPUB 解析 ----------------
if (!class_exists('ZipArchive')) {
    http_response_code(500);
    die('服务器不支持 ZipArchive，无法解析 EPUB');
}
if (filesize($path) > 100 * 1024 * 1024) {
    http_response_code(400);
    die('EPUB 文件过大（超过 100MB），无法在线预览，请直接下载');
}

$zip = new ZipArchive();
if ($zip->open($path) !== true) {
    http_response_code(400);
    die('无法打开 EPUB 文件');
}

// 1) container.xml → content.opf 路径
$containerXml = $zip->getFromName('META-INF/container.xml');
if ($containerXml === false) {
    $zip->close();
    http_response_code(400);
    die('EPUB 文件缺少 container.xml');
}
$container = @simplexml_load_string($containerXml);
if ($container === false) {
    $zip->close();
    http_response_code(400);
    die('EPUB container.xml 解析失败');
}
$rootfile = $container->children('urn:oasis:names:tc:opendocument:xmlns:container')->rootfiles->rootfile;
$rootAttrs = ($rootfile !== null) ? $rootfile->attributes() : null;
if ($rootAttrs === null || !isset($rootAttrs['full-path']) || trim((string)$rootAttrs['full-path']) === '') {
    $zip->close();
    http_response_code(400);
    die('EPUB 缺少 rootfile 声明');
}
$opfPath = (string)$rootAttrs['full-path'];
$opfDir = (dirname($opfPath) === '.' || dirname($opfPath) === '\\') ? '' : str_replace('\\', '/', dirname($opfPath));

// 2) content.opf → manifest / spine
$opfXml = $zip->getFromName($opfPath);
if ($opfXml === false) {
    $zip->close();
    http_response_code(400);
    die('EPUB content.opf 不存在');
}
$opf = @simplexml_load_string($opfXml);
if ($opf === false) {
    $zip->close();
    http_response_code(400);
    die('EPUB content.opf 解析失败');
}
$ns = $opf->children('http://www.idpf.org/2007/opf');

$bookTitle = '未命名书籍';
$meta = $opf->metadata;
if ($meta !== null) {
    $dct = $meta->children('http://purl.org/dc/elements/1.1/');
    if (isset($dct->title) && trim((string)$dct->title) !== '') {
        $bookTitle = trim((string)$dct->title);
    }
}

$manifest = array();
foreach ($ns->manifest->item as $it) {
    $itAttrs = $it->attributes();
    $id = (string)$itAttrs['id'];
    $href = (string)$itAttrs['href'];
    if ($id !== '' && $href !== '') {
        $manifest[$id] = $href;
    }
}

$spineIds = array();
foreach ($ns->spine->itemref as $ir) {
    $irAttrs = $ir->attributes();
    $idref = (string)$irAttrs['idref'];
    if ($idref !== '') {
        $spineIds[] = $idref;
    }
}

// 3) 按 spine 顺序收集章节（标题 + 相对路径）
$chapters = array();
foreach ($spineIds as $sid) {
    if (!isset($manifest[$sid])) continue;
    $href = $manifest[$sid];
    $rel = $opfDir === '' ? $href : $opfDir . '/' . $href;
    $rel = preg_replace('#/+#', '/', $rel);
    $raw = $zip->getFromName($rel);
    if ($raw === false) continue;
    $chapters[] = array('title' => epub_extract_title($raw, $href), 'rel' => $rel);
}
$zip->close();

if (count($chapters) === 0) {
    http_response_code(400);
    die('EPUB 中没有可读章节');
}

// 章节标题提取：title → h1-h6 → class 含 title/head/chapter → 正文向下抓取 → 文件名兜底
// calibre 导出 EPUB 常把 <title> 写成占位符 "Unknown"（非空），需识别为无效并继续兜底
function epub_title_is_placeholder($t) {
    $t = trim($t);
    if ($t === '') return true;
    if (preg_match('/^(unknown|untitled|未命名)$/i', $t)) return true;
    return false;
}

function epub_extract_title($raw, $href) {
    $t = '';
    if (preg_match('#<title[^>]*>(.*?)</title>#is', $raw, $m)) {
        $t = trim(strip_tags($m[1]));
        if (epub_title_is_placeholder($t)) $t = '';
    }
    if ($t === '' && preg_match('#<h([1-6])[^>]*>(.*?)</h\1>#is', $raw, $m)) {
        $t = trim(strip_tags($m[2]));
    }
    if ($t === '') {
        if (preg_match('#<(p|div|section|h[1-6])[^>]*class=["\'][^"\']*(title|head|chapter|hd)[^"\']*["\'][^>]*>(.*?)</\1>#is', $raw, $m)) {
            $t = trim(strip_tags($m[3]));
        }
    }
    if ($t === '') {
        // 向下抓取：正文首个非空文本块（截取前 30 字符），即使标题是长句也能取到
        $body = preg_replace('#<head.*?</head>#is', '', $raw);
        $body = preg_replace('#<script.*?</script>#is', '', $body);
        $body = preg_replace('#<style.*?</style>#is', '', $body);
        $body = preg_replace('#</(p|div|h[1-6]|li|blockquote|br|tr)>#i', "\n", $body);
        $body = strip_tags($body);
        $body = html_entity_decode($body, ENT_QUOTES, 'UTF-8');
        $body = preg_replace('/[ \t]+/', ' ', $body);
        $lines = preg_split("/\n+/", trim($body));
        foreach ($lines as $ln) {
            $ln = trim($ln);
            if ($ln !== '') {
                $t = utf8_len($ln) > 30 ? utf8_substr($ln, 30) : $ln;
                break;
            }
        }
    }
    if ($t === '') {
        $t = basename($href);
        $t = preg_replace('/\.(xhtml?|html?|htm)$/i', '', $t);
    }
    return $t;
}

// UTF-8 安全截取（避免依赖 mbstring，按字符计数、避免截断多字节字符）
function utf8_substr($s, $max) {
    $out = '';
    $n = 0;
    $l = strlen($s);
    for ($i = 0; $i < $l; $i++) {
        $c = $s[$i];
        if ((ord($c) & 0xC0) === 0x80) { // 多字节字符的后续字节，直接跟随
            $out .= $c;
            continue;
        }
        if ($n >= $max) break;
        $n++;
        $out .= $c;
    }
    return $out;
}

// UTF-8 安全字符数（避免依赖 mbstring）
function utf8_len($s) {
    $n = 0;
    $l = strlen($s);
    for ($i = 0; $i < $l; $i++) {
        if ((ord($s[$i]) & 0xC0) !== 0x80) $n++;
    }
    return $n;
}

// 章节正文按段落分页（每页约 $perPage 字符）
function epub_paginate($text, $perPage = 900) {
    $paras = preg_split("/\n\s*\n+/", $text);
    $pages = array();
    $cur = '';
    $curLen = 0;
    foreach ($paras as $p) {
        $p = trim($p);
        if ($p === '') continue;
        $len = utf8_len($p);
        if ($curLen > 0 && $curLen + $len > $perPage) {
            $pages[] = trim($cur);
            $cur = $p;
            $curLen = $len;
        } else {
            $cur .= ($cur === '' ? '' : "\n\n") . $p;
            $curLen += $len + 2;
        }
    }
    if (trim($cur) !== '') $pages[] = trim($cur);
    return $pages;
}

// 章节正文纯文本化
function epub_chapter_text($raw) {
    // 去掉 head / script / style
    $raw = preg_replace('#<head.*?</head>#is', '', $raw);
    $raw = preg_replace('#<script.*?</script>#is', '', $raw);
    $raw = preg_replace('#<style.*?</style>#is', '', $raw);
    // 块级标签转段落空行
    $raw = preg_replace('#</(p|div|h[1-6]|li|blockquote|br|tr)>#i', "\n\n", $raw);
    $text = strip_tags($raw);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    // 归一化空白
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace("/\n\s*\n+/", "\n\n", $text);
    return trim($text);
}

// 当前章节
$chapterIdx = isset($_GET['chapter']) ? (int)$_GET['chapter'] : 1;
if ($chapterIdx < 1 || $chapterIdx > count($chapters)) {
    $chapterIdx = 1;
}
$idx = $chapterIdx - 1;
$cur = $chapters[$idx];

$zip2 = new ZipArchive();
$zip2->open($path);
$raw = $zip2->getFromName($cur['rel']);
$zip2->close();
if ($raw === false) {
    http_response_code(500);
    die('章节读取失败');
}
$text = epub_chapter_text($raw);

// 章节内分页（每页约 900 字符，按段落切分）
$pages = $text === '' ? array() : epub_paginate($text);
$pageCount = count($pages);
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1 || ($pageCount > 0 && $page > $pageCount)) {
    $page = 1;
}
$pageText = $pageCount > 0 ? $pages[$page - 1] : '';

$crumbHome = ($type === 'drive') ? '<a href="drive.php">网盘</a>' : '<a href="index.php">首页</a>';
$downloadUrl = ($type === 'drive')
    ? 'api/drive.php?action=download&amp;folder=' . urlencode($folder) . '&amp;file=' . urlencode($stored)
    : '/download.php?type=' . urlencode($type) . '&amp;file=' . urlencode($stored);
$queryBase = 'epub.php?type=' . urlencode($type) . '&amp;folder=' . urlencode($folder) . '&amp;file=' . urlencode($stored);

page_header('书籍阅读', $type === 'drive' ? 'drive' : 'projects');
echo '<style>.epub-chapter-list{max-height:70vh;overflow-y:auto;}.epub-body{white-space:pre-wrap;word-break:break-word;font-size:15.5px;line-height:1.9;max-height:70vh;overflow-y:auto;}</style>';
echo '<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">' . $crumbHome . '</li><li class="breadcrumb-item active">书籍阅读</li></ol></nav>';
echo '<div class="card mb-3"><div class="card-body d-flex align-items-center justify-content-between flex-wrap">';
echo '<div><strong>' . e($bookTitle) . '</strong> <span class="text-muted small ml-2">EPUB ｜ 共 ' . count($chapters) . ' 章 ｜ 第 ' . $chapterIdx . ' 章</span></div>';
echo '<div><a class="btn btn-sm btn-outline-secondary mr-1" href="javascript:history.back()">返回</a>';
echo '<a class="btn btn-sm btn-primary" href="' . $downloadUrl . '">下载</a></div></div></div>';
echo '<div class="row">';
// 章节列表
echo '<div class="col-md-3 mb-3"><div class="card"><div class="card-header py-2"><strong>目录</strong></div>';
echo '<div class="list-group list-group-flush epub-chapter-list">';
foreach ($chapters as $i => $ch) {
    $active = ($i === $idx) ? ' active' : '';
    echo '<a class="list-group-item list-group-item-action py-2' . $active . '" href="' . $queryBase . '&amp;chapter=' . ($i + 1) . '&amp;page=1">' . e($ch['title']) . '</a>';
}
echo '</div></div></div>';
// 正文（章节内分页显示，翻页阅读）
echo '<div class="col-md-9"><div class="card"><div class="card-body epub-body">';
echo '<h4 class="mb-3">' . e($cur['title']) . '</h4>';
if ($text === '') {
    echo '<p class="text-muted">本章无可显示文字内容（可能为插图或目录页）。</p>';
} else {
    echo nl2br(e($pageText));
}
echo '</div>';
// 分页器 + 上下章（上一页自动接上一章末页逻辑在左，下一页接下一章首页在右）
echo '<div class="card-footer d-flex align-items-center justify-content-between flex-wrap">';
$prevUrl = '';
if ($page > 1) {
    $prevUrl = $queryBase . '&amp;chapter=' . $chapterIdx . '&amp;page=' . ($page - 1);
} elseif ($idx > 0) {
    $prevUrl = $queryBase . '&amp;chapter=' . $idx . '&amp;page=1';
}
if ($prevUrl !== '') {
    echo '<a class="btn btn-outline-primary btn-sm" href="' . $prevUrl . '">上一页</a>';
} else {
    echo '<span></span>';
}
echo '<span class="small text-muted">第 ' . $page . ' / ' . max($pageCount, 1) . ' 页</span>';
$nextUrl = '';
if ($page < $pageCount) {
    $nextUrl = $queryBase . '&amp;chapter=' . $chapterIdx . '&amp;page=' . ($page + 1);
} elseif ($idx < count($chapters) - 1) {
    $nextUrl = $queryBase . '&amp;chapter=' . ($idx + 2) . '&amp;page=1';
}
if ($nextUrl !== '') {
    echo '<a class="btn btn-outline-primary btn-sm" href="' . $nextUrl . '">下一页</a>';
} else {
    echo '<span></span>';
}
echo '</div></div></div>';
echo '</div>';
page_footer();
exit;
