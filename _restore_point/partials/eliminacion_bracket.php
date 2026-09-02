<?php
// Variables: $rondasConPartidos, $torneo, $puedeEditar, $csrf
$totalRondas = count($rondasConPartidos);
?>
<div class="panel" style="padding:1rem 1rem .5rem">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem">
    <strong>Llave de eliminación</strong>
    <?php if ($torneo['estado'] === 'finalizado'): ?>
      <span class="chip success">Campeón definido</span>
    <?php endif; ?>
  </div>

  <div class="br-wrap">
    <div class="br">
      <?php foreach ($rondasConPartidos as $rIndex => $bloque): ?>
        <?php $ronda = $bloque['ronda']; $partidos = $bloque['partidos']; ?>
        <div class="br-round">
          <div class="br-round-label"><?= View::e($ronda['nombre']) ?></div>
          <div class="br-body">
            <?php foreach ($partidos as $p): ?>
              <?php
                $nombreA  = $p['participante_a_nombre'] ?? $p['equipo_a_nombre'] ?? null;
                $nombreB  = $p['participante_b_nombre'] ?? $p['equipo_b_nombre'] ?? null;
                $res      = (new ResultadoModel())->getByEnfrentamiento((int)$p['id']);
                $ganadorA = $res && (float)$res['puntos_a'] > (float)$res['puntos_b'];
                $ganadorB = $res && (float)$res['puntos_b'] > (float)$res['puntos_a'];
              ?>
              <div class="br-match">
                <span class="br-in"></span>
                <span class="br-out"></span>
                <span class="br-v"></span>

                <div class="br-pair">
                  <?php if ($p['es_bye']): ?>
                    <div class="br-team winner">
                      <span><?= View::e($nombreA ?? '—') ?></span>
                      <span class="sc">BYE</span>
                    </div>
                    <div class="br-team bye">
                      <span>Avanza por bye</span>
                      <span class="sc">—</span>
                    </div>
                  <?php else: ?>
                    <div class="br-team <?= $ganadorA ? 'winner' : '' ?>">
                      <span><?= $nombreA ? View::e($nombreA) : '<em class="muted">Por definir</em>' ?></span>
                      <span class="sc"><?= $res ? (int)$res['puntos_a'] : '·' ?></span>
                    </div>
                    <div class="br-team <?= $ganadorB ? 'winner' : '' ?>">
                      <span><?= $nombreB ? View::e($nombreB) : '<em class="muted">Por definir</em>' ?></span>
                      <span class="sc"><?= $res ? (int)$res['puntos_b'] : '·' ?></span>
                    </div>
                  <?php endif; ?>

                  <?php if (($puedeEditar ?? false) && in_array($p['estado'], ['pendiente','en_curso']) && !$p['es_bye'] && $nombreA && $nombreB): ?>
                    <button class="br-action btn small primary"
                      data-modal-cargar
                      data-enf-id="<?= (int)$p['id'] ?>"
                      data-torneo-id="<?= (int)$torneo['id'] ?>"
                      data-nombre-a="<?= View::e($nombreA) ?>"
                      data-nombre-b="<?= View::e($nombreB) ?>">Cargar resultado</button>
                  <?php elseif (($puedeEditar ?? false) && $p['estado'] === 'finalizado' && $res): ?>
                    <button class="br-action btn small"
                      data-modal-corregir
                      data-enf-id="<?= (int)$p['id'] ?>"
                      data-torneo-id="<?= (int)$torneo['id'] ?>"
                      data-puntos-a="<?= (float)$res['puntos_a'] ?>"
                      data-puntos-b="<?= (float)$res['puntos_b'] ?>"
                      data-nombre-a="<?= View::e($nombreA ?? '') ?>"
                      data-nombre-b="<?= View::e($nombreB ?? '') ?>">Corregir</button>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>

      <!-- Columna de campeón -->
      <div class="br-round br-champion">
        <div class="br-round-label">Campeón</div>
        <div class="br-body">
          <div class="br-match">
            <span class="br-in"></span>
            <div class="br-pair br-trophy">
              <div class="br-team <?= $torneo['estado'] === 'finalizado' ? 'winner' : '' ?>">
                <span>
                  <?php if ($torneo['estado'] === 'finalizado'): ?>
                    🏆 <?= View::e($torneo['campeon_participante_nombre'] ?? $torneo['campeon_equipo_nombre'] ?? '—') ?>
                  <?php else: ?>
                    <em class="muted">A definir</em>
                  <?php endif; ?>
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
