<?php

declare(strict_types=1);

namespace HankChen\CanvasNext\Renderer\Pdf\Tests;

use HankChen\CanvasNext\Renderer\Pdf\Affine;
use PHPUnit\Framework\TestCase;

/**
 * 仿射小件单元标定：orientAffine 八向字母栅格（镜像 go TestOrientAffineAllDirections，
 * 期望栅格逐向一致）+ T⁻¹ 逆映射双锁（PHP 特有——分派层给观察方向窗，直嵌路径
 * T⁻¹ 逆映射回内在窗，逆阵是本端新面，须独立钉死）。
 */
class AffineTest extends TestCase
{
    public function testOrientAffineAllDirectionsLetterGrid(): void
    {
        // 3×2 字母栅格 A B C / D E F 按内在格 → 观察格方向（DrawImage 实际使用方向）
        // 重排后逐格比对；期望栅格 = go image_test.go 同一表
        $letters = 'ABCDEF';
        $cases = [
            1 => ['ABC/DEF', 3, 2],
            2 => ['CBA/FED', 3, 2],  // 水平镜像
            3 => ['FED/CBA', 3, 2],  // 旋转 180°
            4 => ['DEF/ABC', 3, 2],  // 垂直镜像
            5 => ['AD/BE/CF', 2, 3], // 转置（主对角镜像）
            6 => ['DA/EB/FC', 2, 3], // 旋转 90° 顺时针
            7 => ['FC/EB/DA', 2, 3], // 反转置（反对角镜像）
            8 => ['CF/BE/AD', 2, 3], // 旋转 90° 逆时针
        ];
        foreach ($cases as $orientation => [$want, $outW, $outH]) {
            $rows = explode('/', $want);
            $grid = array_fill(0, $outH, array_fill(0, $outW, '.'));
            $m = Affine::orient($orientation, 3, 2);
            for ($y = 0; $y < 2; $y++) {
                for ($x = 0; $x < 3; $x++) {
                    [$ox, $oy] = $m->apply($x + 0.5, $y + 0.5); // 格心采样，避边界歧义
                    $grid[(int) $oy][(int) $ox] = $letters[$y * 3 + $x];
                }
            }
            $this->assertSame($rows, array_map(
                static fn (array $row): string => implode('', $row),
                $grid
            ), "orientation {$orientation} 转正栅格不符");
        }
    }

    public function testInverseRoundTripAllDirections(): void
    {
        // T⁻¹ 双锁：观察格心经 T⁻¹ 落回原内在格心、内在格心经 T·T⁻¹ 恒等——
        // 逆阵是 PHP 观察方向窗适配（go 镜像没有的面）的正确性根
        for ($orientation = 1; $orientation <= 8; $orientation++) {
            $t = Affine::orient($orientation, 3, 2);
            $tinv = $t->inverse();
            for ($y = 0; $y < 2; $y++) {
                for ($x = 0; $x < 3; $x++) {
                    // 内在格心 → T → T⁻¹ 恒等
                    [$ox, $oy] = $t->apply($x + 0.5, $y + 0.5);
                    [$bx, $by] = $tinv->apply($ox, $oy);
                    $this->assertEqualsWithDelta($x + 0.5, $bx, 1e-9, "orientation {$orientation} T·T⁻¹ x 不恒等");
                    $this->assertEqualsWithDelta($y + 0.5, $by, 1e-9, "orientation {$orientation} T·T⁻¹ y 不恒等");
                }
            }
        }
    }

    public function testInverseMapsObservedWindowBackToIntrinsic(): void
    {
        // o=6（顺时针 90°）：内在 8×16 → 观察 16×8；观察方向左半窗 [0,0,8,8]
        // 经 T⁻¹ 应落回内在下带 [0,8]×[8,16]（观察左 = 内在底，与管线 EXIF 测试同语义）
        $t = Affine::orient(6, 8, 16);
        $region = $t->inverse()->envelopeOf([0.0, 0.0, 8.0, 8.0]);
        $this->assertEqualsWithDelta([0.0, 8.0, 8.0, 16.0], $region, 1e-9, '观察左半窗应逆映射回内在下带');
    }

    public function testAfterCompositionOrder(): void
    {
        // after 复合方向：P = W.after(T) 应为「先 T 后 W」——取 W=平移(10,5) 验证
        $t = Affine::orient(6, 8, 16);
        $w = new Affine(1, 0, 0, 1, 10, 5);
        $p = $w->after($t);
        [$x, $y] = $t->apply(2, 3);
        [$px, $py] = $p->apply(2, 3);
        $this->assertEqualsWithDelta($x + 10, $px, 1e-9);
        $this->assertEqualsWithDelta($y + 5, $py, 1e-9);
    }
}
