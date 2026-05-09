<?php

declare(strict_types=1);

use Capell\HtmlCache\Tests\HtmlCacheTestCase;

pest()->extend(HtmlCacheTestCase::class)->group('html-cache')->in(__DIR__);
