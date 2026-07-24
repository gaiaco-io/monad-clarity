<?php

namespace Monad\Clarity\Middlewares;

/**
 * Dynamic SEO, Open Graph, Twitter Card, and JSON-LD meta tag service.
 * Invoke from controllers; render once in the layout <head>.
 *
 * @package Monad\Clarity\Middlewares
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class MetaTag
{
    private const DESCRIPTION_MAX = 160;

    private static ?string $title = null;
    private static ?string $title_template = null;
    private static ?string $description = null;
    private static ?string $keywords = null;
    private static ?string $author = null;
    private static ?string $locale = null;
    private static ?string $site_name = null;
    private static ?string $og_type = null;
    private static ?string $og_url = null;
    private static ?string $canonical_url = null;
    private static ?string $robots = null;
    private static ?string $twitter_card = null;
    private static ?string $twitter_site = null;
    private static ?string $twitter_creator = null;

    /** @var array<int, array{url: string, width: ?int, height: ?int, alt: ?string}> */
    private static array $images = [];

    /** @var array<string, mixed> */
    private static array $article = [];

    /** @var array<int, array<string, mixed>> */
    private static array $json_ld = [];

    /**
     * One-shot configuration from an associative array.
     *
     * @param array<string, mixed> $data
     */
    public static function set(array $data): self
    {
        $instance = new self();

        foreach ($data as $key => $value) {
            match ($key) {
                'title' => $instance->title((string) $value),
                'title_template' => $instance->titleTemplate((string) $value),
                'description' => $instance->description((string) $value),
                'keywords' => $instance->keywords((string) $value),
                'author' => $instance->author((string) $value),
                'locale' => $instance->locale((string) $value),
                'site_name' => $instance->siteName((string) $value),
                'og_type' => $instance->ogType((string) $value),
                'og_url' => $instance->ogUrl((string) $value),
                'canonical' => $instance->canonical(is_string($value) ? $value : null),
                'robots' => $instance->robots((string) $value),
                'no_index' => $instance->noIndex((bool) $value),
                'no_follow' => $instance->noFollow((bool) $value),
                'twitter_card' => $instance->twitterCard((string) $value),
                'twitter_site' => $instance->twitterSite((string) $value),
                'twitter_creator' => $instance->twitterCreator((string) $value),
                'image' => is_array($value)
                    ? $instance->image(
                        (string) ($value['url'] ?? $value[0] ?? ''),
                        isset($value['width']) ? (int) $value['width'] : null,
                        isset($value['height']) ? (int) $value['height'] : null,
                        isset($value['alt']) ? (string) $value['alt'] : null,
                    )
                    : $instance->image((string) $value),
                'images' => $instance->images(is_array($value) ? $value : []),
                'article' => $instance->article(is_array($value) ? $value : []),
                'json_ld' => is_array($value) ? $instance->addJsonLd($value) : $instance,
                default => $instance,
            };
        }

        return $instance;
    }

    public function title(string $title): self
    {
        self::$title = trim($title);

        return $this;
    }

    public function titleTemplate(string $template): self
    {
        self::$title_template = $template;

        return $this;
    }

    public function description(string $description): self
    {
        self::$description = trim($description);

        return $this;
    }

    public function keywords(string $keywords): self
    {
        self::$keywords = trim($keywords);

        return $this;
    }

    public function author(string $author): self
    {
        self::$author = trim($author);

        return $this;
    }

    public function locale(string $locale): self
    {
        self::$locale = trim($locale);

        return $this;
    }

    public function siteName(string $site_name): self
    {
        self::$site_name = trim($site_name);

        return $this;
    }

    public function ogType(string $type): self
    {
        self::$og_type = trim($type);

        return $this;
    }

    public function ogUrl(string $url): self
    {
        self::$og_url = self::resolveUrl($url);

        return $this;
    }

    /**
     * Set the primary OG image (replaces any existing images).
     */
    public function image(string $url, ?int $width = null, ?int $height = null, ?string $alt = null): self
    {
        self::$images = [[
            'url' => trim($url),
            'width' => $width,
            'height' => $height,
            'alt' => $alt !== null ? trim($alt) : null,
        ]];

        return $this;
    }

    /**
     * Append an additional OG image.
     */
    public function addImage(string $url, ?int $width = null, ?int $height = null, ?string $alt = null): self
    {
        self::$images[] = [
            'url' => trim($url),
            'width' => $width,
            'height' => $height,
            'alt' => $alt !== null ? trim($alt) : null,
        ];

        return $this;
    }

    /**
     * Replace all OG images.
     *
     * @param array<int, string|array{url: string, width?: int, height?: int, alt?: string}> $images
     */
    public function images(array $images): self
    {
        self::$images = [];

        foreach ($images as $entry) {
            if (is_string($entry)) {
                $this->addImage($entry);
                continue;
            }

            $this->addImage(
                (string) ($entry['url'] ?? ''),
                isset($entry['width']) ? (int) $entry['width'] : null,
                isset($entry['height']) ? (int) $entry['height'] : null,
                isset($entry['alt']) ? (string) $entry['alt'] : null,
            );
        }

        return $this;
    }

    public function twitterCard(string $card): self
    {
        self::$twitter_card = trim($card);

        return $this;
    }

    public function twitterSite(string $handle): self
    {
        self::$twitter_site = self::normalizeTwitterHandle($handle);

        return $this;
    }

    public function twitterCreator(string $handle): self
    {
        self::$twitter_creator = self::normalizeTwitterHandle($handle);

        return $this;
    }

    /**
     * @param string|null $url Absolute or relative URL; null uses current request URL.
     */
    public function canonical(?string $url = null): self
    {
        self::$canonical_url = $url === null || $url === ''
            ? self::currentUrl()
            : self::resolveUrl($url);

        return $this;
    }

    public function robots(string $directives): self
    {
        self::$robots = trim($directives);

        return $this;
    }

    public function noIndex(bool $enabled = true): self
    {
        self::applyRobotsDirective('noindex', $enabled);

        return $this;
    }

    public function noFollow(bool $enabled = true): self
    {
        self::applyRobotsDirective('nofollow', $enabled);

        return $this;
    }

    /**
     * Article-specific Open Graph properties.
     *
     * @param array{
     *     published_time?: string,
     *     modified_time?: string,
     *     expiration_time?: string,
     *     author?: string,
     *     section?: string,
     *     tags?: string[]
     * } $meta
     */
    public function article(array $meta): self
    {
        self::$article = array_merge(self::$article, $meta);
        self::$og_type = 'article';

        return $this;
    }

    /**
     * @param array<string, mixed> $schema
     */
    public function addJsonLd(array $schema): self
    {
        if ($schema !== []) {
            self::$json_ld[] = $schema;
        }

        return $this;
    }

    /**
     * @param array{
     *     name?: string,
     *     description?: string,
     *     url?: string
     * } $data
     * @return array<string, mixed>
     */
    public static function schemaWebPage(array $data = []): array
    {
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => $data['name'] ?? self::resolvedTitle(),
            'description' => $data['description'] ?? self::resolvedDescription(),
            'url' => $data['url'] ?? self::resolvedOgUrl(),
        ], static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param array{
     *     headline?: string,
     *     description?: string,
     *     image?: string|array<int, string>,
     *     datePublished?: string,
     *     dateModified?: string,
     *     author?: string|array{name?: string},
     *     url?: string
     * } $data
     * @return array<string, mixed>
     */
    public static function schemaArticle(array $data = []): array
    {
        $author = $data['author'] ?? self::$author ?? null;
        $author_schema = null;

        if (is_string($author) && $author !== '') {
            $author_schema = ['@type' => 'Person', 'name' => $author];
        } elseif (is_array($author)) {
            $author_schema = array_filter([
                '@type' => 'Person',
                'name' => $author['name'] ?? null,
            ]);
        }

        $image = $data['image'] ?? null;
        if ($image === null && self::$images !== []) {
            $image = self::resolveUrl(self::$images[0]['url']);
        } elseif (is_string($image)) {
            $image = self::resolveUrl($image);
        } elseif (is_array($image)) {
            $image = array_map(
                static fn ($item) => is_string($item) ? self::resolveUrl($item) : $item,
                $image,
            );
        }

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $data['headline'] ?? self::resolvedTitle(),
            'description' => $data['description'] ?? self::resolvedDescription(),
            'image' => $image,
            'datePublished' => $data['datePublished'] ?? (self::$article['published_time'] ?? null),
            'dateModified' => $data['dateModified'] ?? (self::$article['modified_time'] ?? null),
            'author' => $author_schema,
            'url' => $data['url'] ?? self::resolvedOgUrl(),
        ], static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param array{
     *     name?: string,
     *     url?: string,
     *     logo?: string,
     *     sameAs?: string[]
     * } $data
     * @return array<string, mixed>
     */
    public static function schemaOrganization(array $data = []): array
    {
        $logo = $data['logo'] ?? self::envDefault('SEO_ORG_LOGO');
        $logo_schema = null;

        if (is_string($logo) && $logo !== '') {
            $logo_schema = [
                '@type' => 'ImageObject',
                'url' => self::resolveUrl($logo),
            ];
        }

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $data['name'] ?? self::envDefault('SEO_ORG_NAME') ?? self::resolvedSiteName(),
            'url' => $data['url'] ?? (APP['base_url'] ?? ''),
            'logo' => $logo_schema,
            'sameAs' => $data['sameAs'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param array<int, array{name: string, url: string}> $crumbs
     * @return array<string, mixed>
     */
    public static function schemaBreadcrumb(array $crumbs): array
    {
        $items = [];
        $position = 1;

        foreach ($crumbs as $crumb) {
            $items[] = array_filter([
                '@type' => 'ListItem',
                'position' => $position,
                'name' => $crumb['name'] ?? '',
                'item' => isset($crumb['url']) ? self::resolveUrl((string) $crumb['url']) : null,
            ]);
            $position++;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }

    /**
     * Render all SEO meta tags as HTML for the layout <head>.
     */
    public static function render(): string
    {
        $lines = [];

        $lines[] = '<title>' . self::escape(self::resolvedTitle()) . '</title>';

        $description = self::resolvedDescription();
        if ($description !== '') {
            $lines[] = self::metaTag('name', 'description', $description);
        }

        if (self::$keywords !== null && self::$keywords !== '') {
            $lines[] = self::metaTag('name', 'keywords', self::$keywords);
        }

        if (self::$author !== null && self::$author !== '') {
            $lines[] = self::metaTag('name', 'author', self::$author);
        }

        $robots = self::resolvedRobots();
        if ($robots !== '') {
            $lines[] = self::metaTag('name', 'robots', $robots);
        }

        $canonical = self::resolvedCanonical();
        if ($canonical !== '') {
            $lines[] = '<link rel="canonical" href="' . self::escape($canonical) . '">';
        }

        $og_title = self::$title ?? self::resolvedTitle();
        $lines[] = self::metaTag('property', 'og:title', $og_title);
        $lines[] = self::metaTag('property', 'og:site_name', self::resolvedSiteName());
        $lines[] = self::metaTag('property', 'og:type', self::resolvedOgType());
        $lines[] = self::metaTag('property', 'og:locale', self::resolvedLocale());

        $og_url = self::resolvedOgUrl();
        if ($og_url !== '') {
            $lines[] = self::metaTag('property', 'og:url', $og_url);
        }

        if ($description !== '') {
            $lines[] = self::metaTag('property', 'og:description', $description);
        }

        foreach (self::resolvedImages() as $image) {
            $lines[] = self::metaTag('property', 'og:image', $image['url']);

            if ($image['width'] !== null) {
                $lines[] = self::metaTag('property', 'og:image:width', (string) $image['width']);
            }

            if ($image['height'] !== null) {
                $lines[] = self::metaTag('property', 'og:image:height', (string) $image['height']);
            }

            if ($image['alt'] !== null && $image['alt'] !== '') {
                $lines[] = self::metaTag('property', 'og:image:alt', $image['alt']);
            }
        }

        foreach (self::articleMetaTags() as $tag) {
            $lines[] = self::metaTag('property', $tag['property'], $tag['content']);
        }

        $twitter_card = self::resolvedTwitterCard();
        $lines[] = self::metaTag('name', 'twitter:card', $twitter_card);
        $lines[] = self::metaTag('name', 'twitter:title', $og_title);

        if ($description !== '') {
            $lines[] = self::metaTag('name', 'twitter:description', $description);
        }

        $twitter_site = self::resolvedTwitterSite();
        if ($twitter_site !== '') {
            $lines[] = self::metaTag('name', 'twitter:site', $twitter_site);
        }

        if (self::$twitter_creator !== null && self::$twitter_creator !== '') {
            $lines[] = self::metaTag('name', 'twitter:creator', self::$twitter_creator);
        }

        $primary_image = self::resolvedImages()[0]['url'] ?? '';
        if ($primary_image !== '') {
            $lines[] = self::metaTag('name', 'twitter:image', $primary_image);
        }

        foreach (self::resolvedJsonLd() as $schema) {
            $json = json_encode(
                $schema,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
            $lines[] = '<script type="application/ld+json">' . $json . '</script>';
        }

        return implode("\n    ", $lines);
    }

    /**
     * Structured data for a caller to pass into View::render()/share() explicitly, or for
     * custom partial rendering. Not injected automatically — View has no implicit variable
     * injection (§24.4), so whoever wants this in a template passes it in themselves.
     *
     * @return array<string, mixed>
     */
    public static function toViewData(): array
    {
        return [
            'title' => self::resolvedTitle(),
            'raw_title' => self::$title,
            'description' => self::resolvedDescription(),
            'keywords' => self::$keywords,
            'author' => self::$author,
            'locale' => self::resolvedLocale(),
            'site_name' => self::resolvedSiteName(),
            'og_type' => self::resolvedOgType(),
            'og_url' => self::resolvedOgUrl(),
            'canonical' => self::resolvedCanonical(),
            'robots' => self::resolvedRobots(),
            'images' => self::resolvedImages(),
            'article' => self::$article,
            'twitter_card' => self::resolvedTwitterCard(),
            'twitter_site' => self::resolvedTwitterSite(),
            'twitter_creator' => self::$twitter_creator,
            'json_ld' => self::resolvedJsonLd(),
        ];
    }

    /**
     * Clear all SEO state (useful between tests or CLI runs).
     */
    public static function reset(): void
    {
        self::$title = null;
        self::$title_template = null;
        self::$description = null;
        self::$keywords = null;
        self::$author = null;
        self::$locale = null;
        self::$site_name = null;
        self::$og_type = null;
        self::$og_url = null;
        self::$canonical_url = null;
        self::$robots = null;
        self::$twitter_card = null;
        self::$twitter_site = null;
        self::$twitter_creator = null;
        self::$images = [];
        self::$article = [];
        self::$json_ld = [];
    }

    private static function resolvedTitle(): string
    {
        $app_name = APP['name'] ?? 'Application';
        $page_title = self::$title;

        if ($page_title === null || $page_title === '') {
            return $app_name;
        }

        $template = self::$title_template ?? '%s | ' . $app_name;

        if (str_contains($template, '%s')) {
            return sprintf($template, $page_title);
        }

        return $page_title;
    }

    private static function resolvedDescription(): string
    {
        $description = self::$description
            ?? self::envDefault('SEO_DEFAULT_DESCRIPTION')
            ?? '';

        if ($description === '') {
            return '';
        }

        return self::truncate($description, self::DESCRIPTION_MAX);
    }

    private static function resolvedSiteName(): string
    {
        return self::$site_name
            ?? self::envDefault('SEO_ORG_NAME')
            ?? (APP['name'] ?? 'Application');
    }

    private static function resolvedLocale(): string
    {
        return self::$locale
            ?? self::envDefault('SEO_LOCALE')
            ?? 'en_US';
    }

    private static function resolvedOgType(): string
    {
        return self::$og_type ?? 'website';
    }

    private static function resolvedOgUrl(): string
    {
        if (self::$og_url !== null && self::$og_url !== '') {
            return self::$og_url;
        }

        return self::currentUrl();
    }

    private static function resolvedCanonical(): string
    {
        return self::$canonical_url ?? '';
    }

    private static function resolvedRobots(): string
    {
        return self::$robots ?? '';
    }

    private static function resolvedTwitterCard(): string
    {
        return self::$twitter_card ?? 'summary_large_image';
    }

    private static function resolvedTwitterSite(): string
    {
        if (self::$twitter_site !== null && self::$twitter_site !== '') {
            return self::$twitter_site;
        }

        return self::normalizeTwitterHandle(self::envDefault('SEO_TWITTER_SITE') ?? '');
    }

    /**
     * @return array<int, array{url: string, width: ?int, height: ?int, alt: ?string}>
     */
    private static function resolvedImages(): array
    {
        $images = self::$images;

        if ($images === []) {
            $default = self::envDefault('SEO_DEFAULT_IMAGE');
            if ($default !== null && $default !== '') {
                $images = [['url' => $default, 'width' => null, 'height' => null, 'alt' => null]];
            }
        }

        $resolved = [];

        foreach ($images as $image) {
            if ($image['url'] === '') {
                continue;
            }

            $resolved[] = [
                'url' => self::resolveUrl($image['url']),
                'width' => $image['width'],
                'height' => $image['height'],
                'alt' => $image['alt'],
            ];
        }

        return $resolved;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function resolvedJsonLd(): array
    {
        return self::$json_ld;
    }

    /**
     * @return array<int, array{property: string, content: string}>
     */
    private static function articleMetaTags(): array
    {
        if (self::$article === []) {
            return [];
        }

        $tags = [];

        $map = [
            'published_time' => 'article:published_time',
            'modified_time' => 'article:modified_time',
            'expiration_time' => 'article:expiration_time',
            'author' => 'article:author',
            'section' => 'article:section',
        ];

        foreach ($map as $key => $property) {
            if (!empty(self::$article[$key])) {
                $tags[] = [
                    'property' => $property,
                    'content' => (string) self::$article[$key],
                ];
            }
        }

        if (!empty(self::$article['tags']) && is_array(self::$article['tags'])) {
            foreach (self::$article['tags'] as $tag) {
                if (is_string($tag) && $tag !== '') {
                    $tags[] = [
                        'property' => 'article:tag',
                        'content' => $tag,
                    ];
                }
            }
        }

        return $tags;
    }

    private static function applyRobotsDirective(string $directive, bool $enabled): void
    {
        $parts = self::$robots !== null && self::$robots !== ''
            ? array_map('trim', explode(',', self::$robots))
            : ['index', 'follow'];

        $parts = array_filter($parts, static fn ($part) => $part !== '');

        $opposite = $directive === 'noindex' ? 'index' : 'follow';
        $parts = array_values(array_filter(
            $parts,
            static fn ($part) => strcasecmp($part, $directive) !== 0 && strcasecmp($part, $opposite) !== 0,
        ));

        if ($enabled) {
            $parts[] = $directive;
        } else {
            $parts[] = $opposite;
        }

        self::$robots = implode(', ', array_unique($parts));
    }

    private static function metaTag(string $attr, string $name, string $content): string
    {
        return sprintf(
            '<meta %s="%s" content="%s">',
            $attr,
            self::escape($name),
            self::escape($content),
        );
    }

    private static function normalizeTwitterHandle(string $handle): string
    {
        $handle = trim($handle);

        if ($handle === '') {
            return '';
        }

        return str_starts_with($handle, '@') ? $handle : '@' . ltrim($handle, '@');
    }

    private static function currentUrl(): string
    {
        $scheme = self::requestScheme();
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        return $scheme . '://' . $host . $uri;
    }

    private static function resolveUrl(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        $base = rtrim(APP['base_url'] ?? '', '/');

        if ($base !== '' && preg_match('#^https?://#i', $base) === 1) {
            return $base . '/' . ltrim($path, '/');
        }

        $scheme = self::requestScheme();
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return $scheme . '://' . $host . '/' . ltrim($path, '/');
    }

    private static function requestScheme(): string
    {
        $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

        return $is_https ? 'https' : 'http';
    }

    private static function truncate(string $text, int $max): string
    {
        $text = trim($text);

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $truncated = mb_substr($text, 0, $max);
        $last_space = mb_strrpos($truncated, ' ');

        if ($last_space !== false && $last_space > (int) ($max * 0.6)) {
            $truncated = mb_substr($truncated, 0, $last_space);
        }

        return rtrim($truncated, ".,;:!?") . '…';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private static function envDefault(string $key): ?string
    {
        $value = getenv($key);

        if ($value === false || $value === '') {
            return null;
        }

        return $value;
    }
}
