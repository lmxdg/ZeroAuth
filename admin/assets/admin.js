(function () {
    'use strict';

    var PREF_KEY = 'adminPrefs';
    var PREF_DEFAULTS = {
        themeMode: 'light', menuLayout: 'left', menuTheme: 'light', primary: '#006EFF',
        boxStyle: 'card', multiTab: false, accordion: false, showCollapseBtn: true,
        showQuick: true, showReload: true, showBreadcrumb: false, showLang: true,
        showProgress: true, colorWeak: false, watermark: false, menuWidth: 230,
        tabStyle: 'chip', pageAnim: 'fade', radius: 8, lang: 'zh-CN'
    };

    function prefLoad() {
        try {
            var o = JSON.parse(localStorage.getItem(PREF_KEY) || '{}');
            for (var k in PREF_DEFAULTS) { if (o[k] === undefined) o[k] = PREF_DEFAULTS[k]; }
            return o;
        } catch (ex) { return JSON.parse(JSON.stringify(PREF_DEFAULTS)); }
    }

    function prefStore(p) {
        try { localStorage.setItem(PREF_KEY, JSON.stringify(p)); } catch (ex) {  }
    }

    var prefs = prefLoad();

    function hexToRgb(hex) {
        var s = String(hex || '').replace('#', '');
        if (s.length === 3) s = s.replace(/./g, function (c) { return c + c; });
        var n = parseInt(s, 16) || 0;
        return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
    }

    function applyPrefs(p) {
        var r = document.documentElement, st = r.style, rgb = hexToRgb(p.primary);
        st.setProperty('--primary', p.primary);
        st.setProperty('--primary-rgb', rgb.join(','));
        st.setProperty('--primary-hover', 'rgb(' + rgb.map(function (v) { return Math.round(v * 0.85); }).join(',') + ')');
        st.setProperty('--radius', p.radius + 'px');
        r.classList.toggle('theme-dark', p.themeMode === 'dark');
        r.classList.toggle('menu-top', p.menuLayout === 'top');
        r.classList.toggle('menu-dark', p.menuTheme === 'dark');
        r.classList.toggle('box-flat', p.boxStyle === 'shadow');
        r.classList.toggle('color-weak', !!p.colorWeak);
        r.classList.toggle('multitab', !!p.multiTab);
        r.classList.toggle('show-crumbs', !!p.showBreadcrumb);
        r.classList.toggle('tab-line', p.tabStyle === 'line');
        r.classList.toggle('anim-fade', p.pageAnim === 'fade');
        r.classList.toggle('anim-slide', p.pageAnim === 'slide');
        r.classList.toggle('hide-collapsebtn', !p.showCollapseBtn);
        r.classList.toggle('hide-quick', !p.showQuick);
        r.classList.toggle('hide-reload', !p.showReload);
        r.classList.toggle('hide-lang', !p.showLang);
        if (p.lang) r.setAttribute('lang', p.lang);
    }

    applyPrefs(prefs);

    var trigger = document.getElementById('menuTrigger');
    var sidebar = document.getElementById('adminSidebar');
    var mask = document.getElementById('sideMask');

    function closeSidebar() {
        if (sidebar) sidebar.classList.remove('open');
        if (mask) mask.classList.remove('show');
    }

    if (trigger && sidebar) {
        trigger.addEventListener('click', function () {
            sidebar.classList.toggle('open');
            if (mask) mask.classList.toggle('show', sidebar.classList.contains('open'));
        });
        if (mask) mask.addEventListener('click', closeSidebar);
    }

    var STORAGE_KEY = 'adminSideOpen';
    var groups = document.querySelectorAll('.side-group[data-group]');

    function readOpen() {
        try {
            var raw = localStorage.getItem(STORAGE_KEY);
            var obj = raw ? JSON.parse(raw) : {};
            return obj && typeof obj === 'object' ? obj : {};
        } catch (ex) { return {}; }
    }

    function writeOpen(map) {
        try { localStorage.setItem(STORAGE_KEY, JSON.stringify(map)); } catch (ex) {  }
    }

    if (groups.length) {
        var saved = readOpen();
        groups.forEach(function (g) {

            var id = g.getAttribute('data-group');
            if (g.querySelector('.side-item.active') || saved[id]) {
                g.classList.remove('collapsed');
            } else {
                g.classList.add('collapsed');
            }
        });

        groups.forEach(function (g) {
            var parent = g.querySelector('.side-parent');
            if (!parent) return;
            function toggle() {
                g.classList.toggle('collapsed');

                if (prefs.accordion && !g.classList.contains('collapsed')) {
                    groups.forEach(function (o) { if (o !== g) o.classList.add('collapsed'); });
                }
                var map = readOpen();
                if (g.classList.contains('collapsed')) delete map[g.getAttribute('data-group')];
                else map[g.getAttribute('data-group')] = 1;
                writeOpen(map);
            }
            parent.addEventListener('click', toggle);
            parent.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); toggle(); }
            });
        });
    }

    var confirmMask = document.getElementById('confirmMask');
    var confirmText = document.getElementById('confirmText');
    var confirmOk = document.getElementById('confirmOk');
    var confirmCancel = document.getElementById('confirmCancel');
    var confirmInput = document.getElementById('confirmInput');
    var pendingForm = null;

    function tConfirm(message, onOk) {
        var needPrompt = !!(confirmInput && confirmInput.style.display === 'block');
        if (window.adminLayer && !needPrompt) {
            window.adminLayer.confirm(message, {
                title: '确认操作',
                btn: ['取消', '确认'],
                icon: 3,
                shadeClose: true
            }, function (index) {
                window.adminLayer.close(index);
                if (typeof onOk === 'function') onOk();
            });
            return;
        }
        if (!confirmMask) {

            if (typeof onOk === 'function') onOk();
            return;
        }
        confirmText.textContent = message;
        confirmMask.classList.add('show');
        confirmOk.onclick = function () {
            confirmMask.classList.remove('show');
            if (typeof onOk === 'function') onOk();
        };
    }

    if (confirmCancel) {
        confirmCancel.addEventListener('click', function () {
            confirmMask.classList.remove('show');
            pendingForm = null;
        });
    }
    if (confirmMask) {
        confirmMask.addEventListener('click', function (ev) {
            if (ev.target === confirmMask) {
                confirmMask.classList.remove('show');
                pendingForm = null;
            }
        });
    }

    document.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (form && form.hasAttribute && form.hasAttribute('data-confirm')) {
            ev.preventDefault();
            pendingForm = form;
            tConfirm(form.getAttribute('data-confirm'), function () {
                form.removeAttribute('data-confirm');
                if (form.requestSubmit) form.requestSubmit();
                else form.submit();
            });
        }
    }, true);

    document.addEventListener('click', function (ev) {
        var btn = ev.target && ev.target.closest ? ev.target.closest('button[data-confirm]') : null;
        if (!btn) return;
        var form = btn.closest('form');
        if (!form) return;
        ev.preventDefault();
        var promptName = btn.getAttribute('data-prompt');
        if (confirmInput) {
            confirmInput.style.display = promptName ? 'block' : 'none';
            confirmInput.value = '';
            confirmInput.style.borderColor = '';
            if (promptName) {
                confirmInput.placeholder = btn.getAttribute('data-prompt-label') || '请输入';
                confirmInput.focus();
            }
        }
        tConfirm(btn.getAttribute('data-confirm'), function () {
            if (promptName && confirmInput) {
                var v = confirmInput.value.replace(/(^\s+|\s+$)/g, '');
                var minLen = parseInt(btn.getAttribute('data-prompt-min'), 10) || 0;
                if (v === '' || v.length < minLen) {
                    confirmInput.style.borderColor = 'var(--error)';
                    confirmInput.focus();
                    return false;
                }
                var target = form.querySelector('input[name="' + promptName + '"]');
                if (target) target.value = v;
            }
            btn.removeAttribute('data-confirm');
            btn.removeAttribute('data-prompt');
            if (form.requestSubmit) form.requestSubmit();
            else form.submit();
        });
    }, true);

    document.addEventListener('click', function (ev) {
        var chip = ev.target && ev.target.closest ? ev.target.closest('.qr-chip') : null;
        if (chip) {
            var ta = document.getElementById('replyContent');
            if (ta) {
                var text = chip.getAttribute('data-text') || '';
                var cur = ta.value.replace(/\s*$/, '');
                ta.value = cur ? cur + '\n' + text : text;
                ta.focus();
                ta.scrollTop = ta.scrollHeight;
            }
            return;
        }
        var tog = ev.target && ev.target.closest ? ev.target.closest('[data-toggle-qr]') : null;
        if (tog) {
            var panel = document.getElementById('qrPanel');
            if (panel) panel.style.display = panel.style.display === 'none' ? '' : 'none';
        }
    });

    function escapeHtml(s) {
        return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    var PHP_KEYWORDS = ['abstract','and','array','as','break','callable','case','catch','class','clone','const','continue','declare','default','do','echo','else','elseif','empty','enddeclare','endfor','endforeach','endif','endswitch','endwhile','extends','final','finally','fn','for','foreach','function','global','goto','if','implements','include','include_once','instanceof','insteadof','interface','isset','list','match','namespace','new','or','print','private','protected','public','readonly','require','require_once','return','static','switch','throw','trait','try','unset','use','var','while','xor','yield','true','false','null'];

    function highlightPhp(code) {
        var out = '';
        var i = 0, len = code.length;
        var kwMap = {};
        PHP_KEYWORDS.forEach(function (k) { kwMap[k] = 1; });

        while (i < len) {
            var ch = code[i];

            if (ch === '{' && code[i + 1] === '{') {
                var endPh = code.indexOf('}}', i + 2);
                if (endPh !== -1) {
                    out += '<span class="tk-placeholder">' + escapeHtml(code.slice(i, endPh + 2)) + '</span>';
                    i = endPh + 2;
                    continue;
                }
            }

            if (ch === '/' && code[i + 1] === '/') {
                var nl = code.indexOf('\n', i);
                if (nl === -1) nl = len;
                out += '<span class="tk-comment">' + escapeHtml(code.slice(i, nl)) + '</span>';
                i = nl;
                continue;
            }
            if (ch === '#') {
                var nl2 = code.indexOf('\n', i);
                if (nl2 === -1) nl2 = len;
                out += '<span class="tk-comment">' + escapeHtml(code.slice(i, nl2)) + '</span>';
                i = nl2;
                continue;
            }
            if (ch === '/' && code[i + 1] === '*') {
                var endMl = code.indexOf('*/', i + 2);
                endMl = endMl === -1 ? len : endMl + 2;
                out += '<span class="tk-comment">' + escapeHtml(code.slice(i, endMl)) + '</span>';
                i = endMl;
                continue;
            }

            if (ch === "'" || ch === '"') {
                var j = i + 1;
                while (j < len) {
                    if (code[j] === '\\') { j += 2; continue; }
                    if (code[j] === ch) { j++; break; }
                    if (code[j] === '\n') break;
                    j++;
                }
                out += '<span class="tk-string">' + escapeHtml(code.slice(i, j)) + '</span>';
                i = j;
                continue;
            }

            if (ch === '$') {
                var m = /^(\$[a-zA-Z_][a-zA-Z0-9_]*)/.exec(code.slice(i));
                if (m) {
                    out += '<span class="tk-variable">' + escapeHtml(m[1]) + '</span>';
                    i += m[1].length;
                    continue;
                }
            }

            if (/[a-zA-Z_]/.test(ch)) {
                var w = /^[a-zA-Z_][a-zA-Z0-9_]*/.exec(code.slice(i))[0];
                if (kwMap[w.toLowerCase()]) {
                    out += '<span class="tk-keyword">' + escapeHtml(w) + '</span>';
                } else if (code[i + w.length] === '(') {
                    out += '<span class="tk-func">' + escapeHtml(w) + '</span>';
                } else {
                    out += escapeHtml(w);
                }
                i += w.length;
                continue;
            }

            out += escapeHtml(ch);
            i++;
        }
        return out;
    }

    var editor = document.getElementById('codeEditor');
    var preview = document.getElementById('codePreview');

    var langSelect = document.getElementById('langSelect');

    function renderPreview() {
        if (!editor || !preview) return;
        var lang = preview.getAttribute('data-lang') || 'php';
        preview.innerHTML = (lang === 'php' ? highlightPhp(editor.value) : escapeHtml(editor.value)) + '\n';
    }

    if (editor && preview) {
        var timer = null;
        editor.addEventListener('input', function () {
            if (timer) clearTimeout(timer);
            timer = setTimeout(renderPreview, 120);
        });
        if (langSelect) {
            langSelect.addEventListener('change', function () {
                preview.setAttribute('data-lang', langSelect.value === 'php' ? 'php' : 'text');
                renderPreview();
            });
        }
        renderPreview();
    }

    var staticViews = document.querySelectorAll('.code-view[data-lang="php"]');
    staticViews.forEach(function (view) {
        var raw = view.textContent;
        view.innerHTML = highlightPhp(raw);
    });

    if (editor) {
        editor.addEventListener('keydown', function (ev) {
            if (ev.key === 'Tab') {
                ev.preventDefault();
                var start = editor.selectionStart, endT = editor.selectionEnd;
                editor.value = editor.value.slice(0, start) + '    ' + editor.value.slice(endT);
                editor.selectionStart = editor.selectionEnd = start + 4;
            }
        });
    }

    var exportForm = document.getElementById('sdkExportForm');
    if (exportForm) {
        exportForm.addEventListener('submit', function (ev) {
            var tpl = exportForm.querySelector('select[name="template_id"]').value;
            var app = exportForm.querySelector('select[name="app_id"]').value;
            if (!tpl || !app) {
                ev.preventDefault();
                tConfirmExportTip();
            }
        });
        function tConfirmExportTip() {
            var text = document.getElementById('confirmText');
            var title = document.getElementById('confirmTitle');
            if (text && title) {
                title.textContent = '提示';
                text.textContent = '请先选择 SDK 模板与目标应用，再执行打包导出。';
                confirmMask.classList.add('show');
                confirmOk.onclick = function () { confirmMask.classList.remove('show'); };
            }
        }
    }

    function showToast(msg, type) {
        var box = document.getElementById('toastBox');
        if (!box) return;
        var icons = { success: 'check-circle.svg', warn: 'warn-circle.svg', error: 'close-circle.svg', info: 'check-circle.svg' };
        var t = document.createElement('div');
        t.className = 'toast toast-' + (type || 'info');
        t.innerHTML = '<img src="../assets/svg/' + (icons[type] || icons.info) + '" alt=""><span></span>';
        t.querySelector('span').textContent = msg;
        box.appendChild(t);
        setTimeout(function () {
            t.classList.add('hide');
            setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 260);
        }, 3000);
    }

    document.querySelectorAll('.topbar-drop > button').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var drop = btn.parentElement;
            var was = drop.classList.contains('open');
            document.querySelectorAll('.topbar-drop.open').forEach(function (d) { d.classList.remove('open'); });
            if (!was) drop.classList.add('open');
        });
    });

    document.addEventListener('click', function (e) {
        document.querySelectorAll('.topbar-drop.open').forEach(function (d) {
            if (!d.contains(e.target)) d.classList.remove('open');
        });
    });

    var sideCollapseBtn = document.getElementById('sideCollapseBtn');
    if (sideCollapseBtn) {
        sideCollapseBtn.addEventListener('click', function () {
            document.documentElement.classList.toggle('side-collapsed');
        });
    }

    var prog = document.getElementById('topbarProgress');
    var reloadBtn = document.getElementById('reloadBtn');
    if (reloadBtn) {
        reloadBtn.addEventListener('click', function () {
            if (prog && prefs.showProgress) {
                prog.style.transition = 'none';
                prog.style.opacity = '1';
                prog.style.width = '0';
                void prog.offsetWidth;
                prog.style.transition = 'width .3s ease';
                prog.style.width = '70%';
            }
            setTimeout(function () { location.reload(); }, 300);
        });
    }

    var fsBtn = document.getElementById('fullscreenBtn');
    var fsIcon = document.getElementById('fullscreenIcon');

    function syncFsIcon() {
        if (!fsIcon) return;
        var on = fsIcon.getAttribute('data-src-on') || '../assets/svg/fullscreen-exit.svg';
        var off = fsIcon.getAttribute('data-src-off') || '../assets/svg/fullscreen.svg';
        fsIcon.src = document.fullscreenElement ? on : off;
    }

    if (fsBtn) {
        fsBtn.addEventListener('click', function () {
            try {
                if (document.fullscreenElement) {
                    document.exitFullscreen();
                } else if (document.documentElement.requestFullscreen) {
                    var r = document.documentElement.requestFullscreen();
                    if (r && r.catch) r.catch(function () {  });
                }
            } catch (ex) {  }
            setTimeout(syncFsIcon, 60);
        });
    }
    document.addEventListener('fullscreenchange', syncFsIcon);
    syncFsIcon();

    var langItems = document.querySelectorAll('.lang-item');

    function syncLang() {
        langItems.forEach(function (it) {
            it.classList.toggle('active', it.getAttribute('data-lang') === prefs.lang);
        });
    }

    langItems.forEach(function (it) {
        it.addEventListener('click', function () {
            prefs.lang = it.getAttribute('data-lang');
            prefStore(prefs);
            syncLang();
            document.documentElement.setAttribute('lang', prefs.lang);
            showToast('语言偏好已保存', 'success');
        });
    });
    syncLang();

    var themeBtn = document.getElementById('themeModeBtn');
    var themeIcon = document.getElementById('themeModeIcon');

    function syncThemeIcon() {
        if (!themeIcon) return;
        var light = themeIcon.getAttribute('data-src-light') || '../assets/svg/moon.svg';
        var dark = themeIcon.getAttribute('data-src-dark') || '../assets/svg/sun.svg';
        themeIcon.src = prefs.themeMode === 'dark' ? dark : light;
    }

    if (themeBtn) {
        themeBtn.addEventListener('click', function () {
            prefs.themeMode = prefs.themeMode === 'dark' ? 'light' : 'dark';
            prefStore(prefs);
            applyPrefs(prefs);
            syncThemeIcon();
            syncSettingsUI();
            renderWatermark();
        });
    }
    syncThemeIcon();

    function renderWatermark() {
        var old = document.getElementById('globalWatermark');
        if (old && old.parentNode) old.parentNode.removeChild(old);
        if (!prefs.watermark) return;
        var text = document.body.getAttribute('data-wm-text') || '';
        if (!text) return;
        var c = document.createElement('canvas');
        c.width = 300;
        c.height = 200;
        var ctx = c.getContext('2d');
        ctx.translate(150, 100);
        ctx.rotate(-Math.PI / 9);
        ctx.font = '14px sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = prefs.themeMode === 'dark' ? 'rgba(255,255,255,.07)' : 'rgba(29,33,41,.06)';
        ctx.fillText(text, 0, 0);
        var div = document.createElement('div');
        div.className = 'global-watermark';
        div.id = 'globalWatermark';
        div.style.backgroundImage = 'url(' + c.toDataURL() + ')';
        document.body.appendChild(div);
    }
    renderWatermark();

    var TAB_KEY = 'adminTabsList';
    var tabsBar = document.getElementById('adminTabs');

    function loadTabs() {
        try {
            var l = JSON.parse(localStorage.getItem(TAB_KEY) || '[]');
            return Array.isArray(l) ? l : [];
        } catch (ex) { return []; }
    }

    function saveTabs(l) {
        try { localStorage.setItem(TAB_KEY, JSON.stringify(l)); } catch (ex) {  }
    }

    function renderTabs() {
        if (!tabsBar) return;
        var cur = tabsBar.getAttribute('data-url') || location.pathname + location.search;
        var title = tabsBar.getAttribute('data-title') || document.title.split(' - ')[0] || '页面';
        var list = loadTabs();
        var hit = list.some(function (t) { return t.u === cur; });
        if (!hit) {
            list.push({ t: title, u: cur });
            if (list.length > 20) list = list.slice(list.length - 20);
            saveTabs(list);
        }
        if (!prefs.multiTab) { tabsBar.innerHTML = ''; return; }
        tabsBar.innerHTML = '';
        list.forEach(function (t, idx) {
            var a = document.createElement('a');
            a.className = 'atab' + (t.u === cur ? ' active' : '');
            a.href = t.u;
            var s = document.createElement('span');
            s.textContent = t.t;
            a.appendChild(s);
            var x = document.createElement('i');
            x.className = 'atab-close';
            x.title = '关闭';
            x.innerHTML = '&times;';
            x.addEventListener('click', function (ev) {
                ev.preventDefault();
                ev.stopPropagation();
                var l2 = loadTabs();
                l2.splice(idx, 1);
                saveTabs(l2);
                if (t.u === cur) {
                    var nxt = l2[idx] || l2[idx - 1];
                    location.href = nxt ? nxt.u : 'dashboard.php';
                } else {
                    renderTabs();
                }
            });
            a.appendChild(x);
            tabsBar.appendChild(a);
        });
    }
    renderTabs();

    var drawer = document.getElementById('settingsDrawer');
    var settingsMaskEl = document.getElementById('settingsMask');
    var settingsBtn = document.getElementById('settingsBtn');
    var settingsClose = document.getElementById('settingsClose');

    function openDrawer() {
        if (drawer) drawer.classList.add('show');
        if (settingsMaskEl) settingsMaskEl.classList.add('show');
        syncSettingsUI();
    }

    function closeDrawer() {
        if (drawer) drawer.classList.remove('show');
        if (settingsMaskEl) settingsMaskEl.classList.remove('show');
    }

    if (settingsBtn) settingsBtn.addEventListener('click', openDrawer);
    if (settingsClose) settingsClose.addEventListener('click', closeDrawer);
    if (settingsMaskEl) settingsMaskEl.addEventListener('click', closeDrawer);

    function syncSettingsUI() {
        document.querySelectorAll('#settingsDrawer [data-pref][data-val]').forEach(function (ch) {
            ch.classList.toggle('active', String(prefs[ch.getAttribute('data-pref')]) === ch.getAttribute('data-val'));
        });
        document.querySelectorAll('#settingsDrawer input[type="checkbox"][data-pref]').forEach(function (ck) {
            ck.checked = !!prefs[ck.getAttribute('data-pref')];
        });
        document.querySelectorAll('#settingsDrawer [data-color]').forEach(function (sw) {
            sw.classList.toggle('active', String(prefs.primary).toLowerCase() === sw.getAttribute('data-color').toLowerCase());
        });
        document.querySelectorAll('#settingsDrawer input[type="number"][data-num]').forEach(function (nm) {
            nm.value = prefs[nm.getAttribute('data-num')];
        });
    }
    syncSettingsUI();

    document.querySelectorAll('#settingsDrawer [data-pref][data-val]').forEach(function (ch) {
        ch.addEventListener('click', function () {
            prefs[ch.getAttribute('data-pref')] = ch.getAttribute('data-val');
            prefStore(prefs);
            applyPrefs(prefs);
            syncSettingsUI();
            syncThemeIcon();
            renderWatermark();
        });
    });

    document.querySelectorAll('#settingsDrawer input[type="checkbox"][data-pref]').forEach(function (ck) {
        ck.addEventListener('change', function () {
            prefs[ck.getAttribute('data-pref')] = ck.checked;
            prefStore(prefs);
            applyPrefs(prefs);
            renderTabs();
            renderWatermark();
        });
    });

    document.querySelectorAll('#settingsDrawer [data-color]').forEach(function (sw) {
        sw.addEventListener('click', function () {
            prefs.primary = sw.getAttribute('data-color');
            prefStore(prefs);
            applyPrefs(prefs);
            syncSettingsUI();
        });
    });

    function clampNum(v, min, max, dft) {
        v = parseInt(v, 10);
        if (isNaN(v)) v = dft;
        return Math.min(max, Math.max(min, v));
    }

    document.querySelectorAll('#settingsDrawer input[type="number"][data-num]').forEach(function (nm) {
        var key = nm.getAttribute('data-num');
        var range = { radius: [0, 16, 8] }[key];
        nm.addEventListener('input', function () {
            prefs[key] = clampNum(nm.value, range[0], range[1], range[2]);
            prefStore(prefs);
            applyPrefs(prefs);
        });
        nm.addEventListener('change', function () { nm.value = prefs[key]; });
    });

    var resetBtn = document.getElementById('settingsReset');
    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            prefs = JSON.parse(JSON.stringify(PREF_DEFAULTS));
            prefStore(prefs);
            applyPrefs(prefs);
            syncSettingsUI();
            syncThemeIcon();
            syncLang();
            renderTabs();
            renderWatermark();
            showToast('已恢复默认设置', 'success');
        });
    }
})();
