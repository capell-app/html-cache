<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\AccessGate;

use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ActiveAccessGateAreaResolver
{
    public function hasActiveArea(): bool
    {
        $connectionName = $this->connectionName();
        $defaultAreaKey = $this->defaultAreaKey();
        $cacheSeconds = $this->activeAreaCacheSeconds();

        if ($cacheSeconds < 1) {
            return $this->queryHasActiveArea($connectionName, $defaultAreaKey);
        }

        return (bool) Cache::remember(
            $this->cacheKey($connectionName, $defaultAreaKey),
            $cacheSeconds,
            fn (): bool => $this->queryHasActiveArea($connectionName, $defaultAreaKey),
        );
    }

    private function queryHasActiveArea(?string $connectionName, string $defaultAreaKey): bool
    {
        $schema = $connectionName !== null
            ? Schema::connection($connectionName)
            : Schema::getFacadeRoot();

        if (! $schema instanceof SchemaBuilder || ! $schema->hasTable('access_gate_areas')) {
            return false;
        }

        $query = $connectionName !== null
            ? DB::connection($connectionName)->table('access_gate_areas')
            : DB::table('access_gate_areas');

        return $query
            ->where('key', $defaultAreaKey)
            ->where('status', 'active')
            ->exists();
    }

    private function connectionName(): ?string
    {
        $connectionName = config('access-gate.connection');

        return is_string($connectionName) && $connectionName !== '' ? $connectionName : null;
    }

    private function defaultAreaKey(): string
    {
        $defaultAreaKey = config('access-gate.install.default_area.key', 'capell-preview');

        return is_string($defaultAreaKey) && $defaultAreaKey !== '' ? $defaultAreaKey : 'capell-preview';
    }

    private function activeAreaCacheSeconds(): int
    {
        $cacheSeconds = config('capell-html-cache.access_gate.active_area_cache_seconds', 5);

        return is_numeric($cacheSeconds) ? max(0, (int) $cacheSeconds) : 5;
    }

    private function cacheKey(?string $connectionName, string $defaultAreaKey): string
    {
        return 'capell-html-cache:access-gate-active-area:' . sha1(($connectionName ?? 'default') . '|' . $defaultAreaKey);
    }
}
