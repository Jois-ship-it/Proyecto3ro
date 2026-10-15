<section class="section">
  <div class="flex items-center justify-between flex-wrap gap-1">
    <div>
      <div class="eyebrow">Vista pública</div>
      <h1>Calendario de torneos</h1>
    </div>
    <a class="btn" href="/torneos">← Volver a torneos</a>
  </div>

  <p class="muted">Se muestran los torneos públicos con sus fechas de inicio y fin. Hacé clic en un torneo para verlo.</p>

  <div class="mt-1">
    <div class="calendario-caja" id="calendar"></div>
  </div>

</section>

<?php // Los datos viajan en un bloque JSON, que el navegador no ejecuta: calendar.js lo lee de acá. ?>
<script type="application/json" id="torneos-cal"><?= json_encode(array_map(fn($t) => ['id' => (int)$t['id'], 'nombre' => $t['nombre'], 'inicio' => $t['fecha_inicio'] ?? null, 'fin' => $t['fecha_fin'] ?? null], $torneos), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
