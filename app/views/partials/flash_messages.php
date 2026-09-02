<?php
// $_flash es inyectado por BaseController::render()
$_tipos = ['success' => 'success', 'error' => 'danger', 'warning' => 'warning', 'info' => ''];
foreach ($_tipos as $key => $clase):
    $msg = $_flash[$key] ?? null;
    if (!$msg) continue;
?>
<div class="flash-msg chip <?= $clase ?>" style="margin-bottom:.75rem;padding:.7rem 1rem;border-radius:14px;display:block;max-width:100%;white-space:normal;">
  <?= View::e($msg) ?>
</div>
<?php endforeach; ?>
