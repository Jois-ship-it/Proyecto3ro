<?php
/**
 * Lista de equipos del panel.
 * Variables: $equipos (la página actual), $filtros, $pag (Paginador), $estados [valor => texto].
 */
$barra = [
    'accion' => '/admin/equipos',
    'campos' => [
        ['nombre' => 'q',      'tipo' => 'buscar', 'etiqueta' => 'Buscar', 'placeholder' => 'Nombre, categoría o disciplina'],
        ['nombre' => 'estado', 'tipo' => 'opcion', 'etiqueta' => 'Estado', 'opciones' => $estados],
    ],
];
$hayFiltros = array_filter($filtros, fn($v) => $v !== '' && $v !== null) !== [];
?>
<section class="page-header">
  <div class="page-title">
    <div class="eyebrow">Gestión</div>
    <h1>Equipos</h1>
  </div>
  <a class="btn primary" href="/admin/equipos/crear">+ Nuevo equipo</a>
</section>

<div class="table-wrap">
  <div class="table-header"><?php include APP_PATH . '/views/partials/filtros.php'; ?></div>
  <div class="table-scroll">
    <table aria-label="Equipos">
      <thead>
        <tr><th scope="col">Nombre</th><th scope="col">Categoría</th><th scope="col">Integrantes</th><th scope="col">Estado</th><th scope="col">Acciones</th></tr>
      </thead>
      <tbody>
        <?php if (empty($equipos)): ?>
          <tr><td colspan="5" class="muted"><?= $hayFiltros ? 'Ningún equipo coincide con la búsqueda.' : 'Sin equipos registrados.' ?></td></tr>
        <?php else: ?>
          <?php foreach ($equipos as $e): ?>
          <tr>
            <td>
              <div class="flex items-center gap-06">
                <?= View::avatar($e['nombre'], Upload::url('logos', $e['logo'] ?? null), 32) ?>
                <strong><?= View::e($e['nombre']) ?></strong>
              </div>
            </td>
            <td><?= View::e($e['categoria'] ?? $e['disciplina'] ?? '—') ?></td>
            <td><?= (int)($e['total_integrantes'] ?? 0) ?></td>
            <td><?= View::estadoChip($e['estado']) ?></td>
            <td>
              <a class="btn small" href="/admin/equipos/editar/<?= (int)$e['id'] ?>">Gestionar</a>
              <?php $activo = $e['estado'] === 'activo'; ?>
              <form class="d-inline" method="POST" action="/admin/equipos/eliminar/<?= (int)$e['id'] ?>"
                    data-confirmar="<?= $activo ? '¿Desactivar' : '¿Reactivar' ?> este equipo?">
                <?= Csrf::field() ?>
                <button class="btn small <?= $activo ? 'danger' : 'primary' ?>"><?= $activo ? 'Desactivar' : 'Activar' ?></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php $base = '/admin/equipos'; $unidad = 'equipos'; include APP_PATH . '/views/partials/paginacion.php'; ?>
</div>
