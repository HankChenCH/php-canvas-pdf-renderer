<?php

declare(strict_types=1);

namespace HankChen\CanvasNext\Renderer\Pdf\Tests;

use HankChen\CanvasNext\Renderer\Pdf\Tests\Support\BarePdfRenderer;
use HankChen\CanvasNext\Renderer\Pdf\Tests\Support\Fixtures;
use HankChen\CanvasNext\Renderer\Pdf\Tests\Support\PopplerPdfCase;

/**
 * 椭圆绘制原语 + 掩码 blit 原语测试（ADR 0026 形状能力口，镜像 go clip_test.go
 * 的原语级段）：fill("F")/stroke("D")/fill+stroke 一发("FD") 三风格、非正尺寸
 * 与 Width≤0 防线、掩码 = 内切于 maskRect 的椭圆、dst/mask 空矩形防护不画。
 * 描边带宽为驱动原生的缘上居中带 [r-w/2, r+w/2]——与位图后端外缘内缩带的环位
 * 分歧由采样验收取公共带吸收（采样半径 r - w/4）。
 */
class PdfShapeTest extends PopplerPdfCase
{
    public function testDrawEllipseFillStrokeAndCombinedStyles(): void
    {
        // 原语级三风格：fill 单独("F")/ stroke 单独("D")/ 填充加描边一发("FD")；
        // 白底画布直驱后端，描边为缘上居中带。防线腿：非正尺寸与 Width≤0 静默不画
        $paintWhiteBg = static function (BarePdfRenderer $r): void {
            $r->drawRect(0, 0, 40, 40, '#ffffff', []);
        };

        // fill
        $r = new BarePdfRenderer();
        $r->begin(40, 40);
        $paintWhiteBg($r);
        $r->drawEllipse(10, 10, 20, 20, '#ff0000', null);
        $grid = self::rasterize($r->end(), 40, 40);
        self::assertPixelSame(self::RED, $grid, 20, 20);
        // 外接矩形角落椭圆外（距缘 ≥2px 留矢量过渡带余量）：白底不被波及
        self::assertPixelSame(self::WHITE, $grid, 11, 11);

        // stroke
        $r = new BarePdfRenderer();
        $r->begin(40, 40);
        $paintWhiteBg($r);
        $r->drawEllipse(10, 10, 20, 20, null, ['width' => 4, 'color' => '#0000ff']);
        $grid = self::rasterize($r->end(), 40, 40);
        // 居中带 [8,12]，采样半径 10 距带缘 2px 容差取样
        self::assertPixelNear(self::BLUE, $grid, 20, 10, 8);
        self::assertPixelNear(self::BLUE, $grid, 20, 30, 8);
        self::assertPixelNear(self::BLUE, $grid, 10, 20, 8);
        self::assertPixelNear(self::BLUE, $grid, 30, 20, 8);
        // 环内未被填充波及：白
        self::assertPixelSame(self::WHITE, $grid, 20, 20);

        // fill and stroke（双非空一发 "FD"）
        $r = new BarePdfRenderer();
        $r->begin(40, 40);
        $paintWhiteBg($r);
        $r->drawEllipse(10, 10, 20, 20, '#ff0000', ['width' => 4, 'color' => '#0000ff']);
        $grid = self::rasterize($r->end(), 40, 40);
        self::assertPixelSame(self::RED, $grid, 20, 20);
        self::assertPixelNear(self::BLUE, $grid, 20, 10, 8);
    }

    public function testDrawEllipseGuards(): void
    {
        // 非正外接矩形与 Width≤0 描边：静默不画，渲染成功
        $r = new BarePdfRenderer();
        $r->begin(10, 10);
        $r->drawEllipse(0, 0, 0, 10, '#ff0000', null);
        $r->drawEllipse(0, 0, 10, -1, null, ['width' => 2, 'color' => '#0000ff']);
        $r->drawEllipse(0, 0, 10, 10, null, ['width' => 0, 'color' => '#0000ff']);
        $pdf = $r->end();
        self::assertNotSame('', $pdf);
    }

    public function testDrawImageMaskedInscribedEllipseClip(): void
    {
        // 原语级掩码 = 椭圆内切于给定矩形（非方 maskRect 即椭圆，分派层传正方形才圆）：
        // 40×30 掩码 center (20,15) rx=20 ry=15——中心与轴上近缘点露图，角落掩码外
        // 不画。三级取样：精确点 + 墨迹粗判（掩码外区域无墨）
        $r = new BarePdfRenderer();
        $r->begin(40, 30);
        $src = Fixtures::solidPng(40, 30, self::RED);
        $r->drawImageMasked($src, 0, 0, 0, 0, 0, 0, 40, 30, 0, 0, 40, 30);
        $grid = self::rasterize($r->end(), 40, 30);

        self::assertPixelSame(self::RED, $grid, 20, 15);
        self::assertPixelSame(self::RED, $grid, 20, 3);
        self::assertPixelSame(self::RED, $grid, 4, 15);
        // 掩码外不画：页底白（精确点 + 墨迹粗判双证）。墨迹盒取左上角 [0,0,3,3]——
        // 盒内最靠近圆心的点 (3,3) 归一距离 (17/20)²+(12/15)² ≈ 1.36 > 1，整盒在
        // 内切椭圆外（右下角 [33,22,40,30] 含椭圆内点，不可作掩码外采样盒）
        self::assertPixelSame(self::WHITE, $grid, 2, 2);
        self::assertPixelSame(self::WHITE, $grid, 37, 27);
        self::assertBoxInk($grid, [0, 0, 3, 3], 200, false, '掩码外左上角');
        self::assertBoxInk($grid, [17, 12, 23, 18], 200, true, '掩码内中心区');
    }

    public function testDrawImageMaskedEmptyRectGuards(): void
    {
        // dstRect/maskRect 空矩形防护不画（原语级，位图后端同款）：渲染成功
        $r = new BarePdfRenderer();
        $r->begin(10, 10);
        $src = Fixtures::solidPng(4, 4, self::RED);
        $r->drawImageMasked($src, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 5, 5);
        $r->drawImageMasked($src, 0, 0, 0, 0, 0, 0, 5, 5, 0, 0, 0, 0);
        self::assertNotSame('', $r->end());
    }
}
