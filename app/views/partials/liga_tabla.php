<?php
// Variables esperadas: $tabla (array), $torneo (array), $puedeEditar (bool)
?>
<div class="table-wrap">
  <div class="table-header">
    <strong>Tabla de posiciones</strong>
    <span class="muted"><?= View::e($torneo['nombre_puntos'] ?? 'puntos') ?> por victoria: <?= (int)$torneo['puntos_victoria'] ?> | empate: <?= (int)$torneo['puntos_empate'] ?> | derrota: <?= (int)$torneo['puntos_derrota'] ?></span>
  </div>
  <div class="table-scroll">
    <table aria-label="Tabla de posiciones">
      <thead>
        <tr>
          <th scope="col">#</th>
          <th scope="col">Participante / Equipo</th>
          <th scope="col">PJ</th><th scope="col">PG</th><th scope="col">PE</th><th scope="col">PP</th>
          <th scope="col">PF</th><th scope="col">PC</th><th scope="col">Dif</th>
          <th scope="col"><?= View::e(ucfirst($torneo['nombre_puntos'] ?? 'Pts')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($tabla)): ?>
          <tr><td colspan="10" class="muted">Aún no hay datos en la tabla.</td></tr>
        <?php else: ?>
          <?php foreach ($tabla as $fila): ?>
          <tr <?= $torneo['estado'] === 'finalizado' && $fila['posicion'] == 1 ? 'class="fila-campeon"' : '' ?>>
            <td><strong><?= (int)$fila['posicion'] ?></strong>
              <?php if ($torneo['estado'] === 'finalizado' && $fila['posicion'] == 1): ?>
                <span class="chip success">🏆</span>
              <?php endif; ?>
            </td>
            <td>
              <strong><?= View::e($fila['participante_nombre'] ?? $fila['equipo_nombre'] ?? '—') ?></strong>
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
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
