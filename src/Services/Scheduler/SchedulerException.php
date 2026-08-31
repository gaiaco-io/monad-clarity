<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Scheduler;

use RuntimeException;

/**
 * A schedule that cannot be honoured: a malformed cron expression, a job registered twice
 * under one name, or a nonsensical staleness window.
 *
 * Every one of these is thrown at **registration** time, from `app/routes/cli.php`, which
 * the console kernel loads before dispatching any command. A typo therefore breaks the very
 * next `mitosis` invocation loudly, rather than sitting quietly in a crontab and never
 * running the job it was supposed to run.
 *
 * @package Monad\Clarity\Services\Scheduler
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
final class SchedulerException extends RuntimeException
{
}
