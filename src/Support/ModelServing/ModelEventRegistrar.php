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

    /** @var array<class-string<Model>, true> */
    private array $registeredModelClasses = [];

    private bool $registeredForProcess = false;

    public function registerModels(): void
    {
        $request = $this->requestOrNull();
        if ($request instanceof Request) {
            if ($request->attributes->get(self::REQUEST_FLAG) === true) {
                return;
            }

            $request->attributes->set(self::REQUEST_FLAG, true);
        } else {
            if ($this->registeredForProcess) {
                return;
            }

            $this->registeredForProcess = true;
        }

        foreach (CapellCore::getModels() as $modelClass) {
            $this->registerRetrievedHook($modelClass);
        }
    }

    /**
     * @param  class-string  $modelClass
     */
    private function registerRetrievedHook(string $modelClass): void
    {
        if (! is_subclass_of($modelClass, Model::class)) {
            return;
        }

        /** @var class-string<Model> $modelClass */
        if (isset($this->registeredModelClasses[$modelClass])) {
            return;
        }

        $this->registeredModelClasses[$modelClass] = true;

        $modelClass::retrieved(function (Model $model) use ($modelClass): void {
            resolve(RetrievedModelStore::class)->trackByClass($model, $modelClass);
        });
    }

    private function requestOrNull(): ?Request
    {
        try {
            $request = resolve('request');
        } catch (Throwable) {
            return null;
        }

        return $request instanceof Request ? $request : null;
    }
}
