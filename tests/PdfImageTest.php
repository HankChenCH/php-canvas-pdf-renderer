<?php

declare(strict_types=1);

namespace HankChen\CanvasNext\Renderer\Pdf\Tests;

use HankChen\CanvasNext\Layer\ImageLayer;
use HankChen\CanvasNext\Renderer\Pdf\Tests\Support\BarePdfRenderer;
use HankChen\CanvasNext\Renderer\Pdf\Tests\Support\Fixtures;
use HankChen\CanvasNext\Renderer\Pdf\Tests\Support\PopplerPdfCase;

/**
 * 图片原语测试：PNG/JPEG 魔数嗅探一等直嵌（零重编码）、cover 裁窗、contain、
 * fill、tile 网格与截断（同名源按名去重的对象级断言）、GIF 拒收（与 go 镜像的
 * 显式偏差）、空 dstRect 防护、不相交源窗静默、垃圾字节报错、EXIF 八向放置矩阵
 * （管线级）。经标准管线驱动（模板分派 fit 几何——观察方向系 srcRect），防线组
 * 经 BarePdfRenderer 直驱。poppler 栅格化后实心区内部点采样。
 */
class PdfImageTest extends PopplerPdfCase
{
    public function testDrawImagePngDirectEmbedFill(): void
    {
        // PNG 整图 fill：源红蓝两半 → 目标盒同比例映回（采样避开分界）
        $src = Fixtures::twoColorPng(20, 10, self::RED, self::BLUE, 10);
        $layer = ImageLayer::make(20, 10)->setImage($src)->setFit(ImageLayer::FIT_FILL);
        $grid = self::rasterize(self::renderLayer($layer), 20, 10);
        self::assertPixelSame(self::RED, $grid, 4, 5);
        self::assertPixelSame(self::BLUE, $grid, 15, 5);
    }

    public function testDrawImageJpegDirectEmbedFill(): void
    {
        // JPEG 直嵌：有损编码用容差取样
        Fixtures::requireGd();
        $src = Fixtures::solidJpeg(20, 10, self::GREEN);
        $layer = ImageLayer::make(20, 10)->setImage($src)->setFit(ImageLayer::FIT_FILL);
        $grid = self::rasterize(self::renderLayer($layer), 20, 10);
        self::assertPixelNear(self::GREEN, $grid, 10, 5, 24);
    }

    public function testDrawImageCoverLeftRightHalves(): void
    {
        // cover 裁窗：40×10 双色图 cover 进 20×20 盒 → 裁中缝左右各半
        //（与 go TestDrawImageCoverLeftRightHalves / 位图后端同几何）
        $src = Fixtures::twoColorPng(40, 10, self::RED, self::BLUE, 20);
        $layer = ImageLayer::make(20, 20, '#ffffff')->setImage($src)->setFit(ImageLayer::FIT_COVER);
        $grid = self::rasterize(self::renderLayer($layer), 20, 20);
        self::assertPixelSame(self::RED, $grid, 5, 10);
        self::assertPixelSame(self::BLUE, $grid, 15, 10);
    }

    public function testDrawImageContainLetterbox(): void
    {
        // contain（ADR 0023）：40×10 图按比例收缩进 20×20 盒 → 上下留白露背景
        $src = Fixtures::twoColorPng(40, 10, self::RED, self::BLUE, 20);
        $layer = ImageLayer::make(20, 20, '#ffffff')->setImage($src)->setFit(ImageLayer::FIT_CONTAIN);
        $grid = self::rasterize(self::renderLayer($layer), 20, 20);
        // 收缩后图像带内取点；上下留白为背景白
        self::assertPixelSame(self::WHITE, $grid, 3, 2);
        self::assertPixelSame(self::WHITE, $grid, 17, 18);
        self::assertPixelSame(self::RED, $grid, 5, 10);
        self::assertPixelSame(self::BLUE, $grid, 15, 10);
    }

    public function testDrawImageTileGridAndTruncation(): void
    {
        // tile：20×10 源平铺 50×30 盒，默认居中对齐 → 网格起点 = 对齐锚点
        // ((50-20)/2, (30-10)/2) = (15,10)；列 1 整块(x 15..35)、列 2 截断块
        // (x 35..50,15px 宽)；行 1(y 10..20)、行 2(y 20..30)；网格未覆盖区露背景白。
        // 同一 $src 路径跨块直嵌——对象级断言：文档只嵌一份图片 XObject（按名去重）
        $src = Fixtures::solidPng(20, 10, self::RED);
        $layer = ImageLayer::make(50, 30, '#ffffff')->setImage($src)->setFit(ImageLayer::FIT_TILE);
        $pdf = self::renderLayer($layer);
        $grid = self::rasterize($pdf, 50, 30);
        // 块内部与截断块、右下缘：红
        foreach ([[25, 15], [25, 25], [45, 15], [45, 25], [49, 29]] as [$x, $y]) {
            self::assertPixelSame(self::RED, $grid, $x, $y);
        }
        // 网格未覆盖区(锚点左侧/上侧)：背景白
        self::assertPixelSame(self::WHITE, $grid, 5, 5);
        self::assertPixelSame(self::WHITE, $grid, 25, 5);
        self::assertPixelSame(self::WHITE, $grid, 5, 25);
        // 按名去重：同一源只注册一份图片 XObject（对象字典不压缩，字节面可数）
        self::assertSame(1, substr_count($pdf, '/Subtype /Image'), 'tile 多块引用同一源应只嵌一份图片对象');
    }

    public function testDrawImageGifRejected(): void
    {
        // GIF 拒收（与 go 镜像的显式偏差，偏差已声明包 AGENTS.md）：tFPDF _parsegif
        // 走 GD 违零 GD 红线——GIF 魔数在嗅探层即报错中止，绝不进 tFPDF
        // （go 的 GIF 直嵌是纯 Go 解码红利，不可平移；parity 不适用此格式）
        $r = new BarePdfRenderer();
        $r->begin(10, 10);
        $gif = "GIF89a\x01\x00\x01\x00\x80\x00\x00\xFF\xFF\xFF\xFF\x00\x00\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x4C\x01\x00\x3B";
        $path = sys_get_temp_dir() . '/canvas-pdf-fixture-' . uniqid() . '.gif';
        file_put_contents($path, $gif);
        try {
            $r->drawImage($path, 0, 0, 0, 0, 0, 0, 10, 10);
            self::fail('GIF 应被拒收报错');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('GIF', $e->getMessage(), '拒收信息应点名 GIF');
        }
    }

    public function testDrawImageEmptyDstRectSkipped(): void
    {
        // dstRect 空矩形防护不画（原语级，dw/dh ≤ 0）：渲染成功且**零墨迹**——
        // 防护缺席时会以零缩放矩阵发射 Image（结果不可见但发射面在），本断言
        // 钉的是 docblock「防护不画」的结果契约
        $r = new BarePdfRenderer();
        $r->begin(10, 10);
        $src = Fixtures::solidPng(4, 4, self::RED);
        $r->drawImage($src, 0, 0, 0, 0, 0, 0, 0, 0);
        $pdf = $r->end();
        self::assertNotSame('', $pdf);
        $grid = self::rasterize($pdf, 10, 10);
        self::assertFalse(self::boxHasInk($grid, [0, 0, 10, 10], 128), '空 dstRect 应零墨迹（防护不画）');
    }

    public function testDrawImageSrcRectOutsideSilent(): void
    {
        // 源窗与解码图不相交：静默不画（分派层几何保证不发生，wire 可注入）
        $r = new BarePdfRenderer();
        $r->begin(10, 10);
        $src = Fixtures::solidPng(8, 8, self::RED);
        $r->drawImage($src, 100, 100, 100, 100, 0, 0, 5, 5);
        self::assertNotSame('', $r->end(), '静默路径也应有产物');
    }

    public function testDrawImageGarbageAborts(): void
    {
        // 垃圾字节报错中止（嗅探防线）。parity 措辞：与位图后端的 parity 适用域 =
        // 「不可解码字节必须报错中止、不得静默出图」这一失败结局，两后端防线对称；
        // 反例域 = 错误文案与异常类型——两后端各出各的普通异常，不在契约内
        $r = new BarePdfRenderer();
        $r->begin(10, 10);
        $path = Fixtures::solidPng(2, 2, self::RED);
        file_put_contents($path, 'not an image');
        try {
            $r->drawImage($path, 0, 0, 0, 0, 0, 0, 5, 5);
            self::fail('非法图片字节应报错');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testDrawImageExifOrientationRotated(): void
    {
        // EXIF 放置矩阵（管线级）：8×16 上红下蓝源图、orientation 6（顺时针 90°）
        // → 观察方向 16×8 左蓝右红；整图哨兵 blit 进观察方向同尺寸盒。
        // 分派层 intrinsicSize 按观察方向给 srcRect，后端 T⁻¹ 逆映射回内在窗再放置矩阵归正
        Fixtures::requireGd();
        $src = Fixtures::twoBandExifJpeg(6);
        $layer = ImageLayer::make(16, 8, '#ffffff')->setImage($src);
        $grid = self::rasterize(self::renderLayer($layer), 16, 8);
        // JPEG 有损 + 色度抽样，取远离分界的点做容差取样
        self::assertPixelNear(self::BLUE, $grid, 1, 4, 60);
        self::assertPixelNear(self::RED, $grid, 14, 4, 60);
    }

    public function testDrawImageExifOrientationAllEight(): void
    {
        // 八向标定（JPEG 载体，poppler 渲染容差取样；与 go TestDrawImageExifOrientation
        // AllEight 同一期望表——期望色序按 orientAffine 的观察方向映射逐向手算，
        // 与位图后端栅格标定同源）：8×16 上红下蓝源图，90° 族转置后观察方向 16×8
        // 左右分色，镜像/180° 族仍 8×16 上下分色
        Fixtures::requireGd();
        $cases = [
            // [orientation, dstW, dstH, 观察方向分界是否竖线(左右分色), first, second]
            [1, 8, 16, false, self::RED, self::BLUE], // 恒等：无 EXIF 变换，上下带原样
            [6, 16, 8, true, self::BLUE, self::RED],  // 顺时针 90°：下带(蓝)转到左
            [8, 16, 8, true, self::RED, self::BLUE],  // 逆时针 90°：上带(红)转到左
            [5, 16, 8, true, self::RED, self::BLUE],  // 转置：上行(红)到左
            [7, 16, 8, true, self::BLUE, self::RED],  // 反转置：下行(蓝)到左
            [3, 8, 16, false, self::BLUE, self::RED], // 180°：上蓝下红(带序翻转)
            [2, 8, 16, false, self::RED, self::BLUE], // 水平镜像：上下带不变
            [4, 8, 16, false, self::BLUE, self::RED], // 垂直镜像：下带(蓝)翻上、红落底
        ];
        foreach ($cases as [$orientation, $dstW, $dstH, $splitVertical, $first, $second]) {
            $src = Fixtures::twoBandExifJpeg($orientation);
            $layer = ImageLayer::make($dstW, $dstH, '#ffffff')->setImage($src)->setFit(ImageLayer::FIT_FILL);
            $grid = self::rasterize(self::renderLayer($layer), $dstW, $dstH);
            if ($splitVertical) {
                self::assertPixelNear($first, $grid, 1, 4, 60);
                self::assertPixelNear($second, $grid, $dstW - 2, 4, 60);
            } else {
                self::assertPixelNear($first, $grid, 4, 1, 60);
                self::assertPixelNear($second, $grid, 4, $dstH - 2, 60);
            }
        }
    }
}
