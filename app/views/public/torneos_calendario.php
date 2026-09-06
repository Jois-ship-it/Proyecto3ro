<section class="section">
  <div style="display:flex;align-items:center;justify-content:space-between;">
    <div>
      <div class="eyebrow">Vista pública</div>
      <h1>Calendario de torneos</h1>
    </div>
    <a class="btn" href="/torneos">← Volver a torneos</a>
  </div>

  <p class="muted">Se muestran los torneos públicos con sus fechas de inicio y fin. Hacé clic en una fecha para ver el torneo.</p>

  <div style="margin-top:1rem;">
    <div id="calendar" style="background:var(--surface);border-radius:6px;padding:1rem"></div>
  </div>

</section>

<script>
  // Pasar datos al JS: asignar global para que calendar.js los lea como window.TORNEOS_CAL
  window.TORNEOS_CAL = <?= json_encode(array_map(function($t){ return [ 'id'=>(int)$t['id'], 'nombre'=>$t['nombre'], 'inicio'=>$t['fecha_inicio'] ?? null, 'fin'=>$t['fecha_fin'] ?? null ]; }, $torneos), JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="/assets/js/calendar.js"></script>
