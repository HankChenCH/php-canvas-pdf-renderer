<?php

declare(strict_types=1);

namespace HankChen\CanvasNext\Renderer\Pdf\Tests;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Contracts\DownloaderInterface;
use HankChen\CanvasNext\Exception\MaterializeException;
use HankChen\CanvasNext\Layer\ImageLayer;
use HankChen\CanvasNext\Renderer\Pdf\PdfRenderer;
use HankChen\CanvasNext\Renderer\Pdf\Tests\Support\PopplerPdfCase;
use HankChen\CanvasNext\ResourceManagers\ResourceResolver;
use HankChen\CanvasNext\Runtime\Cancellation;
use HankChen\CanvasNext\Runtime\CancellationSource;
use HankChen\CanvasNext\Runtime\NullCancellation;
use HankChen\CanvasNext\Runtime\TimeoutCancellation;

/**
 * 渲染期控制面测试（平移 go cancel_test.go，ADR 0008）：ctx 取消/超时映射渲染
 * code sentinel——检查点在核心模板（根层每图层前/物化入口），后端消费同一错误面；
 * 下载失败注入走资源 code。失败即抛、end 不达、零产物逃逸（失败路径 render 抛出
 * 而非返回——go 断言 len(pdf)==0 的 PHP 对位物）。
 */
class PdfCancelTest extends PopplerPdfCase
{
    public function testPreCancelledContextAbortsRender(): void
    {
        // 预取消 ctx：根层循环检查点先于任何绘制/物化掐断，报 render_cancelled
        $source = new CancellationSource();
        $source->cancel();

        $layer = ImageLayer::make(20, 20)->setImage('https://example.com/a.png');
        try {
            (new PdfRenderer())->render($source->getCancellation(), Canvas::make(40, 40, $layer));
            self::fail('预取消 ctx 的渲染必须报错');
        } catch (MaterializeException $e) {
            self::assertSame('render_cancelled', $e->getErrorCode());
        }
    }

    public function testDeadlineExceededContextAbortsRender(): void
    {
        // 已到期的 deadline：同一检查点报 render_deadline_exceeded
        usleep(1000); // 确保 deadline 已过期（TimeoutCancellation(0) 构造即到期）
        $layer = ImageLayer::make(20, 20)->setImage('https://example.com/a.png');
        try {
            (new PdfRenderer())->render(new TimeoutCancellation(0), Canvas::make(40, 40, $layer));
            self::fail('到期 ctx 的渲染必须报错');
        } catch (MaterializeException $e) {
            self::assertSame('render_deadline_exceeded', $e->getErrorCode());
        }
    }

    public function testDownloadFailureAbortsRenderWithoutProduct(): void
    {
        // 下载失败注入：资源 code resource_download_failed、零产物逃逸（失败即抛、
        // 渲染面丢弃、end 不达——render() 以异常终止而非返回产物）
        $downloader = new class implements DownloaderInterface {
            public int $calls = 0;

            public function download(Cancellation $ctx, string $url): string|false
            {
                $this->calls++;

                return false;
            }
        };
        $resolver = new ResourceResolver($downloader);
        $layer = ImageLayer::make(20, 20)->setImage('https://example.com/broken.png');

        try {
            (new PdfRenderer($resolver))->render(new NullCancellation(), Canvas::make(40, 40, $layer));
            self::fail('下载失败必须报错');
        } catch (MaterializeException $e) {
            self::assertSame('resource_download_failed', $e->getErrorCode());
        }
        self::assertGreaterThanOrEqual(1, $downloader->calls, '下载器未被调用');
    }

    public function testDownloadInterruptedByCancellationKeepsRenderCode(): void
    {
        // 下载被打断于 ctx 取消：下载侧抛出的渲染 code sentinel 穿透渲染面不变形
        // （ctx 归因优先的判定在核心检查点——先于下载；本测试钉「下载实现方观察到
        // 取消后抛出的 code 经管线透传」，后端消费同一错误面、失败即抛、无产物）
        $downloader = new class implements DownloaderInterface {
            public function download(Cancellation $ctx, string $url): string|false
            {
                $ctx->throwIfRequested(); // 下载实现方按契约轮询 ctx：取消即抛渲染 code

                return false;
            }
        };
        $source = new CancellationSource();
        $source->cancel();
        $resolver = new ResourceResolver($downloader);
        $layer = ImageLayer::make(20, 20)->setImage('https://example.com/slow.png');

        try {
            (new PdfRenderer($resolver))->render($source->getCancellation(), Canvas::make(40, 40, $layer));
            self::fail('取消穿透的渲染必须报错');
        } catch (MaterializeException $e) {
            self::assertSame('render_cancelled', $e->getErrorCode(), '渲染 code sentinel 不得在管线中变形');
        }
    }
}
