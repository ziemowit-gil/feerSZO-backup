<?php
/**
 * admin/szo_settings.php — Ustawienia SZO Planner (globalnie dla całego systemu).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ti_planner.php';

require_role('admin');
$PAGE_TITLE = 'SZO Planner — ustawienia';

$KEYS = [
    'szo_max_daily_minutes' => ['label' => 'Maks. czas pracy dziennie (min)', 'default' => 480, 'type' => 'int', 'min' => 60, 'max' => 720,
                                'help'  => 'Maksymalna łączna liczba minut aktywności (bez przerw) prowadzącego w ciągu jednego dnia szkoleniowego.'],
    'szo_daily_start'       => ['label' => 'Domyślny start dnia (HH:MM)', 'default' => '09:00', 'type' => 'time',
                                'help'  => 'Domyślna godzina rozpoczęcia bloku zajęć — używana przy tworzeniu nowego harmonogramu.'],
    'szo_daily_end'         => ['label' => 'Domyślny koniec dnia (HH:MM)', 'default' => '17:00', 'type' => 'time',
                                'help'  => 'Domyślna godzina zakończenia zajęć.'],
    'szo_lunch_duration'    => ['label' => 'Długość przerwy obiadowej (min)', 'default' => 60, 'type' => 'int', 'min' => 0, 'max' => 120,
                                'help'  => 'Czas trwania przerwy obiadowej wliczany do harmonogramu.'],
    'szo_break_interval'    => ['label' => 'Maks. ciągły blok bez przerwy (min)', 'default' => 90, 'type' => 'int', 'min' => 30, 'max' => 180,
                                'help'  => 'Po ilu minutach ciągłej pracy solver wstawi automatyczną przerwę.'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($KEYS as $key => $meta) {
        $raw = trim($_POST[$key] ?? '');
        if ($meta['type'] === 'int') {
            $val = max($meta['min'], min($meta['max'], (int)$raw));
        } else {
            // 'time' — validate HH:MM
            $val = preg_match('/^\d{2}:\d{2}$/', $raw) ? $raw : $meta['default'];
        }
        $exists = db_one("SELECT 1 FROM settings WHERE key_=?", [$key]);
        if ($exists) db_exec("UPDATE settings SET value=? WHERE key_=?", [(string)$val, $key]);
        else         db_exec("INSERT INTO settings (key_,value) VALUES (?,?)", [$key, (string)$val]);
    }
    flash_set('success', 'Ustawienia SZO Planner zapisane.');
    header('Location: szo_settings.php'); exit;
}

// Odczyt wartości
$vals = [];
foreach ($KEYS as $key => $meta) {
    $row = db_one("SELECT value FROM settings WHERE key_=?", [$key]);
    $vals[$key] = $row ? $row['value'] : $meta['default'];
}

include dirname(__DIR__) . '/includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="/admin/index.php">Admin</a></li>
    <li class="breadcrumb-item active">SZO Planner</li>
  </ol>
</nav>

<h1 class="h4 mb-4"><i class="bi bi-calendar3-week me-2"></i>SZO Planner — ustawienia globalne</h1>

<?php flash_render(); ?>

<form method="post" action="">
  <?= csrf_field() ?>
  <div class="card shadow-sm">
    <div class="card-header fw-semibold">Domyślne parametry harmonogramowania</div>
    <div class="card-body">
      <p class="text-muted small mb-4">
        Wartości te są używane przy tworzeniu nowego harmonogramu oraz jako ograniczenia dla silnika Auto-Plan.
        Prowadzący mogą nadpisać je per-harmonogram.
      </p>

      <?php foreach ($KEYS as $key => $meta): ?>
      <div class="mb-4">
        <label for="<?= h($key) ?>" class="form-label fw-semibold"><?= h($meta['label']) ?></label>
        <?php if ($meta['type'] === 'int'): ?>
        <div class="input-group" style="max-width:220px">
          <input type="number" class="form-control" id="<?= h($key) ?>" name="<?= h($key) ?>"
                 value="<?= h($vals[$key]) ?>"
                 min="<?= (int)$meta['min'] ?>" max="<?= (int)$meta['max'] ?>" required>
          <span class="input-group-text">min</span>
        </div>
        <?php else: ?>
        <input type="time" class="form-control" id="<?= h($key) ?>" name="<?= h($key) ?>"
               value="<?= h($vals[$key]) ?>" required style="max-width:140px">
        <?php endif ?>
        <div class="form-text"><?= h($meta['help']) ?></div>
      </div>
      <?php endforeach ?>
    </div>
    <div class="card-footer text-end">
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-floppy me-1"></i>Zapisz ustawienia
      </button>
    </div>
  </div>
</form>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
