<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cached_model_urls', function (Blueprint $table): void {
            $table->id();
            $table->string('url', 2048);
            $table->char('url_hash', 64);
            $table->string('path', 2048);
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('site_domain_id')->nullable()->constrained('site_domains')->nullOnDelete();
            $table->foreignId('language_id')->nullable()->constrained('languages')->nullOnDelete();
            $table->morphs('cacheable');
            $table->timestamp('cached_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['url_hash', 'cacheable_type', 'cacheable_id'], 'cached_model_urls_unique_url_model');
            $table->index('url_hash');
            $table->index(['cacheable_type', 'cacheable_id'], 'cached_model_urls_model_index');
            $table->index(['site_id', 'language_id'], 'cached_model_urls_site_language_index');
            $table->index(['site_id', 'last_seen_at'], 'cached_model_urls_site_last_seen_index');
            $table->index('site_domain_id');
            $table->index('last_seen_at', 'cached_model_urls_last_seen_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cached_model_urls');
    }
};
