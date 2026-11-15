<?php

/**
 * api/pedidos.php
 * ----------------
 *   GET  /api/pedidos.php        -> mis pedidos (historial)
 *   GET  /api/pedidos.php?id=5   -> un pedido mío con su detalle (ticket)
 *   POST /api/pedidos.php        -> confirmar un pedido nuevo
 *   PUT  /api/pedidos.php?id=5   -> guardar la valoración (1 a 5) de un pedido mío
 *
 * Body del POST:
 *   { "nombre_cliente": "...", "direccion": "...", "telefono": "...", "email": "...",
 *     "medio_pago": "tarjeta_credito", "items": [ { "producto_id": 1, "cantidad": 2 } ] }
 *
 * Códigos: 200, 201, 400 datos inválidos, 401 sin sesión, 404 no existe
 * (o es de otro usuario), 405, 409 sin stock / producto ya no disponible, 500.
 *
 * CU 5 "Armar y confirmar un pedido". Todo exige sesión: el pedido se
 * asocia al usuario de $_SESSION, nunca a un id que mande el navegador.
 */

require_once __DIR__ . '/_ayudante.php';
require_once __DIR__ . '/../models/PedidoModel.php';

const MEDIOS_PAGO      = ['tarjeta_credito', 'tarjeta_debito', 'transferencia', 'mercado_pago', 'efectivo'];
const MAXIMO_ITEMS     = 20;
const MAXIMA_CANTIDAD  = 99;

try {
    $usuario = requerir_login();
    $pedidos = new PedidoModel();

    switch ($_SERVER['REQUEST_METHOD'] ?? '') {
        case 'GET':
            atender_get($pedidos, (int) $usuario['id']);
            break;

        case 'POST':
            atender_alta($pedidos, (int) $usuario['id']);
            break;

        case 'PUT':
            atender_valoracion($pedidos, (int) $usuario['id']);
            break;

        default:
            header('Allow: GET, POST, PUT');
            error_api('Método no permitido.', 405);
    }
} catch (PDOException $excepcion) {
    error_log('api/pedidos.php: ' . $excepcion->getMessage());
    error_api('Ocurrió un error interno. Intentá de nuevo más tarde.', 500);
}

// ------------------------------------------------------------------

function atender_get(PedidoModel $pedidos, int $usuarioId): void
{
    if (isset($_GET['id'])) {
        $pedido = $pedidos->buscarPorId(leer_id(), $usuarioId);

        // Mismo 404 si no existe o si es de otro usuario: no se revela cuál de las dos.
        if ($pedido === null) {
            error_api('El pedido no existe.', 404);
        }

        responder(['exito' => true, 'pedido' => formatear_pedido($pedido)]);
    }

    responder([
        'exito'   => true,
        'pedidos' => array_map('formatear_pedido', $pedidos->listarPorUsuario($usuarioId)),
    ]);
}

function atender_alta(PedidoModel $pedidos, int $usuarioId): void
{
    $entrada = leer_json();

    $datosCliente = [
        'usuario_id'     => $usuarioId,
        'nombre_cliente' => texto_obligatorio($entrada, 'nombre_cliente', 'el nombre', 150),
        'direccion'      => texto_obligatorio($entrada, 'direccion', 'la dirección', 255),
        'telefono'       => texto_obligatorio($entrada, 'telefono', 'el teléfono', 50),
        'email'          => texto_obligatorio($entrada, 'email', 'el email', 150),
        'medio_pago'     => (string) ($entrada['medio_pago'] ?? ''),
    ];

    if (!filter_var($datosCliente['email'], FILTER_VALIDATE_EMAIL)) {
        error_api('El email no tiene un formato válido.', 400);
    }

    if (!in_array($datosCliente['medio_pago'], MEDIOS_PAGO, true)) {
        error_api('El medio de pago no es válido.', 400);
    }

    $items = validar_items($entrada['items'] ?? null);

    try {
        $id = $pedidos->crear($datosCliente, $items);
    } catch (RuntimeException $excepcion) {
        // Errores de negocio que lanza el Modelo (sin stock, producto borrado).
        // El mensaje lo escribimos nosotros, así que es seguro mostrarlo.
        error_api($excepcion->getMessage(), 409);
    }

    responder([
        'exito'   => true,
        'mensaje' => 'Pedido confirmado.',
        'pedido'  => formatear_pedido($pedidos->buscarPorId($id, $usuarioId)),
    ], 201);
}

function atender_valoracion(PedidoModel $pedidos, int $usuarioId): void
{
    $id         = leer_id();
    $valoracion = filter_var(leer_json()['valoracion'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 5],
    ]);

    if ($valoracion === false) {
        error_api('La valoración debe ser un número entero del 1 al 5.', 400);
    }

    // guardarValoracion() devuelve false también si se repite la misma
    // valoración, por eso la existencia se verifica aparte.
    if ($pedidos->buscarPorId($id, $usuarioId) === null) {
        error_api('El pedido no existe.', 404);
    }

    $pedidos->guardarValoracion($id, $usuarioId, $valoracion);

    responder(['exito' => true, 'mensaje' => '¡Gracias por tu valoración!']);
}

// ------------------------------------------------------------------

function leer_id(): int
{
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($id === false) {
        error_api('Falta el id del pedido o no es válido.', 400);
    }

    return $id;
}

function texto_obligatorio(array $entrada, string $campo, string $descripcion, int $largoMaximo): string
{
    $valor = trim((string) ($entrada[$campo] ?? ''));

    if ($valor === '' || mb_strlen($valor) > $largoMaximo) {
        error_api("Falta $descripcion o es demasiado largo (máximo $largoMaximo caracteres).", 400);
    }

    return $valor;
}

/**
 * Valida la lista de items y junta en uno los que repiten producto_id.
 * Solo se aceptan id y cantidad: el precio lo pone el Modelo desde la base.
 */
function validar_items($items): array
{
    if (!is_array($items) || $items === [] || count($items) > MAXIMO_ITEMS) {
        error_api('El pedido debe tener entre 1 y ' . MAXIMO_ITEMS . ' productos.', 400);
    }

    $cantidades = [];
    foreach ($items as $item) {
        $productoId = filter_var($item['producto_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $cantidad   = filter_var($item['cantidad'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => MAXIMA_CANTIDAD],
        ]);

        if ($productoId === false || $cantidad === false) {
            error_api('Cada producto necesita un producto_id válido y una cantidad de 1 a ' . MAXIMA_CANTIDAD . '.', 400);
        }

        $cantidades[$productoId] = ($cantidades[$productoId] ?? 0) + $cantidad;
    }

    $resultado = [];
    foreach ($cantidades as $productoId => $cantidad) {
        $resultado[] = ['producto_id' => $productoId, 'cantidad' => $cantidad];
    }

    return $resultado;
}

/** Convierte a número lo que MySQL devuelve como texto (DECIMAL). */
function formatear_pedido(array $pedido): array
{
    $pedido['id']         = (int) $pedido['id'];
    $pedido['usuario_id'] = (int) $pedido['usuario_id'];
    $pedido['total']      = (float) $pedido['total'];
    $pedido['valoracion'] = $pedido['valoracion'] !== null ? (int) $pedido['valoracion'] : null;

    if (isset($pedido['items'])) {
        foreach ($pedido['items'] as &$item) {
            $item['producto_id']     = (int) $item['producto_id'];
            $item['cantidad']        = (int) $item['cantidad'];
            $item['precio_unitario'] = (float) $item['precio_unitario'];
        }
        unset($item);
    }

    return $pedido;
}
