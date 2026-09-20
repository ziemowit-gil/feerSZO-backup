<?php
/**
 * modules/sprawdz_konto/admin_list.php — wysłane linki weryfikacyjne numeru
 * konta (kursanci + kontrahenci): status, statystyki odsłon, szybki podgląd.
 *
 * "Podgląd" pokazuje AKTUALNY numer konta (nie to, co było w chwili wysyłki
 * linku) i NIE liczy się jako odsłona — to osobna, jawna akcja pracownika,
 * inna niż otwarcie właściwego linku przez odbiorcę (sprawdz_konto_resolve_token()).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/logic/sprawdz_konto.php';
sprawdz_konto_migrate();

require_role('admin', 'editor');
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'resend_kursant') {
    csrf_check();
    $account_id = (int)($_POST['account_id'] ?? 0);
    $ok = $account_id ? sprawdz_konto_resend_kursant_link($account_id, (int)$user['id']) : false;
    flash_set($ok ? 'success' : 'danger', $ok
        ? 'Link do sprawdzarki numeru konta wysłany ponownie.'
        : 'Nie udało się wysłać linku — sprawdź, czy kursant ma ustawiony numer konta i poprawny adres e-mail.');
    header('Location: ' . APP_URL . '/modules/sprawdz_konto/admin_list.php');
    exit;
}

$tokens = sprawdz_konto_list_tokens();

// Podgląd aktualnej wartości per token — bez wpływu na statystyki odsłon
// (patrz nagłówek pliku), do wyświetlenia w modalu.
foreach ($tokens as &$t) {
    $t['preview'] = $t['typ'] === 'kursant'
        ? sprawdz_konto_kursant_account_by_id((int)$t['ref_id'])
        : sprawdz_konto_kontrahent_official_account();
}
unset($t);

$status_badges = [
    'aktywny'      => 'success',
    'wygasł'       => 'secondary',
    'unieważniony' => 'danger',
];

$PAGE_TITLE = 'Wysłane linki weryfikacyjne numeru konta';
include dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-link-45deg text-primary"></i> Wysłane linki weryfikacyjne numeru konta</h4>
  <a href="<?= APP_URL ?>/modules/sprawdz_konto/admin_send.php" class="btn btn-sm btn-outline-primary">
    <i class="bi bi-send me-1"></i>Wyślij link kontrahentowi
  </a>
</div>

<?= flash_html() ?>

<?php if (!$tokens): ?>
<div class="card shadow-sm">
  <div class="card-body text-center text-muted py-5">
    <i class="bi bi-link-45deg fs-1 d-block mb-2 opacity-25"></i>
    Nie wysłano jeszcze żadnego linku.
  </div>
</div>
<?php else: ?>

<div class="table-responsive">
  <table class="table table-sm table-hover align-middle bg-white">
    <thead class="table-light">
      <tr>
        <th>Typ</th>
        <th>Odbiorca</th>
        <th>Kontekst</th>
        <th>Status</th>
        <th>Utworzono</th>
        <th>Wygasa</th>
        <th>Otwarcia</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($tokens as $t): ?>
      <tr>
        <td><?= $t['typ'] === 'kursant' ? '<i class="bi bi-mortarboard"></i> Kursant' : '<i class="bi bi-briefcase"></i> Kontrahent' ?></td>
        <td><?= h($t['odbiorca']) ?></td>
        <td class="small text-muted"><?= h($t['kontekst']) ?></td>
        <td><span class="badge bg-<?= $status_badges[$t['status']] ?? 'secondary' ?>"><?= h(ucfirst($t['status'])) ?></span></td>
        <td class="small"><?= date_pl($t['created_at']) ?><?= $t['created_by_name'] ? '<br><span class="text-muted">przez ' . h($t['created_by_name']) . '</span>' : '' ?></td>
        <td class="small"><?= date_pl($t['expires_at']) ?></td>
        <td class="small">
          <?php if ($t['view_count'] > 0): ?>
          <span class="badge bg-info-subtle text-info-emphasis"><?= (int)$t['view_count'] ?>×</span>
          pierwsze: <?= date_pl($t['first_viewed_at']) ?>
          <?php if ($t['last_viewed_at'] !== $t['first_viewed_at']): ?>
          <br>ostatnie: <?= date_pl($t['last_viewed_at']) ?>
          <?php endif; ?>
          <?php else: ?>
          <span class="text-muted">— nie otwarto —</span>
          <?php endif; ?>
        </td>
        <td class="text-nowrap">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#skPreview<?= (int)$t['id'] ?>">
            <i class="bi bi-eye"></i> Podgląd
          </button>
          <?php if ($t['typ'] === 'kursant'): ?>
          <form method="post" class="d-inline" onsubmit="return confirm('Wysłać ponownie link do sprawdzarki numeru konta? Poprzedni link przestanie działać.')">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_action" value="resend_kursant">
            <input type="hidden" name="account_id" value="<?= (int)$t['ref_id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-secondary">
              <i class="bi bi-envelope-arrow-up"></i> Wyślij ponownie
            </button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php foreach ($tokens as $t): ?>
<div class="modal fade" id="skPreview<?= (int)$t['id'] ?>" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-eye me-2"></i>Podgląd — <?= h($t['odbiorca']) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small">To, co odbiorca zobaczy PO OTWARCIU linku — stan aktualny, nie z chwili wysyłki.</p>
        <?php if ($t['preview']): ?>
        <p class="mb-1 small text-muted">Numer konta</p>
        <p class="fs-5 fw-bold font-monospace mb-3"><?= h($t['preview']['numer_konta']) ?></p>
        <p class="mb-1 small text-muted">Rodzaj rachunku</p>
        <p class="mb-0"><?= h($t['preview']['typ_konta']) ?></p>
        <?php else: ?>
        <div class="alert alert-warning mb-0">
          Brak danych do wyświetlenia — konto/umowa mogły zostać usunięte, albo nie ma skonfigurowanego
          żadnego rachunku (ani indywidualnego, ani domyślnego organizacji).
        </div>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zamknij</button>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>

<?php endif; ?>

<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
