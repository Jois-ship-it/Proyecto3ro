<section class="hero">
  <div>
    <div class="eyebrow">Sistema de Gestión Deportiva Modular</div>
    <h1>Organizá torneos con estructura clara.</h1>
    <p>Plataforma web para gestionar torneos deportivos, mentales y electrónicos. Liga, Eliminación Directa y Sistema Suizo.</p>
    <div class="hero-actions">
      <a class="btn primary" href="/torneos">Ver torneos</a>
      <a class="btn" href="/login">Ingresar al panel</a>
    </div>
  </div>

  <?php if ($destacado): ?>
  <div class="hero-card card">
    <div class="eyebrow">Torneo destacado</div>
    <h3><?= View::e($destacado['nombre']) ?></h3>
    <p><?= View::e($destacado['tipo_nombre']) ?> · <?= View::estadoChip($destacado['estado']) ?></p>
    <div class="grid cols-2" style="margin-top:.75rem">
      <div class="stat-card">
        <strong><?= (new TorneoModel())->contarInscritos((int)$destacado['id']) ?></strong>
        <span>Inscritos</span>
      </div>
      <?php if ($destacado['tipo_slug'] === 'suizo' && $destacado['rondas_suizo']): ?>
      <div class="stat-card">
        <strong><?= (int)$destacado['rondas_suizo'] ?></strong>
        <span>Rondas</span>
      </div>
      <?php endif; ?>
    </div>
    <a class="btn primary" href="/torneo/<?= (int)$destacado['id'] ?>" style="margin-top:.75rem;display:inline-flex">Ver detalle</a>
  </div>
  <?php endif; ?>
</section>

<section class="section grid cols-3">
  <article class="card">
    <span class="chip">Liga</span>
    <h3>Tabla de posiciones</h3>
    <p>Todos contra todos. Puntos configurables por victoria, empate y derrota. Criterios de desempate automáticos.</p>
  </article>
  <article class="card">
    <span class="chip warning">Eliminación Directa</span>
    <h3>Llave / bracket</h3>
    <p>Los ganadores avanzan ronda a ronda hasta definir un campeón. Soporta byes para cantidades no potencia de 2.</p>
  </article>
  <article class="card">
    <span class="chip success">Sistema Suizo</span>
    <h3>Rondas por rendimiento</h3>
    <p>Emparejamientos basados en puntaje acumulado. Sin eliminación. Ideal para torneos con muchos participantes.</p>
  </article>
</section>
