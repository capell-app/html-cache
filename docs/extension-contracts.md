# Worked extension examples

These developer-facing recipes are kept beside the package contract. Replace the example values with the site-specific records and data objects used by the calling workflow.

<!-- example: contract Capell\HtmlCache\Contracts\CachePurger -->

```php
<?php
declare(strict_types=1);
final class ExampleCachePurgerImplementation implements \Capell\HtmlCache\Contracts\CachePurger
{
    public function purge(\Capell\HtmlCache\Data\EdgeCachePurgeData $purge): bool
    {
        throw new LogicException('Implement this package contract for the calling site.');
    }
}

app()->bind(\Capell\HtmlCache\Contracts\CachePurger::class, ExampleCachePurgerImplementation::class);
```

<!-- example: contract Capell\HtmlCache\Contracts\PageCacheNotifiable -->

```php
<?php
declare(strict_types=1);
final class ExamplePageCacheNotifiableImplementation implements \Capell\HtmlCache\Contracts\PageCacheNotifiable
{
    public function notifyPageCached(\Illuminate\Database\Eloquent\Model $model): void
    {
        throw new LogicException('Implement this package contract for the calling site.');
    }
}

app()->bind(\Capell\HtmlCache\Contracts\PageCacheNotifiable::class, ExamplePageCacheNotifiableImplementation::class);
```
