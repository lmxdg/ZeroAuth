<?php
// 在线更新前端组件：进度条弹层 + 分块下载脚本
// 依赖 admin/update.php 的 AJAX 接口（prepare / chunk / apply）
declare(strict_types=1);

/**
 * 输出更新弹层所需的样式、DOM 与脚本（自包含，不依赖 admin.css，避免样式缓存失效）
 */
function update_client_assets(): void
{
    $csrf = function_exists('csrf_token') ? csrf_token() : '';
    ?>
<style>
.upd-mask{position:fixed;inset:0;z-index:9999;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(15,23,42,.55);-webkit-backdrop-filter:blur(2px);backdrop-filter:blur(2px)}
.upd-mask.open{display:flex}
.upd-box{width:100%;max-width:560px;max-height:88vh;display:flex;flex-direction:column;background:#FFFFFF;border-radius:14px;box-shadow:0 24px 70px rgba(2,12,32,.38);overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif}
.upd-head{display:flex;align-items:center;gap:12px;padding:20px 22px 14px}
.upd-ico{width:38px;height:38px;flex:none;border-radius:11px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#006EFF,#4F46E5);color:#fff;font-size:18px;font-weight:700}
.upd-title{font-size:16px;font-weight:700;line-height:22px;color:#1D2129}
.upd-sub{font-size:12.5px;line-height:18px;color:#86909C;margin-top:2px}
.upd-body{padding:0 22px 4px}
.upd-bar{position:relative;height:10px;border-radius:999px;background:#EDF1F7;overflow:hidden}
.upd-bar>i{display:block;height:100%;width:0;border-radius:999px;background:linear-gradient(90deg,#006EFF,#38A1FF);transition:width .25s ease}
.upd-bar.indet>i{width:38%!important;animation:updSlide 1.1s ease-in-out infinite}
@keyframes updSlide{0%{margin-left:-40%}100%{margin-left:100%}}
.upd-meta{display:flex;align-items:center;justify-content:space-between;font-size:12.5px;color:#4E5969;margin-top:8px;font-variant-numeric:tabular-nums}
.upd-pct{font-weight:700;color:#006EFF}
.upd-log{margin:14px 0 0;padding:12px 14px;border-radius:10px;background:#0F172A;color:#C8D3E5;font-family:Consolas,Menlo,Monaco,monospace;font-size:12px;line-height:20px;max-height:220px;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere;display:none}
.upd-log.show{display:block}
.upd-log .ok{color:#4ADE80}
.upd-log .err{color:#F87171}
.upd-foot{display:flex;align-items:center;justify-content:flex-end;gap:10px;padding:16px 22px 18px}
.upd-btn{display:inline-flex;align-items:center;justify-content:center;height:36px;padding:0 20px;border-radius:8px;font-size:14px;font-weight:600;border:1px solid transparent;cursor:pointer;text-decoration:none;transition:background .15s,color .15s,border-color .15s}
.upd-btn-plain{background:#fff;border-color:#E5E6EB;color:#1D2129}
.upd-btn-plain:hover{border-color:#006EFF;color:#006EFF}
.upd-btn-plain:disabled{opacity:.5;cursor:not-allowed}
.upd-btn-primary{background:#006EFF;color:#fff}
.upd-btn-primary:hover{background:#005BD1;color:#fff}
html.theme-dark .upd-box{background:#1D2129;box-shadow:0 24px 70px rgba(0,0,0,.6)}
html.theme-dark .upd-title{color:#E8EAED}
html.theme-dark .upd-sub{color:#8A8F99}
html.theme-dark .upd-bar{background:#2E3238}
html.theme-dark .upd-meta{color:#A9AEB8}
html.theme-dark .upd-btn-plain{background:transparent;border-color:#3A3F47;color:#E8EAED}
@media(max-width:560px){.upd-head{padding:16px 16px 12px}.upd-body{padding:0 16px}.upd-foot{padding:14px 16px 16px}.upd-box{max-height:92vh}}
</style>
<div class="upd-mask" id="updMask" aria-hidden="true">
    <div class="upd-box" role="dialog" aria-modal="true" aria-labelledby="updTitle">
        <div class="upd-head">
            <span class="upd-ico">↑</span>
            <div>
                <div class="upd-title" id="updTitle">系统在线更新</div>
                <div class="upd-sub" id="updSub">正在准备…</div>
            </div>
        </div>
        <div class="upd-body">
            <div class="upd-bar" id="updBarWrap"><i id="updBar"></i></div>
            <div class="upd-meta">
                <span class="upd-pct" id="updPct">0%</span>
                <span id="updSize">—</span>
            </div>
            <div class="upd-log" id="updLog"></div>
        </div>
        <div class="upd-foot">
            <button type="button" class="upd-btn upd-btn-plain" id="updClose" disabled>关闭</button>
            <a class="upd-btn upd-btn-primary" id="updReload" href="" style="display:none">刷新页面</a>
        </div>
    </div>
</div>
<script>
(function () {
    if (window.__zauUpdater) { return; }
    window.__zauUpdater = true;

    var CSRF = <?php echo json_encode($csrf, JSON_UNESCAPED_UNICODE); ?>;
    var mask = document.getElementById('updMask');
    if (!mask) { return; }
    var elSub = document.getElementById('updSub');
    var elBar = document.getElementById('updBar');
    var elWrap = document.getElementById('updBarWrap');
    var elPct = document.getElementById('updPct');
    var elSize = document.getElementById('updSize');
    var elLog = document.getElementById('updLog');
    var btnClose = document.getElementById('updClose');
    var btnReload = document.getElementById('updReload');
    var running = false;

    function fmt(bytes) {
        if (!bytes || bytes < 0) { return '—'; }
        if (bytes >= 1048576) { return (bytes / 1048576).toFixed(2) + ' MB'; }
        if (bytes >= 1024) { return (bytes / 1024).toFixed(0) + ' KB'; }
        return bytes + ' B';
    }
    function log(line, cls) {
        elLog.classList.add('show');
        var span = document.createElement('div');
        if (cls) { span.className = cls; }
        span.textContent = line;
        elLog.appendChild(span);
        elLog.scrollTop = elLog.scrollHeight;
    }
    function sub(text) { elSub.textContent = text; }
    function setBar(pct) {
        if (pct === null) {
            elWrap.classList.add('indet');
            elPct.textContent = '…';
            return;
        }
        elWrap.classList.remove('indet');
        pct = Math.max(0, Math.min(100, pct));
        elBar.style.width = pct.toFixed(1) + '%';
        elPct.textContent = pct.toFixed(pct >= 100 ? 0 : 1) + '%';
    }
    function open() {
        mask.classList.add('open');
        mask.setAttribute('aria-hidden', 'false');
        elLog.innerHTML = '';
        elLog.classList.remove('show');
        btnClose.disabled = true;
        btnReload.style.display = 'none';
        setBar(0);
        elSize.textContent = '—';
    }
    function finish(ok, msg) {
        running = false;
        btnClose.disabled = false;
        document.querySelectorAll('[data-updater]').forEach(function (b) { b.disabled = false; });
        if (ok) {
            setBar(100);
            sub('更新完成');
            log('✔ ' + msg, 'ok');
            btnReload.style.display = 'inline-flex';
            btnReload.setAttribute('href', location.pathname + '?t=' + Date.now());
        } else {
            elWrap.classList.remove('indet');
            sub('更新未完成');
            log('✖ ' + msg, 'err');
        }
    }
    function post(action, data) {
        var body = new URLSearchParams();
        body.set('csrf', CSRF);
        if (data) { Object.keys(data).forEach(function (k) { body.set(k, data[k]); }); }
        return fetch('update.php?action=' + action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (r) {
            return r.text().then(function (t) {
                try { return JSON.parse(t); }
                catch (e) { throw new Error('服务器返回异常（HTTP ' + r.status + '）：' + t.replace(/<[^>]+>/g, '').slice(0, 120)); }
            });
        });
    }

    async function run() {
        if (running) { return; }
        running = true;
        document.querySelectorAll('[data-updater]').forEach(function (b) { b.disabled = true; });
        open();
        log('开始检查最新版本…');
        sub('正在检测版本…');
        setBar(null);

        var info;
        try {
            info = await post('prepare');
        } catch (e) {
            return finish(false, e.message);
        }
        if (!info || !info.ok) { return finish(false, (info && info.msg) || '版本检测失败'); }

        log('目标版本：V' + info.version + '　安装包：' + (info.name || 'package.zip'));
        sub('正在下载安装包 V' + info.version + '…');
        var total = info.total || 0;
        var offset = 0;
        var guard = 0;
        setBar(total ? 0 : null);
        elSize.textContent = total ? ('0 B / ' + fmt(total)) : '0 B';

        while (guard++ < 3000) {
            var chunk;
            try {
                chunk = await post('chunk', { offset: offset });
            } catch (e) {
                return finish(false, e.message);
            }
            if (!chunk || !chunk.ok) { return finish(false, (chunk && chunk.msg) || '下载失败'); }
            offset = chunk.downloaded || offset;
            total = chunk.total || total;
            if (total > 0) {
                setBar(offset / total * 100);
                elSize.textContent = fmt(offset) + ' / ' + fmt(total);
            } else {
                setBar(null);
                elSize.textContent = fmt(offset);
            }
            if (chunk.done) { break; }
            if (!chunk.received) { return finish(false, '下载中断：未接收到数据，请检查服务器网络。'); }
        }

        log('下载完成：' + fmt(offset));
        sub('正在解压并替换文件…');
        setBar(null);

        var res;
        try {
            res = await post('apply');
        } catch (e) {
            return finish(false, e.message);
        }
        if (!res || !res.ok) {
            if (res && res.log) { res.log.forEach(function (l) { log(l); }); }
            return finish(false, (res && res.msg) || '文件替换失败');
        }
        (res.log || []).forEach(function (l) { log(l); });
        finish(true, '已更新 ' + (res.copied || 0) + ' 个文件，刷新页面后生效。');
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-updater]') : null;
        if (!btn || btn.disabled) { return; }
        e.preventDefault();
        run();
    });
    btnClose.addEventListener('click', function () {
        if (running) { return; }
        mask.classList.remove('open');
        mask.setAttribute('aria-hidden', 'true');
    });
})();
</script>
    <?php
}
