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

## Internal verification and editor consequences

Every `HtmlCacheMiddleware` invocation authenticates internal bypass and shell
verification headers before checking the cache, including explicitly routed
`frontend.cache` requests. Configure `internal_bypass.header` and `.secret`, plus
`internal_shell.header`, under `capell-html-cache`. Empty secrets never authenticate.
The configurable bypass query parameter defaults to `without_html_cache`; production
ignores it unless `internal_bypass.allow_query_in_production` is explicitly true.
Authenticated internal bypass disables both cache reads and writes; trusted
refresh requests retain their separate read-bypass and publication behaviour.

`bypass.ignored_query_parameters` is an application-owned list of tracking keys or
patterns removed from GET/HEAD queries before cache decisions; its default is empty.
`bypass.ignored_query_parameter_except_paths` preserves queries on configured
acquisition endpoints. The query bag and `QUERY_STRING` are updated together.

The package contributes HTML and conditional translation cache consequences to
Admin's editor-impact planner registry. Counts and measured timing estimates include
only sites the editor can view and planning never queues cache work. Search rebuild
and separate static-export consequences remain with their owning integrations.
