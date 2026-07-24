<?php
/**
 * Bracket de Eliminación Directa estilo Challonge.
 * Posicionamiento computado: cada match hijo se alinea al punto medio
 * vertical de sus dos padres; la final queda centrada respecto al árbol.
 * Variables: $rondasConPartidos, $torneo, $puedeEditar, $csrf
 */
$bk_rem = fn(float $v): string => number_format($v, 3, '.', '') . 'rem';

// Nombre de ronda por cantidad de partidos
$bk_nombreRonda = function (int $matchesEnRonda): string {
    return match ($matchesEnRonda) {
        1  => 'Final',
        2  => 'Semifinales',
        4  => 'Cuartos de Final',
        8  => 'Octavos de Final',
        16 => 'Dieciseisavos',
        default => 'Ronda de ' . ($matchesEnRonda * 2),
    };
};

// Lookup de partidos reales por [numero_ronda][orden]
$bk_lookup = [];
$bk_labels = [];
foreach ($rondasConPartidos as $bloque) {
    $num = (int) $bloque['ronda']['numero'];
    $bk_labels[$num] = $bloque['ronda']['nombre'];
    foreach ($bloque['partidos'] as $p) {
        $bk_lookup[$num][(int) $p['orden']] = $p;
    }
}

// Cantidad de partidos en la primera ronda (define el tamaño del árbol)
$M1 = isset($rondasConPartidos[0]) ? count($rondasConPartidos[0]['partidos']) : 0;

// Cantidad total de rondas del árbol completo (con placeholders)
$totalRounds = 1; $tmp = $M1;
while ($tmp > 1) { $tmp = intdiv($tmp, 2); $totalRounds++; }

// Dimensiones (rem)
$cardW = 12.5; $cardH = 4.4; $gapY = 1.6; $colGap = 3.6; $colW = $cardW + $colGap;
$padX = 0.6; $padTop = 2.0; $padBot = 0.8;

// Centros verticales computados por ronda/slot
$center = [];
for ($r = 0; $r < $totalRounds; $r++) {
    $cnt = max(1, intdiv($M1, (int) pow(2, $r)));
    for ($s = 0; $s < $cnt; $s++) {
        $center[$r][$s] = ($r === 0)
            ? $s * ($cardH + $gapY) + $cardH / 2
            : ($center[$r - 1][2 * $s] + $center[$r - 1][2 * $s + 1]) / 2;
    }
}

$champCenter = $center[$totalRounds - 1][0] ?? $cardH / 2;
$totalH = ($M1 > 0 ? ($M1 - 1) * ($cardH + $gapY) + $cardH : $cardH) + $padTop + $padBot;
$totalW = $totalRounds * $colW + $cardW + $padX * 2;

$resModel = new ResultadoModel();
?>
<div class="panel" style="padding:1rem 1rem .6rem">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem">
    <strong>Llave de eliminación</strong>
    <?php if ($torneo['estado'] === 'finalizado'): ?>
      <span class="chip success">Campeón definido</span>
    <?php endif; ?>
  </div>

  <?php if ($M1 === 0): ?>
    <p class="muted">El bracket aún no fue generado.</p>
  <?php else: ?>
  <div class="bk-scroll">
    <div class="bk" style="width:<?= $bk_rem($totalW) ?>;height:<?= $bk_rem($totalH) ?>">

      <?php for ($r = 0; $r < $totalRounds; $r++): ?>
        <?php
          $matchesEnRonda = max(1, intdiv($M1, (int) pow(2, $r)));
          $numRonda = $r + 1;
          $label = $bk_labels[$numRonda] ?? $bk_nombreRonda($matchesEnRonda);
        ?>
        <div class="bk-col-label" style="left:<?= $bk_rem($r * $colW + $padX) ?>;top:.2rem;width:<?= $bk_rem($cardW) ?>">
          <?= View::e($label) ?>
        </div>

        <?php for ($s = 0; $s < $matchesEnRonda; $s++): ?>
          <?php
            $p   = $bk_lookup[$numRonda][$s + 1] ?? null;
            $cy  = $center[$r][$s];
            $top = $cy - $cardH / 2 + $padTop;
            $left = $r * $colW + $padX;

            $nombreA = $p ? ($p['participante_a_nombre'] ?? $p['equipo_a_nombre'] ?? null) : null;
            $nombreB = $p ? ($p['participante_b_nombre'] ?? $p['equipo_b_nombre'] ?? null) : null;
            $esBye   = $p && $p['es_bye'];
            $res     = $p ? $resModel->getByEnfrentamiento((int) $p['id']) : null;
            $winA    = $res && (float) $res['puntos_a'] > (float) $res['puntos_b'];
            $winB    = $res && (float) $res['puntos_b'] > (float) $res['puntos_a'];

            // Card clickeable para edición
            $clickAttrs = '';
            $clickClass = '';
            if (($puedeEditar ?? false) && $p) {
                if (in_array($p['estado'], ['pendiente', 'en_curso'], true) && !$esBye && $nombreA && $nombreB) {
                    $clickClass = ' clickable';
                    $clickAttrs = 'data-modal-cargar'
                        . ' data-enf-id="' . (int) $p['id'] . '"'
                        . ' data-torneo-id="' . (int) $torneo['id'] . '"'
                        . ' data-nombre-a="' . View::e($nombreA) . '"'
                        . ' data-nombre-b="' . View::e($nombreB) . '"'
                        . ' title="Cargar resultado"';
                } elseif ($p['estado'] === 'finalizado' && $res && !$esBye) {
                    $clickClass = ' clickable';
                    $clickAttrs = 'data-modal-corregir'
                        . ' data-enf-id="' . (int) $p['id'] . '"'
                        . ' data-torneo-id="' . (int) $torneo['id'] . '"'
                        . ' data-puntos-a="' . (float) $res['puntos_a'] . '"'
                        . ' data-puntos-b="' . (float) $res['puntos_b'] . '"'
                        . ' data-nombre-a="' . View::e($nombreA ?? '') . '"'
                        . ' data-nombre-b="' . View::e($nombreB ?? '') . '"'
                        . (Auth::isAdmin() ? ' title="Corregir resultado"' : ' title="Solicitar corrección"');
                }
            }
          ?>
          <div class="bk-card<?= $clickClass ?>" <?= $clickAttrs ?>
               style="left:<?= $bk_rem($left) ?>;top:<?= $bk_rem($top) ?>;width:<?= $bk_rem($cardW) ?>;height:<?= $bk_rem($cardH) ?>">
            <?php if (!$p): ?>
              <div class="bk-row placeholder"><span class="nm">Por definir</span><span class="sc">·</span></div>
              <div class="bk-row placeholder"><span class="nm">Por definir</span><span class="sc">·</span></div>
            <?php elseif ($esBye): ?>
              <div class="bk-row win"><span class="nm"><?= View::e($nombreA ?? '—') ?></span><span class="sc">BYE</span></div>
              <div class="bk-row bye"><span class="nm">Avanza por bye</span><span class="sc">—</span></div>
            <?php else: ?>
              <div class="bk-row <?= $winA ? 'win' : '' ?>">
                <span class="nm"><?= $nombreA ? View::e($nombreA) : 'Por definir' ?></span>
                <span class="sc"><?= $res ? (int) $res['puntos_a'] : '·' ?></span>
              </div>
              <div class="bk-row <?= $winB ? 'win' : '' ?>">
                <span class="nm"><?= $nombreB ? View::e($nombreB) : 'Por definir' ?></span>
                <span class="sc"><?= $res ? (int) $res['puntos_b'] : '·' ?></span>
              </div>
            <?php endif; ?>
          </div>

          <?php
            // Conectores ortogonales hacia los dos padres (rondas >= 1)
            if ($r >= 1):
              $p0 = $center[$r - 1][2 * $s];
              $p1 = $center[$r - 1][2 * $s + 1];
              $xParentRight = ($r - 1) * $colW + $cardW + $padX;
              $xMid = $xParentRight + $colGap / 2;
              $vy = min($p0, $p1) + $padTop;
              $vh = abs($p1 - $p0);
          ?>
            <div class="bk-line" style="left:<?= $bk_rem($xParentRight) ?>;top:<?= $bk_rem($p0 + $padTop) ?>;width:<?= $bk_rem($colGap / 2) ?>;height:2px"></div>
            <div class="bk-line" style="left:<?= $bk_rem($xParentRight) ?>;top:<?= $bk_rem($p1 + $padTop) ?>;width:<?= $bk_rem($colGap / 2) ?>;height:2px"></div>
            <div class="bk-line" style="left:<?= $bk_rem($xMid) ?>;top:<?= $bk_rem($vy) ?>;width:2px;height:<?= $bk_rem($vh) ?>"></div>
            <div class="bk-line" style="left:<?= $bk_rem($xMid) ?>;top:<?= $bk_rem($cy + $padTop) ?>;width:<?= $bk_rem($colGap / 2) ?>;height:2px"></div>
          <?php endif; ?>
        <?php endfor; ?>
      <?php endfor; ?>

      <!-- Columna de Campeón -->
      <?php
        $xFinalRight = ($totalRounds - 1) * $colW + $cardW + $padX;
        $xChampLeft  = $totalRounds * $colW + $padX;
        $champTop    = $champCenter - $cardH / 2 + $padTop;
        $campeonNombre = $torneo['campeon_participante_nombre'] ?? $torneo['campeon_equipo_nombre'] ?? null;
      ?>
      <div class="bk-col-label" style="left:<?= $bk_rem($totalRounds * $colW + $padX) ?>;top:.2rem;width:<?= $bk_rem($cardW) ?>">Campeón</div>
      <div class="bk-line" style="left:<?= $bk_rem($xFinalRight) ?>;top:<?= $bk_rem($champCenter + $padTop) ?>;width:<?= $bk_rem($colGap) ?>;height:2px"></div>
      <div class="bk-card champion" style="left:<?= $bk_rem($xChampLeft) ?>;top:<?= $bk_rem($champTop) ?>;width:<?= $bk_rem($cardW) ?>;height:<?= $bk_rem($cardH) ?>">
        <div class="bk-row <?= $campeonNombre ? 'win' : 'placeholder' ?>" style="flex:1">
          <span class="nm"><?= $campeonNombre ? View::e($campeonNombre) : 'A definir' ?></span>
          <span class="sc">🏆</span>
        </div>
      </div>

    </div>
  </div>
  <?php endif; ?>
</div>
