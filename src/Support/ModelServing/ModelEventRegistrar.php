<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Support\ModelServing;

use Capell\Core\Facades\CapellCore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Throwable;

final class ModelEventRegistrar
{
    private const string REQUEST_FLAG = 'capell.html_cache.model_events_registered';

    /** @var array<class-string, true> */
    private static array $registeredModelClasses = [];

    private static bool $registeredForProcess = false;

    public static function registerModels(): void
    {
        $request = self::requestOrNull();
        if ($request instanceof Request) {
            if ($request->attributes->get(self::REQUEST_FLAG) === true) {
                return;
            }

            $request->attributes->set(self::REQUEST_FLAG, true);
        } else {
            if (self::$registeredForProcess) {
                return;
            }

            self::$registeredForProcess = true;
        }

        foreach (CapellCore::getModels() as $modelClass) {
            self::registerRetrievedHook($modelClass);
        }
    }

    /**
     * @param  class-string  $modelClass
     */
    private static function registerRetrievedHook(string $modelClass): void
    {
        if (isset(self::$registeredModelClasses[$modelClass])) {
            return;
        }

        self::$registeredModelClasses[$modelClass] = true;

        $modelClass::registerModelEvent('retrieved', function (Model $model) use ($modelClass): void {
            resolve(RetrievedModelStore::class)->trackByClass($model, $modelClass);
        });
    }

    private static function requestOrNull(): ?Request
    {
        try {
            $request = resolve('request');
        } catch (Throwable) {
            return null;
        }

        return $request instanceof Request ? $request : null;
    }
}
