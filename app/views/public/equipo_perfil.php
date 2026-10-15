<?php $logoUrl = Upload::url('logos', $equipo['logo'] ?? null); ?>
<section class="section">
  <a class="muted fs-90" href="/torneos">← Torneos</a>

  <!-- Cabecera -->
  <div class="card perfil-intro">
    <div class="flex gap-125 items-center flex-wrap">
      <?= View::avatar($equipo['nombre'], $logoUrl, 96) ?>
      <div class="flex-1 min-w-0">
        <div class="eyebrow">Equipo</div>
        <h1 class="perfil-nombre"><?= View::e($equipo['nombre']) ?></h1>
        <div class="flex gap-05 flex-wrap items-center">
          <?php if (!empty($equipo['categoria'])): ?><span class="chip"><?= View::e($equipo['categoria']) ?></span><?php endif; ?>
          <?php if (!empty($equipo['disciplina'])): ?><span class="chip"><?= View::e($equipo['disciplina']) ?></span><?php endif; ?>
          <?= View::estadoChip($equipo['estado']) ?>
        </div>
        <?php if (!empty($equipo['created_at'])): ?>
          <div class="muted fs-85 mt-035">Creado el <?= View::e(substr((string)$equipo['created_at'],0,10)) ?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php if (!empty($equipo['descripcion'])): ?>
      <p class="mt-1"><?= nl2br(View::e($equipo['descripcion'])) ?></p>
    <?php endif; ?>
  </div>

  <!-- Integrantes -->
  <?php if (!empty($equipo['participantes'])): ?>
  <div class="mb-1">
    <h2 class="como-h3">Integrantes</h2>
    <div class="grid cols-4">
      <?php foreach ($equipo['participantes'] as $p): ?>
        <a class="card tarjeta-integrante" href="/jugador/<?= (int)$p['id'] ?>">
          <?= View::avatar($p['nombre'], Upload::url('avatars', $p['foto'] ?? null), 38) ?>
          <div class="min-w-0">
            <strong class="truncar"><?= View::e($p['nombre']) ?></strong>
            <span class="muted fs-80"><?= View::e($p['rol_en_equipo'] ?? 'jugador') ?></span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Estadísticas e historial -->
  <?php include APP_PATH . '/views/partials/perfil_stats.php'; ?>
</section>
