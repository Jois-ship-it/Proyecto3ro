<?php
/**
 * Panel de inscripciones reutilizable (admin y organizador).
 * Variables: $torneo, $inscritos, $participantesDisponibles, $equiposDisponibles,
 *            $inscripcionBase (ej: "/admin/torneos/5" o "/organizador/torneos/5")
 */
$minEquipo = (int)($torneo['min_integrantes_equipo'] ?? 0);
?>
<?php if (in_array($torneo['estado'], ['borrador','inscripcion'])): ?>
<div class="panel" style="margin-bottom:1rem">
  <h3>Inscripciones (<?= count($inscritos) ?>)</h3>

  <?php if ($torneo['modalidad'] === 'equipos' && $minEquipo > 0): ?>
    <p class="muted" style="margin-top:-.25rem">
      Mínimo <strong><?= $minEquipo ?></strong> integrantes por equipo para poder inscribirse.
    </p>
  <?php endif; ?>

  <?php if (!empty($inscritos)): ?>
  <div class="table-scroll" style="margin-bottom:1rem">
    <table>
      <thead><tr><th>Nombre</th><th>Estado</th><th>Acción</th></tr></thead>
      <tbody>
        <?php foreach ($inscritos as $ins): ?>
        <tr>
          <td><?= View::e($ins['participante_nombre'] ?? $ins['equipo_nombre'] ?? '—') ?></td>
          <td><?= View::estadoChip($ins['estado']) ?></td>
          <td>
            <form method="POST" action="<?= $inscripcionBase ?>/desinscribir" style="display:inline">
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
  <form method="POST" action="<?= $inscripcionBase ?>/inscribir" style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap">
    <?= Csrf::field() ?>
    <div class="field" style="flex:1;min-width:200px">
      <label>Inscribir participante</label>
      <select name="participante_id" required>
        <option value="">Seleccionar…</option>
        <?php foreach ($participantesDisponibles as $p): ?>
          <option value="<?= (int)$p['id'] ?>"><?= View::e($p['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn primary">Inscribir</button>
  </form>
  <?php elseif ($torneo['modalidad'] === 'equipos' && !empty($equiposDisponibles)): ?>
  <form method="POST" action="<?= $inscripcionBase ?>/inscribir" style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap">
    <?= Csrf::field() ?>
    <div class="field" style="flex:1;min-width:200px">
      <label>Inscribir equipo</label>
      <select name="equipo_id" required>
        <option value="">Seleccionar…</option>
        <?php foreach ($equiposDisponibles as $e): ?>
          <?php
            $tot = (int)($e['total_integrantes'] ?? 0);
            $apto = ($minEquipo === 0) || ($tot >= $minEquipo);
          ?>
          <option value="<?= (int)$e['id'] ?>" <?= $apto ? '' : 'disabled' ?>>
            <?= View::e($e['nombre']) ?> · <?= $tot ?> integrante<?= $tot === 1 ? '' : 's' ?><?= $apto ? '' : ' (no cumple el mínimo)' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn primary">Inscribir equipo</button>
  </form>
  <?php elseif ($torneo['modalidad'] === 'equipos'): ?>
    <p class="muted">No hay equipos disponibles para inscribir.</p>
  <?php endif; ?>
</div>
<?php endif; ?>
