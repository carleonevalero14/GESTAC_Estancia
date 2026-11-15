<?php
/**
 * Mantenimiento e incidencias — Servicio (independiente del CMDB).
 * Avance 8.
 *
 * Acciones GET (?accion=):
 *   listar            Incidencias (admin: todas; usuario: solo las suyas).
 *   detalle&id=       Incidencia + historial de estados.
 *   tecnicos          Administradores asignables como técnico (admin).
 *   metricas          Indicadores y datos de gráficas (admin).
 *   preventivos       Mantenimientos programados / realizados.
 *   exportar_excel    Incidencias con tiempos calculados (admin).
 *
 * Acciones POST (?accion=):
 *   crear                  Reporta una incidencia (cualquier sesión).
 *   actualizar             Cambia estado / técnico / solución (admin).
 *   programar_preventivo   Programa un mantenimiento (admin).
 *   completar_preventivo   Registra un mantenimiento realizado (admin).
 *   eliminar_preventivo    Borra uno aún no realizado (admin).
 */
ob_start();
require_once __DIR__ . '/../configuracion/sesion.php';
require_once __DIR__ . '/../configuracion/base_datos.php';
requerirSesionApi();

const INC_CATEGORIAS  = ['hardware', 'software', 'red', 'periferico', 'otro'];
const INC_PRIORIDADES = ['critica', 'alta', 'media', 'baja'];
const INC_ESTADOS     = ['abierta', 'en_proceso', 'resuelta'];
const MTTO_TIPOS      = ['preventivo', 'limpieza', 'actualizacion', 'revision'];
// Tiempo objetivo de resolución por prioridad (horas corridas, no hábiles).
const SLA_HORAS = ['critica' => 4, 'alta' => 8, 'media' => 24, 'baja' => 72];

$accion = $_GET['accion'] ?? ($_POST['accion'] ?? '');
$metodo = $_SERVER['REQUEST_METHOD'];

/** Responde JSON y termina. */
function responder(array $datos, int $codigo = 200): void {
    ob_clean();
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Corta la ejecución si el usuario no es administrador. */
function exigirAdmin(): void {
    if (!esAdmin()) responder(['exito' => false, 'mensaje' => 'Acción restringida a administradores'], 403);
}

function fechaValida(string $f): bool {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $f, $m)) return false;
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

function horas(?int $minutos): ?float {
    return $minutos === null ? null : round($minutos / 60, 1);
}

/** Agrega a una fila de incidencia los tiempos y el estado respecto al SLA. */
function enriquecer(array $f): array {
    $sla = SLA_HORAS[$f['prioridad']] ?? SLA_HORAS['media'];
    $f['sla_horas'] = $sla;
    if ($f['estado'] === 'resuelta') {
        $h = horas(isset($f['min_res']) ? (int) $f['min_res'] : null);
        $f['horas_resolucion'] = $h;
        $f['cumple_sla']       = $h !== null ? ($h <= $sla) : null;
        $f['fuera_sla']        = false;
    } else {
        $h = horas(isset($f['min_abierta']) ? (int) $f['min_abierta'] : null);
        $f['horas_abierta']    = $h;
        $f['fuera_sla']        = $h !== null && $h > $sla;
        $f['cumple_sla']       = null;
    }
    $f['horas_primera_respuesta'] = horas(isset($f['min_resp']) ? (int) $f['min_resp'] : null);
    unset($f['min_res'], $f['min_abierta'], $f['min_resp']);
    return $f;
}

/** Notifica a todos los administradores (no interrumpe el flujo si falla). */
function notificarAdmins(PDO $pdo, string $mensaje): void {
    try {
        $admins = $pdo->query("SELECT username FROM users WHERE rol = 'admin'")->fetchAll();
        $stmt   = $pdo->prepare("INSERT INTO notificaciones (destinatario, tipo, mensaje, url) VALUES (?, 'incidencia', ?, 'mantenimiento.php')");
        foreach ($admins as $a) {
            if ($a['username'] !== usuarioActual()) $stmt->execute([$a['username'], $mensaje]);
        }
    } catch (Throwable $e) { /* la notificación no debe romper la operación */ }
}

function notificarUsuario(PDO $pdo, string $destinatario, string $mensaje): void {
    if ($destinatario === '' || $destinatario === usuarioActual()) return;
    try {
        $pdo->prepare("INSERT INTO notificaciones (destinatario, tipo, mensaje, url) VALUES (?, 'incidencia', ?, 'mantenimiento.php')")
            ->execute([$destinatario, $mensaje]);
    } catch (Throwable $e) { /* idem */ }
}

function activoExiste(PDO $pdo, string $id): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM activos WHERE id_producto = ?");
    $stmt->execute([$id]);
    return (int) $stmt->fetchColumn() > 0;
}

const SQL_TIEMPOS = "TIMESTAMPDIFF(MINUTE, i.fecha_reporte, i.fecha_resolucion) AS min_res,
                     TIMESTAMPDIFF(MINUTE, i.fecha_reporte, NOW()) AS min_abierta,
                     TIMESTAMPDIFF(MINUTE, i.fecha_reporte, i.fecha_primera_respuesta) AS min_resp";

try {
    $pdo = obtenerConexion();

    // ===================== EXPORTAR EXCEL (admin) =====================
    if ($metodo === 'GET' && $accion === 'exportar_excel') {
        exigirAdmin();
        $stmt = $pdo->query(
            "SELECT i.*, a.nombre AS nombre_activo, " . SQL_TIEMPOS . "
             FROM incidencias i LEFT JOIN activos a ON a.id_producto = i.id_activo
             ORDER BY i.fecha_reporte DESC"
        );
        $filas = [];
        foreach ($stmt->fetchAll() as $f) {
            $f = enriquecer($f);
            $f['cumple_txt']  = $f['estado'] !== 'resuelta' ? '' : ($f['cumple_sla'] ? 'Sí' : 'No');
            $f['h_resol_txt'] = $f['horas_resolucion'] ?? '';
            $filas[] = $f;
        }
        header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
        header("Content-Disposition: attachment; filename=reporte_incidencias.xls");
        header("Pragma: no-cache");
        header("Expires: 0");
        echo generarExcelMtto('REPORTE DE INCIDENCIAS',
            ['ID', 'Activo', 'Equipo', 'Solicitante', 'Título', 'Categoría', 'Prioridad', 'Estado', 'Técnico',
             'Fecha reporte', 'Primera respuesta', 'Resolución', 'Horas a 1ª respuesta', 'Horas a resolución',
             'SLA (h)', 'Cumple SLA', 'Solución'],
            $filas,
            ['id', 'id_activo', 'nombre_activo', 'solicitante', 'titulo', 'categoria', 'prioridad', 'estado', 'tecnico',
             'fecha_reporte', 'fecha_primera_respuesta', 'fecha_resolucion', 'horas_primera_respuesta', 'h_resol_txt',
             'sla_horas', 'cumple_txt', 'solucion']
        );
        exit;
    }

    // ===================== LISTAR INCIDENCIAS =====================
    if ($metodo === 'GET' && $accion === 'listar') {
        $sql = "SELECT i.id, i.id_activo, i.solicitante, i.titulo, i.categoria, i.prioridad, i.estado,
                       i.tecnico, i.fecha_reporte, i.fecha_resolucion, a.nombre AS nombre_activo, " . SQL_TIEMPOS . "
                FROM incidencias i LEFT JOIN activos a ON a.id_producto = i.id_activo";
        if (esAdmin()) {
            $stmt = $pdo->query($sql . " ORDER BY (i.estado = 'resuelta'), FIELD(i.prioridad,'critica','alta','media','baja'), i.fecha_reporte DESC");
        } else {
            $stmt = $pdo->prepare($sql . " WHERE i.solicitante = ? ORDER BY (i.estado = 'resuelta'), i.fecha_reporte DESC");
            $stmt->execute([usuarioActual()]);
        }
        responder(['exito' => true, 'datos' => array_map('enriquecer', $stmt->fetchAll())]);
    }

    // ===================== DETALLE + HISTORIAL =====================
    if ($metodo === 'GET' && $accion === 'detalle') {
        $id   = (int) ($_GET['id'] ?? 0);
        $stmt = $pdo->prepare(
            "SELECT i.*, a.nombre AS nombre_activo, " . SQL_TIEMPOS . "
             FROM incidencias i LEFT JOIN activos a ON a.id_producto = i.id_activo WHERE i.id = ?"
        );
        $stmt->execute([$id]);
        $inc = $stmt->fetch();
        if (!$inc || (!esAdmin() && $inc['solicitante'] !== usuarioActual())) {
            responder(['exito' => false, 'mensaje' => 'Incidencia no encontrada'], 404);
        }
        $stmt = $pdo->prepare("SELECT fecha, usuario, estado_anterior, estado_nuevo, comentario
                               FROM incidencias_historial WHERE id_incidencia = ? ORDER BY fecha, id");
        $stmt->execute([$id]);
        responder(['exito' => true, 'datos' => ['incidencia' => enriquecer($inc), 'historial' => $stmt->fetchAll()]]);
    }

    // ===================== TÉCNICOS (admin) =====================
    if ($metodo === 'GET' && $accion === 'tecnicos') {
        exigirAdmin();
        $stmt = $pdo->query("SELECT username FROM users WHERE rol = 'admin' ORDER BY username");
        responder(['exito' => true, 'datos' => array_column($stmt->fetchAll(), 'username')]);
    }

    // ===================== MÉTRICAS (admin) =====================
    if ($metodo === 'GET' && $accion === 'metricas') {
        exigirAdmin();
        $filas = $pdo->query(
            "SELECT i.id_activo, i.categoria, i.prioridad, i.estado,
                    DATE_FORMAT(i.fecha_reporte, '%Y-%m') AS mes_reporte,
                    DATE_FORMAT(i.fecha_resolucion, '%Y-%m') AS mes_resolucion, " . SQL_TIEMPOS . "
             FROM incidencias i"
        )->fetchAll();

        $conteo = ['abierta' => 0, 'en_proceso' => 0, 'resuelta' => 0];
        $porCategoria = array_fill_keys(INC_CATEGORIAS, 0);
        $porPrioridad = array_fill_keys(INC_PRIORIDADES, 0);
        $porMesRep = []; $porMesRes = [];
        $minRes = []; $minResp = [];
        $cumplen = 0; $fueraSla = 0;
        $prio = [];
        foreach (INC_PRIORIDADES as $p) $prio[$p] = ['total' => 0, 'resueltas' => 0, 'min' => [], 'cumplen' => 0];

        foreach ($filas as $f) {
            $conteo[$f['estado']] = ($conteo[$f['estado']] ?? 0) + 1;
            if (isset($porCategoria[$f['categoria']])) $porCategoria[$f['categoria']]++;
            if (isset($porPrioridad[$f['prioridad']])) $porPrioridad[$f['prioridad']]++;
            $porMesRep[$f['mes_reporte']] = ($porMesRep[$f['mes_reporte']] ?? 0) + 1;
            if ($f['mes_resolucion'] !== null) $porMesRes[$f['mes_resolucion']] = ($porMesRes[$f['mes_resolucion']] ?? 0) + 1;
            if ($f['min_resp'] !== null) $minResp[] = (int) $f['min_resp'];

            $sla = SLA_HORAS[$f['prioridad']] ?? SLA_HORAS['media'];
            $prio[$f['prioridad']]['total']++;
            if ($f['estado'] === 'resuelta' && $f['min_res'] !== null) {
                $m = (int) $f['min_res'];
                $minRes[] = $m;
                $prio[$f['prioridad']]['resueltas']++;
                $prio[$f['prioridad']]['min'][] = $m;
                if ($m / 60 <= $sla) { $cumplen++; $prio[$f['prioridad']]['cumplen']++; }
            } elseif ($f['estado'] !== 'resuelta' && (int) $f['min_abierta'] / 60 > $sla) {
                $fueraSla++;
            }
        }

        $prom = fn(array $a) => $a ? round(array_sum($a) / count($a) / 60, 1) : null;
        $mediana = function (array $a) {
            if (!$a) return null;
            sort($a);
            $n = count($a);
            $m = $n % 2 ? $a[intdiv($n, 2)] : ($a[$n / 2 - 1] + $a[$n / 2]) / 2;
            return round($m / 60, 1);
        };
        $pct = fn(int $a, int $b) => $b > 0 ? round($a / $b * 100, 1) : null;

        $porPrioridadDetalle = [];
        foreach ($prio as $p => $d) {
            $porPrioridadDetalle[] = [
                'prioridad' => $p, 'sla_horas' => SLA_HORAS[$p], 'total' => $d['total'], 'resueltas' => $d['resueltas'],
                'mttr_horas' => $prom($d['min']), 'cumplimiento_sla' => $pct($d['cumplen'], $d['resueltas']),
            ];
        }

        // Últimos 6 meses (se parte de CURDATE() del servidor de BD para no depender de la zona horaria de PHP).
        $hoy  = (string) $pdo->query("SELECT CURDATE()")->fetchColumn();
        $mes  = new DateTime(substr($hoy, 0, 7) . '-01');
        $mes->modify('-5 months');
        $porMes = [];
        for ($i = 0; $i < 6; $i++) {
            $k = $mes->format('Y-m');
            $porMes[] = ['mes' => $k, 'reportadas' => $porMesRep[$k] ?? 0, 'resueltas' => $porMesRes[$k] ?? 0];
            $mes->modify('+1 month');
        }

        $top = $pdo->query(
            "SELECT i.id_activo, a.nombre, COUNT(*) AS total
             FROM incidencias i LEFT JOIN activos a ON a.id_producto = i.id_activo
             WHERE i.id_activo IS NOT NULL
             GROUP BY i.id_activo, a.nombre ORDER BY total DESC, i.id_activo LIMIT 5"
        )->fetchAll();

        $pv = $pdo->query(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(fecha_realizada IS NOT NULL), 0) AS realizados,
                    COALESCE(SUM(fecha_realizada IS NULL AND fecha_programada < CURDATE()), 0) AS vencidos,
                    COALESCE(SUM(fecha_realizada IS NULL AND fecha_programada BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)), 0) AS proximos,
                    COALESCE(SUM(fecha_realizada IS NOT NULL AND fecha_realizada <= fecha_programada), 0) AS a_tiempo
             FROM mantenimientos"
        )->fetch();

        $total = array_sum($conteo);
        responder(['exito' => true, 'kpis' => [
            'total'           => $total,
            'abiertas'        => $conteo['abierta'],
            'en_proceso'      => $conteo['en_proceso'],
            'resueltas'       => $conteo['resuelta'],
            'fuera_sla'       => $fueraSla,
            'mttr_horas'      => $prom($minRes),
            'mediana_horas'   => $mediana($minRes),
            'primera_resp_horas' => $prom($minResp),
            'cumplimiento_sla'   => $pct($cumplen, count($minRes)),
            'prev_total'      => (int) $pv['total'],
            'prev_realizados' => (int) $pv['realizados'],
            'prev_vencidos'   => (int) $pv['vencidos'],
            'prev_proximos'   => (int) $pv['proximos'],
            'prev_a_tiempo_pct' => $pct((int) $pv['a_tiempo'], (int) $pv['realizados']),
        ], 'sla_horas' => SLA_HORAS,
           'por_categoria' => $porCategoria, 'por_prioridad' => $porPrioridad,
           'por_prioridad_detalle' => $porPrioridadDetalle, 'por_mes' => $porMes, 'top_activos' => $top]);
    }

    // ===================== PREVENTIVOS: LISTAR =====================
    if ($metodo === 'GET' && $accion === 'preventivos') {
        $stmt = $pdo->query(
            "SELECT m.id, m.id_activo, m.tipo, m.descripcion, m.fecha_programada, m.fecha_realizada,
                    m.realizado_por, m.observaciones, a.nombre AS nombre_activo,
                    DATEDIFF(m.fecha_programada, CURDATE()) AS dias_restantes
             FROM mantenimientos m LEFT JOIN activos a ON a.id_producto = m.id_activo
             ORDER BY (m.fecha_realizada IS NOT NULL), m.fecha_programada, m.id"
        );
        $datos = [];
        foreach ($stmt->fetchAll() as $f) {
            $d = (int) $f['dias_restantes'];
            if ($f['fecha_realizada'] !== null) $f['estado'] = 'realizado';
            elseif ($d < 0)                      $f['estado'] = 'vencido';
            elseif ($d <= 7)                     $f['estado'] = 'proximo';
            else                                 $f['estado'] = 'programado';
            $datos[] = $f;
        }
        responder(['exito' => true, 'datos' => $datos]);
    }

    // =====================================================================
    // A partir de aquí solo POST
    // =====================================================================
    if ($metodo !== 'POST') responder(['exito' => false, 'mensaje' => 'Acción no reconocida'], 400);

    // ===================== CREAR INCIDENCIA =====================
    if ($accion === 'crear') {
        $idActivo  = trim($_POST['id_activo']   ?? '');
        $titulo    = trim($_POST['titulo']      ?? '');
        $desc      = trim($_POST['descripcion'] ?? '');
        $categoria = $_POST['categoria'] ?? '';
        $prioridad = $_POST['prioridad'] ?? 'media';

        if ($titulo === '' || $desc === '') responder(['exito' => false, 'mensaje' => 'Título y descripción son obligatorios']);
        if (mb_strlen($titulo) > 120)       responder(['exito' => false, 'mensaje' => 'El título no puede pasar de 120 caracteres']);
        if (mb_strlen($desc) > 2000)        responder(['exito' => false, 'mensaje' => 'La descripción no puede pasar de 2000 caracteres']);
        if (!in_array($categoria, INC_CATEGORIAS, true))  responder(['exito' => false, 'mensaje' => 'Categoría inválida']);
        if (!in_array($prioridad, INC_PRIORIDADES, true)) responder(['exito' => false, 'mensaje' => 'Prioridad inválida']);
        if ($idActivo !== '' && !activoExiste($pdo, $idActivo)) responder(['exito' => false, 'mensaje' => 'El activo indicado no existe']);

        $pdo->beginTransaction();
        $pdo->prepare(
            "INSERT INTO incidencias (id_activo, solicitante, titulo, descripcion, categoria, prioridad)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$idActivo !== '' ? $idActivo : null, usuarioActual(), $titulo, $desc, $categoria, $prioridad]);
        $idNueva = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO incidencias_historial (id_incidencia, usuario, estado_anterior, estado_nuevo, comentario)
             VALUES (?, ?, NULL, 'abierta', 'Incidencia reportada')"
        )->execute([$idNueva, usuarioActual()]);
        $pdo->commit();

        registrarLog($pdo, 'incidencias', 'INSERT', $idNueva, "Incidencia [$prioridad] «$titulo» por " . usuarioActual());
        notificarAdmins($pdo, "🛠 " . usuarioActual() . " reportó una incidencia $prioridad: $titulo");
        responder(['exito' => true, 'mensaje' => 'Incidencia registrada', 'id' => $idNueva]);
    }

    // ===================== ACTUALIZAR INCIDENCIA (admin) =====================
    if ($accion === 'actualizar') {
        exigirAdmin();
        $id         = (int) ($_POST['id'] ?? 0);
        $estado     = $_POST['estado'] ?? '';
        $tecnico    = trim($_POST['tecnico']    ?? '');
        $comentario = trim($_POST['comentario'] ?? '');
        $solucion   = trim($_POST['solucion']   ?? '');

        if ($id <= 0 || !in_array($estado, INC_ESTADOS, true)) responder(['exito' => false, 'mensaje' => 'Datos inválidos']);
        if (mb_strlen($comentario) > 1000 || mb_strlen($solucion) > 2000) responder(['exito' => false, 'mensaje' => 'Texto demasiado largo']);
        if ($tecnico !== '') {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND rol = 'admin'");
            $chk->execute([$tecnico]);
            if ((int) $chk->fetchColumn() === 0) responder(['exito' => false, 'mensaje' => 'El técnico debe ser un administrador']);
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT * FROM incidencias WHERE id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $actual = $stmt->fetch();
        if (!$actual) { $pdo->rollBack(); responder(['exito' => false, 'mensaje' => 'Incidencia no encontrada'], 404); }

        // Al tomar la incidencia sin indicar técnico, se asigna quien la atiende.
        if ($tecnico === '') $tecnico = (string) ($actual['tecnico'] ?? '');
        if ($tecnico === '' && $estado !== 'abierta') $tecnico = usuarioActual();

        if ($solucion === '') $solucion = (string) ($actual['solucion'] ?? '');
        if ($estado === 'resuelta' && $solucion === '') {
            $pdo->rollBack();
            responder(['exito' => false, 'mensaje' => 'Describe la solución para marcarla como resuelta']);
        }

        $cambioEstado  = $estado !== $actual['estado'];
        $cambioTecnico = $tecnico !== (string) ($actual['tecnico'] ?? '');
        $cambioSol     = $solucion !== (string) ($actual['solucion'] ?? '');
        if (!$cambioEstado && !$cambioTecnico && !$cambioSol && $comentario === '') {
            $pdo->rollBack();
            responder(['exito' => false, 'mensaje' => 'No hay cambios que guardar']);
        }

        // Primera respuesta = primera vez que un administrador atiende la incidencia.
        // Resolución: se conserva la fecha original si ya estaba resuelta; se limpia si se reabre.
        $pdo->prepare(
            "UPDATE incidencias SET
                estado = ?, tecnico = ?, solucion = ?,
                fecha_primera_respuesta = COALESCE(fecha_primera_respuesta, NOW()),
                fecha_resolucion = CASE WHEN ? = 'resuelta' THEN COALESCE(fecha_resolucion, NOW()) ELSE NULL END
             WHERE id = ?"
        )->execute([$estado, $tecnico !== '' ? $tecnico : null, $solucion !== '' ? $solucion : null, $estado, $id]);

        $nota = $comentario;
        if ($cambioTecnico && $tecnico !== '') $nota = trim("Técnico asignado: $tecnico. $comentario");
        if ($cambioEstado || $nota !== '') {
            $pdo->prepare(
                "INSERT INTO incidencias_historial (id_incidencia, usuario, estado_anterior, estado_nuevo, comentario)
                 VALUES (?, ?, ?, ?, ?)"
            )->execute([$id, usuarioActual(), $actual['estado'], $estado, $nota !== '' ? $nota : null]);
        }
        $pdo->commit();

        $etiquetas = ['abierta' => 'abierta', 'en_proceso' => 'en proceso', 'resuelta' => 'resuelta'];
        if ($cambioEstado) {
            notificarUsuario($pdo, $actual['solicitante'], "🛠 Tu incidencia «{$actual['titulo']}» ahora está {$etiquetas[$estado]}");
        }
        registrarLog($pdo, 'incidencias', 'UPDATE', $id, "Incidencia $id: {$actual['estado']} → $estado por " . usuarioActual());
        responder(['exito' => true, 'mensaje' => 'Incidencia actualizada']);
    }

    // ===================== PROGRAMAR PREVENTIVO (admin) =====================
    if ($accion === 'programar_preventivo') {
        exigirAdmin();
        $idActivo = trim($_POST['id_activo'] ?? '');
        $tipo     = $_POST['tipo'] ?? 'preventivo';
        $desc     = trim($_POST['descripcion'] ?? '');
        $fecha    = trim($_POST['fecha_programada'] ?? '');

        if ($idActivo === '' || !activoExiste($pdo, $idActivo)) responder(['exito' => false, 'mensaje' => 'Selecciona un activo válido']);
        if (!in_array($tipo, MTTO_TIPOS, true))  responder(['exito' => false, 'mensaje' => 'Tipo inválido']);
        if (!fechaValida($fecha))                responder(['exito' => false, 'mensaje' => 'Fecha inválida']);
        if (mb_strlen($desc) > 255)              responder(['exito' => false, 'mensaje' => 'Descripción demasiado larga']);

        $pdo->prepare(
            "INSERT INTO mantenimientos (id_activo, tipo, descripcion, fecha_programada, creado_por) VALUES (?, ?, ?, ?, ?)"
        )->execute([$idActivo, $tipo, $desc !== '' ? $desc : null, $fecha, usuarioActual()]);
        $id = (int) $pdo->lastInsertId();
        registrarLog($pdo, 'mantenimientos', 'INSERT', $id, "Mantenimiento $tipo de $idActivo programado para $fecha");
        responder(['exito' => true, 'mensaje' => 'Mantenimiento programado']);
    }

    // ===================== COMPLETAR PREVENTIVO (admin) =====================
    if ($accion === 'completar_preventivo') {
        exigirAdmin();
        $id    = (int) ($_POST['id'] ?? 0);
        $obs   = trim($_POST['observaciones'] ?? '');
        $fecha = trim($_POST['fecha_realizada'] ?? '');
        $hoy   = (string) $pdo->query("SELECT CURDATE()")->fetchColumn();

        if ($id <= 0) responder(['exito' => false, 'mensaje' => 'Datos inválidos']);
        if ($fecha === '') $fecha = $hoy;
        if (!fechaValida($fecha) || $fecha > $hoy) responder(['exito' => false, 'mensaje' => 'La fecha realizada no es válida']);
        if (mb_strlen($obs) > 1000) responder(['exito' => false, 'mensaje' => 'Observaciones demasiado largas']);

        $stmt = $pdo->prepare(
            "UPDATE mantenimientos SET fecha_realizada = ?, realizado_por = ?, observaciones = ?
             WHERE id = ? AND fecha_realizada IS NULL"
        );
        $stmt->execute([$fecha, usuarioActual(), $obs !== '' ? $obs : null, $id]);
        if ($stmt->rowCount() === 0) responder(['exito' => false, 'mensaje' => 'El mantenimiento no existe o ya fue registrado']);
        registrarLog($pdo, 'mantenimientos', 'UPDATE', $id, "Mantenimiento $id realizado el $fecha por " . usuarioActual());
        responder(['exito' => true, 'mensaje' => 'Mantenimiento registrado como realizado']);
    }

    // ===================== ELIMINAR PREVENTIVO (admin) =====================
    if ($accion === 'eliminar_preventivo') {
        exigirAdmin();
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM mantenimientos WHERE id = ? AND fecha_realizada IS NULL");
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) responder(['exito' => false, 'mensaje' => 'Solo se pueden eliminar mantenimientos pendientes']);
        registrarLog($pdo, 'mantenimientos', 'DELETE', $id, "Mantenimiento $id eliminado por " . usuarioActual());
        responder(['exito' => true, 'mensaje' => 'Mantenimiento eliminado']);
    }

    responder(['exito' => false, 'mensaje' => 'Acción no reconocida'], 400);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    responder(['exito' => false, 'mensaje' => 'Error en el servidor'], 500);
}

// ===================== FUNCIÓN AUXILIAR EXCEL =====================
function generarExcelMtto(string $titulo, array $encabezados, array $filas, array $columnas): string {
    $e = fn($s) => htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
    $html = '<html><head><meta charset="UTF-8"><style>
        table { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; }
        th { background-color: #2f6b2f; color: white; padding: 8px; border: 1px solid black; text-align: center; }
        td { border: 1px solid black; padding: 6px; }
        tr:nth-child(even) { background-color: #e6ffe6; }
        .titulo { font-size: 18px; font-weight: bold; text-align: center; margin-bottom: 10px; }
    </style></head><body><div class="titulo">' . $e($titulo) . '</div><table><tr>';
    foreach ($encabezados as $enc) $html .= '<th>' . $e($enc) . '</th>';
    $html .= '</tr>';
    foreach ($filas as $fila) {
        $html .= '<tr>';
        foreach ($columnas as $col) $html .= '<td>' . $e($fila[$col] ?? '') . '</td>';
        $html .= '</tr>';
    }
    return $html . '</table></body></html>';
}
