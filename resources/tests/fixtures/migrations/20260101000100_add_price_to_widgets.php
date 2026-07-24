<?php

declare(strict_types=1);

use Monad\Clarity\Services\Schema;
use Monad\Clarity\Services\Schema\Blueprint;

return new class {
    public function up(): void
    {
        Schema::alterTable('widgets', function (Blueprint $table) {
            $table->integer('price', nullable: true);
        });
    }

    public function down(): void
    {
        Schema::dropColumn('widgets', 'price');
    }
};
