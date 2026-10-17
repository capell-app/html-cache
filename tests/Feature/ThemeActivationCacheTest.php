<?php

declare(strict_types=1);

use Capell\Admin\Actions\Themes\SetActiveThemeForSitesAction;
use Capell\Admin\Data\Themes\SetActiveThemeForSitesData;
use Capell\Admin\Enums\Themes\ThemeActivationScope;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\HtmlCache\Http\Middleware\HtmlCacheMiddleware;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

uses(HtmlCacheTestCase::class, CreatesAdminUser::class);

it('refreshes unindexed public html after activating a theme and switching back', function (?int $port, ?string $mount): void {
    Storage::fake('page_cache');
    config()->set('queue.default', 'sync');
    config()->set('capell-html-cache.enabled', true);
    config()->set('capell-html-cache.minify_html', false);

    $domain = SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'theme-activation.test', 'port' => $port, 'path' => $mount]);
    $originalTheme = $domain->site->theme;
    expect($originalTheme)->toBeInstanceOf(Theme::class);
    $otherTheme = Theme::factory()->create();

    $fetch = function () use ($domain): SymfonyResponse {
        $request = Request::create(rtrim($domain->full_url, '/') . '/about');
        app()->instance('request', $request);

        return resolve(HtmlCacheMiddleware::class)->handle($request, function () use ($domain): Response {
            $themeId = $domain->site()->sole()->theme_id;

            return response('<html><head><link rel="stylesheet" href="/theme-' . $themeId . '.css"></head><body>Public page</body></html>', headers: ['Content-Type' => 'text/html']);
        });
    };

    expect($fetch()->getContent())->toContain('/theme-' . $originalTheme->id . '.css')
        ->and($fetch()->headers->get('X-Frontend-Cache'))->toBe('HIT')
        ->and(CachedModelUrl::query()->count())->toBe(0);

    foreach ([$otherTheme, $originalTheme] as $theme) {
        $this->actingAsAdmin();
        SetActiveThemeForSitesAction::run(new SetActiveThemeForSitesData(
            themeId: $theme->id,
            scope: ThemeActivationScope::SelectedSites,
            siteIds: [$domain->site_id],
        ));
        Auth::forgetGuards();

        $response = $fetch();
        expect($response->headers->get('X-Frontend-Cache'))->not->toBe('HIT')
            ->and($response->getContent())->toContain('/theme-' . $theme->id . '.css')
            ->and($fetch()->headers->get('X-Frontend-Cache'))->toBe('HIT');
    }
})->with([
    'standard root' => [null, null],
    'standard mount' => [null, '/studio'],
    'nonstandard port' => [8080, null],
]);
