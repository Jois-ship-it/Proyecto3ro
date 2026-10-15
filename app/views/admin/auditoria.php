<?php
/**
 * Registro de auditoría del panel, de lo más nuevo a lo más viejo.
 * Variables: $registros (la página actual), $filtros, $pag (Paginador), $acciones [valor => texto].
 */
$barra = [
    'accion' => '/admin/auditoria',
    'campos' => [
        ['nombre' => 'q',      'tipo' => 'buscar', 'etiqueta' => 'Buscar', 'placeholder' => 'Usuario, descripción o tabla'],
        ['nombre' => 'accion', 'tipo' => 'opcion', 'etiqueta' => 'Acción', 'opciones' => $acciones],
        ['nombre' => 'desde',  'tipo' => 'fecha',  'etiqueta' => 'Desde'],
        ['nombre' => 'hasta',  'tipo' => 'fecha',  'etiqueta' => 'Hasta'],
    ],
];
$hayFiltros = array_filter($filtros, fn($v) => $v !== '' && $v !== null) !== [];
?>
<section class="page-header">
  <div class="page-title">
    <div class="eyebrow">Sistema</div>
    <h1>Auditoría</h1>
    <p class="muted">Historial de acciones administrativas<?= $hayFiltros ? ' — ' . (int)$pag->total . ' registros con el filtro aplicado.' : ' — ' . (int)$pag->total . ' registros.' ?></p>
  </div>
</section>

<div class="table-wrap">
  <div class="table-header"><?php include APP_PATH . '/views/partials/filtros.php'; ?></div>
  <div class="table-scroll">
    <table aria-label="Registro de auditoría">
      <thead>
        <tr><th scope="col">Fecha</th><th scope="col">Usuario</th><th scope="col">Acción</th><th scope="col">Tabla</th><th scope="col">Descripción</th><th scope="col">IP</th></tr>
      </thead>
      <tbody>
        <?php if (empty($registros)): ?>
          <tr><td colspan="6" class="muted"><?= $hayFiltros ? 'Ningún registro coincide con la búsqueda.' : 'Sin registros.' ?></td></tr>
        <?php else: ?>
          <?php foreach ($registros as $r): ?>
          <tr>
            <td class="nowrap"><?= View::e($r['created_at']) ?></td>
            <td><?= View::e($r['usuario_nombre'] ?? 'Sistema') ?></td>
            <td><span class="chip"><?= View::e($r['accion']) ?></span></td>
            <td class="muted"><?= View::e($r['tabla_afectada'] ?? '—') ?></td>
            <td><?= View::e($r['descripcion'] ?? '—') ?></td>
            <td class="muted"><?= View::e($r['ip'] ?? '—') ?></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php $base = '/admin/auditoria'; $unidad = 'registros'; include APP_PATH . '/views/partials/paginacion.php'; ?>
</div>
