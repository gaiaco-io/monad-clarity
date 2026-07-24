<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Middlewares;

use Monad\Clarity\Middlewares\MetaTag;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

if (!defined('APP')) {
    // MetaTag reads this ambient application config constant (pre-existing convention,
    // owned by the consuming skeleton app — not something Clarity itself defines).
    define('APP', ['name' => 'Test App', 'base_url' => 'https://example.test']);
}

/**
 * Smoke coverage for the Services\SeoService -> Middlewares\MetaTag relocation+rename:
 * confirms the namespace move didn't break behavior. Not a line-by-line re-verification
 * of pre-existing SeoService logic, which this phase did not rewrite.
 */
final class MetaTagTest extends TestCase
{
    #[After]
    public function resetMetaTag(): void
    {
        MetaTag::reset();
    }

    public function testRenderIncludesTitleDescriptionAndCanonical(): void
    {
        MetaTag::set([
            'title' => 'Product Page',
            'description' => 'A great product.',
            'canonical' => 'https://example.test/products/1',
        ]);

        $html = MetaTag::render();

        self::assertStringContainsString('<title>Product Page | Test App</title>', $html);
        self::assertStringContainsString('name="description" content="A great product."', $html);
        self::assertStringContainsString('<link rel="canonical" href="https://example.test/products/1">', $html);
    }

    public function testRenderEscapesHtmlInTitle(): void
    {
        MetaTag::set(['title' => '<script>alert(1)</script>']);

        $html = MetaTag::render();

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testOpenGraphAndTwitterTagsAreRendered(): void
    {
        MetaTag::set([
            'title' => 'Article Title',
            'image' => 'https://example.test/img.png',
            'twitter_site' => 'clarity',
        ]);

        $html = MetaTag::render();

        self::assertStringContainsString('property="og:title" content="Article Title"', $html);
        self::assertStringContainsString('property="og:image" content="https://example.test/img.png"', $html);
        self::assertStringContainsString('name="twitter:site" content="@clarity"', $html);
    }

    public function testNoIndexAddsRobotsDirective(): void
    {
        MetaTag::set([])->noIndex();

        self::assertStringContainsString('name="robots" content="follow, noindex"', MetaTag::render());
    }

    public function testJsonLdIsRenderedAsScriptTag(): void
    {
        MetaTag::set(['json_ld' => ['@type' => 'WebPage', '@context' => 'https://schema.org']]);

        $html = MetaTag::render();

        self::assertStringContainsString('<script type="application/ld+json">', $html);
        self::assertStringContainsString('"@type":"WebPage"', $html);
    }

    public function testResetClearsStateBetweenRenders(): void
    {
        MetaTag::set(['title' => 'First Page']);
        MetaTag::reset();

        self::assertStringContainsString('<title>Test App</title>', MetaTag::render());
    }
}
