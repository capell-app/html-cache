<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('html_cache_generation_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedInteger('total_sites');
            $table->unsignedInteger('completed_sites')->default(0);
            $table->unsignedInteger('failed_sites')->default(0);
            $table->json('errors')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('html_cache_generation_runs');
    }
};
