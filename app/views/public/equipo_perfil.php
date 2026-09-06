<?php $logoUrl = Upload::url('logos', $equipo['logo'] ?? null); ?>
<section class="section">
  <a class="muted" href="/torneos" style="font-size:.9rem">← Torneos</a>

  <!-- Cabecera -->
  <div class="card" style="margin:.75rem 0 1rem">
    <div style="display:flex;gap:1.25rem;align-items:center;flex-wrap:wrap">
      <?= View::avatar($equipo['nombre'], $logoUrl, 96) ?>
      <div style="flex:1;min-width:0">
        <div class="eyebrow">Equipo</div>
        <h1 style="margin:.1rem 0 .4rem;font-size:clamp(1.6rem,4vw,2.6rem)"><?= View::e($equipo['nombre']) ?></h1>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
          <?php if (!empty($equipo['categoria'])): ?><span class="chip"><?= View::e($equipo['categoria']) ?></span><?php endif; ?>
          <?php if (!empty($equipo['disciplina'])): ?><span class="chip"><?= View::e($equipo['disciplina']) ?></span><?php endif; ?>
          <?= View::estadoChip($equipo['estado']) ?>
        </div>
        <?php if (!empty($equipo['created_at'])): ?>
          <div class="muted" style="font-size:.85rem;margin-top:.35rem">Creado el <?= View::e(substr((string)$equipo['created_at'],0,10)) ?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php if (!empty($equipo['descripcion'])): ?>
      <p style="margin-top:1rem"><?= nl2br(View::e($equipo['descripcion'])) ?></p>
    <?php endif; ?>
  </div>

  <!-- Integrantes -->
  <?php if (!empty($equipo['participantes'])): ?>
  <div style="margin-bottom:1rem">
    <h3>Integrantes</h3>
    <div class="grid cols-4">
      <?php foreach ($equipo['participantes'] as $p): ?>
        <a class="card" href="/jugador/<?= (int)$p['id'] ?>" style="padding:.75rem;display:flex;gap:.6rem;align-items:center">
          <?= View::avatar($p['nombre'], Upload::url('avatars', $p['foto'] ?? null), 38) ?>
          <div style="min-width:0">
            <strong style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= View::e($p['nombre']) ?></strong>
            <span class="muted" style="font-size:.8rem"><?= View::e($p['rol_en_equipo'] ?? 'jugador') ?></span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Estadísticas e historial -->
  <?php include APP_PATH . '/views/partials/perfil_stats.php'; ?>
</section>
