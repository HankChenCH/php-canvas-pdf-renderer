# Changelog

本项目遵循 [Semantic Versioning](https://semver.org/lang/zh-CN/)。

## 1.0.0 - 2026-10-21

- 首发版本：`hankchen/php-canvas-next` 的 PDF 渲染后端（命名空间根 `HankChen\CanvasNext\Renderer\Pdf\`；对位 `php-canvas-image-renderer` 位图后端，语义镜像 `go-canvas/pdf-renderer`）
- **tFPDF 子类**：FPDF 官方四 addon 并入（Transparency `SetAlpha` / Clipping / Transformations / Ellipses）+ desc getter（`getFontDesc`）+ 族名 sha256 安全合成（`safeFamilyName`，禁原始路径进 PDF Name 对象）+ 字体目录进程级装配（`Tfpdf::fontDir()`，悬空软链自愈）
- **`PdfRenderer extends AbstractRenderer implements ShapeCapableRenderer`**：五原语（`begin`/`end(): string`/`drawRect`/`drawImage`/`drawText`）+ 形状对（`drawEllipse`/`drawImageMasked`，ADR 0026）；`begin` 文档续页 + `end` 一次性复位会话（同实例复用干净新文档）；px→pt 96dpi（×0.75）换算责任全在后端
- **图片直嵌**：PNG/JPEG 魔数嗅探一等直嵌零重编码、GIF 不纳入（与 Go 镜像的显式偏差：tFPDF `_parsegif` 走 GD，违零 GD 红线）；源窗观察方向 + T⁻¹ 逆映射回内在窗 + EXIF 八向放置矩阵归正（ADR 0027，平移 go 97 行最小 EXIF 解析器，零扩展依赖）；clip 算子裁窗（裁剪施加在变换之前，双空间纪律）；同名源按名去重；空 dstRect/不相交源窗/垃圾字节防线
- **文本**：TTF-only 硬门槛（glyf `.ttf`；TTC/OTF/CFF/垃圾魔数应用层前置拒收为可读异常——tFPDF 内部是进程级 die）；字体经合成名落位 + `AddFont(true)` 内存内子集（包内零字体资产）；度量自洽（水平 `GetStringWidth` 同度量路径、垂直基线显式公式、未知归 left/top）；Helvetica 兜底（核心字体 718/−207 per-1000 常量）；正值逆时针绕锚点旋转；字色 alpha 经 ExtGState 挂载、画完 `SetAlpha(1)` 恢复
- **多页入口**：继承核心 `AbstractRenderer::renderPages` 模板消费页序列（`DocumentCompiler::compile(...)->canvases` 帧序×页序扁平）出单 PDF；分页权在编译期，后端零自主切页；单 canvas 退化单页与单页渲染结构一致
- **依赖**：php ^8.3 + `hankchen/php-canvas-next` ^2.2 + `setasign/tfpdf` ^1.34；**零 GD/Imagick 硬依赖**（GD 仅测试夹具出场）
- **验收**：poppler 四件套点位断言（`pdftoppm -r 96` 恰好 1:1 回像素网格、无黄金文件、精确点/容差/墨迹粗判三级取样）；83 tests / 842 assertions；CJK 子集体积断言（500 字形 < 1MB）；parity 措辞点名适用域与反例域
