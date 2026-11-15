<?php

/**
 * api/login.php   ->   POST /api/login.php
 * ------------------------------------------
 * Body esperado: { "email": "...", "password": "..." }
 *
 * Devuelve:
 *   200 -> { "exito": true, "usuario": {...} }
 *   400 -> faltan datos
 *   401 -> email o contraseña incorrectos
 *
 * Reemplaza al login simulado de la "Entrega 2" (que comparaba la
 * contraseña en texto plano contra data/usuarios.json en el propio
 * navegador). Acá la contraseña nunca se compara "a mano": se usa
 * password_verify() contra el hash que guardó registro.php.
 */

require_once __DIR__ . '/_ayudante.php';

requerir_metodo('POST');

$datos    = leer_json();
$email    = trim($datos['email'] ?? '');
$password = (string) ($datos['password'] ?? '');

if ($email === '' || $password === '') {
    error_api('Faltan el email o la contraseña.', 400);
}

$usuarios = new UsuarioModel();
$usuario  = $usuarios->buscarPorEmail($email);

// Damos el mismo mensaje si falla el email o la contraseña, para no
// revelar con una respuesta distinta qué emails están registrados.
if (!$usuario || !password_verify($password, $usuario['password_hash'])) {
    error_api('Email o contraseña incorrectos.', 401);
}

// Cambia el id de sesión al loguearse (buena práctica: evita que una
// sesión obtenida ANTES del login quede validada después del login).
session_regenerate_id(true);
$_SESSION['usuario_id'] = (int) $usuario['id'];

responder([
    'exito'   => true,
    'usuario' => [
        'id'     => (int) $usuario['id'],
        'nombre' => $usuario['nombre'],
        'email'  => $usuario['email'],
        'rol'    => $usuario['rol'],
    ],
]);
