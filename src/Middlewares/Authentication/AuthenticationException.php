<?php

declare(strict_types=1);

namespace Monad\Clarity\Middlewares\Authentication;

use RuntimeException;

/**
 * Thrown for failures Authentication cannot express as an AuthResult — Google SSO's
 * token exchange/verification failing outright (misconfiguration, network failure,
 * a rejected id_token), rather than a normal "wrong password" outcome.
 *
 * @package Monad\Clarity\Middlewares\Authentication
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class AuthenticationException extends RuntimeException
{
}
