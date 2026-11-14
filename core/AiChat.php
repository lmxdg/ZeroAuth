<?php
// AI 客服：后台配置读取与 OpenAI 兼容接口调用（服务端代理，密钥不落前端）
declare(strict_types=1);

/**
 * AI 客服是否已启用且配置完整（开关 + 接口地址 + 密钥 + 模型）
 */
function ai_cs_ready(): bool
{
    if (cfg('ai_cs_enabled', '0') !== '1') {
        return false;
    }
    return cfg('ai_cs_api_url', '') !== ''
        && cfg('ai_cs_api_key', '') !== ''
        && cfg('ai_cs_model', '') !== '';
}

/** 悬浮窗标题（默认「AI 客服」） */
function ai_cs_title(): string
{
    $t = trim(cfg('ai_cs_title', ''));
    return $t !== '' ? mb_substr($t, 0, 20) : 'AI 客服';
}

/** 欢迎语 */
function ai_cs_welcome(): string
{
    $w = trim(cfg('ai_cs_welcome', ''));
    return $w !== '' ? mb_substr($w, 0, 100) : '您好，我是 AI 客服，有什么可以帮您？';
}

/** 系统提示词（后台留空时使用内置默认提示词） */
function ai_cs_prompt(): string
{
    $p = trim(cfg('ai_cs_prompt', ''));
    if ($p !== '') {
        return mb_substr($p, 0, 2000);
    }
    $site = cfg('site_name', PRODUCT_NAME);
    return '你是「' . $site . '」的在线 AI 客服助手，当前咨询用户的用户名是「{username}」，可以此称呼用户。'
        . '请用简洁、友好、专业的简体中文回答用户问题，'
        . '帮助用户解决软件授权、卡密兑换、购买支付、使用部署等常见问题。'
        . '如遇无法确定的问题（如退款、人工处理），请建议用户提交工单或联系管理员。';
}

/**
 * 归一化 chat/completions 接口地址：兼容填写 Base URL（如 https://api.openai.com/v1）
 * 或完整地址（如 https://api.openai.com/v1/chat/completions）两种写法
 */
function ai_cs_endpoint(string $url): string
{
    $url = rtrim(trim($url), '/');
    if ($url === '') {
        return '';
    }
    if (preg_match('#/chat/completions$#i', $url)) {
        return $url;
    }
    return $url . '/chat/completions';
}

/**
 * 服务端调用标准 OpenAI 兼容接口（非流式 chat/completions）。
 * 成功返回 ['reply' => string]；失败返回 ['error' => string]。
 *
 * @param array $messages 已含系统提示词的 messages 数组 [['role'=>'user','content'=>'…'],…]
 */
function ai_cs_chat(array $messages): array
{
    $endpoint = ai_cs_endpoint(cfg('ai_cs_api_url', ''));
    $apiKey   = (string)cfg('ai_cs_api_key', '');
    $model    = (string)cfg('ai_cs_model', '');
    if ($endpoint === '' || $apiKey === '' || $model === '') {
        return ['error' => 'AI 客服尚未配置完整，请联系管理员在后台「系统配置 → AI 客服」完成设置。'];
    }

    $payload = json_encode([
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => 0.7,
        'stream'      => false,
    ], JSON_UNESCAPED_UNICODE);

    $resp = http_post($endpoint, $payload, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ], 60);
    if ($resp === false || trim($resp) === '') {
        return ['error' => '连接 AI 服务失败，请稍后重试。'];
    }

    $data = json_decode($resp, true);
    if (!is_array($data)) {
        return ['error' => 'AI 服务响应异常，请稍后重试。'];
    }
    if (isset($data['error'])) {
        $msg = is_array($data['error']) ? (string)($data['error']['message'] ?? '接口错误') : (string)$data['error'];
        return ['error' => 'AI 服务返回错误：' . mb_substr($msg, 0, 200)];
    }

    $reply = '';
    if (isset($data['choices'][0]['message']['content'])) {
        $reply = trim((string)$data['choices'][0]['message']['content']);
    } elseif (isset($data['choices'][0]['text'])) {
        // 兼容少数网关返回的 completions 风格字段
        $reply = trim((string)$data['choices'][0]['text']);
    }
    if ($reply === '') {
        return ['error' => 'AI 未返回内容，请稍后重试。'];
    }
    return ['reply' => mb_substr($reply, 0, 8000)];
}

/**
 * 输出用户中心 AI 客服悬浮窗（右下角浮动按钮 + 聊天面板）。
 * 仅在功能开启且配置完整时由 user_footer() 调用。
 */
function ai_cs_widget(): void
{
    if (!ai_cs_ready()) {
        return;
    }
    $title   = ai_cs_title();
    $welcome = ai_cs_welcome();
    ?>
<div class="ai-cs-fab" id="aiCsFab" role="button" aria-label="<?php echo e($title); ?>" title="<?php echo e($title); ?>">
    <svg viewBox="0 0 24 24" width="26" height="26" fill="none" aria-hidden="true">
        <path d="M12 3.2c-4.5 0-8.1 2.9-8.1 6.5 0 2.1 1.2 3.9 3.1 5.1l-.7 2.9c-.1.5.4.9.8.6l3.2-1.9c.6.1 1.1.1 1.7.1 4.5 0 8.1-2.9 8.1-6.5S16.5 3.2 12 3.2z" fill="currentColor" opacity=".92"/>
        <circle cx="9.1" cy="9.9" r="1.15" fill="#fff"/><circle cx="14.9" cy="9.9" r="1.15" fill="#fff"/>
    </svg>
    <span class="ai-cs-fab-dot" aria-hidden="true"></span>
</div>
<div class="ai-cs-mask" id="aiCsMask"></div>
<section class="ai-cs-panel" id="aiCsPanel" role="dialog" aria-label="<?php echo e($title); ?>" aria-hidden="true">
    <header class="ai-cs-head">
        <div class="ai-cs-head-ico" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none"><path d="M12 3.2c-4.5 0-8.1 2.9-8.1 6.5 0 2.1 1.2 3.9 3.1 5.1l-.7 2.9c-.1.5.4.9.8.6l3.2-1.9c.6.1 1.1.1 1.7.1 4.5 0 8.1-2.9 8.1-6.5S16.5 3.2 12 3.2z" fill="currentColor"/></svg>
        </div>
        <div class="ai-cs-head-txt">
            <b><?php echo e($title); ?></b>
            <i><span class="ai-cs-status-dot"></span>在线 · 由 AI 提供，仅供参考</i>
        </div>
        <button type="button" class="ai-cs-clear" id="aiCsClear" title="清空会话" aria-label="清空会话">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M3 6h18M8 6V4.5A1.5 1.5 0 0 1 9.5 3h5A1.5 1.5 0 0 1 16 4.5V6m2 0-1 14a1.6 1.6 0 0 1-1.6 1.5H8.6A1.6 1.6 0 0 1 7 20L6 6"/></svg>
        </button>
        <button type="button" class="ai-cs-close" id="aiCsClose" title="收起" aria-label="收起">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18"/></svg>
        </button>
    </header>
    <div class="ai-cs-body" id="aiCsBody">
        <div class="ai-cs-row"><div class="ai-cs-bubble ai-cs-bubble-ai"><?php echo e($welcome); ?></div></div>
    </div>
    <footer class="ai-cs-foot">
        <textarea class="ai-cs-input" id="aiCsInput" rows="1" maxlength="2000"
                  placeholder="输入你的问题，Enter 发送，Shift + Enter 换行"></textarea>
        <button type="button" class="ai-cs-send" id="aiCsSend" title="发送" aria-label="发送">
            <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4 20-7z"/></svg>
        </button>
    </footer>
</section>
<script>

(function () {
    var fab = document.getElementById('aiCsFab');
    var panel = document.getElementById('aiCsPanel');
    var mask = document.getElementById('aiCsMask');
    var closeBtn = document.getElementById('aiCsClose');
    var clearBtn = document.getElementById('aiCsClear');
    var body = document.getElementById('aiCsBody');
    var input = document.getElementById('aiCsInput');
    var sendBtn = document.getElementById('aiCsSend');
    if (!fab || !panel || !body || !input || !sendBtn) { return; }

    var history = [];          // [{role, content}]，仅记录真实对话（不含欢迎语）
    var sending = false;
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');

    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function scrollBottom() { body.scrollTop = body.scrollHeight; }
    function addBubble(text, cls) {
        var row = document.createElement('div');
        row.className = 'ai-cs-row' + (cls === 'me' ? ' ai-cs-row-me' : '');
        var b = document.createElement('div');
        b.className = 'ai-cs-bubble' + (cls === 'me' ? ' ai-cs-bubble-me' : ' ai-cs-bubble-ai');
        b.textContent = text;
        row.appendChild(b);
        body.appendChild(row);
        scrollBottom();
        return b;
    }
    function addTyping() {
        var row = document.createElement('div');
        row.className = 'ai-cs-row';
        row.innerHTML = '<div class="ai-cs-bubble ai-cs-bubble-ai ai-cs-typing"><span></span><span></span><span></span></div>';
        body.appendChild(row);
        scrollBottom();
        return row;
    }
    function setError(text) {
        var row = document.createElement('div');
        row.className = 'ai-cs-row';
        row.innerHTML = '<div class="ai-cs-bubble ai-cs-bubble-ai ai-cs-bubble-err">' + esc(text) + '</div>';
        body.appendChild(row);
        scrollBottom();
    }
    function trimHistory() {
        // 保留最近 20 条消息作为上下文
        if (history.length > 20) { history = history.slice(-20); }
    }
    function send() {
        var text = input.value.trim();
        if (sending || text === '') { return; }
        input.value = '';
        input.style.height = 'auto';
        addBubble(text, 'me');
        history.push({ role: 'user', content: text });
        trimHistory();
        sending = true;
        sendBtn.classList.add('off');
        var typing = addTyping();
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'ai_chat.php', true);
        xhr.setRequestHeader('Content-Type', 'application/json; charset=utf-8');
        if (csrfMeta) { xhr.setRequestHeader('X-CSRF-Token', csrfMeta.getAttribute('content')); }
        xhr.timeout = 70000;
        xhr.onload = function () {
            var done = false;
            try {
                var res = JSON.parse(xhr.responseText);
                if (res && res.code === 0 && res.data && res.data.reply) {
                    addBubble(res.data.reply, 'ai');
                    history.push({ role: 'assistant', content: res.data.reply });
                    trimHistory();
                    done = true;
                } else if (res && res.msg) {
                    setError(res.msg);
                    done = true;
                }
            } catch (e) { }
            if (!done) { setError('AI 客服暂时不可用（' + xhr.status + '），请稍后重试。'); }
            typing.remove();
            sending = false;
            sendBtn.classList.remove('off');
            input.focus();
        };
        xhr.onerror = function () {
            typing.remove();
            setError('网络异常，请稍后重试。');
            sending = false;
            sendBtn.classList.remove('off');
        };
        xhr.ontimeout = function () {
            typing.remove();
            setError('AI 响应超时，请稍后重试。');
            sending = false;
            sendBtn.classList.remove('off');
        };
        xhr.send(JSON.stringify({ messages: history }));
    }
    function openPanel() {
        panel.classList.add('show');
        mask.classList.add('show');
        panel.setAttribute('aria-hidden', 'false');
        setTimeout(function () { input.focus(); }, 120);
    }
    function closePanel() {
        panel.classList.remove('show');
        mask.classList.remove('show');
        panel.setAttribute('aria-hidden', 'true');
    }
    fab.addEventListener('click', function () {
        if (panel.classList.contains('show')) { closePanel(); } else { openPanel(); }
    });
    if (closeBtn) closeBtn.addEventListener('click', closePanel);
    if (mask) mask.addEventListener('click', closePanel);
    sendBtn.addEventListener('click', send);
    if (clearBtn) clearBtn.addEventListener('click', function () {
        history = [];
        body.innerHTML = '<div class="ai-cs-row"><div class="ai-cs-bubble ai-cs-bubble-ai"><?php echo e($welcome); ?></div></div>';
    });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
    });
    input.addEventListener('input', function () {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 110) + 'px';
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && panel.classList.contains('show')) { closePanel(); }
    });
})();
</script>
    <?php
}
