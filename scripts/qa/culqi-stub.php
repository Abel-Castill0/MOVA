<?php

/**
 * Doble HTTP local de Culqi SOLO para QA (scripts/qa-payment-race.sh). No habla con Culqi.
 * Lo sirve `php -S` con PHP_CLI_SERVER_WORKERS>1 para que las peticiones lleguen en paralelo.
 *
 *   POST /v2/charges  → 201 con la forma real del sandbox (paid:false, venta_exitosa/AUT0000) tras una
 *                       pausa de 300 ms que ensancha la ventana de carrera; anota cada POST en $CULQI_STUB_LOG.
 *   GET  /v2/charges/{id} → el último cargo creado con ese id (por si la conciliación lo consulta).
 */
$log = getenv('CULQI_STUB_LOG') ?: sys_get_temp_dir().'/culqi_stub_posts.log';
$dir = sys_get_temp_dir().'/culqi_stub_charges';
@mkdir($dir, 0777, true);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

header('Content-Type: application/json');

if ($method === 'POST' && $path === '/v2/charges') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    file_put_contents($log, 'POST /v2/charges '.json_encode(['token_prefix' => substr((string) ($body['source_id'] ?? ''), 0, 8), 'amount' => $body['amount'] ?? null])."\n", FILE_APPEND | LOCK_EX);
    usleep(300_000);

    $id = 'chr_test_'.bin2hex(random_bytes(8));
    $charge = [
        'object' => 'charge', 'id' => $id, 'creation_date' => (int) (microtime(true) * 1000),
        'amount' => $body['amount'] ?? 0, 'amount_refunded' => 0, 'current_amount' => $body['amount'] ?? 0,
        'installments' => 0, 'currency_code' => $body['currency_code'] ?? 'PEN', 'email' => $body['email'] ?? 'qa@mova.test',
        'capture' => true, 'paid' => false, 'dispute' => false, 'duplicated' => false,
        'outcome' => ['type' => 'venta_exitosa', 'code' => 'AUT0000', 'merchant_message' => 'La operación de venta ha sido autorizada exitosamente', 'user_message' => 'Su compra ha sido exitosa'],
        'metadata' => $body['metadata'] ?? [],
    ];
    file_put_contents($dir.'/'.$id.'.json', json_encode($charge));
    http_response_code(201);
    echo json_encode($charge);

    return;
}

if ($method === 'GET' && preg_match('#^/v2/charges/(chr_test_[a-f0-9]+)$#', $path, $m) && is_file($dir.'/'.$m[1].'.json')) {
    echo file_get_contents($dir.'/'.$m[1].'.json');

    return;
}

http_response_code(404);
echo json_encode(['object' => 'error', 'type' => 'parametro_invalido', 'merchant_message' => 'stub: ruta no soportada']);
