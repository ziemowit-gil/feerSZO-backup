<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('admin', 'editor');
require_module_enabled('onboarding_enabled', 'Moduł zgłoszeń wolontariuszy');
require_once __DIR__ . '/../includes/onboarding_schema.php';

$PAGE_TITLE = 'Zgłoszenia wolontariuszy';

$f_status = $_GET['status'] ?? '';

// Count per status
$counts = [];
foreach (['new', 'pending', 'verified', 'converted', 'rejected'] as $s) {
    $r = db_one("SELECT COUNT(*) AS c FROM onboarding_volunteers WHERE status=?", [$s]);
    $counts[$s] = (int)($r['c'] ?? 0);
}
$counts['all'] = array_sum($counts);

// Build query
if ($f_status !== '') {
    $rows = db_all("SELECT * FROM onboarding_volunteers WHERE status=? ORDER BY created_at DESC", [$f_status]);
} else {
    $rows = db_all("SELECT * FROM onboarding_volunteers ORDER BY created_at DESC");
}

$total_shown = count($rows);

function ob_status_badge(string $status): string {
    $map = [
        'new'       => ['label' => 'Nowy',                    'class' => 'bg-secondary'],
        'pending'   => ['label' => 'Nowy — niezweryfikowany',  'class' => 'bg-warning text-dark'],
        'verified'  => ['label' => 'Zweryfikowany',            'class' => 'bg-success'],
        'converted' => ['label' => 'Umowa utworzona',          'class' => 'bg-primary'],
        'rejected'  => ['label' => 'Odrzucony',                'class' => 'bg-danger'],
    ];
    $s = $map[$status] ?? ['label' => $status, 'class' => 'bg-secondary'];
    return '<span class="badge ' . $s['class'] . '">' . htmlspecialchars($s['label']) . '</span>';
}

function mask_pesel(?string $pesel): string {
    if (!$pesel || strlen($pesel) < 11) return '—';
    return substr($pesel, 0, 2) . '***' . substr($pesel, 5, 1) . '***' . substr($pesel, 9, 2);
}

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h4 class="mb-0">
    <i class="bi bi-person-lines-fill text-primary"></i>
    Zgłoszenia wolontariuszy
    <span class="badge bg-secondary ms-1"><?= $counts['all'] ?></span>
  </h4>
  <div class="d-flex gap-2">
    <a href="<?= APP_URL ?>/onboarding/settings.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-sliders"></i> Ustawienia
    </a>
    <a href="<?= APP_URL ?>/contracts/wolontariat/add.php" class="btn btn-sm btn-success">
      <i class="bi bi-plus-circle"></i> Dodaj ręcznie
    </a>
  </div>
</div>

<?= flash_html() ?>

<!-- Filter bar -->
<div class="mb-3">
  <?php
  $filters = [
    ''          => ['label' => 'Wszystkie',               'count' => $counts['all']],
    'pending'   => ['label' => 'Nowe',                    'count' => $counts['pending'] + $counts['new']],
    'verified'  => ['label' => 'Zweryfikowane',            'count' => $counts['verified']],
    'rejected'  => ['label' => 'Odrzucone',                'count' => $counts['rejected']],
    'converted' => ['label' => 'Umowa',                    'count' => $counts['converted']],
  ];
  foreach ($filters as $val => $info):
    $active = ($f_status === $val) ? ' active' : '';
    $cnt_display = $info['count'];
  ?>
  <a href="?status=<?= urlencode($val) ?>"
     class="btn btn-sm btn-outline-secondary<?= $active ?> me-1 mb-1">
    <?= h($info['label']) ?>
    <span class="badge bg-light text-dark ms-1"><?= $cnt_display ?></span>
  </a>
  <?php endforeach; ?>
</div>

<?php if (empty($rows)): ?>
<div class="card shadow-sm">
  <div class="card-body text-center text-muted py-5">
    <i class="bi bi-inbox fs-2 d-block mb-2"></i>
    Brak zgłoszeń<?= $f_status ? ' dla wybranego statusu' : '' ?>.
  </div>
</div>
<?php else: ?>
<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-hover table-sm align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Data zgłoszenia</th>
          <th>Osoba</th>
          <th>PESEL</th>
          <th>Weryfikacja</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
        <tr>
          <td>
            <small class="text-muted"><?= h(date_pl($row['created_at'])) ?></small>
          </td>
          <td>
            <strong><?= h($row['imie_nazwisko'] ?: '—') ?></strong><br>
            <small class="text-muted"><?= h($row['email'] ?: '') ?></small>
          </td>
          <td><?= h(mask_pesel($row['pesel'])) ?></td>
          <td>
            <i class="bi bi-telephone-fill <?= $row['phone_verified'] ? 'text-success' : 'text-muted' ?>" title="Telefon"></i>
            <i class="bi bi-envelope-fill ms-1 <?= $row['email_verified'] ? 'text-success' : 'text-muted' ?>" title="E-mail"></i>
            <?php if ($row['klauzula_accepted']): ?>
              <span class="badge bg-light text-dark border ms-1" style="font-size:.7em">Klauzula</span>
            <?php endif; ?>
          </td>
          <td><?= ob_status_badge($row['status']) ?></td>
          <td class="text-end">
            <a href="<?= APP_URL ?>/onboarding/view.php?id=<?= (int)$row['id'] ?>"
               class="btn btn-sm btn-outline-primary">→</a>
            <?php if (is_admin()): ?>
            <?= delete_btn('onboarding_volunteers', (int)$row['id'], $row['imie_nazwisko'] ?? '#'.$row['id']) ?>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
