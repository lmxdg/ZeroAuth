/* 全站共享 UI：灵动岛提示 + 用户/代理商深浅色切换 */
(function () {
    'use strict';

    var wrap = null;

    function ensureWrap() {
        if (wrap) { return wrap; }
        wrap = document.createElement('div');
        wrap.className = 'ui-island-wrap';
        wrap.id = 'uiIslandWrap';
        document.body.appendChild(wrap);
        return wrap;
    }

    function toast(message, type, duration) {
        message = String(message || '').trim();
        if (!message) { return; }
        type = type || 'ok';
        duration = duration || 3600;
        var box = document.createElement('div');
        box.className = 'ui-island' + (type === 'error' ? ' is-error' : (type === 'info' ? ' is-info' : ''));
        var dot = document.createElement('span');
        dot.className = 'ui-island-dot';
        dot.textContent = type === 'error' ? '!' : (type === 'info' ? 'i' : '\u2713');
        var text = document.createElement('span');
        text.className = 'ui-island-text';
        text.textContent = message;
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'ui-island-close';
        close.setAttribute('aria-label', '关闭');
        close.textContent = '\u00d7';
        var bar = document.createElement('span');
        bar.className = 'ui-island-progress';
        bar.style.animationDuration = duration + 'ms';
        box.appendChild(dot);
        box.appendChild(text);
        box.appendChild(close);
        box.appendChild(bar);
        ensureWrap().appendChild(box);

        var timer = null;
        function dismiss() {
            if (!box || box.classList.contains('out')) { return; }
            box.classList.add('out');
            clearTimeout(timer);
            setTimeout(function () {
                if (box && box.parentNode) { box.parentNode.removeChild(box); }
            }, 340);
        }
        close.addEventListener('click', dismiss);
        timer = setTimeout(dismiss, duration);
        box.addEventListener('mouseenter', function () { clearTimeout(timer); });
        box.addEventListener('mouseleave', function () { timer = setTimeout(dismiss, 1200); });
    }

    window.uiToast = toast;

    function boot() {
        // 成功提示自动上浮为右上角灵动岛
        document.querySelectorAll('.alert.alert-ok').forEach(function (el) {
            var node = el.querySelector('span');
            var msg = node ? node.textContent : el.textContent;
            if (msg && msg.trim()) {
                toast(msg, 'ok');
            }
            el.remove();
        });

        // 用户中心 / 代理商中心深浅色切换
        var themeBtn = document.getElementById('uThemeBtn');
        var themeImg = document.getElementById('uThemeIcon');
        var key = document.documentElement.getAttribute('data-theme-key') || 'centerTheme';

        function applyTheme(mode) {
            var dark = mode === 'dark';
            document.documentElement.classList.toggle('theme-dark', dark);
            document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
            if (themeImg) {
                var light = themeImg.getAttribute('data-src-light') || '../assets/svg/moon.svg';
                var night = themeImg.getAttribute('data-src-dark') || '../assets/svg/sun.svg';
                themeImg.src = dark ? night : light;
            }
            try { localStorage.setItem(key, mode); } catch (e) { }
        }

        if (themeBtn) {
            themeBtn.addEventListener('click', function () {
                var dark = !document.documentElement.classList.contains('theme-dark');
                applyTheme(dark ? 'dark' : 'light');
            });
            applyTheme(document.documentElement.classList.contains('theme-dark') ? 'dark' : 'light');
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
