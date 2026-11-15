/* =====================================================
   mantenimiento.js — Módulo de mantenimiento e incidencias.
   Independiente del CMDB. Usa api.obtener()/api.enviar() (peticiones.js),
   animarContador() (utilidades.js) y Chart.js.
   ===================================================== */

const EP = 'mantenimiento.php';
const ES_ADMIN = !!window.MT_ES_ADMIN;
const VERDES = ['#4CAF50', '#81C784', '#2b5a2b', '#a5d6a7', '#388E3C', '#66BB6A'];
const COLOR_PRIORIDAD = { critica: '#9b2c2c', alta: '#e0a100', media: '#fbd34d', baja: '#4CAF50' };
const ETQ_ESTADO = { abierta: 'Abierta', en_proceso: 'En proceso', resuelta: 'Resuelta' };
const ETQ_PRIO   = { critica: 'Crítica', alta: 'Alta', media: 'Media', baja: 'Baja' };
const ETQ_CAT    = { hardware: 'Hardware', software: 'Software', red: 'Red', periferico: 'Periférico', otro: 'Otro' };
const ETQ_TIPO   = { preventivo: 'Preventivo', limpieza: 'Limpieza', actualizacion: 'Actualización', revision: 'Revisión' };
const ETQ_VENC   = { vencido: 'Vencido', proximo: 'Próximo', programado: 'Programado', realizado: 'Realizado' };

let incidencias = [];
let preventivos = [];
let indicadoresCargados = false;
const graficas = {};

function escapar(t) {
    return String(t ?? '').replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
}
const $ = id => document.getElementById(id);
const fechaCorta = f => f ? String(f).slice(0, 16).replace('T', ' ') : '—';
const horasTxt = h => (h === null || h === undefined || h === '') ? '—' : `${h} h`;

function exportarExcel() {
    window.location.href = `../servicios/${EP}?accion=exportar_excel`;
}

function mostrarMsg(id, texto, error) {
    const m = $(id);
    if (!m) return;
    m.textContent = texto || '';
    m.className = 'mt-msg' + (error ? ' error' : '');
}
const abrir  = id => $(id)?.classList.add('abierto');
const cerrar = id => $(id)?.classList.remove('abierto');

/* ── Pestañas ── */
function cambiarTab(tab) {
    document.querySelectorAll('.mt-tab').forEach(b => b.classList.toggle('activa', b.dataset.tab === tab));
    ['incidencias', 'preventivos', 'indicadores'].forEach(t =>
        $(`tab-${t}`)?.classList.toggle('oculto', t !== tab));
    if (tab === 'indicadores') cargarIndicadores();
}

/* ══════════ INCIDENCIAS ══════════ */
async function cargarIncidencias() {
    try {
        const r = await api.obtener(`${EP}?accion=listar`);
        if (!r.exito) throw new Error(r.mensaje);
        incidencias = r.datos || [];
        filtrarIncidencias();
    } catch (e) {
        console.error(e);
        $('cuerpoInc').innerHTML = '<tr><td colspan="8" class="celda-vacia" style="color:#ffdddd">Error al cargar las incidencias</td></tr>';
    }
}

function filtrarIncidencias() {
    const q = ($('searchInc').value || '').toLowerCase().trim();
    const est = $('filterEstadoInc').value;
    const lista = incidencias.filter(i => {
        const okEst = !est || (est === 'fuera_sla' ? !!i.fuera_sla : i.estado === est);
        const okQ = !q || [i.titulo, i.id_activo, i.nombre_activo, i.solicitante, i.tecnico]
            .some(v => String(v || '').toLowerCase().includes(q));
        return okEst && okQ;
    });
    pintarIncidencias(lista);
}

function pintarIncidencias(lista) {
    const cuerpo = $('cuerpoInc');
    $('infoInc').textContent = `Mostrando ${lista.length} de ${incidencias.length}`;
    if (!lista.length) {
        cuerpo.innerHTML = '<tr><td colspan="8" class="celda-vacia">No hay incidencias</td></tr>';
        return;
    }
    cuerpo.innerHTML = lista.map(i => `
        <tr>
            <td>${i.id_activo ? '#' + escapar(i.id_activo) : '—'}</td>
            <td title="${escapar(i.titulo)}"><strong>${escapar(i.titulo)}</strong>${ES_ADMIN ? `<br><small>${escapar(i.solicitante)}</small>` : ''}</td>
            <td>${escapar(ETQ_CAT[i.categoria] || i.categoria)}</td>
            <td><span class="chip p-${escapar(i.prioridad)}">${escapar(ETQ_PRIO[i.prioridad] || i.prioridad)}</span></td>
            <td><span class="chip e-${escapar(i.estado)}">${escapar(ETQ_ESTADO[i.estado] || i.estado)}</span>${i.fuera_sla ? '<span class="mt-sla" title="Fuera del SLA">⚠️</span>' : ''}</td>
            <td>${escapar(i.tecnico || 'Sin asignar')}</td>
            <td>${escapar(fechaCorta(i.fecha_reporte))}</td>
            <td><button class="btn-exportar btn-carta" data-detalle="${Number(i.id)}">${ES_ADMIN ? 'Atender' : 'Ver'}</button></td>
        </tr>`).join('');
}

/* ── Nueva incidencia ── */
async function cargarListaActivos() {
    try {
        const r = await api.obtener('sistemas_operativos.php');
        const lista = r.equipos || [];
        $('listaActivos').innerHTML = lista.map(e =>
            `<option value="${escapar(e.id_producto)}">${escapar(e.nombre || '')}</option>`).join('');
    } catch (e) { console.error(e); }
}

function abrirNuevaInc() {
    $('formInc').reset();
    mostrarMsg('msgInc', '');
    abrir('modalInc');
    $('inc-titulo').focus();
}

async function enviarIncidencia(ev) {
    ev.preventDefault();
    const btn = $('guardarInc');
    btn.disabled = true;
    try {
        const fd = new FormData();
        fd.append('titulo', $('inc-titulo').value.trim());
        fd.append('id_activo', $('inc-activo').value.trim());
        fd.append('categoria', $('inc-categoria').value);
        fd.append('prioridad', $('inc-prioridad').value);
        fd.append('descripcion', $('inc-descripcion').value.trim());
        const r = await api.enviar(`${EP}?accion=crear`, fd);
        if (r.exito) {
            cerrar('modalInc');
            await cargarIncidencias();
            indicadoresCargados = false;
        } else mostrarMsg('msgInc', r.mensaje || 'No se pudo registrar', true);
    } catch (e) {
        console.error(e);
        mostrarMsg('msgInc', 'Error de comunicación con el servidor', true);
    } finally { btn.disabled = false; }
}

/* ── Detalle / atender ── */
let tecnicosCargados = false;
async function cargarTecnicos() {
    if (!ES_ADMIN || tecnicosCargados) return;
    try {
        const r = await api.obtener(`${EP}?accion=tecnicos`);
        if (r.exito) {
            $('at-tecnico').innerHTML = '<option value="">Sin asignar</option>' +
                (r.datos || []).map(t => `<option value="${escapar(t)}">${escapar(t)}</option>`).join('');
            tecnicosCargados = true;
        }
    } catch (e) { console.error(e); }
}

async function abrirDetalle(id) {
    try {
        await cargarTecnicos();
        const r = await api.obtener(`${EP}?accion=detalle&id=${id}`);
        if (!r.exito) { alert(r.mensaje || 'No se pudo cargar'); return; }
        const i = r.datos.incidencia, h = r.datos.historial || [];
        $('tituloDetalle').textContent = `Incidencia #${i.id}`;
        $('detalleResumen').innerHTML = `
            <p class="mt-resumen-titulo">${escapar(i.titulo)}</p>
            <div class="mt-resumen-chips">
                <span class="chip e-${escapar(i.estado)}">${escapar(ETQ_ESTADO[i.estado] || i.estado)}</span>
                <span class="chip p-${escapar(i.prioridad)}">${escapar(ETQ_PRIO[i.prioridad] || i.prioridad)}</span>
                <span class="chip v-programado">${escapar(ETQ_CAT[i.categoria] || i.categoria)}</span>
                ${i.fuera_sla ? '<span class="chip v-vencido">Fuera de SLA</span>' : ''}
            </div>
            <dl class="mt-datos">
                <div><dt>Activo: </dt><dd>${i.id_activo ? '#' + escapar(i.id_activo) + ' ' + escapar(i.nombre_activo || '') : '—'}</dd></div>
                <div><dt>Solicitante: </dt><dd>${escapar(i.solicitante)}</dd></div>
                <div><dt>Técnico: </dt><dd>${escapar(i.tecnico || 'Sin asignar')}</dd></div>
                <div><dt>Reportada: </dt><dd>${escapar(fechaCorta(i.fecha_reporte))}</dd></div>
                <div><dt>Primera respuesta: </dt><dd>${escapar(fechaCorta(i.fecha_primera_respuesta))}</dd></div>
                <div><dt>Resolución: </dt><dd>${escapar(fechaCorta(i.fecha_resolucion))}</dd></div>
                <div><dt>SLA: </dt><dd>${escapar(i.sla_horas)} h</dd></div>
                <div><dt>Tiempo: </dt><dd>${horasTxt(i.horas_resolucion ?? i.horas_abierta)}</dd></div>
            </dl>
            <div class="mt-bloque"><small>Descripción</small>${escapar(i.descripcion)}</div>
            ${i.solucion ? `<div class="mt-bloque"><small>Solución</small>${escapar(i.solucion)}</div>` : ''}`;
        $('detalleHistorial').innerHTML = h.length ? h.map(e => `
            <div class="mt-evento">
                <div><strong>${escapar(e.usuario || '')}</strong>
                ${e.estado_nuevo ? ' → ' + escapar(ETQ_ESTADO[e.estado_nuevo] || e.estado_nuevo) : ''}</div>
                ${e.comentario ? `<div>${escapar(e.comentario)}</div>` : ''}
                <div class="mt-evento-fecha">${escapar(fechaCorta(e.fecha))}</div>
            </div>`).join('') : '<p class="mt-evento-fecha">Sin movimientos</p>';

        if (ES_ADMIN) {
            $('at-id').value = i.id;
            $('at-estado').value = i.estado;
            $('at-tecnico').value = i.tecnico || '';
            $('at-comentario').value = '';
            $('at-solucion').value = i.solucion || '';
            mostrarMsg('msgAtender', '');
        }
        abrir('modalDetalle');
    } catch (e) {
        console.error(e);
        alert('Error de comunicación con el servidor');
    }
}

async function guardarAtencion(ev) {
    ev.preventDefault();
    const btn = $('guardarAtender');
    btn.disabled = true;
    try {
        const fd = new FormData();
        fd.append('id', $('at-id').value);
        fd.append('estado', $('at-estado').value);
        fd.append('tecnico', $('at-tecnico').value);
        fd.append('comentario', $('at-comentario').value.trim());
        fd.append('solucion', $('at-solucion').value.trim());
        const r = await api.enviar(`${EP}?accion=actualizar`, fd);
        if (r.exito) {
            cerrar('modalDetalle');
            await cargarIncidencias();
            indicadoresCargados = false;
        } else mostrarMsg('msgAtender', r.mensaje || 'No se pudo guardar', true);
    } catch (e) {
        console.error(e);
        mostrarMsg('msgAtender', 'Error de comunicación con el servidor', true);
    } finally { btn.disabled = false; }
}

/* ══════════ PREVENTIVOS ══════════ */
async function cargarPreventivos() {
    try {
        const r = await api.obtener(`${EP}?accion=preventivos`);
        if (!r.exito) throw new Error(r.mensaje);
        preventivos = r.datos || [];
        filtrarPreventivos();
    } catch (e) {
        console.error(e);
        $('cuerpoPrev').innerHTML = '<tr><td colspan="7" class="celda-vacia" style="color:#ffdddd">Error al cargar los mantenimientos</td></tr>';
    }
}

function filtrarPreventivos() {
    const est = $('filterEstadoPrev').value;
    pintarPreventivos(preventivos.filter(p => !est || p.estado === est));
}

function pintarPreventivos(lista) {
    $('infoPrev').textContent = `Mostrando ${lista.length} de ${preventivos.length}`;
    if (!lista.length) {
        $('cuerpoPrev').innerHTML = '<tr><td colspan="7" class="celda-vacia">No hay mantenimientos</td></tr>';
        return;
    }
    $('cuerpoPrev').innerHTML = lista.map(p => {
        let accion = '—';
        if (ES_ADMIN && p.estado !== 'realizado') {
            accion = `<div class="mt-acciones">
                <button class="btn-exportar btn-carta" data-completar="${Number(p.id)}">Realizado</button>
                <button class="btn-exportar btn-carta btn-eliminar" data-eliminar="${Number(p.id)}">Borrar</button></div>`;
        }
        const fecha = p.estado === 'realizado' ? p.fecha_realizada : p.fecha_programada;
        const extra = p.estado === 'realizado' && p.realizado_por ? `<br><small>${escapar(p.realizado_por)}</small>` : '';
        return `<tr>
            <td>#${escapar(p.id_activo)}</td>
            <td title="${escapar(p.nombre_activo || '')}">${escapar(p.nombre_activo || 'N/A')}</td>
            <td>${escapar(ETQ_TIPO[p.tipo] || p.tipo)}</td>
            <td title="${escapar(p.descripcion || '')}">${escapar(p.descripcion || '—')}</td>
            <td>${escapar(fecha || '')}${extra}</td>
            <td><span class="chip v-${escapar(p.estado)}">${escapar(ETQ_VENC[p.estado] || p.estado)}</span></td>
            <td>${accion}</td></tr>`;
    }).join('');
}

function abrirProgramar() {
    $('formProg').reset();
    mostrarMsg('msgProg', '');
    abrir('modalProg');
    $('prog-activo').focus();
}

async function enviarProgramacion(ev) {
    ev.preventDefault();
    const btn = $('guardarProg');
    btn.disabled = true;
    try {
        const fd = new FormData();
        fd.append('id_activo', $('prog-activo').value.trim());
        fd.append('tipo', $('prog-tipo').value);
        fd.append('fecha_programada', $('prog-fecha').value);
        fd.append('descripcion', $('prog-desc').value.trim());
        const r = await api.enviar(`${EP}?accion=programar_preventivo`, fd);
        if (r.exito) { cerrar('modalProg'); await cargarPreventivos(); indicadoresCargados = false; }
        else mostrarMsg('msgProg', r.mensaje || 'No se pudo programar', true);
    } catch (e) {
        console.error(e);
        mostrarMsg('msgProg', 'Error de comunicación con el servidor', true);
    } finally { btn.disabled = false; }
}

function abrirCompletar(id) {
    $('formComp').reset();
    $('comp-id').value = id;
    $('comp-fecha').max = new Date().toLocaleDateString('en-CA');
    mostrarMsg('msgComp', '');
    abrir('modalComp');
}

async function enviarCompletar(ev) {
    ev.preventDefault();
    const btn = $('guardarComp');
    btn.disabled = true;
    try {
        const fd = new FormData();
        fd.append('id', $('comp-id').value);
        fd.append('fecha_realizada', $('comp-fecha').value);
        fd.append('observaciones', $('comp-obs').value.trim());
        const r = await api.enviar(`${EP}?accion=completar_preventivo`, fd);
        if (r.exito) { cerrar('modalComp'); await cargarPreventivos(); indicadoresCargados = false; }
        else mostrarMsg('msgComp', r.mensaje || 'No se pudo registrar', true);
    } catch (e) {
        console.error(e);
        mostrarMsg('msgComp', 'Error de comunicación con el servidor', true);
    } finally { btn.disabled = false; }
}

async function eliminarPreventivo(id) {
    if (!confirm('¿Eliminar este mantenimiento pendiente?')) return;
    try {
        const fd = new FormData();
        fd.append('id', id);
        const r = await api.enviar(`${EP}?accion=eliminar_preventivo`, fd);
        if (!r.exito) alert(r.mensaje || 'No se pudo eliminar');
        await cargarPreventivos();
        indicadoresCargados = false;
    } catch (e) { console.error(e); alert('Error de comunicación con el servidor'); }
}

/* ══════════ INDICADORES ══════════ */
function dibujar(clave, lienzoId, hayDatos, config) {
    const lienzo = $(lienzoId);
    const caja = lienzo.parentElement;
    if (graficas[clave]) { graficas[clave].destroy(); delete graficas[clave]; }
    caja.querySelector('.mt-vacio')?.remove();
    lienzo.style.display = hayDatos ? '' : 'none';
    if (!hayDatos) {
        caja.insertAdjacentHTML('beforeend', '<p class="mt-vacio">Aún no hay datos</p>');
        return;
    }
    graficas[clave] = new Chart(lienzo, config);
}

function ejes(horizontal) {
    const marca = { color: 'rgba(255,255,255,0.75)', font: { size: 10 }, precision: 0 };
    return {
        indexAxis: horizontal ? 'y' : 'x',
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { ticks: marca, grid: { color: 'rgba(255,255,255,0.08)' } },
            y: { ticks: marca, grid: { color: 'rgba(255,255,255,0.08)' }, beginAtZero: true },
        },
        animation: { duration: 600 },
    };
}

function pintarKpis(k) {
    const pct = v => v === null || v === undefined ? '—' : `${v}<small>%</small>`;
    const hrs = v => v === null || v === undefined ? '—' : `${v}<small>h</small>`;
    const items = [
        { n: k.total, e: 'Incidencias' },
        { n: k.abiertas + k.en_proceso, e: 'Sin resolver', alerta: (k.abiertas + k.en_proceso) > 0 },
        { n: k.fuera_sla, e: 'Fuera de SLA', alerta: k.fuera_sla > 0 },
        { n: hrs(k.mttr_horas), e: 'MTTR (promedio)' },
        { n: hrs(k.mediana_horas), e: 'Mediana resolución' },
        { n: hrs(k.primera_resp_horas), e: '1ª respuesta (prom.)' },
        { n: pct(k.cumplimiento_sla), e: 'Cumplimiento SLA' },
        { n: k.prev_vencidos, e: 'Preventivos vencidos', alerta: k.prev_vencidos > 0 },
        { n: k.prev_proximos, e: 'Preventivos ≤ 7 días' },
        { n: pct(k.prev_a_tiempo_pct), e: 'Preventivos a tiempo' },
    ];
    $('mtKpis').innerHTML = items.map(i => `
        <div class="mt-kpi${i.alerta ? ' alerta' : ''}">
            <div class="mt-kpi-numero">${i.n}</div>
            <div class="mt-kpi-etiqueta">${escapar(i.e)}</div>
        </div>`).join('');
}

async function cargarIndicadores() {
    if (!ES_ADMIN || indicadoresCargados) return;
    try {
        const r = await api.obtener(`${EP}?accion=metricas`);
        if (!r.exito) throw new Error(r.mensaje);
        pintarKpis(r.kpis);

        const prio = Object.keys(ETQ_PRIO).filter(p => (r.por_prioridad || {})[p] > 0);
        dibujar('prio', 'chartPrioridad', prio.length > 0, {
            type: 'doughnut',
            data: { labels: prio.map(p => ETQ_PRIO[p]),
                    datasets: [{ data: prio.map(p => r.por_prioridad[p]), backgroundColor: prio.map(p => COLOR_PRIORIDAD[p]), borderWidth: 2, borderColor: '#1f3b1f' }] },
            options: { maintainAspectRatio: false, cutout: '60%',
                plugins: { legend: { position: 'bottom', labels: { color: '#fff', font: { size: 11 }, boxWidth: 12 } } } },
        });

        const cat = Object.keys(ETQ_CAT).filter(c => (r.por_categoria || {})[c] > 0);
        dibujar('cat', 'chartCategoria', cat.length > 0, {
            type: 'bar',
            data: { labels: cat.map(c => ETQ_CAT[c]),
                    datasets: [{ data: cat.map(c => r.por_categoria[c]), backgroundColor: VERDES, borderWidth: 0, borderRadius: 4 }] },
            options: ejes(false),
        });

        const meses = r.por_mes || [];
        dibujar('mes', 'chartMes', meses.some(m => m.reportadas || m.resueltas), {
            type: 'bar',
            data: { labels: meses.map(m => m.mes),
                    datasets: [
                        { label: 'Reportadas', data: meses.map(m => m.reportadas), backgroundColor: '#81C784', borderRadius: 4 },
                        { label: 'Resueltas',  data: meses.map(m => m.resueltas),  backgroundColor: '#2b5a2b', borderRadius: 4 },
                    ] },
            options: { ...ejes(false), plugins: { legend: { display: true, labels: { color: '#fff', font: { size: 11 }, boxWidth: 12 } } } },
        });

        const top = r.top_activos || [];
        dibujar('top', 'chartTop', top.length > 0, {
            type: 'bar',
            data: { labels: top.map(t => `#${t.id_activo} ${t.nombre || ''}`.trim()),
                    datasets: [{ data: top.map(t => Number(t.total)), backgroundColor: '#4CAF50', borderWidth: 0, borderRadius: 4 }] },
            options: ejes(true),
        });

        $('tablaSla').querySelector('tbody').innerHTML = (r.por_prioridad_detalle || []).map(d => `
            <tr><td><span class="chip p-${escapar(d.prioridad)}">${escapar(ETQ_PRIO[d.prioridad])}</span></td>
            <td>${escapar(d.sla_horas)} h</td><td>${escapar(d.resueltas)}/${escapar(d.total)}</td>
            <td>${horasTxt(d.mttr_horas)}</td>
            <td>${d.cumplimiento_sla === null ? '—' : escapar(d.cumplimiento_sla) + '%'}</td></tr>`).join('');
        indicadoresCargados = true;
    } catch (e) {
        console.error(e);
        $('mtKpis').innerHTML = '<p class="mt-vacio">Error al cargar los indicadores</p>';
    }
}

/* ══════════ Arranque ══════════ */
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.mt-tab').forEach(b => b.addEventListener('click', () => cambiarTab(b.dataset.tab)));

    $('searchInc').addEventListener('input', filtrarIncidencias);
    $('filterEstadoInc').addEventListener('change', filtrarIncidencias);
    $('filterEstadoPrev').addEventListener('change', filtrarPreventivos);
    $('btnNuevaInc').addEventListener('click', abrirNuevaInc);
    $('formInc').addEventListener('submit', enviarIncidencia);
    $('cancelarInc').addEventListener('click', () => cerrar('modalInc'));
    $('cerrarDetalle').addEventListener('click', () => cerrar('modalDetalle'));

    $('cuerpoInc').addEventListener('click', e => {
        const b = e.target.closest('[data-detalle]');
        if (b) abrirDetalle(b.dataset.detalle);
    });

    if (ES_ADMIN) {
        $('formAtender').addEventListener('submit', guardarAtencion);
        $('btnProgramar')?.addEventListener('click', abrirProgramar);
        $('formProg').addEventListener('submit', enviarProgramacion);
        $('cancelarProg').addEventListener('click', () => cerrar('modalProg'));
        $('formComp').addEventListener('submit', enviarCompletar);
        $('cancelarComp').addEventListener('click', () => cerrar('modalComp'));
        $('cuerpoPrev').addEventListener('click', e => {
            const c = e.target.closest('[data-completar]');
            if (c) return abrirCompletar(c.dataset.completar);
            const d = e.target.closest('[data-eliminar]');
            if (d) eliminarPreventivo(d.dataset.eliminar);
        });
    }

    window.addEventListener('click', e => {
        if (e.target.classList?.contains('modal')) e.target.classList.remove('abierto');
    });

    cargarListaActivos();
    cargarIncidencias();
    cargarPreventivos();
});
