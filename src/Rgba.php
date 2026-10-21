<?php

declare(strict_types=1);

namespace HankChen\CanvasNext\Renderer\Pdf;

/**
 * 直 alpha 的 8 位色值（go-canvas/pdf-renderer color.go rgba 的对位物；PDF 面只经
 * SetFillColor/SetTextColor/SetAlpha 三缝消费，自持结构不引图像库）。
 */
final class Rgba
{
    public function __construct(
        public readonly int $r,
        public readonly int $g,
        public readonly int $b,
        public readonly int $a = 255,
    ) {
    }

    /**
     * 解析十六进制颜色为直 alpha 的 Rgba。
     * 支持 #rgb / #rgba / #rrggbb / #rrggbbaa，前导 # 可省略；3/4 位逐位翻倍后走同一累积路径。
     * 其余形态报错中止渲染（wire 面只产出 CSS 十六进制写法，颜色名/rgb() 函数串不在支持域——
     * 非法输入防线是几何契约的一部分，与 go-canvas/pdf-renderer color.go parseColor 同一公式对称平移）。
     */
    public static function parse(string $hex): self
    {
        $h = str_starts_with($hex, '#') ? substr($hex, 1) : $hex;
        $len = strlen($h);
        if ($len === 3 || $len === 4) {
            $doubled = '';
            for ($i = 0; $i < $len; $i++) {
                $doubled .= $h[$i] . $h[$i]; // 逐位翻倍：#f00 → ff0000
            }
            $h = $doubled;
        } elseif ($len !== 6 && $len !== 8) {
            throw new \InvalidArgumentException(sprintf('无法解析颜色 "%s": 支持 #rgb/#rgba/#rrggbbaa/#rrggbb/#rrggbbaa 形态', $hex));
        }

        $c = [0, 0, 0, 0];
        $n = strlen($h);
        for ($i = 0; $i < $n; $i++) {
            $v = self::hexDigit($h[$i]);
            if ($v === null) {
                throw new \InvalidArgumentException(sprintf('无法解析颜色 "%s": 非法十六进制位 "%s"', $hex, $h[$i]));
            }
            $c[intdiv($i, 2)] = $c[intdiv($i, 2)] * 16 + $v;
        }
        if ($n === 6) {
            $c[3] = 255;
        }

        return new self($c[0], $c[1], $c[2], $c[3]);
    }

    private static function hexDigit(string $ch): ?int
    {
        if ($ch >= '0' && $ch <= '9') {
            return (int)$ch;
        }
        if ($ch >= 'a' && $ch <= 'f') {
            return ord($ch) - ord('a') + 10;
        }
        if ($ch >= 'A' && $ch <= 'F') {
            return ord($ch) - ord('A') + 10;
        }
        return null;
    }
}
