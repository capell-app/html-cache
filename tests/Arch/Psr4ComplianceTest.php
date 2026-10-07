<?php

declare(strict_types=1);

it('keeps named package and test declarations in their PSR-4 files', function (): void {
    $mismatches = [];

    foreach (['src' => 'Capell\\HtmlCache\\', 'tests' => 'Capell\\HtmlCache\\Tests\\'] as $directory => $prefix) {
        $root = dirname(__DIR__, 2) . '/' . $directory;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $namespace = '';
            $tokens = PhpToken::tokenize((string) file_get_contents($file->getPathname()));
            foreach ($tokens as $index => $token) {
                if ($token->id === T_NAMESPACE) {
                    $namespace = '';
                    for ($next = $index + 1; isset($tokens[$next]) && ! in_array($tokens[$next]->text, [';', '{'], true); $next++) {
                        if (! $tokens[$next]->isIgnorable()) {
                            $namespace .= $tokens[$next]->text;
                        }
                    }
                }
                if (! in_array($token->id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                    continue;
                }
                $next = $index + 1;
                while (isset($tokens[$next]) && $tokens[$next]->isIgnorable()) {
                    $next++;
                }
                if (! isset($tokens[$next]) || $tokens[$next]->id !== T_STRING) {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($root) + 1, -4);
                $expected = $prefix . str_replace('/', '\\', $relative);
                $declared = $namespace . '\\' . $tokens[$next]->text;

                if ($declared !== $expected) {
                    $mismatches[$file->getPathname()] = $declared;
                }
            }
        }
    }

    expect($mismatches)->toBe([]);
});
