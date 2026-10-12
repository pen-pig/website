/* ============================================
 * Markdown 编辑入口 · 洛谷式独立编辑器
 * 用法：给 <textarea> 加 class="md-input"，
 * 自动替换为「编辑 Markdown」按钮，点击跳转
 * md_editor.php（左编辑右预览 + 保存并返回）
 * 内容经 sessionStorage 与来源页交换，
 * 语法与后端 includes/markdown.php 对齐
 * ============================================ */
(function ($) {
    function escHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function renderMd(src) {
        src = String(src || '');
        if ($.trim(src) === '') { return '<p class="md-empty">暂无内容</p>'; }
        // 1. 提取代码块
        var codeBlocks = [];
        src = src.replace(/```([\s\S]*?)```/g, function (m, code) {
            var key = '{{CODEBLOCK_' + codeBlocks.length + '}}';
            codeBlocks.push('<pre><code>' + escHtml(code.replace(/^\n+|\n+$/g, '')) + '</code></pre>');
            return key;
        });
        // 2. 全文转义 + 行内格式
        src = escHtml(src);
        src = src.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        src = src.replace(/~~(.+?)~~/g, '<del>$1</del>');
        src = src.replace(/\*(.+?)\*/g, '<em>$1</em>');
        src = src.replace(/`([^`]+)`/g, '<code>$1</code>');
        function safeUrl(url) {
            url = String(url || '').replace(/^\s+|\s+$/g, '');
            return /^(https?:\/\/|mailto:|\/|#)/i.test(url) ? url : '#';
        }
        src = src.replace(/!\[([^\]]*)\]\(([^)]+)\)/g, function (m, alt, url) {
            return '<img src="' + escHtml(safeUrl(url)) + '" alt="' + escHtml(alt) + '" loading="lazy">';
        });
        src = src.replace(/\[([^\]]+)\]\(([^)]+)\)/g, function (m, label, url) {
            return '<a href="' + escHtml(safeUrl(url)) + '" target="_blank" rel="noopener">' + label + '</a>';
        });
        // 表格先替换为占位符，避免逐行逻辑把它拆成普通段落。
        var tableBlocks = [];
        src = src.replace(/(^|\n)(\|?[^\n|]+(?:\|[^\n|]+)+\|?\n\|?\s*:?-{3,}:?\s*(?:\|\s*:?-{3,}:?\s*)+\|?\n(?:\|?[^\n]+\|?\n?)+)/gm, function (m, lead, block) {
            var rows = block.replace(/^\s+|\s+$/g, '').split('\n');
            if (rows.length < 3) { return m; }
            function cells(row) { return row.replace(/^\||\|$/g, '').split('|').map(function (x) { return x.replace(/^\s+|\s+$/g, ''); }); }
            var html = '<div class="table-responsive"><table class="table table-sm md-table"><thead><tr>';
            cells(rows[0]).forEach(function (cell) { html += '<th>' + cell + '</th>'; });
            html += '</tr></thead><tbody>';
            for (var ri = 2; ri < rows.length; ri++) {
                if (!$.trim(rows[ri])) { continue; }
                html += '<tr>';
                cells(rows[ri]).forEach(function (cell) { html += '<td>' + cell + '</td>'; });
                html += '</tr>';
            }
            html += '</tbody></table></div>';
            var key = '{{TABLEBLOCK_' + tableBlocks.length + '}}';
            tableBlocks.push(html);
            return lead + key + '\n';
        });
        // 3. 逐行块级
        var lines = src.split('\n');
        var html = '', inList = '', inQuote = false, buf = [];
        function closeList() {
            if (inList) {
                html += '<' + inList + '><li>' + buf.join('</li><li>') + '</li></' + inList + '>';
                inList = ''; buf = [];
            }
        }
        for (var i = 0; i < lines.length; i++) {
            var line = lines[i].replace(/\s+$/, '');
            if ($.trim(line) === '') { closeList(); if (inQuote) { html += '</blockquote>'; inQuote = false; } continue; }
            var m;
            if (/^\{\{(?:CODEBLOCK|TABLEBLOCK)_\d+\}\}$/.test($.trim(line))) { closeList(); html += $.trim(line); continue; }
            if ((m = line.match(/^(#{1,4})\s+(.*)$/))) { closeList(); var lv = m[1].length; html += '<h' + lv + '>' + m[2] + '</h' + lv + '>'; continue; }
            if ((m = line.match(/^([-*_])\1{2,}\s*$/))) { closeList(); html += '<hr>'; continue; }
            if ((m = line.match(/^&gt;\s?(.*)$/)) || (m = line.match(/^>\s?(.*)$/))) {
                closeList(); if (!inQuote) { html += '<blockquote>'; inQuote = true; }
                html += m[1] + '<br>'; continue;
            }
            if ((m = line.match(/^[-*+]\s+(?:\[([ xX])\]\s+)?(.*)$/))) {
                if (inList !== 'ul') { closeList(); inList = 'ul'; }
                var item = m[2];
                if (m[1]) { item = '<label class="md-task"><input type="checkbox" disabled' + (m[1].toLowerCase() === 'x' ? ' checked' : '') + '> ' + item + '</label>'; }
                buf.push(item); continue;
            }
            if ((m = line.match(/^\d+[.、]\s+(.*)$/))) { if (inList !== 'ol') { closeList(); inList = 'ol'; } buf.push(m[1]); continue; }
            closeList(); html += '<p>' + line + '</p>';
        }
        closeList(); if (inQuote) { html += '</blockquote>'; }
        // 4. 还原代码块
        for (var k = 0; k < codeBlocks.length; k++) {
            html = html.split('{{CODEBLOCK_' + k + '}}').join(codeBlocks[k]);
        }
        for (var t = 0; t < tableBlocks.length; t++) {
            html = html.split('{{TABLEBLOCK_' + t + '}}').join(tableBlocks[t]);
        }
        return html;
    }

    /* 光标处包裹选区（无选区时插入占位文本） */
    function wrapSel(ta, pre, suf, ph) {
        var start = ta.selectionStart, end = ta.selectionEnd;
        var sel = ta.value.slice(start, end) || ph;
        ta.value = ta.value.slice(0, start) + pre + sel + suf + ta.value.slice(end);
        var p = start + pre.length;
        ta.setSelectionRange(p, p + sel.length);
        ta.focus();
    }

    /* 给选区所在整段每行加前缀（标题/引用/列表等） */
    function linePrefix(ta, prefix) {
        var start = ta.selectionStart, end = ta.selectionEnd;
        var val = ta.value;
        var lineStart = val.lastIndexOf('\n', start - 1) + 1;
        var lineEnd = val.indexOf('\n', end);
        if (lineEnd === -1) { lineEnd = val.length; }
        var block = val.slice(lineStart, lineEnd);
        var out = block.split('\n').map(function (l) {
            return l === '' ? prefix.replace(/\s+$/, '') : prefix + l;
        }).join('\n');
        ta.value = val.slice(0, lineStart) + out + val.slice(lineEnd);
        ta.setSelectionRange(lineStart, lineStart + out.length);
        ta.focus();
    }

    var CMDS = {
        bold:   { label: 'B',    title: '加粗',       run: function (ta) { wrapSel(ta, '**', '**', '加粗文本'); } },
        italic: { label: 'I',    title: '斜体',       run: function (ta) { wrapSel(ta, '*', '*', '斜体文本'); } },
        strike: { label: 'S',    title: '删除线',     run: function (ta) { wrapSel(ta, '~~', '~~', '删除线'); }, cls: 'strike' },
        h:      { label: 'H',    title: '标题',       run: function (ta) { linePrefix(ta, '## '); } },
        quote:  { label: '>',    title: '引用',       run: function (ta) { linePrefix(ta, '> '); } },
        link:   { label: '链接',  title: '插入链接',   run: function (ta) { wrapSel(ta, '[', '](https://)', '链接文字'); } },
        img:    { label: '图片',  title: '插入图片',   run: function (ta) { wrapSel(ta, '![', '](https://)', '图片描述'); } },
        code:   { label: '</>',  title: '代码块',     run: function (ta) { wrapSel(ta, '```\n', '\n```', '代码'); }, cls: 'code-btn' },
        icode:  { label: '`',    title: '行内代码',   run: function (ta) { wrapSel(ta, '`', '`', '代码'); }, cls: 'code-btn' },
        ul:     { label: '•',    title: '无序列表',   run: function (ta) { linePrefix(ta, '- '); } },
        ol:     { label: '1.',   title: '有序列表',   run: function (ta) { linePrefix(ta, '1. '); } },
        hr:     { label: '—',    title: '分隔线',     run: function (ta) { linePrefix(ta, '---'); } },
        table:  { label: '表格',  title: '插入表格',   run: function (ta) { wrapSel(ta, '', '', '| 列1 | 列2 |\n| --- | --- |\n| 内容 | 内容 |'); } }
    };

    /* 暴露给独立编辑器页复用 */
    window.MdPreview = {
        render: renderMd,
        wrapSel: wrapSel,
        linePrefix: linePrefix,
        CMDS: CMDS
    };

    /* ---------- 页内内联编辑器（不跳页） ---------- */
    var $mask = null;

    function buildModal() {
        var toolsHtml = '';
        for (var name in CMDS) {
            var c = CMDS[name];
            toolsHtml += '<button type="button" class="md-btn' + (c.cls ? ' ' + c.cls : '') + '" data-cmd="' + name + '" title="' + c.title + '">' + c.label + '</button>';
        }
        $mask = $(
            '<div class="md-modal-mask">' +
                '<div class="md-inline-modal">' +
                    '<div class="md-modal-top">' +
                        '<span class="md-modal-title">编辑 Markdown</span>' +
                        '<div class="md-modal-tools">' + toolsHtml + '</div>' +
                        '<button type="button" class="md-modal-close" title="关闭">&times;</button>' +
                    '</div>' +
                    '<div class="md-modal-main">' +
                        '<div class="md-modal-pane"><textarea class="md-modal-input" spellcheck="false"></textarea></div>' +
                        '<div class="md-modal-pane md-modal-preview markdown-body"></div>' +
                    '</div>' +
                    '<div class="md-modal-bottom">' +
                        '<span class="md-modal-count"></span>' +
                        '<button type="button" class="btn btn-outline-secondary btn-sm md-cancel-btn">取消</button>' +
                        '<button type="button" class="btn btn-primary btn-sm md-save-btn2">完成编辑</button>' +
                    '</div>' +
                '</div>' +
            '</div>'
        );
        $('body').append($mask);

        var $input = $mask.find('.md-modal-input');
        var $preview = $mask.find('.md-modal-preview');

        function refresh() {
            $preview.html(renderMd($input.val()));
            $mask.find('.md-modal-count').text($.trim($input.val()).length + ' 字');
        }
        $input.on('input', refresh);

        $mask.on('click', '.md-btn', function () {
            var c = CMDS[$(this).data('cmd')];
            if (c) { c.run($input[0]); refresh(); }
        });

        function close() {
            $mask.remove();
            $mask = null;
            $('body').removeClass('md-modal-open');
        }
        $mask.find('.md-modal-close, .md-cancel-btn').on('click', close);
        $mask.on('click', function (e) { if (e.target === this) { close(); } });
        $(document).on('keydown.mdmodal', function (e) {
            if (e.key === 'Escape' && $mask) { close(); }
        });
        $mask.data('close', close);
        $mask.data('input', $input);
        return $mask;
    }

    function openInlineEditor($ta, onDone) {
        if (!$mask) { buildModal(); }
        var $input = $mask.data('input');
        $input.val($ta.val());
        $mask.find('.md-modal-preview').html(renderMd($input.val()));
        $mask.find('.md-modal-count').text($.trim($input.val()).length + ' 字');
        $mask.data('target', $ta);
        $mask.data('onDone', onDone || null);
        $('body').addClass('md-modal-open');
        $mask.show();
        $input.trigger('focus');
    }

    /* 完成编辑：写回原 textarea */
    $(document).on('click', '.md-save-btn2', function () {
        if (!$mask) { return; }
        var $ta = $mask.data('target');
        var onDone = $mask.data('onDone');
        if ($ta) {
            $ta.val($mask.data('input').val());
            if (onDone) { onDone(); } else { $ta.trigger('input'); }
        }
        $mask.data('close')();
    });

    $(function () {
        $('textarea.md-input').each(function () {
            var $ta = $(this);
            var name = $ta.attr('name');
            if (!name) { return; }
            var key = location.pathname + '#' + name;

            /* 从独立编辑器返回时回填 */
            var saved = null;
            try { saved = sessionStorage.getItem('md_edit_' + key); } catch (e) {}
            if (saved !== null) {
                $ta.val(saved);
                try { sessionStorage.removeItem('md_edit_' + key); } catch (e) {}
            }

            $ta.wrap('<div class="md-field"></div>');
            var $field = $ta.parent();
            $ta.hide();
            var $btn = $('<button type="button" class="md-open-btn">编辑 Markdown</button>');
            var $hint = $('<span class="md-hint"></span>');
            $field.append($btn).append($hint);

            function syncHint() {
                var v = $ta.val().trim();
                $hint.text(v ? '已填写 ' + v.length + ' 字' : '未填写')
                     .toggleClass('filled', v !== '');
            }
            syncHint();

            $btn.on('click', function () {
                openInlineEditor($ta, syncHint);
            });
            $ta.on('input', syncHint);
        });
    });
})(jQuery);
