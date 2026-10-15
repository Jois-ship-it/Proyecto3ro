<?php
// $_flash es inyectado por BaseController::render()
$_tipos = ['success' => 'success', 'error' => 'danger', 'warning' => 'warning', 'info' => ''];
foreach ($_tipos as $key => $clase):
    $msg = $_flash[$key] ?? null;
    if (!$msg) continue;
?>
<div class="flash-msg chip <?= $clase ?> flash-chip" role="<?= $key === 'error' ? 'alert' : 'status' ?>">
  <?= View::e($msg) ?>
</div>
<?php endforeach; ?>
