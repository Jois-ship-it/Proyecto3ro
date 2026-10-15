<?php
/**
 * Configuración general del sistema.
 *
 * Variables: $campos (el catálogo ConfiguracionSistemaService::CAMPOS), $valores (clave => valor
 * actual) y, si se volvió del formulario con un error, $enviados (lo que había escrito).
 */
$grupos = [
    'sitio'  => ['Sitio', 'Cómo se presenta la plataforma a quien la visita.'],
    'acceso' => ['Inicio de sesión', 'Cuántos intentos fallidos se admiten y cuánto dura un bloqueo.'],
];
$valor = static fn(string $clave) => ($enviados[$clave] ?? $valores[$clave] ?? '');
?>
<section class="page-header">
  <div class="page-title">
    <div class="eyebrow">Sistema</div>
    <h1>Configuración</h1>
    <p class="muted">Ajustes generales de la plataforma. Los cambios valen desde el próximo pedido y quedan en la auditoría.</p>
  </div>
</section>

<form method="POST" action="/admin/configuracion" class="form-card form-config" novalidate>
  <?= Csrf::field() ?>

  <?php foreach ($grupos as $grupo => [$titulo, $descripcion]): ?>
  <div class="form-seccion">
    <div class="eyebrow"><?= View::e($titulo) ?></div>
    <p class="muted"><?= View::e($descripcion) ?></p>
  </div>
  <div class="form-grid">
    <?php foreach ($campos as $clave => $campo): ?>
      <?php if ($campo['grupo'] !== $grupo) continue; ?>
      <div class="field<?= $campo['tipo'] === 'interruptor' ? ' field-ancho' : '' ?>">
        <?php if ($campo['tipo'] === 'interruptor'): ?>
          <input type="hidden" name="<?= View::e($clave) ?>" value="0">
          <label for="cfg_<?= View::e($clave) ?>">
            <input type="checkbox" id="cfg_<?= View::e($clave) ?>" name="<?= View::e($clave) ?>" value="1"
                   <?= $valor($clave) === '1' ? 'checked' : '' ?> aria-describedby="ayuda_<?= View::e($clave) ?>">
            <?= View::e($campo['etiqueta']) ?>
          </label>
        <?php else: ?>
          <label for="cfg_<?= View::e($clave) ?>"><?= View::e($campo['etiqueta']) ?></label>
          <?php if ($campo['tipo'] === 'entero'): ?>
            <input type="number" id="cfg_<?= View::e($clave) ?>" name="<?= View::e($clave) ?>" required
                   min="<?= (int)$campo['min'] ?>" max="<?= (int)$campo['max'] ?>" step="1" inputmode="numeric"
                   value="<?= View::e($valor($clave)) ?>" aria-describedby="ayuda_<?= View::e($clave) ?>">
          <?php else: ?>
            <input type="<?= $campo['tipo'] === 'email' ? 'email' : 'text' ?>" autocomplete="off" id="cfg_<?= View::e($clave) ?>" name="<?= View::e($clave) ?>"
                   maxlength="<?= (int)$campo['max'] ?>" <?= !empty($campo['obligatorio']) ? 'required' : '' ?>
                   value="<?= View::e($valor($clave)) ?>" aria-describedby="ayuda_<?= View::e($clave) ?>">
          <?php endif; ?>
        <?php endif; ?>
        <small class="muted" id="ayuda_<?= View::e($clave) ?>"><?= View::e($campo['ayuda']) ?></small>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>

  <div class="form-actions">
    <a class="btn" href="/admin">Cancelar</a>
    <button type="submit" class="btn primary">Guardar cambios</button>
  </div>
</form>
