<?php

declare(strict_types=1);

/**
 * Fixture for MediatorIntegrationTest: boots Mediator::register() exactly as a real
 * public/index.php would, then lets an uncaught exception reach the real global
 * set_exception_handler wiring — proving the production JSON-leak fix end to end,
 * not just through the unit-level handleException($exception, $request) call.
 */

require __DIR__ . '/../../../vendor/autoload.php';

use Monad\Clarity\Services\Mediator;

Mediator::configure(debug: false);
Mediator::register();

throw new RuntimeException('fixture-triggered failure: hunter2');
