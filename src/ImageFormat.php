<?php

declare(strict_types=1);

namespace HankChen\CanvasNext\Renderer\Pdf;

/**
 * 直嵌格式防线（go-canvas/pdf-renderer decode.go 的对位物）：
 * 魔数嗅探定格式（扩展名不可信，物化缓存键来自 URL）+ 内在尺寸读取。
 * 格式面 PNG/JPEG 一等直嵌零重编码；**GIF 拒收**——tFPDF _parsegif 走 GD 违
 * 零 GD 红线（与 go 镜像的显式偏差：Go 的 GIF 直嵌是纯 Go 解码红利，不可平移；
 * 偏差已声明包 AGENTS.md）。非 PNG/JPEG 报错——对齐位图后端「垃圾字节报错
 * 中止」的结局 parity（适用域 = 不可解码字节必须报错中止、不得静默出图；
 * 反例域 = 错误文案与异常类型，两后端各出各的，不在契约内）。
 */
final class ImageFormat
{
    /** tFPDF Image() 的 type 形参取值（strtolower 后经 _parse{type} 分派） */
    public const PNG = 'png';
    public const JPG = 'jpg';

    private function __construct()
    {
    }

    /**
     * 按魔数嗅探直嵌格式；GIF 魔数显式拒收（防它漏进 tFPDF 的 GD 路径），
     * 其余不可识别字节报错中止。
     *
     * @return string 'png'|'jpg'
     */
    public static function sniff(string $data): string
    {
        if (str_starts_with($data, "\x89PNG\r\n\x1a\n")) {
            return self::PNG;
        }
        if (str_starts_with($data, "\xFF\xD8")) {
            return self::JPG;
        }
        if (str_starts_with($data, 'GIF87a') || str_starts_with($data, 'GIF89a')) {
            throw new \InvalidArgumentException(
                '图片格式 GIF 不受支持：本包零 GD 红线，GIF 不纳入直嵌（与 go 镜像的显式偏差），请转码 PNG/JPEG'
            );
        }

        throw new \InvalidArgumentException('无法识别的图片格式:仅支持 PNG/JPEG 直嵌');
    }

    /**
     * 解码图片头块取内在尺寸（未按 EXIF 转正的存储尺寸）。getimagesizefromstring
     * 属 ext/standard（非 GD），blit 的 srcRect 逆映射与整幅放置都以它为基准。
     *
     * @return array{0: int, 1: int} [width, height]
     */
    public static function intrinsicSize(string $data): array
    {
        $size = @getimagesizefromstring($data);
        if (!is_array($size) || $size[0] <= 0 || $size[1] <= 0) {
            throw new \RuntimeException('解码图片失败：无法读取内在尺寸');
        }

        return [(int) $size[0], (int) $size[1]];
    }
}
