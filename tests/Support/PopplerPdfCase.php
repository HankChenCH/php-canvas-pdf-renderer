<?php

declare(strict_types=1);

namespace HankChen\CanvasNext\Renderer\Pdf\Tests\Support;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Layer\AbstractLayer;
use HankChen\CanvasNext\Renderer\Pdf\PdfRenderer;
use HankChen\CanvasNext\Runtime\NullCancellation;
use PHPUnit\Framework\TestCase;

/**
 * poppler 点位断言的测试基座：渲染管线装配、poppler 四件套包装（pdfinfo/pdftoppm）、
 * P6 PPM 栅格解析与三级取样断言。
 * 无黄金文件：结构断言（pdfinfo 页数/页尺寸）+ 实心区内部点取样（pdftoppm -r 96
 * 恰好 1:1 还原像素网格，尺寸失配即 Fatal——采样坐标失配无意义）+ 墨迹粗判。
 * （镜像 go-canvas/pdf-renderer helpers_test.go；栅格读取用 PPM 纯字节解析，
 * 测试面零 GD——GD 只在 JPEG 夹具编码出场，见 Fixtures::requireGd）
 */
abstract class PopplerPdfCase extends TestCase
{
    /** 共享色值（[r,g,b]，与 go-canvas/pdf-renderer helpers_test.go 同一色板） */
    protected const RED = [255, 0, 0];
    protected const GREEN = [0, 255, 0];
    protected const BLUE = [0, 0, 255];
    protected const YELLOW = [255, 255, 0];
    protected const WHITE = [255, 255, 255];

    /** 经标准管线渲染整画布（单页 render 模板），产物断言为非空 PDF 字节串 */
    protected static function renderCanvas(Canvas $canvas): string
    {
        $product = (new PdfRenderer())->render(new NullCancellation(), $canvas);
        self::assertNotSame('', $product, 'PDF 产物为空');
        self::assertStringStartsWith('%PDF', $product, '产物不是 PDF 文档');

        return $product;
    }

    /** 单图层便捷渲染（renderLayer 模板，产物断言同上） */
    protected static function renderLayer(AbstractLayer $layer): string
    {
        $product = (new PdfRenderer())->renderLayer(new NullCancellation(), $layer);
        self::assertNotSame('', $product, 'PDF 产物为空');

        return $product;
    }

    /** 落盘 PDF 字节（poppler 工具只吃文件），返回路径 */
    protected static function writePdf(string $pdf): string
    {
        $path = sys_get_temp_dir() . '/canvas-pdf-probe-' . uniqid() . '.pdf';
        self::assertNotFalse(file_put_contents($path, $pdf), '写临时 PDF 失败');

        return $path;
    }

    /** 跑 poppler 工具（最后一个形参恒 pdf 路径），返回 stdout；非零退出即 Fatal */
    protected static function runPoppler(string $tool, array $args): string
    {
        $cmd = escapeshellarg($tool) . ' ' . implode(' ', array_map('escapeshellarg', $args));
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            self::fail("{$tool} 进程启动失败（poppler 四件套缺席？brew install poppler）");
        }
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0) {
            self::fail("{$tool} 退出码 {$code}\nstderr: {$stderr}");
        }

        return $stdout;
    }

    /**
     * pdfinfo 结构断言：页数与逐页尺寸（= px × 0.75 pt）。
     * $sizes 形态 [[wPt, hPt], ...] 按页序；走 -f/-l 逐页输出（多页异尺寸面）
     */
    protected static function assertPdfStructure(string $pdf, int $wantPages, array $sizes): void
    {
        $info = self::runPoppler('pdfinfo', ['-f', '1', '-l', (string) max($wantPages, 1), self::writePdf($pdf)]);
        $lines = explode("\n", $info);
        $pages = null;
        $pageSizes = [];
        foreach ($lines as $line) {
            if ($pages === null && preg_match('/^Pages:\s+(\d+)/', $line, $m) === 1) {
                $pages = (int) $m[1];
            }
            if (preg_match('/^Page\s+(\d+)\s+size:\s+([\d.]+) x ([\d.]+) pts/', $line, $m) === 1) {
                $pageSizes[(int) $m[1]] = [(float) $m[2], (float) $m[3]];
            }
        }
        self::assertSame($wantPages, $pages, "pdfinfo Pages = " . var_export($pages, true) . ", want {$wantPages}");
        foreach ($sizes as $i => [$wPt, $hPt]) {
            $page = $i + 1;
            self::assertArrayHasKey($page, $pageSizes, "pdfinfo 缺第 {$page} 页尺寸");
            self::assertEqualsWithDelta($wPt, $pageSizes[$page][0], 0.01, "第 {$page} 页宽");
            self::assertEqualsWithDelta($hPt, $pageSizes[$page][1], 0.01, "第 {$page} 页高");
        }
    }

    /**
     * pdftoppm -r 96 栅格化（pt × 96/72 = px，恰好 1:1 还原原像素网格）；
     * 实际尺寸与期望不符即 Fatal。$page 指定页码（多页异尺寸面）
     */
    protected static function rasterize(string $pdf, int $width, int $height, int $page = 1): PpmGrid
    {
        $dir = sys_get_temp_dir() . '/canvas-pdf-raster-' . uniqid();
        self::assertTrue(mkdir($dir), '建栅格化临时目录失败');
        $base = $dir . '/grid';
        self::runPoppler('pdftoppm', ['-r', '96', '-f', (string) $page, '-l', (string) $page, '-singlefile', self::writePdf($pdf), $base]);

        $data = @file_get_contents($base . '.ppm');
        if ($data === false) {
            self::fail('读栅格化产物失败：' . $base . '.ppm（pdftoppm 应缺省输出 P6 PPM）');
        }
        $grid = PpmGrid::parse($data);
        if ($grid->width !== $width || $grid->height !== $height) {
            self::fail("栅格化尺寸 = {$grid->width}x{$grid->height}, want {$width}x{$height}（-r 96 应 1:1 还原像素网格）");
        }

        return $grid;
    }

    /** 点位取样精确断言（不透明实心区内部点，零舍入） */
    protected static function assertPixelSame(array $want, PpmGrid $grid, int $x, int $y): void
    {
        $got = $grid->pixelAt($x, $y);
        self::assertSame($want, $got, "像素 ({$x}, {$y}) = " . json_encode($got) . ', want ' . json_encode($want));
    }

    /** 矢量抗锯齿/有损编码下的容差取样（边缘与边界带用），逐通道 |got−want| ≤ tol */
    protected function assertPixelNear(array $want, PpmGrid $grid, int $x, int $y, int $tol): void
    {
        $got = $grid->pixelAt($x, $y);
        foreach (['R' => 0, 'G' => 1, 'B' => 2] as $name => $i) {
            $d = $got[$i] - $want[$i];
            if ($d < -$tol || $d > $tol) {
                self::fail("像素 ({$x}, {$y}) {$name} = {$got[$i]}, 与 {$want[$i]} 相差超容差 {$tol}");
            }
        }
        // 走到这里即通过（计入断言数，防零断言假绿；addToAssertionCount 在
        // PHPUnit 9.6 是实例方法，故本方法非静态）
        $this->addToAssertionCount(1);
    }

    /** 区域内存在暗于阈值的像素（墨迹判定：矢量字形/图形边缘抗锯齿，只做「有/无墨」粗判） */
    protected static function boxHasInk(PpmGrid $grid, array $box, int $threshold): bool
    {
        [$x0, $y0, $x1, $y1] = $box;
        for ($y = max($y0, 0); $y < min($y1, $grid->height); $y++) {
            for ($x = max($x0, 0); $x < min($x1, $grid->width); $x++) {
                [$r, $g, $b] = $grid->pixelAt($x, $y);
                if ($r < $threshold || $g < $threshold || $b < $threshold) {
                    return true;
                }
            }
        }

        return false;
    }

    /** 区域墨迹断言：want=true 期望有墨，false 期望无墨（白底前提） */
    protected static function assertBoxInk(PpmGrid $grid, array $box, int $threshold, bool $want, string $label): void
    {
        $got = self::boxHasInk($grid, $box, $threshold);
        self::assertSame($want, $got, "{$label}: 区域 " . json_encode($box) . ' 墨迹 = ' . var_export($got, true) . ', want ' . var_export($want, true));
    }
}
