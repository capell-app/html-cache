<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('html_cache_generation_runs', function (Blueprint $table): void {
            $table->json('site_ids')->nullable()->after('total_sites');
            $table->boolean('enable_global')->default(false)->after('site_ids');
            $table->unsignedInteger('activate_site_id')->nullable()->after('enable_global');
        });
    }

    public function down(): void
    {
        Schema::table('html_cache_generation_runs', function (Blueprint $table): void {
            $table->dropColumn(['site_ids', 'enable_global', 'activate_site_id']);
        });
    }
};
