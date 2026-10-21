<?php

declare(strict_types=1);

namespace HankChen\CanvasNext\Renderer\Pdf\Tests;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\CanvasBackground;
use HankChen\CanvasNext\Compiler\CanvasPaginator;
use HankChen\CanvasNext\Contracts\DownloaderInterface;
use HankChen\CanvasNext\Exception\MaterializeException;
use HankChen\CanvasNext\Layer\ImageLayer;
use HankChen\CanvasNext\Layer\TableCellLayer;
use HankChen\CanvasNext\Layer\TableLayer;
use HankChen\CanvasNext\Layer\TableRowLayer;
use HankChen\CanvasNext\Layer\TextLayer;
use HankChen\CanvasNext\Renderer\Pdf\PdfRenderer;
use HankChen\CanvasNext\Renderer\Pdf\Tests\Support\PopplerPdfCase;
use HankChen\CanvasNext\ResourceManagers\ResourceResolver;
use HankChen\CanvasNext\Runtime\Cancellation;
use HankChen\CanvasNext\Runtime\CancellationSource;
use HankChen\CanvasNext\Runtime\NullCancellation;
use HankChen\CanvasNext\Runtime\TimeoutCancellation;

/**
 * 多页入口测试（工票 03，平移 go-canvas/pdf-renderer renderpages_test.go 全目）：
 * 消费页序列（CanvasPaginator 切页产物 / DocumentCompiler::compile(...)->canvases
 * 同构形态，帧序×页序）出单 PDF。验收面 = poppler 断言、无黄金文件：pdfinfo 页数/
 * 逐页尺寸、pdftoppm -r 96 逐页栅格化按页采样背景色（页序）、pdftotext 按页提取标记
 * 文本（含「标记不跨页」反例）；另锁 End 后实例复用（多页→多页→单页三轮，工票 02
 * Begin 续页语义的边界）、复用后 CJK 字体重注册、第 k 页失败无产物逃逸、ctx 页间
 * 掐断 sentinel（页间检查点语义在后端的落点）、与 CanvasPaginator 切页联测。
 * 多页编排归核心 walk 模板（工票 01 renderPages），本组不做后端内切页。
 */
class PdfRenderPagesTest extends PopplerPdfCase
{
    /** ==================== 助手 ==================== */

    /**
     * 标记页画布：指定尺寸 + 纯色背景 + 左上角默认字体标记文本
     * （文本走内置 Helvetica，ASCII-only，不依赖 CJK 字体腿；墨迹在角落，
     * 不干扰中心点背景采样）
     */
    private static function markerPage(int $width, int $height, string $bg, string $marker): Canvas
    {
        $layer = TextLayer::make($width, $height)
            ->setPosition(0, 0)
            ->setText($marker)
            ->setFont('', 12, '#111111')
            ->setHorizontalAlign('left')
            ->setVerticalAlign('top');
        $canvas = Canvas::make($width, $height, $layer);
        $canvas->setBackground(CanvasBackground::make($bg));

        return $canvas;
    }

    /** 多页便捷入口渲染（新实例），产物断言为非空 PDF 字节串 */
    private static function renderPdfPages(array $pages): string
    {
        return self::renderPdfPagesOn(new PdfRenderer(), $pages);
    }

    /** 多页便捷入口渲染（指定实例——复用锁测试用），产物断言同上 */
    private static function renderPdfPagesOn(PdfRenderer $renderer, array $pages): string
    {
        $pdf = $renderer->renderPages(new NullCancellation(), $pages);
        self::assertNotSame('', $pdf, 'PDF 产物为空');
        self::assertStringStartsWith('%PDF', $pdf, '产物不是 PDF 文档');

        return $pdf;
    }

    /** 单页入口渲染（指定实例——复用锁测试用），产物断言同上 */
    private static function renderCanvasOn(PdfRenderer $renderer, Canvas $canvas): string
    {
        $pdf = $renderer->render(new NullCancellation(), $canvas);
        self::assertNotSame('', $pdf, 'PDF 产物为空');
        self::assertStringStartsWith('%PDF', $pdf, '产物不是 PDF 文档');

        return $pdf;
    }

    /** pdftotext 按页提取（-f k -l k） */
    private static function pageText(string $pdf, int $page): string
    {
        return self::runPoppler('pdftotext', [self::writePdf($pdf), '-f', (string) $page, '-l', (string) $page, '-']);
    }

    /** pdfinfo 字段表（Pages / Page size 等），供两份产物的结构一致性比对 */
    private static function infoFields(string $pdf): array
    {
        $fields = [];
        foreach (explode("\n", self::runPoppler('pdfinfo', [self::writePdf($pdf)])) as $line) {
            if (preg_match('/^([A-Za-z][A-Za-z ]*):\s*(.*)$/', $line, $m) === 1) {
                $fields[$m[1]] = $m[2];
            }
        }

        return $fields;
    }

    /** ==================== 多页契约：页数 / 逐页尺寸 / 页序 ==================== */

    public function testMultiPageCountSizesAndOrder(): void
    {
        // 三页三尺寸三背景色：页数 = 页序列长度；各页 MediaBox = 各页 px×0.75；
        // 页序 = 页序列序（背景色按页采样 + 标记文本按页提取双重锚定）
        $pdf = self::renderPdfPages([
            self::markerPage(320, 240, '#ff0000', 'PAGE-ONE'),
            self::markerPage(200, 100, '#00ff00', 'PAGE-TWO'),
            self::markerPage(100, 80, '#0000ff', 'PAGE-THREE'),
        ]);

        self::assertPdfStructure($pdf, 3, [[240.0, 180.0], [150.0, 75.0], [75.0, 60.0]]);

        // 页序锚定一：逐页栅格化采中心点背景色（实心区内部点，零抗锯齿干扰）
        $centers = [
            [320, 240, self::RED],
            [200, 100, self::GREEN],
            [100, 80, self::BLUE],
        ];
        foreach ($centers as $i => [$w, $h, $want]) {
            $grid = self::rasterize($pdf, $w, $h, $i + 1);
            self::assertPixelSame($want, $grid, intdiv($w, 2), intdiv($h, 2));
        }

        // 页序锚定二：标记文本按页提取（默认字体 ASCII，与 ToUnicode 无关也可提取）
        foreach (['PAGE-ONE', 'PAGE-TWO', 'PAGE-THREE'] as $i => $marker) {
            self::assertStringContainsString($marker, self::pageText($pdf, $i + 1), "第 " . ($i + 1) . " 页 pdftotext 应含标记 {$marker}");
        }
        // 标记不跨页：第 1 页不得出现后页标记（页序错排在此暴露）
        $first = self::pageText($pdf, 1);
        self::assertStringNotContainsString('PAGE-TWO', $first, '第 1 页出现第 2 页标记，页序错排');
        self::assertStringNotContainsString('PAGE-THREE', $first, '第 1 页出现第 3 页标记，页序错排');
    }

    /** ==================== 退化：单 canvas 多页入口 vs 单页渲染，结构一致 ==================== */

    public function testSingleCanvasDegeneratesLikeSingleRender(): void
    {
        $canvas = self::markerPage(240, 160, '#4682b4', 'DEGENERATE-PAGE');

        $multi = self::renderPdfPages([$canvas]);
        $single = self::renderCanvas($canvas); // 工票 02 单页入口（render 模板）

        // 结构一致（pdfinfo 页数/页尺寸）：字节面不比——创建时间/文档 ID 必然不同，
        // 语义契约是结构同构
        $multiInfo = self::infoFields($multi);
        $singleInfo = self::infoFields($single);
        self::assertSame($singleInfo['Pages'], $multiInfo['Pages'], '退化单页页数应与单页渲染一致');
        self::assertSame($singleInfo['Page size'], $multiInfo['Page size'], '退化单页页尺寸应与单页渲染一致');

        // 退化页内容同构：标记文本与背景色在两个入口下等价呈现
        self::assertStringContainsString('DEGENERATE-PAGE', self::pageText($multi, 1), '多页入口单页应含标记文本');
        self::assertPixelSame([70, 130, 180], self::rasterize($multi, 240, 160, 1), 120, 80);
    }

    /** ==================== End 后复用：同一实例必须得到干净新文档 ==================== */

    public function testReuseAfterEndStartsFreshDocument(): void
    {
        // 工票 02 的 begin 是「文档已在则续页」语义：end 后必须复位会话，
        // 否则第二次渲染把页面追加进旧文档（页数累加、尺寸串页）
        $renderer = new PdfRenderer();

        // 第一次：多页入口，两页两尺寸
        $pdf1 = self::renderPdfPagesOn($renderer, [
            self::markerPage(100, 80, '#ff0000', 'DOC1-A'),
            self::markerPage(120, 90, '#00ff00', 'DOC1-B'),
        ]);
        self::assertPdfStructure($pdf1, 2, [[75.0, 60.0], [90.0, 67.5]]);

        // 第二次（同一实例，多页入口）：一页不同尺寸——复位失效时会是 3 页旧+新混排
        $pdf2 = self::renderPdfPagesOn($renderer, [self::markerPage(200, 148, '#0000ff', 'DOC2-A')]);
        self::assertPdfStructure($pdf2, 1, [[150.0, 111.0]]);
        self::assertPixelSame(self::BLUE, self::rasterize($pdf2, 200, 148, 1), 100, 74);

        // 第三次（同一实例，单页入口）：单页渲染同样受益于复位语义
        $pdf3 = self::renderCanvasOn($renderer, self::markerPage(80, 60, '#ffff00', 'DOC3-A'));
        self::assertPdfStructure($pdf3, 1, [[60.0, 45.0]]);
    }

    public function testReuseAfterEndReregistersFonts(): void
    {
        // 复位语义的字体内面：字体注册表随文档会话走，end 后必须重新注册——
        // 复用族名跳过注册会让第二份文档 SetFont 爆 undefined font
        $font = self::probeCjkFont();
        $renderer = new PdfRenderer();

        $buildPage = static fn (string $marker): Canvas => Canvas::make(160, 60, TextLayer::make(160, 60)
            ->setPosition(0, 0)
            ->setText($marker)
            ->setFont($font, 14, '#111111')
            ->setHorizontalAlign('left')
            ->setVerticalAlign('top'));

        $first = self::renderPdfPagesOn($renderer, [$buildPage('第一次文档')]);
        $second = self::renderPdfPagesOn($renderer, [$buildPage('第二次文档')]);

        foreach ([$first, $second] as $i => $pdf) {
            $marker = $i === 0 ? '第一次文档' : '第二次文档';
            self::assertStringContainsString(
                $marker,
                self::pageText($pdf, 1),
                '第 ' . ($i + 1) . ' 份文档 pdftotext 应提取 CJK 标记（字体重注册生效）',
            );
        }
    }

    /** ==================== 失败/取消注入：任一页失败整文档丢弃 ==================== */

    public function testDownloadFailureOnLaterPageNoProduct(): void
    {
        // 下载失败落在第 2 页（第 1 页无资源、渲染完好）：整文档丢弃、无产物逃逸
        // （PHP 面的「零产物」= renderPages 以异常终止而非返回产物）
        $downloader = new class implements DownloaderInterface {
            public int $calls = 0;

            public function download(Cancellation $ctx, string $url): string|false
            {
                $this->calls++;

                return false;
            }
        };
        $resolver = new ResourceResolver($downloader);
        $broken = Canvas::make(60, 40, ImageLayer::make(20, 20)->setImage('https://example.com/broken.png'));

        $renderer = new PdfRenderer($resolver);
        try {
            $renderer->renderPages(new NullCancellation(), [
                self::markerPage(60, 40, '#d3d3d3', 'OK-PAGE'),
                $broken,
            ]);
            self::fail('第 2 页下载失败必须报错');
        } catch (MaterializeException $e) {
            self::assertSame('resource_download_failed', $e->getErrorCode());
        }
        self::assertGreaterThanOrEqual(1, $downloader->calls, '下载器未被调用');
    }

    public function testCancelArmedAtInterPageCheckpoint(): void
    {
        // 第 1 页零根层（页内检查点全部不触发）：预取消 ctx 的首个命中等检查点
        // 恰为页间一处（下一页 begin 前）——页间检查点语义在 PDF 后端的落点验证
        $pages = [
            Canvas::make(60, 40),
            self::markerPage(60, 40, '#d3d3d3', 'NEVER-REACHED'),
        ];

        $source = new CancellationSource();
        $source->cancel();
        try {
            (new PdfRenderer())->renderPages($source->getCancellation(), $pages);
            self::fail('页间取消必须报错');
        } catch (MaterializeException $e) {
            self::assertSame('render_cancelled', $e->getErrorCode());
        }
    }

    public function testDeadlineArmedAtInterPageCheckpoint(): void
    {
        // 同上检查点，deadline 已到期 → render_deadline_exceeded
        $pages = [
            Canvas::make(60, 40),
            self::markerPage(60, 40, '#d3d3d3', 'NEVER-REACHED'),
        ];

        usleep(1000); // 确保 deadline 已过期（TimeoutCancellation(0) 构造即到期）
        try {
            (new PdfRenderer())->renderPages(new TimeoutCancellation(0), $pages);
            self::fail('页间到期必须报错');
        } catch (MaterializeException $e) {
            self::assertSame('render_deadline_exceeded', $e->getErrorCode());
        }
    }

    /** ==================== 与分页生产端联测：Paginator 切页 → 多页入口 ==================== */

    public function testPaginatedCanvasesRenderAsMultiPagePdf(): void
    {
        // 6 行 × 60px 驱动表，页高 200px：装箱切 2 页（3 行/页，180 ≤ 200 < 240），
        // 每页画布 200×200（页高即页几何，行序保序不切开）——页序列进多页入口，
        // pdfinfo 页数/逐页尺寸与按页行文本抽验（含跨页反例）
        $table = TableLayer::make(200, 360, '#fff');
        for ($i = 1; $i <= 6; $i++) {
            $text = TextLayer::make(200, 60)
                ->setPosition(0, 0)
                ->setText("ROW-{$i}")
                ->setFont('', 12, '#111111')
                ->setHorizontalAlign('left')
                ->setVerticalAlign('top');
            $table->addRow((new TableRowLayer(200, 60))
                ->addCell(TableCellLayer::make(200, 60)->addContentLayer($text)));
        }
        $source = Canvas::make(200, 400, $table);

        $pages = (new CanvasPaginator(200))->paginate($source);
        self::assertCount(2, $pages, '6 行×60px / 页高 200px 应切 2 页');
        foreach ($pages as $i => $page) {
            self::assertSame(200, $page->getWidth(), "第 " . ($i + 1) . " 页宽应承源画布");
            self::assertSame(200, $page->getHeight(), "第 " . ($i + 1) . " 页高应 = pageHeight");
        }

        $pdf = self::renderPdfPages($pages);
        self::assertPdfStructure($pdf, 2, [[150.0, 150.0], [150.0, 150.0]]);

        // 行文本按页分布：第 1 页 ROW-1..3、第 2 页 ROW-4..6（帧序×页序的消费语义）
        $page1 = self::pageText($pdf, 1);
        $page2 = self::pageText($pdf, 2);
        foreach (['ROW-1', 'ROW-2', 'ROW-3'] as $marker) {
            self::assertStringContainsString($marker, $page1, "第 1 页应含 {$marker}");
        }
        foreach (['ROW-4', 'ROW-5', 'ROW-6'] as $marker) {
            self::assertStringContainsString($marker, $page2, "第 2 页应含 {$marker}");
        }
        self::assertStringNotContainsString('ROW-4', $page1, '第 1 页出现第 2 页行文本，装箱页序错排');
    }
}
