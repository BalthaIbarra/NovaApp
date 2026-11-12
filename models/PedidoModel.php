<?php

require_once __DIR__ . '/../config/Conexion.php';
require_once __DIR__ . '/ProductoModel.php';

/**
 * models/PedidoModel.php
 * -----------------------
 * Acceso a datos de "pedidos" y "pedido_items" (el carrito ya
 * confirmado). Es el Modelo más largo porque un pedido no es una
 * sola fila: es una fila en "pedidos" más una fila en "pedido_items"
 * por cada producto distinto que se compró, y las cuatro cosas
 * (guardar el pedido, guardar los items, descontar el stock, y que
 * si algo falla no quede a medias) tienen que pasar juntas o no pasar
 * ninguna. Para eso se usa una transacción de PDO.
 */
class PedidoModel
{
    private PDO $pdo;
    private ProductoModel $productos;

    public function __construct()
    {
        $this->pdo = Conexion::obtener();
        $this->productos = new ProductoModel();
    }

    /**
     * Crea el pedido completo.
     *
     * $datosCliente: nombre_cliente, direccion, telefono, email, medio_pago.
     * $items: arreglo de ['producto_id' => int, 'cantidad' => int].
     *
     * Devuelve el id del pedido creado. Si algo sale mal a mitad de
     * camino (ej. un producto_id que no existe), se deshace todo con
     * rollBack() y la excepción sigue viaje para que api/pedidos.php
     * la atrape y responda con un error claro.
     */
    public function crear(array $datosCliente, array $items): int
    {
        $this->pdo->beginTransaction();

        try {
            $total = 0.0;

            // Primero resolvemos precio y validamos stock de cada item,
            // ANTES de escribir nada, para no dejar un pedido a medio
            // guardar si el segundo producto de la lista no tiene stock.
            $itemsConPrecio = [];
            foreach ($items as $item) {
                $producto = $this->productos->buscarPorId((int) $item['producto_id']);

                if ($producto === null) {
                    throw new RuntimeException('Un producto del pedido ya no existe.');
                }

                if ($producto['stock'] < $item['cantidad']) {
                    throw new RuntimeException("No hay stock suficiente de \"{$producto['nombre']}\".");
                }

                $itemsConPrecio[] = [
                    'producto_id'     => $producto['id'],
                    'cantidad'        => (int) $item['cantidad'],
                    'precio_unitario' => (float) $producto['precio'],
                ];
                $total += $producto['precio'] * $item['cantidad'];
            }

            $consultaPedido = $this->pdo->prepare(
                'INSERT INTO pedidos (usuario_id, nombre_cliente, direccion, telefono, email, medio_pago, total)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $consultaPedido->execute([
                $datosCliente['usuario_id'],
                $datosCliente['nombre_cliente'],
                $datosCliente['direccion'],
                $datosCliente['telefono'],
                $datosCliente['email'],
                $datosCliente['medio_pago'],
                $total,
            ]);
            $pedidoId = (int) $this->pdo->lastInsertId();

            $consultaItem = $this->pdo->prepare(
                'INSERT INTO pedido_items (pedido_id, producto_id, cantidad, precio_unitario)
                 VALUES (?, ?, ?, ?)'
            );
            foreach ($itemsConPrecio as $item) {
                $consultaItem->execute([$pedidoId, $item['producto_id'], $item['cantidad'], $item['precio_unitario']]);
                $this->productos->descontarStock($item['producto_id'], $item['cantidad']);
            }

            $this->pdo->commit();

            return $pedidoId;
        } catch (Throwable $excepcion) {
            $this->pdo->rollBack();
            throw $excepcion;
        }
    }

    /** Historial de pedidos de un usuario (más nuevos primero), sin el detalle de items. */
    public function listarPorUsuario(int $usuarioId): array
    {
        $consulta = $this->pdo->prepare(
            'SELECT * FROM pedidos WHERE usuario_id = ? ORDER BY fecha DESC'
        );
        $consulta->execute([$usuarioId]);

        return $consulta->fetchAll();
    }

    /**
     * Un pedido puntual con su detalle de productos (para el ticket de
     * confirmación). $usuarioId, si se pasa, exige que el pedido sea de
     * ESE usuario — así un cliente no puede pedir el ticket de otro
     * solo adivinando el id en la URL.
     */
    public function buscarPorId(int $id, ?int $usuarioId = null): ?array
    {
        if ($usuarioId !== null) {
            $consulta = $this->pdo->prepare('SELECT * FROM pedidos WHERE id = ? AND usuario_id = ?');
            $consulta->execute([$id, $usuarioId]);
        } else {
            $consulta = $this->pdo->prepare('SELECT * FROM pedidos WHERE id = ?');
            $consulta->execute([$id]);
        }

        $pedido = $consulta->fetch();
        if (!$pedido) {
            return null;
        }

        $consultaItems = $this->pdo->prepare(
            'SELECT pi.cantidad, pi.precio_unitario, p.nombre
             FROM pedido_items pi
             JOIN productos p ON p.id = pi.producto_id
             WHERE pi.pedido_id = ?'
        );
        $consultaItems->execute([$id]);
        $pedido['items'] = $consultaItems->fetchAll();

        return $pedido;
    }

    /** Guarda la valoración de 1 a 5 que el cliente deja al cerrar el ticket. */
    public function guardarValoracion(int $id, int $usuarioId, int $valoracion): bool
    {
        $consulta = $this->pdo->prepare(
            'UPDATE pedidos SET valoracion = ? WHERE id = ? AND usuario_id = ?'
        );
        $consulta->execute([$valoracion, $id, $usuarioId]);

        return $consulta->rowCount() > 0;
    }
}
