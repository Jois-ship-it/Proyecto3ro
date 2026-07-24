-- ============================================================
-- Migración: registro público de participantes con aprobación
-- Agrega estados 'pendiente' y 'rechazado' al ciclo de cuentas.
-- No destructiva (MODIFY conserva los datos existentes).
-- ============================================================
SET NAMES utf8mb4;

-- Estado de la CUENTA (gate de login)
ALTER TABLE usuarios
  MODIFY estado ENUM('pendiente','activo','inactivo','suspendido','rechazado')
  NOT NULL DEFAULT 'activo';

-- Estado del PARTICIPANTE (gate de inscripción)
ALTER TABLE participantes
  MODIFY estado ENUM('pendiente','activo','inactivo','suspendido','rechazado')
  NOT NULL DEFAULT 'activo';

SELECT 'Estados pendiente/rechazado agregados.' AS resultado;
