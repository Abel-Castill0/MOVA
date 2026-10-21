<?php

/**
 * Validacion local del payload Yape para el caso 2072 (ticket WCS-55456).
 *
 * Genera el JSON del cuerpo de POST /v1/payments tal como lo arma
 * MercadoPagoPaymentProvider::createPaymentAttempt(), en DOS variantes de
 * serializacion de `transaction_amount`:
 *   - ACTUAL (rama master):   numero JSON (round(minor/100, 2))  -> "transaction_amount": 1
 *   - FIX (rama fix/yape-amount-format): string con 2 decimales   -> "transaction_amount": "1.00"
 *
 * NO hace ninguna llamada a la API. NO lee .env ni credenciales: el token
 * va como marcador "[REDACTED]". Sirve para comparar rapidamente el cuerpo
 * cuando Mercado Pago responda cual formato espera.
 *
 *   php scripts/test-yape-payload.php
 *
 * Nota: a proposito NO carga vendor/autoload.php. Este script no usa ninguna
 * clase de Laravel; cargar el autoload de Composer dispara platform_check.php,
 * que exige PHP >= 8.3 (el runtime local puede ser 8.1). Asi se puede validar
 * el formato del cuerpo en cualquier maquina, sin base de datos ni framework.
 */

// Intento real del 6/10/2026: recarga 1, paquete de verificacion, S/ 1,00.
$amountMinor = 100;             // 100 centimos = S/ 1,00
$rechargeId = 1;
$attemptNumber = 1;

$description = 'Recarga de creditos MOVA: Verificacion de cobro';
$externalReference = "recharge:{$rechargeId}:attempt:{$attemptNumber}";

/** Variante ACTUAL (master): numero JSON. */
$amountAsNumber = round($amountMinor / 100, 2);

/** Variante FIX (fix/yape-amount-format): string con 2 decimales. */
$amountAsString = number_format($amountMinor / 100, 2, '.', '');

$build = static function (mixed $amount) use ($description, $externalReference): array {
    return [
        'transaction_amount' => $amount,
        'description' => $description,
        'external_reference' => $externalReference,
        'capture' => true,
        'binary_mode' => false,
        'payer' => [
            'email' => '[REDACTED]',
        ],
        'token' => '[REDACTED]',
        'payment_method_id' => 'yape',
        'installments' => 1,
    ];
};

$pretty = static fn (array $payload): string => json_encode(
    $payload,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

echo "=== MOVA - payload Yape local (sin llamada a la API) ===\n\n";

echo "--- ACTUAL (rama master): transaction_amount como numero JSON ---\n";
echo $pretty($build($amountAsNumber))."\n\n";

echo "--- FIX (rama fix/yape-amount-format): transaction_amount como string ---\n";
echo $pretty($build($amountAsString))."\n\n";

echo "--- Diferencias de serializacion ---\n";
printf(
    "master : transaction_amount=%s  (PHP %s)\n",
    json_encode($amountAsNumber),
    get_debug_type($amountAsNumber)
);
printf(
    "fix    : transaction_amount=%s  (PHP %s)\n",
    json_encode($amountAsString),
    get_debug_type($amountAsString)
);
echo "\nNinguna comparacion de verdad usa este valor: el ledger y la\n";
echo "reconciliacion comparan SIEMPRE centimos enteros (amount_minor).\n";
