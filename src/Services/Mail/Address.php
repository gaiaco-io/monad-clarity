<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Mail;

use InvalidArgumentException;

/**
 * One mailbox: an address, and optionally the name shown beside it.
 *
 * Validated at construction, so a malformed recipient fails in the code that built it
 * rather than as a provider's 422 several layers away — and, for a pool, before any mailer
 * spends a round trip discovering what all of them would have said.
 *
 * @package Monad\Clarity\Services\Mail
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final readonly class Address
{
    public string $email;
    public ?string $name;

    public function __construct(string $email, ?string $name = null)
    {
        Header::assertNoInjection($email, 'email address');

        $email = trim($email);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException(
                sprintf('"%s" is not a valid email address.', $email)
            );
        }

        if ($name !== null) {
            Header::assertNoInjection($name, 'display name');

            $name = trim($name);

            if ($name === '') {
                $name = null;
            }
        }

        $this->email = $email;
        $this->name = $name;
    }

    /**
     * The domain half, lowercased — used to build a `Message-ID` that belongs to the sending
     * domain rather than to whatever host happens to be running the process.
     */
    public function domain(): string
    {
        return strtolower(substr($this->email, strrpos($this->email, '@') + 1));
    }

    /**
     * RFC 5322 form: `Display Name <someone@example.com>`. UTF-8 is returned as-is here;
     * MimeMessage asks Header for the encoded variant when it needs one.
     */
    public function format(): string
    {
        return Header::formatAddress($this);
    }
}
