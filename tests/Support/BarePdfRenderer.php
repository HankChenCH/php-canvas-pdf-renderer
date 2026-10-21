<?php

declare(strict_types=1);

namespace HankChen\CanvasNext\Renderer\Pdf\Tests\Support;

use HankChen\CanvasNext\Renderer\Pdf\PdfRenderer;

/**
 * 原语级测试缝：PdfRenderer 为 final（与位图后端 ImageRenderer 同形），经
 * Closure::bind 把作用域绑进 PdfRenderer 直调 protected 原语面——防线/会话
 * 语义测试直驱后端（镜像 go 侧 r.Begin/r.DrawRect 直调形态；管线级测试走
 * PopplerPdfCase::renderCanvas/renderLayer）。不含任何逻辑。
 */
final class BarePdfRenderer
{
    private PdfRenderer $renderer;

    /** \Closure(string $method, mixed ...$args): mixed，作用域绑在 PdfRenderer 上 */
    private \Closure $invoke;

    public function __construct()
    {
        $this->renderer = new PdfRenderer();
        $this->invoke = \Closure::bind(
            function (string $method, mixed ...$args): mixed {
                /** @var PdfRenderer $this 此闭包作用域为 PdfRenderer，protected 面可直调 */
                return $this->{$method}(...$args);
            },
            $this->renderer,
            PdfRenderer::class
        );
    }

    public function begin(int $width, int $height): void
    {
        ($this->invoke)('begin', $width, $height);
    }

    public function end(): string
    {
        return (string) ($this->invoke)('end');
    }

    public function drawRect(int $x, int $y, int $width, int $height, ?string $bgColor, array $border): void
    {
        ($this->invoke)('drawRect', $x, $y, $width, $height, $bgColor, $border);
    }

    public function drawImage(string $src, int $sx, int $sy, int $sw, int $sh, int $dx, int $dy, int $dw, int $dh): void
    {
        ($this->invoke)('drawImage', $src, $sx, $sy, $sw, $sh, $dx, $dy, $dw, $dh);
    }

    public function drawText(
        string $line,
        int $x,
        int $y,
        string $fontFile,
        int $fontSize,
        string $fontColor,
        string $horizontalAlign,
        string $verticalAlign,
        int $angle
    ): void {
        ($this->invoke)('drawText', $line, $x, $y, $fontFile, $fontSize, $fontColor, $horizontalAlign, $verticalAlign, $angle);
    }

    /** 形状能力口原语本就 public，直接透传 */
    public function drawEllipse(int $x, int $y, int $width, int $height, ?string $fillColor, ?array $stroke): void
    {
        $this->renderer->drawEllipse($x, $y, $width, $height, $fillColor, $stroke);
    }

    public function drawImageMasked(
        string $src,
        int $sx,
        int $sy,
        int $sw,
        int $sh,
        int $dx,
        int $dy,
        int $dw,
        int $dh,
        int $maskX,
        int $maskY,
        int $maskW,
        int $maskH
    ): void {
        $this->renderer->drawImageMasked($src, $sx, $sy, $sw, $sh, $dx, $dy, $dw, $dh, $maskX, $maskY, $maskW, $maskH);
    }
}
