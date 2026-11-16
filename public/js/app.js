// Login, registro y sesión reales (AE4). Reemplaza al login simulado de la
// Entrega 2, que bajaba data/usuarios.json y comparaba la contraseña en el
// navegador. Ahora la contraseña viaja solo al servidor (api/login.php), que
// la verifica con password_verify(), y la sesión vive en la cookie PHPSESSID.
// Desacoplado: sin onclick/onsubmit en el HTML, todo se conecta acá vía addEventListener.

const URL_API = '../api/';
const LONGITUD_MINIMA_CLAVE = 8;

document.addEventListener('DOMContentLoaded', () => {
  const formLogin = document.querySelector('#loginForm');
  if (formLogin) formLogin.addEventListener('submit', manejarEnvioLogin);

  const formRegistro = document.querySelector('#registroForm');
  if (formRegistro) formRegistro.addEventListener('submit', manejarEnvioRegistro);

  inicializarSesion();
  inicializarBuscador();
  inicializarCheckout();
  inicializarDetalle();
  inicializarAdmin();
});

// Llama a un endpoint y devuelve { ok, status, datos }. Si se pasa "cuerpo",
// va como JSON (POST por defecto). Los errores de red se tiran para que los
// maneje quien llama.
async function llamarApi(endpoint, cuerpo, metodo = 'POST') {
  const opciones = cuerpo === undefined
    ? {}
    : { method: metodo, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(cuerpo) };

  const respuesta = await fetch(URL_API + endpoint, opciones);
  const datos = await respuesta.json();
  return { ok: respuesta.ok, status: respuesta.status, datos };
}

// Usuario en sesión según el servidor (api/yo.php), o null. Se pide una sola
// vez por página y lo comparten el header y el checkout.
let promesaUsuario = null;

function obtenerUsuarioActual() {
  if (!promesaUsuario) {
    promesaUsuario = llamarApi('yo.php')
      .then(({ ok, datos }) => (ok ? datos.usuario : null))
      .catch(() => null);
  }
  return promesaUsuario;
}

// Header: si hay sesión, "Iniciar sesión" se cambia por el nombre del
// usuario y un botón para cerrarla.
async function inicializarSesion() {
  const enlaceLogin = document.querySelector('.header-actions a[href="login.html"]');
  if (!enlaceLogin) return;

  const usuario = await obtenerUsuarioActual();
  if (!usuario) return;

  const nombre = document.createElement('span');
  nombre.className = 'header-action';
  nombre.textContent = `👤 ${usuario.nombre}`;

  const botonSalir = document.createElement('button');
  botonSalir.type = 'button';
  botonSalir.className = 'header-action';
  botonSalir.textContent = 'Cerrar sesión';
  botonSalir.addEventListener('click', cerrarSesion);

  enlaceLogin.replaceWith(nombre, botonSalir);

  // El enlace al panel solo se agrega para el rol admin.
  const menu = document.querySelector('.navbar-inner');
  if (usuario.rol === 'admin' && menu) {
    const enlaceAdmin = crearEnlace('admin.html', '', 'Administración');
    if (document.querySelector('#productoForm')) enlaceAdmin.className = 'active';
    menu.append(enlaceAdmin);
  }
}

async function cerrarSesion() {
  try {
    await llamarApi('logout.php', {});
  } finally {
    window.location.href = 'index.html';
  }
}

async function manejarEnvioLogin(evento) {
  evento.preventDefault();

  const email = document.querySelector('#login_email').value.trim();
  const clave = document.querySelector('#login_password').value;
  const feedback = document.querySelector('#login-feedback');

  mostrarFeedback(feedback, 'Verificando credenciales...', 'pendiente');

  try {
    const { ok, datos } = await llamarApi('login.php', { email, password: clave });

    if (!ok) {
      mostrarFeedback(feedback, datos.mensaje, 'error');
      return;
    }

    mostrarFeedback(feedback, `¡Bienvenida/o, ${datos.usuario.nombre}! Redirigiendo a la compra...`, 'exito');
    setTimeout(() => {
      window.location.href = 'comprar.html';
    }, 1200);
  } catch (error) {
    mostrarFeedback(feedback, 'No se pudo conectar con el servidor. Intentá nuevamente.', 'error');
  }
}

async function manejarEnvioRegistro(evento) {
  evento.preventDefault();

  const nombre = document.querySelector('#registro_nombre').value.trim();
  const email = document.querySelector('#registro_email').value.trim();
  const clave = document.querySelector('#registro_password').value;
  const claveConfirmacion = document.querySelector('#registro_password_confirm').value;
  const feedback = document.querySelector('#registro-feedback');

  // Validación rápida para no esperar al servidor; api/registro.php la repite.
  if (clave.length < LONGITUD_MINIMA_CLAVE) {
    mostrarFeedback(feedback, `La contraseña debe tener al menos ${LONGITUD_MINIMA_CLAVE} caracteres.`, 'error');
    return;
  }

  if (clave !== claveConfirmacion) {
    mostrarFeedback(feedback, 'Las contraseñas no coinciden.', 'error');
    return;
  }

  mostrarFeedback(feedback, 'Creando cuenta...', 'pendiente');

  try {
    const { ok, datos } = await llamarApi('registro.php', {
      nombre,
      email,
      password: clave,
      password_confirm: claveConfirmacion,
    });

    if (!ok) {
      mostrarFeedback(feedback, datos.mensaje, 'error');
      return;
    }

    mostrarFeedback(feedback, 'Cuenta creada. Redirigiendo a iniciar sesión...', 'exito');
    setTimeout(() => {
      window.location.href = 'login.html';
    }, 1200);
  } catch (error) {
    mostrarFeedback(feedback, 'No se pudo conectar con el servidor. Intentá nuevamente.', 'error');
  }
}

function mostrarFeedback(elemento, mensaje, tipo) {
  elemento.textContent = mensaje;
  elemento.className = 'form-feedback ' + tipo;
}

// Catálogo real (AE4): los productos salen de MySQL a través de la API,
// ya no de data/productos.json. Se piden una sola vez y el buscador
// filtra en memoria mientras se escribe, sin volver a llamar al servidor.
// En páginas sin catálogo, el buscador redirige al listado.
const URL_API_PRODUCTOS = '../api/productos.php';
const UMBRAL_STOCK_BAJO = 12;

let catalogoCompleto = [];

async function inicializarBuscador() {
  const input = document.querySelector('.search-bar input');
  if (!input) return;

  const grilla = document.querySelector('.product-grid');
  const cuerpoTabla = document.querySelector('table.catalog tbody');
  const estado = document.querySelector('#catalogo-estado');
  const hayCatalogoEnEstaPagina = grilla || cuerpoTabla;

  if (!hayCatalogoEnEstaPagina) {
    input.addEventListener('keydown', (evento) => {
      if (evento.key !== 'Enter') return;
      evento.preventDefault();
      window.location.href = 'listado_box.html?q=' + encodeURIComponent(input.value.trim());
    });
    return;
  }

  try {
    catalogoCompleto = await obtenerProductos();
  } catch (error) {
    mostrarEstado(estado, 'No se pudo cargar el catálogo. Intentá recargar la página.', true);
    return;
  }

  const parametros = new URLSearchParams(window.location.search);
  const terminoInicial = parametros.get('q') || '';
  input.value = terminoInicial;
  filtrarYRenderizar(terminoInicial, grilla, cuerpoTabla, estado);

  input.addEventListener('input', () => {
    filtrarYRenderizar(input.value, grilla, cuerpoTabla, estado);
  });
}

async function obtenerProductos() {
  const respuesta = await fetch(URL_API_PRODUCTOS);
  const datos = await respuesta.json();

  if (!respuesta.ok || !datos.exito) {
    throw new Error(datos.mensaje || `Respuesta HTTP ${respuesta.status}`);
  }
  return datos.productos;
}

function filtrarYRenderizar(termino, grilla, cuerpoTabla, estado) {
  const texto = termino.trim().toLowerCase();
  const filtrados = catalogoCompleto.filter((producto) =>
    producto.nombre.toLowerCase().includes(texto)
  );

  if (grilla) grilla.replaceChildren(...filtrados.map(crearTarjeta));
  if (cuerpoTabla) cuerpoTabla.replaceChildren(...filtrados.map(crearFila));

  if (texto === '') {
    mostrarEstado(estado, `Catálogo cargado: ${filtrados.length} productos.`);
  } else if (filtrados.length === 0) {
    mostrarEstado(estado, `No encontramos productos para "${termino.trim()}".`);
  } else {
    mostrarEstado(estado, `${filtrados.length} resultado(s) para "${termino.trim()}".`);
  }
}

// La región #catalogo-estado tiene aria-live="polite": el lector de
// pantalla anuncia cada cambio sin tener que leer todas las tarjetas.
function mostrarEstado(estado, mensaje, esError = false) {
  if (!estado) return;
  estado.textContent = mensaje;
  estado.classList.toggle('error', esError);
}

function formatearPrecio(precio) {
  return '$ ' + precio.toLocaleString('es-AR', { maximumFractionDigits: 2 });
}

// Crea un elemento con clase y texto. Todo dato de la base entra por
// textContent (nunca innerHTML), así un nombre con "<script>" se muestra
// como texto y no se ejecuta (mitigación de XSS, OWASP A03).
function crearElemento(etiqueta, clase, texto) {
  const elemento = document.createElement(etiqueta);
  if (clase) elemento.className = clase;
  if (texto !== undefined) elemento.textContent = texto;
  return elemento;
}

function crearEnlace(href, clase, texto) {
  const enlace = crearElemento('a', clase, texto);
  enlace.href = href;
  return enlace;
}

function crearTarjeta(producto) {
  const tarjeta = crearElemento('article', `product-card cat-${producto.categoria_slug}`);

  const imagen = document.createElement('img');
  imagen.src = producto.imagen;
  imagen.alt = producto.nombre;
  imagen.loading = 'lazy';

  const slug = encodeURIComponent(producto.slug);
  const verDetalle = crearEnlace(`producto.html?slug=${slug}`, 'btn btn-outline', 'Ver detalle');
  const agregar = crearEnlace(`comprar.html?producto=${slug}`, 'btn btn-primary', 'Agregar');

  // Todas las tarjetas repiten "Ver detalle" / "Agregar": el aria-label le
  // dice al lector de pantalla de qué producto es cada enlace.
  verDetalle.setAttribute('aria-label', `Ver detalle de ${producto.nombre}`);
  agregar.setAttribute('aria-label', `Agregar ${producto.nombre} al pedido`);

  const acciones = crearElemento('div', 'card-actions');
  acciones.append(verDetalle, agregar);

  const cuerpo = crearElemento('div', 'body');
  cuerpo.append(
    crearElemento('div', 'category', producto.categoria),
    crearElemento('h2', '', producto.nombre),
    crearElemento('div', 'price small', formatearPrecio(producto.precio)),
    crearElemento('div', 'stock', `Stock: ${producto.stock} unidades`),
    acciones
  );

  tarjeta.append(imagen, cuerpo);
  return tarjeta;
}

function crearFila(producto) {
  const fila = crearElemento('tr', `cat-row cat-${producto.categoria_slug}`);
  const clasePill = producto.stock <= UMBRAL_STOCK_BAJO ? 'stock-pill low' : 'stock-pill';

  const celdaStock = document.createElement('td');
  celdaStock.append(crearElemento('span', clasePill, `${producto.stock} unidades`));

  const celdaNombre = crearElemento('td', 'name');
  celdaNombre.append(crearEnlace(`producto.html?slug=${encodeURIComponent(producto.slug)}`, '', producto.nombre));

  fila.append(
    celdaNombre,
    crearElemento('td', '', producto.categoria),
    crearElemento('td', 'price', formatearPrecio(producto.precio)),
    celdaStock
  );
  return fila;
}

// Checkout real (CU 5). Las cantidades se eligen sobre los productos que
// devuelve la API; el pedido lo crea api/pedidos.php, que vuelve a tomar los
// precios de la base y valida el stock (el total de acá es solo informativo).
// Los modales siguen abriéndose con :target como en la AE1; app.js solo
// completa su contenido antes de cambiar el hash.
let productosCheckout = [];
let pedidoConfirmadoId = null;

async function inicializarCheckout() {
  const form = document.querySelector('#checkoutForm');
  if (!form) return;

  const usuario = await obtenerUsuarioActual();
  if (!usuario) {
    window.location.href = 'login.html';
    return;
  }

  const campoNombre = document.querySelector('#nombre');
  const campoEmail = document.querySelector('#email');
  if (!campoNombre.value) campoNombre.value = usuario.nombre;
  if (!campoEmail.value) campoEmail.value = usuario.email;

  form.addEventListener('submit', (evento) => {
    evento.preventDefault();
    abrirConfirmacion();
  });
  document.querySelector('#lineas-pedido').addEventListener('input', actualizarResumen);
  document.querySelector('#btn-finalizar').addEventListener('click', abrirConfirmacion);
  document.querySelector('#btn-confirmar').addEventListener('click', confirmarPedido);
  document.querySelector('#btn-imprimir').addEventListener('click', () => window.print());
  document.querySelectorAll('input[name="valoracion"]').forEach((radio) => {
    radio.addEventListener('change', guardarValoracion);
  });

  // Si se recarga con #confirmar-pedido en la URL, el modal se abriría vacío.
  if (window.location.hash) {
    history.replaceState(null, '', window.location.pathname + window.location.search);
  }

  // Escape cierra el modal abierto, igual que "Cancelar" / "Cerrar".
  const hayModalAbierto = () => ['#confirmar-pedido', '#pedido-confirmado'].includes(window.location.hash);
  document.addEventListener('keydown', (evento) => {
    if (evento.key === 'Escape' && hayModalAbierto()) window.location.hash = '';
  });

  // Al cerrarse un modal, el foco quedaría en un elemento oculto: vuelve a "Finalizar pedido".
  window.addEventListener('hashchange', () => {
    const focoPerdido = document.activeElement === document.body || document.activeElement.closest('.modal-overlay');
    if (!hayModalAbierto() && focoPerdido) document.querySelector('#btn-finalizar').focus();
  });

  const slugInicial = new URLSearchParams(window.location.search).get('producto');
  await cargarProductosCheckout(slugInicial);
}

async function cargarProductosCheckout(slugInicial) {
  const estado = document.querySelector('#checkout-productos-estado');

  try {
    productosCheckout = await obtenerProductos();
  } catch (error) {
    mostrarEstado(estado, 'No se pudieron cargar los productos. Intentá recargar la página.', true);
    return;
  }

  const lineas = productosCheckout.map((producto) => crearLineaPedido(producto, producto.slug === slugInicial));
  document.querySelector('#lineas-pedido').replaceChildren(...lineas);
  mostrarEstado(estado, '');
  actualizarResumen();
}

function crearLineaPedido(producto, preseleccionado) {
  const sinStock = producto.stock === 0;

  const cantidad = document.createElement('input');
  cantidad.type = 'number';
  cantidad.min = '0';
  cantidad.max = String(producto.stock);
  cantidad.step = '1';
  cantidad.value = preseleccionado && !sinStock ? '1' : '0';
  cantidad.disabled = sinStock;
  cantidad.dataset.productoId = String(producto.id);
  cantidad.setAttribute('aria-label', `Cantidad de ${producto.nombre}`);

  const linea = crearElemento('div', 'order-line');
  linea.append(
    crearElemento('span', 'item-name', sinStock ? `${producto.nombre} (sin stock)` : producto.nombre),
    cantidad,
    crearElemento('span', 'item-price mono', formatearPrecio(producto.precio))
  );
  return linea;
}

// [{ producto, cantidad }] con cantidad > 0, según lo cargado en el formulario.
function itemsSeleccionados() {
  return [...document.querySelectorAll('#lineas-pedido input')]
    .map((input) => ({
      producto: productosCheckout.find((p) => p.id === Number(input.dataset.productoId)),
      cantidad: Math.max(0, Math.floor(Number(input.value) || 0)),
    }))
    .filter((item) => item.producto && item.cantidad > 0);
}

function calcularTotal(items) {
  return items.reduce((suma, item) => suma + item.producto.precio * item.cantidad, 0);
}

function actualizarResumen() {
  const items = itemsSeleccionados();
  const unidades = items.reduce((suma, item) => suma + item.cantidad, 0);

  document.querySelector('#resumen-productos').textContent = String(items.length);
  document.querySelector('#resumen-unidades').textContent = String(unidades);
  document.querySelector('#resumen-total').textContent = formatearPrecio(calcularTotal(items));
}

// Completa una tabla .modal-summary: una fila por producto, envío y total.
function llenarTablaResumen(cuerpoTabla, filas, total, etiquetaTotal) {
  const crearFilaTabla = (nombre, cantidad, importe) => {
    const fila = document.createElement('tr');
    fila.append(
      crearElemento('td', '', nombre),
      crearElemento('td', 'qty', cantidad),
      crearElemento('td', 'amount', importe)
    );
    return fila;
  };

  const filaTotal = crearElemento('tr', 'total');
  const celdaEtiqueta = crearElemento('td', '', etiquetaTotal);
  celdaEtiqueta.colSpan = 2;
  filaTotal.append(celdaEtiqueta, crearElemento('td', 'amount', formatearPrecio(total)));

  cuerpoTabla.replaceChildren(
    ...filas.map((f) => crearFilaTabla(f.nombre, `x${f.cantidad}`, formatearPrecio(f.precio * f.cantidad))),
    crearFilaTabla('Envío estándar', '—', formatearPrecio(0)),
    filaTotal
  );
}

// Abre un modal (:target) y lleva el foco a su título, para que con
// teclado y lector de pantalla se siga desde ahí.
function abrirModal(id, selectorTitulo) {
  window.location.hash = id;
  document.querySelector(selectorTitulo).focus();
}

function abrirConfirmacion() {
  const form = document.querySelector('#checkoutForm');
  const feedback = document.querySelector('#checkout-feedback');
  const items = itemsSeleccionados();

  // El stock va antes que checkValidity(): una cantidad mayor al "max" del
  // input también lo hace fallar, y el mensaje correcto es el de stock.
  if (items.length === 0) {
    mostrarFeedback(feedback, 'Elegí al menos un producto.', 'error');
    return;
  }

  const sinStock = items.find((item) => item.cantidad > item.producto.stock);
  if (sinStock) {
    mostrarFeedback(feedback, `Solo hay ${sinStock.producto.stock} unidades de "${sinStock.producto.nombre}".`, 'error');
    return;
  }

  if (!form.checkValidity()) {
    form.reportValidity();
    mostrarFeedback(feedback, 'Completá todos los datos del cliente.', 'error');
    return;
  }

  mostrarFeedback(feedback, '', '');
  mostrarFeedback(document.querySelector('#confirmar-feedback'), '', '');
  document.querySelector('#btn-confirmar').disabled = false;

  llenarTablaResumen(
    document.querySelector('#confirmar-items'),
    items.map((item) => ({ nombre: item.producto.nombre, cantidad: item.cantidad, precio: item.producto.precio })),
    calcularTotal(items),
    'Total a pagar'
  );
  abrirModal('confirmar-pedido', '#titulo-confirmar');
}

async function confirmarPedido() {
  const boton = document.querySelector('#btn-confirmar');
  const feedback = document.querySelector('#confirmar-feedback');

  const cuerpo = {
    nombre_cliente: document.querySelector('#nombre').value.trim(),
    telefono: document.querySelector('#telefono').value.trim(),
    direccion: document.querySelector('#direccion').value.trim(),
    email: document.querySelector('#email').value.trim(),
    medio_pago: document.querySelector('#medio_pago').value,
    items: itemsSeleccionados().map((item) => ({ producto_id: item.producto.id, cantidad: item.cantidad })),
  };

  boton.disabled = true; // evita pedidos duplicados por doble clic
  mostrarFeedback(feedback, 'Confirmando pedido...', 'pendiente');

  try {
    const { ok, status, datos } = await llamarApi('pedidos.php', cuerpo);

    if (status === 401) {
      window.location.href = 'login.html';
      return;
    }
    if (!ok) {
      mostrarFeedback(feedback, datos.mensaje, 'error');
      boton.disabled = false;
      return;
    }

    mostrarTicket(datos.pedido);
  } catch (error) {
    mostrarFeedback(feedback, 'No se pudo conectar con el servidor. Intentá nuevamente.', 'error');
    boton.disabled = false;
  }
}

function mostrarTicket(pedido) {
  pedidoConfirmadoId = pedido.id;
  const numero = String(pedido.id).padStart(7, '0');
  const fecha = new Date(pedido.fecha.replace(' ', 'T')).toLocaleDateString('es-AR');
  const opcionPago = document.querySelector(`#medio_pago option[value="${pedido.medio_pago}"]`);

  document.querySelector('#ticket-numero').textContent = `Comprobante N.° ${numero}`;
  document.querySelector('#ticket-fecha').textContent = `Fecha: ${fecha}`;
  document.querySelector('#ticket-cliente').textContent = `Cliente: ${pedido.nombre_cliente}`;
  document.querySelector('#ticket-pago').textContent = `Medio de pago: ${opcionPago ? opcionPago.textContent : pedido.medio_pago}`;

  llenarTablaResumen(
    document.querySelector('#ticket-items'),
    pedido.items.map((item) => ({ nombre: item.nombre, cantidad: item.cantidad, precio: item.precio_unitario })),
    pedido.total,
    'Total pagado'
  );

  const mensaje = `Hola Nova Tech, quiero consultar sobre mi pedido N.° ${numero}.`;
  document.querySelector('#enlace-whatsapp').href = 'https://wa.me/5493764284149?text=' + encodeURIComponent(mensaje);

  document.querySelectorAll('input[name="valoracion"]').forEach((radio) => { radio.checked = false; });
  mostrarFeedback(document.querySelector('#valoracion-feedback'), '', '');

  abrirModal('pedido-confirmado', '#titulo-ticket');

  // El pedido ya se guardó: se vuelven a pedir los productos (stock actualizado)
  // y las cantidades quedan en 0 para un pedido nuevo.
  cargarProductosCheckout(null);
}

async function guardarValoracion(evento) {
  if (!pedidoConfirmadoId) return;
  const feedback = document.querySelector('#valoracion-feedback');

  try {
    const { ok, datos } = await llamarApi(
      `pedidos.php?id=${pedidoConfirmadoId}`,
      { valoracion: Number(evento.target.value) },
      'PUT'
    );
    mostrarFeedback(feedback, datos.mensaje, ok ? 'exito' : 'error');
  } catch (error) {
    mostrarFeedback(feedback, 'No se pudo guardar la valoración.', 'error');
  }
}

// Ficha de producto (CU 2): producto.html?slug=<slug>. Sin slug muestra el
// destacado, así siguen funcionando los enlaces "Producto destacado" de la AE1.
async function inicializarDetalle() {
  const ficha = document.querySelector('#detalle-producto');
  if (!ficha) return;

  const estado = document.querySelector('#detalle-estado');
  const slug = new URLSearchParams(window.location.search).get('slug');
  const consulta = slug ? `?slug=${encodeURIComponent(slug)}` : '?destacado=1';

  let producto;
  try {
    const { ok, datos } = await llamarApi('productos.php' + consulta);
    if (!ok) {
      mostrarEstado(estado, datos.mensaje, true);
      return;
    }
    producto = datos.producto;
  } catch (error) {
    mostrarEstado(estado, 'No se pudo cargar el producto. Intentá recargar la página.', true);
    return;
  }

  document.title = `Nova Tech — ${producto.nombre}`;

  const imagen = document.querySelector('#detalle-imagen');
  imagen.src = producto.imagen;
  imagen.alt = `${producto.nombre} - imagen grande`;

  document.querySelector('#detalle-badge').textContent = producto.destacado
    ? `Más vendido · ${producto.categoria}`
    : producto.categoria;
  document.querySelector('#detalle-nombre').textContent = producto.nombre;
  document.querySelector('#detalle-descripcion').textContent = producto.descripcion || '';
  document.querySelector('#detalle-precio').textContent = formatearPrecio(producto.precio);
  document.querySelector('#detalle-comprar').href = `comprar.html?producto=${encodeURIComponent(producto.slug)}`;

  // Categoría primero, después las especificaciones cargadas (si tiene) y el stock.
  const especificaciones = Object.entries(producto.especificaciones || {})
    .filter(([clave]) => clave !== 'Categoría');
  const filas = [['Categoría', producto.categoria], ...especificaciones, ['Stock disponible', `${producto.stock} unidades`]];

  document.querySelector('#detalle-specs').replaceChildren(...filas.map(([clave, valor]) => {
    const fila = document.createElement('tr');
    fila.append(crearElemento('td', 'spec-key', clave), crearElemento('td', 'spec-val', valor));
    return fila;
  }));

  mostrarEstado(estado, '');
  ficha.hidden = false;
}

// Panel de administración (CU 6: gestionar productos). Alta (POST), edición
// (PUT) y baja (DELETE) contra api/productos.php. Mostrarlo solo a admins es
// una ayuda visual: el servidor rechaza con 401/403 a cualquier otro.
let productoEditandoId = null;

async function inicializarAdmin() {
  const form = document.querySelector('#productoForm');
  if (!form) return;

  const estado = document.querySelector('#admin-estado');
  const usuario = await obtenerUsuarioActual();

  if (!usuario) {
    window.location.href = 'login.html';
    return;
  }
  if (usuario.rol !== 'admin') {
    mostrarEstado(estado, 'Esta sección es solo para administradores.', true);
    return;
  }

  mostrarEstado(estado, '');
  document.querySelector('#admin-panel').hidden = false;

  form.addEventListener('submit', guardarProducto);
  document.querySelector('#btn-cancelar-edicion').addEventListener('click', limpiarFormularioProducto);

  await cargarListaAdmin();
}

async function cargarListaAdmin() {
  const feedback = document.querySelector('#admin-lista-feedback');

  try {
    const productos = await obtenerProductos();
    document.querySelector('#admin-tbody').replaceChildren(...productos.map(crearFilaAdmin));
  } catch (error) {
    mostrarFeedback(feedback, 'No se pudo cargar la lista de productos.', 'error');
  }
}

function crearFilaAdmin(producto) {
  const botonEditar = crearElemento('button', 'btn btn-outline btn-chico', 'Editar');
  botonEditar.type = 'button';
  botonEditar.setAttribute('aria-label', `Editar ${producto.nombre}`);
  botonEditar.addEventListener('click', () => editarProducto(producto.id));

  const botonEliminar = crearElemento('button', 'btn btn-outline btn-chico', 'Eliminar');
  botonEliminar.type = 'button';
  botonEliminar.setAttribute('aria-label', `Eliminar ${producto.nombre}`);
  botonEliminar.addEventListener('click', () => eliminarProducto(producto));

  const acciones = crearElemento('div', 'acciones-fila');
  acciones.append(botonEditar, botonEliminar);
  const celdaAcciones = document.createElement('td');
  celdaAcciones.append(acciones);

  const fila = document.createElement('tr');
  fila.append(
    crearElemento('td', 'name', producto.nombre),
    crearElemento('td', '', producto.categoria),
    crearElemento('td', 'price', formatearPrecio(producto.precio)),
    crearElemento('td', '', String(producto.stock)),
    crearElemento('td', '', producto.destacado ? 'Sí' : 'No'),
    celdaAcciones
  );
  return fila;
}

async function editarProducto(id) {
  const feedback = document.querySelector('#admin-form-feedback');

  // La lista no trae descripción ni especificaciones: se pide la ficha completa.
  try {
    const { ok, datos } = await llamarApi(`productos.php?id=${id}`);
    if (!ok) {
      mostrarFeedback(feedback, datos.mensaje, 'error');
      return;
    }

    const p = datos.producto;
    productoEditandoId = p.id;

    document.querySelector('#prod_nombre').value = p.nombre;
    document.querySelector('#prod_categoria').value = p.categoria_slug;
    document.querySelector('#prod_slug').value = p.slug;
    document.querySelector('#prod_slug').disabled = true;
    document.querySelector('#prod_precio').value = p.precio;
    document.querySelector('#prod_stock').value = p.stock;
    document.querySelector('#prod_imagen').value = p.imagen;
    document.querySelector('#prod_descripcion').value = p.descripcion || '';
    document.querySelector('#prod_especificaciones').value = Object.entries(p.especificaciones || {})
      .map(([clave, valor]) => `${clave}: ${valor}`)
      .join('\n');
    document.querySelector('#prod_destacado').checked = p.destacado;

    document.querySelector('#titulo-form').textContent = `Editar: ${p.nombre}`;
    document.querySelector('#btn-guardar').textContent = 'Guardar cambios';
    document.querySelector('#btn-cancelar-edicion').hidden = false;
    mostrarFeedback(feedback, '', '');

    // El formulario está arriba de la tabla: se lleva el foco hasta ahí.
    document.querySelector('#titulo-form').focus();
  } catch (error) {
    mostrarFeedback(feedback, 'No se pudo cargar el producto.', 'error');
  }
}

function limpiarFormularioProducto() {
  productoEditandoId = null;
  document.querySelector('#productoForm').reset();
  document.querySelector('#prod_slug').disabled = false;
  document.querySelector('#titulo-form').textContent = 'Nuevo producto';
  document.querySelector('#btn-guardar').textContent = 'Crear producto';
  document.querySelector('#btn-cancelar-edicion').hidden = true;
}

// "Batería: 40 horas\nPeso: 254 g" -> { "Batería": "40 horas", "Peso": "254 g" }
function leerEspecificaciones(texto) {
  const especificaciones = {};

  for (const linea of texto.split('\n').map((l) => l.trim()).filter(Boolean)) {
    const separador = linea.indexOf(':');
    if (separador < 1) {
      throw new Error(`La línea "${linea}" no tiene el formato Clave: Valor.`);
    }
    especificaciones[linea.slice(0, separador).trim()] = linea.slice(separador + 1).trim();
  }
  return especificaciones;
}

async function guardarProducto(evento) {
  evento.preventDefault();
  const feedback = document.querySelector('#admin-form-feedback');

  let especificaciones;
  try {
    especificaciones = leerEspecificaciones(document.querySelector('#prod_especificaciones').value);
  } catch (error) {
    mostrarFeedback(feedback, error.message, 'error');
    return;
  }

  const cuerpo = {
    nombre: document.querySelector('#prod_nombre').value.trim(),
    categoria_slug: document.querySelector('#prod_categoria').value,
    precio: Number(document.querySelector('#prod_precio').value),
    stock: Number(document.querySelector('#prod_stock').value),
    imagen: document.querySelector('#prod_imagen').value.trim(),
    descripcion: document.querySelector('#prod_descripcion').value.trim(),
    especificaciones,
    destacado: document.querySelector('#prod_destacado').checked,
  };

  const editando = productoEditandoId !== null;
  if (!editando) cuerpo.slug = document.querySelector('#prod_slug').value.trim();

  mostrarFeedback(feedback, 'Guardando...', 'pendiente');

  try {
    const { ok, datos } = editando
      ? await llamarApi(`productos.php?id=${productoEditandoId}`, cuerpo, 'PUT')
      : await llamarApi('productos.php', cuerpo);

    if (!ok) {
      mostrarFeedback(feedback, datos.mensaje, 'error');
      return;
    }

    limpiarFormularioProducto();
    mostrarFeedback(feedback, datos.mensaje, 'exito');
    await cargarListaAdmin();
  } catch (error) {
    mostrarFeedback(feedback, 'No se pudo conectar con el servidor. Intentá nuevamente.', 'error');
  }
}

async function eliminarProducto(producto) {
  const feedback = document.querySelector('#admin-lista-feedback');

  if (!window.confirm(`¿Eliminar "${producto.nombre}"? Esta acción no se puede deshacer.`)) return;

  try {
    const { ok, datos } = await llamarApi(`productos.php?id=${producto.id}`, {}, 'DELETE');
    mostrarFeedback(feedback, datos.mensaje, ok ? 'exito' : 'error');

    if (ok) {
      if (productoEditandoId === producto.id) limpiarFormularioProducto();
      await cargarListaAdmin();
    }
  } catch (error) {
    mostrarFeedback(feedback, 'No se pudo conectar con el servidor. Intentá nuevamente.', 'error');
  }
}
