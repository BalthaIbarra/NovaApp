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
session_destroy();

responder(['exito' => true, 'mensaje' => 'Sesión cerrada.']);
