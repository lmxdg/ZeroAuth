<?php
// 轻量 Markdown 渲染器：用于后台展示系统内置开发文档（标题/表格/代码块/列表/引用/行内标记）
declare(strict_types=1);

function md_lite_inline(string $s): string
{
    $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s) ?? $s;
    $s = preg_replace('/\*\*([^*]+)\*\*/', '<b>$1</b>', $s) ?? $s;
    $s = preg_replace('/\[([^\]]+)\]\((https?:[^)\s]+)\)/', '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>', $s) ?? $s;
    return $s;
}

function md_lite_to_html(string $md): string
{
    $lines = preg_split('/\r\n|\r|\n/', $md);
    $out = [];
    $inCode = false;
    $codeBuf = [];
    $listType = '';   // '' | 'ul' | 'ol'
    $tableRows = [];

    $closeList = static function () use (&$listType, &$out): void {
        if ($listType !== '') {
            $out[] = '</' . $listType . '>';
            $listType = '';
        }
    };
    $flushTable = static function () use (&$tableRows, &$out): void {
        if (!$tableRows) {
            return;
        }
        $rows = $tableRows;
        $tableRows = [];
        $parse = static function (string $line): array {
            $t = trim($line);
            $t = preg_replace('/^\|/', '', $t) ?? $t;
            $t = preg_replace('/\|$/', '', $t) ?? $t;
            return array_map('trim', explode('|', $t));
        };
        $head = $parse((string)array_shift($rows));
        if ($rows !== [] && preg_match('/^\s*\|?[\s:\-|]+\|?\s*$/', (string)$rows[0])) {
            array_shift($rows);
        }
        $html = '<div class="md-tablewrap"><table class="tb"><thead><tr>';
        foreach ($head as $c) {
            $html .= '<th>' . md_lite_inline($c) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $html .= '<tr>';
            foreach ($parse((string)$r) as $c) {
                $html .= '<td>' . md_lite_inline($c) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';
        $out[] = $html;
    };

    foreach ($lines as $line) {
        if ($inCode) {
            if (preg_match('/^\s*```\s*$/', $line)) {
                $out[] = '<pre class="md-code"><code>' . htmlspecialchars(implode("\n", $codeBuf), ENT_QUOTES, 'UTF-8') . '</code></pre>';
                $codeBuf = [];
                $inCode = false;
            } else {
                $codeBuf[] = $line;
            }
            continue;
        }
        if (preg_match('/^\s*```/', $line)) {
            $flushTable();
            $closeList();
            $inCode = true;
            continue;
        }
        if (preg_match('/^\s*\|.*\|\s*$/', $line)) {
            $closeList();
            $tableRows[] = $line;
            continue;
        }
        $flushTable();

        if (trim($line) === '') {
            $closeList();
            continue;
        }
        if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
            $closeList();
            $lvl = strlen($m[1]);
            $out[] = '<h' . $lvl . ' class="md-h">' . md_lite_inline($m[2]) . '</h' . $lvl . '>';
            continue;
        }
        if (preg_match('/^\s*(?:-{3,}|\*{3,})\s*$/', $line)) {
            $closeList();
            $out[] = '<hr class="md-hr">';
            continue;
        }
        if (preg_match('/^\s*>\s?(.*)$/', $line, $m)) {
            $closeList();
            $out[] = '<blockquote class="md-quote">' . md_lite_inline($m[1]) . '</blockquote>';
            continue;
        }
        if (preg_match('/^\s*[-*]\s+(.*)$/', $line, $m)) {
            if ($listType !== 'ul') {
                $closeList();
                $out[] = '<ul class="md-ul">';
                $listType = 'ul';
            }
            $out[] = '<li>' . md_lite_inline($m[1]) . '</li>';
            continue;
        }
        if (preg_match('/^\s*\d+[.)]\s+(.*)$/', $line, $m)) {
            if ($listType !== 'ol') {
                $closeList();
                $out[] = '<ol class="md-ol">';
                $listType = 'ol';
            }
            $out[] = '<li>' . md_lite_inline($m[1]) . '</li>';
            continue;
        }
        $closeList();
        $out[] = '<p class="md-p">' . md_lite_inline($line) . '</p>';
    }
    if ($inCode && $codeBuf) {
        $out[] = '<pre class="md-code"><code>' . htmlspecialchars(implode("\n", $codeBuf), ENT_QUOTES, 'UTF-8') . '</code></pre>';
    }
    $flushTable();
    $closeList();
    return implode("\n", $out);
}

// 后台文档渲染统一样式
function md_lite_styles(): string
{
    return <<<'CSS'
<style>
.md-doc{font-size:14px;line-height:1.9;color:#1D2129;max-width:960px}
.md-doc .md-h{margin:22px 0 10px;font-weight:700;color:#1D2129;line-height:1.5}
.md-doc h1.md-h{font-size:22px;margin-top:4px}
.md-doc h2.md-h{font-size:18px;padding-bottom:8px;border-bottom:1px solid #E5E6EB}
.md-doc h3.md-h{font-size:16px}
.md-doc h4.md-h,.md-doc h5.md-h,.md-doc h6.md-h{font-size:14px}
.md-doc .md-p{margin:8px 0}
.md-doc .md-ul,.md-doc .md-ol{margin:8px 0 8px 22px;display:block;list-style:revert}
.md-doc .md-ul{list-style:disc}
.md-doc .md-ol{list-style:decimal}
.md-doc .md-ul li,.md-doc .md-ol li{margin:4px 0;display:list-item}
.md-doc code{background:#F2F3F5;border:1px solid #E5E6EB;border-radius:4px;padding:1px 6px;font-size:12.5px;font-family:Menlo,Consolas,monospace;color:#C7254E;word-break:break-all}
.md-doc .md-code{background:#0F1722;color:#D6E2F0;border-radius:8px;padding:14px 16px;overflow:auto;font-size:12.5px;line-height:1.8;margin:10px 0}
.md-doc .md-code code{background:none;border:none;color:inherit;padding:0;font-size:inherit;white-space:pre}
.md-doc .md-tablewrap{overflow:auto;margin:10px 0}
.md-doc .tb{width:100%;border-collapse:collapse}
.md-doc .tb th,.md-doc .tb td{border:1px solid #E5E6EB;padding:8px 12px;text-align:left;vertical-align:top}
.md-doc .tb th{background:#F7F8FA;font-weight:600;white-space:nowrap}
.md-doc .md-quote{margin:10px 0;padding:10px 14px;border-left:3px solid #0052D9;background:#F0F5FF;border-radius:0 6px 6px 0;color:#4E5969}
.md-doc .md-hr{border:none;border-top:1px solid #E5E6EB;margin:18px 0}
.md-doc a{color:#0052D9}
</style>
CSS;
}
