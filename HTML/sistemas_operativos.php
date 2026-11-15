<?php require_once __DIR__ . '/../configuracion/sesion.php'; requerirSesion(); ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistemas Operativos — GESTAC</title>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;700;800&display=swap" rel="stylesheet">
    <script>(function(){var t=localStorage.getItem('gestac_tema');if(t&&t!=='original')document.documentElement.classList.add('tema-'+t);}());</script>
    <link rel="stylesheet" href="../recursos/css/base.css">
    <link rel="stylesheet" href="../recursos/css/temas.css">
    <link rel="stylesheet" href="../recursos/css/componentes.css">
    <link rel="stylesheet" href="../recursos/css/paginas.css">
    <link rel="stylesheet" href="../recursos/css/sistemas_operativos.css?v=<?php echo filemtime(__DIR__ . '/../recursos/css/sistemas_operativos.css'); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script>window.SO_ES_ADMIN = <?php echo esAdmin() ? 'true' : 'false'; ?>;</script>
</head>
<body style="background-image: url('../recursos/imagenes/fondo.png'); background-repeat: no-repeat; background-size: cover; background-position: center;">

    <div class="contenedor">
        <header class="encabezado" style="position: relative;">
            <button class="btn-volver" onclick="history.back()">←</button>
            <h1 class="titulo-pagina">SISTEMAS OPERATIVOS</h1>
        </header>

        <div class="cuerpo-pagina">
            <!-- ── KPIs ── -->
            <div class="so-kpis" id="soKpis">
                <div class="so-kpi"><div class="so-kpi-numero">—</div><div class="so-kpi-etiqueta">Equipos</div></div>
            </div>

            <!-- ── Gráficas ── -->
            <div class="so-graficas">
                <section class="so-panel">
                    <h3>Distribución por familia</h3>
                    <div class="so-lienzo"><canvas id="chartFamilia"></canvas></div>
                </section>
                <section class="so-panel">
                    <h3>Equipos por edición</h3>
                    <div class="so-lienzo"><canvas id="chartEdicion"></canvas></div>
                </section>
                <section class="so-panel so-panel-ancho">
                    <h3>Versiones / compilaciones registradas</h3>
                    <div class="so-lienzo so-lienzo-bajo"><canvas id="chartVersion"></canvas></div>
                </section>
            </div>

            <!-- ── Tabla ── -->
            <div class="encabezado-lista">
                <h2 class="titulo-lista">Activos y su sistema operativo</h2>
                <div class="barra-controles">
                    <button class="btn-exportar" onclick="exportarExcel()">Exportar a Excel</button>
                    <label class="caja-busqueda" aria-label="Buscar activo">
                        <span class="icono-busqueda" aria-hidden="true">🔎</span>
                        <input type="text" id="searchInput" placeholder="Buscar activo, usuario o versión">
                    </label>
                    <div class="desplegable">
                        <select id="filterFamilia" aria-label="Filtrar por familia">
                            <option value="">Todos</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="contenedor-tabla">
                <table class="tabla-activos" role="grid">
                    <colgroup>
                        <col style="width:12%">
                        <col style="width:20%">
                        <col style="width:17%">
                        <col style="width:20%">
                        <col style="width:13%">
                        <col style="width:10%">
                        <col style="width:8%">
                    </colgroup>
                    <thead>
                        <tr>
                            <th scope="col">ID activo</th>
                            <th scope="col">Equipo</th>
                            <th scope="col">Usuario</th>
                            <th scope="col">Sistema operativo</th>
                            <th scope="col">Versión</th>
                            <th scope="col">Soporte</th>
                            <th scope="col">Acción</th>
                        </tr>
                    </thead>
                    <tbody id="cuerpoSO">
                        <tr><td colspan="7" class="celda-vacia">Cargando activos...</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="pie-tabla">
                <span class="pagination-info">Mostrando Activos 0 de 0</span>
            </div>
        </div>
    </div>

    <!-- Modal: registrar / editar SO de un activo (solo administradores) -->
    <div id="modalSO" class="modal" role="dialog" aria-modal="true" aria-labelledby="tituloModalSO">
        <div class="modal-contenido" style="max-width:520px;">
            <h2 id="tituloModalSO">Sistema operativo</h2>
            <form id="formSO" autocomplete="off">
                <input type="hidden" id="so-id">
                <div class="formulario-cuadricula">
                    <div class="ancho-completo">
                        <label for="so-activo">Activo</label>
                        <input type="text" id="so-activo" readonly style="background:#e9ecef;cursor:not-allowed">
                    </div>
                    <div class="ancho-completo">
                        <label for="so-nombre">Sistema operativo</label>
                        <input type="text" id="so-nombre" list="listaSO" maxlength="100" placeholder="Ej. Windows 11 Pro">
                        <datalist id="listaSO">
                            <option value="Windows 11 Pro"></option>
                            <option value="Windows 11 Home"></option>
                            <option value="Windows 10 Pro"></option>
                            <option value="Windows 10 Home"></option>
                            <option value="Windows Server 2022"></option>
                            <option value="Windows Server 2019"></option>
                            <option value="Ubuntu 24.04 LTS"></option>
                            <option value="macOS"></option>
                        </datalist>
                    </div>
                    <div class="ancho-completo">
                        <label for="so-version">Versión / compilación (opcional)</label>
                        <input type="text" id="so-version" maxlength="80" placeholder="Ej. 24H2 (compilación 26100)">
                    </div>
                </div>
                <div id="msgSO" class="so-msg"></div>
                <div class="formulario-botones">
                    <button type="button" id="cancelarSO">Cancelar</button>
                    <button type="submit" id="guardarSO">Guardar</button>
                </div>
            </form>
        </div>
    </div>

    <script src="../recursos/js/peticiones.js"></script>
    <script src="../recursos/js/utilidades.js"></script>
    <script src="../recursos/js/sistemas_operativos.js?v=<?php echo filemtime(__DIR__ . '/../recursos/js/sistemas_operativos.js'); ?>"></script>
</body>
</html>
