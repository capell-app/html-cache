<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Capell\HtmlCache\Support\Cache\HtmlCacheDependencyTracking;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(TableName::resolve('html_cache_dependency_tracking_epochs'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('version');
            $table->dateTime('trusted_since', 6);
            $table->unique('version', TableName::namedIndex('html_cache_dependency_tracking_epochs', 'html_cache_dependency_tracking_epochs_version_unique'));
        });

        // With nothing indexed yet, no cached page predates current tracking (a fresh
        // install). Otherwise the first full clear vouches for every page written after it.
        $indexed = Schema::hasTable(TableName::resolve('cached_model_urls')) && DB::table(TableName::resolve('cached_model_urls'))->exists();

        if (! $indexed) {
            DB::table(TableName::resolve('html_cache_dependency_tracking_epochs'))->insert([
                'version' => HtmlCacheDependencyTracking::CURRENT_VERSION,
                'trusted_since' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('html_cache_dependency_tracking_epochs'));
    }
};
