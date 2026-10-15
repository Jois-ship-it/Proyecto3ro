<section class="section pagina-error">
  <div>
    <div class="eyebrow">Error 403</div>
    <h1>Sin permisos</h1>
    <p><?= View::e($mensaje ?? 'No tenés permisos para acceder a esta sección.') ?></p>
    <div class="actions justify-center mt-15">
      <?php if (Auth::isLoggedIn()): ?>
        <a class="btn primary" href="<?= Auth::isAdmin() ? '/admin' : (Auth::isOrganizador() ? '/organizador' : '/participante') ?>">Ir al panel</a>
      <?php endif; ?>
      <a class="btn" href="/">Ir al inicio</a>
    </div>
  </div>
</section>
