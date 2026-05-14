<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stale_cached_urls', function (Blueprint $table): void {
            if (! Schema::hasColumn('stale_cached_urls', 'claim_token')) {
                $table->string('claim_token', 64)->nullable()->after('status');
            }

            $table->index(['status', 'failed_at', 'created_at'], 'stale_cached_urls_status_failed_created_index');
            $table->index(['status', 'updated_at', 'created_at'], 'stale_cached_urls_status_updated_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('stale_cached_urls', function (Blueprint $table): void {
            $table->dropIndex('stale_cached_urls_status_failed_created_index');
            $table->dropIndex('stale_cached_urls_status_updated_created_index');

            if (Schema::hasColumn('stale_cached_urls', 'claim_token')) {
                $table->dropColumn('claim_token');
            }
        });
    }
};
