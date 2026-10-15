<?php
/**
 * Barra de búsqueda y filtros de un listado: un formulario GET, así la dirección queda
 * copiable y el botón «Atrás» funciona.
 *
 * Variables:
 *   $barra    ['accion' => ruta del listado, 'clase' => clases extra del <form>,
 *              'campos' => lista de campos,
 *              'en_vivo' => (opcional) ruta de la API que usa torneos-en-vivo.js para actualizar la lista sin recargar]
 *             campo: ['nombre', 'tipo' => buscar|opcion|fecha, 'etiqueta',
 *                     'placeholder' (buscar), 'opciones' [valor => texto] (opcion)]
 *   $filtros  valores vigentes, ya saneados por el controlador
 */
$hayFiltros = array_filter($filtros, fn($v) => $v !== '' && $v !== null) !== [];
?>
<form class="filtros <?= View::e($barra['clase'] ?? '') ?>" method="GET" action="<?= View::e($barra['accion']) ?>" role="search"<?= !empty($barra['en_vivo']) ? ' data-en-vivo="' . View::e($barra['en_vivo']) . '"' : '' ?>>
  <?php foreach ($barra['campos'] as $campo):
      $nombre = $campo['nombre'];
      $id     = 'f-' . $nombre;
      $valor  = (string) ($filtros[$nombre] ?? '');
  ?>
  <div class="field<?= $campo['tipo'] === 'buscar' ? ' buscar' : '' ?>">
    <label for="<?= View::e($id) ?>"><?= View::e($campo['etiqueta']) ?></label>
    <?php if ($campo['tipo'] === 'opcion'): ?>
      <select id="<?= View::e($id) ?>" name="<?= View::e($nombre) ?>">
        <option value="">Todos</option>
        <?php foreach ($campo['opciones'] as $clave => $texto): ?>
          <option value="<?= View::e($clave) ?>" <?= $valor === (string) $clave ? 'selected' : '' ?>><?= View::e($texto) ?></option>
        <?php endforeach; ?>
      </select>
    <?php elseif ($campo['tipo'] === 'fecha'): ?>
      <input id="<?= View::e($id) ?>" type="date" name="<?= View::e($nombre) ?>" value="<?= View::e($valor) ?>">
    <?php else: ?>
      <input id="<?= View::e($id) ?>" type="search" name="<?= View::e($nombre) ?>" value="<?= View::e($valor) ?>"
             maxlength="80" placeholder="<?= View::e($campo['placeholder'] ?? '') ?>">
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <div class="actions">
    <button class="btn small primary" type="submit">Buscar</button>
    <?php if ($hayFiltros): ?>
      <a class="btn small ghost" href="<?= View::e($barra['accion']) ?>">Limpiar</a>
    <?php endif; ?>
  </div>
</form>
