<?php

declare(strict_types=1);

use Capell\HtmlCache\Enums\HtmlCachePermission;
use Capell\HtmlCache\Models\CachedModelUrl;
use Capell\HtmlCache\Policies\CachedModelUrlPolicy;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;

uses(HtmlCacheTestCase::class);

it('resolves cache map authorization and requires the existing cache view permission', function (): void {
    $user = User::factory()->create();
    $policy = Gate::getPolicyFor(CachedModelUrl::class);

    expect($policy)->toBeInstanceOf(CachedModelUrlPolicy::class)
        ->and(Gate::forUser($user)->allows('viewAny', CachedModelUrl::class))->toBeFalse();

    Permission::findOrCreate(HtmlCachePermission::ViewCachedModelUrls->value);
    $user->givePermissionTo(HtmlCachePermission::ViewCachedModelUrls->value);

    expect(Gate::forUser($user)->allows('viewAny', CachedModelUrl::class))->toBeTrue()
        ->and(Gate::forUser($user)->allows('create', CachedModelUrl::class))->toBeFalse()
        ->and(Gate::forUser($user)->allows('deleteAny', CachedModelUrl::class))->toBeFalse();

    $url = new CachedModelUrl;
    $url->setRelation('site', null);
    expect(Gate::forUser($user)->allows('view', $url))->toBeFalse()
        ->and(Gate::forUser($user)->allows('update', $url))->toBeFalse()
        ->and(Gate::forUser($user)->allows('delete', $url))->toBeFalse();
});

it('retains global admin cache map access', function (): void {
    $user = User::factory()->create();
    $user->assignRole('super_admin');
    $policy = resolve(CachedModelUrlPolicy::class);

    expect($policy->viewAny($user))->toBeTrue()
        ->and($policy->view($user, new CachedModelUrl))->toBeTrue()
        ->and($policy->create($user))->toBeFalse();
});
