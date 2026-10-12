/* ============================================
 * 社团官网系统 全局脚本 main.js
 * 依赖：jQuery 3.5 + Bootstrap 4.6（在 page_footer 中先行加载）
 * 功能：私信未读数 30 秒轮询、通用确认弹窗、AJAX 快捷方法
 * 兼容：所有现代浏览器（无需 ES6+ 特性，保持简单）
 * ============================================ */

(function ($) {
    'use strict';

    /* ---------- 1. 私信未读数轮询（30 秒） ---------- */
    var badge = $('#unread-badge');
    if (badge.length > 0) {
        function refreshUnread() {
            $.ajax({
                url: 'api/messages.php?action=unread',
                method: 'GET',
                dataType: 'json',
                cache: false,
                timeout: 10000
            }).done(function (res) {
                if (res && res.ok && typeof res.count === 'number') {
                    if (res.count > 0) {
                        badge.text(res.count).removeClass('d-none');
                    } else {
                        badge.addClass('d-none');
                    }
                }
            }).fail(function () {
                /* 网络异常静默，下一轮再试 */
            });
        }
        refreshUnread();
        setInterval(refreshUnread, 30000);
    }

    /* ---------- 2. 通用删除确认 ---------- */
    $(document).on('click', 'a[data-confirm], button[data-confirm]', function (e) {
        var msg = $(this).data('confirm') || '确定要执行该操作吗？';
        if (!window.confirm(msg)) {
            e.preventDefault();
            return false;
        }
        return true;
    });

    /* ---------- 3. 表单提交防重复 ---------- */
    $(document).on('submit', 'form[data-once]', function () {
        var btn = $(this).find('button[type="submit"]');
        if (btn.data('submitted')) {
            return false;
        }
        btn.data('submitted', true).prop('disabled', true);
        return true;
    });

    /* ---------- 4. 图片懒加载（可选） ---------- */
    if ($.fn.lazyload === undefined && ('IntersectionObserver' in window)) {
        // 仅在需要时启用，站点图片较少时无需此块
    }

})(jQuery);
