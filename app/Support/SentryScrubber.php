<?php

namespace App\Support;

use Sentry\Event;
use Sentry\EventHint;

/**
 * Redacta secretos que viajan en la RUTA antes de que Sentry los reciba.
 *
 * Culqi no firma sus webhooks, así que la URL registrada en CulqiPanel lleva un
 * secreto (`/api/webhooks/culqi/{secreto}`). Si una petición a esa ruta falla,
 * el SDK de Sentry adjunta la URL completa al evento: sin este filtro el secreto
 * saldría hacia un tercero. Se registra en config/sentry.php como callable de
 * array (compatible con `config:cache`, a diferencia de un Closure).
 */
final class SentryScrubber
{
    private const PATTERN = '#(/webhooks/culqi/)[^/?\#\s]+#i';
    private const REPLACEMENT = '$1[REDACTADO]';

    public static function beforeSend(Event $event, ?EventHint $hint = null): ?Event
    {
        return self::scrub($event);
    }

    public static function beforeSendTransaction(Event $event, ?EventHint $hint = null): ?Event
    {
        return self::scrub($event);
    }

    public static function scrub(Event $event): Event
    {
        $request = $event->getRequest();
        foreach (['url', 'query_string'] as $key) {
            if (isset($request[$key]) && is_string($request[$key])) {
                $request[$key] = self::redact($request[$key]);
            }
        }
        $event->setRequest($request);

        if (is_string($event->getTransaction())) {
            $event->setTransaction(self::redact($event->getTransaction()));
        }

        $breadcrumbs = [];
        foreach ($event->getBreadcrumbs() as $breadcrumb) {
            $clean = $breadcrumb->withMessage(self::redact((string) $breadcrumb->getMessage()));
            $url = $breadcrumb->getMetadata()['url'] ?? null;
            $breadcrumbs[] = is_string($url) ? $clean->withMetadata('url', self::redact($url)) : $clean;
        }
        $event->setBreadcrumb($breadcrumbs);

        $message = $event->getMessage();
        if (is_string($message)) {
            $event->setMessage(self::redact($message));
        }

        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(self::redact((string) $exception->getValue()));
        }

        // El middleware de tracing de sentry-laravel guarda la ruta completa en `contexts.trace.data.url`
        // y en los spans: sin esto, con SENTRY_TRACES_SAMPLE_RATE definido el secreto saldría igualmente.
        foreach ($event->getContexts() as $name => $data) {
            $event->setContext((string) $name, self::redactArray($data));
        }
        $event->setExtra(self::redactArray($event->getExtra()));

        foreach ($event->getSpans() as $span) {
            if (is_string($span->getDescription())) {
                $span->setDescription(self::redact($span->getDescription()));
            }
            $span->setData(self::redactArray($span->getData()));
        }

        return $event;
    }

    /** @param array<array-key, mixed> $data */
    private static function redactArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = self::redact($value);
            } elseif (is_array($value)) {
                $data[$key] = self::redactArray($value);
            }
        }

        return $data;
    }

    public static function redact(string $text): string
    {
        return preg_replace(self::PATTERN, self::REPLACEMENT, $text) ?? $text;
    }
}
