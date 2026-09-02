<?php
/**
 * Panel de estadísticas e historial reutilizable.
 * Espera: $stats (de StatsService). Opcional: $statsTitulo.
 */
$st = $stats ?? null;
if (!$st): ?>
  <div class="card"><p class="muted">Sin estadísticas disponibles todavía.</p></div>
<?php else: ?>

<!-- KPIs -->
<div class="kpi-row" style="grid-template-columns:repeat(auto-fit,minmax(130px,1fr))">
  <div class="stat-card"><strong><?= (int)$st['pj'] ?></strong><span>Partidos jugados</span></div>
  <div class="stat-card"><strong style="color:var(--success)"><?= (int)$st['pg'] ?></strong><span>Victorias</span></div>
  <div class="stat-card"><strong style="color:var(--warning)"><?= (int)$st['pe'] ?></strong><span>Empates</span></div>
  <div class="stat-card"><strong style="color:var(--danger)"><?= (int)$st['pp'] ?></strong><span>Derrotas</span></div>
  <div class="stat-card"><strong><?= (int)$st['winrate'] ?>%</strong><span>% Victorias</span></div>
  <div class="stat-card"><strong>🏆 <?= (int)$st['campeonatos'] ?></strong><span>Campeonatos</span></div>
</div>

<div class="grid cols-3" style="margin:1rem 0">
  <div class="stat-card"><strong><?= (int)$st['torneos_total'] ?></strong><span>Torneos disputados</span></div>
  <div class="stat-card"><strong><?= (int)$st['torneos_activos'] ?></strong><span>Activos</span></div>
  <div class="stat-card"><strong><?= (int)$st['torneos_finalizados'] ?></strong><span>Finalizados</span></div>
</div>

<!-- Rendimiento acumulado -->
<div class="card" style="margin-bottom:1rem">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap">
    <h3 style="margin:0">Rendimiento acumulado</h3>
    <div class="muted">A favor <strong style="color:var(--text)"><?= (int)$st['pf'] ?></strong> · En contra <strong style="color:var(--text)"><?= (int)$st['pc'] ?></strong> · Dif <strong style="color:var(--text)"><?= (int)$st['dif'] >= 0 ? '+' : '' ?><?= (int)$st['dif'] ?></strong> · Byes <strong style="color:var(--text)"><?= (int)$st['byes'] ?></strong></div>
  </div>
  <?php $tot = max(1, (int)$st['pg'] + (int)$st['pe'] + (int)$st['pp']); ?>
  <div style="display:flex;height:14px;border-radius:999px;overflow:hidden;margin-top:.75rem;border:1px solid var(--line)">
    <div style="width:<?= round($st['pg']/$tot*100) ?>%;background:var(--success)"></div>
    <div style="width:<?= round($st['pe']/$tot*100) ?>%;background:var(--warning)"></div>
    <div style="width:<?= round($st['pp']/$tot*100) ?>%;background:var(--danger)"></div>
  </div>
</div>

<!-- Evolución del rendimiento -->
<?php $ev = $st['evolucion'] ?? []; if (count($ev) >= 1): ?>
<?php
  $n = count($ev); $w = 600; $hgt = 120; $pad = 8;
  $coord = function (int $i, int $wr) use ($n, $w, $hgt, $pad) {
      $x = $n > 1 ? $pad + $i / ($n - 1) * ($w - 2 * $pad) : $w / 2;
      $y = $pad + (100 - $wr) / 100 * ($hgt - 2 * $pad);
      return [round($x, 1), round($y, 1)];
  };
  $pts = [];
  foreach ($ev as $i => $e) { [$x, $y] = $coord($i, (int)$e['winrate']); $pts[] = "$x,$y"; }
?>
<div class="card" style="margin-bottom:1rem">
  <h3 style="margin:0 0 .5rem">Evolución del rendimiento</h3>
  <svg viewBox="0 0 <?= $w ?> <?= $hgt ?>" preserveAspectRatio="none" style="width:100%;height:120px;display:block">
    <line x1="0" y1="<?= $pad ?>"        x2="<?= $w ?>" y2="<?= $pad ?>"        stroke="var(--line)" stroke-width="1"/>
    <line x1="0" y1="<?= $hgt/2 ?>"      x2="<?= $w ?>" y2="<?= $hgt/2 ?>"      stroke="var(--line)" stroke-dasharray="3 5" stroke-width="1"/>
    <line x1="0" y1="<?= $hgt-$pad ?>"   x2="<?= $w ?>" y2="<?= $hgt-$pad ?>"   stroke="var(--line)" stroke-width="1"/>
    <polyline points="<?= implode(' ', $pts) ?>" fill="none" stroke="var(--primary-strong)" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
    <?php foreach ($ev as $i => $e): [$x, $y] = $coord($i, (int)$e['winrate']); ?>
      <circle cx="<?= $x ?>" cy="<?= $y ?>" r="3" fill="var(--primary-strong)"/>
    <?php endforeach; ?>
  </svg>
  <div class="muted" style="font-size:.8rem;text-align:center">% de victorias acumulado por partido (0% – 100%)</div>

  <div style="display:flex;gap:.28rem;flex-wrap:wrap;margin-top:.8rem">
    <?php $col = ['G'=>'var(--success)','E'=>'var(--warning)','P'=>'var(--danger)']; foreach ($ev as $e): ?>
      <span title="<?= View::e(($e['rival'] ?? '') . ' · ' . $e['torneo']) ?>"
            style="width:22px;height:22px;border-radius:6px;display:inline-grid;place-items:center;font-size:.7rem;font-weight:800;color:#04121f;background:<?= $col[$e['resultado']] ?? 'var(--muted)' ?>"><?= $e['resultado'] ?></span>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Torneos -->
<?php if (!empty($st['torneos'])): ?>
<div class="table-wrap" style="margin-bottom:1rem">
  <div class="table-header"><strong>Torneos</strong></div>
  <div class="table-scroll">
    <table>
      <thead><tr><th>Torneo</th><th>Formato</th><th>Estado</th><th>Posición</th></tr></thead>
      <tbody>
        <?php foreach ($st['torneos'] as $t): ?>
        <tr>
          <td><strong><?= View::e($t['nombre']) ?></strong>
            <?php if ((int)$t['es_campeon'] === 1): ?> <span class="chip success">🏆 Campeón</span><?php endif; ?>
          </td>
          <td><span class="chip"><?= View::e($t['tipo_nombre']) ?></span></td>
          <td><?= View::estadoChip($t['estado']) ?></td>
          <td><?= $t['posicion'] !== null ? '#' . (int)$t['posicion'] : '—' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Historial de resultados -->
<div class="table-wrap">
  <div class="table-header"><strong>Historial de resultados</strong><span class="muted"><?= count($st['historial']) ?> partidos</span></div>
  <div class="table-scroll">
    <table>
      <thead><tr><th>Res.</th><th>Torneo</th><th>Ronda</th><th>Rival</th><th>Marcador</th></tr></thead>
      <tbody>
        <?php if (empty($st['historial'])): ?>
          <tr><td colspan="5" class="muted">Todavía no jugó partidos.</td></tr>
        <?php else: ?>
          <?php
            $chip = ['G'=>'success','E'=>'warning','P'=>'danger','BYE'=>''];
            $lbl  = ['G'=>'Ganó','E'=>'Empató','P'=>'Perdió','BYE'=>'Bye'];
            foreach (array_reverse($st['historial']) as $h):
          ?>
          <tr>
            <td><span class="chip <?= $chip[$h['resultado']] ?? '' ?>"><?= $lbl[$h['resultado']] ?? $h['resultado'] ?></span></td>
            <td><?= View::e($h['torneo']) ?></td>
            <td class="muted"><?= View::e($h['ronda']) ?></td>
            <td><?= $h['rival'] !== null ? View::e($h['rival']) : '<span class="muted">—</span>' ?></td>
            <td>
              <?php if ($h['resultado'] === 'BYE'): ?><span class="muted">—</span>
              <?php else: ?><strong><?= (int)$h['pf'] ?> - <?= (int)$h['pc'] ?></strong>
                <?php if (!empty($h['corregido'])): ?> <span class="chip warning" style="font-size:.7rem">corregido</span><?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php endif; ?>
