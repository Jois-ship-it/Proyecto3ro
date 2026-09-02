<?php
$slug      = $tipo['slug'] ?? '';
$esLiga    = $slug === 'liga';
$esElim    = $slug === 'eliminacion_directa';
$esSuizo   = $slug === 'suizo';
$puedeEditar = false; // Vista pública: sin edición
?>
<section class="section">
  <div class="page-header">
    <div class="page-title">
      <a class="muted" href="/torneos" style="font-size:.9rem">← Torneos</a>
      <div class="eyebrow" style="margin-top:.5rem"><?= View::e($tipo['nombre']) ?></div>
      <h1><?= View::e($torneo['nombre']) ?></h1>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <?= View::estadoChip($torneo['estado']) ?>
        <span class="chip"><?= View::e(ucfirst($torneo['modalidad'])) ?></span>
        <?php if ($esSuizo && $torneo['rondas_suizo']): ?>
          <span class="chip"><?= count($rondasConPartidos) ?>/<?= (int)$torneo['rondas_suizo'] ?> rondas</span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Campeón -->
  <?php if ($torneo['estado'] === 'finalizado'): ?>
  <div class="card" style="background:rgba(37,194,129,.1);border-color:rgba(37,194,129,.3);text-align:center;padding:1.5rem;margin-bottom:1.5rem">
    <div class="eyebrow" style="color:var(--success)">Campeón del torneo</div>
    <h2>🏆 <?= View::e($torneo['campeon_participante_nombre'] ?? $torneo['campeon_equipo_nombre'] ?? '—') ?></h2>
  </div>
  <?php endif; ?>

  <!-- KPIs -->
  <div class="grid cols-3" style="margin-bottom:1.5rem">
    <div class="stat-card"><strong><?= count($inscritos) ?></strong><span>Participantes</span></div>
    <div class="stat-card"><strong><?= count($rondasConPartidos) ?></strong><span>Rondas</span></div>
    <?php if ($torneo['descripcion']): ?>
    <div class="card"><p class="muted"><?= View::e($torneo['descripcion']) ?></p></div>
    <?php endif; ?>
  </div>

  <!-- Tabla / Ranking -->
  <?php if (!empty($tabla)): ?>
    <div style="margin-bottom:1.5rem">
      <?php if ($esSuizo): ?>
        <?php include APP_PATH . '/views/partials/suizo_ranking.php'; ?>
      <?php else: ?>
        <?php include APP_PATH . '/views/partials/liga_tabla.php'; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- Bracket / Partidos -->
  <?php if (!empty($rondasConPartidos)): ?>
    <?php if ($esElim): ?>
      <?php include APP_PATH . '/views/partials/eliminacion_bracket.php'; ?>
    <?php elseif ($esSuizo): ?>
      <?php include APP_PATH . '/views/partials/suizo_rondas.php'; ?>
    <?php else: ?>
      <?php include APP_PATH . '/views/partials/liga_fixture.php'; ?>
    <?php endif; ?>
  <?php endif; ?>

  <!-- Inscritos -->
  <?php if (!empty($inscritos)): ?>
  <div style="margin-top:1.5rem">
    <h3>Participantes</h3>
    <div class="grid cols-4">
      <?php foreach ($inscritos as $ins): ?>
        <?php
          $perfilUrl = !empty($ins['participante_id']) ? '/jugador/' . (int)$ins['participante_id']
                     : (!empty($ins['equipo_id']) ? '/equipo/' . (int)$ins['equipo_id'] : null);
          $nombre = $ins['participante_nombre'] ?? $ins['equipo_nombre'] ?? '—';
        ?>
        <?php if ($perfilUrl): ?><a class="card" href="<?= $perfilUrl ?>" style="padding:.75rem;display:block"><?php else: ?><div class="card" style="padding:.75rem"><?php endif; ?>
          <strong><?= View::e($nombre) ?></strong>
          <?php if (!empty($ins['nick'])): ?><div class="muted"><?= View::e($ins['nick']) ?></div><?php endif; ?>
        <?php if ($perfilUrl): ?></a><?php else: ?></div><?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</section>
