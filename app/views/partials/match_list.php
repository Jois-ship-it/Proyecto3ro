<?php
// Variables: $partidos (array), $torneo (array), $puedeEditar (bool), $csrf (string)
// Se puede incluir desde liga_fixture, suizo_rondas o bracket
?>
<div class="table-scroll">
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Local</th>
        <th>Resultado</th>
        <th>Visitante</th>
        <th>Fecha</th>
        <th>Estado</th>
        <?php if ($puedeEditar ?? false): ?><th>Acciones</th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($partidos as $p): ?>
        <?php
          $nombreA = $p['participante_a_nombre'] ?? $p['equipo_a_nombre'] ?? '—';
          $nombreB = $p['participante_b_nombre'] ?? $p['equipo_b_nombre'] ?? '—';
          $res     = (new ResultadoModel())->getByEnfrentamiento((int)$p['id']);
          $fpInput = !empty($p['fecha_programada']) ? date('Y-m-d\TH:i', strtotime((string)$p['fecha_programada'])) : '';
          $esFinal = $p['estado'] === 'finalizado';
        ?>
        <tr>
          <td><?= (int)$p['orden'] ?></td>
          <td>
            <strong><?= View::e($nombreA) ?></strong>
            <?php if (!empty($p['nick_a'])): ?><br><span class="muted"><?= View::e($p['nick_a']) ?></span><?php endif; ?>
          </td>
          <td style="text-align:center">
            <?php if ($p['es_bye']): ?>
              <span class="chip warning">Bye</span>
            <?php elseif ($res): ?>
              <strong><?= number_format((float)$res['puntos_a'],0) ?> — <?= number_format((float)$res['puntos_b'],0) ?></strong>
              <?php if ($res['corregido']): ?><br><span class="chip warning" style="font-size:.75rem">Corregido</span><?php endif; ?>
            <?php else: ?>
              <span class="muted">vs</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($p['es_bye']): ?>
              <span class="muted">—</span>
            <?php else: ?>
              <strong><?= View::e($nombreB) ?></strong>
              <?php if (!empty($p['nick_b'])): ?><br><span class="muted"><?= View::e($p['nick_b']) ?></span><?php endif; ?>
            <?php endif; ?>
          </td>
          <td style="font-size:.85rem;white-space:nowrap">
            <?php if (!empty($p['fecha_programada'])): ?>
              <div title="Fecha programada">📅 <?= View::fechaHora($p['fecha_programada']) ?></div>
            <?php endif; ?>
            <?php if ($esFinal && !empty($p['fecha_fin_real'])): ?>
              <div class="muted" title="Finalizado">🏁 <?= View::fechaHora($p['fecha_fin_real']) ?></div>
            <?php elseif (!empty($p['fecha_inicio_real']) && !$esFinal): ?>
              <div class="muted" title="Inicio">▶ <?= View::fechaHora($p['fecha_inicio_real']) ?></div>
            <?php endif; ?>
            <?php if (empty($p['fecha_programada']) && empty($p['fecha_fin_real']) && empty($p['fecha_inicio_real'])): ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td><?= View::estadoChip($p['estado']) ?></td>
          <?php if ($puedeEditar ?? false): ?>
          <td>
            <?php if (in_array($p['estado'], ['pendiente', 'en_curso']) && !$p['es_bye']): ?>
              <button class="btn small primary"
                data-modal-cargar
                data-enf-id="<?= (int)$p['id'] ?>"
                data-torneo-id="<?= (int)$torneo['id'] ?>"
                data-nombre-a="<?= View::e($nombreA) ?>"
                data-nombre-b="<?= View::e($nombreB) ?>">
                Cargar resultado
              </button>
              <button class="btn small"
                data-modal-programar
                data-enf-id="<?= (int)$p['id'] ?>"
                data-fecha="<?= View::e($fpInput) ?>"
                data-nombre-a="<?= View::e($nombreA) ?>"
                data-nombre-b="<?= View::e($nombreB) ?>">
                Programar
              </button>
            <?php elseif ($p['estado'] === 'finalizado' && $res): ?>
              <button class="btn small"
                data-modal-corregir
                data-enf-id="<?= (int)$p['id'] ?>"
                data-torneo-id="<?= (int)$torneo['id'] ?>"
                data-puntos-a="<?= (float)$res['puntos_a'] ?>"
                data-puntos-b="<?= (float)$res['puntos_b'] ?>"
                data-nombre-a="<?= View::e($nombreA) ?>"
                data-nombre-b="<?= View::e($nombreB) ?>">
                <?= Auth::isAdmin() ? 'Corregir' : 'Solicitar corrección' ?>
              </button>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
