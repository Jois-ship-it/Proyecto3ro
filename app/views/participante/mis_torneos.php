<?php
/**
 * Los torneos en los que está inscrito el participante.
 * Variables: $torneos (la página actual), $filtros, $pag (Paginador), $estados [valor => texto].
 */
$barra = [
    'accion' => '/participante/torneos',
    'campos' => [
        ['nombre' => 'q',      'tipo' => 'buscar', 'etiqueta' => 'Buscar', 'placeholder' => 'Nombre del torneo'],
        ['nombre' => 'estado', 'tipo' => 'opcion', 'etiqueta' => 'Estado del torneo', 'opciones' => $estados],
    ],
];
$hayFiltros = array_filter($filtros, fn($v) => $v !== '' && $v !== null) !== [];
?>
<section class="page-header">
  <div class="page-title">
    <div class="eyebrow">Participante</div>
    <h1>Mis torneos</h1>
  </div>
</section>

<div class="table-wrap">
  <div class="table-header"><?php include APP_PATH . '/views/partials/filtros.php'; ?></div>
  <div class="table-scroll">
    <table aria-label="Mis torneos">
      <thead>
        <tr><th scope="col">Torneo</th><th scope="col">Formato</th><th scope="col">Estado</th><th scope="col">Inscripción</th><th scope="col">Ver</th></tr>
      </thead>
      <tbody>
        <?php if (empty($torneos)): ?>
          <tr><td colspan="5" class="muted"><?= $hayFiltros ? 'Ningún torneo coincide con la búsqueda.' : 'No estás inscrito en ningún torneo.' ?></td></tr>
        <?php else: ?>
          <?php foreach ($torneos as $t): ?>
          <tr>
            <td><strong><?= View::e($t['torneo_nombre']) ?></strong></td>
            <td><span class="chip"><?= View::e($t['tipo_slug']) ?></span></td>
            <td><?= View::estadoChip($t['torneo_estado']) ?></td>
            <td><?= View::estadoChip($t['estado']) ?></td>
            <td><a class="btn small" href="/torneo/<?= (int)$t['torneo_id'] ?>">Ver →</a></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php $base = '/participante/torneos'; $unidad = 'torneos'; include APP_PATH . '/views/partials/paginacion.php'; ?>
</div>
