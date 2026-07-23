<?php

declare(strict_types=1);

namespace Gaia\Clarity\Tests\Services;

use Gaia\Clarity\Services\View;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ViewTest extends TestCase
{
    #[Before]
    public function configureViewBasePath(): void
    {
        View::configure(__DIR__ . '/../fixtures/views');
    }

    #[After]
    public function resetView(): void
    {
        View::reset();
    }

    public function testRenderReturnsHtmlResponseWithMergedData(): void
    {
        $response = View::render('hello', ['name' => 'Marshal']);

        self::assertSame('text/html; charset=utf-8', $response->header('Content-Type'));
        self::assertStringContainsString('Hello, Marshal!', $response->content());
    }

    public function testRenderDefaultsToStatus200(): void
    {
        self::assertSame(200, View::render('hello', ['name' => 'Marshal'])->status());
    }

    public function testRenderAcceptsAnExplicitStatusForNon200Pages(): void
    {
        $response = View::render('hello', ['name' => 'Marshal'], status: 404);

        self::assertSame(404, $response->status());
        self::assertStringContainsString('Hello, Marshal!', $response->content());
    }

    public function testSharedDataIsAvailableToEveryView(): void
    {
        View::share('name', 'Shared Name');

        self::assertStringContainsString('Hello, Shared Name!', View::render('hello')->content());
    }

    public function testLocalDataOverridesSharedData(): void
    {
        View::share('name', 'Shared Name');

        $response = View::render('hello', ['name' => 'Local Name']);

        self::assertStringContainsString('Hello, Local Name!', $response->content());
    }

    public function testComposerCanInjectAdditionalData(): void
    {
        View::composer('composed', fn (array $data) => ['injected' => 'from-composer']);

        self::assertStringContainsString('Injected: from-composer', View::render('composed')->content());
    }

    public function testComposerRunsOnlyForItsRegisteredView(): void
    {
        View::composer('composed', fn (array $data) => ['injected' => 'from-composer']);

        $unrelated = View::render('hello', ['name' => 'Marshal']);

        self::assertStringContainsString('Hello, Marshal!', $unrelated->content());
    }

    public function testViewOptsIntoLayoutByAssigningLayoutVariable(): void
    {
        $response = View::render('with-layout', ['body' => 'main content']);

        self::assertStringContainsString('<div class="layout">', $response->content());
        self::assertStringContainsString('Child: main content', $response->content());
    }

    public function testLayoutCanAlsoBeSpecifiedViaRenderData(): void
    {
        $response = View::render('hello', ['name' => 'Marshal', 'layout' => 'layout']);

        self::assertStringContainsString('<div class="layout">', $response->content());
        self::assertStringContainsString('Hello, Marshal!', $response->content());
    }

    public function testExistsReflectsWhetherViewFileIsPresent(): void
    {
        self::assertTrue(View::exists('hello'));
        self::assertFalse(View::exists('does-not-exist'));
    }

    public function testRenderThrowsForMissingView(): void
    {
        $this->expectException(RuntimeException::class);

        View::render('does-not-exist');
    }

    public function testRenderThrowsWhenBasePathNotConfigured(): void
    {
        View::reset();

        $this->expectException(RuntimeException::class);

        View::render('hello');
    }
}
