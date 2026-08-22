<?php
/**
 * crm/invoices/view.php — karta faktury: pozycje, powiązania i akcje.
 *
 * Wystawienie jest nieodwracalne — od tego momentu dokument jest w SZO tylko
 * do odczytu, a jego stan pochodzi z Fakturowni („Odśwież status").
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/invoices.php';
require_once dirname(dirname(__DIR__)) . '/includes/ksef.php';

require_login();
require_module_enabled('invoices_enabled', 'Moduł Faktury');
invoices_migrate();

$id  = (int)($_GET['id'] ?? 0);
$inv = invoice_get($id);
if (!$inv) { flash_set('error', 'Nie znaleziono faktury.'); header('Location: index.php'); exit; }

$can_write = is_admin() || can_write('crm');
$SELF      = APP_URL . '/crm/invoices/view.php?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'issue_local') {
        // Wybór wystawiającego: tylko pobranie PDF albo pobranie razem z wysyłką
        // do nabywcy. Wystawienie samo w sobie jest identyczne — różni je to,
        // co dzieje się z gotowym dokumentem.
        $mode = ($_POST['mode'] ?? 'download') === 'send' ? 'send' : 'download';

        $r = invoice_issue_local($id);
        if (!$r['ok']) {
            flash_set('error', 'Nie udało się wystawić: ' . $r['error']);
            header('Location: ' . $SELF); exit;
        }

        // PDF zapisujemy od razu — ten sam plik idzie do pobrania i w załączniku.
        $stored = invoice_pdf_store($id);
        $msg    = 'Faktura wystawiona — numer ' . $r['number'] . '.';

        if ($mode === 'send') {
            $snd = invoice_send_to_buyer($id);
            $msg .= $snd['ok']
                ? ' Wysłano na ' . $snd['to'] . '.'
                : ' UWAGA: nie wysłano — ' . $snd['error'];
            flash_set($snd['ok'] ? 'success' : 'warning', $msg);
        } else {
            flash_set('success', $msg);
        }

        // Prowadzimy prosto do PDF-a — po wystawieniu operator chce mieć dokument.
        if ($stored) { header('Location: ' . APP_URL . '/crm/invoices/pdf.php?id=' . $id); exit; }
        header('Location: ' . $SELF); exit;
    }

    if ($action === 'send_buyer') {
        $snd = invoice_send_to_buyer($id);
        flash_set($snd['ok'] ? 'success' : 'error',
            $snd['ok'] ? 'Faktura wysłana na ' . $snd['to'] . '.' : ('Nie wysłano: ' . $snd['error']));
        header('Location: ' . $SELF); exit;
    }
    if ($action === 'ksef_send') {
        $r = ksef_send_invoice($id);
        flash_set($r['ok'] ? 'success' : 'error',
            $r['ok'] ? 'Faktura przyjęta przez KSeF — numer ' . $r['ksef_number'] . '.'
                     : ('KSeF odrzucił wysyłkę: ' . $r['error']));
        header('Location: ' . $SELF); exit;
    }
    if ($action === 'push') {
        $r = invoice_push($id);
        flash_set($r['ok'] ? 'success' : 'error',
            $r['ok'] ? 'Faktura wystawiona w Fakturowni.' : ('Nie udało się wystawić: ' . $r['error']));
        header('Location: ' . $SELF); exit;
    }
    if ($action === 'sync') {
        $r = invoice_sync($id);
        flash_set($r['ok'] ? 'success' : 'error',
            $r['ok'] ? 'Status odświeżony: ' . (INVOICE_STATUSES[$r['status']]['label'] ?? $r['status']) . '.'
                     : ('Nie udało się odświeżyć: ' . $r['error']));
        header('Location: ' . $SELF); exit;
    }
    if ($action === 'pdf_refresh') {
        $ok = invoice_pdf_fetch($id) !== null;
        flash_set($ok ? 'success' : 'error', $ok ? 'PDF pobrany z Fakturowni.' : 'Nie udało się pobrać PDF.');
        header('Location: ' . $SELF); exit;
    }
    if ($action === 'delete') {
        $err = invoice_delete($id);
        if ($err) { flash_set('error', $err); header('Location: ' . $SELF); exit; }
        flash_set('success', 'Szkic faktury usunięty.');
        header('Location: ' . APP_URL . '/crm/invoices/index.php'); exit;
    }
}

$PAGE_TITLE = 'Faktura ' . ($inv['number'] ?: '#' . $id);
$st         = INVOICE_STATUSES[$inv['status']] ?? ['label' => $inv['status'], 'color' => '#6B7280'];
$is_draft   = $inv['status'] === 'szkic' || $inv['status'] === 'blad';

// Odnośnik do źródła — żeby z faktury dało się wrócić do oferty / rozliczenia.
$src_link = null;
if ($inv['source'] === 'offer' && $inv['source_id']) {
    $src_link = [APP_URL . '/crm/offers/view.php?id=' . (int)$inv['source_id'], 'Oferta CRM'];
} elseif ($inv['source'] === 'ti_billing' && $inv['source_id']) {
    $src_link = [APP_URL . '/karty30/ti/billing.php', 'Rozliczenia TI'];
}

include dirname(__DIR__) . '/includes/header_crm.php';
?>
<div class="container-fluid px-0" style="max-width:1000px">

  <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <a href="<?= APP_URL ?>/crm/invoices/index.php" class="btn btn-sm btn-crm-ghost">
      <i class="bi bi-arrow-left"></i>
    </a>
    <h1 class="h5 mb-0 me-auto">
      <?= h($inv['number'] ?: 'Szkic faktury #' . $id) ?>
      <span class="badge ms-1" style="background:<?= h($st['color']) ?>"><?= h($st['label']) ?></span>
    </h1>
    <?php if (invoice_is_test($inv)): ?>
    <span class="badge bg-danger" title="Faktura testowa — nie idzie do żadnego systemu zewnętrznego">TEST</span>
    <?php endif; ?>
    <span class="badge bg-light text-dark border"><?= h(INVOICE_SOURCES[$inv['source']] ?? $inv['source']) ?></span>
  </div>

  <?php if (!empty($inv['last_error'])): ?>
  <div class="alert alert-danger py-2" role="alert">
    <i class="bi bi-exclamation-octagon-fill me-1"></i>
    <strong>Ostatni błąd:</strong> <?= h($inv['last_error']) ?>
  </div>
  <?php endif; ?>

  <?php if ($is_draft && !invoices_api_ready()): ?>
  <div class="alert alert-warning py-2" role="note">
    <i class="bi bi-exclamation-triangle-fill me-1"></i>
    Integracja z Fakturownią nie jest skonfigurowana — szkic można edytować, ale nie wystawić.
    <?php if (is_admin()): ?>
    <a href="<?= APP_URL ?>/admin/invoices_settings.php">Skonfiguruj</a>.
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="row g-3">
    <!-- Nabywca + daty -->
    <div class="col-lg-7">
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-header fw-semibold py-2">Nabywca</div>
        <div class="card-body py-2">
          <div class="fw-semibold">
            <?= h($inv['buyer_name'] ?: '—') ?>
            <?php if (invoice_buyer_kind($inv['buyer_tax_no'] ?? '') === 'OF'): ?>
            <span class="badge bg-light text-dark border" style="font-size:.62rem"
                  title="Osoba fizyczna (brak NIP) — faktura poza KSeF">osoba fizyczna</span>
            <?php endif; ?>
          </div>
          <?php if ($inv['buyer_tax_no']): ?><div class="small text-muted">NIP <?= h($inv['buyer_tax_no']) ?></div><?php endif; ?>
          <?php if ($inv['buyer_street'] || $inv['buyer_city']): ?>
          <div class="small"><?= h(trim($inv['buyer_street'] . ', ' . trim($inv['buyer_post_code'] . ' ' . $inv['buyer_city']), ' ,')) ?></div>
          <?php endif; ?>
          <?php if ($inv['buyer_email']): ?>
          <div class="small"><a href="mailto:<?= h($inv['buyer_email']) ?>"><?= h($inv['buyer_email']) ?></a></div>
          <?php endif; ?>
          <?php if (!empty($inv['contact_id'])): ?>
          <a class="btn btn-sm btn-crm-ghost mt-2" href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$inv['contact_id'] ?>">
            <i class="bi bi-person-lines-fill me-1"></i>Kartoteka kontaktu
          </a>
          <?php endif; ?>
        </div>
      </div>

      <!-- Pozycje -->
      <div class="card border-0 shadow-sm">
        <div class="card-header fw-semibold py-2">Pozycje</div>
        <div class="table-responsive">
          <table class="table table-sm mb-0 align-middle">
            <caption class="visually-hidden">Pozycje faktury</caption>
            <thead class="table-light">
              <tr>
                <th scope="col">Nazwa</th>
                <th scope="col" class="text-end">Ilość</th>
                <th scope="col" class="text-end">Cena netto</th>
                <th scope="col" class="text-end">VAT</th>
                <th scope="col" class="text-end">Brutto</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($inv['items'] as $it): ?>
              <tr>
                <td><?= h($it['name']) ?></td>
                <td class="text-end text-nowrap">
                  <?= h(rtrim(rtrim(number_format((float)$it['qty'], 3, ',', ' '), '0'), ',')) ?> <?= h($it['unit']) ?>
                </td>
                <td class="text-end text-nowrap"><?= number_format((float)$it['unit_net'], 2, ',', ' ') ?></td>
                <td class="text-end text-nowrap"><?= h($it['vat_rate']) ?><?= is_numeric($it['vat_rate']) ? '%' : '' ?></td>
                <td class="text-end text-nowrap"><?= number_format((float)$it['line_gross'], 2, ',', ' ') ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$inv['items']): ?>
              <tr><td colspan="5" class="text-muted text-center py-3">Brak pozycji.</td></tr>
            <?php endif; ?>
            </tbody>
            <tfoot class="table-light">
              <tr>
                <th colspan="4" class="text-end">Razem netto</th>
                <td class="text-end"><?= number_format((float)$inv['total_net'], 2, ',', ' ') ?></td>
              </tr>
              <tr>
                <th colspan="4" class="text-end">VAT</th>
                <td class="text-end"><?= number_format((float)$inv['total_vat'], 2, ',', ' ') ?></td>
              </tr>
              <tr>
                <th colspan="4" class="text-end">Do zapłaty</th>
                <td class="text-end fw-bold">
                  <?= number_format((float)$inv['total_gross'], 2, ',', ' ') ?> <?= h($inv['currency']) ?>
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      <!-- Podgląd dokumentu w module — bez otwierania nowej karty.
           Ramka doładowuje się DOPIERO po rozwinięciu: render PDF jest kosztowny,
           a większość wejść na kartę faktury go nie potrzebuje. -->
      <div class="card border-0 shadow-sm mt-3">
        <details id="invPreview">
          <summary class="card-header fw-semibold py-2" style="cursor:pointer;list-style:none">
            <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Podgląd dokumentu
            <span class="text-muted fw-normal" style="font-size:.8rem">
              — <?= !empty($inv['pdf_path']) ? 'zapisana kopia PDF' : 'z szablonu SZO' ?>
            </span>
          </summary>
          <div class="card-body p-0">
            <iframe id="invPreviewFrame" title="Podgląd faktury <?= h((string)($inv['number'] ?: $inv['id'])) ?>"
                    data-src="<?= APP_URL ?>/crm/invoices/pdf.php?id=<?= $id ?><?= empty($inv['pdf_path']) ? '&gen=1' : '' ?>"
                    style="width:100%;height:70vh;border:0;display:block"></iframe>
            <div class="px-3 py-2 d-flex gap-3 align-items-center" style="font-size:.8rem">
              <a target="_blank" rel="noopener"
                 href="<?= APP_URL ?>/crm/invoices/pdf.php?id=<?= $id ?><?= empty($inv['pdf_path']) ? '&gen=1' : '' ?>">
                Otwórz w nowej karcie <i class="bi bi-box-arrow-up-right"></i>
              </a>
              <?php if (!empty($inv['pdf_path'])): ?>
              <a href="<?= APP_URL ?>/crm/invoices/pdf.php?id=<?= $id ?>&gen=1" target="_blank" rel="noopener" class="text-muted">
                Podejrzyj wersję z szablonu SZO
              </a>
              <?php endif; ?>
            </div>
          </div>
        </details>
      </div>
    </div>

    <!-- Metryka + akcje -->
    <div class="col-lg-5">
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-header fw-semibold py-2">Dokument</div>
        <div class="card-body py-2">
          <dl class="row mb-0 small">
            <dt class="col-6">Data wystawienia</dt>
            <dd class="col-6 text-end"><?= h($inv['issue_date'] ? date('d.m.Y', strtotime($inv['issue_date'])) : '—') ?></dd>
            <dt class="col-6">Data sprzedaży</dt>
            <dd class="col-6 text-end"><?= h($inv['sell_date'] ? date('d.m.Y', strtotime($inv['sell_date'])) : '—') ?></dd>
            <dt class="col-6">Termin płatności</dt>
            <dd class="col-6 text-end"><?= h($inv['payment_to'] ? date('d.m.Y', strtotime($inv['payment_to'])) : '—') ?></dd>
            <?php if (!empty($inv['paid_at'])): ?>
            <dt class="col-6">Zapłacona</dt>
            <dd class="col-6 text-end"><?= h(date('d.m.Y', strtotime($inv['paid_at']))) ?></dd>
            <?php endif; ?>
            <?php if ($src_link): ?>
            <dt class="col-6">Źródło</dt>
            <dd class="col-6 text-end"><a href="<?= h($src_link[0]) ?>"><?= h($src_link[1]) ?></a></dd>
            <?php endif; ?>
            <?php if (!empty($inv['last_sync_at'])): ?>
            <dt class="col-6">Ostatnia synchronizacja</dt>
            <dd class="col-6 text-end"><?= h(date('d.m.Y H:i', strtotime($inv['last_sync_at']))) ?></dd>
            <?php endif; ?>
          </dl>
          <?php if (!empty($inv['notes'])): ?>
          <hr class="my-2">
          <div class="small text-muted" style="white-space:pre-line"><?= h($inv['notes']) ?></div>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($can_write): ?>
      <div class="card border-0 shadow-sm">
        <div class="card-header fw-semibold py-2">Akcje</div>
        <div class="card-body py-2 d-grid gap-2">
          <?php if ($is_draft): ?>
            <a class="btn btn-sm btn-crm-outline" href="<?= APP_URL ?>/crm/invoices/form.php?id=<?= $id ?>">
              <i class="bi bi-pencil me-1"></i>Edytuj szkic
            </a>
            <a class="btn btn-sm btn-crm-ghost" target="_blank" rel="noopener"
               href="<?= APP_URL ?>/crm/invoices/pdf.php?id=<?= $id ?>&gen=1">
              <i class="bi bi-file-earmark-pdf me-1"></i>Podgląd PDF (szablon SZO)
            </a>
            <?php $ksef_skip = invoice_ksef_skip_reason($inv); ?>
            <?php if ($ksef_skip !== ''): ?>
            <div class="alert alert-light border py-2 px-2 mb-1" style="font-size:.76rem" role="note">
              <i class="bi bi-info-circle me-1" aria-hidden="true"></i><?= h($ksef_skip) ?>
              Wystawiasz ją w SZO i przekazujesz nabywcy bezpośrednio.
            </div>
            <?php endif; ?>

            <!-- Wystawienie własne: numer nadaje SZO, dokument z naszego szablonu.
                 Kompletna ścieżka także wtedy, gdy żaden system zewnętrzny nie jest
                 podłączony — dlatego stoi jako pierwsze i nie jest nigdy wyłączone.
                 Dwa warianty różni tylko to, co dzieje się z gotowym PDF-em. -->
            <form method="post" onsubmit="return confirm('Wystawić fakturę i nadać jej numer w SZO? Po wystawieniu dokumentu nie da się już edytować.')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="issue_local">
              <input type="hidden" name="mode" value="download">
              <button class="btn btn-sm btn-crm-primary w-100" type="submit">
                <i class="bi bi-file-earmark-check me-1"></i>Wystaw i pobierz PDF
              </button>
            </form>
            <?php
              $buyer_mail_ok = trim((string)$inv['buyer_email']) !== ''
                            && filter_var($inv['buyer_email'], FILTER_VALIDATE_EMAIL);
            ?>
            <form method="post" onsubmit="return confirm('Wystawić fakturę i wysłać ją nabywcy na <?= h((string)$inv['buyer_email']) ?>?')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="issue_local">
              <input type="hidden" name="mode" value="send">
              <button class="btn btn-sm btn-crm-outline w-100" type="submit" <?= $buyer_mail_ok ? '' : 'disabled' ?>
                      title="<?= $buyer_mail_ok ? 'Wystaw, pobierz i wyślij nabywcy' : 'Uzupełnij e-mail nabywcy, aby wysłać' ?>">
                <i class="bi bi-envelope-check me-1"></i>Wystaw, pobierz i wyślij
              </button>
            </form>
            <form method="post" onsubmit="return confirm('Wystawić fakturę w Fakturowni? Po wystawieniu dokumentu nie da się już edytować w SZO.')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="push">
              <button class="btn btn-sm btn-crm-outline w-100" type="submit" <?= invoices_api_ready() ? '' : 'disabled' ?>>
                <i class="bi bi-send-check me-1"></i>Wystaw w Fakturowni
              </button>
            </form>
            <form method="post" onsubmit="return confirm('Usunąć szkic faktury?')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="delete">
              <button class="btn btn-sm btn-outline-danger w-100" type="submit">
                <i class="bi bi-trash me-1"></i>Usuń szkic
              </button>
            </form>
          <?php else: ?>
            <?php if (invoice_ksef_applicable($inv)): ?>
            <?php if (!empty($inv['ksef_number'])): ?>
            <div class="alert alert-success py-2 px-2 mb-1" style="font-size:.78rem" role="status">
              <i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i>
              W KSeF, numer:<br><span class="font-monospace"><?= h($inv['ksef_number']) ?></span>
            </div>
            <?php else: ?>
            <form method="post" onsubmit="return confirm('Wysłać fakturę do KSeF? Operacja jest nieodwracalna.')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="ksef_send">
              <button class="btn btn-sm btn-crm-primary w-100" type="submit" <?= ksef_ready() ? '' : 'disabled' ?>
                      title="<?= ksef_ready() ? 'Wyślij do Krajowego Systemu e-Faktur' : 'KSeF nieskonfigurowany (token, NIP)' ?>">
                <i class="bi bi-bank me-1"></i>Wyślij do KSeF
              </button>
            </form>
            <a class="btn btn-sm btn-crm-ghost w-100" target="_blank" rel="noopener"
               href="<?= APP_URL ?>/crm/invoices/ksef_xml.php?id=<?= $id ?>">
              <i class="bi bi-filetype-xml me-1"></i>Podejrzyj XML FA(3)
            </a>
            <?php endif; ?>
            <?php endif; ?>

            <form method="post">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="sync">
              <button class="btn btn-sm btn-crm-outline w-100" type="submit">
                <i class="bi bi-arrow-repeat me-1"></i>Odśwież status z Fakturowni
              </button>
            </form>
            <?php if (!empty($inv['pdf_path'])): ?>
            <a class="btn btn-sm btn-crm-outline" href="<?= APP_URL ?>/crm/invoices/pdf.php?id=<?= $id ?>">
              <i class="bi bi-file-earmark-pdf me-1"></i>Pobierz PDF
            </a>
            <?php endif; ?>
            <?php if (trim((string)$inv['buyer_email']) !== ''): ?>
            <form method="post" onsubmit="return confirm('Wysłać fakturę na <?= h((string)$inv['buyer_email']) ?>?')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="send_buyer">
              <button class="btn btn-sm btn-crm-outline w-100" type="submit">
                <i class="bi bi-envelope-check me-1"></i>Wyślij nabywcy
              </button>
            </form>
            <?php endif; ?>
            <form method="post">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="pdf_refresh">
              <button class="btn btn-sm btn-crm-ghost w-100" type="submit">
                <i class="bi bi-cloud-arrow-down me-1"></i><?= empty($inv['pdf_path']) ? 'Pobierz PDF z Fakturowni' : 'Pobierz PDF ponownie' ?>
              </button>
            </form>
            <?php if (!empty($inv['fakturownia_url'])): ?>
            <a class="btn btn-sm btn-crm-ghost" target="_blank" rel="noopener" href="<?= h($inv['fakturownia_url']) ?>">
              <i class="bi bi-box-arrow-up-right me-1"></i>Otwórz w Fakturowni
            </a>
            <?php endif; ?>
            <a class="btn btn-sm btn-crm-ghost" target="_blank" rel="noopener"
               href="<?= APP_URL ?>/crm/invoices/pdf.php?id=<?= $id ?>&gen=1">
              <i class="bi bi-printer me-1"></i>Wydruk z szablonu SZO
            </a>
            <a class="btn btn-sm btn-crm-ghost" target="_blank" rel="noopener"
               href="<?= APP_URL ?>/crm/invoices/pdf.php?id=<?= $id ?>&gen=1&duplikat=1">
              <i class="bi bi-files me-1"></i>Duplikat</a>
            <p class="text-muted small mb-0 mt-1">
              Korektę i anulowanie wykonuje się w Fakturowni — SZO odczyta zmianę po odświeżeniu statusu.
            </p>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>
<style>
/* Domyślny trójkącik <details> psuje nagłówek karty — zastępujemy go strzałką
   po prawej, żeby było widać, że blok się rozwija. */
#invPreview > summary { display:flex; align-items:center }
#invPreview > summary::-webkit-details-marker { display:none }
#invPreview > summary::after { content:'▾'; margin-left:auto; color:#6B7280 }
#invPreview[open] > summary::after { content:'▴' }
</style>
<script>
// Ramka podglądu dostaje adres przy pierwszym rozwinięciu — dzięki temu wejście
// na kartę faktury nie renderuje PDF-a niepotrzebnie.
(function () {
  var det = document.getElementById('invPreview');
  var fr  = document.getElementById('invPreviewFrame');
  if (!det || !fr) return;
  det.addEventListener('toggle', function () {
    if (det.open && !fr.getAttribute('src')) fr.setAttribute('src', fr.dataset.src);
  });
})();
</script>
<?php include dirname(__DIR__) . '/includes/footer_crm.php'; ?>
