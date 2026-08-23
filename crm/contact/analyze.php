<?php
/**
 * crm/contact/analyze.php — analizator kartotek z poczty.
 *
 * Dwa przeglądy na jednym ekranie:
 *   • kandydaci na kontakty firmowe — warto ich awansować na podmiot i zaopiekować,
 *   • kandydaci do usunięcia / odfiltrowania — śmieci z automatycznego zakładania.
 *
 * Każda pozycja pokazuje POWODY, dla których została wskazana. Analizator nie
 * usuwa i nie zmienia nic sam: decyzję podejmuje operator, bo pomyłka po stronie
 * automatu kosztuje utratę kontaktu.
 */

declare(strict_types=1);

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_contact_analyzer.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_migrate();
crm_sender_blocklist_migrate();

$can_write  = is_admin() || can_write('crm');
$can_delete = is_admin() || can_delete('crm');
if (!$can_write) {
    flash_set('error', 'Brak uprawnień do analizy kartotek.');
    header('Location: ' . APP_URL . '/crm/index.php'); exit;
}

$tab       = ($_GET['tab'] ?? 'company') === 'junk' ? 'junk' : (($_GET['tab'] ?? '') === 'filter' ? 'filter' : 'company');
$min_score = max(3, min(20, (int)($_GET['min'] ?? 6)));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op  = (string)($_POST['_op'] ?? '');
    $ids = array_values(array_unique(array_map('intval', (array)($_POST['pick'] ?? []))));
    $uid = (int)(current_user()['id'] ?? 0);
    $n   = 0;

    if ($op === 'promote') {
        // Awans na podmiot: typ „kontrahent" i status „aktywny". Nazwy organizacji
        // NIE zmyślamy — jeśli jej nie ma, zostaje puste i operator ją uzupełni.
        foreach ($ids as $cid) {
            $c = db_one("SELECT id, organizacja, email FROM crm_contacts WHERE id=? AND crm_active=1", [$cid]);
            if (!$c) continue;
            CrmManager::updateContact($cid, ['type' => 'kontrahent', 'status' => 'aktywny']);
            $n++;
        }
        flash_set($n ? 'success' : 'info', $n ? "Oznaczono jako kontrahent: {$n}." : 'Nie zaznaczono nikogo.');

    } elseif ($op === 'delete') {
        if (!$can_delete) {
            flash_set('error', 'Brak uprawnień do usuwania kartotek.');
        } else {
            foreach ($ids as $cid) { CrmManager::deleteContact($cid); $n++; }
            flash_set($n ? 'success' : 'info', $n ? "Usunięto kartotek: {$n} (miękko — dane zostają w bazie)." : 'Nie zaznaczono nikogo.');
        }

    } elseif ($op === 'block') {
        // Filtr zakładamy po DOMENIE dla adresów automatycznych i po adresie dla
        // pozostałych — blokowanie całej domeny darmowej poczty odcięłoby ludzi.
        foreach ($ids as $cid) {
            $c = db_one("SELECT email FROM crm_contacts WHERE id=?", [$cid]);
            $e = strtolower(trim((string)($c['email'] ?? '')));
            if ($e === '') continue;
            $dom = crm_email_domain($e);
            $pat = (crm_is_robot_sender($e) && $dom !== '' && !crm_is_free_mail($dom)) ? '@' . $dom : $e;
            if (crm_sender_block_add($pat, 'z analizatora kartotek', $uid) === null) $n++;
        }
        flash_set($n ? 'success' : 'info', $n ? "Dodano do filtra: {$n}." : 'Nie dodano nic.');

    } elseif ($op === 'block_manual') {
        $err = crm_sender_block_add((string)($_POST['pattern'] ?? ''), (string)($_POST['reason'] ?? ''), $uid);
        flash_set($err ? 'error' : 'success', $err ?: 'Filtr dodany.');

    } elseif ($op === 'block_del') {
        db()->prepare("DELETE FROM crm_sender_blocklist WHERE id=?")->execute([(int)($_POST['block_id'] ?? 0)]);
        flash_set('success', 'Filtr usunięty.');
    }

    header('Location: ' . APP_URL . '/crm/contact/analyze.php?tab=' . $tab . '&min=' . $min_score); exit;
}

$res    = crm_analyze_contacts($min_score, 300);
$blocks = [];
try { $blocks = db_all("SELECT * FROM crm_sender_blocklist ORDER BY kind, pattern"); } catch (\Throwable $e) {}

$PAGE_TITLE = 'Analiza kartotek';
$qs = fn(array $o = []) => '?' . http_build_query(array_merge(['tab' => $tab, 'min' => $min_score], $o));

include dirname(__DIR__) . '/includes/header_crm.php';
?>
<div class="container-fluid px-0" style="max-width:1050px">

  <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
    <a href="<?= APP_URL ?>/crm/index.php" class="btn btn-sm btn-crm-ghost"><i class="bi bi-arrow-left"></i></a>
    <h1 class="h5 mb-0 me-auto"><i class="bi bi-funnel me-2"></i>Analiza kartotek z poczty</h1>
    <span class="text-muted small">sprawdzono <?= (int)$res['checked'] ?> kartotek z adresem</span>
  </div>

  <p class="text-muted small mb-3" style="max-width:78ch">
    Automatyczne zakładanie kartotek dla nieznanych nadawców daje dwa skutki naraz: trafiają
    się wartościowe kontakty firmowe i śmieci. Analizator ocenia jedno i drugie po
    <strong>śladach w danych</strong> — czy odpisaliśmy, ile było wiadomości, czy domena jest
    firmowa, czy z tej domeny pisze więcej osób, czy są sprawy albo oferty. Nic nie dzieje się
    samo: każda pozycja pokazuje powody, a decyzję podejmujesz Ty.
  </p>

  <ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item"><a class="nav-link<?= $tab === 'company' ? ' active' : '' ?>" href="<?= h($qs(['tab'=>'company'])) ?>">
      <i class="bi bi-building me-1"></i>Kandydaci firmowi <span class="badge bg-secondary ms-1"><?= count($res['company']) ?></span></a></li>
    <li class="nav-item"><a class="nav-link<?= $tab === 'junk' ? ' active' : '' ?>" href="<?= h($qs(['tab'=>'junk'])) ?>">
      <i class="bi bi-trash me-1"></i>Do usunięcia <span class="badge bg-secondary ms-1"><?= count($res['junk']) ?></span></a></li>
    <li class="nav-item"><a class="nav-link<?= $tab === 'filter' ? ' active' : '' ?>" href="<?= h($qs(['tab'=>'filter'])) ?>">
      <i class="bi bi-slash-circle me-1"></i>Filtr nadawców <span class="badge bg-secondary ms-1"><?= count($blocks) ?></span></a></li>
  </ul>

  <?php if ($tab === 'company'): ?>
  <form method="get" class="d-flex align-items-end gap-2 mb-2">
    <input type="hidden" name="tab" value="company">
    <div>
      <label class="form-label small mb-1" for="minsc">Próg punktowy</label>
      <input type="number" class="form-control form-control-sm" id="minsc" name="min"
             value="<?= $min_score ?>" min="3" max="20" style="width:6rem">
    </div>
    <button class="btn btn-sm btn-crm-outline" type="submit">Przelicz</button>
    <span class="text-muted small ms-2">Niżej próg = więcej kandydatów, mniej pewnych.</span>
  </form>
  <?php endif; ?>

  <?php if ($tab !== 'filter'): $rows = $tab === 'company' ? $res['company'] : $res['junk']; ?>
  <form method="post">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <div class="card border-0 shadow-sm">
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <caption class="visually-hidden">Wyniki analizy</caption>
          <thead class="table-light">
            <tr>
              <th scope="col" style="width:2.5rem"><input type="checkbox" class="form-check-input" id="pickAll" aria-label="Zaznacz wszystkie"></th>
              <?php if ($tab === 'company'): ?><th scope="col" style="width:4rem" class="text-end">Punkty</th><?php endif; ?>
              <th scope="col">Kontakt</th>
              <th scope="col">Adres / domena</th>
              <th scope="col">Dlaczego</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">
              <?= $tab === 'company'
                  ? 'Nikt nie przekroczył progu. Obniż próg albo poczekaj na więcej korespondencji.'
                  : 'Nie ma kartotek wskazanych do usunięcia.' ?>
            </td></tr>
          <?php else: foreach ($rows as $c): ?>
            <tr>
              <td><input type="checkbox" class="form-check-input pick" name="pick[]" value="<?= (int)$c['id'] ?>"
                         aria-label="Wybierz <?= h((string)$c['imie_nazwisko']) ?>"></td>
              <?php if ($tab === 'company'): ?>
              <td class="text-end"><span class="badge bg-success"><?= (int)$c['score'] ?></span></td>
              <?php endif; ?>
              <td>
                <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$c['id'] ?>" class="fw-semibold text-decoration-none">
                  <?= h(mb_strimwidth((string)$c['imie_nazwisko'], 0, 34, '…')) ?>
                </a>
                <?php if (!empty($c['organizacja'])): ?>
                <div class="text-muted" style="font-size:.74rem"><?= h((string)$c['organizacja']) ?></div>
                <?php endif; ?>
              </td>
              <td class="small">
                <?= h((string)$c['email']) ?>
                <?php if (!empty($c['domain']) && crm_is_free_mail((string)$c['domain'])): ?>
                <span class="badge bg-light text-dark border" style="font-size:.6rem" title="Darmowa poczta">poczta darmowa</span>
                <?php endif; ?>
                <?php if (!empty($c['hard'])): ?>
                <span class="badge bg-danger" style="font-size:.6rem" title="Adres automatyczny — kandydat na filtr">auto</span>
                <?php endif; ?>
              </td>
              <td class="small text-muted"><?= h(implode(' · ', (array)$c['reasons'])) ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($rows): ?>
    <div class="card border-0 shadow-sm mt-3">
      <div class="card-body py-2 d-flex flex-wrap gap-2 align-items-center">
        <?php if ($tab === 'company'): ?>
        <button type="submit" name="_op" value="promote" class="btn btn-sm btn-crm-primary"
                onclick="return this.form.querySelectorAll('.pick:checked').length ? true : (alert('Zaznacz co najmniej jeden kontakt.'), false)">
          <i class="bi bi-building me-1"></i>Oznacz jako kontrahent
        </button>
        <span class="text-muted small">Typ zmieni się na „kontrahent", status na „aktywny". Nazwy organizacji nie zmyślamy.</span>
        <?php else: ?>
        <button type="submit" name="_op" value="block" class="btn btn-sm btn-crm-outline"
                onclick="return this.form.querySelectorAll('.pick:checked').length ? true : (alert('Zaznacz co najmniej jeden kontakt.'), false)">
          <i class="bi bi-slash-circle me-1"></i>Dodaj do filtra
        </button>
        <?php if ($can_delete): ?>
        <button type="submit" name="_op" value="delete" class="btn btn-sm btn-outline-danger"
                onclick="return this.form.querySelectorAll('.pick:checked').length ? confirm('Usunąć zaznaczone kartoteki? Usunięcie jest miękkie — dane zostają w bazie.') : (alert('Zaznacz co najmniej jeden kontakt.'), false)">
          <i class="bi bi-trash me-1"></i>Usuń kartoteki
        </button>
        <?php endif; ?>
        <span class="text-muted small ms-auto">
          Filtr blokuje ponowne zakładanie kartoteki przy kolejnym skanowaniu poczty.
        </span>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </form>

  <?php else: ?>
  <div class="row g-3">
    <div class="col-lg-7">
      <div class="card border-0 shadow-sm">
        <div class="card-header fw-semibold py-2">Odfiltrowani nadawcy</div>
        <div class="table-responsive">
          <table class="table table-sm mb-0 align-middle">
            <caption class="visually-hidden">Filtr nadawców</caption>
            <thead class="table-light"><tr>
              <th scope="col">Wzorzec</th><th scope="col">Rodzaj</th><th scope="col">Powód</th><th scope="col"></th>
            </tr></thead>
            <tbody>
            <?php if (!$blocks): ?>
              <tr><td colspan="4" class="text-muted text-center py-4">Filtr jest pusty — każdy nadawca może założyć kartotekę.</td></tr>
            <?php else: foreach ($blocks as $b): ?>
              <tr>
                <td class="font-monospace small"><?= h((string)$b['pattern']) ?></td>
                <td><span class="badge bg-light text-dark border"><?= $b['kind'] === 'domain' ? 'domena' : 'adres' ?></span></td>
                <td class="small text-muted"><?= h((string)$b['reason']) ?></td>
                <td class="text-end">
                  <form method="post" class="d-inline">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="_op" value="block_del">
                    <input type="hidden" name="block_id" value="<?= (int)$b['id'] ?>">
                    <button class="btn btn-sm btn-link text-danger p-0" title="Usuń filtr"><i class="bi bi-x-lg"></i></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="card border-0 shadow-sm">
        <div class="card-header fw-semibold py-2">Dodaj filtr</div>
        <div class="card-body">
          <form method="post">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_op" value="block_manual">
            <label class="form-label small fw-semibold" for="pat">Adres albo domena</label>
            <input class="form-control form-control-sm mb-2" id="pat" name="pattern" required
                   placeholder="noreply@example.com albo @example.com">
            <label class="form-label small fw-semibold" for="rsn">Powód</label>
            <input class="form-control form-control-sm mb-2" id="rsn" name="reason" placeholder="np. newsletter branżowy">
            <button class="btn btn-sm btn-crm-primary w-100"><i class="bi bi-plus-lg me-1"></i>Dodaj</button>
            <p class="form-text mb-0">
              Domenę podaj z „@" na początku — zablokuje wszystkich nadawców z tej domeny.
              Nie blokuj domen darmowej poczty: odcięłoby to zwykłych ludzi.
            </p>
          </form>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
document.getElementById('pickAll')?.addEventListener('change', function () {
  document.querySelectorAll('.pick').forEach(c => { c.checked = this.checked; });
});
</script>
<?php include dirname(__DIR__) . '/includes/footer_crm.php'; ?>
