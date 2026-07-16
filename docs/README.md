# HTML Cache Docs

Full-page static HTML cache for Capell with dependency-indexed invalidation, scheduled stale-regeneration, and public-output safety guarantees.

Start at the [package README](../README.md) when deciding whether to install this package. Use the docs below for setup, extension, debugging, and verification details.

## Guides

| Doc                                         | Use it for                                                                         |
| ------------------------------------------- | ---------------------------------------------------------------------------------- |
| [Admin Guide](admin-guide.md)               | Configure cache behavior and investigate cache health.                             |
| [Cache Invalidation](cache-invalidation.md) | Focused package workflow, setup, troubleshooting, or implementation details.       |
| [Overview](overview.md)                     | Package boundary, runtime surfaces, install notes, and first troubleshooting path. |

## Package Verification

From this package directory, the portable aliases are:

```bash
composer test
composer lint
composer analyse
```

From the monorepo root, use the local overlay for routine development:

```bash
vendor/bin/pest packages/html-cache/tests --configuration=phpunit.xml
COMPOSER=composer.local.json composer preflight
```

## Read Next

| Related doc                                                     | Why                                                   |
| --------------------------------------------------------------- | ----------------------------------------------------- |
| [Repository package docs](../../../docs/README.md)              | Cross-package workflow index and install-order notes. |
| [Frontend Authoring](../../frontend-authoring/docs/overview.md) | Neighboring package in the same Capell workflow.      |
| [Diagnostics](../../diagnostics/docs/overview.md)               | Neighboring package in the same Capell workflow.      |
