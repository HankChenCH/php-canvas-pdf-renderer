<?php

declare(strict_types=1);

namespace HankChen\CanvasNext\Renderer\Pdf\Tests;

use HankChen\CanvasNext\Renderer\Pdf\Rgba;
use PHPUnit\Framework\TestCase;

/**
 * hex 颜色解析正反例矩阵——对称平移 go-canvas/pdf-renderer/color_test.go（几何/颜色契约
 * 的一部分，两后端防线必须对称）。正例 = go TestParseColor 同一用例集；反例 = go
 * TestParseColorInvalid 同一用例集（非法输入防线随矩阵平移，防 PHP 侧留静默面）。
 */
final class RgbaTest extends TestCase
{
    public function testParseValidForms(): void
    {
        $cases = [
            ['#000000', [0, 0, 0, 255]],
            ['ffffff', [255, 255, 255, 255]],
            ['#ff0000', [255, 0, 0, 255]],
            ['#f00', [255, 0, 0, 255]],
            ['#abc', [170, 187, 204, 255]],
            ['#ff000080', [255, 0, 0, 128]],
            ['#f008', [255, 0, 0, 136]],
        ];
        foreach ($cases as [$in, $want]) {
            $got = Rgba::parse($in);
            self::assertSame($want, [$got->r, $got->g, $got->b, $got->a], "parse($in)");
        }
    }

    public function testParseInvalidInputThrows(): void
    {
        $thrown = 0;
        foreach (['', '#', '#ff', '#fffff', '#ff00000', 'red', '#zzz', '#ff00zz'] as $in) {
            try {
                Rgba::parse($in);
                self::fail("parse($in) 应报错中止");
            } catch (\InvalidArgumentException) {
                $thrown++; // 预期：非法输入报错中止渲染（防线 = 几何契约的一部分）
            }
        }
        self::assertSame(8, $thrown, '八个反例必须全部报错');
    }
}
