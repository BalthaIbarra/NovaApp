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

// Opción 3 — Buscador y Filtro de Catálogo en Tiempo Real.
// Carga el catálogo con fetch(), filtra en memoria con .filter() e inyecta
// el resultado en el DOM. En páginas sin catálogo, el buscador redirige.
let catalogoCompleto = [];

async function inicializarBuscador() {
  const input = document.querySelector('.search-bar input');
  if (!input) return;

  const grilla = document.querySelector('.product-grid');
  const cuerpoTabla = document.querySelector('table.catalog tbody');
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
    mostrarErrorCatalogo(grilla, cuerpoTabla);
    return;
  }

  const parametros = new URLSearchParams(window.location.search);
  const terminoInicial = parametros.get('q') || '';
  input.value = terminoInicial;
  filtrarYRenderizar(terminoInicial, grilla, cuerpoTabla);

  input.addEventListener('input', () => {
    filtrarYRenderizar(input.value, grilla, cuerpoTabla);
  });
}

async function obtenerProductos() {
  const respuesta = await fetch('data/productos.json');
  if (!respuesta.ok) {
    throw new Error(`Respuesta HTTP ${respuesta.status}`);
  }
  return respuesta.json();
}

function mostrarErrorCatalogo(grilla, cuerpoTabla) {
  const mensaje = '<p class="form-feedback error">No se pudo cargar el catálogo. Intentá recargar la página.</p>';
  if (grilla) grilla.innerHTML = mensaje;
  if (cuerpoTabla) cuerpoTabla.innerHTML = `<tr><td colspan="4">${mensaje}</td></tr>`;
}

function filtrarYRenderizar(termino, grilla, cuerpoTabla) {
  const texto = termino.trim().toLowerCase();
  const filtrados = catalogoCompleto.filter((producto) =>
    producto.nombre.toLowerCase().includes(texto)
  );
  renderizarCatalogo(filtrados, grilla, cuerpoTabla);
}

function renderizarCatalogo(productos, grilla, cuerpoTabla) {
  if (grilla) grilla.innerHTML = productos.map(tarjetaHTML).join('');
  if (cuerpoTabla) cuerpoTabla.innerHTML = productos.map(filaHTML).join('');
}

function tarjetaHTML(producto) {
  const botonDetalle = producto.detalle
    ? '<a href="producto.html" class="btn btn-outline">Ver detalle</a>'
    : '';
  const botonAgregar = producto.detalle
    ? '<a href="comprar.html" class="btn btn-primary">Agregar</a>'
    : '<a href="comprar.html" class="btn btn-primary btn-block">Agregar al carrito</a>';

  return `
    <article class="product-card cat-${producto.categoriaSlug}">
      <img src="${producto.imagen}" alt="${producto.nombre}">
      <div class="body">
        <div class="category">${producto.categoria}</div>
        <h2>${producto.nombre}</h2>
        <div class="price small">${producto.precioTexto}</div>
        <div class="stock">Stock: ${producto.stock} unidades</div>
        <div class="card-actions">${botonDetalle}${botonAgregar}</div>
      </div>
    </article>
  `;
}

function filaHTML(producto) {
  const clasePill = producto.stockBajo ? 'stock-pill low' : 'stock-pill';
  return `
    <tr class="cat-row cat-${producto.categoriaSlug}">
      <td class="name">${producto.nombre}</td>
      <td>${producto.categoria}</td>
      <td class="price">${producto.precioTexto}</td>
      <td><span class="${clasePill}">${producto.stock} unidades</span></td>
    </tr>
  `;
}
