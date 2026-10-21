<?php

declare(strict_types=1);

namespace HankChen\CanvasNext\Renderer\Pdf;

/**
 * 2×3 仿射矩阵（y 向下坐标系，先 x 后 y 的行向量约定）：
 * x' = a·x + b·y + e；y' = c·x + d·y + f。像素空间参与运算，发射时统一 ×0.75。
 * （go-canvas/pdf-renderer image.go affine 的对位物；本端新增 inverse——
 * PHP 分派层给的 srcRect 是观察方向系，直嵌路径 T⁻¹ 逆映射回内在窗，
 * go 分派层直接给内在系 srcRect 故无此面）
 */
final class Affine
{
    public function __construct(
        public readonly float $a,
        public readonly float $b,
        public readonly float $c,
        public readonly float $d,
        public readonly float $e,
        public readonly float $f,
    ) {
    }

    /**
     * EXIF orientation 的恒距归正矩阵（y 向下，整幅内在图 sw×sh → 观察方向）。
     * 逐向平移 go-canvas/pdf-renderer image.go orientAffine（2/4 镜像、3 旋转 180°、
     * 6/8 旋转 90°、5/7 沿对角镜像）；orientation 1 及越界归一：恒等。
     */
    public static function orient(int $orientation, int $sw, int $sh): self
    {
        $w = (float) $sw;
        $h = (float) $sh;

        return match ($orientation) {
            2 => new self(-1, 0, 0, 1, $w, 0),
            3 => new self(-1, 0, 0, -1, $w, $h),
            4 => new self(1, 0, 0, -1, 0, $h),
            5 => new self(0, 1, 1, 0, 0, 0),    // 转置（主对角镜像）
            6 => new self(0, -1, 1, 0, $h, 0),  // 旋转 90° 顺时针
            7 => new self(0, -1, -1, 0, $h, $w),// 反转置（反对角镜像）
            8 => new self(0, 1, -1, 0, 0, $w),  // 旋转 90° 逆时针
            default => new self(1, 0, 0, 1, 0, 0),
        };
    }

    /** 复合：this ∘ other（先施加 other、后施加 this） */
    public function after(self $n): self
    {
        return new self(
            $this->a * $n->a + $this->b * $n->c,
            $this->a * $n->b + $this->b * $n->d,
            $this->c * $n->a + $this->d * $n->c,
            $this->c * $n->b + $this->d * $n->d,
            $this->a * $n->e + $this->b * $n->f + $this->e,
            $this->c * $n->e + $this->d * $n->f + $this->f,
        );
    }

    /** 点变换：返回 [x', y'] */
    public function apply(float $x, float $y): array
    {
        return [
            $this->a * $x + $this->b * $y + $this->e,
            $this->c * $x + $this->d * $y + $this->f,
        ];
    }

    /**
     * 逆映射（八向归正均为恒距变换，行列式 ±1 恒可逆；奇异阵属内部错误防线）。
     * x = (d·x' − b·y' − (d·e − b·f)) / det 等，按克莱姆法则展开。
     */
    public function inverse(): self
    {
        $det = $this->a * $this->d - $this->b * $this->c;
        if ($det === 0.0) {
            throw new \LogicException('仿射矩阵奇异，不可逆（orient 表八向均恒距，此处属内部错误）');
        }

        // 线性部分逆转置 / det；平移项 = −lin⁻¹·t
        $ia = $this->d / $det;
        $ib = -$this->b / $det;
        $ic = -$this->c / $det;
        $id = $this->a / $det;

        return new self(
            $ia,
            $ib,
            $ic,
            $id,
            -($ia * $this->e + $ib * $this->f),
            -($ic * $this->e + $id * $this->f),
        );
    }

    /**
     * 矩形（[minX, minY, maxX, maxY]）经仿射后的轴对齐包络（八向归正均为
     * 轴排列/翻转，四角即足）。返回同形态浮点数组。
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $rect
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function envelopeOf(array $rect): array
    {
        [$x0, $y0] = $this->apply($rect[0], $rect[1]);
        [$x1, $y1] = $this->apply($rect[2], $rect[3]);
        [$x2, $y2] = $this->apply($rect[0], $rect[3]);
        [$x3, $y3] = $this->apply($rect[2], $rect[1]);

        return [
            min($x0, $x1, $x2, $x3),
            min($y0, $y1, $y2, $y3),
            max($x0, $x1, $x2, $x3),
            max($y0, $y1, $y2, $y3),
        ];
    }
}
