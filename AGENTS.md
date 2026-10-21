# AGENTS.md

## Purpose

`hankchen/php-canvas-pdf-renderer`：核心包 `hankchen/php-canvas-next` 的 PDF 渲染后端（tFPDF + FPDF 官方四 addon 以子类并入），`end()` 产物 = PDF 文档字节串。命名空间根 `HankChen\CanvasNext\Renderer\Pdf\`（PSR-4 → `src/`），PHP `^8.3`。与 `php-canvas-image-renderer`（位图后端）对位、与 `go-canvas/pdf-renderer` 语义镜像——语义对齐、手段各异（矢量直嵌 vs 像素搬运）。中文注释、conventional commits + 中文 subject、TDD，均与核心包约定一致。

**红线：零 GD/Imagick**——PNG/JPEG 纯原生直嵌（PDF 原生格式，不重编码），GIF 不纳入（tFPDF `_parsegif` 走 GD，与 go 镜像的显式偏差，go 的 GIF 直嵌是纯 Go 解码红利不可平移）。poppler 四件套（pdfinfo/pdftoppm/pdftotext/pdffonts）只作测试期外部进程，不进依赖树。

## Layout

- `src/Tfpdf.php` — tFPDF 子类：FPDF 官方四 addon 原文并入（Transparency #74 SetAlpha=ExtGState ca/CA/BM / Clipping #78 ClippingRect+ClippingEllipse+UnsetClipping=`q re W n`+`Q` / Transformations #79 StartTransform+Rotate+StopTransform=`q cm`+`Q` / Circles and ellipses #6 Ellipse+Circle=四段贝塞尔 D/F/FD）+ `getFontDesc`（`$fonts` protected，经此暴露 per-1000 desc）+ `safeFamilyName`（族名 = "f"+sha256(路径) 前 16 位 hex）。addon 未并入面（ClippingText/RoundedRect/Polygon/Cell、Scale/Skew/Mirror 系）不在契约内，方法体保持原文（只依赖 `_out()` 与核心成员）。
- `src/Rgba.php` — hex 四形态（`#rgb/#rgba/#rrggbb/#rrggbbaa`，`#` 可省）解析 + 非法输入报错中止；与 go `color.go` / image-renderer 同式小件，正反例防线三端对称。
- `src/PdfRenderer.php` — 渲染后端本体（`extends AbstractRenderer implements ShapeCapableRenderer`）：五原语（drawText 本段最小接线，完整文本语义落文本票）+ 形状对；Begin 文档续页 / End 一次性复位会话；图片直嵌 = 魔数嗅探（PNG/JPEG 一等零重编码、GIF 拒收）+ clip 算子裁 dstRect + 放置矩阵（观察方向窗 T⁻¹ 回内在窗 + EXIF 归正 + placeAffine 双空间换算）；掩码 blit = ClipEllipse 替位。
- `src/Affine.php` — 2×3 仿射小件（y 向下像素空间）：orient 八向表（平移 go orientAffine）+ after/apply/inverse/envelopeOf；inverse 是 PHP 特有面（go 分派层给内在系 srcRect 无需逆映射，PHP 分派层给观察方向系）。
- `src/Exif.php` — EXIF orientation 最小解析器（逐行平移 go exif.go；开放点 5 定稿 = 字节级自持，不用 ext-exif）：只认 JPEG APP1(Exif) 的 IFD0 Orientation，非 JPEG/截断/值域外一律 fail-safe 归 1。
- `src/ImageFormat.php` — 直嵌格式防线：魔数嗅探（扩展名不可信）+ 内在尺寸（getimagesizefromstring，ext/standard 非 GD）；GIF 魔数显式拒收。
- `tests/Support/` — 测试基座：`PopplerPdfCase`（poppler 包装 + P6 PPM 栅格解析 + 三级取样断言；栅格读取零 GD）、`PpmGrid`、`Fixtures`（纯 PHP PNG 编码 + GD JPEG/EXIF 拼装夹具）、`BarePdfRenderer`（Closure::bind 直调 protected 原语面的测试缝，PdfRenderer final 不被子类化）。
- `tests/TfpdfTest.php` — 子类行为测试（操作符面 + header 版本 + desc + 族名）。
- `tests/RgbaTest.php` — 颜色正反例矩阵（对称平移 go `color_test.go` 同一用例集）。
- `tests/PdfBackendTest.php` / `PdfImageTest.php` / `PdfShapeTest.php` / `PdfClipTest.php` / `AffineTest.php` / `ExifTest.php` — 渲染器本体测试组（平移 go backend/image/clip 测试全目：会话/结构/矩形/直嵌/EXIF 八向/clip 圆矩阵/椭圆/掩码 + 仿射与 EXIF 单元标定）。
- `scripts/visual-check-pdf.php` — 目验样张：tFPDF 综合样张 + PdfRenderer 管线样张各出 .pdf + `pdftoppm -r 96` 预览图。

## Commands

```sh
export PATH="$(brew --prefix)/opt/php@8.3/bin:$PATH"   # Homebrew PHP 是 keg-only
composer install
composer test
php scripts/visual-check-pdf.php out.pdf   # 目验样张（样张 + pdftoppm 预览；poppler 缺席只出 .pdf）
```

验收依赖 **poppler 四件套**（本地 `brew install poppler`；CI apt 装 poppler-utils）+ **CJK 单体 TTF**（环境变量 `CANVAS_PDF_TEST_CJK_FONT` 优先 → 系统 TTF 候选 → `markTestSkipped` 留指引；CI 装 fonts-ipafont-gothic，macOS 本机用 `/System/Library/Fonts/Supplemental/Arial Unicode.ttf`）。

## Rules and gotchas

- **依赖核心包**：`"hankchen/php-canvas-next": "^2.1"` 走 Packagist；本地跨包联调临时 `composer config repositories.php-canvas-next path ../php-canvas-next` 且**勿提交**（本地核心在分支 tip 时 path 只解析出 `dev-<branch>` 不满足 `^`，此时直接走 Packagist）。composer.lock 不入库。
- **依赖方向单向**：本包只可依赖核心包 + setasign/tfpdf；不得 import php-canvas-image-renderer。共享小件（颜色/EXIF 等）= 同式复制注明出处（go 先例同款），复制面膨胀再议抽共享包。
- **TTF-only 硬门槛**：tFPDF 的 UTF-8 路径只吃 TrueType（glyf）`.ttf`；**ttcf/OTTO(CFF)/垃圾魔数在 tFPDF 内部是 `die()` 硬退进程**（ttfonts.php getMetrics——不可 try/catch、无错误位；与 go 侧「静默吞错留到 SetFont 才爆」形态不同，两者都不可接受）。因此字体入 `AddFont` 前必须应用层魔数嗅探拒收（ttcf/OTTO/版本魔数外 → 可读异常报错中止），绝不能让 `die()` 发生。字体扩展名不可信，按魔数判定。
- **AddFont 文件路径解析**：`AddFont($family,$style,$file,true)` 的 `$file` 相对 `fontpath/unifont/`（或 `_SYSTEM_TTFONTS` 前缀）解析——**绝对路径直传会把路径拼成 `fontpath/unifont/<绝对路径>` 后 fopen 失败 die**。metrics 缓存（`.mtx.php`/`.cw.dat`/`.cw127.php`）写在 fontpath/unifont/ 下、以 `$file` 去扩展名 basename 命名：跨目录同名文件有碰撞面，且绝对路径形态下缓存路径必坏（fopen E_WARNING + 写缓存 TypeError 崩）——集成走「临时目录 + 合成名软链/复制 + `FPDF_FONTPATH`」形态，缓存目录不可写只降速不坏（is_writable 守卫）。
- **字体族名**：`safeFamilyName()`（"f"+sha256 前 16 位 hex，镜像 go text.go）。族名只作会话注册键，tFPDF 的 `$family` 参数不进 PDF 字节（/BaseFont = 字体内部 fullName，见下）——但原始路径/含空格斜杠的串仍禁用（防线对称，防回归）。
- **tFPDF 子集内嵌的 pdffonts 形态**（spike 实测钉定）：`MPDFAA+ArialUnicodeMS  CID TrueType  Identity-H  emb=yes sub=yes uni=yes`——**sub=yes 带固定前缀 `MPDFAA+`**（tfpdf.php `'MPDFAA+'.$font['name']` 硬编码，name=字体内部 fullName 去空格括号）；与 go 侧 sub=no 无前缀形态**不同**。断言锚点 = "CID TrueType + Identity-H + emb/uni yes + 前缀形态 + pdftotext CJK 可提取"；CJK 子集体积断言（子集按需，体积驱动 = 字形数）。
- **核心字体 desc 缺位**：`fonts['helvetica']` 无 `desc` 键（font/*.php metric 文件不定义 `$desc`）——垂直 metrics 用 PDF 标准 14 字体 AFM 常量兜底（Helvetica Ascender 718 / Descender −207，per-1000）；TTF（uni）字体 desc 齐全（per-1000，`round()` 归 **float**）直接可用。与 go 的「GetFontDesc 恒零判空」同构。
- **单位口径红线**：页尺寸/坐标/字号/线宽一律 px×0.75（96dpi，pt = px × 0.75），换算责任全在后端；对齐偏移（GetStringWidth/metrics 所得）已是 pt 不再乘——**混乘 = 对齐整体缩水 0.75**（go 踩坑前置）。
- **放置矩阵双空间**：绘制推导在 y 向下像素空间，发射前换算到 PDF 原生 y 向上；tFPDF/addon 的变换/裁切入参恒 FPDF 用户空间（y 向下、内部自换算），两套口径不可混用。
- **FPDF 几何陷阱**：①竖版('P')构造 `[w,h]` 时 w>h 会被构造器**交换**（横向宽页必须用 'L'——PdfRenderer::begin 按 wPt>hPt 选 'L'/'P'，续页 AddPage 同规则）；②`Image()` 的 x/y 是**顶左锚点、用户空间 y 向下**；③`Text()` 原点在基线；④超出 MediaBox 的内容被查看器裁掉（页面几何算错会伪装成"字形丢失"）。
- **addon 签名与 go-pdf/fpdf 不全同形**：本包并入的 PHP addon #6 是五参 `Ellipse($x,$y,$rx,$ry,$style)`——go fpdf 的 `Ellipse` 多一个 `degRotate` 参数，**六参调用会把 0 塞进 $style 变纯描边**（静默错形，无报错）。Clipping/Transform 家族同理，调用前对一眼本包 Tfpdf.php 的签名。
- **源窗双坐标系**：go 分派层 intrinsicSize 不做 EXIF 翻转（srcRect=内在系），**PHP 分派层 intrinsicSize 按观察方向给 srcRect（07 票裁决）**——PDF 直嵌路径必须 T⁻¹ 逆映射回内在窗再走 orientAffine 放置矩阵（go embedBlit 可平移的只有「内在窗 → box 包络 → W∘T」那半段，T⁻¹ 是 PHP 特有半段，不可照抄 go 全函数）。
- **测试面 GD 边界**：GD 只允许出现在测试夹具编码（JPEG 无纯 PHP 编码器，镜像 go stdlib jpeg.Encode 手法）——`Fixtures::requireGd()` 缺席即 skip；栅格读取走 pdftoppm 缺省 P6 PPM 纯字节解析（零 GD），PNG 夹具是纯 PHP 编码器。CI setup-php 装 gd 仅此用途（workflow 内有注释）。
- **alpha 语义**：`SetAlpha` 经 ExtGState 挂载、**不包 q/Q**（状态残留到下次 SetAlpha 或页尾）——画完必须 `SetAlpha(1)` 恢复防残留（go paintRect 同款）；用了 ExtGState 后 `_enddoc` 把 header 从缺省 `%PDF-1.3` 抬到 `%PDF-1.4`（与 go fpdf 缺省照发 + blend 抬 1.4 对齐，无需干预；带 alpha 通道的 PNG 走 SMask 路径同样抬 1.4）。addon 原文 `_putresourcedict` 会无条件发射空 `/ExtGState << >>` 字典键——忠实并入行为，非 bug。
- **vAlign 缺省**：TextLayer 缺省 verticalAlign=bottom（wire 面历史语义），管线级文本测试需显式对齐；垂直基线公式 top:+ascent / center:(ascent+descent)/2 / bottom:−descent，未知取值归 top。
- **测试纪律**：TDD 先红后绿；poppler 点位断言 = `pdftoppm -r 96` 恰好 1:1 回像素网格 + 无黄金文件三级取样；parity/等价断言点名适用域与反例域（措辞纪律）；本包测试可直接读未压缩内容流做操作符级断言（`SetCompression(false)`），栅格级断言归渲染器本体用例组。
- **CI**：workflow 额外 checkout 核心包到工作区子目录、checkout 最新 release tag、把 path repository 重写指向它（path repo 的 `../` 相对路径在 CI 工作区不存在），并装 poppler-utils + fonts-ipafont-gothic（CJK 字体选型 = 开放点 6 定稿：与 go 侧同字体、单体 .ttf 满足 TTF-only——fonts-noto-cjk 是 .ttc 会被防线拒收）。
