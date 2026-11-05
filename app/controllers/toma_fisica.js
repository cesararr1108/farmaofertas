/* Toma física sin lote */
const URL_TF = '../models/toma_fisica.php';

let productoActual = null;  // producto seleccionado para contar
let resultadosBusqueda = [];
let ultimosConteos = [];
let timerBusqueda = null;
let previewOk = false;

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function fmt(n) {
  if (n === null || n === undefined || n === '') return '';
  return Number(n).toLocaleString('es-CO', { maximumFractionDigits: 4 });
}

function fmtDif(n) {
  if (n === null || n === undefined) return '';
  const cls = n > 0 ? 'dif-pos' : (n < 0 ? 'dif-neg' : '');
  return `<span class="${cls}">${n > 0 ? '+' : ''}${fmt(n)}</span>`;
}

function api(op, datos = {}) {
  return $.ajax({
    url: URL_TF,
    type: 'POST',
    dataType: 'json',
    data: Object.assign({ op, bodega: $('#bodega').val(), toma: $('#toma').val() }, datos)
  }).then(r => {
    if (!r || r.ok === false) return $.Deferred().reject(r && r.mensaje ? r.mensaje : 'Error inesperado', r).promise();
    return r;
  }, (xhr) => $.Deferred().reject('Error de comunicación con el servidor: ' + (xhr.responseText || xhr.statusText)).promise());
}

function alerta(icon, title, text) {
  return Swal.fire({ icon, title, html: text || '', customClass: { container: 'custom-swal-container' } });
}

function toast(icon, title) {
  Swal.fire({ toast: true, position: 'top-end', icon, title, showConfirmButton: false, timer: 1500 });
}

/* ------------------------------ Carga inicial ------------------------------ */

$(function () {
  api('bodegas').then(r => {
    $('#bodega').html(r.data.map(b => `<option value="${esc(b.bodega)}">${esc(b.bodega)} - ${esc(b.descripcion)}</option>`).join(''));
    if (r.bodega_default) $('#bodega').val(String(r.bodega_default));
    cargarHistorial();
  }).fail(m => alerta('error', 'Bodegas', esc(m)));

  api('tipos_doc').then(r => {
    const ent = r.data.filter(t => +t.sw === 12), sal = r.data.filter(t => +t.sw === 11);
    const opt = t => `<option value="${esc(t.tipo)}">${esc(t.tipo)} - ${esc(t.descripcion)}</option>`;
    $('#tipo_entrada').html('<option value="">Seleccione...</option>' + ent.map(opt).join(''));
    $('#tipo_salida').html('<option value="">Seleccione...</option>' + sal.map(opt).join(''));
  }).fail(m => alerta('error', 'Tipos de documento', esc(m)));

  $('#bodega, #toma').on('change', () => {
    limpiarSeleccion();
    $('#resultados, #listado, #preview, #resumen-ajuste, #resumen-listado').empty();
    invalidarPreview();
    cargarHistorial();
  });

  /* Búsqueda: Enter busca de inmediato (lector de código de barras), al escribir espera 400 ms */
  $('#busqueda').on('keydown', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      clearTimeout(timerBusqueda);
      buscar(true);
    } else if (e.key === 'ArrowDown' && resultadosBusqueda.length) {
      e.preventDefault();
      $('#resultados tbody tr').first().trigger('focus');
    }
  }).on('input', function () {
    clearTimeout(timerBusqueda);
    if ($(this).val().trim().length >= 3) timerBusqueda = setTimeout(() => buscar(false), 400);
  });

  $('#resultados').on('click', 'tbody tr', function () {
    seleccionar(resultadosBusqueda[$(this).data('idx')]);
  }).on('keydown', 'tbody tr', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); $(this).trigger('click'); }
    if (e.key === 'ArrowDown') { e.preventDefault(); $(this).next().trigger('focus'); }
    if (e.key === 'ArrowUp') { e.preventDefault(); const p = $(this).prev(); p.length ? p.trigger('focus') : $('#busqueda').trigger('focus'); }
  });

  $('#modo_sumar').on('change', actualizarLabelCantidad);

  $('#cantidad').on('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); guardarConteo(); }
    if (e.key === 'Escape') { limpiarSeleccion(); $('#busqueda').trigger('focus'); }
  });
  $('#btn-guardar-conteo').on('click', guardarConteo);
  $('#btn-limpiar-conteo').on('click', limpiarConteo);
  $('#btn-ver-lotes').on('click', () => productoActual && verLotes(productoActual.codigo));

  $('#btn-listar, #btn-tab-listado').on('click', listar);
  $('#estado').on('change', listar);
  $('#filtro_listado').on('input', filtrarListado);
  $('#listado').on('click', '.btn-lotes', function () { verLotes($(this).data('codigo')); });
  $('#listado').on('click', '.btn-contar', function () {
    const cod = String($(this).data('codigo'));
    bootstrap.Tab.getOrCreateInstance(document.querySelector('[data-bs-target="#tab-conteo"]')).show();
    $('#busqueda').val(cod);
    buscar(true);
  });

  $('#btn-tab-ajuste').on('click', cargarHistorial);
  $('#btn-previsualizar').on('click', previsualizar);
  $('#btn-ajustar').on('click', ajustar);
  $('#tipo_entrada, #tipo_salida, #incluir_no_contados').on('change', invalidarPreview);
});

/* --------------------------------- Conteo --------------------------------- */

function buscar(desdeEnter) {
  const filtro = $('#busqueda').val().trim();
  if (!filtro) return;
  api('buscar', { filtro }).then(r => {
    resultadosBusqueda = r.data;
    if (!r.data.length) {
      $('#resultados').html(`<div class="alert alert-warning p-2">${esc(r.mensaje || 'No se encontraron referencias marcadas para toma física')}</div>`);
      if (desdeEnter) $('#busqueda').trigger('select');
      return;
    }
    // código o código de barras exacto: seleccionar directo
    const exactos = r.data.filter(p => p.exacto);
    if (desdeEnter && (exactos.length === 1 || r.data.length === 1)) {
      $('#resultados').empty();
      seleccionar(exactos[0] || r.data[0]);
      return;
    }
    pintarResultados();
  }).fail(m => alerta('error', 'Búsqueda', esc(m)));
}

function pintarResultados() {
  let h = `<table class="table table-sm table-bordered table-hover mb-0">
    <thead class="table-light"><tr><th>Código</th><th>Descripción</th><th class="text-end">Sistema</th><th class="text-end">Contado</th><th>Usuario</th></tr></thead><tbody>`;
  resultadosBusqueda.forEach((p, i) => {
    h += `<tr tabindex="0" data-idx="${i}">
      <td>${esc(p.codigo)}</td><td>${esc(p.descripcion)}</td>
      <td class="text-end">${fmt(p.stock)}</td><td class="text-end">${p.conteo === null ? '<span class="text-muted">-</span>' : fmt(p.conteo)}</td>
      <td>${esc(p.usuario)}</td></tr>`;
  });
  $('#resultados').html(h + '</tbody></table>');
}

function seleccionar(p) {
  if (!p) return;
  productoActual = p;
  $('#p_codigo').text(p.codigo);
  $('#p_descripcion').text(p.descripcion);
  $('#p_stock').text(fmt(p.stock));
  $('#p_conteo').html(p.conteo === null ? '<span class="text-muted">sin contar</span>' : fmt(p.conteo));
  $('#p_lotes').html(p.n_lotes > 0 ? p.n_lotes : '<span class="text-danger fw-bold">sin lotes en la toma (no se podrá ajustar)</span>');
  $('#resultados tbody tr').removeClass('activo').filter(`[data-idx="${resultadosBusqueda.indexOf(p)}"]`).addClass('activo');
  actualizarLabelCantidad();
  $('#panel-producto').show();
  $('#cantidad').val('').trigger('focus');
}

function actualizarLabelCantidad() {
  $('#lbl_cantidad').text($('#modo_sumar').is(':checked') ? 'Cantidad a sumar' : 'Total contado (reemplaza)');
}

function limpiarSeleccion() {
  productoActual = null;
  $('#panel-producto').hide();
  $('#cantidad').val('');
}

function guardarConteo() {
  if (!productoActual) return;
  const v = $('#cantidad').val();
  if (v === '' || isNaN(Number(v))) { toast('warning', 'Digite la cantidad'); $('#cantidad').trigger('focus'); return; }
  const modo = $('#modo_sumar').is(':checked') ? 'sumar' : 'reemplazar';
  const p = productoActual;
  $('#btn-guardar-conteo').prop('disabled', true);
  api('guardar_conteo', { codigo: p.codigo, cantidad: v, modo }).then(r => {
    p.conteo = r.data.conteo;
    ultimosConteos.unshift({ hora: new Date().toLocaleTimeString(), codigo: p.codigo, descripcion: p.descripcion,
      cantidad: Number(v), modo, total: r.data.conteo, stock: p.stock });
    pintarUltimos();
    toast('success', `${p.codigo}: ${fmt(r.data.conteo)}`);
    if (resultadosBusqueda.includes(p)) pintarResultados();
    limpiarSeleccion();
    invalidarPreview();
    $('#busqueda').val('').trigger('focus');
  }).fail(m => alerta('error', 'Conteo', esc(m)))
    .always(() => $('#btn-guardar-conteo').prop('disabled', false));
}

function limpiarConteo() {
  if (!productoActual) return;
  const p = productoActual;
  Swal.fire({ icon: 'question', title: '¿Borrar el conteo?', text: `${p.codigo} - ${p.descripcion}`, showCancelButton: true,
    confirmButtonText: 'Sí, borrar', cancelButtonText: 'Cancelar', customClass: { container: 'custom-swal-container' } })
    .then(res => {
      if (!res.isConfirmed) return;
      api('limpiar_conteo', { codigo: p.codigo }).then(() => {
        p.conteo = null;
        seleccionar(p);
        if (resultadosBusqueda.includes(p)) pintarResultados();
        invalidarPreview();
        toast('success', 'Conteo eliminado');
      }).fail(m => alerta('error', 'Conteo', esc(m)));
    });
}

function pintarUltimos() {
  let h = `<table class="table table-sm table-bordered mb-0"><thead class="table-light"><tr>
    <th>Hora</th><th>Código</th><th>Descripción</th><th class="text-end">Digitado</th><th class="text-end">Total contado</th><th class="text-end">Sistema</th><th class="text-end">Dif.</th></tr></thead><tbody>`;
  ultimosConteos.slice(0, 100).forEach(u => {
    h += `<tr><td>${esc(u.hora)}</td><td>${esc(u.codigo)}</td><td>${esc(u.descripcion)}</td>
      <td class="text-end">${u.modo === 'sumar' ? '+' : '='}${fmt(u.cantidad)}</td><td class="text-end">${fmt(u.total)}</td>
      <td class="text-end">${fmt(u.stock)}</td><td class="text-end">${fmtDif(Math.round((u.total - u.stock) * 10000) / 10000)}</td></tr>`;
  });
  $('#ultimos').html(h + '</tbody></table>');
}

/* --------------------------------- Lotes ---------------------------------- */

function verLotes(codigo) {
  api('lotes', { codigo }).then(r => {
    const p = r.data;
    $('#modalLotesTitulo').text(`${p.codigo} - ${p.descripcion}`);
    let h = `<p class="mb-1">Sistema: <b>${fmt(p.stock)}</b> &nbsp; Contado: <b>${p.contado_null ? 'sin contar' : fmt(p.contado)}</b> &nbsp; Diferencia: ${p.contado_null ? '' : fmtDif(p.diferencia)}</p>
      <p class="small text-muted">Simulación del ajuste con el conteo actual (lotes ordenados por vencimiento).</p>
      <table class="table table-sm table-bordered"><thead class="table-light"><tr>
      <th>Lote</th><th>Vence</th><th class="text-end">Stock sistema</th><th class="text-end">Queda</th><th class="text-end">Ajuste</th></tr></thead><tbody>`;
    p.lotes.forEach(l => {
      h += `<tr><td>${esc(l.lote)}</td><td>${esc(l.vcmto || '')}</td><td class="text-end">${fmt(l.stock)}</td>
        <td class="text-end">${fmt(l.final)}</td><td class="text-end">${fmtDif(l.diferencia)}</td></tr>`;
    });
    $('#modalLotesBody').html(h + '</tbody></table>');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalLotes')).show();
  }).fail(m => alerta('warning', 'Lotes', esc(m)));
}

/* -------------------------------- Listado --------------------------------- */

let datosListado = [];

function listar() {
  $('#listado').html('<div class="text-center p-3"><span class="spinner-border spinner-border-sm"></span> Consultando...</div>');
  api('listar', { estado: $('#estado').val() }).then(r => {
    datosListado = r.data;
    filtrarListado();
  }).fail(m => { $('#listado').empty(); alerta('error', 'Listado', esc(m)); });
}

function filtrarListado() {
  const f = $('#filtro_listado').val().trim().toUpperCase();
  const datos = f ? datosListado.filter(d => (d.codigo + ' ' + d.descripcion).toUpperCase().includes(f)) : datosListado;
  const contados = datos.filter(d => d.conteo !== null).length;
  $('#resumen-listado').html(`Referencias: <b>${datos.length}</b> &nbsp; Contadas: <b>${contados}</b> &nbsp; Pendientes: <b>${datos.length - contados}</b>`);
  let h = `<table class="table table-sm table-bordered table-hover"><thead class="table-light sticky-top"><tr>
    <th>Código</th><th>Descripción</th><th class="text-end">Sistema</th><th class="text-end">Contado</th><th class="text-end">Dif.</th><th class="text-center">Lotes</th><th></th></tr></thead><tbody>`;
  datos.forEach(d => {
    h += `<tr><td>${esc(d.codigo)}</td><td>${esc(d.descripcion)}</td><td class="text-end">${fmt(d.stock)}</td>
      <td class="text-end">${d.conteo === null ? '<span class="text-muted">-</span>' : fmt(d.conteo)}</td>
      <td class="text-end">${fmtDif(d.diferencia)}</td>
      <td class="text-center">${d.sin_lotes ? '<span class="badge bg-danger">sin lotes</span>' : d.n_lotes}</td>
      <td class="text-nowrap">
        <button class="btn btn-sm btn-outline-primary py-0 btn-contar" data-codigo="${esc(d.codigo)}" title="Contar"><i class="fa-solid fa-barcode"></i></button>
        ${d.sin_lotes ? '' : `<button class="btn btn-sm btn-outline-secondary py-0 btn-lotes" data-codigo="${esc(d.codigo)}" title="Lotes"><i class="fa-solid fa-boxes-stacked"></i></button>`}
      </td></tr>`;
  });
  $('#listado').html(h + '</tbody></table>');
}

/* --------------------------------- Ajuste --------------------------------- */

function invalidarPreview() {
  previewOk = false;
  $('#btn-ajustar').prop('disabled', true);
}

function previsualizar() {
  invalidarPreview();
  $('#preview').html('<div class="text-center p-3"><span class="spinner-border spinner-border-sm"></span> Calculando...</div>');
  api('previsualizar', { incluir_no_contados: $('#incluir_no_contados').is(':checked') }).then(r => {
    const pl = r.data;
    let res = `<div class="alert alert-info p-2 mb-2">
      Entradas: <b>${pl.lineas_entrada}</b> líneas / <b>${fmt(pl.unidades_entrada)}</b> und (costo $${fmt(Math.round(pl.costo_entrada))}) &nbsp;|&nbsp;
      Salidas: <b>${pl.lineas_salida}</b> líneas / <b>${fmt(pl.unidades_salida)}</b> und (costo $${fmt(Math.round(pl.costo_salida))})</div>`;
    if (pl.sin_lotes.length) {
      res += `<div class="alert alert-warning p-2 mb-2"><b>${pl.sin_lotes.length}</b> referencia(s) contadas sin lotes en referencias_fis_lote; no se ajustarán:<br>` +
        pl.sin_lotes.map(p => `${esc(p.codigo)} - ${esc(p.descripcion)} (contado ${fmt(p.contado)})`).join('<br>') + '</div>';
    }
    $('#resumen-ajuste').html(res);

    if (!pl.productos.length) {
      $('#preview').html('<div class="alert alert-success p-2">No hay diferencias para ajustar.</div>');
      return;
    }
    let h = `<table class="table table-sm table-bordered"><thead class="table-light sticky-top"><tr>
      <th>Código</th><th>Descripción</th><th>Lote</th><th>Vence</th><th class="text-end">Sistema</th><th class="text-end">Queda</th><th class="text-end">Ajuste</th><th>Doc.</th></tr></thead><tbody>`;
    pl.productos.forEach(p => {
      const lineas = p.lotes;
      lineas.forEach((l, i) => {
        h += `<tr>${i === 0 ? `<td rowspan="${lineas.length}">${esc(p.codigo)}</td>
            <td rowspan="${lineas.length}">${esc(p.descripcion)}<br><small class="text-muted">Sistema ${fmt(p.stock)} · Contado ${p.contado_null ? '0 (no contado)' : fmt(p.contado)}</small></td>` : ''}
          <td>${esc(l.lote)}</td><td>${esc(l.vcmto || '')}</td>
          <td class="text-end">${fmt(l.stock)}</td><td class="text-end">${fmt(l.final)}</td><td class="text-end">${fmtDif(l.diferencia)}</td>
          <td>${l.diferencia > 0 ? '<span class="badge bg-success">Entrada</span>' : (l.diferencia < 0 ? '<span class="badge bg-danger">Salida</span>' : '')}</td></tr>`;
      });
    });
    $('#preview').html(h + '</tbody></table>');
    previewOk = true;
    $('#btn-ajustar').prop('disabled', false);
  }).fail(m => { $('#preview').empty(); alerta('error', 'Previsualización', esc(m)); });
}

function ajustar() {
  if (!previewOk) return;
  const tipo_entrada = $('#tipo_entrada').val(), tipo_salida = $('#tipo_salida').val();
  if (!tipo_entrada || !tipo_salida) { alerta('warning', 'Ajuste', 'Seleccione el documento de entrada y el de salida'); return; }
  Swal.fire({ icon: 'warning', title: '¿Generar el ajuste?',
    html: `Bodega <b>${esc($('#bodega option:selected').text())}</b>, ${esc($('#toma option:selected').text())}.<br>Se crearán los documentos en el DMS.`,
    showCancelButton: true, confirmButtonText: 'Sí, ajustar', cancelButtonText: 'Cancelar', customClass: { container: 'custom-swal-container' } })
    .then(res => {
      if (!res.isConfirmed) return;
      $('#btn-ajustar').prop('disabled', true);
      Swal.fire({ title: 'Generando ajuste...', allowOutsideClick: false, didOpen: () => Swal.showLoading(), customClass: { container: 'custom-swal-container' } });
      api('ajustar', {
        tipo_entrada, tipo_salida, notas: $('#notas_ajuste').val(),
        incluir_no_contados: $('#incluir_no_contados').is(':checked')
      }).then(r => {
        const d = r.data;
        let msg = '';
        if (d.numero_entrada) msg += `Entrada <b>${esc(d.tipo_entrada)} ${d.numero_entrada}</b> (${d.lineas_entrada} líneas)<br>`;
        if (d.numero_salida) msg += `Salida <b>${esc(d.tipo_salida)} ${d.numero_salida}</b> (${d.lineas_salida} líneas)<br>`;
        if (d.sin_lotes.length) msg += `<br><span class="text-danger">${d.sin_lotes.length} referencia(s) sin lotes no se ajustaron.</span>`;
        alerta('success', 'Ajuste realizado', msg);
        invalidarPreview();
        $('#preview, #resumen-ajuste').empty();
        cargarHistorial();
      }).fail(m => { Swal.close(); alerta('error', 'Ajuste', esc(m)); $('#btn-ajustar').prop('disabled', !previewOk); });
    });
}

function cargarHistorial() {
  if (!$('#bodega').val()) return;
  api('historial').then(r => {
    if (!r.data.length) { $('#historial').html('<div class="text-muted small">Sin ajustes registrados en esta bodega.</div>'); return; }
    let h = `<table class="table table-sm table-bordered"><thead class="table-light"><tr>
      <th>#</th><th>Fecha</th><th>Toma</th><th>Usuario</th><th>Entrada</th><th>Salida</th><th>Notas</th></tr></thead><tbody>`;
    r.data.forEach(a => {
      h += `<tr><td>${a.id}</td><td>${esc(a.fecha)}</td><td>${a.toma}</td><td>${esc(a.usuario)}</td>
        <td>${a.numero_entrada ? `${esc(a.tipo_entrada)} ${a.numero_entrada} (${a.lineas_entrada})` : '-'}</td>
        <td>${a.numero_salida ? `${esc(a.tipo_salida)} ${a.numero_salida} (${a.lineas_salida})` : '-'}</td>
        <td>${esc(a.notas)}</td></tr>`;
    });
    $('#historial').html(h + '</tbody></table>');
  }).fail(m => $('#historial').html(`<div class="text-danger small">${esc(m)}</div>`));
}
