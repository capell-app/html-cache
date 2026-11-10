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
        Schema::create(TableName::resolve('html_cache_model_withdrawals'), function (Blueprint $table): void {
            $table->id();
            $table->string('model_key', 191);
            $table->dateTime('withdrawn_at', 6);
            $table->index(['model_key', 'id'], TableName::namedIndex('html_cache_model_withdrawals', 'html_cache_model_withdrawals_key_sequence_index'));
            $table->index('withdrawn_at', TableName::namedIndex('html_cache_model_withdrawals', 'html_cache_model_withdrawals_withdrawn_at_index'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('html_cache_model_withdrawals'));
    }
};
