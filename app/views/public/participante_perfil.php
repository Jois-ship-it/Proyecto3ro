<?php $fotoUrl = Upload::url('avatars', $participante['foto'] ?? null); ?>
<section class="section">
  <a class="muted fs-90" href="/torneos">← Torneos</a>

  <!-- Cabecera -->
  <div class="card perfil-intro">
    <div class="flex gap-125 items-center flex-wrap">
      <?= View::avatar($participante['nombre'], $fotoUrl, 96) ?>
      <div class="flex-1 min-w-0">
        <div class="eyebrow">Participante</div>
        <h1 class="perfil-nombre"><?= View::e($participante['nombre']) ?></h1>
        <div class="flex gap-05 flex-wrap items-center">
          <?php if (!empty($participante['nick'])): ?><span class="chip"><?= View::e($participante['nick']) ?></span><?php endif; ?>
          <?= View::estadoChip($participante['estado']) ?>
        </div>
        <?php if (!empty($participante['equipos'])): ?>
          <div class="muted fs-85 mt-04">
            Equipos:
            <?php foreach ($participante['equipos'] as $i => $eq): ?>
              <a class="color-primario" href="/equipo/<?= (int)$eq['id'] ?>"><?= View::e($eq['nombre']) ?></a><?= $i < count($participante['equipos'])-1 ? ', ' : '' ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if (!empty($participante['created_at'])): ?>
          <div class="muted fs-85 mt-02">Miembro desde <?= View::e(substr((string)$participante['created_at'],0,10)) ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Estadísticas e historial -->
  <?php include APP_PATH . '/views/partials/perfil_stats.php'; ?>
</section>
