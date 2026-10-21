<?php

declare(strict_types=1);

namespace HankChen\CanvasNext\Renderer\Pdf;

use HankChen\CanvasNext\Contracts\ShapeCapableRenderer;
use HankChen\CanvasNext\ResourceManagers\ResourceResolver;
use HankChen\CanvasNext\Renderer\AbstractRenderer;

/**
 * PDF 渲染后端：以 tFPDF 新建单文档为渲染面，实现五个绘制原语接入渲染模板；
 * 承载 PNG/JPEG 魔数嗅探一等直嵌（零重编码；GIF 拒收）、clip 算子裁窗 + EXIF
 * 放置矩阵归正、TTF 子集内嵌文本（完整语义落文本票，本段最小接线）。
 * 对位 `php-canvas-image-renderer` 位图后端、语义镜像 `go-canvas/pdf-renderer`。
 *
 * 单位契约：核心接口坐标恒为抽象像素，px→pt 换算责任全在后端（96dpi 口径，
 * pt = px × 0.75）——页 MediaBox、原语坐标、字号、线宽一律 ×0.75，文档单位设 pt。
 *
 * 会话语义（镜像 go Renderer）：实例即一个 PDF 文档会话——首次 begin 建档并建
 * 首页（关自动分页：绘制坐标全由模板给足，内容溢出不切页，与位图后端出界即裁
 * 同语义），文档已在则以本页尺寸续页；end = 防御复查 → 内存输出字节串 → 文档
 * 对象与字体注册表一并复位，同一实例再次渲染必然得到干净新文档。**非并发安全**：
 * tFPDF 实例按渲染会话持有，并发渲染请各自 new。
 *
 * 失败语义：任一绘制步失败即抛、end 不达、无半成品文档逃逸（ADR 0005 同款）。
 */
final class PdfRenderer extends AbstractRenderer implements ShapeCapableRenderer
{
    /** px→pt 换算系数（96dpi 口径：pt = px × 72/96） */
    private const PX_TO_PT = 0.75;

    private ?Tfpdf $doc = null;

    /** 渲染面宽（px，页面几何换算基准） */
    private int $width = 0;

    private int $height = 0;

    /**
     * 已注册字体表（路径 → 合成字体族名）：多行文本逐行 drawText，同一路径只
     * 读盘/注册一次；随文档会话走，end 复位（完整消费在文本票）
     *
     * @var array<string, string>
     */
    private array $fonts = [];

    public function __construct(?ResourceResolver $resolver = null)
    {
        parent::__construct($resolver ?? new ResourceResolver());
    }

    /**
     * 渲染面未建的用法错误防线（模板保证先 begin，防御直调）
     */
    private function doc(): Tfpdf
    {
        if ($this->doc === null) {
            throw new \LogicException('PDF 渲染面未初始化:应经渲染模板 begin 建面');
        }

        return $this->doc;
    }

    /**
     * 首次调用新建单文档（页尺寸 = px × 0.75 pt，MediaBox 与编译期页几何同口径）
     * 并建第一页；文档已在（多页入口逐页 begin）则以本页尺寸续页。
     * 关闭自动分页：内容溢出不切页（与位图后端出界即裁同语义）。
     * 横向宽页（wPt > hPt）必须以 'L' 装载——tFPDF 竖版('P')构造会把 [w,h] 中
     * w>h 交换（包 AGENTS.md 几何陷阱①），'L' 形态下 _getpagesize 归一后回翻
     * 恰好还原目标宽高。
     */
    protected function begin(int $width, int $height): void
    {
        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException("渲染面尺寸非法: {$width}x{$height}");
        }
        $wPt = $width * self::PX_TO_PT;
        $hPt = $height * self::PX_TO_PT;
        $orientation = $wPt > $hPt ? 'L' : 'P';
        if ($this->doc === null) {
            $doc = new Tfpdf($orientation, 'pt', [$wPt, $hPt]);
            $doc->SetAutoPageBreak(false);
            $doc->AddPage();
            $this->doc = $doc;
        } else {
            $this->doc->AddPage($orientation, [$wPt, $hPt]);
        }
        $this->width = $width;
        $this->height = $height;
    }

    /**
     * 全程内存构建，Output 到字符串即 PDF 产物（ADR 0010：产物 = 字节流）；
     * 未建面返回空串（go nil 接口对位物；真实产物恒以 %PDF 头起始，空串无歧义）。
     * 绘制期任一原语报错时模板即中止、end 不达（失败路径无产物逃逸）；此处仍
     * 防御性复查——tFPDF 错误即抛无 sticky 位（与 go fpdf Err() 形态不同），Output
     * 期异常兜底为空串，宁可无产物不可出半成品。
     * end 一次性终结文档会话：无论产出与否，doc 与字体注册表一并复位——begin 的
     * 「文档已在则续页」分支只应活在单次渲染会话内，end 后实例复用必须从建档起步
     * （复用三轮锁定测试钉死，PdfBackendTest::testRendererReuseAfterEndStartsFreshDocument）。
     */
    protected function end(): string
    {
        if ($this->doc === null) {
            return '';
        }
        $doc = $this->doc;
        $this->doc = null;  // 会话复位：再次 begin 即建档新文档
        $this->fonts = [];  // 字体注册随文档会话走：族名映射只对当前文档有意义

        try {
            return (string) $doc->Output('S');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * 绘制矩形盒：背景色 + 四边边框。
     * 背景为 null 或空串跳过填充（位图后端同款守卫：非 null 且非空串才填充）；
     * 边框 = 盒内嵌色带（非描边矩形），序 top/bottom/left/right，逐边可 null、
     * width≤0 的边不计（语义对齐 image-renderer borderBands，PHP 绘制序）。
     *
     * @param array{top: ?array, bottom: ?array, left: ?array, right: ?array} $border
     */
    protected function drawRect(int $x, int $y, int $width, int $height, ?string $bgColor, array $border): void
    {
        $doc = $this->doc();

        if ($bgColor !== null && $bgColor !== '') {
            $this->paintRect($doc, $x, $y, $width, $height, Rgba::parse($bgColor));
        }

        foreach ($this->borderBands($x, $y, $width, $height, $border) as [$bx, $by, $bw, $bh, $rgba]) {
            $this->paintRect($doc, $bx, $by, $bw, $bh, $rgba);
        }
    }

    /**
     * 以像素坐标画一个实心矩形（内部 ×0.75 转 pt）；半透明色经 ExtGState 挂
     * alpha，画完恢复不透明——SetAlpha 不包 q/Q 状态残留，后续元素不吃残留
     * （文本/下一色块；spike 实测钉定）
     */
    private function paintRect(Tfpdf $doc, int $x, int $y, int $w, int $h, Rgba $c): void
    {
        $doc->SetAlpha($c->a / 255, 'Normal');
        $doc->SetFillColor($c->r, $c->g, $c->b);
        $doc->Rect(
            $x * self::PX_TO_PT,
            $y * self::PX_TO_PT,
            $w * self::PX_TO_PT,
            $h * self::PX_TO_PT,
            'F'
        );
        $doc->SetAlpha(1, 'Normal');
    }

    /**
     * 四条边的线宽带，序为 top/bottom/left/right（PHP 绘制序，后画覆盖先画）；
     * 未设或 width≤0（wire 解码可注入）的边不计。yield 形态
     * [x, y, width, height, Rgba]。与 image-renderer / go borderBands 同一公式的
     * 小件复制——几何契约的一部分，多后端防线必须对称（跨端矩阵平移纪律）
     *
     * @param array{top: ?array, bottom: ?array, left: ?array, right: ?array} $border
     * @return \Generator<int, array{0: int, 1: int, 2: int, 3: int, 4: Rgba}>
     */
    private function borderBands(int $x, int $y, int $width, int $height, array $border): \Generator
    {
        $s = $border['top'] ?? null;
        if ($s !== null && $s['width'] > 0) {
            yield [$x, $y, $width, (int) $s['width'], Rgba::parse($s['color'])];
        }
        $s = $border['bottom'] ?? null;
        if ($s !== null && $s['width'] > 0) {
            yield [$x, max($y, $y + $height - (int) $s['width']), $width, (int) $s['width'], Rgba::parse($s['color'])];
        }
        $s = $border['left'] ?? null;
        if ($s !== null && $s['width'] > 0) {
            yield [$x, $y, (int) $s['width'], $height, Rgba::parse($s['color'])];
        }
        $s = $border['right'] ?? null;
        if ($s !== null && $s['width'] > 0) {
            yield [max($x, $x + $width - (int) $s['width']), $y, (int) $s['width'], $height, Rgba::parse($s['color'])];
        }
    }

    /**
     * 绘制图片（双矩形 blit，ADR 0024）：把源图中 (sx, sy) 起的 sw×sh 矩形拉伸
     * 搬运至目标 (dx, dy) 处的 dw×dh 矩形。srcRect 坐标系 = **观察方向**（分派层
     * intrinsicSize 按观察方向给出，07 票裁决）——直嵌路径经 T⁻¹ 逆映射回内在窗，
     * EXIF 八向放置矩阵归正（orientation 是恒距变换，「观察窗 → T⁻¹ → 内在窗 →
     * T → 放置」与位图后端「裁窗后转正像素」在单页放置面上语义等价；像素插值 vs
     * 矢量缩放的采样差异不在契约内）。sw 或 sh ≤ 0 为整图哨兵：取内在整幅；
     * dw/dh ≤ 0 后端防护不画。防御：源窗与解码图不相交时静默不画。
     */
    protected function drawImage(string $src, int $sx, int $sy, int $sw, int $sh, int $dx, int $dy, int $dw, int $dh): void
    {
        $this->embedBlit($src, $sx, $sy, $sw, $sh, $dx, $dy, $dw, $dh, function (Tfpdf $doc) use ($dx, $dy, $dw, $dh): void {
            // 裁切 = clip 算子裁 dstRect；裁剪施加在变换之前（双空间纪律：裁剪
            // 入参恒 tFPDF 用户空间 y 向下，内部自换算）
            $doc->ClippingRect(
                $dx * self::PX_TO_PT,
                $dy * self::PX_TO_PT,
                $dw * self::PX_TO_PT,
                $dh * self::PX_TO_PT,
                false
            );
        });
    }

    /**
     * 掩码 blit 原语（ADR 0026，drawImage 的掩码形态）：双矩形 blit（语义与
     * drawImage 完全一致，含 sw/sh ≤ 0 整图哨兵）后以 (maskX, maskY) 起的
     * maskW×maskH 外接矩形的内切椭圆为掩码裁切，掩码外不画——分派层传正方形
     * 即圆。矢量承接 = ClipEllipse 替位既有 ClipRect，直嵌零重编码与放置矩阵
     * 机制全复用；掩码矩形是画布绝对坐标，与目标矩形独立，裁剪路径在未入放置
     * 矩阵的用户空间施加。dw/dh/maskW/maskH ≤ 0 防护不画。
     */
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
        if ($dw <= 0 || $dh <= 0 || $maskW <= 0 || $maskH <= 0) {
            return;
        }
        [$cx, $cy, $rx, $ry] = self::inscribedEllipse($maskX, $maskY, $maskW, $maskH);
        $this->embedBlit($src, $sx, $sy, $sw, $sh, $dx, $dy, $dw, $dh, static function (Tfpdf $doc) use ($cx, $cy, $rx, $ry): void {
            $doc->ClippingEllipse($cx, $cy, $rx, $ry, false);
        });
    }

    /**
     * 直嵌 blit 共享主体（drawImage/drawImageMasked 同源）：读取 → 魔数嗅探 →
     * 内在尺寸 → 观察方向源窗 T⁻¹ 逆映射回内在窗 → 放置矩阵 P 算定 → 直嵌 →
     * 调用臂给定的裁剪原语内发射放置矩阵与整幅图。裁剪闭包在 StartTransform
     * 之前调用——双空间纪律：tFPDF 裁剪/变换入参恒用户空间，矩阵换算归
     * placeAffine。
     */
    private function embedBlit(
        string $src,
        int $sx,
        int $sy,
        int $sw,
        int $sh,
        int $dx,
        int $dy,
        int $dw,
        int $dh,
        \Closure $clip
    ): void {
        $doc = $this->doc();

        $data = @file_get_contents($src);
        if ($data === false) {
            throw new \RuntimeException("读取图片 {$src} 失败");
        }
        $type = ImageFormat::sniff($data);
        [$iw, $ih] = ImageFormat::intrinsicSize($data);
        // T = EXIF 八向归正矩阵（内在 → 观察方向）；orientation 1 恒等，零开销
        $T = Affine::orient(Exif::orientation($data), $iw, $ih);

        // 观察方向窗 → T⁻¹ 逆映射回内在窗（整图哨兵 = 内在全幅）；与内在边界
        // 求交，不相交静默不画（分派层几何保证不发生，wire 可注入）
        $bounds = [0.0, 0.0, (float) $iw, (float) $ih];
        if ($sw <= 0 || $sh <= 0) {
            $region = $bounds;
        } else {
            $region = $T->inverse()->envelopeOf([
                (float) $sx,
                (float) $sy,
                (float) ($sx + $sw),
                (float) ($sy + $sh),
            ]);
            $region = [
                max($region[0], $bounds[0]),
                max($region[1], $bounds[1]),
                min($region[2], $bounds[2]),
                min($region[3], $bounds[3]),
            ];
            if ($region[2] - $region[0] <= 0 || $region[3] - $region[1] <= 0) {
                return; // 源窗与解码图不相交：静默
            }
        }

        // 放置矩阵 P = W ∘ T（先 EXIF 归正、后窗映射）：box = 内在窗在观察方向的
        // 包络，W 把 box 拉伸平移到 dstRect
        $box = $T->envelopeOf($region);
        $sxScale = $dw / ($box[2] - $box[0]);
        $syScale = $dh / ($box[3] - $box[1]);
        $W = new Affine(
            $sxScale, 0, 0, $syScale,
            (float) $dx - $sxScale * $box[0],
            (float) $dy - $syScale * $box[1],
        );
        $P = $W->after($T);

        // 同名源按名去重：tFPDF Image() 以路径串为键，tile 多块引用同一源只嵌
        // 一份（对象级断言：PdfImageTest tile 测试数 /Subtype /Image）
        $clip($doc);
        $doc->StartTransform();
        $this->placeAffine($doc, $P);
        // 整幅图按内在尺寸入坐标（矩阵负责裁窗与映射），放置点恒 (0,0) 不为负
        try {
            $doc->Image($src, 0, 0, $iw * self::PX_TO_PT, $ih * self::PX_TO_PT, $type);
        } catch (\Exception $e) {
            // 嗅探已过而 tFPDF 解析仍失败（真损坏图）：包一层可读上下文再抛，
            // 失败即抛结局不变
            throw new \RuntimeException("绘制图片 {$src}: {$e->getMessage()}", 0, $e);
        }
        $doc->StopTransform();
        $doc->UnsetClipping();
    }

    /**
     * 矩形的内切椭圆（tFPDF ClippingEllipse 入参形态：圆心 + 半轴）。
     * 像素空间算定后统一 ×0.75 发射——掩码 = 椭圆内切于给定矩形的唯一几何落点
     *
     * @return array{0: float, 1: float, 2: float, 3: float} [cx, cy, rx, ry]
     */
    private static function inscribedEllipse(int $x, int $y, int $w, int $h): array
    {
        $cx = ($x + $w / 2) * self::PX_TO_PT;
        $cy = ($y + $h / 2) * self::PX_TO_PT;

        return [$cx, $cy, $w / 2 * self::PX_TO_PT, $h / 2 * self::PX_TO_PT];
    }

    /**
     * 发射放置矩阵：像素空间（y 向下）仿射换算为 PDF 原生坐标系（y 向上，pt）
     * 的 cm 矩阵后叠加到当前变换。推导：原生/像素空间的轴翻转 F(x, y) = (x, H−y)
     * （H = 页高 pt）夹逼 M = F ∘ P ∘ F，展开得
     *
     *   A = a   B = −c   C = −b   D = d
     *   E = b·H + e   F = H·(1−d) − f   （e, f 已 ×pxToPt）
     *
     * 恒等阵自检：M = I；镜像/旋转用例由 EXIF 八向栅格测试锁定。
     * （公式逐项平移 go-canvas/pdf-renderer image.go placeAffine）
     */
    private function placeAffine(Tfpdf $doc, Affine $m): void
    {
        $H = $this->height * self::PX_TO_PT;
        $doc->Transform([
            $m->a,
            -$m->c,
            -$m->b,
            $m->d,
            $m->b * $H + $m->e * self::PX_TO_PT,
            $H * (1 - $m->d) - $m->f * self::PX_TO_PT,
        ]);
    }

    /**
     * 椭圆绘制原语（ADR 0026）：在 (x, y) 起的 width×height 外接矩形内画内切
     * 椭圆，fillColor 填充 + stroke 圆周描边一体承接（圆形档的背景填充与描边环
     * 同原语两调，双非空即 "FD" 一发）。width/height ≤ 0 防护不画；stroke.width
     * ≤ 0 不描（wire 解码可注入，与 borderBands 同纪律）。pxToPt 口径：外接矩形
     * → 圆心/半轴、线宽换算后统一 ×0.75，tFPDF Ellipse 入参恒用户空间（与
     * ClipRect/Transform 家族同一双空间纪律）。颜色先解析后挂载——错误路径不
     * 残留任何文档状态。
     * 已知限制：tFPDF 的 ExtGState alpha 对单次 "FD" 的填充与描边一体生效，两色
     * alpha 不同时后挂载者同时作用于两者（当前分派层两调各只带一色，不触发）。
     *
     * @param array{width: int, color: string}|null $stroke
     */
    public function drawEllipse(int $x, int $y, int $width, int $height, ?string $fillColor, ?array $stroke): void
    {
        $doc = $this->doc();
        if ($width <= 0 || $height <= 0) {
            return;
        }

        // 颜色先全量解析（错误在改任何文档状态前返回），再按风格挂载
        $fill = null;
        $draw = null;
        if ($fillColor !== null && $fillColor !== '') {
            $fill = Rgba::parse($fillColor);
        }
        if ($stroke !== null && $stroke['width'] > 0) {
            $draw = Rgba::parse($stroke['color']);
        }

        $style = '';
        if ($fill !== null) {
            $doc->SetAlpha($fill->a / 255, 'Normal');
            $doc->SetFillColor($fill->r, $fill->g, $fill->b);
            $style = 'F';
        }
        if ($draw !== null) {
            $doc->SetAlpha($draw->a / 255, 'Normal');
            $doc->SetDrawColor($draw->r, $draw->g, $draw->b);
            $doc->SetLineWidth((float) $stroke['width'] * self::PX_TO_PT);
            $style .= 'D';
        }
        if ($style === '') {
            return;
        }

        $cx = ($x + $width / 2) * self::PX_TO_PT;
        $cy = ($y + $height / 2) * self::PX_TO_PT;
        // 注意：本包并入的 addon #6 是五参签名 Ellipse($x,$y,$rx,$ry,$style)——
        // go-pdf/fpdf 的 Ellipse 多一个 degRotate 参数，不可照搬六参调用
        $doc->Ellipse($cx, $cy, $width / 2 * self::PX_TO_PT, $height / 2 * self::PX_TO_PT, $style);
        $doc->SetAlpha(1, 'Normal');
    }

    /**
     * 绘制单行文本，(x, y) 为 align 语义下的锚点——**本段最小接线**：签名与
     * 防线（未建面用法错误）就位，完整文本语义（TTF 魔数嗅探 + 子集内嵌 + 对齐
     * 锚点落笔 + 角度旋转，镜像 go-canvas/pdf-renderer text.go）落下一票，届时
     * 本体消费 $fonts 注册表（end 随会话复位）。
     */
    protected function drawText(
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
        $this->doc();
    }
}
