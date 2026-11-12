-- ============================================================
--  sql/schema.sql
--  Base de datos de Nova Tech para el AE4.
-- ------------------------------------------------------------
--  Evoluciona la AE1 (maqueta estática) y la "Entrega 2" (buscador
--  y login simulados contra data/productos.json y data/usuarios.json)
--  a una base real en MySQL. La forma de la tabla productos está
--  pensada a propósito igual que esos JSON, para que la migración
--  de datos sea directa.
--
--  Cómo correrlo:
--    1) Crear la base en phpMyAdmin (o con el CREATE DATABASE de
--       abajo) y pararse en ella.
--    2) Pegar este archivo completo en la pestaña SQL y ejecutar.
--
--  Usuarios de prueba que deja cargados (contraseña real entre
--  paréntesis, el hash ya está generado con password_hash()):
--    demo@novatech.com   / demo1234   (rol cliente)
--    admin@novatech.com  / admin1234  (rol admin)
-- ============================================================

CREATE DATABASE IF NOT EXISTS novatech_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE novatech_db;

-- ------------------------------------------------------------
--  usuarios
--  Clientes y administradores de la tienda. El mismo login sirve
--  para los dos; lo que cambia es el rol.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  nombre          VARCHAR(120)  NOT NULL,
  email           VARCHAR(150)  NOT NULL UNIQUE,
  password_hash   VARCHAR(255)  NOT NULL,
  rol             ENUM('cliente', 'admin') NOT NULL DEFAULT 'cliente',
  fecha_registro  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
--  productos
--  El catálogo. "slug" es el identificador de texto que ya se
--  usaba en data/productos.json (ej. "novasound-x1"); lo dejamos
--  como columna propia porque el front lo usa para armar clases
--  CSS de categoría (cat-audio, cat-carga, etc.) y lo vamos a
--  necesitar para no tener que tocar ese CSS.
--  "especificaciones" guarda pares clave/valor en JSON (solo lo
--  completo para el producto destacado, que es el único que
--  muestra una ficha con tabla de specs); el resto queda en NULL.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS productos (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  slug             VARCHAR(60)    NOT NULL UNIQUE,
  nombre           VARCHAR(150)   NOT NULL,
  categoria        VARCHAR(50)    NOT NULL,
  categoria_slug   VARCHAR(30)    NOT NULL,
  descripcion      TEXT           NULL,
  especificaciones JSON           NULL,
  precio           DECIMAL(10,2)  NOT NULL,
  stock            INT            NOT NULL DEFAULT 0,
  imagen           VARCHAR(255)   NOT NULL,
  destacado        TINYINT(1)     NOT NULL DEFAULT 0,
  fecha_alta       TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
--  pedidos
--  Un pedido confirmado en comprar.html. Guarda los datos de
--  envío "a mano" (nombre_cliente, direccion, etc.) en vez de
--  traerlos siempre de "usuarios", porque alguien puede pedir
--  que se lo mande a otra dirección o con otro nombre de contacto.
--  "valoracion" es la estrellita de 1 a 5 que ya existía como
--  truco de CSS en la AE1; acá pasa a guardarse de verdad.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pedidos (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id      INT            NOT NULL,
  nombre_cliente  VARCHAR(150)   NOT NULL,
  direccion       VARCHAR(255)   NOT NULL,
  telefono        VARCHAR(50)    NOT NULL,
  email           VARCHAR(150)   NOT NULL,
  medio_pago      ENUM('tarjeta_credito', 'tarjeta_debito', 'transferencia', 'mercado_pago', 'efectivo') NOT NULL,
  total           DECIMAL(10,2)  NOT NULL,
  valoracion      TINYINT        NULL,
  estado          ENUM('confirmado', 'cancelado') NOT NULL DEFAULT 'confirmado',
  fecha           TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pedidos_usuario
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
--  pedido_items
--  El detalle de cada pedido (qué productos, en qué cantidad).
--  "precio_unitario" es una FOTO del precio al momento de la
--  compra: si mañana el producto cambia de precio, el pedido
--  viejo no debe cambiar con él.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pedido_items (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  pedido_id        INT            NOT NULL,
  producto_id      INT            NOT NULL,
  cantidad         INT            NOT NULL,
  precio_unitario  DECIMAL(10,2)  NOT NULL,
  CONSTRAINT fk_items_pedido
    FOREIGN KEY (pedido_id) REFERENCES pedidos(id),
  CONSTRAINT fk_items_producto
    FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
--  Datos de prueba
-- ------------------------------------------------------------

-- Contraseñas reales: demo1234 / admin1234 (hasheadas con password_hash()).
INSERT INTO usuarios (nombre, email, password_hash, rol) VALUES
  ('Usuario Demo',       'demo@novatech.com',  '$2y$10$rDDhbQrYkmDnHRhXiaKU..HzY/qz/z7CXy1ZGYcPGUYcKrWCR2.Ym', 'cliente'),
  ('Administrador Nova', 'admin@novatech.com', '$2y$10$pZeQI7c4Mb0uLdB8yhLgJ.5htSeZtdNvzWTQ.RcJRkEuNu9Hz7Gma', 'admin');

-- Los 8 productos, migrados tal cual de data/productos.json.
-- Solo el destacado (NovaSound X1) trae descripción y especificaciones,
-- porque es el único que hoy tiene ficha propia en producto.html.
INSERT INTO productos (slug, nombre, categoria, categoria_slug, descripcion, especificaciones, precio, stock, imagen, destacado) VALUES
  (
    'novasound-x1', 'Auriculares Bluetooth NovaSound X1', 'Audio', 'audio',
    'Los NovaSound X1 combinan cancelación de ruido activa de doble micrófono, drivers de 40mm de neodimio y una estructura plegable de aluminio cepillado.',
    JSON_OBJECT(
      'Categoría', 'Audio',
      'Conectividad', 'Bluetooth 5.3 + entrada Jack 3.5mm',
      'Batería', 'Hasta 40 horas (ANC apagado) / 28 horas (ANC activo)',
      'Cancelación de ruido', 'Activa (ANC), doble micrófono',
      'Drivers', '40mm de neodimio',
      'Peso', '254 g',
      'Color', 'Negro grafito'
    ),
    45999.00, 25, 'https://images.unsplash.com/photo-1585298723682-7115561c51b7?w=500&q=75&auto=format&fit=crop', 1
  ),
  ('novacharge-65w', 'Cargador Rápido GaN NovaCharge 65W', 'Carga', 'carga', NULL, NULL,
    18500.00, 40, 'https://images.unsplash.com/photo-1583863788434-e58a36330cf0?w=500&q=75&auto=format&fit=crop', 0),
  ('novakey-rgb', 'Teclado Mecánico NovaKey RGB', 'Periféricos', 'perifericos', NULL, NULL,
    52000.00, 15, 'https://images.unsplash.com/photo-1618384887929-16ec33fab9ef?w=500&q=75&auto=format&fit=crop', 0),
  ('novaclick-pro', 'Mouse Inalámbrico NovaClick Pro', 'Periféricos', 'perifericos', NULL, NULL,
    22300.00, 30, 'https://images.unsplash.com/photo-1615663245857-ac93bb7c39e7?w=500&q=75&auto=format&fit=crop', 0),
  ('novaboom-mini', 'Parlante Portátil NovaBoom Mini', 'Audio', 'audio', NULL, NULL,
    31200.00, 18, 'https://images.unsplash.com/photo-1608043152269-423dbba4e7e1?w=500&q=75&auto=format&fit=crop', 0),
  ('novacharge-20k', 'Power Bank NovaCharge 20000mAh', 'Carga', 'carga', NULL, NULL,
    27800.00, 8, 'https://images.unsplash.com/photo-1609091839311-d5365f9ff1c5?w=500&q=75&auto=format&fit=crop', 0),
  ('novabuds-air', 'Auriculares In-Ear NovaBuds Air', 'Audio', 'audio', NULL, NULL,
    28900.00, 35, 'https://images.unsplash.com/photo-1590658268037-6bf12165a8df?w=500&q=75&auto=format&fit=crop', 0),
  ('novahub-7', 'Hub USB-C NovaHub 7-en-1', 'Accesorios', 'accesorios', NULL, NULL,
    19900.00, 12, 'images/novahub-usbc.jpg', 0);
