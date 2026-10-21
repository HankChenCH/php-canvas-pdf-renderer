<?php

declare(strict_types=1);

namespace HankChen\CanvasNext\Renderer\Pdf\Tests\Support;

/**
 * 测试夹具：图片编码落盘。
 *  - PNG 走纯 PHP 编码（8bit colortype 2 非隔行——tFPDF _parsepng 支持面），
 *    测试面零 GD；
 *  - JPEG 无纯 PHP 编码器（与 go 侧 stdlib jpeg.Encode 同位置的空白），用 GD
 *    imagejpeg 编码——**GD 仅测试夹具编码出场**，包运行时零 GD 红线不动
 *    （composer require 无 ext-gd、src/ 零 GD 引用）；GD 缺席由 requireGd()
 *    markTestSkipped 留指引（镜像 go 侧 CJK 字体探针纪律）。
 *  - EXIF 夹具 = JPEG 主体 + 手工拼装 APP1 段（镜像 go jpegWithExifOrientation，
 *    字节布局逐项对齐）。
 */
final class Fixtures
{
    /** 纯色 PNG（路径） */
    public static function solidPng(int $width, int $height, array $rgb): string
    {
        return self::png($width, $height, static fn (int $x, int $y): array => $rgb);
    }

    /** 左半 one、右半 other 的双色 PNG，splitX 为分界列（对齐 go twoColorPng） */
    public static function twoColorPng(int $width, int $height, array $one, array $other, int $splitX): string
    {
        return self::png($width, $height, static fn (int $x, int $y): array => $x < $splitX ? $one : $other);
    }

    /** 上下两色带 PNG（遮罩采样源图，对齐 go bandPng） */
    public static function bandPng(int $width, int $height, array $top, array $bottom): string
    {
        $half = intdiv($height, 2);

        return self::png($width, $height, static fn (int $x, int $y): array => $y < $half ? $top : $bottom);
    }

    /**
     * 纯 PHP PNG 编码落盘（8bit / colortype 2 DeviceRGB / 无隔行），
     * $pixel(x, y) 返回 [r, g, b]
     */
    public static function png(int $width, int $height, \Closure $pixel): string
    {
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };
        $ihdr = pack('N2C5', $width, $height, 8, 2, 0, 0, 0);
        $raw = '';
        for ($y = 0; $y < $height; $y++) {
            $raw .= "\x00"; // 每行 filter byte 0（None）
            for ($x = 0; $x < $width; $x++) {
                $raw .= pack('C3', ...$pixel($x, $y));
            }
        }
        $png = "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', $ihdr)
            . $chunk('IDAT', (string) gzcompress($raw))
            . $chunk('IEND', '');

        return self::writeTemp('fixture.png', $png);
    }

    /** GD 缺席即 skip（JPEG 夹具编码腿专用；PNG 腿不依赖） */
    public static function requireGd(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            \PHPUnit\Framework\Assert::markTestSkipped(
                '本机 PHP 缺 ext-gd：JPEG 夹具编码腿不可跑（仅测试夹具用，包运行时零 GD）；装 GD 或设 CANVAS_PDF_TEST_CJK_FONT 类指引后重跑'
            );
        }
    }

    /** 纯色 JPEG（GD 编码，路径） */
    public static function solidJpeg(int $width, int $height, array $rgb): string
    {
        return self::gdJpeg($width, $height, static function ($im) use ($rgb): void {
            $fill = imagecolorallocate($im, ...$rgb);
            imagefill($im, 0, 0, (int) $fill);
        });
    }

    /**
     * 8×16 上红下蓝、带指定 EXIF orientation 的 JPEG 落盘
     * （镜像 go twoBandExifJpeg；八向标定夹具）
     */
    public static function twoBandExifJpeg(int $orientation, string $byteOrder = 'II', int $type = 3): string
    {
        return self::gdJpeg(8, 16, static function ($im) use ($orientation, $byteOrder, $type): void {
            $red = (int) imagecolorallocate($im, 255, 0, 0);
            $blue = (int) imagecolorallocate($im, 0, 0, 255);
            imagefill($im, 0, 0, $red);
            imagefilledrectangle($im, 0, 8, 7, 15, $blue);
        }, $orientation, $byteOrder, $type);
    }

    /** JPEG 主体 + 手工拼装 APP1（Exif）段：SOI 后插 FFE1 len "Exif\0\0" + TIFF */
    public static function gdJpeg(int $width, int $height, \Closure $paint, int $exifOrientation = 0, string $byteOrder = 'II', int $exifType = 3): string
    {
        self::requireGd();
        $im = imagecreatetruecolor($width, $height);
        $paint($im);
        ob_start();
        imagejpeg($im);
        $body = (string) ob_get_clean();
        imagedestroy($im);

        if ($exifOrientation === 0) {
            return self::writeTemp('fixture.jpg', $body);
        }

        $payload = "Exif\x00\x00" . self::tiffWithOrientation($byteOrder, $exifType, $exifOrientation);
        $segLen = strlen($payload) + 2; // 段长字段含自身 2 字节
        $out = "\xFF\xD8\xFF\xE1" . pack('n', $segLen) . $payload;

        return self::writeTemp('exif-fixture.jpg', $out . substr($body, 2)); // 去掉主体自身的 SOI
    }

    /**
     * 组装仅含 orientation 标签的 TIFF 块（IFD0 单条目，镜像 go tiffWithOrientation）：
     * $byteOrder 'II'(小端)|'MM'(大端)；$type 3=SHORT（值内联前 2 字节）/ 4=LONG
     */
    private static function tiffWithOrientation(string $byteOrder, int $type, int $orientation): string
    {
        $u16 = $byteOrder === 'II' ? 'v' : 'n';
        $u32 = $byteOrder === 'II' ? 'V' : 'N';
        $tiff = $byteOrder;
        $tiff .= pack($u16, 42);        // 魔数
        $tiff .= pack($u32, 8);         // IFD0 偏移紧随头部
        $tiff .= pack($u16, 1);         // 条目数
        $tiff .= pack($u16, 0x0112);    // Orientation 标签
        $tiff .= pack($u16, $type);     // 字段类型
        $tiff .= pack($u32, 1);         // 计数
        $tiff .= $type === 3
            ? pack($u16, $orientation) . pack($u16, 0)
            : pack($u32, $orientation);
        $tiff .= pack($u32, 0);         // 下一 IFD 偏移

        return $tiff;
    }

    private static function writeTemp(string $name, string $bytes): string
    {
        $path = sys_get_temp_dir() . '/canvas-pdf-fixture-' . uniqid() . '-' . $name;
        if (file_put_contents($path, $bytes) === false) {
            throw new \RuntimeException("写测试夹具失败: {$path}");
        }

        return $path;
    }
}
