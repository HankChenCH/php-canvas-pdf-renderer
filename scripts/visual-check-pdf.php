<?php

declare(strict_types=1);

/**
 * 目验样张：tFPDF 子类综合能力一页出 .pdf + pdftoppm -r 96 预览图。
 *
 * 用法：php scripts/visual-check-pdf.php [输出.pdf]   （缺省 visual-check.pdf）
 * poppler 在场时附带预览 png；CJK 字体按测试同款探针（CANVAS_PDF_VISUAL_FONT 可覆写），
 * 全缺降级 Helvetica 只排 ASCII。
 */

require __DIR__ . '/../vendor/autoload.php';

use HankChen\CanvasNext\Renderer\Pdf\Tfpdf;

$out = $argv[1] ?? __DIR__ . '/../visual-check.pdf';

// 字体路径隔离（可写 unifont 有 metrics 缓存；核心字体 metric 文件复制就位）
$fontDir = sys_get_temp_dir() . '/canvas-pdf-renderer-visual-fonts';
if (!is_dir($fontDir . '/unifont')) {
    mkdir($fontDir . '/unifont', 0777, true);
    $vendorFont = dirname((new ReflectionClass(\tFPDF::class))->getFileName()) . '/font';
    foreach (glob($vendorFont . '/*.php') ?: [] as $f) {
        copy($f, $fontDir . '/' . basename($f));
    }
}
if (!defined('FPDF_FONTPATH')) {
    define('FPDF_FONTPATH', $fontDir . '/');
}

// CJK 探针：环境变量 → 系统 TTF 候选 → null（降级 Helvetica）
$cjk = getenv('CANVAS_PDF_VISUAL_FONT') ?: null;
if (!$cjk || !is_file($cjk)) {
    foreach (['/usr/share/fonts/truetype/ipafont-gothic/ipag.ttf', '/System/Library/Fonts/Supplemental/Arial Unicode.ttf'] as $candidate) {
        if (is_file($candidate)) {
            $cjk = $candidate;
            break;
        }
    }
}

// 注意：竖版('P')构造 [w,h] 时 w>h 会被构造器交换——横向宽页必须用 'L'
$pdf = new Tfpdf('L', 'pt', [400, 300]);
$pdf->SetCompression(false);
$pdf->SetAutoPageBreak(false);
$pdf->AddPage();

// 背景 + 标题块
$pdf->SetFillColor(255, 255, 255);
$pdf->Rect(0, 0, 400, 300, 'F');
$pdf->SetFillColor(31, 122, 205);
$pdf->Rect(0, 0, 400, 40, 'F');

if ($cjk) {
    $base = 'visual.ttf';
    copy($cjk, $fontDir . '/unifont/' . $base);
    $family = $pdf->safeFamilyName($base); // 族名安全合成，禁原始路径
    $pdf->AddFont($family, '', $base, true);
    $pdf->SetFont($family);
    $title = 'PDF 后端目验样张 · 证书';
} else {
    $pdf->SetFont('helvetica');
    $title = 'Canvas PDF Renderer (fallback Helvetica)';
}
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFontSize(16);
$pdf->Text(16, 26, $title);

// 椭圆三风格
$pdf->SetDrawColor(200, 60, 60);
$pdf->SetLineWidth(1.5);
$pdf->Ellipse(60, 110, 36, 24, 'D');
$pdf->SetFillColor(90, 180, 90);
$pdf->Ellipse(160, 110, 36, 24, 'F');
$pdf->SetDrawColor(60, 60, 160);
$pdf->SetFillColor(240, 200, 80);
$pdf->Ellipse(260, 110, 36, 24, 'FD');

// 椭圆裁切 + 旋转文本（先裁切，再变换内画格线，画完恢复）
$pdf->ClippingEllipse(340, 105, 34, 34);
$pdf->SetFillColor(225, 235, 245);
$pdf->Rect(306, 71, 68, 68, 'F');
$pdf->SetFillColor(120, 150, 190);
$pdf->StartTransform();
$pdf->Rotate(30, 340, 105);
for ($i = 0; $i < 6; $i++) {
    $pdf->Rect(300 + $i * 14, 60, 4, 90, 'F');
}
$pdf->StopTransform();
$pdf->UnsetClipping();

// alpha 叠层（画完恢复防残留）
$pdf->SetAlpha(0.45);
$pdf->SetFillColor(250, 140, 30);
$pdf->Rect(30, 160, 180, 80, 'F');
$pdf->SetFillColor(40, 160, 160);
$pdf->Rect(90, 190, 180, 80, 'F');
$pdf->SetAlpha(1);

// 文本三垂直对齐参考（基线公式演示）+ CJK 行
$pdf->SetTextColor(20, 20, 20);
$pdf->SetFontSize(12);
if ($cjk) {
    $pdf->Text(30, 280, '季度证书 · alpha 叠层 · 椭圆裁切 —— poppler 目验');
}
$pdf->SetFont('helvetica');
$pdf->Text(230, 290, 'Helvetica fallback 123');

$bytes = $pdf->Output('S');
file_put_contents($out, $bytes);
echo "PDF  : {$out} (" . strlen($bytes) . " bytes)\n";

// pdftoppm 预览（poppler 缺席跳过）
$ppm = 'pdftoppm';
if (shell_exec("command -v $ppm") === null) {
    echo "poppler 不在场，跳过预览（brew install poppler）\n";
    exit(0);
}
$prefix = preg_replace('/\.pdf$/', '', $out);
shell_exec(escapeshellcmd($ppm) . ' -r 96 -png ' . escapeshellarg($out) . ' ' . escapeshellarg($prefix));
foreach (glob($prefix . '-*.png') ?: [] as $png) {
    echo "预览 : {$png}\n";
}
