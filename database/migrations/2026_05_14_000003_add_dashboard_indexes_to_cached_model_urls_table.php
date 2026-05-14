<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cached_model_urls')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('CREATE INDEX cached_model_urls_site_language_path_index ON cached_model_urls (site_id, language_id, path(191))');

            return;
        }

        Schema::table('cached_model_urls', function (Blueprint $table): void {
            $table->index(['site_id', 'language_id', 'path'], 'cached_model_urls_site_language_path_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cached_model_urls')) {
            return;
        }

        Schema::table('cached_model_urls', function (Blueprint $table): void {
            $table->dropIndex('cached_model_urls_site_language_path_index');
        });
    }
};
