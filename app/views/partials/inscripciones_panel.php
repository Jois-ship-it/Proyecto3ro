<?php
/**
 * Panel de inscripciones reutilizable (admin y organizador).
 * Variables: $torneo, $inscritos, $participantesDisponibles, $equiposDisponibles,
 *            $inscripcionBase (ej: "/admin/torneos/5" o "/organizador/torneos/5")
 */
$minEquipo = (int)($torneo['min_integrantes_equipo'] ?? 0);
?>
<?php if (in_array($torneo['estado'], ['borrador','inscripcion'])): ?>
<div class="panel mb-1">
  <h2 class="como-h3">Inscripciones (<?= count($inscritos) ?>)</h2>

  <?php if ($torneo['modalidad'] === 'equipos' && $minEquipo > 0): ?>
    <p class="muted mt-neg025">
      Mínimo <strong><?= $minEquipo ?></strong> integrantes por equipo para poder inscribirse.
    </p>
  <?php endif; ?>

  <?php if (!empty($inscritos)): ?>
  <div class="table-scroll mb-1">
    <table aria-label="Inscritos del torneo">
      <thead><tr><th scope="col">Nombre</th><th scope="col">Estado</th><th scope="col">Acción</th></tr></thead>
      <tbody>
        <?php foreach ($inscritos as $ins): ?>
        <tr>
          <td><?= View::e($ins['participante_nombre'] ?? $ins['equipo_nombre'] ?? '—') ?></td>
          <td><?= View::estadoChip($ins['estado']) ?></td>
          <td>
            <form class="d-inline" method="POST" action="<?= $inscripcionBase ?>/desinscribir">
              <?= Csrf::field() ?>
              <?php if ($ins['participante_id']): ?>
                <input type="hidden" name="participante_id" value="<?= (int)$ins['participante_id'] ?>">
              <?php else: ?>
                <input type="hidden" name="equipo_id" value="<?= (int)$ins['equipo_id'] ?>">
              <?php endif; ?>
              <button class="btn small danger">Retirar</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php if ($torneo['modalidad'] === 'individual' && !empty($participantesDisponibles)): ?>
  <form class="flex gap-075 items-end flex-wrap" method="POST" action="<?= $inscripcionBase ?>/inscribir">
    <?= Csrf::field() ?>
    <div class="field campo-flexible">
      <label for="inscripciones-participante">Inscribir participante</label>
      <div class="combobox" data-combobox data-name="participante_id" data-required="true"
           data-options='<?= View::e(json_encode($participantesDisponibles, JSON_UNESCAPED_UNICODE)) ?>'>
        <input id="inscripciones-participante" type="text" placeholder="Buscar participante…" autocomplete="off">
        <input type="hidden" name="participante_id" value="">
        <ul class="combobox-list"></ul>
      </div>
    </div>
    <button type="submit" class="btn primary">Inscribir</button>
  </form>
  <?php elseif ($torneo['modalidad'] === 'equipos' && !empty($equiposDisponibles)): ?>
  <form class="flex gap-075 items-end flex-wrap" method="POST" action="<?= $inscripcionBase ?>/inscribir">
    <?= Csrf::field() ?>
    <div class="field campo-flexible">
      <label for="inscripciones-equipo">Inscribir equipo</label>
      <div class="combobox" data-combobox data-name="equipo_id" data-required="true"
           data-options='<?= View::e(json_encode($equiposDisponibles, JSON_UNESCAPED_UNICODE)) ?>'>
        <input id="inscripciones-equipo" type="text" placeholder="Buscar equipo…" autocomplete="off">
        <input type="hidden" name="equipo_id" value="">
        <ul class="combobox-list"></ul>
      </div>
    </div>
    <button type="submit" class="btn primary">Inscribir equipo</button>
  </form>
  <?php elseif ($torneo['modalidad'] === 'equipos'): ?>
    <p class="muted">No hay equipos disponibles para inscribir.</p>
  <?php endif; ?>
</div>
<?php endif; ?>
