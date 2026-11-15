-- =====================================================
-- Avance 7: Dashboard de sistemas operativos (independiente del CMDB)
-- Agrega a `activos` el sistema operativo capturado a mano.
-- Idempotente (XAMPP/MariaDB). Ejecutar sobre la base GESTAC.
-- =====================================================

ALTER TABLE activos
  ADD COLUMN IF NOT EXISTS so_nombre  VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS so_version VARCHAR(80)  NULL;
