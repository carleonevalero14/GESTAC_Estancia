<?php require_once __DIR__ . '/../configuracion/sesion.php'; requerirSesion(); ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mantenimiento e Incidencias — GESTAC</title>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;700;800&display=swap" rel="stylesheet">
    <script>(function(){var t=localStorage.getItem('gestac_tema');if(t&&t!=='original')document.documentElement.classList.add('tema-'+t);}());</script>
    <link rel="stylesheet" href="../recursos/css/base.css">
    <link rel="stylesheet" href="../recursos/css/temas.css">
    <link rel="stylesheet" href="../recursos/css/componentes.css">
    <link rel="stylesheet" href="../recursos/css/paginas.css">
    <link rel="stylesheet" href="../recursos/css/mantenimiento.css?v=<?php echo filemtime(__DIR__ . '/../recursos/css/mantenimiento.css'); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script>window.MT_ES_ADMIN = <?php echo esAdmin() ? 'true' : 'false'; ?>;</script>
</head>
<body style="background-image: url('../recursos/imagenes/fondo.png'); background-repeat: no-repeat; background-size: cover; background-position: center;">

    <div class="contenedor">
        <header class="encabezado" style="position: relative;">
            <button class="btn-volver" onclick="history.back()">←</button>
            <h1 class="titulo-pagina">MANTENIMIENTO E INCIDENCIAS</h1>
        </header>

        <div class="cuerpo-pagina">
            <!-- ── Pestañas ── -->
            <div class="mt-tabs" role="tablist">
                <button class="mt-tab activa" data-tab="incidencias" role="tab">🛠 Incidencias</button>
                <button class="mt-tab" data-tab="preventivos" role="tab">📅 Preventivos</button>
                <?php if (esAdmin()): ?>
                <button class="mt-tab" data-tab="indicadores" role="tab">📊 Indicadores</button>
                <?php endif; ?>
            </div>

            <!-- ══════════ INCIDENCIAS ══════════ -->
            <section id="tab-incidencias">
                <div class="encabezado-lista">
                    <h2 class="titulo-lista"><?php echo esAdmin() ? 'Incidencias reportadas' : 'Mis incidencias'; ?></h2>
                    <div class="barra-controles">
                        <?php if (esAdmin()): ?>
                        <button class="btn-exportar" onclick="exportarExcel()">Exportar a Excel</button>
                        <?php endif; ?>
                        <label class="caja-busqueda" aria-label="Buscar incidencia">
                            <span class="icono-busqueda" aria-hidden="true">🔎</span>
                            <input type="text" id="searchInc" placeholder="Buscar por título, activo o persona">
                        </label>
                        <div class="desplegable">
                            <select id="filterEstadoInc" aria-label="Filtrar por estado">
                                <option value="">Todos</option>
                                <option value="abierta">Abiertas</option>
                                <option value="en_proceso">En proceso</option>
                                <option value="resuelta">Resueltas</option>
                                <option value="fuera_sla">Fuera de SLA</option>
                            </select>
                        </div>
                        <button class="btn-principal" id="btnNuevaInc" type="button">Nueva incidencia</button>
                    </div>
                </div>

                <div class="contenedor-tabla">
                    <table class="tabla-activos" role="grid">
                        <colgroup>
                            <col style="width:12%"><col style="width:23%"><col style="width:10%"><col style="width:10%">
                            <col style="width:13%"><col style="width:12%"><col style="width:12%"><col style="width:8%">
                        </colgroup>
                        <thead>
                            <tr>
                                <th>Activo</th><th>Título</th><th>Categoría</th><th>Prioridad</th>
                                <th>Estado</th><th>Técnico</th><th>Reportada</th><th>Acción</th>
                            </tr>
                        </thead>
                        <tbody id="cuerpoInc">
                            <tr><td colspan="8" class="celda-vacia">Cargando incidencias...</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="pie-tabla"><span id="infoInc">Mostrando 0 de 0</span></div>
            </section>

            <!-- ══════════ PREVENTIVOS ══════════ -->
            <section id="tab-preventivos" class="oculto">
                <div class="encabezado-lista">
                    <h2 class="titulo-lista">Mantenimientos programados</h2>
                    <div class="barra-controles">
                        <div class="desplegable">
                            <select id="filterEstadoPrev" aria-label="Filtrar por estado">
                                <option value="">Todos</option>
                                <option value="vencido">Vencidos</option>
                                <option value="proximo">Próximos (7 días)</option>
                                <option value="programado">Programados</option>
                                <option value="realizado">Realizados</option>
                            </select>
                        </div>
                        <?php if (esAdmin()): ?>
                        <button class="btn-principal" id="btnProgramar" type="button">Programar mantenimiento</button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="contenedor-tabla">
                    <table class="tabla-activos" role="grid">
                        <colgroup>
                            <col style="width:14%"><col style="width:16%"><col style="width:12%"><col style="width:22%">
                            <col style="width:12%"><col style="width:12%"><col style="width:12%">
                        </colgroup>
                        <thead>
                            <tr>
                                <th>Activo</th><th>Equipo</th><th>Tipo</th><th>Descripción</th>
                                <th>Programado</th><th>Estado</th><th>Acción</th>
                            </tr>
                        </thead>
                        <tbody id="cuerpoPrev">
                            <tr><td colspan="7" class="celda-vacia">Cargando mantenimientos...</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="pie-tabla"><span id="infoPrev">Mostrando 0 de 0</span></div>
            </section>

            <?php if (esAdmin()): ?>
            <!-- ══════════ INDICADORES ══════════ -->
            <section id="tab-indicadores" class="oculto">
                <div class="mt-kpis" id="mtKpis"></div>

                <div class="mt-graficas">
                    <section class="mt-panel">
                        <h3>Incidencias por prioridad</h3>
                        <div class="mt-lienzo"><canvas id="chartPrioridad"></canvas></div>
                    </section>
                    <section class="mt-panel">
                        <h3>Incidencias por categoría</h3>
                        <div class="mt-lienzo"><canvas id="chartCategoria"></canvas></div>
                    </section>
                    <section class="mt-panel mt-panel-ancho">
                        <h3>Reportadas vs. resueltas (últimos 6 meses)</h3>
                        <div class="mt-lienzo mt-lienzo-bajo"><canvas id="chartMes"></canvas></div>
                    </section>
                    <section class="mt-panel">
                        <h3>Activos con más incidencias</h3>
                        <div class="mt-lienzo"><canvas id="chartTop"></canvas></div>
                    </section>
                    <section class="mt-panel">
                        <h3>Tiempos y SLA por prioridad</h3>
                        <div class="contenedor-tabla" style="max-height:260px;margin-top:0;">
                            <table class="tabla-activos" id="tablaSla">
                                <thead>
                                    <tr><th>Prioridad</th><th>SLA</th><th>Resueltas</th><th>MTTR</th><th>Cumple</th></tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </section>
                </div>

                <p class="mt-nota">
                    <strong>Definiciones:</strong> MTTR = promedio de horas entre el reporte y la resolución.
                    Primera respuesta = horas hasta que un administrador atiende la incidencia por primera vez.
                    SLA = tiempo objetivo de resolución según prioridad, en horas corridas (no hábiles).
                    Con pocos equipos, los promedios se leen junto con la mediana.
                </p>
            </section>
            <?php endif; ?>
        </div>
    </div>

    <datalist id="listaActivos"></datalist>

    <!-- ══════════ Modal: nueva incidencia ══════════ -->
    <div id="modalInc" class="modal" role="dialog" aria-modal="true" aria-labelledby="tituloModalInc">
        <div class="modal-contenido" style="max-width:640px;">
            <h2 id="tituloModalInc">Nueva incidencia</h2>
            <form id="formInc" autocomplete="off">
                <div class="formulario-cuadricula">
                    <div class="ancho-completo">
                        <label for="inc-titulo">Título</label>
                        <input type="text" id="inc-titulo" maxlength="120" required placeholder="Ej. La laptop no enciende">
                    </div>
                    <div>
                        <label for="inc-activo">Activo afectado (opcional)</label>
                        <input type="text" id="inc-activo" list="listaActivos" placeholder="ID del activo">
                    </div>
                    <div>
                        <label for="inc-categoria">Categoría</label>
                        <select id="inc-categoria" required>
                            <option value="hardware">Hardware</option>
                            <option value="software">Software</option>
                            <option value="red">Red</option>
                            <option value="periferico">Periférico</option>
                            <option value="otro">Otro</option>
                        </select>
                    </div>
                    <div>
                        <label for="inc-prioridad">Prioridad</label>
                        <select id="inc-prioridad" required>
                            <option value="baja">Baja</option>
                            <option value="media" selected>Media</option>
                            <option value="alta">Alta</option>
                            <option value="critica">Crítica</option>
                        </select>
                    </div>
                    <div class="ancho-completo">
                        <label for="inc-descripcion">Descripción del problema</label>
                        <textarea id="inc-descripcion" maxlength="2000" required placeholder="¿Qué pasó, cuándo y qué se intentó?"></textarea>
                    </div>
                </div>
                <div id="msgInc" class="mt-msg"></div>
                <div class="formulario-botones">
                    <button type="button" id="cancelarInc">Cancelar</button>
                    <button type="submit" id="guardarInc">Enviar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ══════════ Modal: detalle / atender incidencia ══════════ -->
    <div id="modalDetalle" class="modal" role="dialog" aria-modal="true" aria-labelledby="tituloDetalle">
        <div class="modal-contenido" style="max-width:720px;">
            <h2 id="tituloDetalle">Incidencia</h2>
            <div id="detalleResumen"></div>
            <h3 class="mt-sub">Historial</h3>
            <div class="mt-timeline" id="detalleHistorial"></div>

            <?php if (esAdmin()): ?>
            <h3 class="mt-sub">Atender</h3>
            <form id="formAtender" autocomplete="off">
                <input type="hidden" id="at-id">
                <div class="formulario-cuadricula">
                    <div>
                        <label for="at-estado">Estado</label>
                        <select id="at-estado">
                            <option value="abierta">Abierta</option>
                            <option value="en_proceso">En proceso</option>
                            <option value="resuelta">Resuelta</option>
                        </select>
                    </div>
                    <div>
                        <label for="at-tecnico">Técnico</label>
                        <select id="at-tecnico"><option value="">Sin asignar</option></select>
                    </div>
                    <div class="ancho-completo">
                        <label for="at-comentario">Comentario (queda en el historial)</label>
                        <textarea id="at-comentario" maxlength="1000" style="min-height:60px;"></textarea>
                    </div>
                    <div class="ancho-completo">
                        <label for="at-solucion">Solución (obligatoria para resolver)</label>
                        <textarea id="at-solucion" maxlength="2000" style="min-height:60px;"></textarea>
                    </div>
                </div>
                <div id="msgAtender" class="mt-msg"></div>
                <div class="formulario-botones">
                    <button type="button" id="cerrarDetalle">Cerrar</button>
                    <button type="submit" id="guardarAtender">Guardar cambios</button>
                </div>
            </form>
            <?php else: ?>
            <div class="formulario-botones">
                <button type="button" id="cerrarDetalle" style="background:#d9d9d9;color:#333;">Cerrar</button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (esAdmin()): ?>
    <!-- ══════════ Modal: programar mantenimiento ══════════ -->
    <div id="modalProg" class="modal" role="dialog" aria-modal="true" aria-labelledby="tituloModalProg">
        <div class="modal-contenido" style="max-width:560px;">
            <h2 id="tituloModalProg">Programar mantenimiento</h2>
            <form id="formProg" autocomplete="off">
                <div class="formulario-cuadricula">
                    <div>
                        <label for="prog-activo">Activo</label>
                        <input type="text" id="prog-activo" list="listaActivos" required placeholder="ID del activo">
                    </div>
                    <div>
                        <label for="prog-tipo">Tipo</label>
                        <select id="prog-tipo">
                            <option value="preventivo">Preventivo</option>
                            <option value="limpieza">Limpieza</option>
                            <option value="actualizacion">Actualización</option>
                            <option value="revision">Revisión</option>
                        </select>
                    </div>
                    <div class="ancho-completo">
                        <label for="prog-fecha">Fecha programada</label>
                        <input type="date" id="prog-fecha" required>
                    </div>
                    <div class="ancho-completo">
                        <label for="prog-desc">Descripción (opcional)</label>
                        <input type="text" id="prog-desc" maxlength="255" placeholder="Ej. Limpieza interna y cambio de pasta térmica">
                    </div>
                </div>
                <div id="msgProg" class="mt-msg"></div>
                <div class="formulario-botones">
                    <button type="button" id="cancelarProg">Cancelar</button>
                    <button type="submit" id="guardarProg">Programar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ══════════ Modal: completar mantenimiento ══════════ -->
    <div id="modalComp" class="modal" role="dialog" aria-modal="true" aria-labelledby="tituloModalComp">
        <div class="modal-contenido" style="max-width:520px;">
            <h2 id="tituloModalComp">Registrar mantenimiento realizado</h2>
            <form id="formComp" autocomplete="off">
                <input type="hidden" id="comp-id">
                <div class="formulario-cuadricula">
                    <div class="ancho-completo">
                        <label for="comp-fecha">Fecha realizada (vacío = hoy)</label>
                        <input type="date" id="comp-fecha">
                    </div>
                    <div class="ancho-completo">
                        <label for="comp-obs">Observaciones</label>
                        <textarea id="comp-obs" maxlength="1000" style="min-height:70px;"></textarea>
                    </div>
                </div>
                <div id="msgComp" class="mt-msg"></div>
                <div class="formulario-botones">
                    <button type="button" id="cancelarComp">Cancelar</button>
                    <button type="submit" id="guardarComp">Registrar</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script src="../recursos/js/peticiones.js"></script>
    <script src="../recursos/js/utilidades.js"></script>
    <script src="../recursos/js/mantenimiento.js?v=<?php echo filemtime(__DIR__ . '/../recursos/js/mantenimiento.js'); ?>"></script>
</body>
</html>
