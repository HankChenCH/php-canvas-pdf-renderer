<?php

declare(strict_types=1);

namespace HankChen\CanvasNext\Renderer\Pdf\Tests;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Layer\ImageLayer;
use HankChen\CanvasNext\Renderer\Pdf\Tests\Support\Fixtures;
use HankChen\CanvasNext\Renderer\Pdf\Tests\Support\PopplerPdfCase;

/**
 * 裁剪形状圆形档 poppler 点位采样（ADR 0026，与位图腿 ImageClipRenderingTest /
 * go clip_test.go 同一矩阵）：掩码 blit 走 ClipEllipse 矢量裁剪路径（直嵌零重编码
 * 与放置矩阵全复用），椭圆经 tFPDF 原生 Ellipse 绘制。
 * 位图/矢量等价域 = 单页放置面（采样差异不在契约内）；圆缘采样点自缘内缩 ≥3px
 * 留矢量抗锯齿过渡带余量。描边带采样取居中带与内缩带公共区：tFPDF 描边为缘上
 * 居中带 [r-w/2, r+w/2]（驱动原生行为，与位图后端内缩带 [r-w, r] 的分歧由采样
 * 半径 r-w/4 吸收），经公开渲染管线（模板 → PDF 后端）驱动。
 */
class PdfClipTest extends PopplerPdfCase
{
    public function testClipCircleBackgroundFillsInscribedCircleCornersRevealLowerLayer(): void
    {
        // 背景绿填进内切圆；四角在圆外露出垫底红层（整层渲染面裁进圆）
        $lower = ImageLayer::make(100, 100, '#f00')->setPriority(10);
        $circle = ImageLayer::make(100, 100, '#0f0')->setClip(ImageLayer::CLIP_CIRCLE)->setPriority(1);

        $grid = self::rasterize(self::renderCanvas(Canvas::make(100, 100, $lower, $circle)), 100, 100);

        self::assertPixelSame(self::RED, $grid, 2, 2);
        self::assertPixelSame(self::RED, $grid, 97, 2);
        self::assertPixelSame(self::RED, $grid, 2, 97);
        self::assertPixelSame(self::RED, $grid, 97, 97);
        self::assertPixelSame(self::GREEN, $grid, 50, 50);
        // 圆内四向中点(r=50,采样点距缘 ≥15px 留矢量过渡带余量)
        self::assertPixelSame(self::GREEN, $grid, 15, 50);
        self::assertPixelSame(self::GREEN, $grid, 85, 50);
        self::assertPixelSame(self::GREEN, $grid, 50, 15);
        self::assertPixelSame(self::GREEN, $grid, 50, 85);
    }

    public function testNonSquareBoxKeepsPerfectCircleWithTransparentFlanks(): void
    {
        // 非方盒 100×50：内切圆 center (50,25) r=25,左右露出下层、不变形为椭圆
        $lower = ImageLayer::make(100, 50, '#f00')->setPriority(10);
        $circle = ImageLayer::make(100, 50, '#ff0')->setClip(ImageLayer::CLIP_CIRCLE)->setPriority(1);

        $grid = self::rasterize(self::renderCanvas(Canvas::make(100, 50, $lower, $circle)), 100, 50);

        self::assertPixelSame(self::YELLOW, $grid, 50, 25);
        self::assertPixelSame(self::YELLOW, $grid, 32, 25);
        self::assertPixelSame(self::YELLOW, $grid, 50, 10);
        self::assertPixelSame(self::RED, $grid, 10, 25);
        self::assertPixelSame(self::RED, $grid, 90, 25);
        self::assertPixelSame(self::RED, $grid, 2, 2);
    }

    public function testClipCircleImageMaskedIntoCircleCoverFit(): void
    {
        // cover(缺省):方图进方盒整图绘出后遮罩进圆——圆内露图、圆外露下层
        $src = Fixtures::bandPng(100, 100, self::RED, self::BLUE);
        $lower = ImageLayer::make(100, 100, '#0f0')->setPriority(10);
        $circle = ImageLayer::make(100, 100)->setImage($src)->setClip(ImageLayer::CLIP_CIRCLE)->setPriority(1);

        $grid = self::rasterize(self::renderCanvas(Canvas::make(100, 100, $lower, $circle)), 100, 100);

        self::assertPixelSame(self::RED, $grid, 50, 25);
        self::assertPixelSame(self::BLUE, $grid, 50, 75);
        self::assertPixelSame(self::GREEN, $grid, 2, 2);
        self::assertPixelSame(self::GREEN, $grid, 97, 97);
    }

    public function testClipCircleBorderStrokesRingAlongCircumference(): void
    {
        // 环带描边:padding 12 → 内切圆 side 76 center (50,50) r=38,border 宽 8 蓝——
        // tFPDF 居中带 [34,42],采样半径 ≈36(r - w/4)与位图内缩带 [30,38] 取公共区
        // 跨后端同矩阵;环内露图、环外与四角露下层红(padding 即环外留白)
        $src = Fixtures::solidPng(40, 40, [0x12, 0x34, 0x56]);
        $lower = ImageLayer::make(100, 100, '#f00')->setPriority(10);
        $circle = ImageLayer::make(100, 100, '#ffffff')
            ->setImage($src)
            ->setPadding(12)
            ->setBorder(8, '#0000ff')
            ->setClip(ImageLayer::CLIP_CIRCLE)
            ->setPriority(1);

        $grid = self::rasterize(self::renderCanvas(Canvas::make(100, 100, $lower, $circle)), 100, 100);

        // 采样点半径 36 距居中带内缘 2px,矢量抗锯齿下用容差取样
        self::assertPixelNear(self::BLUE, $grid, 14, 50, 8);
        self::assertPixelNear(self::BLUE, $grid, 86, 50, 8);
        self::assertPixelNear(self::BLUE, $grid, 50, 14, 8);
        self::assertPixelNear(self::BLUE, $grid, 50, 86, 8);
        // 环内被图覆盖:圆心 = 源图色(先背景填充后内容绘出,图遮住同圆的背景填充)
        self::assertPixelSame([0x12, 0x34, 0x56], $grid, 50, 50);
        self::assertPixelSame(self::RED, $grid, 2, 2);
        self::assertPixelSame(self::RED, $grid, 50, 5);
    }

    public function testClipCircleWithFillFitStretchThenMask(): void
    {
        // fill:40×40 红图非等比拉伸进内容盒 100×50 后遮罩进内切圆 (25,0)-(75,50)
        $src = Fixtures::solidPng(40, 40, self::RED);
        $lower = ImageLayer::make(100, 50, '#0f0')->setPriority(10);
        $circle = ImageLayer::make(100, 50)
            ->setImage($src)
            ->setFit(ImageLayer::FIT_FILL)
            ->setClip(ImageLayer::CLIP_CIRCLE)
            ->setPriority(1);

        $grid = self::rasterize(self::renderCanvas(Canvas::make(100, 50, $lower, $circle)), 100, 50);

        self::assertPixelSame(self::RED, $grid, 50, 25);
        self::assertPixelSame(self::RED, $grid, 35, 25);
        self::assertPixelSame(self::GREEN, $grid, 10, 25);
    }

    public function testClipCircleWithContainFitGapInsideCircleStaysLowerLayer(): void
    {
        // contain:40×10 上下两色图进 100×100 盒收缩 100×25(y=37 起,半带界 y≈50);
        // 圆内但收缩矩形外的空隙露下层——先绘后裁不改变 contain 几何
        $src = Fixtures::bandPng(40, 10, self::RED, self::BLUE);
        $lower = ImageLayer::make(100, 100, '#0f0')->setPriority(10);
        $circle = ImageLayer::make(100, 100)
            ->setImage($src)
            ->setFit(ImageLayer::FIT_CONTAIN)
            ->setClip(ImageLayer::CLIP_CIRCLE)
            ->setPriority(1);

        $grid = self::rasterize(self::renderCanvas(Canvas::make(100, 100, $lower, $circle)), 100, 100);

        self::assertPixelSame(self::RED, $grid, 30, 40);
        self::assertPixelSame(self::BLUE, $grid, 30, 55);
        // 圆内(dist 40 < 50)但 y<37 收缩矩形外:露下层
        self::assertPixelSame(self::GREEN, $grid, 50, 10);
        // 圆外:露下层
        self::assertPixelSame(self::GREEN, $grid, 2, 2);
    }

    public function testClipCircleWithTileFitBlocksOutsideCircleMaskedAway(): void
    {
        // tile:40×40 单元 center 锚定 (30,30),可见块铺满 [30,100)²——
        // 圆内块区露图、圆内锚点未覆盖区(x<30)与圆外露下层
        $src = Fixtures::solidPng(40, 40, self::RED);
        $lower = ImageLayer::make(100, 100, '#0f0')->setPriority(10);
        $circle = ImageLayer::make(100, 100)
            ->setImage($src)
            ->setFit(ImageLayer::FIT_TILE)
            ->setClip(ImageLayer::CLIP_CIRCLE)
            ->setPriority(1);

        $grid = self::rasterize(self::renderCanvas(Canvas::make(100, 100, $lower, $circle)), 100, 100);

        self::assertPixelSame(self::RED, $grid, 35, 35);
        self::assertPixelSame(self::RED, $grid, 50, 50);
        self::assertPixelSame(self::GREEN, $grid, 15, 50);
        self::assertPixelSame(self::GREEN, $grid, 5, 5);
    }

    public function testUnknownClipValueRendersRectangleWithoutMask(): void
    {
        // 未知 clip 值渲染面按矩形降级(wire 透传保存):整盒铺图无遮罩、四角是图
        $src = Fixtures::bandPng(100, 100, self::RED, self::BLUE);
        $graph = ImageLayer::make(100, 100)->setImage($src)->graph();
        $graph['spec']['clip'] = 'star';
        $circle = ImageLayer::fromGraph($graph);

        $grid = self::rasterize(self::renderLayer($circle), 100, 100);

        self::assertPixelSame(self::RED, $grid, 2, 2);
        self::assertPixelSame(self::BLUE, $grid, 97, 97);
        self::assertPixelSame(self::RED, $grid, 50, 25);
    }
}
