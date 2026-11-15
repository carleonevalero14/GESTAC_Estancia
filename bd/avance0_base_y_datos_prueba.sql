-- =====================================================================
-- GESTAC — Avance 0: tablas base y datos de PRUEBA (solo desarrollo)
-- ---------------------------------------------------------------------
-- Crea la base GESTAC con las tablas que el sistema ya espera
-- (activos, usuarios, users, logs, inventario_perifericos, prestamos)
-- y carga datos de ejemplo. Úsalo SOLO si no tienes un respaldo de la
-- base real. Después ejecuta, en orden: avance1, avance4, avance5,
-- avance6, avance7 y avance8.
--
-- Cuentas de prueba (CAMBIAR en cualquier uso real):
--   admin@gestac.local    contraseña: Admin123!    rol: admin
--   usuario@gestac.local  contraseña: Usuario123!  rol: user
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `GESTAC` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `GESTAC`;

CREATE TABLE IF NOT EXISTS `users` (
  `ID`       INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(100) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `rol`      VARCHAR(20)  NOT NULL DEFAULT 'user'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `activos` (
  `id_producto`    VARCHAR(50)  NOT NULL PRIMARY KEY,
  `nombre`         VARCHAR(150) NULL,
  `categoria`      VARCHAR(50)  NULL,
  `procesador`     VARCHAR(100) NULL,
  `ram`            VARCHAR(50)  NULL,
  `almacenamiento` VARCHAR(50)  NULL,
  `lote`           VARCHAR(50)  NULL,
  `marca`          VARCHAR(80)  NULL,
  `descripcion`    VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `usuarios` (
  `nomina`    VARCHAR(30)  NOT NULL PRIMARY KEY,
  `nombre`    VARCHAR(120) NULL,
  `ubicacion` VARCHAR(120) NULL,
  `activo`    VARCHAR(50)  NULL,
  `fecha`     DATE         NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `logs` (
  `id`          INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `fecha`       DATETIME DEFAULT CURRENT_TIMESTAMP,
  `tabla`       VARCHAR(50)  NULL,
  `accion`      VARCHAR(30)  NULL,
  `registro_id` VARCHAR(100) NULL,
  `descripcion` VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `inventario_perifericos` (
  `id`     INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `nombre` VARCHAR(120) NULL,
  `S/N`    VARCHAR(80)  NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `prestamos` (
  `id`               INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `usuario`          VARCHAR(120) NULL,
  `periferico`       VARCHAR(120) NULL,
  `fecha_prestamo`   DATE NULL,
  `fecha_devolucion` DATE NULL,
  `estado`           VARCHAR(30) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Datos de prueba ──────────────────────────────────────────────────
INSERT IGNORE INTO `users` (`username`, `password`, `rol`) VALUES
 ('admin@gestac.local',   '$2y$10$i962Psw.bVjEnyUVuwVtm.WuJ1kekL0wkUulWaaTBWIyVWCVYUnmW', 'admin'),
 ('usuario@gestac.local', '$2y$10$ltffCYBNBRbymv5SItwr7.4Q8s9hxuRItwpAUyHGlQVYVi7WJZ3SW', 'user');

INSERT IGNORE INTO `activos` (`id_producto`,`nombre`,`categoria`,`procesador`,`ram`,`almacenamiento`,`lote`,`marca`,`descripcion`) VALUES
 ('A001','Laptop Dell Latitude','Laptop','Intel Core i5','8 GB','256 GB SSD','L1','Dell','Laptop de oficina'),
 ('A002','Laptop HP ProBook','Laptop','Intel Core i7','16 GB','512 GB SSD','L1','HP','Laptop de desarrollo'),
 ('A003','PC Lenovo ThinkCentre','PC','Intel Core i5','8 GB','500 GB HDD','L2','Lenovo','Equipo de escritorio'),
 ('A004','PC HP EliteDesk','PC','Intel Core i3','4 GB','500 GB HDD','L2','HP','Equipo de escritorio'),
 ('A005','Laptop Lenovo ThinkPad','Laptop','AMD Ryzen 5','16 GB','512 GB SSD','L3','Lenovo','Laptop de soporte'),
 ('A006','Monitor Samsung 24"','Monitor',NULL,NULL,NULL,'L3','Samsung','Monitor');

INSERT IGNORE INTO `usuarios` (`nomina`,`nombre`,`ubicacion`,`activo`,`fecha`) VALUES
 ('1001','Ana López','Oficina 1','A001','2026-01-15'),
 ('1002','Luis Pérez','Oficina 2','A002','2026-02-03'),
 ('1003','María Gómez','Laboratorio','A003','2026-02-20');

INSERT IGNORE INTO `inventario_perifericos` (`nombre`,`S/N`) VALUES
 ('Mouse Logitech','SN-0001'),('Teclado Dell','SN-0002'),('Diadema USB','SN-0003');
