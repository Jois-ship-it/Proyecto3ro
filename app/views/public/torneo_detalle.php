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
      <a class="muted" href="/torneos" style="font-size:.9rem">← Torneos</a>
      <div class="eyebrow" style="margin-top:.5rem"><?= View::e($tipo['nombre']) ?></div>
      <h1><?= View::e($torneo['nombre']) ?></h1>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
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
  <div class="card" style="background:rgba(37,194,129,.1);border-color:rgba(37,194,129,.3);text-align:center;padding:1.5rem;margin-bottom:1.5rem">
    <div class="eyebrow" style="color:var(--success)">Campeón del torneo</div>
    <h2>🏆 <?= View::e($torneo['campeon_participante_nombre'] ?? $torneo['campeon_equipo_nombre'] ?? '—') ?></h2>
  </div>
  <?php endif; ?>

  <!-- KPIs -->
  <div class="grid cols-3" style="margin-bottom:1.5rem">
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
  <div class="card" style="margin-bottom:1.5rem">
    <div class="eyebrow">Datos del evento</div>
    <div class="grid cols-2" style="margin-top:.75rem">
      <?php foreach ($datosEvento as $clave => $valor): ?>
        <?php
          $def   = $clavesConfig[$clave];
          $ancho = $def['tipo'] === 'texto_largo' ? ' style="grid-column:1/-1"' : '';
        ?>
        <div<?= $ancho ?>>
          <div class="muted" style="font-size:.8rem"><?= View::e($def['etiqueta']) ?></div>
          <?php if ($clave === 'contacto'): ?>
            <a href="mailto:<?= View::e($valor) ?>"><?= View::e($valor) ?></a>
          <?php elseif ($clave === 'cierre_inscripcion'): ?>
            <strong><?= View::e(date('d/m/Y', strtotime($valor))) ?></strong>
            <?php if ($torneo['estado'] === 'inscripcion' && date('Y-m-d') > $valor): ?>
              <span class="chip warning" style="padding:.1rem .45rem;font-size:.7rem">plazo cerrado</span>
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
  <div style="margin-bottom:1.5rem">
    <h3>Próximos partidos</h3>
    <p class="muted">Partidos con fecha igual o posterior a hoy.</p>
    <div style="margin-top:.75rem">
      <div id="calendarMatches" style="display:flex;flex-direction:column;gap:.75rem"></div>
    </div>
  </div>

  <script>
    const MATCHES = <?= json_encode($matchesForCalendar, JSON_UNESCAPED_UNICODE) ?>;

    (function () {
      const cont = document.getElementById('calendarMatches');
      if (!cont) return;

      function escapeHtml(value) {
        return String(value ?? '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/\"/g, '&quot;')
          .replace(/'/g, '&#039;');
      }

      function parseDateParts(input) {
        if (input === null || input === undefined) return null;
        const raw = String(input).trim();
        if (!raw) return null;

        const datePart = raw.includes('T') ? raw.split('T')[0] : raw.split(' ')[0];
        const [year, month, day] = datePart.split('-').map(Number);
        if (!year || !month || !day) return null;

        const timeRaw = raw.includes('T') ? (raw.split('T')[1] || '00:00:00') : (raw.includes(' ') ? (raw.split(' ')[1] || '00:00:00') : '00:00:00');
        const [hour = '00', minute = '00', second = '00'] = (timeRaw || '00:00:00').split(':').map(String);

        return { year, month, day, hour: Number(hour), minute: Number(minute), second: Number(second) };
      }

      function sameOrAfterToday(dateValue) {
        const parts = parseDateParts(dateValue);
        if (!parts) return false;

        const today = new Date();
        const todayDate = new Date(today.getFullYear(), today.getMonth(), today.getDate());
        const matchDate = new Date(parts.year, parts.month - 1, parts.day);
        return matchDate.getTime() >= todayDate.getTime();
      }

      function sortMatches(list) {
        return [...list].sort((a, b) => {
          const left = parseDateParts(a.fecha);
          const right = parseDateParts(b.fecha);
          if (!left || !right) return 0;

          const leftKey = Date.UTC(left.year, left.month - 1, left.day, left.hour, left.minute, left.second);
          const rightKey = Date.UTC(right.year, right.month - 1, right.day, right.hour, right.minute, right.second);
          return leftKey - rightKey;
        });
      }

      function formatDateLabel(dateValue) {
        const parts = parseDateParts(dateValue);
        if (!parts) return 'Sin fecha';

        const weekdays = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
        const months = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
        const date = new Date(parts.year, parts.month - 1, parts.day);
        const weekday = weekdays[date.getDay()];
        const month = months[date.getMonth()];
        const base = `${weekday.charAt(0).toUpperCase() + weekday.slice(1)} ${date.getDate()} de ${month}`;

        const hasTime = parts.hour !== 0 || parts.minute !== 0 || parts.second !== 0;
        return hasTime ? `${base} · ${String(parts.hour).padStart(2, '0')}:${String(parts.minute).padStart(2, '0')}` : base;
      }

      function renderEmpty(message) {
        cont.innerHTML = '<div class="card" style="padding:1rem;"><div class="muted">' + escapeHtml(message) + '</div></div>';
      }

      const upcomingMatches = sortMatches(MATCHES.filter(match => sameOrAfterToday(match.fecha)));

      if (!MATCHES.length) {
        renderEmpty('No hay partidos programados.');
        return;
      }

      if (!upcomingMatches.length) {
        renderEmpty('No hay próximos partidos programados.');
        return;
      }

      const list = document.createElement('div');
      list.style.display = 'flex';
      list.style.flexDirection = 'column';
      list.style.gap = '.75rem';

      upcomingMatches.forEach((match) => {
        const item = document.createElement('div');
        item.className = 'card';
        item.style.padding = '1rem';
        item.style.display = 'flex';
        item.style.flexDirection = 'column';
        item.style.gap = '.35rem';

        const teams = document.createElement('div');
        teams.style.fontWeight = '700';
        teams.style.lineHeight = '1.3';
        teams.innerHTML = escapeHtml(match.a) + ' <span class="muted">vs</span> ' + escapeHtml(match.b);

        const meta = document.createElement('div');
        const rondaText = (match.ronda || match.ronda === 0) ? 'Ronda ' + escapeHtml(String(match.ronda)) : 'Ronda';
        const ordenText = (match.orden || match.orden === 0) ? ' · Orden ' + escapeHtml(String(match.orden)) : '';
        meta.className = 'muted';
        meta.style.fontSize = '.85rem';
        meta.innerHTML = rondaText + ordenText;

        const fechaText = document.createElement('div');
        fechaText.className = 'muted';
        fechaText.style.fontSize = '.85rem';
        fechaText.textContent = formatDateLabel(match.fecha);

        item.appendChild(teams);
        item.appendChild(meta);
        item.appendChild(fechaText);
        list.appendChild(item);
      });

      cont.innerHTML = '';
      cont.appendChild(list);
    })();
  </script>
  <?php endif; ?>  <!-- Tabla / Ranking -->
  <?php if (!empty($tabla)): ?>
    <div style="margin-bottom:1.5rem">
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
  <div style="margin-top:1.5rem">
    <h3>Participantes</h3>
    <div class="grid cols-4">
      <?php foreach ($inscritos as $ins): ?>
        <?php
          $perfilUrl = !empty($ins['participante_id']) ? '/jugador/' . (int)$ins['participante_id']
                     : (!empty($ins['equipo_id']) ? '/equipo/' . (int)$ins['equipo_id'] : null);
          $nombre = $ins['participante_nombre'] ?? $ins['equipo_nombre'] ?? '—';
        ?>
        <?php if ($perfilUrl): ?><a class="card" href="<?= $perfilUrl ?>" style="padding:.75rem;display:block"><?php else: ?><div class="card" style="padding:.75rem"><?php endif; ?>
          <strong><?= View::e($nombre) ?></strong>
          <?php if (!empty($ins['nick'])): ?><div class="muted"><?= View::e($ins['nick']) ?></div><?php endif; ?>
        <?php if ($perfilUrl): ?></a><?php else: ?></div><?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</section>
