<?php

require_once __DIR__ . '/../config/Conexion.php';

/**
 * models/UsuarioModel.php
 * ------------------------
 * Todo lo que tiene que ver con leer y escribir la tabla "usuarios".
 *
 * La idea del patrón MVC es que este archivo sea el ÚNICO lugar del
 * proyecto que arma consultas SQL sobre usuarios. Los endpoints de
 * /api (login.php, registro.php, etc.) no escriben SQL: le piden a
 * este Modelo lo que necesitan ("buscarPorEmail", "crear", etc.) y
 * trabajan con el arreglo que les devuelve. Así, si el día de mañana
 * cambia algo de la tabla, se corrige en un solo lugar.
 *
 * Ninguno de estos métodos sabe nada de JSON, de sesiones ni de
 * códigos HTTP — esa parte es responsabilidad de /api. Este archivo
 * solo habla con la base de datos.
 */
class UsuarioModel
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Conexion::obtener();
    }

    /**
     * Busca un usuario por email. Devuelve el arreglo con TODAS sus
     * columnas (incluido el password_hash) porque login.php necesita
     * compararlo con password_verify(). Devuelve null si no existe.
     */
    public function buscarPorEmail(string $email): ?array
    {
        $consulta = $this->pdo->prepare('SELECT * FROM usuarios WHERE email = ?');
        $consulta->execute([$email]);
        $fila = $consulta->fetch();

        return $fila ?: null;
    }

    /**
     * Busca un usuario por id. Esta versión NO trae password_hash:
     * se usa para devolver "quién soy" al front (yo.php), y no hay
     * ninguna razón para que el hash salga de la base en esa respuesta.
     */
    public function buscarPorId(int $id): ?array
    {
        $consulta = $this->pdo->prepare(
            'SELECT id, nombre, email, rol, fecha_registro FROM usuarios WHERE id = ?'
        );
        $consulta->execute([$id]);
        $fila = $consulta->fetch();

        return $fila ?: null;
    }

    /**
     * true si ya existe una cuenta con ese email. registro.php la usa
     * para no dejar crear dos cuentas con el mismo email.
     */
    public function existeEmail(string $email): bool
    {
        $consulta = $this->pdo->prepare('SELECT 1 FROM usuarios WHERE email = ?');
        $consulta->execute([$email]);

        return (bool) $consulta->fetch();
    }

    /**
     * Crea un usuario nuevo. OJO: $passwordHash tiene que venir YA
     * procesado con password_hash() desde registro.php — este Modelo
     * no hashea nada, solo guarda lo que le pasan. Devuelve el id
     * que le asignó la base al usuario nuevo.
     */
    public function crear(string $nombre, string $email, string $passwordHash, string $rol = 'cliente'): int
    {
        $consulta = $this->pdo->prepare(
            'INSERT INTO usuarios (nombre, email, password_hash, rol) VALUES (?, ?, ?, ?)'
        );
        $consulta->execute([$nombre, $email, $passwordHash, $rol]);

        return (int) $this->pdo->lastInsertId();
    }
}
