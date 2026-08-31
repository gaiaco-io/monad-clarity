<?php

declare(strict_types=1);

namespace Monad\Clarity\Services\Scheduler;

/**
 * What became of one run of one job. Three cases, and there is deliberately no fourth for
 * "skipped": a tick that stands down — because the previous run is still going, or because
 * another node claimed the slot first — writes no row at all. A row exists only where work
 * was actually attempted, which keeps `scheduled_runs` a record of what ran rather than a
 * log of what didn't.
 *
 * @package Monad\Clarity\Services\Scheduler
 * @author Marshal Yung <marshal.yung@gaiaco.io>
 */
enum RunState: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
