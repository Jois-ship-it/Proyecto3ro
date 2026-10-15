<?php
/**
 * Lista de participantes del panel.
 * Variables: $participantes (la página actual), $filtros, $pag (Paginador), $estados [valor => texto].
 */
$barra = [
    'accion' => '/admin/participantes',
    'campos' => [
        ['nombre' => 'q',      'tipo' => 'buscar', 'etiqueta' => 'Buscar', 'placeholder' => 'Nombre, apodo, documento o correo'],
        ['nombre' => 'estado', 'tipo' => 'opcion', 'etiqueta' => 'Estado', 'opciones' => $estados],
    ],
];
$hayFiltros = array_filter($filtros, fn($v) => $v !== '' && $v !== null) !== [];
?>
<section class="page-header">
  <div class="page-title">
    <div class="eyebrow">Gestión</div>
    <h1>Participantes</h1>
    <p class="muted">Personas individuales que compiten en torneos. Se registran por cuenta propia.</p>
  </div>
  <a class="btn" href="/admin/registros">Registros pendientes →</a>
</section>

<div class="table-wrap">
  <div class="table-header"><?php include APP_PATH . '/views/partials/filtros.php'; ?></div>
  <div class="table-scroll">
    <table aria-label="Participantes">
      <thead>
        <tr><th scope="col">Nombre</th><th scope="col">Nick / Doc</th><th scope="col">Contacto</th><th scope="col">Equipos</th><th scope="col">Estado</th><th scope="col">Acciones</th></tr>
      </thead>
      <tbody>
        <?php if (empty($participantes)): ?>
          <tr><td colspan="6" class="muted"><?= $hayFiltros ? 'Ningún participante coincide con la búsqueda.' : 'Sin participantes registrados.' ?></td></tr>
        <?php else: ?>
          <?php foreach ($participantes as $p): ?>
          <tr>
            <td><strong><?= View::e($p['nombre']) ?></strong></td>
            <td><?= View::e($p['nick'] ?? $p['documento'] ?? '—') ?></td>
            <td><?= View::e($p['email'] ?? $p['telefono'] ?? '—') ?></td>
            <td><span class="muted"><?= View::e($p['equipos'] ?? '—') ?></span></td>
            <td><?= View::estadoChip($p['estado']) ?></td>
            <td>
              <a class="btn small" href="/admin/participantes/editar/<?= (int)$p['id'] ?>">Editar</a>
              <?php $activo = $p['estado'] === 'activo'; ?>
              <form class="d-inline" method="POST" action="/admin/participantes/eliminar/<?= (int)$p['id'] ?>"
                    data-confirmar="<?= $activo ? '¿Desactivar' : '¿Reactivar' ?> este participante?">
                <?= Csrf::field() ?>
                <button class="btn small <?= $activo ? 'danger' : 'primary' ?>"><?= $activo ? 'Desactivar' : 'Activar' ?></button>
              </form>
              <?php if (!empty($p['usuario_id'])): ?>
                <?php if (($p['estado'] ?? '') === 'suspendido'): ?>
                  <form class="d-inline" method="POST" action="/admin/participantes/desbloquear/<?= (int)$p['usuario_id'] ?>">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="return" value="/admin/participantes">
                    <button class="btn small primary">Desbloquear cuenta</button>
                  </form>
                <?php else: ?>
                  <form class="d-inline" method="POST" action="/admin/participantes/bloquear/<?= (int)$p['usuario_id'] ?>" data-confirmar="¿Bloquear la cuenta vinculada a este participante?">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="return" value="/admin/participantes">
                    <button class="btn small danger">Bloquear cuenta</button>
                  </form>
                <?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php $base = '/admin/participantes'; $unidad = 'participantes'; include APP_PATH . '/views/partials/paginacion.php'; ?>
</div>
