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
    <table aria-label="Ranking del torneo">
      <thead>
        <tr>
          <th scope="col">#</th>
          <th scope="col">Participante / Equipo</th>
          <th scope="col">PJ</th><th scope="col">PG</th><th scope="col">PE</th><th scope="col">PP</th>
          <th scope="col">PF</th><th scope="col">PC</th><th scope="col">Dif</th>
          <th scope="col">Pts</th><th scope="col">Byes</th><th scope="col">Buchholz</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($tabla)): ?>
          <tr><td colspan="12" class="muted">Aún no hay datos.</td></tr>
        <?php else: ?>
          <?php foreach ($tabla as $fila): ?>
          <tr <?= ($torneo['estado'] === 'finalizado' && $fila['posicion'] == 1) ? 'class="fila-campeon"' : '' ?>>
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
