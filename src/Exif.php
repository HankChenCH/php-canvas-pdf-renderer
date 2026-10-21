<?php

declare(strict_types=1);

namespace HankChen\CanvasNext\Renderer\Pdf;

/**
 * EXIF orientation 最小解析器（逐行平移 go-canvas/pdf-renderer exif.go——
 * 开放点 5 定稿：不用 ext-exif，字节级自持）。
 *
 * JPEG 的 APP1（Exif）段携带拍摄方向，放置前需归正到观察方向。只找 IFD0 的
 * Orientation 标签（0x0112），其余结构一概不碰。
 *
 * 形态定稿理由（工票 Comments 记录）：① 与直嵌路径共享同一次读盘字节，零二次
 * I/O；② 八向 × II/MM × SHORT/LONG 解析矩阵可平移 go fixtures 单元锁定（ext-exif
 * 是黑盒扩展，无此单测面）；③ 零扩展依赖 = 真应用层自持（ext-exif 缺席不影响行为）。
 * 与核心分派层 intrinsicSize 的 ext-exif 读取同字段同值域（1–8 整数面，值域外
 * 双侧同向 fail-safe 归 1），T⁻¹ 适配与分派几何同系。
 */
final class Exif
{
    private function __construct()
    {
    }

    /**
     * 从图片字节流解析 EXIF orientation（1–8）。
     * 非 JPEG、无 EXIF、结构截断/非法一律归 1（无需转正）。
     */
    public static function orientation(string $data): int
    {
        if (strlen($data) < 4 || $data[0] !== "\xFF" || $data[1] !== "\xD8") {
            return 1; // 非 JPEG（PNG/GIF 不携带该 EXIF 结构）
        }
        $pos = 2;
        $len = strlen($data);
        while ($pos + 4 <= $len && $data[$pos] === "\xFF") {
            $marker = $data[$pos + 1];
            if ($marker === "\xFF") { // 标记前的填充字节，步进一位重新判读
                $pos++;
                continue;
            }
            if ($marker === "\xD8" || $marker === "\x01" || $marker === "\xD9") { // SOI/TEM/EOI 无段载荷
                $pos += 2;
                continue;
            }
            $segLen = (ord($data[$pos + 2]) << 8) | ord($data[$pos + 3]);
            if ($segLen < 2 || $pos + 2 + $segLen > $len) {
                return 1; // 截断或非法段长
            }
            if ($marker === "\xDA") { // SOS：熵编码开始，EXIF 只会更靠前
                return 1;
            }
            if ($marker === "\xE1" && $segLen >= 8 && substr($data, $pos + 4, 6) === "Exif\x00\x00") {
                return self::parseTiffOrientation(substr($data, $pos + 10, $segLen - 8));
            }
            $pos += 2 + $segLen;
        }

        return 1;
    }

    /** 从 TIFF 头起解析 IFD0 的 Orientation 值（SHORT/LONG 均兼容） */
    private static function parseTiffOrientation(string $tiff): int
    {
        if (strlen($tiff) < 8) {
            return 1;
        }
        $bo = substr($tiff, 0, 2);
        $u16 = $bo === 'II' ? 'v' : ($bo === 'MM' ? 'n' : null);
        if ($u16 === null) {
            return 1;
        }
        $u32 = $bo === 'II' ? 'V' : 'N';
        $v16 = unpack($u16, substr($tiff, 2, 2))[1];
        if ($v16 !== 42) {
            return 1;
        }
        $ifd0 = unpack($u32, substr($tiff, 4, 4))[1];
        if ($ifd0 < 8 || $ifd0 + 2 > strlen($tiff)) {
            return 1;
        }
        $entries = unpack($u16, substr($tiff, $ifd0, 2))[1];
        for ($i = 0; $i < $entries; $i++) {
            $e = $ifd0 + 2 + $i * 12;
            if ($e + 12 > strlen($tiff)) {
                return 1;
            }
            if (unpack($u16, substr($tiff, $e, 2))[1] !== 0x0112) { // Orientation
                continue;
            }
            // 字段类型：3 SHORT / 4 LONG，值内联于条目
            $type = unpack($u16, substr($tiff, $e + 2, 2))[1];
            $v = match ($type) {
                3 => unpack($u16, substr($tiff, $e + 8, 2))[1],
                4 => unpack($u32, substr($tiff, $e + 8, 4))[1],
                default => 0,
            };
            if ($v >= 1 && $v <= 8) {
                return $v;
            }

            return 1;
        }

        return 1;
    }
}
