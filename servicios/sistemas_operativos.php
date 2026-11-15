<?php
/**
 * Dashboard de sistemas operativos — Servicio (independiente del CMDB).
 * Avance 7. Lee de `activos` (columnas so_nombre / so_version, capturadas a
 * mano) y de `usuarios` para saber quién tiene asignado cada equipo.
 *
 * GET                        Datos del dashboard (kpis, gráficas y equipos).
 * GET  ?accion=exportar_excel  Reporte en Excel.
 * POST ?accion=guardar_so    Captura/edita el SO de un activo (solo admin).
 */
ob_start();
require_once __DIR__ . '/../configuracion/sesion.php';
require_once __DIR__ . '/../configuracion/base_datos.php';
requerirSesionApi();

// Familias cuyo soporte del fabricante ya terminó (Windows 10: 14-oct-2025).
const SO_SIN_SOPORTE = ['Windows 10', 'Windows 8', 'Windows 7'];
const SO_SIN_REGISTRO = 'Sin registrar';

/** Clasifica el texto de so_nombre en una familia legible. */
function familiaSO(?string $nombre): string {
    $n = strtolower(trim((string) $nombre));
    if ($n === '')                      return SO_SIN_REGISTRO;
    if (str_contains($n, 'server'))     return 'Windows Server';
    if (str_contains($n, 'windows 11')) return 'Windows 11';
    if (str_contains($n, 'windows 10')) return 'Windows 10';
    if (str_contains($n, 'windows 8'))  return 'Windows 8';
    if (str_contains($n, 'windows 7'))  return 'Windows 7';
    if (str_contains($n, 'windows'))    return 'Windows (otro)';
    if (str_contains($n, 'linux') || str_contains($n, 'ubuntu') || str_contains($n, 'debian')) return 'Linux';
    if (str_contains($n, 'mac'))        return 'macOS';
    return 'Otro';
}

/** Quita el prefijo "Microsoft " para mostrar nombres más cortos. */
function nombreCorto(?string $nombre): string {
    $n = trim((string) $nombre);
    return $n === '' ? SO_SIN_REGISTRO : trim(preg_replace('/^Microsoft\s+/i', '', $n));
}

$accion = $_GET['accion'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'];

try {
    $pdo = obtenerConexion();

    // ===================== POST: GUARDAR SO DE UN ACTIVO =====================
    if ($metodo === 'POST' && $accion === 'guardar_so') {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        if (!esAdmin()) {
            echo json_encode(['exito' => false, 'mensaje' => 'Acción restringida a administradores']);
            exit;
        }
        $id      = trim($_POST['id_producto'] ?? '');
        $nombre  = trim($_POST['so_nombre']   ?? '');
        $version = trim($_POST['so_version']  ?? '');
        if ($id === '') {
            echo json_encode(['exito' => false, 'mensaje' => 'Falta el activo']);
            exit;
        }
        if (mb_strlen($nombre) > 100 || mb_strlen($version) > 80) {
            echo json_encode(['exito' => false, 'mensaje' => 'Nombre o versión demasiado largos']);
            exit;
        }
        $chk = $pdo->prepare("SELECT COUNT(*) FROM activos WHERE id_producto = ?");
        $chk->execute([$id]);
        if ((int) $chk->fetchColumn() === 0) {
            echo json_encode(['exito' => false, 'mensaje' => 'El activo no existe']);
            exit;
        }
        $upd = $pdo->prepare("UPDATE activos SET so_nombre = ?, so_version = ? WHERE id_producto = ?");
        $upd->execute([$nombre !== '' ? $nombre : null, $version !== '' ? $version : null, $id]);
        registrarLog($pdo, 'activos', 'SO', $id, "SO de $id: " . ($nombre !== '' ? $nombre : '(vacío)') . " $version");
        echo json_encode(['exito' => true, 'mensaje' => 'Sistema operativo guardado']);
        exit;
    }

    // ===================== LECTURA DE EQUIPOS =====================
    $filas = $pdo->query(
        "SELECT a.id_producto, a.nombre, a.categoria, a.marca, a.so_nombre, a.so_version,
                u.nombre AS usuario
         FROM activos a
         LEFT JOIN usuarios u ON u.activo = a.id_producto
         ORDER BY a.id_producto"
    )->fetchAll();

    // ===================== EXPORTAR EXCEL =====================
    if ($metodo === 'GET' && $accion === 'exportar_excel') {
        header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
        header("Content-Disposition: attachment; filename=reporte_sistemas_operativos.xls");
        header("Pragma: no-cache");
        header("Expires: 0");

        $salida = [];
        foreach ($filas as $f) {
            $fam = familiaSO($f['so_nombre']);
            $salida[] = [
                'id'      => $f['id_producto'],
                'nombre'  => $f['nombre'],
                'cat'     => $f['categoria'],
                'usuario' => $f['usuario'],
                'so'      => nombreCorto($f['so_nombre']),
                'version' => $f['so_version'],
                'familia' => $fam,
                'soporte' => $fam === SO_SIN_REGISTRO ? 'Sin dato' : (in_array($fam, SO_SIN_SOPORTE, true) ? 'Sin soporte' : 'Vigente'),
            ];
        }
        echo generarExcelSO('REPORTE DE SISTEMAS OPERATIVOS',
            ['ID Activo', 'Nombre', 'Categoría', 'Usuario asignado', 'Sistema operativo', 'Versión', 'Familia', 'Soporte'],
            $salida,
            ['id', 'nombre', 'cat', 'usuario', 'so', 'version', 'familia', 'soporte']
        );
        exit;
    }

    ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    // ---------- Agregaciones (en PHP: pocos equipos) ----------
    $porFamilia = [];
    $porEdicion = [];
    $porVersion = [];
    $equipos    = [];
    $sinSoporte = 0;
    $sinRegistro = 0;

    foreach ($filas as $f) {
        $fam      = familiaSO($f['so_nombre']);
        $edicion  = nombreCorto($f['so_nombre']);
        $version  = trim((string) $f['so_version']);
        $obsoleto = in_array($fam, SO_SIN_SOPORTE, true);

        $porFamilia[$fam]     = ($porFamilia[$fam]     ?? 0) + 1;
        $porEdicion[$edicion] = ($porEdicion[$edicion] ?? 0) + 1;
        if ($fam !== SO_SIN_REGISTRO) {
            $clave = $fam . '|' . ($version !== '' ? $version : 'Sin versión');
            $porVersion[$clave] = ($porVersion[$clave] ?? 0) + 1;
        }
        if ($obsoleto)                 $sinSoporte++;
        if ($fam === SO_SIN_REGISTRO)  $sinRegistro++;

        $equipos[] = [
            'id_producto' => $f['id_producto'],
            'nombre'      => $f['nombre'],
            'categoria'   => $f['categoria'],
            'usuario'     => $f['usuario'],
            'so_nombre'   => $f['so_nombre'],
            'so_version'  => $f['so_version'],
            'so'          => $edicion,
            'familia'     => $fam,
            'sin_soporte' => $obsoleto,
        ];
    }

    arsort($porFamilia);
    arsort($porEdicion);
    arsort($porVersion);

    $lista = fn(array $m) => array_map(
        fn($k, $v) => ['nombre' => $k, 'total' => $v], array_keys($m), array_values($m)
    );

    $versiones = [];
    foreach ($porVersion as $clave => $n) {
        [$fam, $ver] = explode('|', $clave, 2);
        $versiones[] = ['familia' => $fam, 'version' => $ver, 'total' => $n];
    }

    $total      = count($equipos);
    $registrados = $total - $sinRegistro;
    // La familia predominante se calcula solo entre equipos con SO registrado.
    $conSO = $porFamilia;
    unset($conSO[SO_SIN_REGISTRO]);

    echo json_encode([
        'exito' => true,
        'kpis'  => [
            'total_equipos'   => $total,
            'registrados'     => $registrados,
            'pct_registrados' => $total > 0 ? round($registrados / $total * 100, 1) : 0,
            'familias'        => count($conSO),
            'sin_soporte'     => $sinSoporte,
            'pct_sin_soporte' => $registrados > 0 ? round($sinSoporte / $registrados * 100, 1) : 0,
            'sin_registro'    => $sinRegistro,
            'predominante'    => $conSO ? array_key_first($conSO) : '—',
        ],
        'por_familia' => $lista($porFamilia),
        'por_edicion' => $lista($porEdicion),
        'por_version' => $versiones,
        'equipos'     => $equipos,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['exito' => false, 'mensaje' => 'Error en el servidor']);
}

// ===================== FUNCIÓN AUXILIAR EXCEL =====================
function generarExcelSO(string $titulo, array $encabezados, array $filas, array $columnas): string {
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
