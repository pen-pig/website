<?php
/**
 * 代码片段分享（Paste）
 * - 登录用户可发布代码片段（标题 + 语言 + 代码，≤64KB / ≤2000 行）
 * - 全站列表 + 单片查看（highlight.js 高亮 + 行号 + 一键复制）
 * - 作者可删自己的片段；管理员/干事可删任意片段
 * 数据：data/json/pastes.json {"pastes":[...]}
 */
require_once __DIR__ . '/includes/config.php';
require_login();
$user = current_user();

$langs = array(
    'plaintext' => '纯文本', 'c' => 'C', 'cpp' => 'C/C++', 'python' => 'Python',
    'javascript' => 'JavaScript', 'typescript' => 'TypeScript', 'java' => 'Java',
    'php' => 'PHP', 'csharp' => 'C#', 'go' => 'Go', 'rust' => 'Rust',
    'sql' => 'SQL', 'bash' => 'Shell', 'json' => 'JSON', 'xml' => 'HTML/XML',
    'css' => 'CSS', 'markdown' => 'Markdown', 'x86asm' => '汇编'
);

// ---- POST：发布 / 删除 ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'create') {
        $title = trim(isset($_POST['title']) ? $_POST['title'] : '');
        $lang  = isset($_POST['lang']) ? $_POST['lang'] : 'plaintext';
        $code  = isset($_POST['code']) ? $_POST['code'] : '';
        if ($title === '') $title = '无标题';
        if (mb_strlen($title, 'UTF-8') > 60) $title = mb_substr($title, 0, 60, 'UTF-8');
        if (!isset($langs[$lang])) $lang = 'plaintext';
        // 去 UTF-8 BOM 与结尾多余空白
        $code = preg_replace('/^\xEF\xBB\xBF/', '', $code);
        $code = rtrim($code, "\r\n");
        $lineCount = $code === '' ? 0 : count(explode("\n", str_replace("\r\n", "\n", $code)));
        if (trim($code) === '') {
            $_SESSION['flash'] = '发布失败：代码内容不能为空';
            redirect('paste.php');
        }
        if (strlen($code) > 65536) {
            $_SESSION['flash'] = '发布失败：内容超过 64KB 上限';
            redirect('paste.php');
        }
        if ($lineCount > 2000) {
            $_SESSION['flash'] = '发布失败：超过 2000 行上限';
            redirect('paste.php');
        }
        $pastes = jdb_collection('pastes', 'pastes');
        $id = 'p' . date('ymd') . '_' . bin2hex(openssl_random_pseudo_bytes(4));
        $pastes[] = array(
            'id'         => $id,
            'title'      => $title,
            'lang'       => $lang,
            'code'       => $code,
            'uid'        => $user['id'],
            'author'     => $user['name'],
            'created_at' => date('Y-m-d H:i:s')
        );
        jdb_save_collection('pastes', 'pastes', $pastes);
        log_action('paste_create', '发布代码片段「' . $title . '」');
        $_SESSION['flash'] = '代码片段已发布';
        redirect('paste.php?id=' . urlencode($id));
    }

    if ($action === 'delete') {
        $id = isset($_POST['id']) ? $_POST['id'] : '';
        $pastes = jdb_collection('pastes', 'pastes');
        $found = null; $idx = -1;
        foreach ($pastes as $i => $p) {
            if (isset($p['id']) && $p['id'] === $id) { $found = $p; $idx = $i; break; }
        }
        if ($found === null) {
            $_SESSION['flash'] = '片段不存在或已删除';
            redirect('paste.php');
        }
        $canDel = ($found['uid'] === $user['id']) || in_array($user['role'], array('admin', 'officer'));
        if (!$canDel) {
            $_SESSION['flash'] = '只能删除自己发布的片段';
            redirect('paste.php?id=' . urlencode($id));
        }
        array_splice($pastes, $idx, 1);
        jdb_save_collection('pastes', 'pastes', $pastes);
        log_action('paste_delete', '删除代码片段「' . $found['title'] . '」');
        $_SESSION['flash'] = '片段已删除';
        redirect('paste.php');
    }
    redirect('paste.php');
}

// ---- GET：单片查看 ----
$viewPaste = null;
$id = isset($_GET['id']) ? $_GET['id'] : '';
if ($id !== '') {
    foreach (jdb_collection('pastes', 'pastes') as $p) {
        if (isset($p['id']) && $p['id'] === $id) { $viewPaste = $p; break; }
    }
    if ($viewPaste === null) {
        page_header('代码分享', 'paste');
        echo '<div class="alert alert-warning">片段不存在或已被删除。</div><a class="btn btn-outline-primary" href="/paste.php">← 返回列表</a>';
        page_footer();
        exit;
    }
}

if ($viewPaste !== null) {
    // ---------- 查看页 ----------
    page_header('代码片段：' . $viewPaste['title'], 'paste');
    $lines = explode("\n", str_replace("\r\n", "\n", $viewPaste['code']));
    $lnHtml = implode("\n", range(1, count($lines)));
    $canDel = ($viewPaste['uid'] === $user['id']) || in_array($user['role'], array('admin', 'officer'));
    echo '<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="/paste.php">代码分享</a></li><li class="breadcrumb-item active">' . e($viewPaste['title']) . '</li></ol></nav>';
    echo '<div class="card">';
    echo '<div class="card-header d-flex justify-content-between align-items-center flex-wrap">';
    echo '<strong>' . e($viewPaste['title']) . '</strong>';
    echo '<span class="small text-muted">' . e($viewPaste['author']) . ' · ' . e(fmt_time($viewPaste['created_at'])) . ' · ' . count($lines) . ' 行</span>';
    echo '<span>';
    echo '<button type="button" class="btn btn-sm btn-outline-secondary mr-1" onclick="copyPaste(this)">复制代码</button>';
    if ($canDel) {
        echo '<form method="post" action="/paste.php" style="display:inline;" onsubmit="return confirm(\'确定删除该片段？\')">' . csrf_field();
        echo '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . e($viewPaste['id']) . '">';
        echo '<button class="btn btn-sm btn-outline-danger">删除</button></form>';
    }
    echo '</span>';
    echo '</div>';
    echo '<div class="card-body p-0">';
    echo '<div class="code-view" id="pasteCode"><div class="code-ln">' . $lnHtml . '</div><pre><code class="language-' . e($viewPaste['lang']) . '">' . e($viewPaste['code']) . '</code></pre></div>';
    echo '</div>';
    echo '</div>';
    echo '<script>function copyPaste(btn){var t=document.querySelector("#pasteCode code").innerText;if(navigator.clipboard){navigator.clipboard.writeText(t).then(function(){btn.textContent="已复制 ✓";setTimeout(function(){btn.textContent="复制代码";},1500);});}else{var ta=document.createElement("textarea");ta.value=t;document.body.appendChild(ta);ta.select();document.execCommand("copy");document.body.removeChild(ta);btn.textContent="已复制 ✓";setTimeout(function(){btn.textContent="复制代码";},1500);}}</script>';
    page_footer();
    exit;
}

// ---------- 列表页 ----------
page_header('代码分享', 'paste');
$pastes = jdb_collection('pastes', 'pastes');
usort($pastes, function ($a, $b) { return strcmp($b['created_at'], $a['created_at']); });

// 发布表单
echo '<div class="card mb-4"><div class="card-header"><strong>贴一段代码</strong></div><div class="card-body">';
echo '<form method="post" action="/paste.php">' . csrf_field();
echo '<input type="hidden" name="action" value="create">';
echo '<div class="form-row align-items-end">';
echo '<div class="form-group col-md-5 mb-2"><label class="mb-1">标题（可选，≤60 字）</label><input type="text" name="title" class="form-control" maxlength="60" placeholder="如：快速排序 · C 语言版"></div>';
echo '<div class="form-group col-md-3 mb-2"><label class="mb-1">语言</label><select name="lang" class="form-control">';
foreach ($langs as $k => $label) { echo '<option value="' . e($k) . '">' . e($label) . '</option>'; }
echo '</select></div>';
echo '</div>';
echo '<div class="form-group mb-2"><label class="mb-1">代码（≤64KB / ≤2000 行）</label><textarea name="code" class="form-control font-monospace" rows="10" style="font-size:13px;background:#0d1117;color:#c9d1d9;border-radius:10px;" placeholder="粘贴代码…" required></textarea></div>';
echo '<button class="btn btn-primary">发布片段</button>';
echo '</form></div></div>';

// 列表
echo '<div class="card"><div class="card-header d-flex justify-content-between"><strong>全部片段（' . count($pastes) . '）</strong><span class="small text-muted">最新在前</span></div><div class="card-body p-0">';
if (count($pastes) === 0) {
    echo '<div class="p-3 text-muted small">还没有代码片段，来贴第一段吧。</div>';
} else {
    echo '<div class="list-group list-group-flush">';
    foreach ($pastes as $p) {
        $canDel = ($p['uid'] === $user['id']) || in_array($user['role'], array('admin', 'officer'));
        $preview = preg_replace('/\s+/', ' ', mb_substr($p['code'], 0, 80, 'UTF-8'));
        echo '<div class="list-group-item">';
        echo '<div class="d-flex justify-content-between align-items-center flex-wrap">';
        echo '<span><a href="/paste.php?id=' . urlencode($p['id']) . '"><strong>' . e($p['title']) . '</strong></a>';
        echo ' <span class="badge badge-light">' . e(isset($langs[$p['lang']]) ? $langs[$p['lang']] : $p['lang']) . '</span></span>';
        echo '<span class="small text-muted">' . e($p['author']) . ' · ' . e(fmt_time($p['created_at'])) . '</span>';
        echo '</div>';
        echo '<div class="small text-muted mt-1" style="font-family:monospace;word-break:break-all;">' . e($preview) . '…</div>';
        echo '<div class="mt-1"><a class="btn btn-sm btn-outline-primary" href="/paste.php?id=' . urlencode($p['id']) . '">查看</a>';
        if ($canDel) {
            echo ' <form method="post" action="/paste.php" style="display:inline;" onsubmit="return confirm(\'确定删除该片段？\')">' . csrf_field();
            echo '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . e($p['id']) . '">';
            echo '<button class="btn btn-sm btn-outline-danger">删除</button></form>';
        }
        echo '</div>';
        echo '</div>';
    }
    echo '</div>';
}
echo '</div></div>';
page_footer();
