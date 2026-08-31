<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Mail;

use InvalidArgumentException;

/**
 * A file travelling with a message, held as raw bytes.
 *
 * Bytes, never a path: an adapter that accepted a path would have to read it at send time,
 * which puts a filesystem in the middle of every provider integration and turns a missing
 * file into a failure the pool cannot classify. The caller reads the file; this holds what
 * it read.
 *
 * An attachment with a $contentId is *inline* — the thing an HTML body references as
 * `<img src="cid:...">`, which MimeMessage renders as a `multipart/related` part rather
 * than a downloadable one.
 *
 * @package Monad\Clarity\Services\Mail
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final readonly class Attachment
{
    public string $filename;
    public string $contentType;
    public string $contents;
    public ?string $contentId;

    public function __construct(
        string $filename,
        string $contentType,
        string $contents,
        ?string $contentId = null,
    ) {
        Header::assertNoInjection($filename, 'attachment filename');
        Header::assertNoInjection($contentType, 'attachment content type');

        $filename = trim($filename);

        if ($filename === '') {
            throw new InvalidArgumentException('An attachment requires a filename.');
        }

        // The filename is written into a Content-Disposition header and is frequently used
        // by receiving clients as a save-to-disk name. A directory separator there is either
        // a mistake or an attempt at traversal, and neither should reach the recipient.
        if (str_contains($filename, '/') || str_contains($filename, '\\')) {
            throw new InvalidArgumentException(sprintf(
                'The attachment filename "%s" contains a directory separator. Pass the base name; '
                . 'a path here is written into the header a mail client saves the file under.',
                $filename
            ));
        }

        if (preg_match('#^[a-z0-9][a-z0-9!\#$&^_.+-]*/[a-z0-9][a-z0-9!\#$&^_.+-]*$#i', $contentType) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'The attachment content type "%s" is not a valid MIME type such as "application/pdf".',
                $contentType
            ));
        }

        if ($contents === '') {
            throw new InvalidArgumentException(sprintf(
                'The attachment "%s" is empty. An empty part is silently dropped by some providers '
                . 'and delivered as a broken file by others.',
                $filename
            ));
        }

        if ($contentId !== null) {
            Header::assertNoInjection($contentId, 'attachment content ID');

            $contentId = trim($contentId, " \t<>");

            if ($contentId === '') {
                throw new InvalidArgumentException(sprintf(
                    'The attachment "%s" was given an empty content ID. Omit it entirely for an '
                    . 'ordinary attachment, or pass the token the HTML references as "cid:token".',
                    $filename
                ));
            }
        }

        $this->filename = $filename;
        $this->contentType = $contentType;
        $this->contents = $contents;
        $this->contentId = $contentId;
    }

    /**
     * An attachment the HTML body references as `<img src="cid:$contentId">`.
     */
    public static function inline(
        string $filename,
        string $contentType,
        string $contents,
        string $contentId,
    ): self {
        return new self($filename, $contentType, $contents, $contentId);
    }

    public function isInline(): bool
    {
        return $this->contentId !== null;
    }

    public function disposition(): string
    {
        return $this->isInline() ? 'inline' : 'attachment';
    }
}
