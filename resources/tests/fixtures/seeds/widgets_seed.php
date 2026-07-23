<?php

declare(strict_types=1);

use Gaia\Clarity\Services\DB;

return function () {
    DB::insert('widgets', ['id' => 'seed-1', 'name' => 'Seeded Widget']);
};
