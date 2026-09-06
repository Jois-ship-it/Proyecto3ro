-- Migration: Add failed_attempts column and 'bloqueada' estado to usuarios
ALTER TABLE usuarios
  ADD COLUMN failed_attempts INT NOT NULL DEFAULT 0 AFTER password_hash;

-- Extend enum to include 'bloqueada'
ALTER TABLE usuarios
  MODIFY COLUMN estado ENUM('pendiente','activo','inactivo','suspendido','rechazado','bloqueada') NOT NULL DEFAULT 'activo';

-- Nota: index opcional para consultas por estado puede agregarse manualmente si se desea.
