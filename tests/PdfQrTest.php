<?php

declare(strict_types=1);

namespace HankChen\CanvasNext\Renderer\Pdf\Tests;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Layer\QrCodeLayer;
use HankChen\CanvasNext\Renderer\Pdf\Tests\Support\PopplerPdfCase;

/**
 * QR 臂测试（平移 go qr_test.go）：QR 臂零专属代码——endroid PNG 经 Resolver
 * 物化 → drawImage 整图哨兵直嵌。测试用角点暗像素簇计数 + 暗像素占比区间，
 * 不承诺字节 parity（反例域 = endroid 产物字节面；跨端一致性锚定在选项级 +
 * cover 铺放）。
 */
class PdfQrTest extends PopplerPdfCase
{
    public function testQrCodeLayerRendersDarkFinderCorners(): void
    {
        // QR 图层管线渲染：三个 finder pattern 角区含暗像素簇、整体暗像素占比
        // 落 QR 典型区间（码图内切于内容盒，ADR 0015）。块量化缩放（endroid 出图
        // 尺寸量化后 cover 拉伸）使单点采样对齐敏感，故用角区计数 + 占比区间
        $layer = QrCodeLayer::make(60, 60)
            ->setPosition(0, 0)
            ->setText('php-canvas');
        $grid = self::rasterize(self::renderCanvas(Canvas::make(60, 60, $layer)), 60, 60);

        $darkIn = static function (int $x0, int $y0, int $x1, int $y1) use ($grid): int {
            $n = 0;
            for ($y = $y0; $y < $y1; $y++) {
                for ($x = $x0; $x < $x1; $x++) {
                    [$r, $g, $b] = $grid->pixelAt($x, $y);
                    if ($r < 100 && $g < 100 && $b < 100) {
                        $n++;
                    }
                }
            }

            return $n;
        };

        // finder 角区(12×12)：至少 1/3 暗像素（finder 外环 + 中心块的主体）
        foreach ([[0, 0, 12, 12], [48, 0, 60, 12], [0, 48, 12, 60]] as [$x0, $y0, $x1, $y1]) {
            self::assertGreaterThanOrEqual(48, $darkIn($x0, $y0, $x1, $y1), "QR finder 角区 ({$x0},{$y0})-({$x1},{$y1}) 暗像素不足");
        }

        // 整体占比：QR 码典型 ~50%，容 [15%, 70%]（纠错级别与内容相关）
        $total = $darkIn(0, 0, 60, 60);
        self::assertTrue(
            $total >= 60 * 60 * 15 / 100 && $total <= 60 * 60 * 70 / 100,
            "QR 暗像素占比 = {$total}/" . (60 * 60) . ', 超出 QR 典型区间'
        );
    }
}
