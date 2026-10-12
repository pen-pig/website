<?php
/**
 * Markdown 独立编辑器（洛谷式：左编辑区 + 右预览区 + 保存并返回）
 * 内容经 sessionStorage 与来源表单页交换，不直接写库。
 * 入口：任意 textarea.md-input 的「编辑 Markdown」按钮
 */
require_once __DIR__ . '/includes/config.php';
$user = require_role(array('admin', 'officer'));

$k = isset($_GET['k']) ? (string)$_GET['k'] : '';
$r = isset($_GET['r']) ? (string)$_GET['r'] : '';

// 仅允许站内相对路径作为返回地址，防止 open redirect
if ($r === '' || $r[0] !== '/' || strpos($r, '//') === 0) {
    $r = '/';
}
if ($k === '') {
    // 无 key 时只能返回，不提供编辑
    header('Location: ' . $r);
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Markdown 编辑器</title>
<link rel="stylesheet" href="/assets/vendor/bootstrap.min.css">
<link rel="stylesheet" href="/assets/css/style.css?v=11">
</head>
<body class="md-editor-page">
<div class="md-editor">
    <div class="md-editor-top">
        <div class="md-editor-title">Markdown 编辑器</div>
        <div class="md-editor-tools" id="mdTools"></div>
    </div>
    <div class="md-editor-main">
        <div class="md-editor-pane">
            <textarea class="md-editor-input" id="mdEditorInput" placeholder="在这里输入 Markdown 内容…"></textarea>
        </div>
        <div class="md-editor-pane md-editor-preview markdown-body" id="mdEditorPreview"></div>
    </div>
    <div class="md-editor-bottom">
        <button type="button" class="md-save-btn" id="mdSaveBtn">保存并返回</button>
    </div>
</div>
<script src="/assets/vendor/jquery.min.js"></script>
<script src="/assets/js/mdpreview.js"></script>
<script>
(function () {
    var K = <?php echo json_encode($k); ?>;
    var R = <?php echo json_encode($r); ?>;
    var $input = $('#mdEditorInput');
    var $preview = $('#mdEditorPreview');

    var val = '';
    try { val = sessionStorage.getItem('md_edit_' + K) || ''; } catch (e) {}
    $input.val(val);

    function refresh() { $preview.html(MdPreview.render($input.val())); }
    refresh();

    // 工具栏按钮
    var toolsHtml = '';
    for (var name in MdPreview.CMDS) {
        var c = MdPreview.CMDS[name];
        toolsHtml += '<button type="button" class="md-btn' + (c.cls ? ' ' + c.cls : '') + '" data-cmd="' + name + '" title="' + c.title + '">' + c.label + '</button>';
    }
    $('#mdTools').html(toolsHtml).on('click', '.md-btn', function () {
        var c = MdPreview.CMDS[$(this).data('cmd')];
        if (c) { c.run($input[0]); refresh(); }
    });

    $input.on('input', refresh);

    $('#mdSaveBtn').on('click', function () {
        try { sessionStorage.setItem('md_edit_' + K, $input.val()); } catch (e) {}
        location.href = R;
    });
})();
</script>
</body>
</html>
