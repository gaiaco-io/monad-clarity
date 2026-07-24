<?php

declare(strict_types=1);

use Monad\Clarity\Services\Schema;
use Monad\Clarity\Services\Schema\Blueprint;

return new class {
    public function up(): void
    {
        Schema::createTable('widgets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
    }

    public function down(): void
    {
        Schema::dropTable('widgets');
    }
};
