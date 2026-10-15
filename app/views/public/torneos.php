<?php
/**
 * Lista pública de torneos, con búsqueda por nombre y paginación.
 * Variables: $torneos (solo la página actual), $filtros, $pag (Paginador),
 *            $tipos [slug => nombre], $estados [valor => texto].
 */
$barra = [
    'accion' => '/torneos',
    'clase'  => 'card',
    'en_vivo' => '/api/torneos',   // torneos-en-vivo.js actualiza la lista con esta API; sin JavaScript, el formulario es un GET común
    'campos' => [
        ['nombre' => 'q',      'tipo' => 'buscar', 'etiqueta' => 'Buscar',  'placeholder' => 'Nombre del torneo'],
        ['nombre' => 'tipo',   'tipo' => 'opcion', 'etiqueta' => 'Formato', 'opciones' => $tipos],
        ['nombre' => 'estado', 'tipo' => 'opcion', 'etiqueta' => 'Estado',  'opciones' => $estados],
    ],
];
$hayFiltros = array_filter($filtros, fn($v) => $v !== '' && $v !== null) !== [];
?>
<section class="section">
  <div class="flex items-start justify-between gap-1 flex-wrap mb-12">
    <div>
      <div class="eyebrow">Vista pública</div>
      <h1 class="m-0">Torneos</h1>
    </div>
    <a class="btn" href="/torneos/calendario">Ver calendario</a>
  </div>

  <?php include APP_PATH . '/views/partials/filtros.php'; ?>

  <?php /* Lo que torneos-en-vivo.js reemplaza: la grilla (o el cartel) y el pie. El aviso lo oyen los lectores de pantalla. */ ?>
  <p class="solo-lectores" id="estado-lista" role="status"></p>
  <div id="lista-torneos">
  <?php if (empty($torneos)): ?>
    <div class="card sin-resultados">
      <p class="muted"><?= $hayFiltros ? 'No encontramos torneos con esa búsqueda.' : 'No hay torneos públicos disponibles.' ?></p>
    </div>
  <?php else: ?>
    <div class="grid cols-3">
      <?php foreach ($torneos as $t): ?>
      <article class="card flex flex-col gap-06">
        <div class="flex justify-between items-start gap-05">
          <strong class="rompe-texto"><?= View::e($t['nombre']) ?></strong>
          <?= View::estadoChip($t['estado']) ?>
        </div>
        <div>
          <span class="chip"><?= View::e($t['tipo_nombre']) ?></span>
          <span class="muted ml-04"><?= View::e(ucfirst($t['modalidad'])) ?></span>
        </div>
        <div class="muted"><?= (int)$t['inscritos'] ?> participantes</div>
        <div class="muted"><?= View::e($t['fecha_inicio'] ?? '—') ?> → <?= View::e($t['fecha_fin'] ?? '—') ?></div>
        <a class="btn small primary mt-auto" href="/torneo/<?= (int)$t['id'] ?>">Ver detalle →</a>
      </article>
      <?php endforeach; ?>
    </div>
    <?php $base = '/torneos'; $unidad = 'torneos'; include APP_PATH . '/views/partials/paginacion.php'; ?>
  <?php endif; ?>
  </div>
</section>
