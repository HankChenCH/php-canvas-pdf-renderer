<?php

declare(strict_types=1);

namespace HankChen\CanvasNext\Renderer\Pdf\Tests;

use HankChen\CanvasNext\Renderer\Pdf\Exif;
use PHPUnit\Framework\TestCase;

/**
 * EXIF orientation 最小解析器单元矩阵（平移 go exif.go 同构测试面）：
 * II/MM × SHORT/LONG 全八向、非 JPEG、无 EXIF、截断、SOS 前置、坏魔数、
 * 值域外 fail-safe 归 1。
 */
class ExifTest extends TestCase
{
    public function testParsesAllEightOrientationsBothByteOrdersBothTypes(): void
    {
        // 8 向 × II/MM × SHORT/LONG 全组合（go tiffWithOrientation 同一拼装面）
        foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $orientation) {
            foreach (['II', 'MM'] as $bo) {
                foreach ([3, 4] as $type) {
                    $jpeg = $this->jpegWithTiff($bo, $type, $orientation);
                    $this->assertSame($orientation, Exif::orientation($jpeg), "o={$orientation} bo={$bo} type={$type}");
                }
            }
        }
    }

    public function testNonJpegReturnsOne(): void
    {
        // 非 JPEG（PNG 等不携带该 EXIF 结构）归 1
        $png = "\x89PNG\r\n\x1a\n" . str_repeat("\0", 24);
        $this->assertSame(1, Exif::orientation($png));
        $this->assertSame(1, Exif::orientation(''));
    }

    public function testJpegWithoutExifReturnsOne(): void
    {
        // 无 APP1(Exif) 段的普通 JPEG（SOI + APP0(JFIF) + 假 SOF）归 1
        $jpeg = "\xFF\xD8\xFF\xE0" . pack('n', 16) . "JFIF\0" . "\0\1\2\0\0\1\0\1\0\0"
            . "\xFF\xC0" . pack('n', 17) . str_repeat("\0", 15);
        $this->assertSame(1, Exif::orientation($jpeg));
    }

    public function testTruncatedSegmentReturnsOne(): void
    {
        // 段长声明超出字节流（截断）fail-safe 归 1
        $payload = "Exif\x00\x00" . $this->tiff('II', 3, 6);
        $jpeg = "\xFF\xD8\xFF\xE1" . pack('n', strlen($payload) + 200) . $payload;
        $this->assertSame(1, Exif::orientation($jpeg));
    }

    public function testSegmentLengthBelowTwoReturnsOne(): void
    {
        $jpeg = "\xFF\xD8\xFF\xE1" . pack('n', 1) . "Exif\0\0xxxx";
        $this->assertSame(1, Exif::orientation($jpeg));
    }

    public function testSosBeforeExifReturnsOne(): void
    {
        // SOS（熵编码开始）先于 APP1：EXIF 只会更靠前，归 1
        $jpeg = "\xFF\xD8\xFF\xDA" . pack('n', 8) . str_repeat("\0", 6)
            . "\xFF\xE1" . pack('n', 12) . "Exif\0\0" . $this->tiff('II', 3, 6);
        $this->assertSame(1, Exif::orientation($jpeg));
    }

    public function testBadTiffMagicReturnsOne(): void
    {
        $payload = "Exif\x00\x00" . 'XX' . pack('n', 42) . pack('V', 8);
        $jpeg = "\xFF\xD8\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
        $this->assertSame(1, Exif::orientation($jpeg));
    }

    public function testBadTiffVersionReturnsOne(): void
    {
        $payload = "Exif\x00\x00" . 'II' . pack('v', 43) . pack('V', 8);
        $jpeg = "\xFF\xD8\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
        $this->assertSame(1, Exif::orientation($jpeg));
    }

    public function testOutOfRangeOrientationReturnsOne(): void
    {
        // 值域外（0 / 9）fail-safe 归 1
        foreach ([0, 9, 65535] as $bad) {
            $jpeg = $this->jpegWithTiff('II', 3, $bad);
            $this->assertSame(1, Exif::orientation($jpeg), "orientation {$bad} 应 fail-safe 归 1");
        }
    }

    public function testIfdOffsetOutOfRangeReturnsOne(): void
    {
        $tiff = 'II' . pack('v', 42) . pack('V', 999999);
        $payload = "Exif\x00\x00" . $tiff;
        $jpeg = "\xFF\xD8\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
        $this->assertSame(1, Exif::orientation($jpeg));
    }

    public function testOtherTagsSkipped(): void
    {
        // IFD0 里 Orientation 之前带其他条目（Make 字符串形态）应跳过继续找
        $u16 = 'v';
        $u32 = 'V';
        $tiff = 'II' . pack($u16, 42) . pack($u32, 8);
        $tiff .= pack($u16, 2);
        $tiff .= pack($u16, 0x010F) . pack($u16, 2) . pack($u32, 4) . pack($u32, 0x00000020); // Make, ASCII, offset
        $tiff .= pack($u16, 0x0112) . pack($u16, 3) . pack($u32, 1) . pack($u16, 6) . pack($u16, 0);
        $tiff .= pack($u32, 0);
        $tiff .= 'ACME';
        $jpeg = "\xFF\xD8\xFF\xE1" . pack('n', strlen("Exif\0\0" . $tiff) + 2) . "Exif\0\0" . $tiff;
        $this->assertSame(6, Exif::orientation($jpeg));
    }

    /** SOI + APP1(Exif) 的最小 JPEG 字节流（不嵌真实熵编码——解析器只走段头） */
    private function jpegWithTiff(string $bo, int $type, int $orientation): string
    {
        $payload = "Exif\x00\x00" . $this->tiff($bo, $type, $orientation);

        return "\xFF\xD8\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
    }

    /** IFD0 单条目 Orientation 的最小 TIFF 块（镜像 go tiffWithOrientation） */
    private function tiff(string $bo, int $type, int $orientation): string
    {
        $u16 = $bo === 'II' ? 'v' : 'n';
        $u32 = $bo === 'II' ? 'V' : 'N';
        $tiff = $bo . pack($u16, 42) . pack($u32, 8) . pack($u16, 1);
        $tiff .= pack($u16, 0x0112) . pack($u16, $type) . pack($u32, 1);
        $tiff .= $type === 3 ? pack($u16, $orientation) . pack($u16, 0) : pack($u32, $orientation);
        $tiff .= pack($u32, 0);

        return $tiff;
    }
}
