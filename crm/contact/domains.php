<?php
/**
 * crm/contact/domains.php — porządkowanie kartotek po domenie e-mail.
 *
 * Skrzynka CRM, import i sync Outlooka zakładają osobną kartotekę każdemu
 * nadawcy. Efekt: dwadzieścia osób z adresami @um.krakow.pl i ani jednego
 * rekordu urzędu. Ten ekran robi z tego jeden podmiot i listę osób kontaktowych.
 *
 * Dwa wejścia do tej samej operacji:
 *   • OD DOMENY   — widać, gdzie zebrała się grupa adresów bez podmiotu,
 *   • OD PODMIOTU — podmiot ma stronę WWW, więc jego domena jest znana i można
 *     od razu pokazać, kto z bazy pisze z tej domeny.
 *
 * Nic nie dzieje się automatycznie: operator wybiera podmiot, zaznacza osoby
 * i widzi, co dokładnie zostanie przeniesione. Kartoteki nie są kasowane —
 * najwyżej wygaszane (crm_active=0), więc pomyłkę da się cofnąć.
 */

declare(strict_types=1);

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_domains.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_merge.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_migrate();
crm_require('contacts', 'read');

$can_write = is_admin() || can_write('crm');
if (!$can_write) {
    flash_set('error', 'Brak uprawnień do porządkowania kartotek.');
    header('Location: ' . APP_URL . '/crm/index.php'); exit;
}

$domain = crm_domain_normalize((string)($_GET['domain'] ?? ''));
$tab    = ($_GET['tab'] ?? 'domeny') === 'podmioty' ? 'podmioty' : 'domeny';

// ── POST: przypnij zaznaczone osoby do podmiotu ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $post_domain = crm_domain_normalize((string)($_POST['domain'] ?? ''));
    $ids         = array_values(array_unique(array_map('intval', (array)($_POST['pick'] ?? []))));
    $org_id      = (int)($_POST['org_id'] ?? 0);
    $new_name    = trim((string)($_POST['new_org_name'] ?? ''));
    $move        = !empty($_POST['move_history']);
    $deactivate  = !empty($_POST['deactivate']);
    $back        = APP_URL . '/crm/contact/domains.php?domain=' . urlencode($post_domain);

    if (crm_domain_is_public($post_domain)) {
        flash_set('error', 'To domena darmowej poczty — adresy w niej nie należą do jednego podmiotu.');
        header('Location: ' . $back); exit;
    }
    if (!$ids) {
        flash_set('info', 'Nie zaznaczono żadnej osoby.');
        header('Location: ' . $back); exit;
    }

    // Podmiot docelowy: istniejący albo zakładany na miejscu.
    if (!$org_id && $new_name !== '') {
        $org_id = CrmManager::createContact([
            'type'          => 'organizacja',
            'status'        => 'aktywny',
            'imie_nazwisko' => $new_name,
            'strona_www'    => 'https://' . $post_domain,
            'source'        => 'domains',
            'created_by'    => (int)(current_user()['id'] ?? 0) ?: null,
        ]);
        if ($org_id) {
            CrmManager::addNote($org_id, sprintf(
                'Podmiot założony z ekranu porządkowania domen (domena %s).', $post_domain
            ), (int)(current_user()['id'] ?? 0) ?: null);
        }
    }

    if (!$org_id) {
        flash_set('error', 'Wskaż podmiot docelowy albo podaj nazwę nowego.');
        header('Location: ' . $back); exit;
    }

    $ok = 0; $errors = [];
    foreach ($ids as $cid) {
        $r = crm_attach_contact_to_org($org_id, $cid, [
            'move_history' => $move,
            'deactivate'   => $deactivate,
        ]);
        if (!empty($r['ok'])) $ok++;
        elseif (!empty($r['error'])) $errors[] = $r['error'];
    }

    $errors = array_values(array_unique($errors));
    if ($ok) {
        flash_set('success', sprintf(
            'Przypięto %d %s do podmiotu.%s',
            $ok, $ok === 1 ? 'osobę' : 'osób',
            $errors ? ' Pominięto: ' . implode(' ', $errors) : ''
        ));
        header('Location: ' . APP_URL . '/crm/contact/view.php?id=' . $org_id); exit;
    }
    flash_set('error', $errors ? implode(' ', $errors) : 'Nic nie przypięto.');
    header('Location: ' . $back); exit;
}

$PAGE_TITLE = 'Kartoteki wg domen';
include dirname(__DIR__) . '/includes/header_crm.php';
?>
<div class="container-fluid px-0" style="max-width:1050px">

  <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
    <a href="<?= APP_URL ?>/crm/index.php" class="btn btn-sm btn-crm-ghost" aria-label="Wróć do listy kontaktów"><i class="bi bi-arrow-left"></i></a>
    <h1 class="h5 mb-0 me-auto"><i class="bi bi-diagram-3 me-2"></i>Kartoteki wg domen</h1>
  </div>

<?php if ($domain === ''): ?>

  <p class="text-muted small mb-3" style="max-width:78ch">
    Adresy z jednej domeny prawie zawsze należą do jednej instytucji. Poniżej domeny,
    w których zebrało się więcej niż jedna kartoteka — oraz podmioty, których
    <strong>strona WWW</strong> zdradza domenę, więc wiadomo, kogo do nich przypiąć.
    Domeny darmowej poczty są pominięte: <code>gmail.com</code> nie jest organizacją.
  </p>

  <ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item"><a class="nav-link<?= $tab === 'domeny' ? ' active' : '' ?>" href="?tab=domeny">
      <i class="bi bi-at me-1"></i>Domeny w kartotece</a></li>
    <li class="nav-item"><a class="nav-link<?= $tab === 'podmioty' ? ' active' : '' ?>" href="?tab=podmioty">
      <i class="bi bi-building me-1"></i>Podmioty ze stroną WWW</a></li>
  </ul>

  <?php if ($tab === 'domeny'): $list = crm_domain_overview(false, 2); ?>
  <div class="card">
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <caption class="visually-hidden">Domeny występujące w kartotece kontaktów</caption>
        <thead class="table-light">
          <tr>
            <th scope="col">Domena</th>
            <th scope="col" class="text-end" style="width:8rem">Kartotek</th>
            <th scope="col" style="width:16rem">Podmiot w bazie</th>
            <th scope="col" style="width:9rem"></th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$list): ?>
          <tr><td colspan="4" class="text-center text-muted py-4">
            Nie ma domeny, w której byłaby więcej niż jedna kartoteka. Nie ma czego porządkować.
          </td></tr>
        <?php else: foreach ($list as $d): $orgs = crm_domain_orgs($d['domain']); ?>
          <tr>
            <td class="fw-semibold font-monospace" style="font-size:.85rem"><?= h($d['domain']) ?></td>
            <td class="text-end"><span class="badge bg-secondary"><?= (int)$d['n'] ?></span></td>
            <td class="small">
              <?php if ($orgs): ?>
                <?php foreach (array_slice($orgs, 0, 2) as $o): ?>
                <div><a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$o['id'] ?>" class="text-decoration-none"><?= h($o['imie_nazwisko']) ?></a></div>
                <?php endforeach; ?>
              <?php else: ?>
                <span class="text-muted">brak — trzeba założyć</span>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <a href="?domain=<?= urlencode($d['domain']) ?>" class="btn btn-sm btn-crm-outline">Przejrzyj</a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php else: $sugg = crm_domain_org_suggestions(); ?>
  <p class="text-muted small mb-2" style="max-width:78ch">
    Domena brana jest ze <strong>strony WWW</strong> podmiotu, a gdy jej nie ma — z jego
    adresu e-mail. WWW jest pewniejsze: skrzynka podmiotu bywa na cudzym serwerze,
    strona prawie zawsze stoi we własnej domenie.
  </p>
  <div class="card">
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <caption class="visually-hidden">Podmioty z rozpoznaną domeną</caption>
        <thead class="table-light">
          <tr>
            <th scope="col">Podmiot</th>
            <th scope="col">Domena ze strony WWW</th>
            <th scope="col" class="text-end" style="width:10rem">Osób do przypięcia</th>
            <th scope="col" style="width:9rem"></th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$sugg): ?>
          <tr><td colspan="4" class="text-center text-muted py-4">
            Żaden podmiot z rozpoznaną domeną nie ma w bazie osób z adresem w tej domenie.
          </td></tr>
        <?php else: foreach ($sugg as $s): ?>
          <tr>
            <td>
              <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$s['org']['id'] ?>" class="fw-semibold text-decoration-none">
                <?= h($s['org']['imie_nazwisko']) ?>
              </a>
            </td>
            <td class="font-monospace small"><?= h($s['domain']) ?></td>
            <td class="text-end"><span class="badge bg-primary"><?= count($s['candidates']) ?></span></td>
            <td class="text-end">
              <a href="?domain=<?= urlencode($s['domain']) ?>" class="btn btn-sm btn-crm-outline">Przejrzyj</a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

<?php else: /* ── Widok jednej domeny ─────────────────────────────────────── */
  $cands = crm_domain_contacts($domain);
  $orgs  = crm_domain_orgs($domain);
  $pub   = crm_domain_is_public($domain);

  // Ile historii wisi na kandydatach — jedno zapytanie na tabelę, nie na kontakt.
  $stat = [];
  if ($cands) {
      $in = implode(',', array_fill(0, count($cands), '?'));
      $ids = array_map(fn($c) => (int)$c['id'], $cands);
      foreach (['crm_communications' => 'comms', 'crm_cases' => 'cases'] as $tbl => $key) {
          try {
              foreach (db_all("SELECT contact_id, COUNT(*) AS n FROM {$tbl} WHERE contact_id IN ({$in}) GROUP BY contact_id", $ids) as $r) {
                  $stat[(int)$r['contact_id']][$key] = (int)$r['n'];
              }
          } catch (\Throwable $e) {}
      }
  }
?>
  <div class="d-flex flex-wrap align-items-baseline gap-2 mb-3">
    <a href="?" class="small text-decoration-none"><i class="bi bi-chevron-left"></i> wszystkie domeny</a>
    <span class="text-muted">·</span>
    <span class="font-monospace fw-semibold"><?= h($domain) ?></span>
    <span class="badge bg-secondary"><?= count($cands) ?> <?= count($cands) === 1 ? 'osoba' : 'osób' ?></span>
  </div>

  <?php if ($pub): ?>
  <div class="alert alert-warning">
    <strong><?= h($domain) ?></strong> to domena darmowej poczty. Adresy w niej nie należą do
    jednej instytucji, więc przypinanie ich do wspólnego podmiotu jest tu zablokowane.
  </div>
  <?php elseif (!$cands): ?>
  <div class="alert alert-info mb-0">W tej domenie nie ma kartotek osób do przypięcia.</div>
  <?php else: ?>

  <form method="post">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="domain" value="<?= h($domain) ?>">

    <!-- Podmiot docelowy -->
    <div class="card mb-3">
      <div class="card-body">
        <div class="crm-section-title">Podmiot docelowy</div>

        <?php if ($orgs): ?>
        <?php foreach ($orgs as $i => $o): ?>
        <div class="form-check">
          <input class="form-check-input" type="radio" name="org_id" id="org<?= (int)$o['id'] ?>"
                 value="<?= (int)$o['id'] ?>" <?= $i === 0 ? 'checked' : '' ?> data-org-pick>
          <label class="form-check-label" for="org<?= (int)$o['id'] ?>">
            <span class="fw-semibold"><?= h($o['imie_nazwisko']) ?></span>
            <span class="text-muted small ms-1"><?= h($o['strona_www'] ?: $o['email'] ?: '') ?></span>
          </label>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <div class="form-check">
          <input class="form-check-input" type="radio" name="org_id" id="orgNew" value="0"
                 <?= $orgs ? '' : 'checked' ?> data-org-pick>
          <label class="form-check-label" for="orgNew">Załóż nowy podmiot</label>
        </div>
        <div class="ms-4 mt-2" id="newOrgBox"<?= $orgs ? ' hidden' : '' ?>>
          <label class="form-label small mb-1" for="new_org_name">Nazwa podmiotu</label>
          <input type="text" class="form-control form-control-sm" id="new_org_name" name="new_org_name"
                 value="" placeholder="np. Urząd Miasta Krakowa" style="max-width:28rem">
          <div class="form-text" style="font-size:.75rem">
            Powstanie organizacja ze stroną <code>https://<?= h($domain) ?></code> i statusem „aktywny”.
          </div>
        </div>
      </div>
    </div>

    <!-- Co zrobić z historią -->
    <div class="card mb-3">
      <div class="card-body">
        <div class="crm-section-title">Co się stanie</div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="move_history" name="move_history" value="1" checked>
          <label class="form-check-label" for="move_history">
            Przenieś historię na podmiot
            <span class="text-muted small d-block">Wiadomości, sprawy, oferty, notatki, zgody i faktury zaznaczonych osób zaczną wisieć na podmiocie.</span>
          </label>
        </div>
        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" id="deactivate" name="deactivate" value="1" checked>
          <label class="form-check-label" for="deactivate">
            Wygaś samodzielne kartoteki
            <span class="text-muted small d-block">Osoby znikną z listy kontaktów i zostaną jako osoby kontaktowe podmiotu. Kartoteki nie są kasowane — da się je przywrócić.</span>
          </label>
        </div>
      </div>
    </div>

    <!-- Kandydaci -->
    <div class="card">
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <caption class="visually-hidden">Osoby z adresem w domenie <?= h($domain) ?></caption>
          <thead class="table-light">
            <tr>
              <th scope="col" style="width:2.5rem"><input type="checkbox" class="form-check-input" id="pickAll" checked aria-label="Zaznacz wszystkie"></th>
              <th scope="col">Osoba</th>
              <th scope="col">Adres</th>
              <th scope="col">Stanowisko</th>
              <th scope="col" class="text-end" style="width:12rem">Historia</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($cands as $c): $s = $stat[(int)$c['id']] ?? []; ?>
            <tr>
              <td><input type="checkbox" class="form-check-input pick" name="pick[]" value="<?= (int)$c['id'] ?>" checked
                         aria-label="Wybierz <?= h((string)$c['imie_nazwisko']) ?>"></td>
              <td>
                <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$c['id'] ?>" class="fw-semibold text-decoration-none" target="_blank" rel="noopener">
                  <?= h((string)$c['imie_nazwisko']) ?>
                </a>
                <?php if (!empty($c['organizacja'])): ?>
                <div class="text-muted" style="font-size:.74rem"><?= h((string)$c['organizacja']) ?></div>
                <?php endif; ?>
              </td>
              <td class="small font-monospace"><?= h((string)$c['email']) ?></td>
              <td class="small text-muted"><?= h((string)($c['stanowisko'] ?? '')) ?: '—' ?></td>
              <td class="text-end small text-muted">
                <?php
                  $bits = [];
                  if (!empty($s['comms'])) $bits[] = $s['comms'] . ' wiad.';
                  if (!empty($s['cases'])) $bits[] = $s['cases'] . ' spr.';
                  echo $bits ? h(implode(' · ', $bits)) : '—';
                ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-body border-top d-flex flex-wrap gap-2 align-items-center">
        <button class="btn btn-crm-primary btn-sm" type="submit">
          <i class="bi bi-people-fill me-1"></i>Przypnij zaznaczone do podmiotu
        </button>
        <span class="text-muted small">Operacja zapisuje notatkę w obu kartotekach.</span>
      </div>
    </div>
  </form>

  <script>
  (function() {
      var all = document.getElementById('pickAll');
      if (all) all.addEventListener('change', function() {
          document.querySelectorAll('.pick').forEach(function(c) { c.checked = all.checked; });
      });
      // Pole nazwy nowego podmiotu ma sens tylko przy wyborze „Załóż nowy”.
      var box = document.getElementById('newOrgBox');
      document.querySelectorAll('[data-org-pick]').forEach(function(r) {
          r.addEventListener('change', function() {
              var isNew = document.getElementById('orgNew').checked;
              box.hidden = !isNew;
              if (isNew) document.getElementById('new_org_name').focus();
          });
      });
  })();
  </script>
  <?php endif; ?>

<?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/includes/footer_crm.php'; ?>
