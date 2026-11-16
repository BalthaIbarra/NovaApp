# Nova Tech — Tienda de accesorios tecnológicos

Trabajo integrador **AE4 / TIFAS** de *Paradigmas y Lenguajes de Programación III*
(Ingeniería en Sistemas de Información, Universidad de la Cuenca del Plata).

**Integrantes:** Balthazar Ibarra · Thaiana Fritz

Nova Tech es una tienda online ficticia de accesorios tecnológicos. El proyecto
evoluciona la maqueta estática de la **AE1** (HTML5 + CSS3) a una
**Single Page Application en JavaScript Vanilla** que consume una **API REST en
PHP** con persistencia en **MySQL**, siguiendo el patrón **MVC desacoplado**:
el cliente y el servidor solo se comunican por HTTP intercambiando JSON.

---

## Casos de uso cubiertos

| CU | Caso de uso | Vista (`/public`) | Endpoint (`/api`) | Tablas |
|----|-------------|-------------------|-------------------|--------|
| 1 | Ver catálogo (con filtro por categoría y búsqueda) | `listado_box.html`, `listado_tabla.html` | `GET productos.php` | `productos` |
| 2 | Ver detalle de un producto | `producto.html?slug=...` | `GET productos.php?slug=` | `productos` |
| 3 | Registrarse como cliente | `login.html#registro` | `POST registro.php` | `usuarios` |
| 4 | Iniciar / cerrar sesión | `login.html`, header | `POST login.php`, `POST logout.php`, `GET yo.php` | `usuarios` |
| 5 | Armar y confirmar un pedido | `comprar.html` | `POST pedidos.php`, `PUT pedidos.php?id=` | `pedidos`, `pedido_items`, `productos` |
| 6 | Gestionar productos (rol admin) | `admin.html` | `POST`, `PUT`, `DELETE productos.php` | `productos` |

---

## Arquitectura y carpetas

```
NovaApp/
├── .htaccess              → Solo expone /public y /api; la raíz redirige a /public
├── config/
│   └── Conexion.php       → Conexión PDO a MySQL (Singleton) con manejo de excepciones
├── models/                → MODELO: acceso a datos con PDO Prepared Statements
│   ├── UsuarioModel.php
│   ├── ProductoModel.php
│   └── PedidoModel.php    → Crea el pedido dentro de una transacción
├── api/                   → CONTROLADOR: endpoints REST que responden JSON
│   ├── _ayudante.php      → Sesión, respuestas JSON y control de acceso (no es un endpoint)
│   ├── login.php
│   ├── logout.php
│   ├── registro.php
│   ├── yo.php
│   ├── productos.php
│   └── pedidos.php
├── public/                → VISTA: la SPA (evolución de la maqueta de la AE1)
│   ├── index.html, listado_box.html, listado_tabla.html,
│   │   producto.html, comprar.html, login.html, admin.html
│   ├── css/estilos.css    → Layout responsive de la AE1 + :focus-visible
│   ├── js/app.js          → Cliente JS Vanilla (fetch, async/await, textContent)
│   └── images/
├── sql/
│   └── schema.sql         → Crea la base novatech_db con datos de prueba
└── README.md
```

```
 Navegador (public/)                Servidor (api/ → models/ → MySQL)
 ┌──────────────────┐   fetch()    ┌──────────────┐   ┌─────────────┐   ┌───────┐
 │ HTML + CSS       │ ───JSON────▶ │ api/*.php    │──▶│ models/*.php│──▶│ MySQL │
 │ js/app.js        │ ◀──JSON───── │ (controlador)│◀──│ (PDO)       │◀──│       │
 └──────────────────┘              └──────────────┘   └─────────────┘   └───────┘
```

El HTML no contiene PHP ni JavaScript embebido: toda la dinámica está en
`js/app.js`, conectada con `addEventListener`.

---

## Instalación en XAMPP

### Requisitos

- [XAMPP](https://www.apachefriends.org/) con **PHP 8** y **MySQL/MariaDB**
- Git (o descargar el ZIP del repositorio)

### Pasos

1. **Iniciar Apache y MySQL** desde el panel de control de XAMPP.

2. **Copiar el proyecto dentro de `htdocs`.** El nombre de la carpeta puede ser cualquiera:

   ```cmd
   cd /d C:\xampp\htdocs
   git clone https://github.com/BalthaIbarra/NovaApp.git
   ```

3. **Crear la base de datos.** Ejecutar `sql/schema.sql` una sola vez, de alguna de estas dos formas:

   - **phpMyAdmin:** entrar a `http://localhost/phpmyadmin`, ir a la pestaña
     **Importar**, elegir `sql/schema.sql` y apretar **Importar**.
   - **Consola:**

     ```cmd
     cd /d C:\xampp\htdocs\NovaApp
     C:\xampp\mysql\bin\mysql.exe -u root < sql\schema.sql
     ```

   El script crea la base `novatech_db`, las tablas y los datos de prueba.
   Si la base ya existía y se quiere empezar de cero, primero hay que borrarla
   (en phpMyAdmin, o con `DROP DATABASE novatech_db;`): si se ejecuta sobre
   una base que ya tiene los datos de prueba, la carga falla porque esos
   emails y productos ya existen.

4. **Abrir el sitio:** `http://localhost/NovaApp/`. Redirige solo a
   `http://localhost/NovaApp/public/`.

> La conexión usa el usuario `root` sin contraseña, que es la configuración por
> defecto de XAMPP. Si MySQL tiene otra contraseña, se cambia en
> `config/Conexion.php`.

### Usuarios de prueba

| Rol | Email | Contraseña |
|-----|-------|------------|
| Cliente | `demo@novatech.com` | `demo1234` |
| Administrador | `admin@novatech.com` | `admin1234` |

Con el usuario administrador aparece en el menú la opción **Administración**
(`admin.html`), desde donde se dan de alta, editan y eliminan productos.

---

## API REST

Todas las respuestas son JSON (`Content-Type: application/json; charset=utf-8`)
con la forma `{ "exito": true|false, ... }` y un código HTTP acorde.

| Método | Endpoint | Acceso | Descripción |
|--------|----------|--------|-------------|
| POST | `/api/registro.php` | Público | Crea una cuenta de cliente → 201, 400, 409 |
| POST | `/api/login.php` | Público | Inicia sesión → 200, 400, 401 |
| POST | `/api/logout.php` | Público | Cierra la sesión → 200 |
| GET | `/api/yo.php` | Con sesión | Usuario logueado → 200, 401 |
| GET | `/api/productos.php` | Público | Lista; filtros `?categoria=` y `?q=` → 200, 400 |
| GET | `/api/productos.php?id=` / `?slug=` / `?destacado=1` | Público | Detalle → 200, 404 |
| POST | `/api/productos.php` | Admin | Alta → 201, 400, 401, 403, 409 |
| PUT | `/api/productos.php?id=` | Admin | Edición → 200, 400, 401, 403, 404 |
| DELETE | `/api/productos.php?id=` | Admin | Baja → 200, 401, 403, 404, 409 (ya tiene pedidos) |
| GET | `/api/pedidos.php` | Con sesión | Mis pedidos → 200, 401 |
| GET | `/api/pedidos.php?id=` | Con sesión | Detalle / ticket de un pedido propio → 200, 404 |
| POST | `/api/pedidos.php` | Con sesión | Confirma un pedido (transacción) → 201, 400, 401, 409 (sin stock) |
| PUT | `/api/pedidos.php?id=` | Con sesión | Guarda la valoración 1 a 5 → 200, 400, 404 |

Ante un error de base de datos, cualquier endpoint responde **500** con un
mensaje genérico; el detalle solo se registra en el log de PHP.

---

## Seguridad (OWASP Top 10)

- **Contraseñas (A02):** se guardan con `password_hash()` (BCRYPT) en `VARCHAR(255)`
  y se verifican con `password_verify()`. Nunca viajan ni se comparan en el navegador.
- **Inyección SQL (A03):** todas las consultas usan PDO Prepared Statements con
  marcadores nombrados (`:campo`) y `ATTR_EMULATE_PREPARES = false`.
- **XSS (A03):** los datos dinámicos se insertan en el DOM con `textContent` /
  `createElement`, nunca con `innerHTML`.
- **Control de acceso y sesiones (A01 / A07):** `session_start()` + `$_SESSION`
  en el servidor, `session_regenerate_id(true)` tras el login, cookie `HttpOnly`
  y `SameSite=Lax`. Sin sesión → 401; sin rol admin → 403.
- **Configuración (A05):** `.htaccess` bloquea el acceso web a `config/`,
  `models/`, `sql/` y `.git/`.

## Accesibilidad (WCAG 2.1)

- Navegación completa con **Tab** y **Enter**, con foco visible mediante `:focus-visible`.
- Enlace "Saltar al contenido principal" como primer elemento enfocable.
- Regiones `aria-live="polite"` en los contenedores que se actualizan por `fetch()`.
- Contraste de color AA (mínimo 4.5:1) y etiquetas en todos los campos de formulario.
- Los modales del checkout pasan el foco a su título al abrirse y se cierran con **Escape**.
