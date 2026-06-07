<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cached_model_urls', function (Blueprint $table): void {
            $table->unsignedBigInteger('hit_count')->default(0)->after('last_seen_at');
            $table->unsignedBigInteger('bytes_served')->default(0)->after('hit_count');
            $table->timestamp('last_hit_at')->nullable()->after('bytes_served');
            $table->index('last_hit_at', 'cached_model_urls_last_hit_index');
        });
    }

    public function down(): void
    {
        Schema::table('cached_model_urls', function (Blueprint $table): void {
            $table->dropIndex('cached_model_urls_last_hit_index');
            $table->dropColumn(['hit_count', 'bytes_served', 'last_hit_at']);
        });
    }
};
