<?php
/**
 * Reporte global del sistema (solo administrador).
 * Variables: $generado (fecha y hora), $generadoPor, $resumen (v_estadisticas_sistema),
 *            $porFormato [formato => cantidad], $correcciones [estado => cantidad],
 *            $cuentas [etiqueta => cantidad], $torneos (solo la página actual), $filtros,
 *            $pag (Paginador), $tipos [slug => nombre], $estados [valor => texto].
 */
$barra = [
    'accion' => '/admin/reportes',
    'campos' => [
        ['nombre' => 'q',      'tipo' => 'buscar', 'etiqueta' => 'Buscar',  'placeholder' => 'Nombre del torneo'],
        ['nombre' => 'tipo',   'tipo' => 'opcion', 'etiqueta' => 'Formato', 'opciones' => $tipos],
        ['nombre' => 'estado', 'tipo' => 'opcion', 'etiqueta' => 'Estado',  'opciones' => $estados],
    ],
];
$hayFiltros = array_filter($filtros, fn($v) => $v !== '' && $v !== null) !== [];
$csv = '/admin/reportes/torneos.csv' . ($hayFiltros ? '?' . http_build_query(array_filter($filtros)) : '');
?>
<section class="page-header">
  <div class="page-title">
    <div class="eyebrow">Sistema</div>
    <h1>Reportes</h1>
    <p class="muted">Estado general de la plataforma al <?= View::fechaHora($generado) ?>.</p>
  </div>
  <div class="actions no-print">
    <button type="button" class="btn" data-imprimir>Imprimir / PDF</button>
    <a class="btn primary" href="<?= View::e($csv) ?>">Descargar CSV</a>
  </div>
</section>
<p class="solo-impresion"><?= View::e($_sitio['nombre']) ?> · Reporte general generado el <?= View::fechaHora($generado) ?> por <?= View::e($generadoPor) ?></p>

<div class="kpi-row">
  <div class="stat-card"><strong><?= (int)$resumen['torneos_total'] ?></strong><span>Torneos</span></div>
  <div class="stat-card"><strong><?= (int)$resumen['participantes_activos'] ?></strong><span>Participantes activos</span></div>
  <div class="stat-card"><strong><?= (int)$resumen['equipos_activos'] ?></strong><span>Equipos activos</span></div>
  <div class="stat-card"><strong><?= (int)$resumen['partidos_jugados'] ?></strong><span>Partidos jugados</span></div>
</div>

<div class="grid cols-4 mb-1">
  <article class="card">
    <h2 class="como-h3">Torneos por estado</h2>
    <div class="dato"><span>Borrador</span><strong><?= (int)$resumen['torneos_borrador'] ?></strong></div>
    <div class="dato"><span>En inscripción</span><strong><?= (int)$resumen['torneos_inscripcion'] ?></strong></div>
    <div class="dato"><span>En curso</span><strong><?= (int)$resumen['torneos_en_curso'] ?></strong></div>
    <div class="dato"><span>Finalizados</span><strong><?= (int)$resumen['torneos_finalizados'] ?></strong></div>
    <div class="dato"><span>Cancelados</span><strong><?= (int)$resumen['torneos_cancelados'] ?></strong></div>
  </article>
  <article class="card">
    <h2 class="como-h3">Torneos por formato</h2>
    <?php foreach ($porFormato as $formato => $cantidad): ?>
      <div class="dato"><span><?= View::e($formato) ?></span><strong><?= (int)$cantidad ?></strong></div>
    <?php endforeach; ?>
  </article>
  <article class="card">
    <h2 class="como-h3">Correcciones de resultados</h2>
    <?php foreach ($correcciones as $estado => $cantidad): ?>
      <div class="dato"><span><?= View::e(ucfirst($estado)) ?>s</span><strong><?= (int)$cantidad ?></strong></div>
    <?php endforeach; ?>
  </article>
  <article class="card">
    <h2 class="como-h3">Cuentas</h2>
    <?php foreach ($cuentas as $etiqueta => $cantidad): ?>
      <div class="dato"><span><?= View::e($etiqueta) ?></span><strong><?= (int)$cantidad ?></strong></div>
    <?php endforeach; ?>
  </article>
</div>

<div class="table-wrap">
  <div class="table-header"><?php include APP_PATH . '/views/partials/filtros.php'; ?></div>
  <div class="table-scroll">
    <table aria-label="Torneos del sistema">
      <thead>
        <tr><th scope="col">Torneo</th><th scope="col">Formato</th><th scope="col">Estado</th><th scope="col" class="num">Inscritos</th><th scope="col" class="num">Partidos</th><th scope="col">Campeón</th><th scope="col" class="no-print">Acciones</th></tr>
      </thead>
      <tbody>
        <?php if (empty($torneos)): ?>
          <tr><td colspan="7" class="muted"><?= $hayFiltros ? 'Ningún torneo coincide con la búsqueda.' : 'Sin torneos creados.' ?></td></tr>
        <?php else: ?>
          <?php foreach ($torneos as $t): ?>
          <tr>
            <td><strong><?= View::e($t['nombre']) ?></strong></td>
            <td><span class="chip"><?= View::e($t['tipo_nombre']) ?></span></td>
            <td><?= View::estadoChip($t['estado']) ?></td>
            <td class="num"><?= (int)$t['inscritos'] ?></td>
            <td class="num"><?= (int)$t['partidos_jugados'] ?> / <?= (int)$t['partidos_total'] ?></td>
            <td><?= View::e($t['campeon'] ?? '—') ?></td>
            <td class="no-print nowrap"><a class="btn small" href="/admin/torneos/<?= (int)$t['id'] ?>/reporte">Reporte</a></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php $base = '/admin/reportes'; $unidad = 'torneos'; include APP_PATH . '/views/partials/paginacion.php'; ?>
</div>
