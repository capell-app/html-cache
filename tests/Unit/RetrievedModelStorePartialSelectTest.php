<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\HtmlCache\Support\ModelServing\RetrievedModelStore;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Database\Eloquent\Model;

uses(HtmlCacheTestCase::class);

afterEach(function (): void {
    Model::preventAccessingMissingAttributes(false);
});

it('skips a partially selected model instead of throwing under strict models', function (): void {
    Model::preventAccessingMissingAttributes();

    // A real mapped model: the application enforces the morph map, which an anonymous class cannot satisfy.
    $partial = (new Page)->newFromBuilder(['name' => 'Selected without the key']);
    $complete = (new Page)->newFromBuilder(['id' => 7, 'name' => 'Complete']);

    $store = new RetrievedModelStore;
    $store->track($partial);
    $store->track($complete);

    expect($store->tracked($complete->getMorphClass()))->toBe(1)
        ->and($store->modelKeys())->toHaveCount(1);
});
