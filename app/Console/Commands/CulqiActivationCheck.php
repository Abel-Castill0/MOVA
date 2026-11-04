<?php

namespace App\Console\Commands;

use App\Models\CreditTransaction;
use App\Models\PaymentOrder;
use App\Payment\Culqi\CulqiClient;
use App\Payment\Culqi\CulqiConfig;
use App\Support\CheckoutAllowlist;
use App\Support\CheckoutProvider;
use App\Support\CreditPackages;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Comprobación previa a la activación de Culqi live (la usa infra/azure/culqi-live-activation.sh).
 *
 * SOLO LECTURA: no crea cargos, no escribe en la base y nunca imprime llaves, secretos ni correos. La única llamada
 * externa es un GET /v2/charges?limit=1 a la API de Culqi. Cada línea empieza por OK, FALLA, AVISO o NO-VERIFICADO;
 * las últimas líneas (RESULTADO, ESTADO, COMPRA_LIVE_VERIFICADA) son las que lee el script. Sale con 0 solo si no hay
 * ninguna FALLA.
 */
class CulqiActivationCheck extends Command
{
    protected $signature = 'culqi:activation-check {--offline : no consulta la API de Culqi (pruebas)}';

    protected $description = 'Culqi live: verifica (solo lectura) que todo esté listo para abrir compras, sin cobrar ni mostrar secretos.';

    /** Precios autorizados (docs/release/MOVA_V1_DECISIONS.md, payments-culqi.md): código => [créditos, céntimos PEN]. */
    public const AUTHORIZED_PACKAGES = [
        'inicio' => [5, 1000],
        'esencial' => [10, 1980],
        'impulso' => [15, 2940],
        'avance' => [20, 3880],
        'pro' => [30, 5760],
    ];

    private int $failures = 0;

    public function handle(CulqiClient $client): int
    {
        $this->check((bool) config('payments.enabled'), 'PAYMENTS_ENABLED=true (la conciliación depende de esto)', 'PAYMENTS_ENABLED no está activo: ni compras ni conciliación funcionarían');

        $problems = CulqiConfig::problems();
        $this->check($problems === [], 'Configuración de Culqi coherente (llaves, modo y hosts)', 'Configuración de Culqi incoherente: '.implode(' | ', $problems));
        $this->check(config('payments.culqi.env') === 'live' && ! CulqiConfig::isTestMode(), 'Modo live (CULQI_ENV=live)', 'No está en modo live');
        $this->check(CulqiConfig::keyMode(CulqiConfig::publicKey(), 'pk') === 'live', 'Llave pública pk_live instalada', 'Falta la llave pública pk_live');
        $this->check(CulqiConfig::keyMode(CulqiConfig::secretKey(), 'sk') === 'live', 'Llave secreta sk_live instalada (Key Vault)', 'Falta la llave secreta sk_live');
        $this->check(strlen((string) config('payments.culqi.webhook_secret')) >= 32, 'Secreto del webhook instalado (>= 32 caracteres)', 'Secreto del webhook ausente o corto');
        $this->check((bool) config('payments.culqi.webhooks_enabled'), 'Receptor de webhooks de Culqi habilitado', 'CULQI_WEBHOOKS_ENABLED no está activo');
        $this->check(! (bool) config('payments.mercadopago.new_payments_enabled', false), 'Mercado Pago retirado para compras nuevas (sin respaldo automático)', 'MERCADOPAGO_NEW_PAYMENTS_ENABLED está activo: habría dos proveedores');
        $this->check(CheckoutProvider::culqiLegacyProcessing(), 'Conciliación de Culqi agendada (culqi:reconcile cada 5 min)', 'La conciliación de Culqi no se agendaría');

        $methods = CheckoutProvider::culqiMethods();
        $this->check(in_array('card', $methods, true), 'Medio ofrecido: tarjeta', 'La tarjeta no está entre los medios ofrecidos');
        foreach (array_diff($methods, ['card']) as $extra) {
            $this->line("AVISO  Medio '{$extra}' ofrecido: confirma que está habilitado y probado en live antes de abrir compras");
        }

        $this->checkPackages();
        $this->checkMigrations();

        if ($this->option('offline')) {
            $this->line('NO-VERIFICADO  Conexión a la API live de Culqi (--offline)');
        } elseif ($problems === []) {
            $r = $client->listCharges(['limit' => 1]);
            $this->check($r->successful(), 'API live de Culqi accesible con la llave de Key Vault (GET de solo lectura, HTTP '.$r->status.')', 'La API de Culqi no respondió OK (HTTP '.($r->status ?: 'sin respuesta').')');
        }

        $received = DB::table('payment_webhooks')->where('provider', 'culqi')->count();
        $this->line($received > 0
            ? "OK  Webhooks auténticos de Culqi recibidos: {$received}"
            : 'NO-VERIFICADO  Aún no llegó ningún webhook auténtico de Culqi (se confirma con la primera compra real); su registro en CulqiPanel no se puede consultar por API');
        $this->line('NO-VERIFICADO  Medios habilitados en el comercio (solo visibles en CulqiPanel → Mi empresa)');

        $verified = $this->liveCulqiPurchaseVerified();
        $state = $this->state();

        $this->line('RESULTADO: '.($this->failures === 0 ? 'LISTO' : 'NO LISTO ('.$this->failures.' falla(s))'));
        $this->line('ESTADO: '.$state);
        $this->line('COMPRA_LIVE_VERIFICADA: '.($verified ? 'si' : 'no'));

        return $this->failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function check(bool $ok, string $pass, string $fail): void
    {
        if ($ok) {
            $this->line('OK  '.$pass);

            return;
        }
        $this->failures++;
        $this->line('FALLA  '.$fail);
    }

    private function checkPackages(): void
    {
        $configured = (array) config('credits.packages');
        $ok = array_keys($configured) === array_keys(self::AUTHORIZED_PACKAGES);
        foreach (self::AUTHORIZED_PACKAGES as $code => [$credits, $minor]) {
            $package = $configured[$code] ?? null;
            if ($package === null) {
                $ok = false;

                continue;
            }
            $p = CreditPackages::pricing($code, $package);
            $ok = $ok && (int) $p['credits'] === $credits && (int) $p['amount_minor'] === $minor && $p['currency'] === 'PEN';
        }
        $this->check($ok, 'Cinco paquetes con precios autorizados (1000/1980/2940/3880/5760 céntimos PEN; 5/10/15/20/30 créditos)', 'Los paquetes no coinciden con los precios autorizados');
        $this->check(! (bool) config('credits.verification_package.enabled'), 'Paquete de verificación de S/ 1 apagado', 'CREDITS_VERIFICATION_PACKAGE_ENABLED está activo');
    }

    private function checkMigrations(): void
    {
        $files = collect(glob(database_path('migrations/*.php')) ?: [])->map(fn ($p) => pathinfo($p, PATHINFO_FILENAME));
        $applied = DB::table('migrations')->pluck('migration')->flip();
        $pending = $files->reject(fn ($n) => $applied->has($n))->count();
        $this->check($pending === 0, 'Migraciones aplicadas', "Hay {$pending} migración(es) pendiente(s)");
    }

    /**
     * Al menos un cargo/orden LIVE de Culqi pagado (identificador chr_live_/ord_live_) cuya recarga tiene su depósito único
     * en el ledger. Un cargo de pruebas nunca cuenta, aunque estuviera en esta base.
     */
    private function liveCulqiPurchaseVerified(): bool
    {
        $keys = PaymentOrder::query()->where('provider', 'culqi')->whereNotNull('paid_at')
            ->where(fn ($q) => $q->where('provider_order_id', 'like', 'chr_live_%')->orWhere('provider_order_id', 'like', 'ord_live_%'))
            ->pluck('recharge_request_id')->filter()->map(fn ($id) => "recharge:{$id}:deposit");

        return $keys->isNotEmpty() && CreditTransaction::query()->whereIn('idempotency_key', $keys->all())->exists();
    }

    /**
     * Estado REAL de las compras nuevas, con las mismas puertas que el checkout. El interruptor de cierre deja Culqi como
     * proveedor (la conciliación sigue) y pone en la allowlist solo direcciones *.invalid, que ninguna cuenta puede tener.
     */
    private function state(): string
    {
        if (CheckoutProvider::mercadoPagoEnabled(null) || (bool) config('payments.mercadopago.new_payments_enabled', false)) {
            $this->failures++;

            return 'ABIERTO-MERCADOPAGO (no permitido: Mercado Pago está retirado)';
        }
        if (config('payments.provider') !== 'culqi') {
            return 'PREPARADO (compras nuevas cerradas)';
        }
        if (CulqiConfig::problems() !== [] || CulqiConfig::publicKey() === null) {
            return 'PREPARADO (cerrado: configuración de Culqi incoherente)';
        }
        $entries = CheckoutAllowlist::entries();
        if ($entries === []) {
            return 'ACTIVO-GENERAL (todos los profesores pueden comprar)';
        }
        $real = array_filter($entries, fn (string $e) => ! str_ends_with($e, '.invalid'));

        return $real === []
            ? 'PREPARADO (compras nuevas cerradas por el interruptor)'
            : 'ACTIVO-PRUEBA (solo '.count($real).' cuenta(s) de la lista de prueba)';
    }
}
