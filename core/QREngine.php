<?php
// 二维码生成（支付码）
declare(strict_types=1);

class QRcode
{
    private static array $exp = [];
    private static array $log = [];

    private const VERSIONS = [
        1  => ['total' => 26,  'data' => [16],              'ec' => 10],
        2  => ['total' => 44,  'data' => [28],              'ec' => 16, 'align' => [18]],
        3  => ['total' => 70,  'data' => [44],              'ec' => 26, 'align' => [22]],
        4  => ['total' => 100, 'data' => [32, 32],          'ec' => 18, 'align' => [26]],
        5  => ['total' => 134, 'data' => [43, 43],          'ec' => 24, 'align' => [30]],
        6  => ['total' => 172, 'data' => [27, 27, 27, 27],  'ec' => 16, 'align' => [34]],
        7  => ['total' => 196, 'data' => [31, 31, 31, 31],  'ec' => 18, 'align' => [22, 38]],
        8  => ['total' => 242, 'data' => [39, 39, 38, 38],  'ec' => 22, 'align' => [24, 42]],
        9  => ['total' => 292, 'data' => [37, 37, 36, 36, 36], 'ec' => 22, 'align' => [26, 46]],
        10 => ['total' => 346, 'data' => [44, 44, 43, 43, 42], 'ec' => 26, 'align' => [28, 50]],
    ];

    public static function png_data(string $text, int $moduleSize = 4, int $margin = 4): string
    {
        $matrix = self::encode($text);
        $size = count($matrix);
        $imgSize = ($size + $margin * 2) * $moduleSize;

        $img = imagecreatetruecolor($imgSize, $imgSize);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 29, 33, 41);
        imagefilledrectangle($img, 0, 0, $imgSize, $imgSize, $white);
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if ($matrix[$r][$c] === true) {
                    imagefilledrectangle(
                        $img,
                        ($c + $margin) * $moduleSize,
                        ($r + $margin) * $moduleSize,
                        ($c + $margin + 1) * $moduleSize - 1,
                        ($r + $margin + 1) * $moduleSize - 1,
                        $black
                    );
                }
            }
        }
        ob_start();
        imagepng($img);
        $png = (string)ob_get_clean();
        imagedestroy($img);
        return $png;
    }

    private static function encode(string $text): array
    {
        self::initGf();
        $len = strlen($text);
        if ($len > 213) {
            throw new RuntimeException('QR 内容超过 213 字节上限');
        }

        $version = 0;
        foreach (self::VERSIONS as $v => $spec) {
            $countBits = $v <= 9 ? 8 : 16;
            $dataCodewords = $spec['total'] - $spec['ec'] * count($spec['data']);
            if ((4 + $countBits + $len * 8) <= $dataCodewords * 8) {
                $version = $v;
                break;
            }
        }
        if ($version === 0) {
            throw new RuntimeException('QR 内容过长');
        }
        $spec = self::VERSIONS[$version];
        $dataTotal = array_sum($spec['data']);

        $bits = '';
        $bits .= '0100';         $bits .= str_pad(decbin($len), $version <= 9 ? 8 : 16, '0', STR_PAD_LEFT);
        for ($i = 0; $i < $len; $i++) {
            $bits .= str_pad(decbin(ord($text[$i])), 8, '0', STR_PAD_LEFT);
        }
        $capBits = $dataTotal * 8;
        $bits = substr($bits . '0000', 0, min(strlen($bits) + 4, $capBits));
        while (strlen($bits) % 8 !== 0) {
            $bits .= '0';
        }
        $padSeq = ['11101100', '00010001'];
        $pi = 0;
        while (strlen($bits) < $capBits) {
            $bits .= $padSeq[$pi++ % 2];
        }

        $dataBytes = [];
        for ($i = 0; $i < $dataTotal; $i++) {
            $dataBytes[] = (int)bindec(substr($bits, $i * 8, 8));
        }

        $blocks = [];
        $offset = 0;
        foreach ($spec['data'] as $blockDataLen) {
            $blockData = array_slice($dataBytes, $offset, $blockDataLen);
            $offset += $blockDataLen;
            $blocks[] = [
                'data' => $blockData,
                'ec'   => self::rsEncode($blockData, $spec['ec']),
            ];
        }

        $codewords = [];
        $maxData = max(array_map('count', array_column($blocks, 'data')));
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($blocks as $b) {
                if ($i < count($b['data'])) {
                    $codewords[] = $b['data'][$i];
                }
            }
        }
        for ($i = 0; $i < $spec['ec']; $i++) {
            foreach ($blocks as $b) {
                $codewords[] = $b['ec'][$i];
            }
        }
        $totalBits = $spec['total'] * 8;
        $allBits = '';
        foreach ($codewords as $cw) {
            $allBits .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
        }
        while (strlen($allBits) < $totalBits) {
            $allBits .= '0';
        }

        $size = 17 + $version * 4;
        $m = array_fill(0, $size, array_fill(0, $size, null));

        self::drawFinder($m, 0, 0);
        self::drawFinder($m, $size - 7, 0);
        self::drawFinder($m, 0, $size - 7);
        self::drawTiming($m, $size);

        $aligns = $spec['align'] ?? [];
        foreach ($aligns as $cy) {
            foreach ($aligns as $cx) {
                self::drawAlignment($m, $cx, $cy);
            }
        }

        self::reserveFormat($m, $size);
        if ($version >= 7) {
            self::reserveVersion($m, $size);
        }

        $bitIdx = 0;
        $col = $size - 1;
        $upward = true;
        while ($col > 0) {
            if ($col === 6) {
                $col = 5;             }
            for ($i = 0; $i < $size; $i++) {
                $row = $upward ? $size - 1 - $i : $i;
                for ($c = 0; $c < 2; $c++) {
                    $x = $col - $c;
                    if ($m[$row][$x] !== null) {
                        continue;
                    }
                    $dark = false;
                    if ($bitIdx < $totalBits) {
                        $dark = $allBits[$bitIdx] === '1';
                    }
                    $bitIdx++;
                    if (($row + $x) % 2 === 0) {
                        $dark = !$dark;                     }
                    $m[$row][$x] = $dark;
                }
            }
            $upward = !$upward;
            $col -= 2;
        }

        self::drawFormat($m, $size, 0);         if ($version >= 7) {
            self::drawVersion($m, $size, $version);
        }

        return $m;
    }

    private static function initGf(): void
    {
        if (self::$exp !== []) {
            return;
        }
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$exp[$i] = $x;
            self::$log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        self::$exp[255] = self::$exp[0];
    }

    private static function gfMul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        return self::$exp[(self::$log[$a] + self::$log[$b]) % 255];
    }

    private static function rsEncode(array $data, int $ecLen): array
    {
        $g = [1];
        for ($i = 0; $i < $ecLen; $i++) {
            $g = self::polyMulDesc($g, [1, self::$exp[$i]]);
        }

        $buf = array_merge($data, array_fill(0, $ecLen, 0));
        $dataLen = count($data);
        for ($i = 0; $i < $dataLen; $i++) {
            $t = $buf[$i];
            if ($t === 0) {
                continue;
            }
            $tl = self::$log[$t];
            for ($j = 1; $j <= $ecLen; $j++) {
                if ($g[$j] !== 0) {
                    $buf[$i + $j] ^= self::$exp[($tl + self::$log[$g[$j]]) % 255];
                }
            }
        }
        return array_slice($buf, $dataLen);
    }

    private static function polyMulDesc(array $a, array $b): array
    {
        $out = array_fill(0, count($a) + count($b) - 1, 0);
        foreach ($a as $i => $av) {
            if ($av === 0) {
                continue;
            }
            foreach ($b as $j => $bv) {
                if ($bv === 0) {
                    continue;
                }
                $out[$i + $j] ^= self::gfMul($av, $bv);
            }
        }
        return $out;
    }

    private static function set(array &$m, int $row, int $col, bool $dark): void
    {
        $m[$row][$col] = $dark;
    }

    private static function drawFinder(array &$m, int $topLeftCol, int $topLeftRow): void
    {
        for ($r = -1; $r <= 7; $r++) {
            for ($c = -1; $c <= 7; $c++) {
                $row = $topLeftRow + $r;
                $col = $topLeftCol + $c;
                if ($row < 0 || $col < 0 || $row >= count($m) || $col >= count($m)) {
                    continue;
                }
                $inside = ($r >= 0 && $r <= 6 && $c >= 0 && $c <= 6);
                if ($inside && ($r === 0 || $r === 6 || $c === 0 || $c === 6 || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4))) {
                    $m[$row][$col] = true;
                } else {
                    $m[$row][$col] = false;
                }
            }
        }
    }

    private static function drawTiming(array &$m, int $size): void
    {
        for ($i = 8; $i < $size - 8; $i++) {
            $dark = $i % 2 === 0;
            if ($m[6][$i] === null) {
                $m[6][$i] = $dark;
            }
            if ($m[$i][6] === null) {
                $m[$i][6] = $dark;
            }
        }
    }

    private static function drawAlignment(array &$m, int $cx, int $cy): void
    {
        $size = count($m);
        for ($r = $cy - 2; $r <= $cy + 2; $r++) {
            for ($c = $cx - 2; $c <= $cx + 2; $c++) {
                if ($r < 0 || $c < 0 || $r >= $size || $c >= $size) {
                    return;
                }
                if ($m[$r][$c] !== null) {
                    return;                 }
            }
        }
        for ($r = -2; $r <= 2; $r++) {
            for ($c = -2; $c <= 2; $c++) {
                $dark = (max(abs($r), abs($c)) !== 1);
                self::set($m, $cy + $r, $cx + $c, $dark);
            }
        }
    }

    private static function reserveFormat(array &$m, int $size): void
    {
        for ($i = 0; $i <= 8; $i++) {
            if ($i !== 6) {
                $m[$i][8] = $m[$i][8] ?? false;
            }
            if ($i !== 8) {
                $m[8][$i] = $m[8][$i] ?? false;
            }
        }
        $m[7][8] = $m[7][8] ?? false;         for ($i = 0; $i < 9; $i++) {
            $m[$size - 1 - $i][8] = $m[$size - 1 - $i][8] ?? false;
        }
        for ($i = 0; $i < 8; $i++) {
            $m[8][$size - 1 - $i] = $m[8][$size - 1 - $i] ?? false;
        }
    }

    private static function reserveVersion(array &$m, int $size): void
    {
        for ($i = 0; $i < 18; $i++) {
            $a = $size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $m[$b][$a] = $m[$b][$a] ?? false;
            $m[$a][$b] = $m[$a][$b] ?? false;
        }
    }

    private static function drawFormat(array &$m, int $size, int $mask): void
    {
        $data = (0 << 3) | $mask;         $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = fn (int $i): bool => (($bits >> $i) & 1) === 1;

        for ($i = 0; $i <= 5; $i++) {
            self::set($m, $i, 8, $bit($i));
        }
        self::set($m, 7, 8, $bit(6));
        self::set($m, 8, 8, $bit(7));
        self::set($m, 8, 7, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            self::set($m, 8, 14 - $i, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            self::set($m, 8, $size - 1 - $i, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            self::set($m, $size - 15 + $i, 8, $bit($i));
        }
        self::set($m, $size - 8, 8, true);
    }

    private static function drawVersion(array &$m, int $size, int $version): void
    {
        $rem = $version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        }
        $bits = ($version << 12) | $rem;
        for ($i = 0; $i < 18; $i++) {
            $dark = (($bits >> $i) & 1) === 1;
            $a = $size - 11 + $i % 3;
            $b = intdiv($i, 3);
            self::set($m, $b, $a, $dark);
            self::set($m, $a, $b, $dark);
        }
    }
}
