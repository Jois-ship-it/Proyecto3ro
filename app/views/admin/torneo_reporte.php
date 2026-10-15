<?php
/**
 * Reporte de un torneo (administrador: cualquiera; organizador: los suyos).
 * Variables: $torneo, $resumen (inscritos, partidos_total, partidos_jugados, partidos_pendientes,
 *            byes, puntos_total, promedio), $tabla (liga y suizo), $partidos, $campeon,
 *            $configuracion (sede…), $generado, $generadoPor.
 */
$base    = '/admin/torneos/' . (int) $torneo['id'] . '/reporte';
$volver  = Auth::isAdmin() ? '/admin/torneos/' . (int) $torneo['id'] : '/organizador/torneos/' . (int) $torneo['id'];
$avance  = $resumen['partidos_total'] > 0
    ? (int) round($resumen['partidos_jugados'] * 100 / $resumen['partidos_total'])
    : 0;
$conTabla = in_array($torneo['tipo_slug'], ['liga', 'suizo'], true);
$puntos   = ucfirst((string) ($torneo['nombre_puntos'] ?? 'puntos'));
?>
<section class="page-header">
  <div class="page-title">
    <div class="eyebrow">Reporte del torneo</div>
    <h1 class="rompe-texto"><?= View::e($torneo['nombre']) ?></h1>
    <p class="muted">
      <?= View::e($torneo['tipo_nombre']) ?> · <?= View::e(ucfirst($torneo['modalidad'])) ?> ·
      generado el <?= View::fechaHora($generado) ?>
    </p>
  </div>
  <div class="actions no-print">
    <a class="btn ghost" href="<?= $volver ?>">‹ Volver al torneo</a>
    <button type="button" class="btn" data-imprimir>Imprimir / PDF</button>
  </div>
</section>
<p class="solo-impresion"><?= View::e($_sitio['nombre']) ?> · Reporte del torneo generado el <?= View::fechaHora($generado) ?> por <?= View::e($generadoPor) ?></p>

<div class="kpi-row">
  <div class="stat-card"><strong><?= (int)$resumen['inscritos'] ?></strong><span>Inscritos</span></div>
  <div class="stat-card"><strong><?= (int)$resumen['partidos_jugados'] ?> / <?= (int)$resumen['partidos_total'] ?></strong><span>Partidos jugados</span></div>
  <div class="stat-card"><strong><?= View::numero($resumen['puntos_total']) ?></strong><span><?= View::e($puntos) ?> anotados</span></div>
  <div class="stat-card"><strong class="fs-135"><?= $campeon !== null ? View::e($campeon) : 'En disputa' ?></strong><span>Campeón</span></div>
</div>

<div class="grid cols-2 mb-1">
  <article class="card">
    <h2 class="como-h3">Datos del torneo</h2>
    <div class="dato"><span>Estado</span><?= View::estadoChip($torneo['estado']) ?></div>
    <div class="dato"><span>Organizador</span><strong><?= View::e($torneo['organizador_nombre'] ?? '—') ?></strong></div>
    <div class="dato"><span>Fechas</span><strong><?= View::fecha($torneo['fecha_inicio']) ?> → <?= View::fecha($torneo['fecha_fin']) ?></strong></div>
    <div class="dato"><span>Sede</span><strong><?= View::e(($configuracion['sede'] ?? '') !== '' ? $configuracion['sede'] : '—') ?></strong></div>
  </article>
  <article class="card">
    <h2 class="como-h3">Avance</h2>
    <div class="barra" role="progressbar" aria-valuenow="<?= $avance ?>" aria-valuemin="0" aria-valuemax="100"><span class="pct-<?= max(0, min(100, (int) $avance)) ?>"></span></div>
    <p class="muted m-05-0-075"><?= $avance ?>% de los partidos jugados</p>
    <div class="dato"><span>Pendientes</span><strong><?= (int)$resumen['partidos_pendientes'] ?></strong></div>
    <div class="dato"><span>Pasan de ronda sin jugar (bye)</span><strong><?= (int)$resumen['byes'] ?></strong></div>
    <div class="dato"><span>Promedio por partido</span><strong><?= View::numero($resumen['promedio']) ?></strong></div>
  </article>
</div>

<?php if ($conTabla): ?>
<div class="table-wrap mb-1">
  <div class="table-header">
    <strong>Tabla de posiciones</strong>
    <a class="btn small no-print" href="<?= $base ?>/posiciones.csv">Descargar CSV</a>
  </div>
  <div class="table-scroll">
    <table aria-label="Tabla de posiciones">
      <thead>
        <tr><th scope="col">#</th><th scope="col">Competidor</th><th scope="col" class="num">PJ</th><th scope="col" class="num">PG</th><th scope="col" class="num">PE</th><th scope="col" class="num">PP</th><th scope="col" class="num">PF</th><th scope="col" class="num">PC</th><th scope="col" class="num">Dif</th><th scope="col" class="num"><?= View::e($puntos) ?></th></tr>
      </thead>
      <tbody>
        <?php if (empty($tabla)): ?>
          <tr><td colspan="10" class="muted">Aún no hay datos en la tabla.</td></tr>
        <?php else: ?>
          <?php foreach ($tabla as $f): ?>
          <tr>
            <td><strong><?= (int)$f['posicion'] ?></strong></td>
            <td><?= View::e($f['competidor']) ?></td>
            <td class="num"><?= (int)$f['pj'] ?></td>
            <td class="num"><?= (int)$f['pg'] ?></td>
            <td class="num"><?= (int)$f['pe'] ?></td>
            <td class="num"><?= (int)$f['pp'] ?></td>
            <td class="num"><?= (int)$f['pf'] ?></td>
            <td class="num"><?= (int)$f['pc'] ?></td>
            <td class="num"><?= (int)$f['diferencia'] > 0 ? '+' : '' ?><?= (int)$f['diferencia'] ?></td>
            <td class="num"><strong><?= (int)$f['puntos'] ?></strong></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="table-wrap">
  <div class="table-header">
    <strong>Partidos</strong>
    <span class="actions no-print">
      <a class="btn small" href="<?= $base ?>/partidos.csv">Descargar CSV de partidos</a>
      <a class="btn small" href="<?= $base ?>/inscritos.csv">Descargar CSV de inscritos</a>
    </span>
  </div>
  <div class="table-scroll">
    <table aria-label="Partidos del torneo">
      <thead>
        <tr><th scope="col">Ronda</th><th scope="col">Local</th><th scope="col" class="num">Resultado</th><th scope="col">Visitante</th><th scope="col">Ganador</th><th scope="col">Estado</th></tr>
      </thead>
      <tbody>
        <?php if (empty($partidos)): ?>
          <tr><td colspan="6" class="muted">Todavía no se generó la competencia.</td></tr>
        <?php else: ?>
          <?php foreach ($partidos as $p): ?>
          <tr>
            <td><?= View::e($p['ronda_nombre']) ?></td>
            <td><?= View::e($p['lado_a'] ?? '—') ?></td>
            <td class="num">
              <?php if ($p['puntos_a'] !== null && (int)$p['es_bye'] === 0): ?>
                <strong><?= View::numero($p['puntos_a']) ?> – <?= View::numero($p['puntos_b']) ?></strong>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td><?= (int)$p['es_bye'] === 1 ? '<span class="muted">Bye</span>' : View::e($p['lado_b'] ?? '—') ?></td>
            <td><?php if ($p['es_empate']): ?><span class="muted">Empate</span><?php else: ?><?= View::e($p['ganador_texto']) ?><?php endif; ?></td>
            <td><?= View::estadoChip($p['estado']) ?></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
