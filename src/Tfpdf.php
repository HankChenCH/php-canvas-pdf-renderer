<?php

declare(strict_types=1);

namespace HankChen\CanvasNext\Renderer\Pdf;

/**
 * tFPDF 渲染基座：FPDF 官方四 addon 以子类并入 + 字体 desc getter + 族名安全合成。
 *
 * 并入纪律（四件均为 fpdf.org 官方 Scripts 页原文方法集，方法体只依赖 _out() 与 $k/$h 等
 * 核心成员；与 go-pdf/fpdf 的 PDF 操作符逐字同源，poppler 点位断言可平移）：
 *  - Transparency #74（Martin Hall-May）：SetAlpha（ExtGState ca/CA/BM）
 *  - Clipping #78（Andreas Würmser）：ClippingRect/ClippingEllipse/UnsetClipping（q re W n / Q）
 *  - Transformations #79 v2（Wagner & Würmser）：StartTransform/Rotate/StopTransform（q cm Q）
 *  - Circles and ellipses #6（Olivier Plathey）：Ellipse/Circle（四段贝塞尔，D/F/FD）
 * 契约未用到的 addon 方法（ClippingText、ClippingRoundedRect、ClippingPolygon、ClippedCell、
 * Scale 系、Skew 系、Mirror 系）刻意不并入——本类只承载本包 Backend 契约所需的操作符面。
 *
 * 字体侧：
 *  - getFontDesc：$fonts 为 tFPDF protected，经本 getter 暴露 per-1000 desc；核心字体无 desc
 *    （返回 null），调用侧以 PDF 标准 14 字体 AFM 常量兜底（Helvetica Ascender 718 / Descender −207）。
 *  - safeFamilyName：族名 = "f" + sha256(字体路径) 前 16 位 hex（镜像 go-canvas pdf-renderer
 *    text.go）。禁用原始路径/含空格斜杠的串作族名——防写坏 PDF Name 对象。
 *  - AddFont($family,$style,$file,true) 的 $file 按 tFPDF 规则相对 fontpath/unifont/ 解析
 *    （或 _SYSTEM_TTFONTS 前缀）；metrics 缓存（.mtx.php/.cw.dat）写在 fontpath/unifont/ 下、
 *    以 $file 去扩展名的 basename 命名（跨目录同名文件有碰撞面，集成时用合成名规避）。
 *  - fontDir()：进程级字体目录装配（FPDF_FONTPATH → 本包临时目录，构造期先行触发）——
 *    渲染器的字体落位（合成名软链/复制进 unifont/）与核心字体 metric 同源于此。
 */
class Tfpdf extends \tFPDF
{
    /** ExtGState 注册表（addon #74：序号 => ['parms'=>ca/CA/BM, 'n'=>对象号]） */
    protected array $extgstates = [];

    /** 进程级字体目录缓存（fontDir() 装配一次，形态与 FPDF_FONTPATH 惯例一致：尾带斜杠） */
    private static ?string $fontDir = null;

    public function __construct($orientation = 'P', $unit = 'mm', $size = 'A4')
    {
        // FPDF_FONTPATH 在父构造期读取——装配必须先于 parent::__construct，
        // 保证本进程所有实例（核心字体 metric + unifont 解析 + metrics 缓存）同源
        self::fontDir();
        parent::__construct($orientation, $unit, $size);
    }

    /**
     * 字体目录装配（进程级一次）：FPDF_FONTPATH 指向本包管理的临时目录——
     * tFPDF 的 AddFont($file) 相对 fontpath/unifont/ 解析、metrics 缓存写在
     * fontpath/unifont/ 下、核心字体 metric 从 fontpath/ include，三处必须同源
     * （spike 实测：绝对路径直传 AddFont 会被拼成 fontpath/unifont/<绝对路径>
     * 后 fopen 失败 die——字体必须以合成名落位此目录、basename 进 AddFont）。
     * 缺省目录 = 系统临时目录/canvas-pdf-renderer-fonts：核心字体 metric 自
     * vendor 软链（失败退回复制），unifont/ 为运行时字体落位与缓存工作区。
     * 宿主已定义 FPDF_FONTPATH 时按宿主配置走（不重定义、不写 vendor）。
     */
    public static function fontDir(): string
    {
        if (self::$fontDir !== null) {
            return self::$fontDir;
        }
        if (defined('FPDF_FONTPATH')) {
            return self::$fontDir = FPDF_FONTPATH;
        }

        $dir = sys_get_temp_dir() . '/canvas-pdf-renderer-fonts';
        if (!is_dir($dir . '/unifont') && !@mkdir($dir . '/unifont', 0755, true) && !is_dir($dir . '/unifont')) {
            throw new \RuntimeException("字体目录创建失败: {$dir}/unifont");
        }
        $vendorFont = dirname((new \ReflectionClass(\tFPDF::class))->getFileName()) . '/font';
        foreach (glob($vendorFont . '/*.php') ?: [] as $metric) {
            $target = $dir . '/' . basename($metric);
            if (is_file($target)) {
                continue;
            }
            if (is_link($target)) {
                @unlink($target); // 悬空软链（旧源被清理）：重建而非撞 EEXIST
            }
            if (!@symlink($metric, $target) && !@copy($metric, $target)) {
                throw new \RuntimeException("核心字体 metric 落位失败: {$metric} → {$target}");
            }
        }
        define('FPDF_FONTPATH', $dir . '/');

        return self::$fontDir = $dir . '/';
    }

    // ==================== Transparency addon（fpdf.org script #74 原文并入） ====================

    /**
     * alpha: real value from 0 (transparent) to 1 (opaque)
     * bm:    blend mode, one of the following:
     *          Normal, Multiply, Screen, Overlay, Darken, Lighten, ColorDodge, ColorBurn,
     *          HardLight, SoftLight, Difference, Exclusion, Hue, Saturation, Color, Luminosity
     */
    public function SetAlpha($alpha, $bm = 'Normal')
    {
        // set alpha for stroking (CA) and non-stroking (ca) operations
        $gs = $this->AddExtGState(['ca' => $alpha, 'CA' => $alpha, 'BM' => '/' . $bm]);
        $this->SetExtGState($gs);
    }

    public function AddExtGState($parms)
    {
        $n = count($this->extgstates) + 1;
        $this->extgstates[$n]['parms'] = $parms;
        return $n;
    }

    public function SetExtGState($gs)
    {
        $this->_out(sprintf('/GS%d gs', $gs));
    }

    protected function _enddoc()
    {
        if (!empty($this->extgstates) && $this->PDFVersion < '1.4') {
            $this->PDFVersion = '1.4';
        }
        parent::_enddoc();
    }

    protected function _putextgstates()
    {
        for ($i = 1; $i <= count($this->extgstates); $i++) {
            $this->_newobj();
            $this->extgstates[$i]['n'] = $this->n;
            $this->_out('<</Type /ExtGState');
            foreach ($this->extgstates[$i]['parms'] as $k => $v) {
                $this->_out('/' . $k . ' ' . $v);
            }
            $this->_out('>>');
            $this->_out('endobj');
        }
    }

    protected function _putresourcedict()
    {
        parent::_putresourcedict();
        $this->_out('/ExtGState <<');
        foreach ($this->extgstates as $k => $extgstate) {
            $this->_out('/GS' . $k . ' ' . $extgstate['n'] . ' 0 R');
        }
        $this->_out('>>');
    }

    protected function _putresources()
    {
        $this->_putextgstates();
        parent::_putresources();
    }

    // ==================== Clipping addon（fpdf.org script #78 原文并入；rect/ellipse 两形） ====================

    public function ClippingRect($x, $y, $w, $h, $outline = false)
    {
        $op = $outline ? 'S' : 'n';
        $this->_out(sprintf('q %.2F %.2F %.2F %.2F re W %s',
            $x * $this->k,
            ($this->h - $y) * $this->k,
            $w * $this->k, -$h * $this->k,
            $op));
    }

    public function ClippingEllipse($x, $y, $rx, $ry, $outline = false)
    {
        $op = $outline ? 'S' : 'n';
        $lx = 4 / 3 * (M_SQRT2 - 1) * $rx;
        $ly = 4 / 3 * (M_SQRT2 - 1) * $ry;
        $k = $this->k;
        $h = $this->h;
        $this->_out(sprintf('q %.2F %.2F m %.2F %.2F %.2F %.2F %.2F %.2F c',
            ($x + $rx) * $k, ($h - $y) * $k,
            ($x + $rx) * $k, ($h - ($y - $ly)) * $k,
            ($x + $lx) * $k, ($h - ($y - $ry)) * $k,
            $x * $k, ($h - ($y - $ry)) * $k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            ($x - $lx) * $k, ($h - ($y - $ry)) * $k,
            ($x - $rx) * $k, ($h - ($y - $ly)) * $k,
            ($x - $rx) * $k, ($h - $y) * $k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            ($x - $rx) * $k, ($h - ($y + $ly)) * $k,
            ($x - $lx) * $k, ($h - ($y + $ry)) * $k,
            $x * $k, ($h - ($y + $ry)) * $k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c W %s',
            ($x + $lx) * $k, ($h - ($y + $ry)) * $k,
            ($x + $rx) * $k, ($h - ($y + $ly)) * $k,
            ($x + $rx) * $k, ($h - $y) * $k,
            $op));
    }

    public function UnsetClipping()
    {
        $this->_out('Q');
    }

    // ==================== Transformations addon（fpdf.org script #79 v2 原文并入；rotate 核） ====================

    public function StartTransform()
    {
        // save the current graphic state
        $this->_out('q');
    }

    public function Rotate($angle, $x = '', $y = '')
    {
        if ($x === '') {
            $x = $this->x;
        }
        if ($y === '') {
            $y = $this->y;
        }
        $y = ($this->h - $y) * $this->k;
        $x *= $this->k;
        // calculate elements of transformation matrix
        $tm[0] = cos(deg2rad($angle));
        $tm[1] = sin(deg2rad($angle));
        $tm[2] = -$tm[1];
        $tm[3] = $tm[0];
        $tm[4] = $x + $tm[1] * $y - $tm[0] * $x;
        $tm[5] = $y - $tm[0] * $y - $tm[1] * $x;
        // rotate the coordinate system around ($x,$y)
        $this->Transform($tm);
    }

    public function Transform($tm)
    {
        $this->_out(sprintf('%.3F %.3F %.3F %.3F %.3F %.3F cm', $tm[0], $tm[1], $tm[2], $tm[3], $tm[4], $tm[5]));
    }

    public function StopTransform()
    {
        // restore previous graphic state
        $this->_out('Q');
    }

    // ==================== Circles and ellipses addon（fpdf.org script #6 原文并入） ====================

    public function Circle($x, $y, $r, $style = 'D')
    {
        $this->Ellipse($x, $y, $r, $r, $style);
    }

    public function Ellipse($x, $y, $rx, $ry, $style = 'D')
    {
        if ($style == 'F') {
            $op = 'f';
        } elseif ($style == 'FD' || $style == 'DF') {
            $op = 'B';
        } else {
            $op = 'S';
        }
        $lx = 4 / 3 * (M_SQRT2 - 1) * $rx;
        $ly = 4 / 3 * (M_SQRT2 - 1) * $ry;
        $k = $this->k;
        $h = $this->h;
        $this->_out(sprintf('%.2F %.2F m %.2F %.2F %.2F %.2F %.2F %.2F c',
            ($x + $rx) * $k, ($h - $y) * $k,
            ($x + $rx) * $k, ($h - ($y - $ly)) * $k,
            ($x + $lx) * $k, ($h - ($y - $ry)) * $k,
            $x * $k, ($h - ($y - $ry)) * $k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            ($x - $lx) * $k, ($h - ($y - $ry)) * $k,
            ($x - $rx) * $k, ($h - ($y - $ly)) * $k,
            ($x - $rx) * $k, ($h - $y) * $k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            ($x - $rx) * $k, ($h - ($y + $ly)) * $k,
            ($x - $lx) * $k, ($h - ($y + $ry)) * $k,
            $x * $k, ($h - ($y + $ry)) * $k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c %s',
            ($x + $lx) * $k, ($h - ($y + $ry)) * $k,
            ($x + $rx) * $k, ($h - ($y + $ly)) * $k,
            ($x + $rx) * $k, ($h - $y) * $k,
            $op));
    }

    // ==================== 字体侧扩展（本包自有；desc getter + 族名安全合成） ====================

    /**
     * 已注册字体的 desc 元数据（per-1000 度量：Ascent/Descent/CapHeight/Flags/FontBBox/
     * ItalicAngle/StemV/MissingWidth）。核心字体（Helvetica 等）无 desc，返回 null——调用侧以
     * AFM 规范常量兜底（Helvetica 718/-207），与 go 侧 GetFontDesc 恒零判定同构。
     */
    public function getFontDesc(string $family, string $style = ''): ?array
    {
        $style = strtoupper($style);
        if ($style === 'IB') {
            $style = 'BI';
        }
        $fontkey = strtolower($family) . $style;
        return isset($this->fonts[$fontkey]['desc']) ? $this->fonts[$fontkey]['desc'] : null;
    }

    /**
     * 族名安全合成："f" + sha256(字体路径) 前 16 位 hex（镜像 go-canvas pdf-renderer text.go
     * addUTF8Font）。族名只作会话内注册键（tFPDF 不把它写进 PDF 字节），但禁止原始路径/含
     * 空格斜杠的串——防线对称 + 防任何写 Name 对象的回归。
     */
    public function safeFamilyName(string $fontFile): string
    {
        return 'f' . substr(hash('sha256', $fontFile), 0, 16);
    }
}
