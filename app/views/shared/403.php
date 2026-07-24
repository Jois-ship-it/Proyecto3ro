<section class="section" style="min-height:70vh;display:grid;place-items:center;text-align:center">
  <div>
    <div class="eyebrow">Error 403</div>
    <h1>Sin permisos</h1>
    <p>No tenés permisos para acceder a esta sección.</p>
    <div class="actions" style="justify-content:center;margin-top:1.5rem">
      <?php if (Auth::isLoggedIn()): ?>
        <a class="btn primary" href="<?= Auth::isAdmin() ? '/admin' : (Auth::isOrganizador() ? '/organizador' : '/participante') ?>">Ir al panel</a>
      <?php endif; ?>
      <a class="btn" href="/">Ir al inicio</a>
    </div>
  </div>
</section>
