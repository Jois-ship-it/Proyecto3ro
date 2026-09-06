<section class="page-header">
  <div class="page-title">
    <div class="eyebrow">Participante</div>
    <h1>Mi panel</h1>
  </div>
</section>

<?php if (!$participante): ?>
  <div class="card">
    <p class="muted">No tenés un perfil de participante vinculado a tu cuenta. Contactá al administrador.</p>
  </div>
<?php else: ?>
  <?php $fotoUrl = Upload::url('avatars', $participante['foto'] ?? null); ?>
  <?php if ($stats): ?>
  <div class="kpi-row" style="grid-template-columns:repeat(auto-fit,minmax(120px,1fr))">
    <div class="stat-card"><strong><?= (int)$stats['pj'] ?></strong><span>Jugados</span></div>
    <div class="stat-card"><strong style="color:var(--success)"><?= (int)$stats['pg'] ?></strong><span>Victorias</span></div>
    <div class="stat-card"><strong><?= (int)$stats['winrate'] ?>%</strong><span>% Victorias</span></div>
    <div class="stat-card"><strong>🏆 <?= (int)$stats['campeonatos'] ?></strong><span>Campeonatos</span></div>
  </div>
  <?php endif; ?>
  <div class="grid cols-2">
    <article class="card">
      <div style="display:flex;gap:1rem;align-items:center">
        <?= View::avatar($participante['nombre'], $fotoUrl, 56) ?>
        <div>
          <h3 style="margin:0"><?= View::e($participante['nombre']) ?></h3>
          <?php if ($participante['nick']): ?><div class="chip"><?= View::e($participante['nick']) ?></div><?php endif; ?>
        </div>
      </div>
      <a class="btn small" href="/participante/perfil" style="margin-top:.75rem;display:inline-flex">Ver mi perfil completo</a>
    </article>
    <article class="card">
      <h3>Mis torneos</h3>
      <?php if (empty($torneos)): ?>
        <p class="muted">Sin torneos registrados.</p>
      <?php else: ?>
        <?php foreach (array_slice($torneos, 0, 5) as $t): ?>
        <div style="padding:.45rem 0;border-bottom:1px solid var(--line)">
          <strong><?= View::e($t['torneo_nombre']) ?></strong>
          <?= View::estadoChip($t['torneo_estado']) ?>
        </div>
        <?php endforeach; ?>
        <a class="btn small" href="/participante/torneos" style="margin-top:.75rem;display:inline-flex">Ver todos</a>
      <?php endif; ?>
    </article>
  </div>
<?php endif; ?>
