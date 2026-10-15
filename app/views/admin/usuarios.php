<?php
/**
 * Lista de las cuentas de UN rol (organizadores o administradores).
 * Variables: $ctx (singular, plural, descripcion, ruta), $usuarios (la página actual), $unicoAdminActivoId,
 *            $filtros, $pag (Paginador), $estados [valor => texto].
 */
$yo    = (int) (Auth::id() ?? 0);
$barra = [
    'accion' => $ctx['ruta'],
    'campos' => [
        ['nombre' => 'q',      'tipo' => 'buscar', 'etiqueta' => 'Buscar', 'placeholder' => 'Nombre o correo'],
        ['nombre' => 'estado', 'tipo' => 'opcion', 'etiqueta' => 'Estado', 'opciones' => $estados],
    ],
];
$hayFiltros = array_filter($filtros, fn($v) => $v !== '' && $v !== null) !== [];
?>
<section class="page-header">
  <div class="page-title">
    <div class="eyebrow">Administración</div>
    <h1><?= View::e($ctx['plural']) ?></h1>
    <p class="muted"><?= View::e($ctx['descripcion']) ?></p>
  </div>
  <a class="btn primary" href="<?= $ctx['ruta'] ?>/crear">+ Nuevo <?= View::e($ctx['singular']) ?></a>
</section>

<div class="table-wrap">
  <div class="table-header"><?php include APP_PATH . '/views/partials/filtros.php'; ?></div>
  <div class="table-scroll">
    <table aria-label="Cuentas">
      <thead>
        <tr><th scope="col">ID</th><th scope="col">Nombre</th><th scope="col">Email</th><th scope="col">Estado</th><th scope="col">Acciones</th></tr>
      </thead>
      <tbody>
        <?php if (empty($usuarios)): ?>
          <tr><td colspan="5" class="muted"><?php if ($hayFiltros): ?>Ninguna cuenta coincide con la búsqueda.<?php else: ?>No hay <?= View::e(strtolower($ctx['plural'])) ?>. Creá uno con “Nuevo <?= View::e($ctx['singular']) ?>”.<?php endif; ?></td></tr>
        <?php else: ?>
          <?php foreach ($usuarios as $u):
              $esPropia = (int) $u['id'] === $yo;
              $esUnico  = (int) $u['id'] === (int) $unicoAdminActivoId;
              $activo   = $u['estado'] === 'activo';
          ?>
          <tr>
            <td><?= (int)$u['id'] ?></td>
            <td>
              <strong><?= View::e($u['nombre']) ?></strong>
              <?php if ($esPropia): ?><span class="chip ml-04">Vos</span><?php endif; ?>
            </td>
            <td><?= View::e($u['email']) ?></td>
            <td>
              <?= View::estadoChip($u['estado']) ?>
              <?php if ($u['estado'] === 'bloqueada'): ?>
                <div class="muted fs-80 mt-025">
                  <?= !empty($u['bloqueada_hasta']) ? 'Hasta las ' . date('H:i', strtotime($u['bloqueada_hasta'])) : 'Hasta que se desbloquee' ?>
                </div>
              <?php endif; ?>
            </td>
            <td>
              <a class="btn small" href="<?= $ctx['ruta'] ?>/editar/<?= (int)$u['id'] ?>">Editar</a>
              <?php if ($esPropia || $esUnico): ?>
                <span class="muted fs-85 ml-05">
                  <?= $esPropia ? 'Es tu cuenta' : 'Único administrador activo' ?>
                </span>
              <?php else: ?>
                <?php if ($u['estado'] !== 'bloqueada'): /* una cuenta bloqueada solo se desbloquea */ ?>
                <form class="d-inline" method="POST" action="<?= $ctx['ruta'] ?>/eliminar/<?= (int)$u['id'] ?>"
                      data-confirmar="<?= $activo ? '¿Desactivar' : '¿Reactivar' ?> esta cuenta?">
                  <?= Csrf::field() ?>
                  <button class="btn small <?= $activo ? 'danger' : 'primary' ?>"><?= $activo ? 'Desactivar' : 'Activar' ?></button>
                </form>
                <?php endif; ?>
                <?php if ($u['estado'] === 'bloqueada'): ?>
                  <form class="d-inline" method="POST" action="<?= $ctx['ruta'] ?>/desbloquear/<?= (int)$u['id'] ?>">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="return" value="<?= $ctx['ruta'] ?>">
                    <button class="btn small primary">Desbloquear</button>
                  </form>
                <?php elseif ($activo): ?>
                  <form class="d-inline" method="POST" action="<?= $ctx['ruta'] ?>/bloquear/<?= (int)$u['id'] ?>" data-confirmar="¿Bloquear esta cuenta?">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="return" value="<?= $ctx['ruta'] ?>">
                    <button class="btn small danger">Bloquear</button>
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
  <?php $base = $ctx['ruta']; $unidad = 'cuentas'; include APP_PATH . '/views/partials/paginacion.php'; ?>
</div>
