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
        Schema::create(TableName::resolve('html_cache_request_observations'), function (Blueprint $table): void {
            $table->char('url_hash', 64);
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('language_id');
            $table->unsignedBigInteger('weighted_hits')->default(0);
            $table->unsignedBigInteger('weighted_misses')->default(0);
            $table->double('last_render_milliseconds')->nullable();
            $table->dateTime('observation_started_at', 6);
            $table->dateTime('last_observed_at', 6);
            $table->dateTime('last_rendered_at', 6)->nullable();
            $table->primary(['site_id', 'language_id', 'url_hash'], TableName::namedIndex('html_cache_request_observations', 'html_cache_observation_scope_primary'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('html_cache_request_observations'));
    }
};
