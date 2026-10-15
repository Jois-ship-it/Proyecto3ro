<?php
/**
 * Lista de torneos del panel (el administrador ve todos; el organizador, los suyos).
 * Variables: $torneos (solo la página actual), $filtros, $pag (Paginador),
 *            $tipos [slug => nombre], $estados [valor => texto].
 */
$barra = [
    'accion' => '/admin/torneos',
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
    <div class="eyebrow">Gestión</div>
    <h1>Torneos</h1>
  </div>
  <?php if (Auth::isAdmin()): ?>
    <a class="btn primary" href="/admin/torneos/crear">+ Nuevo torneo</a>
  <?php endif; ?>
</section>

<div class="table-wrap">
  <div class="table-header"><?php include APP_PATH . '/views/partials/filtros.php'; ?></div>
  <div class="table-scroll">
    <table aria-label="Torneos">
      <thead>
        <tr><th scope="col">Nombre</th><th scope="col">Formato</th><th scope="col">Modalidad</th><th scope="col">Inscritos</th><th scope="col">Estado</th><th scope="col">Acciones</th></tr>
      </thead>
      <tbody>
        <?php if (empty($torneos)): ?>
          <tr><td colspan="6" class="muted"><?= $hayFiltros ? 'Ningún torneo coincide con la búsqueda.' : 'Sin torneos creados.' ?></td></tr>
        <?php else: ?>
          <?php foreach ($torneos as $t): ?>
          <tr>
            <td><strong><?= View::e($t['nombre']) ?></strong></td>
            <td><span class="chip"><?= View::e($t['tipo_nombre']) ?></span></td>
            <td><?= View::e(ucfirst($t['modalidad'])) ?></td>
            <td><?= (int)$t['inscritos'] ?></td>
            <td><?= View::estadoChip($t['estado']) ?></td>
            <td class="nowrap">
              <a class="btn small" href="/admin/torneos/<?= (int)$t['id'] ?>">Gestionar</a>
              <a class="btn small" href="/admin/torneos/editar/<?= (int)$t['id'] ?>">Editar</a>
              <a class="btn small" href="/admin/torneos/<?= (int)$t['id'] ?>/reporte">Reporte</a>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php $base = '/admin/torneos'; $unidad = 'torneos'; include APP_PATH . '/views/partials/paginacion.php'; ?>
</div>
