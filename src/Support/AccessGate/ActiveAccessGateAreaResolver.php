<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\AccessGate;

use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Closure;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ActiveAccessGateAreaResolver
{
    public function hasBrowserToken(Request $request): bool
    {
        $cookieName = config('access-gate.cookies.browser_token.name', 'capell_access_gate_browser_token');
        if (! is_string($cookieName) || $cookieName === '') {
            return false;
        }

        if ($request->cookies->has($cookieName)) {
            return true;
        }

        $cookieHeader = $request->headers->get('Cookie');

        return is_string($cookieHeader) && str_contains($cookieHeader, $cookieName . '=');
    }

    public function hasActiveArea(): bool
    {
        $connectionName = $this->connectionName();
        $defaultAreaKey = $this->defaultAreaKey();
        $cacheSeconds = $this->activeAreaCacheSeconds();

        if ($cacheSeconds < 1) {
            return $this->queryHasActiveArea($connectionName, $defaultAreaKey);
        }

        return (bool) Cache::memo()->remember(
            $this->cacheKey($connectionName, $defaultAreaKey),
            $cacheSeconds,
            fn (): bool => $this->queryHasActiveArea($connectionName, $defaultAreaKey),
        );
    }

    public function refreshActiveArea(): bool
    {
        $this->forgetCachedActiveArea();

        return $this->hasActiveArea();
    }

    public function forgetCachedActiveArea(): void
    {
        Cache::memo()->forget($this->cacheKey($this->connectionName(), $this->defaultAreaKey()));
    }

    private function queryHasActiveArea(?string $connectionName, string $defaultAreaKey): bool
    {
        if (! $this->areasTableExists($connectionName)) {
            return false;
        }

        $query = $connectionName !== null
            ? DB::connection($connectionName)->table('access_gate_areas')
            : DB::table('access_gate_areas');

        if (CapellCore::isPackageAvailable('capell-app/access-gate') && app()->bound('capell.access-gate.area-lookup')) {
            $lookup = app()->make('capell.access-gate.area-lookup');

            if ($lookup instanceof Closure) {
                $area = $lookup($defaultAreaKey);

                return is_object($area) && ($area->status->value ?? null) === 'active';
            }
        }

        return $query->where('key', $defaultAreaKey)->where('status', 'active')->exists();
    }

    private function areasTableExists(?string $connectionName): bool
    {
        // The default connection shares the request's schema probe with every other
        // consumer of this table; a dedicated connection keeps its own builder.
        if ($connectionName === null) {
            return resolve(RuntimeSchemaState::class)->hasTable('access_gate_areas');
        }

        $schema = Schema::connection($connectionName);

        return $schema instanceof SchemaBuilder && $schema->hasTable('access_gate_areas');
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
        return 'capell-html-cache:access-gate-active-area:' . hash('sha256', ($connectionName ?? 'default') . '|' . $defaultAreaKey);
    }
}
