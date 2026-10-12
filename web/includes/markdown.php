<?php
/**
 * Markdown 简易解析（兼容 PHP 5.6）
 * 安全策略：先对全文做 HTML 转义，再转换为 Markdown 结构，
 * 因此任何 HTML 标签都会以纯文本显示，天然防 XSS。
 * 支持：标题(#/##/###/####)、粗体、斜体、删除线、行内代码、代码块、图片、表格、任务列表、无序列表、有序列表、链接、引用、段落、分隔线
 */

function markdown_parse($text) {
    if ($text === null || $text === '') {
        return '';
    }
    $text = (string)$text;
    // 统一换行
    $text = str_replace("\r\n", "\n", $text);
    $text = str_replace("\r", "\n", $text);
    // 1. 先整体 HTML 转义，杜绝 XSS
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    // 2. 提取代码块（``` 包裹，可选语言标注 ```cpp），保留原样并转 <pre>
    //    class="language-xxx" 供 highlight.js 高亮；无标注时 hljs 自动检测
    $codeBlocks = array();
    $text = preg_replace_callback('/```(\w*)\s*\n?(.*?)```/s', function ($m) use (&$codeBlocks) {
        $lang = strtolower($m[1]);
        $cls = ($lang !== '' && preg_match('/^[a-z0-9+#-]{1,16}$/', $lang)) ? ' class="language-' . $lang . '"' : '';
        $key = '{{CODEBLOCK_' . count($codeBlocks) . '}}';
        $codeBlocks[$key] = '<pre><code' . $cls . '>' . trim($m[2], "\n") . '</code></pre>';
        return $key;
    }, $text);

    // 3. 行内格式：粗体、斜体、行内代码、链接
    $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text);
    $text = preg_replace('/~~(.+?)~~/s', '<del>$1</del>', $text);
    $text = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $text);
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
    // 链接和图片只允许安全协议；图片使用原生懒加载。
    $safeUrl = function ($url) {
        $url = trim($url);
        return preg_match('/^(https?:\/\/|mailto:|\/|#)/i', $url) ? $url : '#';
    };
    $text = preg_replace_callback('/!\[([^\]]*)\]\(([^)]+)\)/s', function ($m) use ($safeUrl) {
        return '<img src="' . htmlspecialchars($safeUrl($m[2]), ENT_QUOTES, 'UTF-8') . '" alt="' . $m[1] . '" loading="lazy">';
    }, $text);
    $text = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/s', function ($m) use ($safeUrl) {
        return '<a href="' . htmlspecialchars($safeUrl($m[2]), ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
    }, $text);

    // 表格：识别“表头 | 表头 + 分隔线 + 数据行”，先替换为安全占位块。
    $tableBlocks = array();
    $text = preg_replace_callback('/(^|\n)((?:\|?[^\n|]+(?:\|[^\n|]+)+\|?\n)\|?\s*:?-{3,}:?\s*(?:\|\s*:?-{3,}:?\s*)+\|?(?:\n|$)((?:\|?[^\n]+\|?(?:\n|$))+))/m', function ($m) use (&$tableBlocks) {
        $rows = preg_split('/\n/', trim($m[2]));
        if (count($rows) < 3) return $m[0];
        $cells = function ($row) {
            $row = trim($row);
            $row = trim($row, '|');
            return array_map('trim', explode('|', $row));
        };
        $head = $cells($rows[0]);
        $html = '<div class="table-responsive"><table class="table table-sm md-table"><thead><tr>';
        foreach ($head as $cell) $html .= '<th>' . $cell . '</th>';
        $html .= '</tr></thead><tbody>';
        for ($i = 2; $i < count($rows); $i++) {
            if (trim($rows[$i]) === '') continue;
            $html .= '<tr>';
            foreach ($cells($rows[$i]) as $cell) $html .= '<td>' . $cell . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';
        $key = '{{TABLEBLOCK_' . count($tableBlocks) . '}}';
        $tableBlocks[] = $html;
        return $m[1] . $key . "\n";
    }, $text);

    // 4. 逐行处理块级元素
    $lines = explode("\n", $text);
    $html = '';
    $inList = '';     // 当前列表类型：ul / ol / ''
    $inQuote = false;
    $listBuffer = array();

    $closeList = function () use (&$html, &$inList, &$listBuffer) {
        if ($inList !== '') {
            $html .= '<' . $inList . '><li>' . implode('</li><li>', $listBuffer) . '</li></' . $inList . '>';
            $inList = '';
            $listBuffer = array();
        }
    };

    foreach ($lines as $line) {
        $line = rtrim($line);
        if (trim($line) === '') {
            $closeList();
            if ($inQuote) { $inQuote = false; }
            continue;
        }
        // 代码块和表格占位必须作为块级 HTML 输出，避免被包进 <p>。
        if (preg_match('/^\{\{(?:CODEBLOCK|TABLEBLOCK)_\d+\}\}$/', trim($line))) {
            $closeList();
            $html .= trim($line);
            continue;
        }
        // 标题
        if (preg_match('/^(#{1,4})\s+(.*)$/', $line, $m)) {
            $closeList();
            $level = strlen($m[1]);
            $html .= '<h' . $level . '>' . $m[2] . '</h' . $level . '>';
            continue;
        }
        // 分隔线
        if (preg_match('/^([-*_])\1{2,}\s*$/', $line)) {
            $closeList();
            $html .= '<hr>';
            continue;
        }
        // 引用
        if (preg_match('/^&gt;\s?(.*)$/', $line, $m) || preg_match('/^>\s?(.*)$/', $line, $m)) {
            $closeList();
            if (!$inQuote) { $html .= '<blockquote>'; $inQuote = true; }
            $html .= $m[1] . '<br>';
            continue;
        }
        // 无序列表和任务列表
        if (preg_match('/^[-*+]\s+(?:\[([ xX])\]\s+)?(.*)$/', $line, $m)) {
            if ($inList !== 'ul') { $closeList(); $inList = 'ul'; }
            $item = $m[2];
            if (isset($m[1]) && $m[1] !== '') {
                $checked = strtolower($m[1]) === 'x' ? ' checked' : '';
                $item = '<label class="md-task"><input type="checkbox" disabled' . $checked . '> ' . $item . '</label>';
            }
            $listBuffer[] = $item;
            continue;
        }
        // 有序列表
        if (preg_match('/^\d+[.、]\s+(.*)$/', $line, $m)) {
            if ($inList !== 'ol') { $closeList(); $inList = 'ol'; }
            $listBuffer[] = $m[1];
            continue;
        }
        // 普通段落
        $closeList();
        $html .= '<p>' . $line . '</p>';
    }
    $closeList();
    if ($inQuote) { $html .= '</blockquote>'; }

    // 5. 还原代码块
    foreach ($codeBlocks as $key => $val) {
        $html = str_replace($key, $val, $html);
    }
    foreach ($tableBlocks as $key => $val) {
        $html = str_replace('{{TABLEBLOCK_' . $key . '}}', $val, $html);
    }
    return $html;
}
