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
     * $datosCliente: usuario_id, nombre_cliente, direccion, telefono, email, medio_pago.
     * $items: arreglo de ['producto_id' => int, 'cantidad' => int].
     *
     * Devuelve el id del pedido creado. Si algo sale mal a mitad de
     * camino, se deshace todo con rollBack(). Los problemas del pedido
     * (producto inexistente, sin stock) se lanzan como RuntimeException
     * para que api/pedidos.php los distinga de un error de la base.
     */
    public function crear(array $datosCliente, array $items): int
    {
        $this->pdo->beginTransaction();

        try {
            $total = 0.0;

            // Primero resolvemos el precio de cada item desde la base (nunca
            // se confía en un precio que mande el navegador).
            $itemsConPrecio = [];
            foreach ($items as $item) {
                $producto = $this->productos->buscarPorId((int) $item['producto_id']);

                if ($producto === null) {
                    throw new RuntimeException('Un producto del pedido ya no existe.');
                }

                $itemsConPrecio[] = [
                    'producto_id'     => (int) $producto['id'],
                    'nombre'          => $producto['nombre'],
                    'cantidad'        => (int) $item['cantidad'],
                    'precio_unitario' => (float) $producto['precio'],
                ];
                $total += $producto['precio'] * $item['cantidad'];
            }

            $consultaPedido = $this->pdo->prepare(
                'INSERT INTO pedidos (usuario_id, nombre_cliente, direccion, telefono, email, medio_pago, total)
                 VALUES (:usuario_id, :nombre_cliente, :direccion, :telefono, :email, :medio_pago, :total)'
            );
            $consultaPedido->execute([
                ':usuario_id'     => $datosCliente['usuario_id'],
                ':nombre_cliente' => $datosCliente['nombre_cliente'],
                ':direccion'      => $datosCliente['direccion'],
                ':telefono'       => $datosCliente['telefono'],
                ':email'          => $datosCliente['email'],
                ':medio_pago'     => $datosCliente['medio_pago'],
                ':total'          => round($total, 2),
            ]);
            $pedidoId = (int) $this->pdo->lastInsertId();

            $consultaItem = $this->pdo->prepare(
                'INSERT INTO pedido_items (pedido_id, producto_id, cantidad, precio_unitario)
                 VALUES (:pedido_id, :producto_id, :cantidad, :precio_unitario)'
            );
            foreach ($itemsConPrecio as $item) {
                if (!$this->productos->descontarStock($item['producto_id'], $item['cantidad'])) {
                    throw new RuntimeException("No hay stock suficiente de \"{$item['nombre']}\".");
                }

                $consultaItem->execute([
                    ':pedido_id'       => $pedidoId,
                    ':producto_id'     => $item['producto_id'],
                    ':cantidad'        => $item['cantidad'],
                    ':precio_unitario' => $item['precio_unitario'],
                ]);
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
            'SELECT * FROM pedidos WHERE usuario_id = :usuario_id ORDER BY fecha DESC, id DESC'
        );
        $consulta->execute([':usuario_id' => $usuarioId]);

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
        $consulta = $this->pdo->prepare(
            'SELECT * FROM pedidos
             WHERE id = :id AND (:usuario_a IS NULL OR usuario_id = :usuario_b)'
        );
        $consulta->execute([':id' => $id, ':usuario_a' => $usuarioId, ':usuario_b' => $usuarioId]);

        $pedido = $consulta->fetch();
        if (!$pedido) {
            return null;
        }

        $consultaItems = $this->pdo->prepare(
            'SELECT pi.producto_id, p.nombre, pi.cantidad, pi.precio_unitario
             FROM pedido_items pi
             JOIN productos p ON p.id = pi.producto_id
             WHERE pi.pedido_id = :pedido_id'
        );
        $consultaItems->execute([':pedido_id' => $id]);
        $pedido['items'] = $consultaItems->fetchAll();

        return $pedido;
    }

    /** Guarda la valoración de 1 a 5 que el cliente deja al cerrar el ticket. */
    public function guardarValoracion(int $id, int $usuarioId, int $valoracion): bool
    {
        $consulta = $this->pdo->prepare(
            'UPDATE pedidos SET valoracion = :valoracion WHERE id = :id AND usuario_id = :usuario_id'
        );
        $consulta->execute([':valoracion' => $valoracion, ':id' => $id, ':usuario_id' => $usuarioId]);

        return $consulta->rowCount() > 0;
    }
}
