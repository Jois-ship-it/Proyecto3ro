<?php $user = Auth::user(); ?>
<header class="topbar">
  <button class="btn ghost mobile-menu-btn" data-sidebar-toggle aria-label="Menú">☰ Menú</button>
  <div>
    <strong><?= APP_NAME ?></strong>
    <div class="muted">
      <?php if (Auth::isAdmin()): ?>Panel de administración
      <?php elseif (Auth::isOrganizador()): ?>Panel del organizador
      <?php else: ?>Panel del participante
      <?php endif; ?>
    </div>
  </div>
  <div class="actions">
    <?php if ($user): ?>
      <span class="chip"><?= View::e($user['nombre']) ?></span>
      <span class="chip"><?= View::e(ucfirst($user['rol'])) ?></span>
    <?php endif; ?>
    <a class="btn small" href="/logout">Cerrar sesión</a>
  </div>
</header>
