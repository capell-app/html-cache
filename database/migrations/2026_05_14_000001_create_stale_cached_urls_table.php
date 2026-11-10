<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(TableName::resolve('stale_cached_urls'), function (Blueprint $table): void {
            $table->id();
            $table->string('url', 2048);
            $table->char('url_hash', 64);
            $table->string('path', 2048);
            $table->string('stale_key', 128)->unique();
            $table->foreignId('site_id')->nullable()->constrained(TableName::resolve('sites'))->nullOnDelete();
            $table->foreignId('site_domain_id')->nullable()->constrained(TableName::resolve('site_domains'))->nullOnDelete();
            $table->foreignId('language_id')->nullable()->constrained(TableName::resolve('languages'))->nullOnDelete();
            $table->string('cache_path', 2048)->nullable();
            $table->string('error_cache_path', 2048)->nullable();
            $table->string('reason', 120)->nullable();
            $table->string('status', 40)->default('pending');
            $table->string('claim_token', 64)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], TableName::namedIndex('stale_cached_urls', 'stale_cached_urls_status_created_index'));
            $table->index(['site_id', 'language_id'], TableName::namedIndex('stale_cached_urls', 'stale_cached_urls_site_language_index'));
            $table->index('site_domain_id');
            $table->index('url_hash');
            $table->index(['status', 'failed_at', 'created_at'], TableName::namedIndex('stale_cached_urls', 'stale_cached_urls_status_failed_created_index'));
            $table->index(['status', 'updated_at', 'created_at'], TableName::namedIndex('stale_cached_urls', 'stale_cached_urls_status_updated_created_index'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('stale_cached_urls'));
    }
};
