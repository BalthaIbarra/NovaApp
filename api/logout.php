<?php

/**
 * api/logout.php   ->   POST /api/logout.php
 * --------------------------------------------
 * Cierra la sesión actual (si había una). Siempre responde 200,
 * incluso si no había nadie logueado: cerrar una sesión que ya
 * estaba cerrada no es un error.
 */

require_once __DIR__ . '/_ayudante.php';

requerir_metodo('POST');

$_SESSION = [];

// Además de destruir la sesión en el servidor, se vence la cookie PHPSESSID
// para que el navegador no siga mandando un id que ya no sirve.
$parametros = session_get_cookie_params();
setcookie(session_name(), '', [
    'expires'  => time() - 3600,
    'path'     => $parametros['path'],
    'httponly' => $parametros['httponly'],
    'samesite' => $parametros['samesite'],
]);

session_destroy();

responder(['exito' => true, 'mensaje' => 'Sesión cerrada.']);
