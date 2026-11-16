<?php

/**
 * api/productos.php
 * ------------------
 *   GET    /api/productos.php                    -> lista (?categoria=audio y/o ?q=texto)
 *   GET    /api/productos.php?id=3               -> detalle
 *   GET    /api/productos.php?slug=novasound-x1  -> detalle por slug
 *   GET    /api/productos.php?destacado=1        -> producto destacado
 *   POST   /api/productos.php                    -> alta         (solo admin)
 *   PUT    /api/productos.php?id=3               -> modificación (solo admin)
 *   DELETE /api/productos.php?id=3               -> baja         (solo admin)
 *
 * Códigos: 200, 201, 400 datos inválidos, 401 sin sesión, 403 no es admin,
 * 404 no existe, 405 método no soportado, 409 conflicto, 500 error de base.
 *
 * Los GET son públicos (CU 1 y 2: ver catálogo y detalle). Las escrituras
 * son el CU 6 "Gestionar productos" y exigen rol admin.
 */

require_once __DIR__ . '/_ayudante.php';
require_once __DIR__ . '/../models/ProductoModel.php';

// Lista cerrada slug => nombre visible: el front arma la clase CSS
// (cat-audio, cat-carga...) con el slug, así que no puede ser cualquiera.
const CATEGORIAS = [
    'audio'       => 'Audio',
    'carga'       => 'Carga',
    'perifericos' => 'Periféricos',
    'accesorios'  => 'Accesorios',
];

const LARGO_MAXIMO_BUSQUEDA = 100;

try {
    $productos = new ProductoModel();

    switch ($_SERVER['REQUEST_METHOD'] ?? '') {
        case 'GET':
            atender_get($productos);
            break;

        case 'POST':
            requerir_admin();
            atender_alta($productos);
            break;

        case 'PUT':
            requerir_admin();
            atender_modificacion($productos);
            break;

        case 'DELETE':
            requerir_admin();
            atender_baja($productos);
            break;

        default:
            header('Allow: GET, POST, PUT, DELETE');
            error_api('Método no permitido.', 405);
    }
} catch (PDOException $excepcion) {
    // El detalle (SQL, rutas del servidor) va solo al log de PHP, nunca al cliente.
    error_log('api/productos.php: ' . $excepcion->getMessage());
    error_api('Ocurrió un error interno. Intentá de nuevo más tarde.', 500);
}

// ------------------------------------------------------------------

function atender_get(ProductoModel $productos): void
{
    if (isset($_GET['id'])) {
        responder_producto($productos->buscarPorId(leer_id()));
    }

    if (isset($_GET['slug'])) {
        responder_producto($productos->buscarPorSlug(texto($_GET, 'slug')));
    }

    if (isset($_GET['destacado'])) {
        responder_producto($productos->obtenerDestacado());
    }

    $categoria = texto($_GET, 'categoria');
    $busqueda  = mb_substr(texto($_GET, 'q'), 0, LARGO_MAXIMO_BUSQUEDA);

    if ($categoria !== '' && !array_key_exists($categoria, CATEGORIAS)) {
        error_api('La categoría pedida no existe.', 400);
    }

    responder([
        'exito'     => true,
        'productos' => array_map('formatear_producto', $productos->listarTodos($categoria, $busqueda)),
    ]);
}

function atender_alta(ProductoModel $productos): void
{
    $datos = validar_producto(leer_json());

    // Si el admin no manda slug, se arma desde el nombre.
    $datos['slug'] = $datos['slug'] ?? generar_slug($datos['nombre']);

    if ($datos['slug'] === '') {
        error_api('No se pudo generar un slug a partir del nombre.', 400);
    }

    // La columna ya es UNIQUE; se chequea antes para responder 409 y no 500.
    if ($productos->buscarPorSlug($datos['slug']) !== null) {
        error_api('Ya existe un producto con ese slug.', 409);
    }

    $id = $productos->crear($datos);

    responder([
        'exito'    => true,
        'mensaje'  => 'Producto creado correctamente.',
        'producto' => formatear_producto($productos->buscarPorId($id)),
    ], 201);
}

function atender_modificacion(ProductoModel $productos): void
{
    $id = leer_id();

    // actualizar() devuelve false también si los datos no cambiaron,
    // por eso la existencia se verifica aparte.
    if ($productos->buscarPorId($id) === null) {
        error_api('El producto no existe.', 404);
    }

    // El slug no se edita: cambiarlo rompería los enlaces existentes.
    $productos->actualizar($id, validar_producto(leer_json()));

    responder([
        'exito'    => true,
        'mensaje'  => 'Producto actualizado correctamente.',
        'producto' => formatear_producto($productos->buscarPorId($id)),
    ]);
}

function atender_baja(ProductoModel $productos): void
{
    $id = leer_id();

    if ($productos->buscarPorId($id) === null) {
        error_api('El producto no existe.', 404);
    }

    try {
        $productos->eliminar($id);
    } catch (PDOException $excepcion) {
        // 23000 = la FK de pedido_items impide borrar un producto ya vendido.
        if ($excepcion->getCode() === '23000') {
            error_api('No se puede eliminar: el producto ya forma parte de pedidos realizados.', 409);
        }

        throw $excepcion;
    }

    responder(['exito' => true, 'mensaje' => 'Producto eliminado correctamente.']);
}

// ------------------------------------------------------------------

function responder_producto(?array $producto): void
{
    if ($producto === null) {
        error_api('El producto no existe.', 404);
    }

    responder(['exito' => true, 'producto' => formatear_producto($producto)]);
}

function leer_id(): int
{
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($id === false) {
        error_api('Falta el id del producto o no es válido.', 400);
    }

    return $id;
}

/**
 * Valida el body de un alta o edición y lo devuelve con las claves que
 * espera ProductoModel. Se valida en el servidor aunque el formulario
 * también lo haga, porque la validación del navegador se puede saltear.
 */
function validar_producto(array $entrada): array
{
    $nombre        = texto($entrada, 'nombre');
    $categoriaSlug = texto($entrada, 'categoria_slug');
    $descripcion   = texto($entrada, 'descripcion');
    $imagen        = texto($entrada, 'imagen');
    $precio        = $entrada['precio'] ?? null;
    $stock         = filter_var($entrada['stock'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

    if ($nombre === '' || mb_strlen($nombre) > 150) {
        error_api('El nombre es obligatorio y no puede superar los 150 caracteres.', 400);
    }

    if (!array_key_exists($categoriaSlug, CATEGORIAS)) {
        error_api('La categoría no es válida. Opciones: ' . implode(', ', array_keys(CATEGORIAS)) . '.', 400);
    }

    if (!is_numeric($precio) || (float) $precio <= 0 || (float) $precio > 99999999.99) {
        error_api('El precio debe ser un número mayor a 0.', 400);
    }

    if ($stock === false) {
        error_api('El stock debe ser un número entero mayor o igual a 0.', 400);
    }

    // Termina en el src de un <img>: solo http(s) o rutas dentro de images/,
    // para que no se cuele un "javascript:" o similar.
    $esUrlWeb    = filter_var($imagen, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $imagen);
    $esRutaLocal = preg_match('#^images/[A-Za-z0-9._/-]+$#', $imagen) && strpos($imagen, '..') === false;
    if (mb_strlen($imagen) > 255 || (!$esUrlWeb && !$esRutaLocal)) {
        error_api('La imagen debe ser una URL http(s) o una ruta dentro de images/.', 400);
    }

    $especificaciones = null;
    if (!empty($entrada['especificaciones'])) {
        if (!is_array($entrada['especificaciones'])) {
            error_api('Las especificaciones deben ser un objeto clave/valor.', 400);
        }

        $especificaciones = [];
        foreach ($entrada['especificaciones'] as $clave => $valor) {
            if (!is_string($clave) || !is_scalar($valor)) {
                error_api('Las especificaciones deben ser un objeto clave/valor de texto.', 400);
            }
            $especificaciones[trim($clave)] = trim((string) $valor);
        }
    }

    $datos = [
        'nombre'           => $nombre,
        'categoria'        => CATEGORIAS[$categoriaSlug],
        'categoria_slug'   => $categoriaSlug,
        'descripcion'      => $descripcion !== '' ? $descripcion : null,
        'especificaciones' => $especificaciones,
        'precio'           => round((float) $precio, 2),
        'stock'            => $stock,
        'imagen'           => $imagen,
        'destacado'        => !empty($entrada['destacado']) ? 1 : 0,
    ];

    // Solo se usa en el alta; en la edición se ignora.
    $slug = texto($entrada, 'slug');
    if ($slug !== '') {
        if (strlen($slug) > 60 || !preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug)) {
            error_api('El slug solo puede tener minúsculas, números y guiones (ej. "novaclick-pro").', 400);
        }
        $datos['slug'] = $slug;
    }

    return $datos;
}

/** "Teclado Mecánico NovaKey RGB" -> "teclado-mecanico-novakey-rgb" */
function generar_slug(string $texto): string
{
    $texto = strtr(mb_strtolower($texto), [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
    ]);

    return substr(trim(preg_replace('/[^a-z0-9]+/', '-', $texto), '-'), 0, 60);
}

/** MySQL devuelve DECIMAL como texto; el front necesita números para sumar el carrito. */
function formatear_producto(array $fila): array
{
    $fila['id']        = (int) $fila['id'];
    $fila['precio']    = (float) $fila['precio'];
    $fila['stock']     = (int) $fila['stock'];
    $fila['destacado'] = (bool) $fila['destacado'];

    return $fila;
}
