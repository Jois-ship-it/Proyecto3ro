<?php
$slug      = $tipo['slug'] ?? '';
$esLiga    = $slug === 'liga';
$esElim    = $slug === 'eliminacion_directa';
$esSuizo   = $slug === 'suizo';
$puedeEditar = false; // Vista pública: sin edición
?>
<section class="section">
  <div class="page-header">
    <div class="page-title">
      <a class="muted fs-90" href="/torneos">← Torneos</a>
      <div class="eyebrow mt-05"><?= View::e($tipo['nombre']) ?></div>
      <h1 class="rompe-texto"><?= View::e($torneo['nombre']) ?></h1>
      <div class="flex gap-05 flex-wrap">
        <?= View::estadoChip($torneo['estado']) ?>
        <span class="chip"><?= View::e(ucfirst($torneo['modalidad'])) ?></span>
        <?php if ($esSuizo && $torneo['rondas_suizo']): ?>
          <span class="chip"><?= count($rondasConPartidos) ?>/<?= (int)$torneo['rondas_suizo'] ?> rondas</span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Campeón -->
  <?php if ($torneo['estado'] === 'finalizado'): ?>
  <div class="card banner-campeon-ancho">
    <div class="eyebrow color-ok">Campeón del torneo</div>
    <h2>🏆 <?= View::e($torneo['campeon_participante_nombre'] ?? $torneo['campeon_equipo_nombre'] ?? '—') ?></h2>
  </div>
  <?php endif; ?>

  <!-- KPIs -->
  <div class="grid cols-3 mb-15">
    <div class="stat-card"><strong><?= count($inscritos) ?></strong><span>Participantes</span></div>
    <div class="stat-card"><strong><?= count($rondasConPartidos) ?></strong><span>Rondas</span></div>
    <?php if ($torneo['descripcion']): ?>
    <div class="card"><p class="muted"><?= View::e($torneo['descripcion']) ?></p></div>
    <?php endif; ?>
  </div>

  <?php
    /*
     * Datos del evento (tabla configuraciones_torneo). El bloque entero se
     * omite si el torneo no tiene ninguno cargado: son todos opcionales.
     */
    $datosEvento = array_filter($configuracion, fn(string $v) => $v !== '');
  ?>
  <?php if ($datosEvento !== []): ?>
  <div class="card mb-15">
    <div class="eyebrow">Datos del evento</div>
    <div class="grid cols-2 mt-075">
      <?php foreach ($datosEvento as $clave => $valor): ?>
        <?php
          $def   = $clavesConfig[$clave];
          $ancho = $def['tipo'] === 'texto_largo' ? ' class="span-all"' : '';
        ?>
        <div<?= $ancho ?>>
          <div class="muted fs-80"><?= View::e($def['etiqueta']) ?></div>
          <?php if ($clave === 'contacto'): ?>
            <a href="mailto:<?= View::e($valor) ?>"><?= View::e($valor) ?></a>
          <?php elseif ($clave === 'cierre_inscripcion'): ?>
            <strong><?= View::e(date('d/m/Y', strtotime($valor))) ?></strong>
            <?php if ($torneo['estado'] === 'inscripcion' && date('Y-m-d') > $valor): ?>
              <span class="chip warning chip-mini">plazo cerrado</span>
            <?php endif; ?>
          <?php else: ?>
            <strong><?= View::e($valor) ?></strong>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Calendario de partidos programados -->
  <?php
    $matchesForCalendar = [];
    foreach ($rondasConPartidos as $bloque) {
      $rondaActual = $bloque['ronda'] ?? [];
      $partidos = $bloque['partidos'] ?? [];
      foreach ($partidos as $partido) {
        if (empty($partido['fecha_programada'])) {
          continue;
        }

        $matchesForCalendar[] = [
          'id' => (int)($partido['id'] ?? 0),
          'ronda' => (int)($rondaActual['numero'] ?? 0),
          'orden' => (int)($partido['orden'] ?? 0),
          'fecha' => $partido['fecha_programada'],
          'a' => $partido['participante_a_nombre'] ?? $partido['equipo_a_nombre'] ?? '—',
          'b' => $partido['participante_b_nombre'] ?? $partido['equipo_b_nombre'] ?? '—',
          'url' => $partido['url'] ?? '',
        ];
      }
    }
  ?>

  <?php if (isset($torneo['estado']) && ($torneo['estado'] === 'en_curso' || $torneo['estado'] === 'finalizado')): ?>
  <div class="mb-15">
    <h2 class="como-h3">Próximos partidos</h2>
    <p class="muted">Partidos con fecha igual o posterior a hoy.</p>
    <div class="mt-075">
      <div class="flex flex-col gap-075" id="calendarMatches"></div>
    </div>
  </div>

  <?php // Los partidos viajan en un bloque JSON, que el navegador no ejecuta: proximos-partidos.js lo lee de acá. ?>
  <script type="application/json" id="partidos-calendario"><?= json_encode($matchesForCalendar, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
  <?php endif; ?>  <!-- Tabla / Ranking -->
  <?php if (!empty($tabla)): ?>
    <div class="mb-15">
      <?php if ($esSuizo): ?>
        <?php include APP_PATH . '/views/partials/suizo_ranking.php'; ?>
      <?php else: ?>
        <?php include APP_PATH . '/views/partials/liga_tabla.php'; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- Bracket / Partidos -->
  <?php if (!empty($rondasConPartidos)): ?>
    <?php if ($esElim): ?>
      <?php include APP_PATH . '/views/partials/eliminacion_bracket.php'; ?>
    <?php elseif ($esSuizo): ?>
      <?php include APP_PATH . '/views/partials/suizo_rondas.php'; ?>
    <?php else: ?>
      <?php include APP_PATH . '/views/partials/liga_fixture.php'; ?>
    <?php endif; ?>
  <?php endif; ?>

  <!-- Inscritos -->
  <?php if (!empty($inscritos)): ?>
  <div class="mt-15">
    <h2 class="como-h3">Participantes</h2>
    <div class="grid cols-4">
      <?php foreach ($inscritos as $ins): ?>
        <?php
          // Con los perfiles cerrados, quien no inició sesión ve el nombre sin enlace.
          $perfilUrl = !($perfilesVisibles ?? true) ? null
                     : (!empty($ins['participante_id']) ? '/jugador/' . (int)$ins['participante_id']
                     : (!empty($ins['equipo_id']) ? '/equipo/' . (int)$ins['equipo_id'] : null));
          $nombre = $ins['participante_nombre'] ?? $ins['equipo_nombre'] ?? '—';
        ?>
        <?php if ($perfilUrl): ?><a class="card tarjeta-enlace" href="<?= $perfilUrl ?>"><?php else: ?><div class="card p-075"><?php endif; ?>
          <strong><?= View::e($nombre) ?></strong>
          <?php if (!empty($ins['nick'])): ?><div class="muted"><?= View::e($ins['nick']) ?></div><?php endif; ?>
        <?php if ($perfilUrl): ?></a><?php else: ?></div><?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</section>
