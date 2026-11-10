<?php

declare(strict_types=1);

use Capell\Core\Actions\PublicRead\PublishPublicArtefactAction;
use Capell\Core\Support\PublicRead\PublicArtefactPermit;
use Capell\Core\Support\PublicRead\PublicReadEligibilityState;
use Capell\Frontend\Contracts\StaticErrorPageStore;
use Capell\Frontend\Contracts\StaticMaintenancePageStore;
use Capell\HtmlCache\Support\Cache\HtmlCachePublicationGuard;
use Capell\HtmlCache\Support\Cache\HtmlCacheStore;
use Capell\HtmlCache\Support\Error\HtmlCacheStaticErrorPageStore;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Capell\HtmlCache\Tests\Support\PublishesStaticPages;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Storage;

uses(HtmlCacheTestCase::class);

it('registers the frontend static error page store against the page cache disk', function (): void {
    Storage::fake('page_cache');

    $store = resolve(StaticErrorPageStore::class);

    expect($store)->toBeInstanceOf(HtmlCacheStaticErrorPageStore::class);

    PublishesStaticPages::put($store, 'errors/https.example.test/404.html', '<h1>Not Found</h1>');

    expect($store->exists('errors/https.example.test/404.html'))->toBeTrue()
        ->and($store->path('errors/https.example.test/404.html'))->not->toBeNull()
        ->and(Storage::disk('page_cache')->get('errors/https.example.test/404.html'))->toBe('<h1>Not Found</h1>');
});

it('rejects revoked and foreign permits without publishing static pages', function (string $contract): void {
    Storage::fake('page_cache');
    $store = resolve($contract);
    $state = resolve(PublicReadEligibilityState::class);
    $captured = null;
    expect(PublishPublicArtefactAction::run(
        $store,
        null,
        $state->freshStamp(),
        [],
        static function (PublicArtefactPermit $permit) use ($store, &$captured): bool {
            $captured = $permit;
            expect(fn () => resolve(HtmlCachePublicationGuard::class)->exclusive(static fn (): bool => true))
                ->toThrow(RuntimeException::class, 'Cannot reacquire the HTML cache publication lock');
            $store->put($permit, 'errors/valid.html', 'valid');

            $originalLock = config('capell-html-cache.deployment.publication_lock_path');
            config()->set('capell-html-cache.deployment.publication_lock_path', storage_path('framework/testing/foreign-static-pages.lock'));
            try {
                $foreign = new HtmlCacheStaticErrorPageStore(
                    new HtmlCacheStore(resolve(FilesystemManager::class)),
                    resolve(HtmlCachePublicationGuard::class),
                );
                expect(fn () => $foreign->put($permit, 'errors/foreign.html', 'foreign'))->toThrow(RuntimeException::class);
                expect($foreign->exists('errors/foreign.html'))->toBeFalse();
            } finally {
                config()->set('capell-html-cache.deployment.publication_lock_path', $originalLock);
            }

            return true;
        },
    ))->toBeTrue();
    expect($store->exists('errors/valid.html'))->toBeTrue();
    expect($captured)->toBeInstanceOf(PublicArtefactPermit::class);
    expect(fn () => $store->put($captured, 'errors/revoked.html', 'revoked'))->toThrow(RuntimeException::class);
    expect($store->exists('errors/revoked.html'))->toBeFalse();
})->with([StaticErrorPageStore::class, StaticMaintenancePageStore::class]);

it('refuses static publication without a readable build stamp', function (string $contract): void {
    Storage::fake('page_cache');
    $store = resolve($contract);
    expect(PublishPublicArtefactAction::run($store, null, null, [], static function (PublicArtefactPermit $permit) use ($store): bool {
        $store->put($permit, 'errors/refused.html', 'refused');

        return true;
    }))->toBeFalse();
    expect($store->exists('errors/refused.html'))->toBeFalse();
})->with([StaticErrorPageStore::class, StaticMaintenancePageStore::class]);
