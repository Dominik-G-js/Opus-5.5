<?php
/** @var App\Kernel\ViewHelpers $v */
foreach ($v->flashes() as $flash):
    $type = in_array($flash['type'], ['success', 'error', 'info'], true) ? $flash['type'] : 'info';
    ?>
  <div class="flash flash-<?= $v->e($type) ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>"><?= $v->e($flash['message']) ?></div>
<?php endforeach; ?>
