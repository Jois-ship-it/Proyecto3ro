<?php
/**
 * Bandeja de solicitudes de corrección (las pendientes primero).
 * Variables: $solicitudes (la página actual), $filtros, $pag (Paginador), $estados [valor => texto].
 */
$barra = [
    'accion' => '/admin/correcciones',
    'campos' => [
        ['nombre' => 'q',      'tipo' => 'buscar', 'etiqueta' => 'Buscar', 'placeholder' => 'Torneo o solicitante'],
        ['nombre' => 'estado', 'tipo' => 'opcion', 'etiqueta' => 'Estado', 'opciones' => $estados],
    ],
];
$hayFiltros = array_filter($filtros, fn($v) => $v !== '' && $v !== null) !== [];
?>
<section class="page-header">
  <div class="page-title">
    <div class="eyebrow">Resultados</div>
    <h1>Solicitudes de corrección</h1>
    <p class="muted">Revisá y aprobá o rechazá las correcciones solicitadas por los organizadores.</p>
  </div>
</section>

<div class="table-wrap">
  <div class="table-header"><?php include APP_PATH . '/views/partials/filtros.php'; ?></div>
  <div class="table-scroll">
    <table aria-label="Solicitudes de corrección">
      <thead>
        <tr>
          <th scope="col">Estado</th>
          <th scope="col">Torneo</th>
          <th scope="col">Partido</th>
          <th scope="col">Actual → Solicitado</th>
          <th scope="col">Motivo</th>
          <th scope="col">Solicitante</th>
          <th scope="col">Fecha</th>
          <th scope="col">Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($solicitudes)): ?>
          <tr><td colspan="8" class="muted"><?= $hayFiltros ? 'Ninguna solicitud coincide con la búsqueda.' : 'No hay solicitudes de corrección.' ?></td></tr>
        <?php else: ?>
          <?php foreach ($solicitudes as $s): ?>
            <?php
              $nombreA = $s['participante_a_nombre'] ?? $s['equipo_a_nombre'] ?? '—';
              $nombreB = $s['participante_b_nombre'] ?? $s['equipo_b_nombre'] ?? '—';
              $pendiente = $s['estado'] === 'pendiente';
            ?>
            <tr>
              <td><?= View::estadoChip($s['estado']) ?></td>
              <td><strong><?= View::e($s['torneo_nombre']) ?></strong></td>
              <td><?= View::e($nombreA) ?> <span class="muted">vs</span> <?= View::e($nombreB) ?></td>
              <td>
                <span class="muted"><?= (int)$s['actual_a'] ?>–<?= (int)$s['actual_b'] ?></span>
                &nbsp;→&nbsp;
                <strong><?= (int)$s['puntos_a'] ?>–<?= (int)$s['puntos_b'] ?></strong>
              </td>
              <td class="celda-motivo"><?= View::e($s['motivo']) ?>
                <?php if ($s['estado'] === 'rechazada' && $s['motivo_rechazo']): ?>
                  <div class="chip danger mt-03 d-block ws-normal">Rechazo: <?= View::e($s['motivo_rechazo']) ?></div>
                <?php endif; ?>
              </td>
              <td><?= View::e($s['solicitante_nombre']) ?>
                <?php if ($s['revisor_nombre']): ?><div class="muted fs-80">Revisó: <?= View::e($s['revisor_nombre']) ?></div><?php endif; ?>
              </td>
              <td class="muted nowrap"><?= View::e($s['created_at']) ?></td>
              <td>
                <?php if ($pendiente): ?>
                  <form class="d-inline" method="POST" action="/admin/correcciones/<?= (int)$s['id'] ?>/aprobar"
                        data-confirmar="¿Aprobar y aplicar esta corrección? Se recalculará la tabla.">
                    <?= Csrf::field() ?>
                    <button class="btn small primary">Aprobar</button>
                  </form>
                  <button class="btn small danger" data-rechazar data-id="<?= (int)$s['id'] ?>" data-abrir-modal="modalRechazar">Rechazar</button>
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php $base = '/admin/correcciones'; $unidad = 'solicitudes'; include APP_PATH . '/views/partials/paginacion.php'; ?>
</div>

<!-- Modal rechazar -->
<div class="modal-fondo" id="modalRechazar" data-cierra-fuera aria-hidden="true">
  <div class="form-card modal-card modal-460" role="dialog" aria-modal="true" aria-labelledby="modalRechazarTitulo">
    <h3 id="modalRechazarTitulo">Rechazar solicitud</h3>
    <form method="POST" id="formRechazar">
      <?= Csrf::field() ?>
      <div class="field">
        <label for="correcciones-motivo_rechazo">Motivo del rechazo *</label>
        <textarea id="correcciones-motivo_rechazo" name="motivo_rechazo" required minlength="5" placeholder="Explicá por qué se rechaza…"></textarea>
      </div>
      <div class="form-actions">
        <button type="button" class="btn" data-cerrar-modal>Cancelar</button>
        <button type="submit" class="btn danger">Rechazar</button>
      </div>
    </form>
  </div>
</div>

