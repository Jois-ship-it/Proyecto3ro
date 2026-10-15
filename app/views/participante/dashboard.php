<?php
/**
 * «Mi panel» del participante.
 *
 * Variables: $participante (o null si la cuenta no tiene perfil), $torneos (sus inscripciones),
 * $stats (o null), $panel (PanelParticipanteService::panel(): próximos partidos, últimos resultados
 * y cómo va en cada torneo).
 */
$resultados = [
    'victoria' => ['success', 'Victoria'],
    'empate'   => ['warning', 'Empate'],
    'derrota'  => ['danger',  'Derrota'],
];
$ordinal = static fn(int $n): string => $n . '.º';
?>
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
  <div class="kpi-row grilla-120">
    <div class="stat-card"><strong><?= (int)$stats['pj'] ?></strong><span>Jugados</span></div>
    <div class="stat-card"><strong class="color-ok"><?= (int)$stats['pg'] ?></strong><span>Victorias</span></div>
    <div class="stat-card"><strong><?= (int)$stats['winrate'] ?>%</strong><span>% Victorias</span></div>
    <div class="stat-card"><strong>🏆 <?= (int)$stats['campeonatos'] ?></strong><span>Campeonatos</span></div>
  </div>
  <?php endif; ?>

  <div class="grid cols-2">
    <article class="card">
      <h2 class="como-h3">Próximos partidos</h2>
      <?php if (empty($panel['proximos'])): ?>
        <p class="muted">No tenés partidos pendientes.</p>
      <?php else: ?>
        <ul class="lista-filas">
          <?php foreach ($panel['proximos'] as $p): ?>
          <li>
            <a href="/torneo/<?= (int)$p['torneo_id'] ?>">
              <span class="detalle">
                <strong>vs <?= View::e($p['rival']) ?></strong>
                <small><?= View::e($p['torneo']) ?> · <?= View::e($p['ronda']) ?><?= $p['como'] ? ' · con ' . View::e($p['como']) : '' ?></small>
              </span>
              <?php if ($p['fecha']): ?><span class="chip"><?= View::e(View::fechaHora($p['fecha'])) ?></span>
              <?php else: ?><span class="muted">Sin fecha</span><?php endif; ?>
            </a>
          </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </article>

    <article class="card">
      <h2 class="como-h3">Últimos resultados</h2>
      <?php if (empty($panel['recientes'])): ?>
        <p class="muted">Todavía no jugaste ningún partido.</p>
      <?php else: ?>
        <ul class="lista-filas">
          <?php foreach ($panel['recientes'] as $r): ?>
          <?php [$clase, $texto] = $resultados[$r['resultado']]; ?>
          <li>
            <a href="/torneo/<?= (int)$r['torneo_id'] ?>">
              <span class="detalle">
                <strong>vs <?= View::e($r['rival']) ?></strong>
                <small><?= View::e($r['torneo']) ?> · <?= View::e($r['ronda']) ?><?= $r['como'] ? ' · con ' . View::e($r['como']) : '' ?></small>
              </span>
              <span class="chip <?= $clase ?>"><?= $texto ?> <?= View::numero($r['mios']) ?> — <?= View::numero($r['rival_puntos']) ?></span>
            </a>
          </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </article>
  </div>

  <article class="card mt-1">
    <h2 class="como-h3">Mi posición</h2>
    <?php if (empty($panel['competencias'])): ?>
      <p class="muted">Todavía no estás en ningún torneo en curso o finalizado.</p>
    <?php else: ?>
      <ul class="lista-filas">
        <?php foreach ($panel['competencias'] as $c): ?>
        <li>
          <a href="/torneo/<?= (int)$c['torneo_id'] ?>">
            <span class="detalle">
              <strong><?= View::e($c['torneo']) ?></strong>
              <small>
                <?= View::e(ucfirst(str_replace('_', ' ', $c['estado']))) ?><?= $c['como'] ? ' · con ' . View::e($c['como']) : '' ?>
                <?php if ($c['posicion'] !== null): ?> · <?= (int)$c['puntos'] ?> pts · <?= (int)$c['pj'] ?> PJ<?php endif; ?>
              </small>
            </span>
            <?php if ($c['posicion'] !== null): ?>
              <span class="chip"><?= $ordinal($c['posicion']) ?> de <?= (int)$c['total'] ?></span>
            <?php elseif ($c['llave'] === 'campeon'): ?>
              <span class="chip success">Campeón</span>
            <?php elseif ($c['llave'] === 'en_carrera'): ?>
              <span class="chip">Sigue en carrera</span>
            <?php elseif ($c['llave'] === 'eliminado'): ?>
              <span class="chip danger">Eliminado en <?= View::e($c['ronda_fuera']) ?></span>
            <?php else: ?>
              <span class="chip warning">Sin partidos jugados</span>
            <?php endif; ?>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </article>

  <div class="grid cols-2 mt-1">
    <article class="card">
      <div class="flex gap-1 items-center">
        <?= View::avatar($participante['nombre'], $fotoUrl, 56) ?>
        <div>
          <h2 class="como-h3 m-0"><?= View::e($participante['nombre']) ?></h2>
          <?php if ($participante['nick']): ?><div class="chip"><?= View::e($participante['nick']) ?></div><?php endif; ?>
        </div>
      </div>
      <a class="btn small mt-075 d-inline-flex" href="/participante/perfil">Ver mi perfil completo</a>
    </article>
    <article class="card">
      <h2 class="como-h3">Mis torneos</h2>
      <?php if (empty($torneos)): ?>
        <p class="muted">Sin torneos registrados.</p>
      <?php else: ?>
        <?php foreach (array_slice($torneos, 0, 5) as $t): ?>
        <div class="fila-torneo">
          <strong><?= View::e($t['torneo_nombre']) ?></strong>
          <?= View::estadoChip($t['torneo_estado']) ?>
        </div>
        <?php endforeach; ?>
        <a class="btn small mt-075 d-inline-flex" href="/participante/torneos">Ver todos</a>
      <?php endif; ?>
    </article>
  </div>
<?php endif; ?>
