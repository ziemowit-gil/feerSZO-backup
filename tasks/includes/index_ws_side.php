<?php
/**
 * tasks/includes/index_ws_side.php — kolumna obszarów roboczych po lewej (lista zadań).
 * Wymaga z index.php: $workspaces (task_user_workspaces), $ws_id, $view_mode.
 * Na wąskim ekranie kolumna zamienia się w poziomy, przewijany pasek nad listą.
 */
$_wss_view = $view_mode === 'kanban' ? '&view=kanban' : '';
// Kolor ikony na kafelku obszaru: czarny na jasnym tle (np. żółtym), biały na ciemnym — który da większy kontrast
$_wss_ink = static function (string $hex): string {
    $h = ltrim(trim($hex), '#');
    if (strlen($h) === 3) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
    if (!preg_match('/^[0-9a-f]{6}$/i', $h)) return '#ffffff';
    $lin = static function (string $x): float { $v = hexdec($x) / 255; return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4; };
    $lum = 0.2126 * $lin(substr($h, 0, 2)) + 0.7152 * $lin(substr($h, 2, 2)) + 0.0722 * $lin(substr($h, 4, 2));
    return $lum > 0.179 ? '#1d1d1d' : '#ffffff';
};
?>
<nav class="tk-ws-side" aria-label="Obszary robocze">
  <div class="tk-ws-side-h">Obszary</div>
  <ul class="tk-ws-side-list">
    <?php foreach ($workspaces as $_wss): $_wss_on = (int)$_wss['id'] === (int)$ws_id; ?>
    <li>
      <a href="<?= APP_URL ?>/tasks/index.php?ws=<?= (int)$_wss['id'] ?><?= $_wss_view ?>"
         class="tk-ws-side-link<?= $_wss_on ? ' active' : '' ?>"<?= $_wss_on ? ' aria-current="page"' : '' ?>
         style="--ws:<?= h($_wss['color'] ?: '#5f5f5f') ?>;--ws-ink:<?= $_wss_ink((string)($_wss['color'] ?: '#5f5f5f')) ?>"
         title="<?= h($_wss['name']) ?>">
        <span class="tk-ws-tile" aria-hidden="true"><i class="bi <?= h($_wss['icon'] ?: 'bi-folder') ?>"></i></span>
        <span class="tk-ws-side-name"><?= h($_wss['name']) ?></span>
        <span class="tk-ws-side-cnt<?= (int)$_wss['task_count'] ? '' : ' is-zero' ?>"><span class="visually-hidden">, zadań: </span><?= (int)$_wss['task_count'] ?></span>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php if (!empty($_tsk_is_any_leader)): ?>
  <a href="<?= APP_URL ?>/tasks/settings/workspaces.php" class="tk-ws-side-manage">
    <i class="bi bi-sliders" aria-hidden="true"></i>Zarządzaj obszarami
  </a>
  <?php endif; ?>
</nav>
