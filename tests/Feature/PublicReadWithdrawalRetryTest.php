<?php

declare(strict_types=1);

use Capell\Core\Actions\PublicRead\RecordPublicReadEligibilityChangeAction;
use Capell\Core\Actions\PublicRead\WithdrawPublicReadProjectionsAction;
use Capell\Core\Contracts\PublicRead\EvaluatesPublicPageReadCandidatesIndividually;
use Capell\Core\Contracts\PublicRead\FingerprintsPublicReadRules;
use Capell\Core\Contracts\PublicRead\PublicPageReadEligibility;
use Capell\Core\Data\PublicRead\PublicPageReadCandidateData;
use Capell\Core\Data\PublicRead\PublicReadStampData;
use Capell\Core\Exceptions\PublicReadWithdrawalFailedException;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\PublicRead\PublicArtefactPermit;
use Capell\Core\Support\PublicRead\PublicPageReadEligibilityRegistry;
use Capell\Core\Support\PublicRead\PublicReadEligibilityState;
use Capell\Core\Support\TableName;
use Capell\HtmlCache\Contracts\CachePurger;
use Capell\HtmlCache\Data\EdgeCachePurgeData;
use Capell\HtmlCache\PublicRead\HtmlCachePublicReadWithdrawalStep;
use Capell\HtmlCache\Support\Cache\HtmlCacheStore;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Capell\HtmlCache\Tests\Support\PublishesHtmlCacheArtefacts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(HtmlCacheTestCase::class);

it('keeps the HTML cache withdrawal owed when the edge purge fails, until the reconcile run completes it', function (): void {
    Storage::fake('page_cache');
    Model::withoutEvents(fn (): SiteDomain => SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'listing.example.test', 'path' => null]));
    resolve(PublicPageReadEligibilityRegistry::class)->register(new readonly class implements PublicPageReadEligibility
    {
        use EvaluatesPublicPageReadCandidatesIndividually;

        public function key(): string
        {
            return 'test-retry';
        }

        public function isPubliclyReadable(PublicPageReadCandidateData $candidate): bool
        {
            return true;
        }
    });
    config()->set('queue.default', 'sync');
    $edge = new stdClass;
    $edge->available = false;
    $edge->purged = 0;

    app()->instance(CachePurger::class, new readonly class($edge) implements CachePurger
    {
        public function __construct(private stdClass $edge) {}

        public function purge(EdgeCachePurgeData $purge): bool
        {
            throw_unless($this->edge->available, RuntimeException::class, 'The edge is unavailable.');
            $this->edge->purged++;

            return true;
        }
    });
    $owed = static fn (): array => array_values(DB::table(TableName::resolve(WithdrawPublicReadProjectionsAction::TABLE))->pluck('step')->filter(static fn (mixed $step): bool => is_string($step))->all());

    expect(fn (): ?string => RecordPublicReadEligibilityChangeAction::run('test'))
        ->toThrow(PublicReadWithdrawalFailedException::class, HtmlCachePublicReadWithdrawalStep::KEY)
        ->and($owed())->toBe([HtmlCachePublicReadWithdrawalStep::KEY]);

    $edge->available = true;
    expect(Artisan::call('capell:public-read:reconcile'))->toBe(0);

    expect($edge->purged)->toBeGreaterThan(0)
        ->and($owed())->toBe([]);
});

it('removes a disk copy built under rules whose change was never announced, in one reconcile run', function (): void {
    Storage::fake('page_cache');
    Model::withoutEvents(fn (): SiteDomain => SiteDomain::factory()->create(['scheme' => 'https', 'domain' => 'listing.example.test', 'path' => null]));
    $rules = new stdClass;
    $rules->version = 1;
    resolve(PublicPageReadEligibilityRegistry::class)->register(new readonly class($rules) implements FingerprintsPublicReadRules, PublicPageReadEligibility
    {
        use EvaluatesPublicPageReadCandidatesIndividually;

        public function __construct(private stdClass $rules) {}

        public function key(): string
        {
            return 'test-reconcile';
        }

        public function isPubliclyReadable(PublicPageReadCandidateData $candidate): bool
        {
            return true;
        }

        public function publicReadRulesFingerprint(): string
        {
            return 'version:' . $this->rules->version;
        }
    });
    app()->instance(CachePurger::class, new readonly class implements CachePurger
    {
        public function purge(EdgeCachePurgeData $purge): bool
        {
            return true;
        }
    });

    expect(Artisan::call('capell:public-read:reconcile'))->toBe(0);
    app()->forgetScopedInstances();

    $builtUnder = resolve(PublicReadEligibilityState::class)->freshStamp();
    expect($builtUnder)->toBeInstanceOf(PublicReadStampData::class)
        ->and(PublishesHtmlCacheArtefacts::write(static fn (PublicArtefactPermit $permit) => resolve(HtmlCacheStore::class)->put($permit, 'https.listing.example.test/gated.html', 'Old rules'), $builtUnder))->toBeTrue()
        ->and(Storage::disk('page_cache')->exists('https.listing.example.test/gated.html'))->toBeTrue();

    // An editor's save whose eligibility change was lost (a crash before it was recorded).
    $rules->version = 2;
    app()->forgetScopedInstances();

    expect(Artisan::call('capell:public-read:reconcile'))->toBe(0)
        ->and(Storage::disk('page_cache')->exists('https.listing.example.test/gated.html'))->toBeFalse()
        ->and(DB::table(TableName::resolve(WithdrawPublicReadProjectionsAction::TABLE))->count())->toBe(0);
});
