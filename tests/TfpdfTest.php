<?php

declare(strict_types=1);

namespace HankChen\CanvasNext\Renderer\Pdf\Tests;

use HankChen\CanvasNext\Renderer\Pdf\Tests\Support\PopplerPdfCase;
use HankChen\CanvasNext\Renderer\Pdf\Tfpdf;

/**
 * tFPDF 子类行为测试：四 addon 并入的操作符面（与 go-pdf/fpdf 逐字同源）+ 输出 header 版本
 * + desc getter + 族名安全合成。操作符断言直读未压缩内容流（SetCompression(false)）；
 * poppler 栅格级断言属渲染器本体票（本段=子类行为），spike 已用 poppler 四件套实测背书。
 * 继承 PopplerPdfCase 只为共享 CJK 探针（probeCjkFont）。
 */
final class TfpdfTest extends PopplerPdfCase
{
    /** 测试字体路径：隔离 fontpath（可写 unifont 有 metrics 缓存 + 核心字体 metric 文件在位） */
    private static string $fontDir;

    public static function setUpBeforeClass(): void
    {
        // 字体目录走运行时同一条装配路径（Tfpdf 构造期触发 fontDir()）：可写 unifont
        // + 核心字体 metric 在位 + FPDF_FONTPATH 定义，metrics 缓存不写进 vendor/
        self::$fontDir = Tfpdf::fontDir();
    }

    /** 96x96pt 素页（pt 单位 + 关压缩 = 内容流可直接断言；-r 96 栅格恰好 1:1 回像素） */
    private static function newDoc(): Tfpdf
    {
        $pdf = new Tfpdf('P', 'pt', [96, 96]);
        $pdf->SetCompression(false);
        $pdf->SetAutoPageBreak(false); // 契约：内容溢出不切页，与位图出界即裁同语义
        $pdf->AddPage();
        return $pdf;
    }

    private static function content(Tfpdf $pdf): string
    {
        $bytes = $pdf->Output('S');
        $pos = strpos($bytes, "stream\n");
        self::assertNotFalse($pos, '内容流缺位');
        $end = strpos($bytes, 'endstream', $pos);
        return substr($bytes, $pos + 7, $end - $pos - 7);
    }

    /** CJK 探针字体：共享探针在 PopplerPdfCase::probeCjkFont（env 优先 → 系统候选 → skip） */

    public function testSetAlphaEmitsExtGStateAndBumpsHeader(): void
    {
        $pdf = self::newDoc();
        $pdf->SetFillColor(255, 0, 0);
        $pdf->Rect(0, 0, 48, 96, 'F');
        $pdf->SetAlpha(0.5);
        $pdf->SetFillColor(0, 0, 0);
        $pdf->Rect(24, 24, 48, 48, 'F');
        $pdf->SetAlpha(1); // 画完恢复防残留
        $pdf->SetFillColor(0, 0, 255);
        $pdf->Rect(0, 0, 16, 16, 'F');
        $bytes = $pdf->Output('S');

        self::assertStringStartsWith('%PDF-1.4', $bytes, '用了 ExtGState 后 header 抬 1.4（addon _enddoc 语义）');
        self::assertStringContainsString('/GS1 gs', $bytes);
        self::assertStringContainsString('/GS2 gs', $bytes);
        self::assertStringContainsString('/ExtGState <<', $bytes, 'ExtGState 挂进页资源字典');
        self::assertStringContainsString('<</Type /ExtGState', $bytes);
        self::assertStringContainsString('/ca 0.5', $bytes);
        self::assertStringContainsString('/CA 0.5', $bytes);
        self::assertStringContainsString('/BM /Normal', $bytes);
        self::assertStringContainsString('0.000 0.000 1.000 rg', $bytes, '恢复后的蓝色标记块不受 alpha 残留');
    }

    public function testWithoutAlphaEmitsPlainDocument(): void
    {
        $pdf = self::newDoc();
        $pdf->SetFillColor(255, 0, 0);
        $pdf->Rect(0, 0, 48, 96, 'F');
        $bytes = $pdf->Output('S');

        self::assertStringStartsWith('%PDF-1.3', $bytes, 'tFPDF 缺省 header 1.3（与 go fpdf 缺省照发对齐）');
        self::assertStringNotContainsString('/gs', $bytes, '无 gs 操作符');
        self::assertStringNotContainsString('<</Type /ExtGState', $bytes, '无 ExtGState 状态对象（addon 原文会发射空 /ExtGState << >> 字典键，属忠实并入行为）');
    }

    public function testClippingRectRoundtrip(): void
    {
        $pdf = self::newDoc();
        $pdf->ClippingRect(10, 20, 30, 40);
        $pdf->SetFillColor(0, 0, 0);
        $pdf->Rect(0, 0, 96, 96, 'F');
        $pdf->UnsetClipping();

        self::assertStringContainsString('q 10.00 76.00 30.00 -40.00 re W n', self::content($pdf), 'q re W n 裁切开（与 go ClipRect 逐字同源）');
        self::assertStringContainsString("\nQ", self::content($pdf), 'Q 恢复裁切');
    }

    public function testClippingEllipseEmitsBezierAndOperator(): void
    {
        $pdf = self::newDoc();
        $pdf->ClippingEllipse(48, 48, 24, 12);

        $content = self::content($pdf);
        self::assertStringContainsString(' m ', $content);
        self::assertMatchesRegularExpression('# c W n$#m', $content, '四段贝塞尔闭合后 W n 裁切');
    }

    public function testTransformRotateWrapsUserSpace(): void
    {
        $pdf = self::newDoc();
        $pdf->StartTransform();
        $pdf->Rotate(90, 48, 48);
        $pdf->StopTransform();

        // q + cm 矩阵 + Q；90° 在 (48,48)：tm = [cos, sin, -sin, cos, x+tmy-tmx, y-tmy-tmx]
        self::assertMatchesRegularExpression('#^q\n0\.000 1\.000 -1\.000 0\.000 96\.000 -?0\.000 cm\nQ$#m', self::content($pdf), 'q cm Q 包裹，绕锚点矩阵正确');
    }

    public function testEllipseStyles(): void
    {
        foreach (['F' => 'f', 'D' => 'S', 'FD' => 'B'] as $style => $op) {
            $pdf = self::newDoc();
            $pdf->Ellipse(48, 48, 24, 12, $style);
            self::assertMatchesRegularExpression('# c ' . $op . '$#m', self::content($pdf), "椭圆 $style 风格发射 $op");
        }
    }

    public function testGetFontDescForTtfAndCore(): void
    {
        $font = self::probeCjkFont();
        $pdf = self::newDoc();
        $base = 'descprobe.ttf';
        copy($font, self::$fontDir . '/unifont/' . $base);
        $family = $pdf->safeFamilyName($base);
        $pdf->AddFont($family, '', $base, true);
        $pdf->SetFont($family);

        $desc = $pdf->getFontDesc($family);
        self::assertNotNull($desc, 'TTF（uni）字体有 per-1000 desc');
        self::assertIsFloat($desc['Ascent'], 'tFPDF round() 归 float');
        self::assertGreaterThan(0, $desc['Ascent']);
        self::assertIsFloat($desc['Descent']);
        self::assertLessThan(0, $desc['Descent'], 'descent 为负（基线公式 top/center/bottom 依赖）');

        self::assertNull($pdf->getFontDesc('helvetica'), '核心字体无 desc——调用侧走 718/-207 per-1000 常量兜底（与 go 同款）');
    }

    public function testSafeFamilyName(): void
    {
        $pdf = self::newDoc();
        $family = $pdf->safeFamilyName('/some/path/My Font.ttf');
        self::assertSame('f' . substr(hash('sha256', '/some/path/My Font.ttf'), 0, 16), $family, 'f + sha256(路径) 前 16 位 hex（镜像 go text.go）');
        self::assertSame(17, strlen($family));
        self::assertStringStartsWith('f', $family);
        self::assertStringNotContainsString('/', $family);
        self::assertStringNotContainsString(' ', $family, '族名无空格/斜杠——可安全写 PDF Name 对象');
    }

    public function testRawFontPathNeverEntersPdfBytes(): void
    {
        $font = self::probeCjkFont();
        $pdf = self::newDoc();
        $base = 'pathprobe.ttf';
        copy($font, self::$fontDir . '/unifont/' . $base);
        // 故意用路径形态的族名注册（族名只是注册键），断言它绝不落进 PDF 字节
        $rawFamily = '/should/never-appear 8a';
        $pdf->AddFont($rawFamily, '', $base, true);
        $pdf->SetFont($rawFamily);
        $pdf->SetFontSize(16);
        $pdf->Text(10, 40, '探针 probe');

        $bytes = $pdf->Output('S');
        self::assertStringNotContainsString('never-appear', $bytes, '族名参数不进 PDF 字节（tFPDF /BaseFont 来自字体内部 fullName）');
        self::assertStringNotContainsString('should', $bytes);
    }
}
