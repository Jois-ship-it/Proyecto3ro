<?php
// Variables: $tabla, $torneo
?>
<div class="table-wrap">
  <div class="table-header">
    <strong>Ranking acumulado</strong>
    <?php if ($totalRondasJugadas ?? 0): ?>
      <span class="chip"><?= (int)$totalRondasJugadas ?> / <?= (int)$torneo['rondas_suizo'] ?> rondas</span>
    <?php endif; ?>
  </div>
  <div class="table-scroll">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Participante / Equipo</th>
          <th>PJ</th><th>PG</th><th>PE</th><th>PP</th>
          <th>PF</th><th>PC</th><th>Dif</th>
          <th>Pts</th><th>Byes</th><th>Buchholz</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($tabla)): ?>
          <tr><td colspan="12" class="muted">Aún no hay datos.</td></tr>
        <?php else: ?>
          <?php foreach ($tabla as $fila): ?>
          <tr <?= ($torneo['estado'] === 'finalizado' && $fila['posicion'] == 1) ? 'style="background:rgba(37,194,129,.1)"' : '' ?>>
            <td><strong><?= (int)$fila['posicion'] ?></strong>
              <?php if ($torneo['estado'] === 'finalizado' && $fila['posicion'] == 1): ?><span class="chip success">🏆</span><?php endif; ?>
            </td>
            <td><strong><?= View::e($fila['participante_nombre'] ?? $fila['equipo_nombre'] ?? '—') ?></strong>
              <?php if (!empty($fila['nick'])): ?><div class="muted"><?= View::e($fila['nick']) ?></div><?php endif; ?>
            </td>
            <td><?= (int)$fila['pj'] ?></td>
            <td><?= (int)$fila['pg'] ?></td>
            <td><?= (int)$fila['pe'] ?></td>
            <td><?= (int)$fila['pp'] ?></td>
            <td><?= (int)$fila['pf'] ?></td>
            <td><?= (int)$fila['pc'] ?></td>
            <td><?= (int)$fila['diferencia'] >= 0 ? '+' : '' ?><?= (int)$fila['diferencia'] ?></td>
            <td><strong><?= (int)$fila['puntos'] ?></strong></td>
            <td><?= (int)$fila['byes_recibidos'] ?></td>
            <td><?= number_format((float)$fila['buchholz'], 1) ?></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
