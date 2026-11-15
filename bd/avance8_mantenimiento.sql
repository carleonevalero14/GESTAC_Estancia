-- =====================================================
-- Avance 8: Mantenimiento e incidencias (independiente del CMDB)
-- 3 tablas: incidencias, historial de estados y mantenimientos preventivos.
-- Idempotente. Ejecutar sobre la base GESTAC.
-- =====================================================

-- ===================== 1. INCIDENCIAS (tickets) =====================
CREATE TABLE IF NOT EXISTS incidencias (
  id                      INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  id_activo               VARCHAR(50)  NULL,                       -- activos.id_producto (se valida en PHP)
  solicitante             VARCHAR(50)  NOT NULL,                   -- users.username de quien reporta
  titulo                  VARCHAR(120) NOT NULL,
  descripcion             TEXT         NOT NULL,
  categoria               VARCHAR(20)  NOT NULL,                   -- hardware / software / red / periferico / otro
  prioridad               VARCHAR(10)  NOT NULL DEFAULT 'media',   -- critica / alta / media / baja
  estado                  VARCHAR(12)  NOT NULL DEFAULT 'abierta', -- abierta / en_proceso / resuelta
  tecnico                 VARCHAR(50)  NULL,
  fecha_reporte           DATETIME     DEFAULT CURRENT_TIMESTAMP,
  fecha_primera_respuesta DATETIME     NULL,
  fecha_resolucion        DATETIME     NULL,
  solucion                TEXT         NULL,
  KEY idx_inc_estado (estado, prioridad),
  KEY idx_inc_activo (id_activo),
  KEY idx_inc_solicitante (solicitante),
  KEY idx_inc_fecha (fecha_reporte)
) DEFAULT CHARSET=utf8mb4;

-- ===================== 2. HISTORIAL DE ESTADOS (trazabilidad) =====================
CREATE TABLE IF NOT EXISTS incidencias_historial (
  id              INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  id_incidencia   INT NOT NULL,
  fecha           DATETIME DEFAULT CURRENT_TIMESTAMP,
  usuario         VARCHAR(50) NOT NULL,
  estado_anterior VARCHAR(12) NULL,
  estado_nuevo    VARCHAR(12) NOT NULL,
  comentario      TEXT NULL,
  KEY idx_hist_inc (id_incidencia, fecha),
  CONSTRAINT fk_hist_incidencia FOREIGN KEY (id_incidencia)
    REFERENCES incidencias (id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4;

-- ===================== 3. MANTENIMIENTOS PREVENTIVOS =====================
CREATE TABLE IF NOT EXISTS mantenimientos (
  id               INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  id_activo        VARCHAR(50)  NOT NULL,
  tipo             VARCHAR(30)  NOT NULL DEFAULT 'preventivo',
  descripcion      VARCHAR(255) NULL,
  fecha_programada DATE         NOT NULL,
  fecha_realizada  DATE         NULL,
  realizado_por    VARCHAR(50)  NULL,
  observaciones    TEXT         NULL,
  creado_por       VARCHAR(50)  NOT NULL,
  fecha_creacion   DATETIME     DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mtto_fecha (fecha_realizada, fecha_programada),
  KEY idx_mtto_activo (id_activo)
) DEFAULT CHARSET=utf8mb4;
