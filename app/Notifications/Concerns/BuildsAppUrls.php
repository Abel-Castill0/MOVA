<?php

namespace App\Notifications\Concerns;

trait BuildsAppUrls
{
    protected function appUrl(string $path = '/'): string
    {
        return rtrim((string) config('app.url'), '/') . '/' . ltrim($path, '/');
    }

    protected function appRoute(string $name, mixed $parameters = []): string
    {
        return $this->appUrl(route($name, $parameters, false));
    }
}
