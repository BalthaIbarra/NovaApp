<?php

/**
 * api/yo.php   ->   GET /api/yo.php
 * ------------------------------------
 * Le dice al front "quién está logueado ahora mismo", a partir de la
 * cookie de sesión. La SPA lo llama al cargar cualquier página para
 * decidir si muestra "Iniciar sesión" o el nombre del cliente en el
 * header, y para saber si puede dejarlo pasar a comprar.html.
 *
 * Devuelve:
 *   200 -> { "exito": true, "usuario": {...} }  si hay sesión
 *   401 -> { "exito": false, "mensaje": "..." } si no hay sesión
 */

require_once __DIR__ . '/_ayudante.php';

requerir_metodo('GET');

$usuario = requerir_login();

responder(['exito' => true, 'usuario' => $usuario]);
