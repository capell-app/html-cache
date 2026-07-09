<?php

declare(strict_types=1);

use Capell\HtmlCache\Tests\HtmlCacheTestCase;

require_once __DIR__ . '/Support/CachedModelUrlsTestSupport.php';

pest()->extend(HtmlCacheTestCase::class)->group('html-cache')->in('.');
