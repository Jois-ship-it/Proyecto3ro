<?php
/**
 * Matriz de permisos: una tabla por rol, una fila por módulo, una casilla por
 * acción. Se guarda todo junto con un solo botón, así una edición es un cambio
 * coherente y no una secuencia de estados intermedios.
 *
 * @var array  $roles
 * @var array  $modulos
 * @var array  $permisos    rol_id → slug → fila
 * @var int    $rolAdminId
 * @var array  $acciones
 */
$etiquetas = [
    'ver'      => 'Ver',
    'crear'    => 'Crear',
    'editar'   => 'Editar',
    'eliminar' => 'Eliminar',
];
?>
<section class="page-header">
  <div class="page-title">
    <div class="eyebrow">Sistema</div>
    <h1>Permisos por rol</h1>
  </div>
</section>

<p class="muted">
  Define qué puede hacer cada rol sobre cada módulo. Se suma a las otras dos
  comprobaciones y no las reemplaza: un módulo <strong>desactivado</strong> en
  <a href="/admin/modulos">Módulos</a> queda cerrado para todos los roles, y un
  organizador solo alcanza <strong>sus</strong> torneos aunque tenga el permiso.
  Un módulo sin ninguna casilla marcada queda negado para ese rol.
</p>

<form method="POST" action="/admin/permisos">
  <?= Csrf::field() ?>

  <?php foreach ($roles as $rol): ?>
    <?php $rolId = (int) $rol['id']; ?>
    <article class="card mb-125">
      <h2 class="m-0-0-035"><?= View::e(ucfirst((string) $rol['nombre'])) ?></h2>

      <?php if ($rolId === $rolAdminId): ?>
        <p class="muted m-0">
          Control completo sobre el sistema. No se le asignan permisos por módulo
          y no se puede recortar desde acá: es lo que evita que el administrador
          se deje afuera por accidente.
        </p>
      <?php else: ?>
        <div class="table-scroll">
          <table aria-label="Permisos del rol <?= View::e(ucfirst((string) $rol['nombre'])) ?>">
            <thead>
              <tr>
                <th scope="col">Módulo</th>
                <?php foreach ($acciones as $accion): ?>
                  <th scope="col" class="text-center"><?= View::e($etiquetas[$accion] ?? $accion) ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($modulos as $modulo): ?>
                <?php
                  $slug  = (string) $modulo['slug'];
                  $fila  = $permisos[$rolId][$slug] ?? null;
                  $apagado = $modulo['estado'] !== 'activo';
                ?>
                <tr>
                  <td>
                    <?= View::e((string) $modulo['nombre']) ?>
                    <?php if ($apagado): ?>
                      <span class="chip warning chip-mini">módulo desactivado</span>
                    <?php endif; ?>
                  </td>
                  <?php foreach ($acciones as $accion): ?>
                    <td class="text-center">
                      <input type="checkbox"
                             aria-label="<?= View::e(($etiquetas[$accion] ?? $accion) . ' en ' . (string) $modulo['nombre'] . ' — ' . ucfirst((string) $rol['nombre'])) ?>"
                             name="permisos[<?= $rolId ?>][<?= View::e($slug) ?>][<?= View::e($accion) ?>]"
                             value="1"
                             <?= ($fila && (int) $fila['puede_' . $accion] === 1) ? 'checked' : '' ?>>
                    </td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>

  <div class="actions">
    <button type="submit" class="btn primary">Guardar permisos</button>
    <a class="btn" href="/admin">Cancelar</a>
  </div>
</form>
