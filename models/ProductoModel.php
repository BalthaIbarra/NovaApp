<?php

require_once __DIR__ . '/../config/Conexion.php';

/**
 * models/ProductoModel.php
 * -------------------------
 * Acceso a datos del catálogo (tabla "productos").
 *
 * Igual que UsuarioModel: acá vive toda la SQL de productos, y
 * api/productos.php solo llama a estos métodos. Eso es lo que la
 * consigna pide como "CRUD con PDO Prepared Statements" separado
 * del controlador.
 */
class ProductoModel
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Conexion::obtener();
    }

    /**
     * Lista productos para el catálogo (listado_box.html / listado_tabla.html).
     * $categoriaSlug filtra por categoría (igual que las pestañas "Audio",
     * "Periféricos", etc. que ya existían armadas con CSS). $busqueda
     * filtra por nombre, igual que el buscador del header.
     * No trae "especificaciones" porque el listado no la necesita.
     */
    public function listarTodos(?string $categoriaSlug = null, ?string $busqueda = null): array
    {
        $condiciones = [];
        $parametros  = [];

        if ($categoriaSlug !== null && $categoriaSlug !== '') {
            $condiciones[] = 'categoria_slug = ?';
            $parametros[]  = $categoriaSlug;
        }

        if ($busqueda !== null && $busqueda !== '') {
            $condiciones[] = 'nombre LIKE ?';
            $parametros[]  = '%' . $busqueda . '%';
        }

        $where = $condiciones ? ('WHERE ' . implode(' AND ', $condiciones)) : '';

        $consulta = $this->pdo->prepare(
            "SELECT id, slug, nombre, categoria, categoria_slug, precio, stock, imagen, destacado
             FROM productos
             $where
             ORDER BY nombre ASC"
        );
        $consulta->execute($parametros);

        return $consulta->fetchAll();
    }

    /**
     * Ficha completa de un producto (para producto.html y para editarlo
     * desde el panel de administración). Devuelve "especificaciones" ya
     * decodificada de JSON a arreglo asociativo, para que el endpoint no
     * tenga que preocuparse por eso.
     */
    public function buscarPorId(int $id): ?array
    {
        $consulta = $this->pdo->prepare('SELECT * FROM productos WHERE id = ?');
        $consulta->execute([$id]);
        $fila = $consulta->fetch();

        return $fila ? $this->decodificarEspecificaciones($fila) : null;
    }

    /** Igual que buscarPorId, pero por el slug ("novasound-x1") que usa el front. */
    public function buscarPorSlug(string $slug): ?array
    {
        $consulta = $this->pdo->prepare('SELECT * FROM productos WHERE slug = ?');
        $consulta->execute([$slug]);
        $fila = $consulta->fetch();

        return $fila ? $this->decodificarEspecificaciones($fila) : null;
    }

    /** El producto que se muestra en la portada y en producto.html. */
    public function obtenerDestacado(): ?array
    {
        $consulta = $this->pdo->query('SELECT * FROM productos WHERE destacado = 1 LIMIT 1');
        $fila = $consulta->fetch();

        return $fila ? $this->decodificarEspecificaciones($fila) : null;
    }

    /**
     * Alta de un producto nuevo (panel de administración).
     * $especificaciones llega como arreglo asociativo o null; acá se
     * convierte a JSON recién al guardar, nunca antes.
     */
    public function crear(array $datos): int
    {
        $consulta = $this->pdo->prepare(
            'INSERT INTO productos
                (slug, nombre, categoria, categoria_slug, descripcion, especificaciones, precio, stock, imagen, destacado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $consulta->execute([
            $datos['slug'],
            $datos['nombre'],
            $datos['categoria'],
            $datos['categoria_slug'],
            $datos['descripcion'] ?? null,
            isset($datos['especificaciones']) ? json_encode($datos['especificaciones'], JSON_UNESCAPED_UNICODE) : null,
            $datos['precio'],
            $datos['stock'],
            $datos['imagen'],
            $datos['destacado'] ?? 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** Edición de un producto existente. Devuelve true si modificó alguna fila. */
    public function actualizar(int $id, array $datos): bool
    {
        $consulta = $this->pdo->prepare(
            'UPDATE productos SET
                nombre = ?, categoria = ?, categoria_slug = ?, descripcion = ?,
                especificaciones = ?, precio = ?, stock = ?, imagen = ?, destacado = ?
             WHERE id = ?'
        );
        $consulta->execute([
            $datos['nombre'],
            $datos['categoria'],
            $datos['categoria_slug'],
            $datos['descripcion'] ?? null,
            isset($datos['especificaciones']) ? json_encode($datos['especificaciones'], JSON_UNESCAPED_UNICODE) : null,
            $datos['precio'],
            $datos['stock'],
            $datos['imagen'],
            $datos['destacado'] ?? 0,
            $id,
        ]);

        return $consulta->rowCount() > 0;
    }

    /** Baja de un producto. Devuelve true si borró alguna fila. */
    public function eliminar(int $id): bool
    {
        $consulta = $this->pdo->prepare('DELETE FROM productos WHERE id = ?');
        $consulta->execute([$id]);

        return $consulta->rowCount() > 0;
    }

    /** Descuenta stock al confirmar un pedido (lo usa PedidoModel). */
    public function descontarStock(int $id, int $cantidad): void
    {
        $consulta = $this->pdo->prepare('UPDATE productos SET stock = stock - ? WHERE id = ?');
        $consulta->execute([$cantidad, $id]);
    }

    private function decodificarEspecificaciones(array $fila): array
    {
        $fila['especificaciones'] = $fila['especificaciones']
            ? json_decode($fila['especificaciones'], true)
            : null;

        return $fila;
    }
}
