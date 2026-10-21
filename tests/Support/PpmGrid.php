<?php

declare(strict_types=1);

namespace HankChen\CanvasNext\Renderer\Pdf\Tests\Support;

/**
 * P6 PPM 栅格（pdftoppm 缺省输出形态）的纯字节解析：头部 token 流 +
 * 逐像素 RGB 三元组。测试面零 GD 的栅格读取落点。
 */
final class PpmGrid
{
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        private readonly string $pixels,
        private readonly int $dataOffset,
    ) {
    }

    public static function parse(string $data): self
    {
        if (preg_match('/^P6\s+(\d+)\s+(\d+)\s+(\d+)\s/', $data, $m, PREG_OFFSET_CAPTURE) !== 1) {
            throw new \RuntimeException('非 P6 PPM：头部解析失败');
        }
        $width = (int) $m[1][0];
        $height = (int) $m[2][0];
        $maxval = (int) $m[3][0];
        // 数据起点 = 全匹配结尾（末段 \s = maxval 后的单个空白分隔字节，必须计入——
        // 漏掉它像素流错位 1 字节，纯色读出轮转色）
        $offset = $m[0][1] + strlen($m[0][0]);
        if ($maxval !== 255) {
            throw new \RuntimeException("非 8bit PPM：maxval = {$maxval}");
        }
        if (strlen($data) - $offset < $width * $height * 3) {
            throw new \RuntimeException('PPM 像素数据截断');
        }

        return new self($width, $height, $data, $offset);
    }

    /** @return array{int, int, int} [r, g, b] */
    public function pixelAt(int $x, int $y): array
    {
        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            throw new \OutOfBoundsException("像素 ({$x}, {$y}) 越界 {$this->width}x{$this->height}");
        }
        $o = $this->dataOffset + ($y * $this->width + $x) * 3;

        return [ord($this->pixels[$o]), ord($this->pixels[$o + 1]), ord($this->pixels[$o + 2])];
    }
}
