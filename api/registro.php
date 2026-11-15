<?php

/**
 * api/registro.php   ->   POST /api/registro.php
 * --------------------------------------------------
 * Body esperado: { "nombre": "...", "email": "...", "password": "...", "password_confirm": "..." }
 *
 * Devuelve:
 *   201 -> { "exito": true, "mensaje": "..." }
 *   400 -> datos inválidos (falta algo, contraseña corta, no coincide)
 *   409 -> ya existe una cuenta con ese email
 *
 * La validación de "contraseña de al menos 8 caracteres" y "las dos
 * contraseñas coinciden" ya existía en app.js desde la Entrega 2;
 * se repite acá del lado del servidor porque esa validación del
 * navegador se puede saltear fácilmente (ej. llamando a este mismo
 * endpoint directo con curl o Postman), y el servidor es el único
 * lugar en el que confiar de verdad.
 */

require_once __DIR__ . '/_ayudante.php';

const LONGITUD_MINIMA_CLAVE = 8;

requerir_metodo('POST');

$datos            = leer_json();
$nombre           = trim($datos['nombre'] ?? '');
$email            = trim($datos['email'] ?? '');
$password         = (string) ($datos['password'] ?? '');
$passwordConfirma = (string) ($datos['password_confirm'] ?? '');

if ($nombre === '' || $email === '' || $password === '') {
    error_api('Faltan datos obligatorios (nombre, email o contraseña).', 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    error_api('El email no tiene un formato válido.', 400);
}

if (strlen($password) < LONGITUD_MINIMA_CLAVE) {
    error_api('La contraseña debe tener al menos ' . LONGITUD_MINIMA_CLAVE . ' caracteres.', 400);
}

if ($password !== $passwordConfirma) {
    error_api('Las contraseñas no coinciden.', 400);
}

$usuarios = new UsuarioModel();

if ($usuarios->existeEmail($email)) {
    error_api('Ya existe una cuenta registrada con ese email.', 409);
}

// password_hash() genera el "salt" solo y lo guarda adentro del mismo
// hash; nunca se guarda la contraseña en texto plano en ningún lado.
$passwordHash = password_hash($password, PASSWORD_BCRYPT);

$usuarios->crear($nombre, $email, $passwordHash);

responder([
    'exito'   => true,
    'mensaje' => 'Cuenta creada correctamente. Ya podés iniciar sesión.',
], 201);
