<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/zwroty_kosztow.php';

require_role('admin', 'editor');

$fm  = new FinanceManager();
$id  = (int)($_GET['id'] ?? 0);
$req = $fm->getOrFail($id);
$PAGE_TITLE = 'Wniosek ' . ($req['nr_wniosku'] ?? '#' . $id);

// Pobierz powiązaną umowę i osoby
$umowa = null;
if ($req['umowa_type'] === 'wolontariat') {
    $umowa = db_one("SELECT * FROM umowy_wolontariat WHERE id=?", [$req['umowa_id']]);
}
$wnioskodawca   = db_one("SELECT * FROM users WHERE id=?", [$req['wnioskodawca_id']]);
$zlozone_przez  = !empty($req['zlozone_przez_id']) && $req['zlozone_przez_id'] != $req['wnioskodawca_id']
    ? db_one("SELECT name, email FROM users WHERE id=?", [$req['zlozone_przez_id']])
    : null;

// ── Akcje workflow (POST) ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';
    $user   = current_user();
    $uid    = (int)$user['id'];

    try {
        switch ($action) {
            case 'weryfikacja':
                $fm->sendToVerification($id, $uid);
                flash_set('success', 'Wniosek przekazany do weryfikacji merytorycznej.');
                break;

            case 'zatwierdz':
                $fm->approve($id, $uid, trim($_POST['uwagi'] ?? ''));
                flash_set('success', 'Wniosek zatwierdzony.');
                break;

            case 'do_wyplaty':
                $fm->schedulePayment($id, $uid);
                flash_set('success', 'Wniosek oznaczony jako „Do wypłaty".');
                break;

            case 'wyplacono':
                $fm->markAsPaid($id, $uid, [
                    'data_wyplaty'  => trim($_POST['data_wyplaty']  ?? date('Y-m-d')),
                    'forma_wyplaty' => trim($_POST['forma_wyplaty'] ?? 'przelew'),
                    'nr_przelewu'   => trim($_POST['nr_przelewu']   ?? ''),
                ]);
                flash_set('success', 'Wniosek oznaczony jako wypłacony. Status końcowy.');
                break;

            case 'odrzuc':
                $powod = trim($_POST['odrzucenie_powod'] ?? '');
                if (!$powod) throw new \InvalidArgumentException('Podaj powód odrzucenia.');
                $fm->reject($id, $uid, $powod);
                flash_set('warning', 'Wniosek odrzucony.');
                break;

            case 'save_ksef':
                $fm->saveKsef($id, $uid, [
                    'ksef_numer'           => trim($_POST['ksef_numer']           ?? ''),
                    'ksef_data'            => trim($_POST['ksef_data']             ?? '') ?: null,
                    'ksef_nip_sprzedawcy'  => trim($_POST['ksef_nip_sprzedawcy']  ?? ''),
                ]);
                flash_set('success', 'Dane faktury KSeF zapisane.');
                break;
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }

    header('Location: view.php?id=' . $id); exit;
}

// Log historii
$log = db_all(
    "SELECT l.*, u.name AS user_name FROM zwroty_log l
     LEFT JOIN users u ON u.id = l.user_id
     WHERE l.request_id = ? ORDER BY l.created_at ASC",
    [$id]
);

$zalaczniki = json_decode($req['zalaczniki'] ?? '[]', true) ?: [];

include dirname(dirname(__DIR__)) . '/includes/header.php';

// Mapa ikon dla logu
$log_icons = [
    'created'    => ['bi-plus-circle',          'text-secondary'],
    'weryfikacja'=> ['bi-search',                'text-info'],
    'approved'   => ['bi-check-circle-fill',     'text-success'],
    'do_wyplaty' => ['bi-send-check-fill',       'text-primary'],
    'paid'       => ['bi-cash-coin',             'text-dark'],
    'rejected'   => ['bi-x-circle-fill',         'text-danger'],
];
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="index.php">Zwroty kosztów</a></li>
    <li class="breadcrumb-item active"><?= h($req['nr_wniosku'] ?? '#'.$id) ?></li>
  </ol>
</nav>

<?= flash_html() ?>

<!-- ── Nagłówek ───────────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-4 border-0" style="background:linear-gradient(135deg,#0f2044,#1d4ed8);color:#fff;border-radius:1rem">
<div class="card-body py-3 px-4">
  <div class="row align-items-center g-2">
    <div class="col">
      <div class="d-flex align-items-center gap-3">
        <div class="bg-white bg-opacity-10 rounded-3 p-2">
          <i class="bi bi-receipt-cutoff fs-3"></i>
        </div>
        <div>
          <div style="font-size:.7rem;opacity:.6;font-weight:700;letter-spacing:.1em;text-transform:uppercase">Numer wniosku</div>
          <div class="fw-bold font-monospace" style="font-size:1.4rem;letter-spacing:.04em">
            <?= h($req['nr_wniosku'] ?? '—') ?>
          </div>
          <div style="opacity:.7;font-size:.83rem"><?= h($req['tytul']) ?></div>
        </div>
      </div>
    </div>
    <div class="col-auto text-end">
      <?= zwroty_status_badge($req['status']) ?>
      <div class="mt-2 fw-bold" style="font-size:1.5rem">
        <?= number_format((float)$req['kwota'], 2, ',', ' ') ?> <?= h($req['waluta']) ?>
      </div>
      <div style="opacity:.65;font-size:.78rem">
        Wydatek z <?= $req['data_wydatku'] ? date('d.m.Y', strtotime($req['data_wydatku'])) : '—' ?>
      </div>
    </div>
  </div>
</div>
</div>

<div class="row g-4">
<div class="col-xl-8">

<!-- ── Dane wniosku ───────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-4">
<div class="card-header fw-semibold"><i class="bi bi-info-circle text-primary me-1"></i> Szczegóły wniosku</div>
<div class="card-body">
<div class="row g-3">
  <div class="col-md-6">
    <div class="detail-label">Tytuł wydatku</div>
    <div class="detail-value fw-semibold"><?= h($req['tytul']) ?></div>
  </div>
  <div class="col-md-3">
    <div class="detail-label">Kwota</div>
    <div class="detail-value fw-bold text-success fs-5"><?= number_format((float)$req['kwota'],2,',',' ') ?> <?= h($req['waluta']) ?></div>
  </div>
  <div class="col-md-3">
    <div class="detail-label">Data wydatku</div>
    <div class="detail-value"><?= $req['data_wydatku'] ? date('d.m.Y', strtotime($req['data_wydatku'])) : '—' ?></div>
  </div>
  <?php if ($req['kategoria']): ?>
  <div class="col-md-6">
    <div class="detail-label">Kategoria</div>
    <div class="detail-value"><?= h(zwroty_kategoria_label($req['kategoria'])) ?></div>
  </div>
  <?php endif; ?>
  <?php if ($req['opis']): ?>
  <div class="col-12">
    <div class="detail-label">Opis / uzasadnienie</div>
    <div class="detail-value"><?= nl2br(h($req['opis'])) ?></div>
  </div>
  <?php endif; ?>
</div>
</div>
</div>

<!-- ── Bez umowy ───────────────────────────────────────────────────────── -->
<?php if ($req['bez_umowy']): ?>
<div class="alert alert-warning d-flex gap-2 align-items-center mb-4" style="font-size:.85rem">
  <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0"></i>
  <div><strong>Wniosek złożony bez powiązanej umowy</strong> — tryb administracyjny. Limit budżetowy nie jest sprawdzany.</div>
</div>
<?php endif; ?>

<!-- ── Faktura KSeF ─────────────────────────────────────────────────────── -->
<?php if ($req['faktura_elektroniczna']): ?>
<div class="card shadow-sm mb-4 border-primary border-opacity-25">
<div class="card-header fw-semibold d-flex align-items-center gap-2">
  <i class="bi bi-qr-code text-primary"></i>
  Faktura elektroniczna (KSeF)
  <?php if ($req['ksef_numer']): ?>
  <span class="badge bg-success ms-auto">Zweryfikowana</span>
  <?php else: ?>
  <span class="badge bg-warning text-dark ms-auto">Oczekuje na numer KSeF</span>
  <?php endif; ?>
</div>
<div class="card-body">
  <?php if ($req['ksef_numer']): ?>
  <div class="row g-3 mb-3">
    <div class="col-md-5">
      <div class="detail-label">Numer KSeF</div>
      <div class="detail-value font-monospace fw-bold"><?= h($req['ksef_numer']) ?></div>
    </div>
    <?php if ($req['ksef_data']): ?>
    <div class="col-md-3">
      <div class="detail-label">Data faktury</div>
      <div class="detail-value"><?= date('d.m.Y', strtotime($req['ksef_data'])) ?></div>
    </div>
    <?php endif; ?>
    <?php if ($req['ksef_nip_sprzedawcy']): ?>
    <div class="col-md-4">
      <div class="detail-label">NIP sprzedawcy</div>
      <div class="detail-value font-monospace"><?= h($req['ksef_nip_sprzedawcy']) ?></div>
    </div>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <p class="text-muted small mb-3">Wolontariusz zadeklarował posiadanie faktury elektronicznej KSeF. Administrator uzupełnia numer po weryfikacji.</p>
  <?php endif; ?>

  <!-- Formularz uzupełnienia KSeF (admin) -->
  <?php if (can_edit()): ?>
  <form method="post" class="border-top pt-3 mt-2">
    <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
    <input type="hidden" name="_action" value="save_ksef">
    <div class="row g-2 align-items-end">
      <div class="col-md-4">
        <label class="form-label small fw-semibold">Numer KSeF</label>
        <input type="text" name="ksef_numer" class="form-control form-control-sm font-monospace"
               value="<?= h($req['ksef_numer'] ?? '') ?>"
               placeholder="np. 1234567890-20240529-ABC">
      </div>
      <div class="col-md-3">
        <label class="form-label small fw-semibold">Data faktury</label>
        <input type="date" name="ksef_data" class="form-control form-control-sm"
               value="<?= h($req['ksef_data'] ?? '') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label small fw-semibold">NIP sprzedawcy</label>
        <input type="text" name="ksef_nip_sprzedawcy" class="form-control form-control-sm font-monospace"
               maxlength="10" placeholder="0000000000"
               value="<?= h($req['ksef_nip_sprzedawcy'] ?? '') ?>">
      </div>
      <div class="col-md-2">
        <button class="btn btn-primary btn-sm w-100">
          <i class="bi bi-floppy me-1"></i>Zapisz
        </button>
      </div>
    </div>
  </form>
  <?php endif; ?>
</div>
</div>
<?php endif; ?>

<!-- ── Umowa powiązana ─────────────────────────────────────────────────── -->
<?php if ($umowa): ?>
<div class="card shadow-sm mb-4">
<div class="card-header fw-semibold"><i class="bi bi-file-earmark-person text-success me-1"></i> Powiązana umowa</div>
<div class="card-body">
<div class="row g-3">
  <div class="col-md-4">
    <div class="detail-label">Numer umowy</div>
    <div class="detail-value">
      <a href="<?= APP_URL ?>/contracts/wolontariat/view.php?id=<?= $umowa['id'] ?>" class="fw-semibold text-decoration-none">
        <?= h($umowa['numer_umowy']) ?>
      </a>
    </div>
  </div>
  <div class="col-md-4">
    <div class="detail-label">Wolontariusz</div>
    <div class="detail-value fw-semibold"><?= h($umowa['imie_nazwisko']) ?></div>
  </div>
  <div class="col-md-4">
    <div class="detail-label">Status umowy</div>
    <div class="detail-value"><?= status_badge($umowa['status']) ?></div>
  </div>
  <?php
  $el = (new FinanceManager())->validateEligibility((int)$req['umowa_id']);
  if ($el['limit'] !== null):
  ?>
  <div class="col-12">
    <div class="detail-label mb-1">Wykorzystanie limitu zwrotów</div>
    <?php $pct = $el['limit'] > 0 ? min(100, round($el['zuzyty'] / $el['limit'] * 100)) : 0; ?>
    <div class="d-flex gap-4 small mb-1">
      <span>Limit: <strong><?= number_format($el['limit'],2,',',' ') ?> PLN</strong></span>
      <span>Zatwierdzone: <strong class="text-danger"><?= number_format($el['zuzyty'],2,',',' ') ?> PLN</strong></span>
      <span>Dostępne: <strong class="text-success"><?= number_format($el['dostepny'],2,',',' ') ?> PLN</strong></span>
    </div>
    <div class="progress" style="height:8px">
      <div class="progress-bar bg-<?= $pct>=80?'danger':($pct>=50?'warning':'success') ?>" style="width:<?= $pct ?>%"></div>
    </div>
  </div>
  <?php endif; ?>
</div>
</div>
</div>
<?php endif; ?>

<!-- ── Załączniki ─────────────────────────────────────────────────────── -->
<?php if ($zalaczniki): ?>
<div class="card shadow-sm mb-4">
<div class="card-header fw-semibold"><i class="bi bi-paperclip text-muted me-1"></i> Załączniki (<?= count($zalaczniki) ?>)</div>
<div class="card-body py-2">
  <div class="d-flex flex-wrap gap-2">
  <?php foreach ($zalaczniki as $zal): ?>
  <?php
  $ext = strtolower(pathinfo($zal['file'] ?? '', PATHINFO_EXTENSION));
  $icon = match($ext) {
      'pdf'  => 'bi-file-earmark-pdf text-danger',
      'jpg','jpeg','png','webp','gif' => 'bi-file-earmark-image text-primary',
      'xlsx','xls','csv' => 'bi-file-earmark-excel text-success',
      'doc','docx' => 'bi-file-earmark-word text-info',
      default => 'bi-file-earmark text-secondary',
  };
  ?>
  <a href="<?= APP_URL ?>/uploads/zwroty/<?= urlencode($zal['file']) ?>"
     target="_blank"
     class="d-flex align-items-center gap-2 border rounded px-3 py-2 text-decoration-none text-dark bg-light"
     style="font-size:.83rem">
    <i class="bi <?= $icon ?> fs-5"></i>
    <span><?= h($zal['name']) ?></span>
  </a>
  <?php endforeach; ?>
  </div>
</div>
</div>
<?php endif; ?>

<!-- ── Historia ───────────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-4">
<div class="card-header fw-semibold"><i class="bi bi-clock-history text-muted me-1"></i> Historia</div>
<div class="card-body py-2">
<?php if ($log): ?>
  <div class="d-flex flex-column gap-2">
  <?php foreach ($log as $entry):
    [$icon_cls, $text_cls] = $log_icons[$entry['action']] ?? ['bi-dot', 'text-muted'];
  ?>
  <div class="d-flex gap-3 align-items-start py-1 border-bottom" style="font-size:.85rem">
    <div class="mt-1"><i class="bi <?= $icon_cls ?> <?= $text_cls ?> fs-5"></i></div>
    <div class="flex-grow-1">
      <div><?= nl2br(h($entry['note'])) ?></div>
      <div class="text-muted" style="font-size:.75rem">
        <?= h($entry['user_name'] ?? 'System') ?> ·
        <?= $entry['created_at'] ? date('d.m.Y H:i', strtotime($entry['created_at'])) : '' ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
<?php else: ?>
  <div class="text-muted small py-2">Brak wpisów historii.</div>
<?php endif; ?>
</div>
</div>

</div><!-- /col-xl-8 -->

<!-- ══ Sidebar: akcje ══════════════════════════════════════════════════════ -->
<div class="col-xl-4">

<?php if ($req['status'] !== 'wyplacono' && $req['status'] !== 'odrzucony'): ?>
<div class="card shadow-sm mb-4 border-primary border-opacity-25">
<div class="card-header fw-semibold"><i class="bi bi-lightning text-primary me-1"></i> Akcje</div>
<div class="card-body">

  <?php if ($req['status'] === 'oczekuje'): ?>
  <!-- Przekaż do weryfikacji -->
  <form method="post" class="mb-2">
    <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
    <input type="hidden" name="_action" value="weryfikacja">
    <button class="btn btn-outline-info w-100">
      <i class="bi bi-search me-1"></i>Przekaż do weryfikacji merytorycznej
    </button>
  </form>
  <?php endif; ?>

  <?php if ($req['status'] === 'weryfikacja'): ?>
  <!-- Zatwierdź -->
  <form method="post" class="mb-2">
    <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
    <input type="hidden" name="_action" value="zatwierdz">
    <div class="mb-2">
      <textarea name="uwagi" class="form-control form-control-sm" rows="2"
                placeholder="Uwagi do zatwierdzenia (opcjonalnie)"></textarea>
    </div>
    <button class="btn btn-success w-100">
      <i class="bi bi-check-lg me-1"></i>Zatwierdź wniosek
    </button>
  </form>
  <?php endif; ?>

  <?php if ($req['status'] === 'zatwierdzony'): ?>
  <!-- Do wypłaty -->
  <form method="post" class="mb-2">
    <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
    <input type="hidden" name="_action" value="do_wyplaty">
    <button class="btn btn-primary w-100">
      <i class="bi bi-send me-1"></i>Przekaż do wypłaty
    </button>
  </form>
  <?php endif; ?>

  <?php if ($req['status'] === 'do_wyplaty'): ?>
  <!-- Wypłacono -->
  <form method="post" class="mb-2">
    <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
    <input type="hidden" name="_action" value="wyplacono">
    <div class="mb-2">
      <label class="form-label small fw-semibold">Data wypłaty <span class="text-danger">*</span></label>
      <input type="date" name="data_wyplaty" class="form-control form-control-sm"
             value="<?= date('Y-m-d') ?>" required>
    </div>
    <div class="mb-2">
      <label class="form-label small fw-semibold">Forma wypłaty</label>
      <select name="forma_wyplaty" class="form-select form-select-sm">
        <option value="przelew">Przelew bankowy</option>
        <option value="gotowka">Gotówka</option>
        <option value="karta">Karta płatnicza</option>
      </select>
    </div>
    <div class="mb-3">
      <label class="form-label small fw-semibold">Nr przelewu / potwierdzenie</label>
      <input type="text" name="nr_przelewu" class="form-control form-control-sm font-monospace"
             placeholder="opcjonalnie">
    </div>
    <button class="btn btn-dark w-100">
      <i class="bi bi-cash-coin me-1"></i>Oznacz jako wypłacony
    </button>
  </form>
  <?php endif; ?>

  <!-- Odrzuć (zawsze dostępne w trakcie workflow) -->
  <hr class="my-3">
  <button class="btn btn-outline-danger w-100 btn-sm" type="button"
          data-bs-toggle="collapse" data-bs-target="#reject-form">
    <i class="bi bi-x-circle me-1"></i>Odrzuć wniosek
  </button>
  <div class="collapse mt-2" id="reject-form">
    <form method="post">
      <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="odrzuc">
      <div class="mb-2">
        <textarea name="odrzucenie_powod" class="form-control form-control-sm" rows="3"
                  placeholder="Powód odrzucenia (wymagane)" required></textarea>
      </div>
      <button class="btn btn-danger w-100 btn-sm">
        <i class="bi bi-x-lg me-1"></i>Potwierdź odrzucenie
      </button>
    </form>
  </div>

</div>
</div>
<?php endif; ?>

<!-- Decyzja finalna — info -->
<?php if ($req['status'] === 'wyplacono'): ?>
<div class="card shadow-sm mb-4 border-success">
<div class="card-body">
  <div class="d-flex gap-2 mb-2">
    <i class="bi bi-cash-coin text-success fs-4"></i>
    <div>
      <div class="fw-bold text-success">Wypłacono</div>
      <div class="small text-muted">Status końcowy — wniosek zarchiwizowany</div>
    </div>
  </div>
  <?php if ($req['data_wyplaty']): ?>
  <div class="small"><strong>Data:</strong> <?= date('d.m.Y', strtotime($req['data_wyplaty'])) ?></div>
  <?php endif; ?>
  <?php if ($req['forma_wyplaty']): ?>
  <div class="small"><strong>Forma:</strong> <?= h($req['forma_wyplaty']) ?></div>
  <?php endif; ?>
  <?php if ($req['nr_przelewu']): ?>
  <div class="small font-monospace"><strong>Nr przelewu:</strong> <?= h($req['nr_przelewu']) ?></div>
  <?php endif; ?>
</div>
</div>
<?php endif; ?>

<?php if ($req['status'] === 'odrzucony'): ?>
<div class="card shadow-sm mb-4 border-danger">
<div class="card-body">
  <div class="d-flex gap-2 mb-2">
    <i class="bi bi-x-circle-fill text-danger fs-4"></i>
    <div class="fw-bold text-danger">Odrzucono</div>
  </div>
  <?php if ($req['odrzucenie_powod']): ?>
  <div class="small"><strong>Powód:</strong> <?= nl2br(h($req['odrzucenie_powod'])) ?></div>
  <?php endif; ?>
</div>
</div>
<?php endif; ?>

<!-- Meta -->
<div class="card shadow-sm mb-4">
<div class="card-header fw-semibold">Informacje</div>
<div class="card-body small text-muted">
  <div class="mb-1">
    <i class="bi bi-person me-1"></i>Wolontariusz: <strong><?= h($wnioskodawca['name'] ?? '—') ?></strong>
  </div>
  <?php if ($zlozone_przez): ?>
  <div class="mb-1 text-primary" style="font-size:.8rem">
    <i class="bi bi-person-check me-1"></i>Złożono przez admina:
    <strong><?= h($zlozone_przez['name'] ?? $zlozone_przez['email']) ?></strong>
  </div>
  <?php endif; ?>
  <div class="mb-1"><i class="bi bi-calendar-plus me-1"></i>Data złożenia: <?= $req['created_at'] ? date('d.m.Y H:i', strtotime($req['created_at'])) : '—' ?></div>
  <?php if ($req['archived_at']): ?>
  <div><i class="bi bi-archive me-1"></i>Zarchiwizowano: <?= date('d.m.Y H:i', strtotime($req['archived_at'])) ?></div>
  <?php endif; ?>
</div>
</div>

</div><!-- /col-xl-4 -->
</div><!-- /row -->

<style>
.detail-label { font-size: .74rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .05em; margin-bottom: .2rem; }
.detail-value { color: #1e293b; }
</style>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
