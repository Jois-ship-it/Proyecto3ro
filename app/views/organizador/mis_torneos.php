<?php
/**
 * Los torneos del organizador (el administrador ve todos).
 * Variables: $torneos (la página actual), $filtros, $pag (Paginador), $tipos [slug => nombre], $estados [valor => texto].
 */
$barra = [
    'accion' => '/organizador/torneos',
    'campos' => [
        ['nombre' => 'q',      'tipo' => 'buscar', 'etiqueta' => 'Buscar',  'placeholder' => 'Nombre del torneo'],
        ['nombre' => 'tipo',   'tipo' => 'opcion', 'etiqueta' => 'Formato', 'opciones' => $tipos],
        ['nombre' => 'estado', 'tipo' => 'opcion', 'etiqueta' => 'Estado',  'opciones' => $estados],
    ],
];
$hayFiltros = array_filter($filtros, fn($v) => $v !== '' && $v !== null) !== [];
?>
<section class="page-header">
  <div class="page-title">
    <div class="eyebrow">Organizador</div>
    <h1>Mis torneos</h1>
  </div>
</section>

<div class="table-wrap">
  <div class="table-header"><?php include APP_PATH . '/views/partials/filtros.php'; ?></div>
  <div class="table-scroll">
    <table aria-label="Mis torneos">
      <thead>
        <tr><th scope="col">Nombre</th><th scope="col">Formato</th><th scope="col">Estado</th><th scope="col">Acciones</th></tr>
      </thead>
      <tbody>
        <?php if (empty($torneos)): ?>
          <tr><td colspan="4" class="muted"><?= $hayFiltros ? 'Ningún torneo coincide con la búsqueda.' : 'Sin torneos asignados.' ?></td></tr>
        <?php else: ?>
          <?php foreach ($torneos as $t): ?>
          <tr>
            <td><strong><?= View::e($t['nombre']) ?></strong></td>
            <td><span class="chip"><?= View::e($t['tipo_nombre']) ?></span></td>
            <td><?= View::estadoChip($t['estado']) ?></td>
            <td class="nowrap">
              <a class="btn small primary" href="/organizador/torneos/<?= (int)$t['id'] ?>">Gestionar</a>
              <a class="btn small" href="/admin/torneos/<?= (int)$t['id'] ?>/reporte">Reporte</a>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php $base = '/organizador/torneos'; $unidad = 'torneos'; include APP_PATH . '/views/partials/paginacion.php'; ?>
</div>
