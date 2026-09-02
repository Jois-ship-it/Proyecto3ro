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
<div class="card" style="margin-bottom:1rem">
  <div style="display:flex;gap:1.25rem;align-items:center;flex-wrap:wrap">
    <?= View::avatar($participante['nombre'], $fotoUrl, 92) ?>
    <div style="min-width:0;flex:1">
      <h2 style="margin:0 0 .25rem"><?= View::e($participante['nombre']) ?></h2>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
        <?php if (!empty($participante['nick'])): ?><span class="chip"><?= View::e($participante['nick']) ?></span><?php endif; ?>
        <?= View::estadoChip($participante['estado']) ?>
        <?php if (!empty($participante['email'])): ?><span class="muted"><?= View::e($participante['email']) ?></span><?php endif; ?>
      </div>
      <?php if ($fechaReg): ?>
        <div class="muted" style="font-size:.85rem;margin-top:.35rem">Miembro desde <?= View::e(substr((string)$fechaReg, 0, 10)) ?></div>
      <?php endif; ?>
    </div>
    <a class="btn" href="#editar">Editar perfil</a>
  </div>
</div>

<!-- Estadísticas e historial -->
<?php include APP_PATH . '/views/partials/perfil_stats.php'; ?>

<!-- Edición del perfil -->
<details class="form-card" id="editar" style="margin-top:1rem">
  <summary style="cursor:pointer;font-weight:800;color:#d7eaff;list-style:none">✏️ Editar mi información</summary>
  <form method="POST" action="/participante/perfil" enctype="multipart/form-data" style="margin-top:1rem">
    <?= Csrf::field() ?>
    <div class="form-grid">
      <div class="field" style="grid-column:1/-1">
        <label>Foto de perfil</label>
        <div style="display:flex;align-items:center;gap:1rem">
          <?= View::avatar($participante['nombre'], $fotoUrl, 56) ?>
          <input type="file" name="foto" accept="image/jpeg,image/png,image/webp,image/gif">
        </div>
        <span class="muted" style="font-size:.8rem">JPG, PNG, WEBP o GIF. Máx 2 MB. Dejá vacío para conservar la actual.</span>
      </div>
      <div class="field">
        <label>Nombre completo *</label>
        <input type="text" name="nombre" required value="<?= View::e($participante['nombre']) ?>">
      </div>
      <div class="field">
        <label>Nick / Alias</label>
        <input type="text" name="nick" value="<?= View::e($participante['nick'] ?? '') ?>">
      </div>
      <div class="field">
        <label>Email</label>
        <input type="email" name="email" value="<?= View::e($participante['email'] ?? '') ?>">
      </div>
      <div class="field">
        <label>Teléfono</label>
        <input type="text" name="telefono" value="<?= View::e($participante['telefono'] ?? '') ?>">
      </div>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn primary">Guardar cambios</button>
    </div>
  </form>
</details>

<!-- Derecho de supresión — Habeas Data (ley 18331) -->
<details class="form-card" style="margin-top:1rem;border-left:3px solid #c0392b">
  <summary style="cursor:pointer;font-weight:800;color:#ffb3b3;list-style:none">Eliminar mis datos personales</summary>
  <div style="margin-top:1rem">
    <p style="margin:0 0 .75rem">
      De acuerdo a la <strong>ley Nº 18331 (Habeas Data)</strong>, tenés derecho a solicitar la supresión
      de tus datos personales. Esta acción:
    </p>
    <ul style="margin:0 0 1rem 1.25rem;line-height:1.8">
      <li>Elimina tu nombre, documento, email, teléfono, nick y foto del sistema.</li>
      <li>Anonimiza tu cuenta de acceso (ya no podrás iniciar sesión).</li>
      <li>Tu historial de participación en torneos queda registrado de forma anónima.</li>
      <li><strong>Esta acción es irreversible.</strong></li>
    </ul>
    <button type="button" class="btn" style="background:#c0392b;border-color:#c0392b"
            onclick="document.getElementById('modal-habeas').style.display='flex'">
      Solicitar eliminación de mis datos
    </button>
  </div>
</details>

<!-- Modal de confirmación -->
<div id="modal-habeas" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:1000;align-items:center;justify-content:center">
  <div class="card" style="max-width:420px;width:90%;padding:1.5rem">
    <h3 style="margin:0 0 .75rem;color:#ffb3b3">¿Confirmar eliminación de datos?</h3>
    <p style="margin:0 0 1rem;font-size:.95rem">
      Vas a eliminar <strong>permanentemente</strong> todos tus datos personales y tu acceso al sistema.
      Serás redirigido al inicio de sesión.
    </p>
    <form method="POST" action="/participante/perfil/eliminar-datos">
      <?= Csrf::field() ?>
      <div style="display:flex;gap:.75rem;justify-content:flex-end">
        <button type="button" class="btn"
                onclick="document.getElementById('modal-habeas').style.display='none'">Cancelar</button>
        <button type="submit" class="btn" style="background:#c0392b;border-color:#c0392b">Sí, eliminar mis datos</button>
      </div>
    </form>
  </div>
</div>

<?php endif; ?>
