// ── CONFIGURACIÓN CENTRAL ──
const API = 'http://localhost:8080';

// ── UTILIDADES ──

/** Escapa HTML para prevenir XSS */
function esc(str) {
  if (str == null) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

/** Muestra un mensaje de estado */
function setStatus(el, msg, type = 'error') {
  if (!el) return;
  el.className = `alert alert-${type}`;
  el.innerHTML = `<span>${iconFor(type)}</span><span>${esc(msg)}</span>`;
  el.style.display = 'flex';
}

function iconFor(type) {
  return { ok: '✓', error: '✕', warn: '⚠', info: 'ℹ' }[type] || 'ℹ';
}

function clearStatus(el) {
  if (!el) return;
  el.style.display = 'none';
  el.textContent = '';
}

/** Deshabilita/habilita un botón con spinner */
function setLoading(btn, loading, label = '') {
  if (!btn) return;
  btn.disabled = loading;
  btn.innerHTML = loading
    ? `<span class="spinner"></span>${label ? ' ' + label : ''}`
    : (btn.dataset.label || btn.textContent);
  if (!btn.dataset.label) btn.dataset.label = btn.textContent;
}

/** Formatea estado general en español con clase de pill */
function estadoPill(estado) {
  const map = {
    pendiente_validacion_email: ['pendiente', 'Pendiente email'],
    pendiente_secretaria:       ['pendiente', 'Pend. Secretaría'],
    observado_secretaria:       ['observado', 'Observado'],
    rechazado_secretaria:       ['rechazado', 'Rechazado'],
    aprobado_secretaria:        ['aprobado', 'Aprobado Sec.'],
    cargado_plataforma:         ['cargado', 'En plataforma'],
    inactivo:                   ['rechazado', 'Inactivo'],
  };
  const [cls, label] = map[estado] || ['pendiente', estado];
  return `<span class="pill pill-${cls}">${esc(label)}</span>`;
}

/** Validar DNI argentino */
function validarDNI(dni) {
  return /^\d{7,8}$/.test(dni.replace(/\./g, '').trim());
}

/** Validar email */
function validarEmail(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

/** Validar contraseña mínima */
function validarPassword(pass) {
  return pass.length >= 6;
}

/** Modal de confirmación */
function confirmar({ titulo, mensaje, btnOk = 'Confirmar', btnCancel = 'Cancelar', tipo = 'primary' }) {
  return new Promise(resolve => {
    const overlay = document.getElementById('modalConfirm');
    const titleEl = document.getElementById('modalConfirmTitle');
    const msgEl   = document.getElementById('modalConfirmMsg');
    const okBtn   = document.getElementById('modalConfirmOk');
    const cancelBtn = document.getElementById('modalConfirmCancel');

    titleEl.textContent = titulo;
    msgEl.textContent   = mensaje;
    okBtn.textContent   = btnOk;
    okBtn.className     = `btn btn-${tipo}`;
    overlay.classList.remove('hidden');

    const close = (val) => {
      overlay.classList.add('hidden');
      okBtn.removeEventListener('click', onOk);
      cancelBtn.removeEventListener('click', onCancel);
      resolve(val);
    };
    const onOk     = () => close(true);
    const onCancel = () => close(false);
    okBtn.addEventListener('click', onOk);
    cancelBtn.addEventListener('click', onCancel);
  });
}

/** Modal con textarea para observaciones */
function pedirObservaciones({ titulo, placeholder = 'Observaciones (opcional)...' }) {
  return new Promise(resolve => {
    const overlay = document.getElementById('modalObs');
    const titleEl = document.getElementById('modalObsTitle');
    const textarea = document.getElementById('modalObsText');
    const okBtn   = document.getElementById('modalObsOk');
    const cancelBtn = document.getElementById('modalObsCancel');

    titleEl.textContent  = titulo;
    textarea.placeholder = placeholder;
    textarea.value       = '';
    overlay.classList.remove('hidden');

    const close = (val) => {
      overlay.classList.add('hidden');
      okBtn.removeEventListener('click', onOk);
      cancelBtn.removeEventListener('click', onCancel);
      resolve(val);
    };
    const onOk     = () => close(textarea.value.trim());
    const onCancel = () => close(null);
    okBtn.addEventListener('click', onOk);
    cancelBtn.addEventListener('click', onCancel);
  });
}
