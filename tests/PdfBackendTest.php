<?php

declare(strict_types=1);

namespace HankChen\CanvasNext\Renderer\Pdf\Tests;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\CanvasBackground;
use HankChen\CanvasNext\Layer\ImageLayer;
use HankChen\CanvasNext\Renderer\Pdf\Tests\Support\BarePdfRenderer;
use HankChen\CanvasNext\Renderer\Pdf\Tests\Support\PopplerPdfCase;

/**
 * 会话与矩形原语测试：pdfinfo 页数/页尺寸（px × 0.75 pt）、Begin 文档续页、
 * End 一次性复位（复用三轮锁）、尺寸/未建面防线、背景色中心点、四边异色边框
 * 色带内侧采样与绘制序、画布背景先色后图。管线级经标准管线（模板 → 后端），
 * 防线级经 BarePdfRenderer 直驱（镜像 go backend_test.go 全目）。
 */
class PdfBackendTest extends PopplerPdfCase
{
    public function testRenderStructureSinglePagePxToPt(): void
    {
        // 空画布：pdfinfo 页数 1、页尺寸 = px × 0.75 pt（200×100 px → 150×75 pt，
        // 横向宽页——tFPDF 竖版构造会交换 w/h，后端必须走 'L' 装载，本例即其验收面）
        $pdf = self::renderCanvas(Canvas::make(200, 100));
        self::assertPdfStructure($pdf, 1, [[150.0, 75.0]]);
    }

    public function testBeginContinuationPagesWithOwnSizes(): void
    {
        // Begin 文档续页语义：首次建档建首页，文档已在则按本页尺寸续页；
        // 两页异尺寸（横→竖）各按自己的 px × 0.75 落 MediaBox
        $r = new BarePdfRenderer();
        $r->begin(200, 100);
        $r->drawRect(0, 0, 200, 100, '#0000ff', []);
        $r->begin(60, 80);
        $r->drawRect(0, 0, 60, 80, '#ff0000', []);
        $pdf = $r->end();

        self::assertPdfStructure($pdf, 2, [[150.0, 75.0], [45.0, 60.0]]);
        $page1 = self::rasterize($pdf, 200, 100, 1);
        self::assertPixelSame(self::BLUE, $page1, 10, 5);
        $page2 = self::rasterize($pdf, 60, 80, 2);
        self::assertPixelSame(self::RED, $page2, 30, 40);
    }

    public function testRendererReuseAfterEndStartsFreshDocument(): void
    {
        // End 一次性复位会话（锁定测试）：同一实例三轮渲染，每轮必须干净新文档——
        // 复位失效时第二次渲染会把页面追加进旧文档（页数累加、尺寸串页）
        $r = new BarePdfRenderer();
        foreach ([[200, 100, self::BLUE], [80, 60, self::RED], [40, 40, self::GREEN]] as $i => [$w, $h, $color]) {
            $r->begin($w, $h);
            $r->drawRect(0, 0, $w, $h, sprintf('#%02x%02x%02x', ...$color), []);
            $pdf = $r->end();
            self::assertPdfStructure($pdf, 1, [[$w * 0.75, $h * 0.75]]);
            $grid = self::rasterize($pdf, $w, $h);
            self::assertPixelSame($color, $grid, intdiv($w, 2), intdiv($h, 2));
        }
    }

    public function testBeginInvalidSizeFails(): void
    {
        // 尺寸防线：非正尺寸报错，渲染面不建立
        $r = new BarePdfRenderer();
        try {
            $r->begin(0, 10);
            self::fail('Begin(0, 10) 应报错');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        try {
            $r->begin(10, -1);
            self::fail('Begin(10, -1) 应报错');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testDrawPrimitiveBeforeBeginFails(): void
    {
        // 用法错误防线：未 begin 即绘制原语报错（模板保证先 begin，防御直调）；
        // 未 begin 的 end 产物 = 空串（go nil 接口对位物）
        $r = new BarePdfRenderer();
        foreach ([
            'drawRect' => fn () => $r->drawRect(0, 0, 1, 1, null, []),
            'drawImage' => fn () => $r->drawImage('/dev/null', 0, 0, 0, 0, 0, 0, 1, 1),
            'drawText' => fn () => $r->drawText('x', 0, 0, '', 12, '#000', 'left', 'top', 0),
            'drawEllipse' => fn () => $r->drawEllipse(0, 0, 1, 1, '#f00', null),
            'drawImageMasked' => fn () => $r->drawImageMasked('/dev/null', 0, 0, 0, 0, 0, 0, 1, 1, 0, 0, 1, 1),
        ] as $name => $call) {
            try {
                $call();
                self::fail("未 begin 调 {$name} 应报错");
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame('', $r->end(), '未 begin 的 end 产物应为空串');
    }

    public function testDrawRectBackgroundCenterSample(): void
    {
        // 背景填充：实心区中心点精确取样
        $layer = ImageLayer::make(20, 10, '#ff0000');
        $grid = self::rasterize(self::renderLayer($layer), 20, 10);
        self::assertPixelSame(self::RED, $grid, 10, 5);
    }

    public function testDrawRectBorderBandsInnerSample(): void
    {
        // 四边异色边框：每边色带**内侧**采样（线宽 4，取第 2 行/列，避开矢量
        // 抗锯齿边缘）；色带序 top/bottom/left/right 后画覆盖先画由异色互证——
        // 角部 (1,1)/(1,18) 属 left 色带（后画）应红，钉死 left/right 晚于 top/bottom
        $layer = ImageLayer::make(30, 20, '#ffffff')
            ->setBorderTop(4, '#0000ff')
            ->setBorderBottom(4, '#0000ff')
            ->setBorderLeft(4, '#ff0000')
            ->setBorderRight(4, '#ff0000');
        $grid = self::rasterize(self::renderLayer($layer), 30, 20);

        // 白底中心
        self::assertPixelSame(self::WHITE, $grid, 15, 10);
        // top 色带内侧(y=1)与 bottom 色带内侧(y=18)：蓝
        self::assertPixelNear(self::BLUE, $grid, 15, 1, 8);
        self::assertPixelNear(self::BLUE, $grid, 15, 18, 8);
        // left 色带内侧(x=1, 行中段避开角部覆盖序)与 right 色带内侧(x=28)：红
        self::assertPixelNear(self::RED, $grid, 1, 10, 8);
        self::assertPixelNear(self::RED, $grid, 28, 10, 8);
        // 绘制序角部互证：left/right 后画，覆盖 top/bottom（若序颠倒则为蓝）
        self::assertPixelNear(self::RED, $grid, 1, 1, 8);
        self::assertPixelNear(self::RED, $grid, 1, 18, 8);
    }

    public function testDrawRectNilAndEmptyBackgroundSkipped(): void
    {
        // nil 背景（不传背景）跳过填充：渲染成功、结构合法
        $layer = ImageLayer::make(8, 8);
        $pdf = self::renderLayer($layer);
        self::assertPdfStructure($pdf, 1, [[6.0, 6.0]]);
    }

    public function testCanvasBackgroundPaintedFirst(): void
    {
        // 画布背景铺装（ADR 0022）：先色后图层——色块被后绘图层盒覆盖
        $layer = ImageLayer::make(10, 10, '#ffffff')->setPosition(5, 5);
        $canvas = Canvas::make(20, 20, $layer);
        $canvas->setBackground(CanvasBackground::make('#ff0000'));

        $grid = self::rasterize(self::renderCanvas($canvas), 20, 20);
        self::assertPixelSame(self::RED, $grid, 2, 2);
        self::assertPixelSame(self::WHITE, $grid, 10, 10);
    }
}
