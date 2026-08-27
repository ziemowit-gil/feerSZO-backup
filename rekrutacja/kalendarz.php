<?php
/**
 * rekrutacja/kalendarz.php — Harmonogram rozmów kwalifikacyjnych
 * Prosty widok tygodniowy (lista dzienna) na najbliższe 14 dni + archiwum.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/rekrutacja.php';

rekr_migrate();
require_module_enabled('rekrutacja_enabled', 'Moduł rekrutacji');
rekr_require_operator();

$days_ahead = 14;
$upcoming = rekr_upcoming_interviews($days_ahead);
$by_day = [];
foreach ($upcoming as $iv) {
    $by_day[substr($iv['starts_at'], 0, 10)][] = $iv;
}

$past = db_all(
    "SELECT i.*, a.imie, a.nazwisko, a.id AS app_id, u.name AS interviewer_name
     FROM rekr_interviews i
     JOIN rekr_applications a ON a.id = i.application_id
     LEFT JOIN users u ON u.id = i.interviewer_id
     WHERE i.starts_at < ? ORDER BY i.starts_at DESC LIMIT 30", [date('Y-m-d 00:00:00')]);

$dni = ['Mon'=>'poniedziałek','Tue'=>'wtorek','Wed'=>'środa','Thu'=>'czwartek','Fri'=>'piątek','Sat'=>'sobota','Sun'=>'niedziela'];

$PAGE_TITLE = 'Rekrutacja — rozmowy';
include dirname(__DIR__) . '/includes/header.php';
?>
<div class="container py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-calendar-week me-2" aria-hidden="true"></i>Rozmowy kwalifikacyjne — najbliższe <?= $days_ahead ?> dni</h1>
    <a class="btn btn-sm btn-outline-secondary" href="index.php"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Lista zgłoszeń</a>
  </div>

  <?php if (!$by_day): ?>
    <div class="alert alert-light border">Brak zaplanowanych rozmów. Rozmowę planuje się z karty kandydata.</div>
  <?php endif; ?>

  <?php for ($d = 0; $d < $days_ahead; $d++):
      $date = date('Y-m-d', strtotime("+{$d} days"));
      if (empty($by_day[$date])) continue; ?>
  <div class="card mb-3">
    <div class="card-header fw-semibold">
      <?= h($dni[date('D', strtotime($date))] ?? '') ?>, <?= h(date('d.m.Y', strtotime($date))) ?>
      <?= $d === 0 ? '<span class="badge text-bg-primary ms-1">dziś</span>' : '' ?>
    </div>
    <ul class="list-group list-group-flush">
      <?php foreach ($by_day[$date] as $iv): ?>
      <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
          <strong><?= h(substr($iv['starts_at'], 11, 5)) ?></strong>
          <span class="text-muted">(<?= (int)$iv['duration_min'] ?> min)</span>
          — <a href="view.php?id=<?= (int)$iv['app_id'] ?>"><?= h(trim($iv['imie'] . ' ' . $iv['nazwisko'])) ?></a>
          <span class="badge text-bg-<?= h(REKR_TYPES[$iv['type']]['class'] ?? 'secondary') ?>"><?= h(REKR_TYPES[$iv['type']]['label'] ?? $iv['type']) ?></span>
        </div>
        <div class="small text-muted">
          <?= $iv['interviewer_name'] ? '<i class="bi bi-person me-1" aria-hidden="true"></i>' . h($iv['interviewer_name']) : '' ?>
          <?php if ($iv['location']): ?>
            · <?php if (preg_match('#^https?://#i', $iv['location'])): ?>
                <a href="<?= h($iv['location']) ?>" target="_blank" rel="noopener">spotkanie online</a>
              <?php else: ?><?= h($iv['location']) ?><?php endif; ?>
          <?php endif; ?>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endfor; ?>

  <h2 class="h5 mt-4">Wcześniejsze rozmowy</h2>
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead><tr><th scope="col">Termin</th><th scope="col">Kandydat</th><th scope="col">Status</th><th scope="col">Prowadzący</th></tr></thead>
      <tbody>
      <?php if (!$past): ?><tr><td colspan="4" class="text-muted">Brak.</td></tr><?php endif; ?>
      <?php foreach ($past as $iv): ?>
        <tr>
          <td class="text-nowrap"><?= h(date('d.m.Y H:i', strtotime($iv['starts_at']))) ?></td>
          <td><a href="view.php?id=<?= (int)$iv['app_id'] ?>"><?= h(trim($iv['imie'] . ' ' . $iv['nazwisko'])) ?></a></td>
          <td><span class="badge text-bg-<?= ['zaplanowana'=>'warning','odbyta'=>'success','odwolana'=>'secondary'][$iv['status']] ?? 'light' ?>"><?= h($iv['status']) ?></span></td>
          <td><?= h($iv['interviewer_name'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
