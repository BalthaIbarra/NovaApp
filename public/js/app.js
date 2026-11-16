// Opción 4 — Módulo de Registro y Autenticación de Usuarios (Entrega 2).
// Desacoplado: sin onclick/onsubmit en el HTML, todo se conecta acá vía addEventListener.

const LONGITUD_MINIMA_CLAVE = 8;

document.addEventListener('DOMContentLoaded', () => {
  const formLogin = document.querySelector('#loginForm');
  if (formLogin) formLogin.addEventListener('submit', manejarEnvioLogin);

  const formRegistro = document.querySelector('#registroForm');
  if (formRegistro) formRegistro.addEventListener('submit', manejarEnvioRegistro);

  inicializarBuscador();
});

async function manejarEnvioLogin(evento) {
  evento.preventDefault();

  const email = document.querySelector('#login_email').value.trim();
  const clave = document.querySelector('#login_password').value;
  const feedback = document.querySelector('#login-feedback');

  mostrarFeedback(feedback, 'Verificando credenciales...', 'pendiente');

  try {
    const usuarios = await obtenerUsuarios();
    const usuarioValido = usuarios.find(
      (usuario) => usuario.email === email && usuario.password === clave
    );

    if (usuarioValido) {
      mostrarFeedback(feedback, `¡Bienvenida/o, ${usuarioValido.nombre}! Redirigiendo a la compra...`, 'exito');
      setTimeout(() => {
        window.location.href = 'comprar.html';
      }, 1200);
    } else {
      mostrarFeedback(feedback, 'Email o contraseña incorrectos.', 'error');
    }
  } catch (error) {
    mostrarFeedback(feedback, 'No se pudo verificar el usuario. Intentá nuevamente.', 'error');
  }
}

async function manejarEnvioRegistro(evento) {
  evento.preventDefault();

  const email = document.querySelector('#registro_email').value.trim();
  const clave = document.querySelector('#registro_password').value;
  const claveConfirmacion = document.querySelector('#registro_password_confirm').value;
  const feedback = document.querySelector('#registro-feedback');

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
    const usuarios = await obtenerUsuarios();
    const yaExiste = usuarios.some((usuario) => usuario.email === email);

    if (yaExiste) {
      mostrarFeedback(feedback, 'Ya existe una cuenta registrada con ese email.', 'error');
      return;
    }

    mostrarFeedback(feedback, 'Cuenta creada. Redirigiendo a iniciar sesión...', 'exito');
    setTimeout(() => {
      window.location.href = 'login.html';
    }, 1200);
  } catch (error) {
    mostrarFeedback(feedback, 'No se pudo crear la cuenta. Intentá nuevamente.', 'error');
  }
}

async function obtenerUsuarios() {
  const respuesta = await fetch('data/usuarios.json');
  if (!respuesta.ok) {
    throw new Error(`Respuesta HTTP ${respuesta.status}`);
  }
  return respuesta.json();
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

  // Solo el destacado tiene ficha propia en producto.html por ahora.
  const acciones = crearElemento('div', 'card-actions');
  if (producto.destacado) {
    acciones.append(
      crearEnlace('producto.html', 'btn btn-outline', 'Ver detalle'),
      crearEnlace('comprar.html', 'btn btn-primary', 'Agregar')
    );
  } else {
    acciones.append(crearEnlace('comprar.html', 'btn btn-primary btn-block', 'Agregar al carrito'));
  }

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

  fila.append(
    crearElemento('td', 'name', producto.nombre),
    crearElemento('td', '', producto.categoria),
    crearElemento('td', 'price', formatearPrecio(producto.precio)),
    celdaStock
  );
  return fila;
}
