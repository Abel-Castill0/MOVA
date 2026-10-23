<?php

namespace App\Support;

use App\Models\Subject;
use App\Payment\Culqi\CulqiConfig;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Catálogo público de MOVA (Culqi exige mostrar productos/servicios con imagen, descripción y precio visible).
 *
 * FUENTE DE VERDAD DE PRECIOS — nunca se escriben en componentes Vue ni en config/catalog.php:
 *  - Paquetes de créditos (lo que MOVA vende al PROFESOR y lo único que se cobra con Culqi): config('credits.packages').
 *  - Tarifa de las clases (la paga el padre DIRECTAMENTE al profesor; MOVA no intermedia ese cobro): config('credits.class_rates').
 * La presentación (imagen, texto) vive en config/catalog.php. Las materias salen de la base de datos con su número real de
 * profesores verificados: no se anuncian como disponibles las que no tienen ninguno.
 */
final class Catalog
{
    /** @return list<array<string, mixed>> */
    public static function packages(): array
    {
        $out = [];
        foreach ((array) config('credits.packages', []) as $code => $package) {
            $out[] = self::presentPackage((string) $code, (array) $package);
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public static function package(string $code): ?array
    {
        $package = config("credits.packages.{$code}");

        return is_array($package) ? self::presentPackage($code, $package) : null;
    }

    /** @return list<array<string, mixed>> */
    public static function subjects(): array
    {
        $presentation = (array) config('catalog.subjects', []);
        $generic = (array) config('catalog.generic_subject', []);
        $rates = self::classRates();

        return Subject::query()
            ->orderBy('name')
            ->withCount(['teachers as verified_teachers_count' => fn ($q) => $q->where('teacher_profiles.is_verified', true)])
            ->get(['id', 'name', 'normalized_name', 'level'])
            ->map(function (Subject $subject) use ($presentation, $generic, $rates) {
                $p = (array) ($presentation[$subject->normalized_name] ?? $generic);

                return [
                    'id' => $subject->id,
                    'name' => $subject->name,
                    'level' => $subject->level,
                    'image' => $p['image'] ?? $generic['image'] ?? null,
                    'alt' => $p['alt'] ?? $generic['alt'] ?? $subject->name,
                    'description' => $p['description'] ?? $generic['description'] ?? '',
                    'verified_teachers' => (int) $subject->verified_teachers_count,
                    'rate_cap' => $rates['base'],
                ];
            })
            ->all();
    }

    /** @return array{base: int, experienced: int, expert: int} */
    public static function classRates(): array
    {
        return [
            'base' => (int) config('credits.class_rates.base', 20),
            'experienced' => (int) config('credits.class_rates.experienced', 25),
            'expert' => (int) config('credits.class_rates.expert', 30),
        ];
    }

    /**
     * Estado HONESTO del pago en línea para la persona que mira: `online` solo si Culqi está realmente habilitado para ella
     * (proveedor activo, configuración coherente, allowlist); `testMode` si las llaves son de integración (sin cobros reales).
     *
     * @return array{online: bool, provider: string|null, testMode: bool, methods: list<string>}
     */
    public static function paymentsState(?Authenticatable $user): array
    {
        $provider = CheckoutProvider::active($user);
        $online = $provider === 'culqi';

        return [
            'online' => $online,
            'provider' => $provider,
            'testMode' => $online && CulqiConfig::isTestMode(),
            'methods' => $online ? CheckoutProvider::culqiMethods() : [],
        ];
    }

    /** @param array<string, mixed> $package */
    private static function presentPackage(string $code, array $package): array
    {
        $presentation = (array) config("catalog.packages.{$code}", []);
        $credits = (int) ($package['credits'] ?? 0);
        $amount = (string) ($package['amount_pen'] ?? '0.00');
        $minutes = (int) config('credits.credit_minutes', 60);

        return CreditPackages::pricing($code, $package) + [
            'code' => $code,
            'name' => (string) ($package['name'] ?? $code),
            'credits' => $credits,
            'amount_pen' => $amount,
            'currency' => 'PEN',
            'minutes_included' => $credits * $minutes,
            'image' => $presentation['image'] ?? '/images/mascot/movi-saluda-512.webp',
            'alt' => $presentation['alt'] ?? 'Movi, la ardilla mascota de MOVA',
            'tagline' => $presentation['tagline'] ?? '',
            'description' => $presentation['description'] ?? '',
            'benefits' => array_values((array) ($presentation['benefits'] ?? [])),
        ];
    }
}
