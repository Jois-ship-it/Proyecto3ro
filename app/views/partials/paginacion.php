<?php
/**
 * Pie de un listado: el resumen «Mostrando X–Y de N» y los botones de página.
 *
 * Variables:
 *   $pag      Paginador del listado
 *   $base     ruta del listado (sin query), a la que se le agregan los filtros y ?page=
 *   $filtros  filtros vigentes, para que cambiar de página no los pierda
 *   $unidad   plural de lo que se lista: «torneos», «equipos»…
 */
$ir = fn(int $n): string => View::e(Paginador::enlace($base, $filtros, $n));
?>
<div class="listado-pie">
  <p class="resumen">
    <?php if ($pag->total === 0): ?>
      Sin resultados.
    <?php else: ?>
      Mostrando <?= $pag->desde ?>–<?= $pag->hasta ?> de <?= $pag->total ?> <?= View::e($unidad) ?>
    <?php endif; ?>
  </p>

  <?php if ($pag->paginas > 1): ?>
  <nav class="paginacion" aria-label="Páginas de <?= View::e($unidad) ?>">
    <?php if ($pag->hayAnterior()): ?>
      <a class="btn small" rel="prev" href="<?= $ir($pag->pagina - 1) ?>">‹ Anterior</a>
    <?php else: ?>
      <span class="btn small disabled" aria-disabled="true">‹ Anterior</span>
    <?php endif; ?>

    <?php foreach ($pag->ventana() as $n): ?>
      <?php if ($n === null): ?>
        <span class="paginacion-gap" aria-hidden="true">…</span>
      <?php elseif ($n === $pag->pagina): ?>
        <span class="btn small primary" aria-current="page"><?= $n ?></span>
      <?php else: ?>
        <a class="btn small" href="<?= $ir($n) ?>" aria-label="Página <?= $n ?>"><?= $n ?></a>
      <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($pag->hayPosterior()): ?>
      <a class="btn small" rel="next" href="<?= $ir($pag->pagina + 1) ?>">Siguiente ›</a>
    <?php else: ?>
      <span class="btn small disabled" aria-disabled="true">Siguiente ›</span>
    <?php endif; ?>
  </nav>
  <?php endif; ?>
</div>
