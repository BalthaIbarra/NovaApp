<?php

/**
 * api/_ayudante.php
 * ------------------
 * Arranque común de todos los endpoints de /api. El nombre empieza
 * con "_" a propósito, para dejar claro que este archivo no es un
 * endpoint en sí mismo (no lo llama nadie por fetch), sino algo que
 * los demás archivos de /api incluyen al principio con:
 *
 *     require_once __DIR__ . '/_ayudante.php';
 *
 * Se encarga de lo que se repetiría en cada endpoint si no estuviera
 * acá: iniciar la sesión, avisar que las respuestas son JSON, y
 * definir funciones de ayuda (responder, error_api, leer_json,
 * requerir_login, requerir_admin, requerir_metodo).
 *
 * Como la SPA (carpeta /public) y la API (/api) se sirven desde el
 * mismo Apache y el mismo puerto, no hace falta configurar CORS: para
 * el navegador es el mismo origen, así que la cookie de sesión
 * (PHPSESSID) viaja sola en cada fetch() sin pedirle nada especial.
 */

require_once __DIR__ . '/../models/UsuarioModel.php';

header('Content-Type: application/json; charset=utf-8');

// Red de seguridad (OWASP A05): XAMPP trae display_errors=On, que ante un
// error de PHP le muestra al cliente un HTML con rutas del servidor. Acá los
// errores nunca se muestran: cualquier warning se convierte en excepción, y
// toda excepción no atrapada termina en un 500 JSON con el detalle solo en el log.
ini_set('display_errors', '0');

set_error_handler(function (int $nivel, string $mensaje, string $archivo, int $linea): bool {
    if (!(error_reporting() & $nivel)) {
        return false;
    }
    // Un "deprecated" no es un error del pedido: se anota y se sigue.
    if ($nivel === E_DEPRECATED || $nivel === E_USER_DEPRECATED) {
        error_log("API (deprecated): $mensaje en $archivo:$linea");
        return true;
    }
    throw new ErrorException($mensaje, 0, $nivel, $archivo, $linea);
});

set_exception_handler(function (Throwable $excepcion): void {
    error_log('API: ' . $excepcion->getMessage() . ' en ' . $excepcion->getFile() . ':' . $excepcion->getLine());
    responder(['exito' => false, 'mensaje' => 'Ocurrió un error interno. Intentá de nuevo más tarde.'], 500);
});

session_set_cookie_params([
    'lifetime' => 0,        // 0 = dura hasta que se cierra el navegador
    'path'     => '/',
    'httponly' => true,     // la cookie no se puede leer desde JavaScript
    'samesite' => 'Lax',
]);
session_start();

/** Responde en JSON con el código HTTP indicado y corta la ejecución. */
function responder($datos, int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Atajo para responder un error con el formato { exito: false, mensaje: ... }. */
function error_api(string $mensaje, int $codigo = 400): void
{
    responder(['exito' => false, 'mensaje' => $mensaje], $codigo);
}

/**
 * Lee el body de la petición como JSON (lo que manda fetch() con
 * Content-Type: application/json). Si no es JSON válido, corta con 400.
 *
 * Exigir ese Content-Type también frena CSRF: un formulario HTML de otro
 * sitio solo puede mandar text/plain o form-data, nunca application/json.
 */
function leer_json(): array
{
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
        error_api('El cuerpo de la petición debe enviarse como application/json.', 415);
    }

    $crudo = file_get_contents('php://input');
    $datos = json_decode($crudo, true);

    if (!is_array($datos)) {
        error_api('El cuerpo de la petición debe ser un JSON válido.', 400);
    }

    return $datos;
}

/**
 * Devuelve $origen[$clave] como texto (recortado salvo que $recortar sea false).
 * Si llega un array u objeto en lugar de texto, corta con 400: sin esto PHP lo
 * convertía en la palabra "Array" y se guardaba en la base.
 */
function texto(array $origen, string $clave, bool $recortar = true): string
{
    $valor = $origen[$clave] ?? '';

    if (!is_string($valor) && !is_int($valor) && !is_float($valor)) {
        error_api("El campo \"$clave\" tiene un formato inválido.", 400);
    }

    return $recortar ? trim((string) $valor) : (string) $valor;
}

/** Datos del usuario logueado (sin password_hash), o null si no hay sesión. */
function usuario_actual(): ?array
{
    if (empty($_SESSION['usuario_id'])) {
        return null;
    }

    $usuarios = new UsuarioModel();

    return $usuarios->buscarPorId((int) $_SESSION['usuario_id']);
}

/** Corta con 401 si no hay nadie logueado. Si hay, devuelve sus datos. */
function requerir_login(): array
{
    $usuario = usuario_actual();

    if ($usuario === null) {
        error_api('Necesitás iniciar sesión para hacer esto.', 401);
    }

    return $usuario;
}

/** Igual que requerir_login(), pero además exige rol "admin". Corta con 403 si no lo es. */
function requerir_admin(): array
{
    $usuario = requerir_login();

    if ($usuario['rol'] !== 'admin') {
        error_api('Esta acción es solo para administradores.', 403);
    }

    return $usuario;
}

/** Corta con 405 si el método HTTP del pedido no es el esperado. */
function requerir_metodo(string $metodo): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $metodo) {
        error_api("Método no permitido, se esperaba $metodo.", 405);
    }
}
