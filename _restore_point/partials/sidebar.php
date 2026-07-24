<?php $rol = Auth::rol(); ?>
<aside class="sidebar" data-sidebar>
  <a class="brand" href="/">
    <span class="logo-mark">
      <img src="/assets/img/fa-icon.svg" alt="FlexArena" width="26" height="26">
    </span>
    <span><?= APP_NAME ?></span>
  </a>

  <a class="side-link" href="/">Vista pública <span>↗</span></a>

  <?php if ($rol === 'administrador'): ?>
  <div class="side-section-title">Admin</div>
  <a class="side-link" href="/admin"><span>Panel admin</span><span>›</span></a>

  <div class="side-section-title">Gestión</div>
  <a class="side-link" href="/admin/participantes"><span>Participantes</span><span>›</span></a>
  <a class="side-link" href="/admin/equipos"><span>Equipos</span><span>›</span></a>
  <a class="side-link" href="/admin/torneos"><span>Torneos</span><span>›</span></a>
  <a class="side-link" href="/admin/usuarios"><span>Usuarios</span><span>›</span></a>

  <div class="side-section-title">Sistema</div>
  <a class="side-link" href="/admin/modulos"><span>Módulos</span><span>›</span></a>
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
</aside>
