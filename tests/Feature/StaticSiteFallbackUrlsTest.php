<?php

declare(strict_types=1);

use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Translation;
use Capell\HtmlCache\Actions\RecordCachedModelUrlsAction;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Support\Cache\HtmlCachePathResolver;
use Capell\HtmlCache\Support\StaticSite\StaticSiteExtensionRegistry;
use Capell\HtmlCache\Support\StaticSite\StaticSiteGenerator;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(HtmlCacheTestCase::class);

/**
 * An English and a French domain (public IPs, so the outbound visitor accepts them) on one site, French falling back to English.
 * "/about" has only an English translation, so the French domain serves it at
 * the English path; "/contact" has its own French translation and URL.
 *
 * @return array{site: Site, english: SiteDomain, french: SiteDomain}
 */
function staticFallbackSite(): array
{
    return Model::withoutEvents(function (): array {
        $english = Language::factory()->english(isDefault: true)->createOne();
        $french = Language::factory()->french()->createOne(['fallback_language_id' => $english->getKey()]);
        $site = Site::factory()->createOne();
        $englishDomain = SiteDomain::factory()->site($site)->language($english)->createOne(['scheme' => 'https', 'domain' => '8.8.8.8', 'path' => null, 'port' => null, 'status' => true]);
        $frenchDomain = SiteDomain::factory()->site($site)->language($french)->createOne(['scheme' => 'https', 'domain' => '8.8.4.4', 'path' => null, 'port' => null, 'status' => true]);

        $about = Page::factory()->site($site)->withTranslations($english)->createOne(['visible_from' => now()->subDay(), 'visible_until' => null]);
        PageUrl::factory()->page($about)->site($site)->language($english)->createOne(['url' => '/about']);

        $contact = Page::factory()->site($site)->withTranslations($english)->createOne(['visible_from' => now()->subDay(), 'visible_until' => null]);
        Translation::factory()->translatable($contact)->language($french)->createOne(['title' => 'Contact FR']);
        PageUrl::factory()->page($contact)->site($site)->language($english)->createOne(['url' => '/contact']);
        PageUrl::factory()->page($contact)->site($site)->language($french)->createOne(['url' => '/nous-contacter']);

        return ['site' => $site, 'english' => $englishDomain, 'french' => $frenchDomain];
    });
}

it('generates the fallback-language URLs a domain serves for untranslated pages', function (): void {
    $fixture = staticFallbackSite();
    app()->instance(StaticSiteExtensionRegistry::class, new StaticSiteExtensionRegistry);
    config(['capell-html-cache.static_generation.internal_requests' => false]);
    Http::fake(fn () => Http::response('ok', 200));

    $visited = [];
    $totals = [];
    (new StaticSiteGenerator($fixture['site']))->process(
        prepare: function (int $total, SiteDomain $domain) use (&$totals): void {
            $totals[$domain->domain] = $total;
        },
        checkpoint: function (string $url) use (&$visited): void {
            $visited[] = $url;
        },
    );

    // The French domain answers /about with the English page, so static output must hold it;
    // /contact has a French translation and is served only at /nous-contacter.
    expect($visited)->toEqualCanonicalizing([
        'https://8.8.8.8/about',
        'https://8.8.8.8/contact',
        'https://8.8.4.4/nous-contacter',
        'https://8.8.4.4/about',
    ])
        ->and($totals)->toBe(['8.8.8.8' => 2, '8.8.4.4' => 2]);
});

it('purges the cached French fallback URL when the English translation is edited', function (): void {
    Storage::fake('page_cache');
    config()->set('queue.default', 'sync');
    config()->set('capell-html-cache.invalidation.mode', 'instant');
    $fixture = staticFallbackSite();
    $about = PageUrl::query()->where('url', '/about')->sole()->pageable;
    throw_unless($about instanceof Page, RuntimeException::class, 'Expected the /about page.');
    $englishTranslation = $about->translations()->where('language_id', $fixture['english']->language_id)->sole();

    $resolver = resolve(HtmlCachePathResolver::class);
    $frenchUrl = 'https://8.8.4.4/about';
    $frenchPath = $resolver->pathForUrl('/about', $fixture['french']);
    $englishPath = $resolver->pathForUrl('/about', $fixture['english']);
    Storage::disk('page_cache')->put($frenchPath, 'Cached French fallback');
    Storage::disk('page_cache')->put($englishPath, 'Cached English');
    // A fallback render on the French domain retrieves the English page and its English translation.
    $rendered = [$about->getMorphClass() => [$about->id], $englishTranslation->getMorphClass() => [$englishTranslation->id]];
    RecordCachedModelUrlsAction::run($frenchUrl, $rendered, CarbonImmutable::now()->subMinute());
    RecordCachedModelUrlsAction::run('https://8.8.8.8/about', $rendered, CarbonImmutable::now()->subMinute());

    $englishTranslation->update(['title' => 'About us, revised']);

    expect(Storage::disk('page_cache')->exists($frenchPath))->toBeFalse()
        ->and(Storage::disk('page_cache')->exists($englishPath))->toBeFalse()
        ->and(CachedModelUrl::query()->where('url', $frenchUrl)->exists())->toBeFalse();
});
