<?php

declare(strict_types=1);

use Capell\HtmlCache\Support\Cache\PageCache;
use Capell\HtmlCache\Tests\HtmlCacheTestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(HtmlCacheTestCase::class);

const MISS_MINIFICATION_HTML = "<!DOCTYPE html>\n<html>\n    <body>\n        <main>\n            <p>   Cached   page   </p>\n        </main>\n    </body>\n</html>\n";

beforeEach(function (): void {
    Storage::fake('page_cache');
    Queue::fake();
    config()->set('capell-html-cache.enabled', true);
    config()->set('capell-html-cache.write_enabled', true);
    config()->set('capell-html-cache.request_coalescing.enabled', false);
    config()->set('capell-html-cache.origin_swr.enabled', false);
});

it('serves the stored minified bytes on the first cacheable response', function (): void {
    config()->set('capell-html-cache.minify_html', true);
    $request = Request::create('https://example.test/minified');
    app()->instance('request', $request);
    $response = response(MISS_MINIFICATION_HTML, headers: ['Content-Type' => 'text/html']);

    expect(resolve(PageCache::class)->cache($this->beginCacheRender($request), $response))->toBeTrue();

    $stored = resolve(PageCache::class)->getCachePage(Request::create('https://example.test/minified'));

    expect($stored)->toBeString()
        ->and(strlen((string) $stored))->toBeLessThan(strlen(MISS_MINIFICATION_HTML))
        ->and($response->getContent())->toBe($stored);
});

it('leaves the first response untouched when minification is off', function (): void {
    config()->set('capell-html-cache.minify_html', false);
    $request = Request::create('https://example.test/unminified');
    app()->instance('request', $request);
    $response = response(MISS_MINIFICATION_HTML, headers: ['Content-Type' => 'text/html']);

    expect(resolve(PageCache::class)->cache($this->beginCacheRender($request), $response))->toBeTrue()
        ->and($response->getContent())->toBe(MISS_MINIFICATION_HTML);
});
