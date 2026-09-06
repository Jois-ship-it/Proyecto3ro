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

            // Card clickeable para edición: ahora muestra un menú con acciones (Programar / Cargar)
            $clickAttrs = '';
            $clickClass = '';
            $hasActionMenu = false;
            if (($puedeEditar ?? false) && $p) {
                if (in_array($p['estado'], ['pendiente', 'en_curso'], true) && !$esBye && $nombreA && $nombreB) {
                    $clickClass = ' clickable';
                    $fpInput = !empty($p['fecha_programada']) ? date('Y-m-d\\TH:i', strtotime((string)$p['fecha_programada'])) : '';
                    $clickAttrs = 'data-enf-id="' . (int) $p['id'] . '"'
                        . ' data-torneo-id="' . (int) $torneo['id'] . '"'
                        . ' data-fecha="' . View::e($fpInput) . '"'
                        . ' data-nombre-a="' . View::e($nombreA) . '"'
                        . ' data-nombre-b="' . View::e($nombreB) . '"'
                        . ' tabindex="0" role="button" aria-haspopup="true" aria-expanded="false"';
                    $hasActionMenu = true;
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
              <?php if ($hasActionMenu): ?>
                <div class="bk-action-menu" aria-hidden="true">
                  <div class="bk-action-inner">
                    <button type="button" class="btn small" data-action="cargar" data-enf-id="<?= (int)$p['id'] ?>" data-torneo-id="<?= (int)$torneo['id'] ?>" data-nombre-a="<?= View::e($nombreA) ?>" data-nombre-b="<?= View::e($nombreB) ?>">Cargar resultado</button>
                    <button type="button" class="btn small" data-action="programar" data-enf-id="<?= (int)$p['id'] ?>" data-fecha="<?= View::e($fpInput) ?>" data-nombre-a="<?= View::e($nombreA) ?>" data-nombre-b="<?= View::e($nombreB) ?>">Programar</button>
                  </div>
                </div>
              <?php endif; ?>
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
<script>
  // Menú de acciones dentro del bracket: Programar / Cargar resultado
  (function () {
    function closeAllMenus() {
      document.querySelectorAll('.bk-action-menu').forEach(m => {
        m.style.display = 'none';
        m.style.visibility = '';
        m.style.position = 'absolute';
        m.style.left = '';
        m.style.top = '';
        m.style.bottom = '';
        m.removeAttribute('data-portal');
        const card = m.closest('.bk-card'); if (card) card.setAttribute('aria-expanded','false');
        m.setAttribute('aria-hidden','true');
      });
    }

    // Toggle menú al click o Enter/Space cuando la tarjeta está enfocada
    document.querySelectorAll('.bk-card.clickable').forEach(card => {
      card.addEventListener('click', function (e) {
        if (e.target.closest('.bk-action-menu')) return; // clicks dentro del menú no togglean
        const menu = card.querySelector('.bk-action-menu');
        if (!menu) return;
        const visible = menu.style.display === 'block';
        closeAllMenus();
        if (!visible) {
          // Mostrar temporalmente para medir su tamaño natural
          menu.style.display = 'block';
          menu.style.visibility = 'hidden';
          menu.style.position = 'absolute';
          menu.style.left = '';
          menu.style.right = '';
          menu.style.top = '';
          menu.style.bottom = '';

          const mH = menu.offsetHeight;
          const mW = menu.offsetWidth;
          const cardRect = card.getBoundingClientRect();

          // Decide si se abre hacia arriba si no cabe abajo
          const spaceBelow = window.innerHeight - cardRect.bottom;
          const spaceAbove = cardRect.top;
          const openUp = (spaceBelow < mH && spaceAbove > mH);

          // Usar posicionamiento fijo para evitar recorte por contenedores con overflow:hidden
          menu.style.position = 'fixed';
          if (openUp) {
            menu.style.top = Math.max(6, cardRect.top - mH - 6) + 'px';
          } else {
            menu.style.top = Math.min(window.innerHeight - mH - 6, cardRect.bottom + 6) + 'px';
          }
          // Alinear al borde derecho de la tarjeta (similar a CSS right: .5rem)
          const right = Math.min(window.innerWidth - 8, window.innerWidth - (cardRect.right - 8));
          // compute left to keep menu within viewport
          let left = cardRect.right - 8 - mW;
          if (left < 8) left = 8;
          if (left + mW > window.innerWidth - 8) left = window.innerWidth - mW - 8;
          menu.style.left = left + 'px';

          menu.style.visibility = 'visible';
          menu.setAttribute('aria-hidden','false');
          card.setAttribute('aria-expanded','true');
          menu.dataset.portal = 'true';

          // focus al primer botón
          const btn = menu.querySelector('button'); if (btn) btn.focus();
        }
      });
      card.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault(); card.click();
        } else if (e.key === 'Escape') {
          closeAllMenus();
        }
      });
    });

    // Cerrar menús al hacer scroll/resize para evitar que queden descolocados
    ['scroll', 'resize'].forEach(evt => window.addEventListener(evt, function () { closeAllMenus(); }));

    // Acciones de los botones del menú
    document.addEventListener('click', function (e) {
      const act = e.target.closest('.bk-action-menu button');
      if (!act) return;
      const action = act.dataset.action;
      const enfId = act.dataset.enfId;
      const torneoId = act.dataset.torneoId || document.getElementById('programarEnfId')?.closest('form')?.querySelector('input[name="torneo_id"]')?.value || '';
      const nombreA = act.dataset.nombreA || '';
      const nombreB = act.dataset.nombreB || '';
      const fecha = act.dataset.fecha || '';

      if (action === 'cargar') {
        // Abrir modal Cargar resultado (mismo comportamiento que admin)
        const cargarEnf = document.getElementById('cargarEnfId');
        if (cargarEnf) cargarEnf.value = enfId;
        const modal = document.getElementById('modalCargar');
        const title = document.getElementById('modalCargarTitulo');
        const lblA = document.getElementById('lblCargarA');
        const lblB = document.getElementById('lblCargarB');
        if (title) title.textContent = nombreA + ' vs ' + nombreB;
        if (lblA) lblA.textContent = nombreA;
        if (lblB) lblB.textContent = nombreB;
        const puntosA = document.getElementById('cargarPuntosA'); if (puntosA) puntosA.value = '';
        const puntosB = document.getElementById('cargarPuntosB'); if (puntosB) puntosB.value = '';
        if (modal) { modal.style.display = 'grid'; modal.setAttribute('aria-hidden','false'); }
      }

      if (action === 'programar') {
        const programarEnf = document.getElementById('programarEnfId'); if (programarEnf) programarEnf.value = enfId;
        const programarFecha = document.getElementById('programarFecha'); if (programarFecha) programarFecha.value = fecha || '';
        const modalTitle = document.getElementById('modalProgramarTitulo'); if (modalTitle) modalTitle.textContent = 'Programar · ' + nombreA + ' vs ' + nombreB;
        const modal = document.getElementById('modalProgramar'); if (modal) { modal.style.display = 'grid'; modal.setAttribute('aria-hidden','false'); }
      }

      // cerrar menús tras accion
      closeAllMenus();
    });

    // Cerrar al click fuera
    document.addEventListener('click', function (e) {
      if (e.target.closest('.bk-card')) return; closeAllMenus();
    }, true);

    // Cerrar con Escape
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAllMenus(); });

    // Asegurar que los menús están cerrados al cargar la página (evita menús visibles tras reload)
    closeAllMenus();
  })();
</script>
