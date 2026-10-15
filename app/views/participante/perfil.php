<?php
$fotoUrl = $participante ? Upload::url('avatars', $participante['foto'] ?? null) : null;
$fechaReg = $participante['created_at'] ?? ($usuario['created_at'] ?? null);
?>
<section class="page-header">
  <div class="page-title">
    <div class="eyebrow">Participante</div>
    <h1>Mi perfil</h1>
  </div>
</section>

<?php if (!$participante): ?>
  <div class="card"><p class="muted">No tenés un perfil de participante vinculado a tu cuenta.</p></div>
<?php else: ?>

<!-- Cabecera del perfil -->
<div class="card mb-1">
  <div class="flex gap-125 items-center flex-wrap">
    <?= View::avatar($participante['nombre'], $fotoUrl, 92) ?>
    <div class="min-w-0 flex-1">
      <h2 class="m-0-0-025"><?= View::e($participante['nombre']) ?></h2>
      <div class="flex gap-05 flex-wrap items-center">
        <?php if (!empty($participante['nick'])): ?><span class="chip"><?= View::e($participante['nick']) ?></span><?php endif; ?>
        <?= View::estadoChip($participante['estado']) ?>
        <?php if (!empty($participante['email'])): ?><span class="muted"><?= View::e($participante['email']) ?></span><?php endif; ?>
      </div>
      <?php if ($fechaReg): ?>
        <div class="muted fs-85 mt-035">Miembro desde <?= View::e(substr((string)$fechaReg, 0, 10)) ?></div>
      <?php endif; ?>
    </div>
    <a class="btn" href="#editar">Editar perfil</a>
  </div>
</div>

<!-- Estadísticas e historial -->
<?php include APP_PATH . '/views/partials/perfil_stats.php'; ?>

<!-- Edición del perfil -->
<details class="form-card mt-1" id="editar">
  <summary class="resumen-desplegable">✏️ Editar mi información</summary>
  <form class="mt-1" method="POST" action="/participante/perfil" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <div class="form-grid">
      <div class="field span-all">
        <label for="perfil-foto">Foto de perfil</label>
        <div class="flex items-center gap-1">
          <?= View::avatar($participante['nombre'], $fotoUrl, 56) ?>
          <input id="perfil-foto" type="file" name="foto" accept="image/jpeg,image/png,image/webp,image/gif">
        </div>
        <span class="muted fs-80">JPG, PNG, WEBP o GIF. Máx 2 MB. Dejá vacío para conservar la actual.</span>
      </div>
      <div class="field">
        <label for="perfil-nombre">Nombre completo *</label>
        <input id="perfil-nombre" type="text" name="nombre" required value="<?= View::e($participante['nombre']) ?>">
      </div>
      <div class="field">
        <label for="perfil-nick">Nick / Alias</label>
        <input id="perfil-nick" type="text" name="nick" value="<?= View::e($participante['nick'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="perfil-email">Email</label>
        <input id="perfil-email" type="email" autocomplete="email" name="email" value="<?= View::e($participante['email'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="perfil-telefono">Teléfono</label>
        <input id="perfil-telefono" type="text" name="telefono" value="<?= View::e($participante['telefono'] ?? '') ?>">
      </div>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn primary">Guardar cambios</button>
    </div>
  </form>
</details>

<!-- Derecho de supresión — Habeas Data (ley 18331) -->
<details class="form-card panel-peligro">
  <summary class="resumen-peligro">Eliminar mis datos personales</summary>
  <div class="mt-1">
    <p class="m-0-0-075">
      De acuerdo a la <strong>ley Nº 18331 (Habeas Data)</strong>, tenés derecho a solicitar la supresión
      de tus datos personales. Esta acción:
    </p>
    <ul class="lista-peligro">
      <li>Elimina tu nombre, documento, email, teléfono, nick y foto del sistema.</li>
      <li>Anonimiza tu cuenta de acceso (ya no podrás iniciar sesión).</li>
      <li>Tu historial de participación en torneos queda registrado de forma anónima.</li>
      <li><strong>Esta acción es irreversible.</strong></li>
    </ul>
    <button type="button" class="btn btn-peligro-solido"
            data-abrir-modal="modal-habeas">
      Solicitar eliminación de mis datos
    </button>
  </div>
</details>

<!-- Modal de confirmación -->
<div class="modal-fondo modal-fondo--flex" id="modal-habeas">
  <div class="card modal-habeas-tarjeta" role="dialog" aria-modal="true" aria-labelledby="modalHabeasTitulo">
    <h3 class="titulo-peligro" id="modalHabeasTitulo">¿Confirmar eliminación de datos?</h3>
    <p class="texto-modal">
      Vas a eliminar <strong>permanentemente</strong> todos tus datos personales y tu acceso al sistema.
      Serás redirigido al inicio de sesión.
    </p>
    <form method="POST" action="/participante/perfil/eliminar-datos">
      <?= Csrf::field() ?>
      <div class="flex gap-075 justify-end">
        <button type="button" class="btn"
                data-cerrar-modal>Cancelar</button>
        <button type="submit" class="btn btn-peligro-solido">Sí, eliminar mis datos</button>
      </div>
    </form>
  </div>
</div>

<?php endif; ?>
