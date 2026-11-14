<?php

declare(strict_types=1);

namespace Monad\Clarity\Tests\Services\LLM;

use InvalidArgumentException;
use Monad\Clarity\Services\LLM\ImageRequest;
use Monad\Clarity\Services\LLM\ReferenceImage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageRequestTest extends TestCase
{
    public function testConstructsWithDefaultsFromMinimalArguments(): void
    {
        $request = new ImageRequest(model: 'gemini-nano-banana-2.1', prompt: 'A lighthouse at dusk');

        self::assertSame('gemini-nano-banana-2.1', $request->model);
        self::assertSame('A lighthouse at dusk', $request->prompt);
        self::assertSame([], $request->referenceImages);
        self::assertNull($request->aspectRatio);
        self::assertNull($request->imageSize);
        self::assertNull($request->outputMimeType);
        self::assertSame(120, $request->timeoutSeconds);
    }

    public function testAcceptsEveryOptionalField(): void
    {
        $reference = new ReferenceImage('image/png', 'png-bytes');

        $request = new ImageRequest(
            model: 'gemini-nano-banana-2.1',
            prompt: 'Make it green',
            referenceImages: [$reference],
            aspectRatio: '8:1',
            imageSize: '4K',
            outputMimeType: 'image/jpeg',
            timeoutSeconds: 300,
        );

        self::assertSame([$reference], $request->referenceImages);
        self::assertSame('8:1', $request->aspectRatio);
        self::assertSame('4K', $request->imageSize);
        self::assertSame('image/jpeg', $request->outputMimeType);
        self::assertSame(300, $request->timeoutSeconds);
    }

    public function testPassesThroughAnAspectRatioAndSizeNoListNames(): void
    {
        $request = new ImageRequest(model: 'm', prompt: 'p', aspectRatio: '37:11', imageSize: '512');

        self::assertSame('37:11', $request->aspectRatio);
        self::assertSame('512', $request->imageSize);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidArguments(): iterable
    {
        yield 'empty model' => [['model' => ''], 'requires a non-empty $model'];
        yield 'empty prompt' => [['prompt' => ''], 'requires a $prompt'];
        yield 'whitespace prompt' => [['prompt' => "  \n "], 'requires a $prompt'];
        yield 'keyed reference list' => [['referenceImages' => ['a' => new ReferenceImage('image/png', 'x')]], 'must be a list'];
        yield 'non-image reference' => [['referenceImages' => ['raw-bytes']], 'found string'];
        yield 'ratio with a decimal' => [['aspectRatio' => '1.5:1'], 'width:height in whole numbers'];
        yield 'ratio as a word' => [['aspectRatio' => 'square'], 'width:height in whole numbers'];
        yield 'ratio with a zero' => [['aspectRatio' => '0:1'], 'width:height in whole numbers'];
        yield 'empty size' => [['imageSize' => ' '], 'must be null or a size'];
        yield 'non-image output type' => [['outputMimeType' => 'application/pdf'], '"application/pdf" is not one'];
        yield 'zero timeout' => [['timeoutSeconds' => 0], 'at least 1'];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('invalidArguments')]
    public function testRejectsInvalidArgumentsWithAMessageNamingTheProblem(array $override, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new ImageRequest(...array_merge(['model' => 'gemini-nano-banana-2.1', 'prompt' => 'A lighthouse'], $override));
    }
}
