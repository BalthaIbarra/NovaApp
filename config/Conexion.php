<?php

/**
 * config/Conexion.php
 * -------------------
 * Conexión a MySQL, armada como Singleton.
 *
 * "Singleton" quiere decir que esta clase nunca se instancia con
 * `new Conexion()` (el constructor está marcado como privado a
 * propósito, para que eso directamente no compile). La única forma
 * de usarla es:
 *
 *     $pdo = Conexion::obtener();
 *
 * La primera vez que alguien llama a obtener(), se abre la conexión
 * de verdad y se guarda en una propiedad estática ($instancia). Todas
 * las llamadas siguientes, sean del mismo archivo o de cualquier otro
 * endpoint dentro del mismo request, reciben ese mismo objeto PDO en
 * vez de abrir una conexión nueva cada vez. Para una sola petición
 * HTTP no cambia mucho, pero evita el error clásico de terminar con
 * cinco conexiones abiertas a la misma base por descuido si un
 * endpoint usa dos o tres Modelos distintos.
 */
class Conexion
{
    /** @var PDO|null La única conexión que va a existir en todo el request. */
    private static ?PDO $instancia = null;

    // Constructor privado: nadie de afuera puede hacer "new Conexion()".
    private function __construct()
    {
    }

    // Tampoco se puede clonar una instancia ya creada (otra forma de
    // terminar con una segunda conexión por accidente).
    private function __clone()
    {
    }

    /**
     * Devuelve la conexión PDO compartida, creándola recién la
     * primera vez que se pide.
     */
    public static function obtener(): PDO
    {
        if (self::$instancia === null) {
            $host      = 'localhost';
            $baseDatos = 'novatech_db';
            $usuario   = 'root';
            $password  = '';

            // utf8mb4 para poder guardar acentos, ñ y emojis sin que se rompan.
            $dsn = "mysql:host=$host;dbname=$baseDatos;charset=utf8mb4";

            $opciones = [
                // Si algo sale mal, que tire una excepción clara en vez de
                // fallar en silencio o devolver "false" sin explicar por qué.
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

                // Las filas que trae una consulta vienen como arreglo
                // asociativo ($fila['nombre']) en vez de también numerado.
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

                // Usa las consultas preparadas reales del motor de MySQL,
                // no una emulación hecha por PHP. Es más seguro.
                PDO::ATTR_EMULATE_PREPARES => false,
            ];

            try {
                self::$instancia = new PDO($dsn, $usuario, $password, $opciones);
            } catch (PDOException $excepcion) {
                // No le mostramos el detalle del error al usuario (podría
                // filtrar información del servidor); lo dejamos en el log
                // para que lo veamos nosotros al revisar errores de PHP.
                error_log('Error de conexion a la base: ' . $excepcion->getMessage());

                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'exito'   => false,
                    'mensaje' => 'No se pudo conectar con la base de datos',
                ]);
                exit;
            }
        }

        return self::$instancia;
    }
}
