<?php

declare(strict_types=1);

use Capell\Admin\Contracts\EditorImpact\EditorImpactConsequencePlanner;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\HtmlCache\Actions\PlanEditorHtmlCacheConsequencesAction;
use Capell\HtmlCache\Actions\RecordCachedModelUrlsAction;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;

uses(HtmlCacheTestCase::class);

it('registers package cache consequences and hides inaccessible site URLs without writes', function (): void {
    $queue = Queue::fake();
    config(['capell-html-cache.invalidation.mode' => 'scheduled', 'capell-html-cache.enabled' => true]);
    $page = Page::factory()->create();
    $hidden = Site::factory()->create();
    Gate::before(fn ($user, string $ability, array $arguments): bool => ! ($arguments[0] ?? null) instanceof Site || $arguments[0]->getKey() !== $hidden->getKey());
    $this->actingAs(User::factory()->create());
    SiteDomain::factory()->create(['site_id' => $page->site_id, 'domain' => 'visible.example.test', 'scheme' => 'https', 'path' => null]);
    SiteDomain::factory()->create(['site_id' => $hidden->getKey(), 'domain' => 'hidden.example.test', 'scheme' => 'https', 'path' => null]);
    RecordCachedModelUrlsAction::run('https://visible.example.test/one', [$page->getMorphClass() => [$page->id]]);
    RecordCachedModelUrlsAction::run('https://hidden.example.test/private', [$page->getMorphClass() => [$page->id]]);
    $before = CachedModelUrl::query()->count();
    $queuedBefore = $queue->pushedJobs();
    $plan = resolve(PlanEditorHtmlCacheConsequencesAction::class)->plan($page);
    expect($plan[0]->count)->toBe(1)->and($plan[0]->urls)->toBe(['https://visible.example.test/one'])->and($plan[0]->estimatedSeconds)->toBeNull()->and(CachedModelUrl::query()->count())->toBe($before);
    expect(collect(app()->tagged(EditorImpactConsequencePlanner::TAG))->contains(fn ($planner) => $planner instanceof PlanEditorHtmlCacheConsequencesAction))->toBeTrue();
    expect($queue->pushedJobs())->toBe($queuedBefore);
});

it('does not plan consequences for an unauthorised or unsaved record', function (): void {
    expect(resolve(PlanEditorHtmlCacheConsequencesAction::class)->plan(new Page))->toBe([]);
    Gate::define('update', fn ($user, $record) => false);
    expect(resolve(PlanEditorHtmlCacheConsequencesAction::class)->plan(Page::factory()->create()))->toBe([]);
});

it('keeps retained cache tables inactive when the package or cache is disabled', function (): void {
    $this->actingAs(User::factory()->create());
    Gate::before(fn () => true);
    $page = Page::factory()->create();
    $planner = resolve(PlanEditorHtmlCacheConsequencesAction::class);
    CapellCore::forcePackageInstalled('capell-app/html-cache');
    config(['capell-html-cache.enabled' => true]);
    expect($planner->plan($page))->not->toBe([]);
    config(['capell-html-cache.enabled' => false]);
    expect($planner->plan($page))->toBe([]);
    config(['capell-html-cache.enabled' => true]);
    CapellCore::forcePackageInstalled('capell-app/html-cache', false);
    expect($planner->plan($page))->toBe([]);
});
