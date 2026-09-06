<header class="public-nav" data-public-nav>
  <a class="brand" href="/">
    <span class="logo-mark">
      <img src="/assets/img/flexarena-logo.svg" alt="FlexArena">
    </span>
    <span><?= APP_NAME ?></span>
  </a>
  <button class="btn ghost nav-toggle" data-nav-toggle aria-label="Menú">Menú</button>
  <nav class="nav-links">
    <a class="nav-link" href="/">Inicio</a>
    <a class="nav-link" href="/torneos">Torneos</a>
    <?php if (Auth::isLoggedIn()): ?>
      <?php $rol = Auth::rol(); ?>
      <?php if ($rol === 'administrador'): ?>
        <a class="nav-link" href="/admin">Panel admin</a>
      <?php elseif ($rol === 'organizador'): ?>
        <a class="nav-link" href="/organizador">Panel organizador</a>
      <?php elseif ($rol === 'participante'): ?>
        <a class="nav-link" href="/participante">Mi panel</a>
      <?php endif; ?>
      <a class="btn small" href="/logout">Salir</a>
    <?php else: ?>
      <a class="nav-link" href="/registro">Registrarse</a>
      <a class="btn primary" href="/login">Ingresar</a>
    <?php endif; ?>
  </nav>
</header>
