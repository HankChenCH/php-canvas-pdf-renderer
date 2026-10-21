<?php

declare(strict_types=1);

namespace HankChen\CanvasNext\Renderer\Pdf\Tests;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Layer\TextLayer;
use HankChen\CanvasNext\Renderer\Pdf\Tests\Support\BarePdfRenderer;
use HankChen\CanvasNext\Renderer\Pdf\Tests\Support\PpmGrid;
use HankChen\CanvasNext\Renderer\Pdf\Tests\Support\PopplerPdfCase;

/**
 * 文本原语测试（平移 go-canvas/pdf-renderer text_test.go 全目）：空行守卫、
 * Helvetica 兜底、对齐锚点（实测宽度水平对齐 + metrics 垂直基线）、角度旋转
 * （poppler 栅格实测标定）、27 组合交叉积单页网格、TTF 子集内嵌（CJK 管线 +
 * pdffonts 子集标记按 spike 实测形态）、TTC/OTTO/垃圾字体可读拒收、End 后复用
 * CJK 字体重注册与 CJK 子集体积断言（tFPDF 特有新增）。
 * 墨迹断言为相对关系（锚点方位/跨距变化），不依赖具体字体度量数值。
 */
class PdfTextTest extends PopplerPdfCase
{
    /** ==================== 助手 ==================== */

    /**
     * 区域限定的非白墨迹包围盒（阈值 128：任一通道暗于阈值即墨，镜像 go inkBoxIn）；
     * 无墨返回 null。返回 [minX, minY, maxX, maxY]
     *
     * @return array{0: int, 1: int, 2: int, 3: int}|null
     */
    private static function inkBoxIn(PpmGrid $grid, int $x0, int $y0, int $x1, int $y1): ?array
    {
        $x0 = max($x0, 0);
        $y0 = max($y0, 0);
        $x1 = min($x1, $grid->width);
        $y1 = min($y1, $grid->height);
        $minX = $x1;
        $minY = $y1;
        $maxX = $x0 - 1;
        $maxY = $y0 - 1;
        $hasInk = false;
        for ($y = $y0; $y < $y1; $y++) {
            for ($x = $x0; $x < $x1; $x++) {
                [$r, $g, $b] = $grid->pixelAt($x, $y);
                if ($r < 128 || $g < 128 || $b < 128) {
                    $hasInk = true;
                    $minX = min($minX, $x);
                    $minY = min($minY, $y);
                    $maxX = max($maxX, $x);
                    $maxY = max($maxY, $y);
                }
            }
        }

        return $hasInk ? [$minX, $minY, $maxX, $maxY] : null;
    }

    /** 整幅非白墨迹包围盒；无墨返回 null */
    private static function inkBox(PpmGrid $grid): ?array
    {
        return self::inkBoxIn($grid, 0, 0, $grid->width, $grid->height);
    }

    /** 建面 width×height 直驱一次 drawText（$invoke($r) 内自选参数），栅格化回位图 */
    private static function drawTextOn(int $width, int $height, \Closure $invoke): PpmGrid
    {
        $r = new BarePdfRenderer();
        $r->begin($width, $height);
        $invoke($r);
        $pdf = $r->end();
        self::assertNotSame('', $pdf, 'PDF 产物为空');

        return self::rasterize($pdf, $width, $height);
    }

    /** 写一个垃圾字节字体夹具并返回路径 */
    private static function writeFontFixture(string $name, string $bytes): string
    {
        $path = sys_get_temp_dir() . '/canvas-pdf-font-fixture-' . uniqid() . '-' . $name;
        self::assertNotFalse(file_put_contents($path, $bytes), '写字体夹具失败');

        return $path;
    }

    /**
     * pdffonts 内嵌子集行断言（spike 实测形态）：存在一行以固定前缀 MPDFAA+ 起
     * （tfpdf.php 硬编码 'MPDFAA+'.name）、类型 CID TrueType、编码 Identity-H、
     * emb/sub/uni 三列全 yes
     */
    private function assertEmbeddedSubsetFontLine(string $pdffonts): void
    {
        foreach (explode("\n", $pdffonts) as $line) {
            if (preg_match('#^MPDFAA\+\S+.*CID TrueType\s+Identity-H\s+yes\s+yes\s+yes#', $line) === 1) {
                $this->addToAssertionCount(1);

                return;
            }
        }
        self::fail("pdffonts 未找到 tFPDF 子集内嵌行(MPDFAA+ / CID TrueType / Identity-H / emb-sub-uni yes):\n" . $pdffonts);
    }

    /** ==================== 原语级 ==================== */

    public function testDrawTextEmptyLineNoOp(): void
    {
        // 空行零副作用（go 同款空串守卫）：不报错、无墨迹
        $grid = self::drawTextOn(20, 20, fn (BarePdfRenderer $r) => $r->drawText('', 10, 10, '', 12, '#000', 'left', 'top', 0));
        self::assertBoxInk($grid, [0, 0, 20, 20], 128, false, '空行');
    }

    public function testDrawTextHelveticaFallbackInkExists(): void
    {
        // 空串/纯数字字体 = PDF 核心字体 Helvetica 兜底（ASCII-only 语义，
        // 对齐 image 侧内置默认字体与 go basicfont：ASCII 可渲）
        foreach (['', '1', '42', '1.5'] as $fontFile) {
            $grid = self::drawTextOn(40, 20, fn (BarePdfRenderer $r) => $r->drawText('abc', 5, 15, $fontFile, 12, '#000', 'left', 'top', 0));
            self::assertBoxInk($grid, [0, 0, 40, 20], 128, true, "fontFile={$fontFile} Helvetica 兜底");
        }
    }

    public function testDrawTextHorizontalAnchors(): void
    {
        // 水平对齐锚点：左对齐墨迹从 x 起笔、右对齐墨迹收于 x、居中关于 x 对称；
        // 宽度来自 GetStringWidth 实测（与落笔同一度量路径）。
        // 单位口径红线：锚点 ×0.75、实测宽度偏移已是 pt 不再乘——混乘会让右对齐
        // 墨迹越过锚点 ~25% 文本宽（WWW@12px 宽 ≈34px，混乘误差 ~8px ≫ 容差）
        // (x,y)=(20/40/30, 5)；墨迹收敛判定留 ±2 容差
        $left = self::drawTextOn(60, 20, fn (BarePdfRenderer $r) => $r->drawText('WWW', 20, 5, '', 12, '#000', 'left', 'top', 0));
        $leftBox = self::inkBox($left);
        self::assertNotNull($leftBox, 'left 无墨迹');
        self::assertTrue($leftBox[0] >= 18 && $leftBox[0] <= 22, "left 首列墨迹 x={$leftBox[0]}, want ∈[18,22]");

        $right = self::drawTextOn(60, 20, fn (BarePdfRenderer $r) => $r->drawText('WWW', 40, 5, '', 12, '#000', 'right', 'top', 0));
        $rightBox = self::inkBox($right);
        self::assertNotNull($rightBox, 'right 无墨迹');
        self::assertTrue($rightBox[2] >= 38 && $rightBox[2] <= 42, "right 末列墨迹 x={$rightBox[2]}, want ∈[38,42]");

        $center = self::drawTextOn(60, 20, fn (BarePdfRenderer $r) => $r->drawText('WWW', 30, 5, '', 12, '#000', 'center', 'top', 0));
        $centerBox = self::inkBox($center);
        self::assertNotNull($centerBox, 'center 无墨迹');
        $lgap = 30 - $centerBox[0];
        $rgap = $centerBox[2] - 30;
        self::assertTrue(abs($lgap - $rgap) <= 2, "center 墨迹 [{$centerBox[0]},{$centerBox[2]}] 应关于 x=30 对称(左距 {$lgap} 右距 {$rgap})");
    }

    public function testDrawTextVerticalAnchorsFromFontMetrics(): void
    {
        // 垂直对齐用字体 metrics 落基线（top:+ascent / center:(ascent+descent)/2 /
        // bottom:-descent，descent 为负）：top 墨迹全在锚点下方、bottom 收于锚点、
        // center 跨锚点两侧；top 与 bottom 整体错开一个 metrics 线高（相对关系断言）
        $span = static function (string $vAlign): array {
            $grid = self::drawTextOn(40, 40, static fn (BarePdfRenderer $r) => $r->drawText('ABC', 10, 20, '', 12, '#000', 'left', $vAlign, 0));
            $box = self::inkBox($grid);
            self::assertNotNull($box, "vAlign={$vAlign} 无墨迹");

            return [$box[1], $box[3]];
        };

        [$tminY, $tmaxY] = $span('top');
        [$bminY, $bmaxY] = $span('bottom');
        self::assertTrue($tminY >= 19, "top 首行墨迹 y={$tminY}, 不得高于锚点上方 1px");
        self::assertTrue($bmaxY <= 21, "bottom 末行墨迹 y={$bmaxY}, 应收于锚点 y=20（基线按 descent 抬起）");
        self::assertTrue($tminY > $bminY && $tmaxY > $bmaxY, "top 墨迹 [{$tminY},{$tmaxY}] 应整体低于 bottom 墨迹 [{$bminY},{$bmaxY}] 一个 metrics 线高");

        [$cminY, $cmaxY] = $span('center');
        self::assertTrue($cminY < 20 && $cmaxY > 20, "center 墨迹 [{$cminY},{$cmaxY}] 应跨越锚点 y=20 两侧");
    }

    public function testDrawTextUnknownAlignFallsBackLeftTop(): void
    {
        // 未知对齐取值归 left/top（switch 缺省臂，与 image 侧/go 同一防线）
        $boxOf = static function (string $h, string $v): array {
            $grid = self::drawTextOn(60, 30, static fn (BarePdfRenderer $r) => $r->drawText('ABC', 10, 5, '', 12, '#000', $h, $v, 0));
            $box = self::inkBox($grid);
            self::assertNotNull($box, "align={$h}/{$v} 无墨迹");

            return [$box[0], $box[1]];
        };
        $foreign = $boxOf('diagonal', 'middle');
        $known = $boxOf('left', 'top');
        self::assertSame($known, $foreign, '未知取值应与 left/top 起墨一致');
    }

    public function testDrawTextAngleRotatesAboutAnchor(): void
    {
        // 角度 90（正值逆时针，poppler 栅格实测标定）：水平行文绕锚点转纵向——
        // 横向跨距收缩为字面高度、纵向跨距膨胀为文本宽度、墨迹向上越过锚点；
        // -90 镜像向下（相对关系断言，不依赖对齐偏移具体数值）
        $boxOf = static function (int $angle): array {
            $grid = self::drawTextOn(80, 80, static fn (BarePdfRenderer $r) => $r->drawText('WWW', 40, 40, '', 12, '#000', 'left', 'center', $angle));
            $box = self::inkBox($grid);
            self::assertNotNull($box, "angle={$angle} 无墨迹");

            return $box;
        };

        [$ux0, $uy0, $ux1, $uy1] = $boxOf(0);
        [$rx0, $ry0, $rx1, $ry1] = $boxOf(90);
        [$lx0, , , $ly1] = $boxOf(-90);

        self::assertTrue($rx1 - $rx0 < $ux1 - $ux0, "旋转后横向跨距 " . ($rx1 - $rx0) . ", 应小于未旋转的 " . ($ux1 - $ux0));
        self::assertTrue($ry1 - $ry0 > $uy1 - $uy0, "旋转后纵向跨距 " . ($ry1 - $ry0) . ", 应大于未旋转的 " . ($uy1 - $uy0));
        self::assertTrue($ry0 < 40, "逆时针旋转后墨迹顶 y={$ry0}, 应向上越过锚点 y=40");
        self::assertTrue($ly1 > 40, "顺时针(-90)旋转后墨迹底 y={$ly1}, 应向下越过锚点 y=40");
        self::assertTrue($lx0 < 40, "顺时针(-90)旋转后墨迹左缘 x={$lx0}, 应向左越过锚点 x=40");
    }

    public function testDrawTextAnchorMatrixCrossProduct(): void
    {
        // 工票 02 验收面的真交叉积：hAlign×vAlign×角度(0/±90) 全组合 27 格，
        // 单页网格一次渲染一次栅格化，逐格点位断言（不拆轴、不留弱化角）——
        // 0° 行逐格锁定对齐锚点语义（实测宽度水平对齐 + metrics 垂直基线），
        // ±90° 行逐格断言「墨迹相对盒 = 同列 0° 相对盒绕锚点的旋转像」（对齐
        // 偏移随落笔点一起旋转，恒距变换），全程不依赖具体字体度量数值
        $cell = 80; // 单格边长：墨迹最大伸展(WWW@12px 宽 ≈34px + 抗锯齿) < 半格
        $tol = 2;   // 栅格化抗锯齿边缘容差(px)
        $hAligns = ['left', 'center', 'right'];
        $vAligns = ['top', 'center', 'bottom'];
        $angles = [0, 90, -90];
        $cols = count($hAligns) * count($vAligns);
        $width = $cols * $cell;
        $height = count($angles) * $cell;

        $grid = self::drawTextOn($width, $height, static function (BarePdfRenderer $r) use ($hAligns, $vAligns, $angles, $cell, $cols): void {
            foreach ($angles as $row => $angle) {
                for ($col = 0; $col < $cols; $col++) {
                    $h = $hAligns[intdiv($col, count($vAligns))];
                    $v = $vAligns[$col % count($vAligns)];
                    // 锚点 = 格中心：同一字面同一字号，全组合铺满网格
                    $r->drawText('WWW', $col * $cell + intdiv($cell, 2), $row * $cell + intdiv($cell, 2), '', 12, '#000', $h, $v, $angle);
                }
            }
        });

        // relBox 锚点相对墨迹盒(格内缩 2px 扫描,隔离相邻格的抗锯齿越界)；无墨返回 null
        $relBox = static function (int $col, int $row) use ($grid, $cell): ?array {
            $box = self::inkBoxIn($grid, $col * $cell + 2, $row * $cell + 2, ($col + 1) * $cell - 2, ($row + 1) * $cell - 2);
            if ($box === null) {
                return null;
            }

            return [
                $box[0] - $col * $cell - intdiv($cell, 2),
                $box[1] - $row * $cell - intdiv($cell, 2),
                $box[2] - $col * $cell - intdiv($cell, 2),
                $box[3] - $row * $cell - intdiv($cell, 2),
            ];
        };
        $within = static fn (int $got, int $want): bool => $got >= $want - $tol && $got <= $want + $tol;
        $alignPair = static function (int $col) use ($hAligns, $vAligns): array {
            return [$hAligns[intdiv($col, count($vAligns))], $vAligns[$col % count($vAligns)]];
        };

        // 0° 行：hAlign×vAlign 锚点语义 9 格逐格锁定
        for ($col = 0; $col < $cols; $col++) {
            [$h, $v] = $alignPair($col);
            $label = "0°({$h},{$v})";
            $box = $relBox($col, 0);
            self::assertNotNull($box, "{$label} 无墨迹");
            [$x0, $y0, $x1, $y1] = $box;
            switch ($h) {
                case 'left':
                    self::assertTrue($within($x0, 0), "{$label} 左对齐应从锚点起笔: 首列相对 x={$x0}, want |x|≤{$tol}");
                    break;
                case 'center':
                    self::assertTrue($within($x0 + $x1, 0), "{$label} 居中应关于锚点对称: 左距 " . -$x0 . " vs 右距 {$x1}");
                    break;
                case 'right':
                    self::assertTrue($within($x1, 0), "{$label} 右对齐应收于锚点: 末列相对 x={$x1}, want |x|≤{$tol}");
                    break;
            }
            switch ($v) {
                case 'top':
                    self::assertTrue($y0 >= -1, "{$label} 顶对齐墨迹不得高于锚点上方 1px: 首行相对 y={$y0}");
                    break;
                case 'center':
                    self::assertTrue($y0 < 0 && $y1 > 0, "{$label} 居中墨迹 [{$y0},{$y1}] 应跨越锚点两侧");
                    break;
                case 'bottom':
                    self::assertTrue($y1 <= 1, "{$label} 底对齐墨迹应收于锚点上方: 末行相对 y={$y1}");
                    break;
            }
        }

        // ±90° 行：墨迹相对盒 = 同列 0° 相对盒的旋转像
        // (+90 视觉逆时针:(dx,dy)→(dy,−dx)；−90 镜像:(dx,dy)→(−dy,dx)；
        // 与 testDrawTextAngleRotatesAboutAnchor 的方向标定同源)
        $rotBox = static fn (int $angle, int $x0, int $y0, int $x1, int $y1): array => match (true) {
            $angle === 90 => [$y0, -$x1, $y1, -$x0],
            $angle === -90 => [-$y1, $x0, -$y0, $x1],
            default => [$x0, $y0, $x1, $y1],
        };
        $base0 = [];
        for ($col = 0; $col < $cols; $col++) {
            $box = $relBox($col, 0);
            self::assertNotNull($box, "0° 第 {$col} 列无墨迹,±90° 行失去旋转基准");
            $base0[$col] = $box;
        }
        foreach (array_slice($angles, 1) as $row => $angle) {
            for ($col = 0; $col < $cols; $col++) {
                [$h, $v] = $alignPair($col);
                [$bx0, $by0, $bx1, $by1] = $base0[$col];
                $got = $relBox($col, $row + 1);
                self::assertNotNull($got, "{$angle}°({$h},{$v}) 无墨迹");
                $want = $rotBox($angle, $bx0, $by0, $bx1, $by1);
                foreach (['x0' => 0, 'y0' => 1, 'x1' => 2, 'y1' => 3] as $name => $i) {
                    self::assertTrue(
                        $within($got[$i], $want[$i]),
                        "{$angle}°({$h},{$v}) 墨迹相对盒 {$name} = {$got[$i]}, want {$want[$i]}(±{$tol},应为同列 0° 盒绕锚点的旋转像)"
                    );
                }
            }
        }
    }

    public function testDrawTextInvalidFontAbortsReadable(): void
    {
        // 失败防线（TTF-only 硬门槛，spike 实测钉定）：ttcf/OTTO/垃圾魔数在 tFPDF
        // 内部是进程级 die()——应用层魔数嗅探必须前置拒收为可读异常，绝不能让
        // die() 发生（进程死亡即本测试组死亡）。parity 措辞：与 go 的 parity 适用域
        // = 「TTC/垃圾字体必须可读拒收、不得静默吞」这一失败结局；反例域 = 错误
        // 文案与异常类型（两后端各出各的，不在契约内）
        $r = new BarePdfRenderer();
        $r->begin(20, 20);

        $junk = self::writeFontFixture('junk.ttf', 'not a font');
        try {
            $r->drawText('x', 5, 10, $junk, 12, '#000', 'left', 'top', 0);
            self::fail('垃圾字体字节应报错');
        } catch (\InvalidArgumentException $e) {
            $this->addToAssertionCount(1);
        }

        $ttc = self::writeFontFixture('fake.ttc', 'ttcf............');
        try {
            $r->drawText('x', 5, 10, $ttc, 12, '#000', 'left', 'top', 0);
            self::fail('TTC 字体集应报错');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('TTC', $e->getMessage(), 'TTC 错误应可读');
        }

        $otto = self::writeFontFixture('fake.otf', 'OTTO' . str_repeat("\x00", 16));
        try {
            $r->drawText('x', 5, 10, $otto, 12, '#000', 'left', 'top', 0);
            self::fail('OTF/CFF 字体应报错');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('CFF', $e->getMessage(), 'OTF/CFF 错误应可读');
        }
    }

    /** ==================== 管线级 ==================== */

    public function testDrawTextPipelineExtractsCjkWithEmbeddedSubset(): void
    {
        // 管线级：pdftotext 提取 ASCII + CJK（ToUnicode 随子集内嵌）；pdffonts
        // 内嵌子集标记按 spike 实测形态钉定 = 「CID TrueType + Identity-H +
        // MPDFAA+ 前缀 + emb/sub/uni 全 yes」（与 go 侧 sub=no 无前缀形态不同，
        // 不可照抄 go 断言）。TextLayer 缺省 vAlign=bottom——管线级文本测试显式对齐
        $font = self::probeCjkFont();

        $layer = TextLayer::make(200, 40)
            ->setPosition(0, 0)
            ->setText('php-canvas 证书 PDF')
            ->setFont($font, 20, '#111111')
            ->setHorizontalAlign('left')
            ->setVerticalAlign('top')
            ->setPadding(6);
        $pdf = self::renderCanvas(Canvas::make(200, 40, $layer));

        $text = self::runPoppler('pdftotext', [self::writePdf($pdf), '-']);
        self::assertStringContainsString('php-canvas', $text, 'pdftotext 应提取 ASCII 串');
        self::assertStringContainsString('证书', $text, 'pdftotext 应提取 CJK 串(ToUnicode)');

        $this->assertEmbeddedSubsetFontLine(self::runPoppler('pdffonts', [self::writePdf($pdf)]));
    }

    public function testDrawTextHelveticaFallbackNotEmbedded(): void
    {
        // 兜底字体 = 核心字体 Helvetica：ASCII 兜底可渲且**不内嵌**（核心字体由
        // 阅读器提供，与 TTF 子集内嵌互证）
        $layer = TextLayer::make(100, 30)
            ->setPosition(0, 0)
            ->setText('fallback ascii')
            ->setFont('', 14, '#111111')
            ->setHorizontalAlign('left')
            ->setVerticalAlign('top');
        $pdf = self::renderCanvas(Canvas::make(100, 30, $layer));

        $text = self::runPoppler('pdftotext', [self::writePdf($pdf), '-']);
        self::assertStringContainsString('fallback ascii', $text, 'pdftotext 应提取兜底字体串');

        $fonts = self::runPoppler('pdffonts', [self::writePdf($pdf)]);
        foreach (explode("\n", $fonts) as $line) {
            if (str_contains($line, 'Helvetica')) {
                self::assertStringContainsString(' no ', $line, 'Helvetica 核心字体应不内嵌: ' . $line);
                self::assertStringNotContainsString('yes', $line, 'Helvetica 行不应出现内嵌标记: ' . $line);

                return;
            }
        }
        self::fail("pdffonts 未找到 Helvetica 行:\n" . $fonts);
    }

    public function testRendererReuseReregistersCjkFontAfterEnd(): void
    {
        // End 后复用三轮锁（字体侧）：end 复位字体注册表后，同实例再渲染 CJK 必须
        // 重新注册——复位失效时第二轮在新建文档上沿用旧族名且跳过 AddFont，SetFont
        // 即报 Undefined font。每轮：单页结构 + pdftotext 可提取该轮文案 + pdffonts
        // 内嵌子集行（子集内嵌随文档走，逐轮独立成立）
        $font = self::probeCjkFont();
        $r = new BarePdfRenderer();
        foreach (['证书一轮', '证书二轮', '证书三轮'] as $i => $line) {
            $round = $i + 1;
            $r->begin(160, 40);
            $r->drawText($line, 10, 20, $font, 16, '#111111', 'left', 'center', 0);
            $pdf = $r->end();

            self::assertPdfStructure($pdf, 1, [[120.0, 30.0]]);
            $text = self::runPoppler('pdftotext', [self::writePdf($pdf), '-']);
            self::assertStringContainsString($line, $text, "第 {$round} 轮 pdftotext 应提取该轮文案（字体逐轮重注册）");
            $this->assertEmbeddedSubsetFontLine(self::runPoppler('pdffonts', [self::writePdf($pdf)]));
        }
    }

    public function testCjkSubsetStaysSmall(): void
    {
        // CJK 子集体积断言（tFPDF 特有新增）：子集按需、体积驱动 = 字形数——
        // 500 个互异 CJK 字符的文档必须 < 1MB（探针字体全量 20MB 级，若子集
        // 失效退化为整字体嵌入会爆表）。单行溢出页宽由「不切页」契约兜住
        $font = self::probeCjkFont();
        $chars = '';
        for ($i = 0; $i < 500; $i++) {
            $chars .= mb_chr(0x4E00 + $i, 'UTF-8');
        }

        $layer = TextLayer::make(11000, 40)
            ->setPosition(0, 0)
            ->setText($chars)
            ->setFont($font, 20, '#111111')
            ->setHorizontalAlign('left')
            ->setVerticalAlign('top');
        $pdf = self::renderCanvas(Canvas::make(11000, 40, $layer));

        self::assertLessThan(1048576, strlen($pdf), '500 字形 CJK 子集应 < 1MB（整字体嵌入则子集机制失效）');
    }
}
