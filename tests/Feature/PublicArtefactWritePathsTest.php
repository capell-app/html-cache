<?php

declare(strict_types=1);

use Capell\Core\Support\PublicRead\PublicArtefactPermit;
use Capell\Frontend\Contracts\StaticErrorPageStore;
use Capell\Frontend\Contracts\StaticMaintenancePageStore;
use Capell\Frontend\Support\Static\StaticPageArtifactStore;
use Capell\HtmlCache\Support\Cache\HtmlCacheStore;
use Capell\HtmlCache\Support\Error\HtmlCacheStaticErrorPageStore;
use Capell\HtmlCache\Support\Maintenance\HtmlCacheStaticMaintenancePageStore;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/**
 * Rendered HTML served without Laravel (the page cache disk, static exports,
 * static error and maintenance pages) is written only inside
 * PublishPublicArtefactAction. Two mechanical checks hold that:
 *
 * - every write method of those stores requires a PublicArtefactPermit;
 * - in the code that owns those stores, every filesystem write happens in a
 *   function that is handed a permit, or is listed below as a write that is
 *   not rendered HTML (manifests, lock state, metadata), with its reason.
 */
const PUBLIC_ARTEFACT_NON_HTML_WRITES = [
    // Static export receipts and private ownership records, written under the store lock.
    'Capell\Frontend\Support\Static\StaticPageArtifactStore::writeManifest' => 'the public manifest is a JSON receipt, not HTML',
    'Capell\Frontend\Support\Static\StaticPageArtifactStore::writeOwnership' => 'private ownership JSON outside the export root',
    'Capell\Frontend\Support\Static\StaticPageArtifactStore::writeAtomically' => 'the atomic-write primitive behind putHtml and the receipts',
    // The publication guard's own lock state, outside the page cache.
    'Capell\HtmlCache\Support\Cache\HtmlCachePublicationGuard::writeUrlCounters' => 'withdrawal counters beside the lock file',
    'Capell\HtmlCache\Support\Cache\HtmlCachePublicationGuard::rotate' => 'the generation token in the lock file',
    'Capell\HtmlCache\Actions\RenderCoalescedHtmlCacheMissAction::acquire' => 'touches the render lock beside the guard lock file to record its last use',
    // Probes and bookkeeping that never hold rendered HTML.
    'Capell\HtmlCache\Health\HtmlCacheHealthCheck::isPageCacheDiskWritable' => 'a writability probe file, deleted at once',
    'Capell\HtmlCache\Support\Cache\EdgePurge\EdgePurgePendingQueue::*' => 'edge purge bookkeeping in the cache store',
    'Capell\HtmlCache\Support\Cache\EdgePurge\EdgePurgeRateGovernor::*' => 'edge purge rate state in the cache store',
];

/**
 * Every source file that can reach the page cache or the static export store:
 * all of html-cache, and any other Core or package file that names the page
 * cache disk, the export path or one of the stores.
 *
 * @return list<string>
 */
function publicArtefactWriterSources(): array
{
    $packages = dirname(__DIR__, 3);
    $core = dirname((string) (new ReflectionClass(StaticPageArtifactStore::class))->getFileName(), 5);
    $files = [];

    foreach ([...glob($packages . '/*/src', GLOB_ONLYDIR) ?: [], ...glob($core . '/*/src', GLOB_ONLYDIR) ?: []] as $root) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();

            if (str_starts_with($path, $packages . '/html-cache/src/')
                || preg_match('/\'page_cache\'|HtmlCacheStore\b|StaticPageArtifactStore\b|static_artifacts_path|StaticErrorPageStore\b|StaticMaintenancePageStore\b|HtmlCacheFilesystem\b/', (string) file_get_contents($path)) === 1) {
                $files[] = $path;
            }
        }
    }

    sort($files);

    expect($files)->not->toBeEmpty();

    return array_values(array_unique($files));
}

it('requires a publication permit on every write method of a store served without Laravel', function (): void {
    $stores = [HtmlCacheStore::class, StaticPageArtifactStore::class, StaticErrorPageStore::class, StaticMaintenancePageStore::class, HtmlCacheStaticErrorPageStore::class, HtmlCacheStaticMaintenancePageStore::class];
    $unguarded = [];

    foreach ($stores as $store) {
        foreach ((new ReflectionClass($store))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (preg_match('/^(put|replace|write|store|save|copy|move)/i', $method->getName()) !== 1) {
                continue;
            }

            $first = $method->getParameters()[0] ?? null;
            $type = $first?->getType();

            if (array_key_exists($method->getDeclaringClass()->getName() . '::' . $method->getName(), PUBLIC_ARTEFACT_NON_HTML_WRITES)) {
                continue;
            }

            if (! $type instanceof ReflectionNamedType || $type->getName() !== PublicArtefactPermit::class) {
                $unguarded[] = $store . '::' . $method->getName();
            }
        }
    }

    expect($unguarded)->toBe([]);
});

it('writes files in the public artefact stores only where a publication permit is in hand', function (): void {
    $parser = (new ParserFactory)->createForHostVersion();
    $writes = ['put', 'replace', 'putHtml', 'putOwnedHtml', 'write', 'writeStream', 'putFile', 'putFileAs', 'append', 'prepend', 'copy', 'move'];
    $functions = ['file_put_contents', 'rename', 'copy', 'fwrite', 'fputs', 'touch', 'symlink', 'link'];
    $unfenced = [];

    foreach (publicArtefactWriterSources() as $path) {
        $ast = $parser->parse((string) file_get_contents($path)) ?? [];
        $traverser = new NodeTraverser(new ParentConnectingVisitor);
        $ast = $traverser->traverse($ast);
        $collector = new class($writes, $functions) extends NodeVisitorAbstract
        {
            /** @var list<Node> */
            public array $calls = [];

            /**
             * @param  list<string>  $writes
             * @param  list<string>  $functions
             */
            public function __construct(private readonly array $writes, private readonly array $functions) {}

            public function enterNode(Node $node): null
            {
                $isWrite = match (true) {
                    // Facade writes reach a filesystem only through File or Storage.
                    $node instanceof StaticCall => $node->class instanceof Name && in_array($node->class->getLast(), ['File', 'Storage'], true)
                        && $node->name instanceof Node\Identifier && in_array($node->name->toString(), $this->writes, true),
                    $node instanceof MethodCall => $node->name instanceof Node\Identifier && in_array($node->name->toString(), $this->writes, true)
                        && ! ($node->var instanceof Node\Expr\PropertyFetch && $node->var->name instanceof Node\Identifier && in_array($node->var->name->toString(), ['attributes', 'query', 'headers', 'cache'], true)),
                    $node instanceof FuncCall => $node->name instanceof Name && in_array($node->name->toLowerString(), $this->functions, true),
                    default => false,
                };

                if ($isWrite) {
                    $this->calls[] = $node;
                }

                return null;
            }
        };
        (new NodeTraverser($collector))->traverse($ast);

        foreach ($collector->calls as $call) {
            $permitted = false;
            $method = null;
            $class = null;

            for ($node = $call->getAttribute('parent'); $node instanceof Node; $node = $node->getAttribute('parent')) {
                if ($node instanceof FunctionLike && ! $permitted) {
                    foreach ($node->getParams() as $param) {
                        if ($param->type instanceof Name && in_array($param->type->getLast(), ['PublicArtefactPermit'], true)) {
                            $permitted = true;
                        }
                    }
                }

                if ($node instanceof ClassMethod && $method === null) {
                    $method = $node->name->toString();
                }

                if ($node instanceof ClassLike && $class === null) {
                    $class = $node->name?->toString();
                }
            }

            if ($permitted) {
                continue;
            }

            $namespace = preg_match('/^namespace\s+([^;]+);/m', (string) file_get_contents($path), $matches) === 1 ? $matches[1] : '';
            $owner = $namespace . '\\' . $class . '::' . $method;

            if (! array_key_exists($owner, PUBLIC_ARTEFACT_NON_HTML_WRITES) && ! array_key_exists($namespace . '\\' . $class . '::*', PUBLIC_ARTEFACT_NON_HTML_WRITES)) {
                $unfenced[] = $owner . ' (' . basename($path) . ':' . $call->getStartLine() . ')';
            }
        }
    }

    expect(array_values(array_unique($unfenced)))->toBe([]);
});
