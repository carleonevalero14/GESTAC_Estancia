/* =====================================================
   sistemas_operativos.js — Dashboard de sistemas operativos.
   Independiente del CMDB: lee de activos (servicios/sistemas_operativos.php).
   Usa api.obtener()/api.enviar() (peticiones.js), animarContador()
   (utilidades.js) y Chart.js.
   ===================================================== */

const ENDPOINT_SO  = 'sistemas_operativos.php';
const VERDES_SO    = ['#4CAF50', '#81C784', '#2b5a2b', '#a5d6a7', '#388E3C', '#66BB6A', '#c8e6c9', '#1f3b1f'];
const COLOR_ALERTA = '#9b2c2c';
const COLOR_NEUTRO = '#9e9e9e';
const FAMILIAS_OBSOLETAS = ['Windows 10', 'Windows 8', 'Windows 7'];
let equiposSO = [];
const graficasSO = {};

function escapar(texto) {
    return String(texto ?? '').replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
}

function exportarExcel() {
    window.location.href = `../servicios/${ENDPOINT_SO}?accion=exportar_excel`;
}

/* ── KPIs ── */
function renderizarKpis(k) {
    const items = [
        { id: 'soKpiTotal', numero: k.total_equipos, etiqueta: 'Activos', animar: true },
        { id: 'soKpiReg',   numero: k.registrados,   etiqueta: `Con SO registrado (${k.pct_registrados}%)`, animar: true },
        { id: 'soKpiFam',   numero: k.familias,      etiqueta: 'Familias de SO', animar: true },
        { id: 'soKpiPred',  numero: k.predominante,  etiqueta: 'Predominante', texto: true },
        { id: 'soKpiObs',   numero: k.sin_soporte,   etiqueta: `Sin soporte (${k.pct_sin_soporte}%)`, animar: true, alerta: k.sin_soporte > 0 },
        { id: 'soKpiSin',   numero: k.sin_registro,  etiqueta: 'Sin SO registrado', animar: true, alerta: k.sin_registro > 0 },
    ];
    document.getElementById('soKpis').innerHTML = items.map(i => `
        <div class="so-kpi${i.alerta ? ' alerta' : ''}">
            <div class="so-kpi-numero${i.texto ? ' texto' : ''}" id="${i.id}">${i.animar ? 0 : escapar(i.numero)}</div>
            <div class="so-kpi-etiqueta">${escapar(i.etiqueta)}</div>
        </div>`).join('');
    items.filter(i => i.animar).forEach(i => animarContador(i.id, i.numero, 800));
}

/* ── Gráficas ── */
function opcionesEjes(horizontal) {
    const marca = { color: 'rgba(255,255,255,0.75)', font: { size: 10 }, precision: 0 };
    return {
        indexAxis: horizontal ? 'y' : 'x',
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { ticks: marca, grid: { color: 'rgba(255,255,255,0.08)' } },
            y: { ticks: marca, grid: { color: 'rgba(255,255,255,0.08)' } },
        },
        animation: { duration: 600 },
    };
}

function colorFamilia(nombre, i) {
    if (nombre === 'Sin registrar') return COLOR_NEUTRO;
    return FAMILIAS_OBSOLETAS.includes(nombre) ? COLOR_ALERTA : VERDES_SO[i % VERDES_SO.length];
}

/* Dibuja la gráfica, o un aviso si no hay datos que mostrar. */
function dibujar(clave, lienzoId, hayDatos, config) {
    const lienzo = document.getElementById(lienzoId);
    const caja   = lienzo.parentElement;
    if (graficasSO[clave]) { graficasSO[clave].destroy(); delete graficasSO[clave]; }
    caja.querySelector('.so-vacio')?.remove();
    lienzo.style.display = hayDatos ? '' : 'none';
    if (!hayDatos) {
        caja.insertAdjacentHTML('beforeend', '<p class="so-vacio">Aún no hay sistemas operativos registrados</p>');
        return;
    }
    graficasSO[clave] = new Chart(lienzo, config);
}

function renderizarGraficas(datos) {
    const fam = datos.por_familia;
    dibujar('familia', 'chartFamilia', fam.length > 0, {
        type: 'doughnut',
        data: {
            labels: fam.map(d => d.nombre),
            datasets: [{
                data: fam.map(d => d.total),
                backgroundColor: fam.map((d, i) => colorFamilia(d.nombre, i)),
                borderWidth: 2, borderColor: '#1f3b1f',
            }],
        },
        options: {
            maintainAspectRatio: false, cutout: '60%',
            plugins: { legend: { position: 'bottom', labels: { color: '#fff', font: { size: 11 }, boxWidth: 12 } } },
            animation: { duration: 700 },
        },
    });

    const edi = datos.por_edicion;
    dibujar('edicion', 'chartEdicion', edi.length > 0, {
        type: 'bar',
        data: {
            labels: edi.map(d => d.nombre),
            datasets: [{
                data: edi.map(d => d.total),
                backgroundColor: edi.map((d, i) => d.nombre === 'Sin registrar' ? COLOR_NEUTRO : VERDES_SO[i % VERDES_SO.length]),
                borderWidth: 0, borderRadius: 4,
            }],
        },
        options: opcionesEjes(true),
    });

    const ver = datos.por_version;
    dibujar('version', 'chartVersion', ver.length > 0, {
        type: 'bar',
        data: {
            labels: ver.map(d => `${d.familia} · ${d.version}`),
            datasets: [{
                data: ver.map(d => d.total),
                backgroundColor: ver.map((d, i) => colorFamilia(d.familia, i)),
                borderWidth: 0, borderRadius: 4,
            }],
        },
        options: opcionesEjes(false),
    });
}

/* ── Tabla y filtros ── */
function llenarFiltroFamilia(familias) {
    const sel    = document.getElementById('filterFamilia');
    const actual = sel.value;
    sel.innerHTML = '<option value="">Todos</option>' +
        familias.map(f => `<option value="${escapar(f.nombre)}">${escapar(f.nombre)}</option>`).join('');
    sel.value = familias.some(f => f.nombre === actual) ? actual : '';
}

function aplicarFiltrosSO() {
    const termino = (document.getElementById('searchInput')?.value || '').toLowerCase().trim();
    const familia = document.getElementById('filterFamilia')?.value || '';
    const filtrados = equiposSO.filter(e => {
        const okFam  = !familia || e.familia === familia;
        const okTerm = !termino || [e.id_producto, e.nombre, e.usuario, e.so, e.so_version]
            .some(v => String(v || '').toLowerCase().includes(termino));
        return okFam && okTerm;
    });
    renderizarTablaSO(filtrados);
}

function chipSoporte(e) {
    if (e.familia === 'Sin registrar') return '<span class="chip neutro">Sin dato</span>';
    return e.sin_soporte ? '<span class="chip sin-soporte">Sin soporte</span>' : '<span class="chip">Vigente</span>';
}

function renderizarTablaSO(lista) {
    const cuerpo  = document.getElementById('cuerpoSO');
    const infoPag = document.querySelector('.pagination-info');
    cuerpo.innerHTML = '';

    if (!lista.length) {
        cuerpo.innerHTML = '<tr><td colspan="7" class="celda-vacia">No se encontraron activos</td></tr>';
        if (infoPag) infoPag.textContent = `Mostrando Activos 0 de ${equiposSO.length}`;
        return;
    }

    lista.forEach(e => {
        const accion = window.SO_ES_ADMIN
            ? `<button class="boton-editar" title="Registrar o editar SO" aria-label="Editar" data-editar="${escapar(e.id_producto)}"></button>`
            : `<button class="btn-exportar btn-carta" data-ver="${escapar(e.id_producto)}">Ver</button>`;
        const fila = document.createElement('tr');
        fila.innerHTML = `
            <td>#${escapar(e.id_producto)}</td>
            <td title="${escapar(e.nombre)}"><strong>${escapar(e.nombre || 'N/A')}</strong></td>
            <td>${escapar(e.usuario || 'Sin asignar')}</td>
            <td title="${escapar(e.so)}">${escapar(e.so)}</td>
            <td title="${escapar(e.so_version || '')}">${escapar(e.so_version || '—')}</td>
            <td>${chipSoporte(e)}</td>
            <td class="col-editar">${accion}</td>
        `;
        cuerpo.appendChild(fila);
    });

    if (infoPag) infoPag.textContent = `Mostrando Activos 1 a ${lista.length} de ${equiposSO.length}`;
}

/* ── Modal de captura (admin) ── */
function abrirModalSO(id) {
    const e = equiposSO.find(x => x.id_producto === id);
    if (!e) return;
    document.getElementById('so-id').value      = e.id_producto;
    document.getElementById('so-activo').value  = `${e.id_producto} — ${e.nombre || ''}`;
    document.getElementById('so-nombre').value  = e.so_nombre || '';
    document.getElementById('so-version').value = e.so_version || '';
    const msg = document.getElementById('msgSO');
    msg.textContent = ''; msg.className = 'so-msg';
    document.getElementById('modalSO').classList.add('abierto');
    document.getElementById('so-nombre').focus();
}

function cerrarModalSO() {
    document.getElementById('modalSO').classList.remove('abierto');
}

async function guardarSO(ev) {
    ev.preventDefault();
    const boton = document.getElementById('guardarSO');
    const msg   = document.getElementById('msgSO');
    boton.disabled = true;
    try {
        const fd = new FormData();
        fd.append('id_producto', document.getElementById('so-id').value);
        fd.append('so_nombre',   document.getElementById('so-nombre').value.trim());
        fd.append('so_version',  document.getElementById('so-version').value.trim());
        const r = await api.enviar(`${ENDPOINT_SO}?accion=guardar_so`, fd);
        if (r.exito) {
            cerrarModalSO();
            await cargarDashboardSO();
        } else {
            msg.className = 'so-msg error';
            msg.textContent = r.mensaje || 'No se pudo guardar';
        }
    } catch (err) {
        console.error(err);
        msg.className = 'so-msg error';
        msg.textContent = 'Error de comunicación con el servidor';
    } finally {
        boton.disabled = false;
    }
}

/* ── Carga ── */
async function cargarDashboardSO() {
    try {
        const r = await api.obtener(ENDPOINT_SO);
        if (!r.exito) throw new Error(r.mensaje || 'Respuesta no válida');
        equiposSO = r.equipos || [];
        renderizarKpis(r.kpis);
        renderizarGraficas(r);
        llenarFiltroFamilia(r.por_familia);
        aplicarFiltrosSO();
    } catch (err) {
        console.error(err);
        document.getElementById('cuerpoSO').innerHTML =
            '<tr><td colspan="7" class="celda-vacia" style="color:#ffdddd">Error al cargar los datos</td></tr>';
    }
}

document.addEventListener('DOMContentLoaded', () => {
    cargarDashboardSO();
    document.getElementById('searchInput')?.addEventListener('input', aplicarFiltrosSO);
    document.getElementById('filterFamilia')?.addEventListener('change', aplicarFiltrosSO);

    document.getElementById('cuerpoSO').addEventListener('click', e => {
        const editar = e.target.closest('[data-editar]');
        if (editar) return abrirModalSO(editar.dataset.editar);
        const ver = e.target.closest('[data-ver]');
        if (ver) window.location.href = `ver_activo.php?id=${encodeURIComponent(ver.dataset.ver)}`;
    });

    document.getElementById('formSO')?.addEventListener('submit', guardarSO);
    document.getElementById('cancelarSO')?.addEventListener('click', cerrarModalSO);
    window.addEventListener('click', e => {
        if (e.target === document.getElementById('modalSO')) cerrarModalSO();
    });
});
