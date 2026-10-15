<?php
// Los contadores de pendientes los calcula BaseController::render() (la vista no consulta nada).
$rol = Auth::rol();
$regPend    = (int) ($_pendientes['registros'] ?? 0);
$pendientes = (int) ($_pendientes['correcciones'] ?? 0);
?>
<aside class="sidebar" data-sidebar>
  <a class="brand" href="/">
    <span class="logo-mark">
      <img src="/assets/img/flexarena-logo.svg" alt="FlexArena">
    </span>
    <span><?= View::e($_sitio['nombre']) ?></span>
  </a>

  <nav aria-label="Menú del panel">
  <a class="side-link" href="/">Vista pública <span>↗</span></a>

  <?php if ($rol === 'administrador'): ?>
  <div class="side-section-title">Admin</div>
  <a class="side-link" href="/admin"><span>Panel admin</span><span>›</span></a>

  <div class="side-section-title">Gestión</div>
  <a class="side-link" href="/admin/participantes"><span>Participantes</span><span>›</span></a>
  <a class="side-link" href="/admin/registros">
    <span>Registros</span>
    <?php if ($regPend > 0): ?>
      <span class="chip warning chip-menu"><?= $regPend ?></span>
    <?php else: ?><span>›</span><?php endif; ?>
  </a>
  <a class="side-link" href="/admin/equipos"><span>Equipos</span><span>›</span></a>
  <a class="side-link" href="/admin/torneos"><span>Torneos</span><span>›</span></a>
  <a class="side-link" href="/admin/organizadores"><span>Organizadores</span><span>›</span></a>
  <a class="side-link" href="/admin/administradores"><span>Administradores</span><span>›</span></a>
  <a class="side-link" href="/admin/correcciones">
    <span>Correcciones</span>
    <?php if ($pendientes > 0): ?>
      <span class="chip warning chip-menu"><?= $pendientes ?></span>
    <?php else: ?><span>›</span><?php endif; ?>
  </a>

  <div class="side-section-title">Sistema</div>
  <a class="side-link" href="/admin/modulos"><span>Módulos</span><span>›</span></a>
  <a class="side-link" href="/admin/permisos"><span>Permisos</span><span>›</span></a>
  <a class="side-link" href="/admin/configuracion"><span>Configuración</span><span>›</span></a>
  <a class="side-link" href="/admin/reportes"><span>Reportes</span><span>›</span></a>
  <a class="side-link" href="/admin/auditoria"><span>Auditoría</span><span>›</span></a>

  <div class="side-section-title">Otros roles</div>
  <a class="side-link" href="/organizador"><span>Panel organizador</span><span>›</span></a>
  <a class="side-link" href="/participante"><span>Panel participante</span><span>›</span></a>

  <?php elseif ($rol === 'organizador'): ?>
  <div class="side-section-title">Organizador</div>
  <a class="side-link" href="/organizador"><span>Panel organizador</span><span>›</span></a>
  <a class="side-link" href="/organizador/torneos"><span>Mis torneos</span><span>›</span></a>

  <?php elseif ($rol === 'participante'): ?>
  <div class="side-section-title">Participante</div>
  <a class="side-link" href="/participante"><span>Mi panel</span><span>›</span></a>
  <a class="side-link" href="/participante/torneos"><span>Mis torneos</span><span>›</span></a>
  <a class="side-link" href="/participante/perfil"><span>Mi perfil</span><span>›</span></a>
  <?php endif; ?>
  </nav>
</aside>
