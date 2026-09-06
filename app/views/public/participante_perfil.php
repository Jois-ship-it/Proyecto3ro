<?php $fotoUrl = Upload::url('avatars', $participante['foto'] ?? null); ?>
<section class="section">
  <a class="muted" href="/torneos" style="font-size:.9rem">← Torneos</a>

  <!-- Cabecera -->
  <div class="card" style="margin:.75rem 0 1rem">
    <div style="display:flex;gap:1.25rem;align-items:center;flex-wrap:wrap">
      <?= View::avatar($participante['nombre'], $fotoUrl, 96) ?>
      <div style="flex:1;min-width:0">
        <div class="eyebrow">Participante</div>
        <h1 style="margin:.1rem 0 .4rem;font-size:clamp(1.6rem,4vw,2.6rem)"><?= View::e($participante['nombre']) ?></h1>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
          <?php if (!empty($participante['nick'])): ?><span class="chip"><?= View::e($participante['nick']) ?></span><?php endif; ?>
          <?= View::estadoChip($participante['estado']) ?>
        </div>
        <?php if (!empty($participante['equipos'])): ?>
          <div class="muted" style="font-size:.85rem;margin-top:.4rem">
            Equipos:
            <?php foreach ($participante['equipos'] as $i => $eq): ?>
              <a href="/equipo/<?= (int)$eq['id'] ?>" style="color:var(--primary-strong)"><?= View::e($eq['nombre']) ?></a><?= $i < count($participante['equipos'])-1 ? ', ' : '' ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if (!empty($participante['created_at'])): ?>
          <div class="muted" style="font-size:.85rem;margin-top:.2rem">Miembro desde <?= View::e(substr((string)$participante['created_at'],0,10)) ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Estadísticas e historial -->
  <?php include APP_PATH . '/views/partials/perfil_stats.php'; ?>
</section>
