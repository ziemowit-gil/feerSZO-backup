<?php
/**
 * crm/contact/merge.php — duplikaty kartotek: wykrywanie i scalanie.
 *
 * Duplikaty produkują cztery źródła naraz: auto-kartoteka ze Skrzynki CRM,
 * import CSV, synchronizacja Outlooka i formularze zgłoszeniowe. Import umiał
 * je tylko POMIJAĆ po adresie e-mail, więc wszystko, co przyszło inną drogą
 * albo z inną literówką, zostawało w bazie jako druga kartoteka tej samej osoby.
 *
 * Ekran pokazuje grupy podejrzeń wraz z POWODEM (ten sam NIP / e-mail /
 * telefon / nazwa) i pozwala scalić dwie kartoteki po obejrzeniu ich obok
 * siebie. Nic nie scala się automatycznie: dwóch Janów Kowalskich to nie
 * duplikat, a scalenia nie da się odkliknąć jednym ruchem.
 */

declare(strict_types=1);

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_merge.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_migrate();

$can_write = is_admin() || can_write('crm');
if (!$can_write) {
    flash_set('error', 'Brak uprawnień do scalania kartotek.');
    header('Location: ' . APP_URL . '/crm/index.php'); exit;
}

// ── POST: scal ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $keep = (int)($_POST['keep'] ?? 0);
    $drop = (int)($_POST['drop'] ?? 0);

    $res = crm_merge_contacts($keep, $drop);
    if (!empty($res['ok'])) {
        $moved = $res['moved'] ?? [];
        flash_set('success', sprintf(
            'Scalono. Przeniesiono: %s.',
            $moved ? implode(', ', array_map(
                fn($t, $n) => crm_merge_table_label($t) . ": $n", array_keys($moved), $moved)) : 'nic'
        ));
        header('Location: ' . APP_URL . '/crm/contact/view.php?id=' . $keep); exit;
    }
    flash_set('error', $res['error'] ?? 'Nie udało się scalić kartotek.');
    header('Location: ' . APP_URL . '/crm/contact/merge.php'); exit;
}

$a_id = (int)($_GET['a'] ?? 0);
$b_id = (int)($_GET['b'] ?? 0);

$PAGE_TITLE = 'Duplikaty kartotek';
include dirname(__DIR__) . '/includes/header_crm.php';
?>
<div class="container-fluid px-0" style="max-width:1000px">

  <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
    <a href="<?= APP_URL ?>/crm/index.php" class="btn btn-sm btn-crm-ghost" aria-label="Wróć do listy kontaktów"><i class="bi bi-arrow-left"></i></a>
    <h1 class="h5 mb-0 me-auto"><i class="bi bi-intersect me-2"></i>Duplikaty kartotek</h1>
  </div>

<?php if ($a_id && $b_id):
  $A = db_one("SELECT * FROM crm_contacts WHERE id=?", [$a_id]);
  $B = db_one("SELECT * FROM crm_contacts WHERE id=?", [$b_id]);
  if (!$A || !$B): ?>
  <div class="alert alert-danger">Nie znaleziono jednej z kartotek.</div>
  <?php else:
    $prevA = crm_merge_preview($a_id);
    $prevB = crm_merge_preview($b_id);
    // Pola pokazywane w porównaniu — te, o które w kartotece naprawdę chodzi.
    $cmp = ['type','status','imie_nazwisko','email','telefon','nip','krs','regon',
            'organizacja','stanowisko','strona_www','branza','adres','owner_id','source','notatka'];
  ?>
  <p class="text-muted small mb-3" style="max-width:78ch">
    Scalenie przenosi <strong>całą historię</strong> kartoteki porzucanej na zachowaną i uzupełnia
    w niej puste pola. Wypełnione pola kartoteki zachowanej zostają nietknięte — scalenie ma
    dodawać informację, nie ujmować. Porzucana kartoteka nie jest kasowana, tylko wygaszana.
  </p>

  <form method="post">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <div class="card border-0 shadow-sm">
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <caption class="visually-hidden">Porównanie dwóch kartotek</caption>
          <thead class="table-light">
            <tr>
              <th scope="col" style="width:11rem">Pole</th>
              <?php foreach ([[$A, $a_id, $b_id], [$B, $b_id, $a_id]] as [$C, $cid, $oid]): ?>
              <th scope="col">
                <label class="d-flex align-items-start gap-2" style="cursor:pointer">
                  <input class="form-check-input mt-1" type="radio" name="keep" value="<?= (int)$cid ?>"
                         <?= $cid === $a_id ? 'checked' : '' ?> data-keep>
                  <span>
                    <span class="fw-semibold"><?= h($C['imie_nazwisko']) ?></span>
                    <span class="d-block text-muted fw-normal" style="font-size:.74rem">
                      #<?= (int)$cid ?> · dodano <?= date('d.m.Y', strtotime($C['created_at'])) ?>
                    </span>
                  </span>
                </label>
              </th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($cmp as $f):
            $va = crm_audit_format($f, $A[$f] ?? null);
            $vb = crm_audit_format($f, $B[$f] ?? null);
            if ($va === '—' && $vb === '—') continue;
            $diff = $va !== $vb;
          ?>
            <tr<?= $diff ? ' style="background:#FFFBEB"' : '' ?>>
              <td class="text-muted small"><?= h(crm_audit_field_label($f)) ?></td>
              <td class="small"><?= h($va) ?></td>
              <td class="small"><?= h($vb) ?></td>
            </tr>
          <?php endforeach; ?>
            <tr class="table-light">
              <td class="text-muted small">Co wisi na kartotece</td>
              <?php foreach ([$prevA, $prevB] as $p): ?>
              <td class="small">
                <?php if (!$p): ?><span class="text-muted">nic</span>
                <?php else: foreach ($p as $t => $n): ?>
                  <div><?= h(crm_merge_table_label($t)) ?>: <strong><?= (int)$n ?></strong></div>
                <?php endforeach; endif; ?>
              </td>
              <?php endforeach; ?>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="card-body border-top d-flex flex-wrap gap-2 align-items-center">
        <input type="hidden" name="drop" id="dropField" value="<?= (int)$b_id ?>">
        <button class="btn btn-crm-primary btn-sm" type="submit"
                onclick="return confirm('Scalić kartoteki? Operacji nie cofa jedno kliknięcie.')">
          <i class="bi bi-intersect me-1"></i>Scal — zostaw zaznaczoną
        </button>
        <a href="?" class="btn btn-outline-secondary btn-sm">Anuluj</a>
        <span class="text-muted small">Żółte wiersze to pola, które się różnią.</span>
      </div>
    </div>
  </form>

  <script>
  (function() {
      // Porzucana kartoteka to zawsze ta druga — pilnujemy tego jednym polem.
      var A = <?= (int)$a_id ?>, B = <?= (int)$b_id ?>, drop = document.getElementById('dropField');
      document.querySelectorAll('[data-keep]').forEach(function(r) {
          r.addEventListener('change', function() {
              drop.value = (parseInt(r.value, 10) === A) ? B : A;
          });
      });
  })();
  </script>
  <?php endif; ?>

<?php else: $groups = crm_find_duplicates(); ?>

  <p class="text-muted small mb-3" style="max-width:78ch">
    Podejrzenia wyliczane na żywo z kartoteki. Powód mówi, na jakiej podstawie kartoteki
    trafiły do jednej grupy — <strong>ten sam NIP</strong> to praktycznie dowód, sama
    <strong>zbieżność nazwy</strong> to najsłabszy sygnał (dwóch Janów Kowalskich to nie
    duplikat). Nic nie dzieje się samo.
  </p>

  <div class="card border-0 shadow-sm">
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <caption class="visually-hidden">Grupy prawdopodobnych duplikatów</caption>
        <thead class="table-light">
          <tr>
            <th scope="col" style="width:9rem">Powód</th>
            <th scope="col">Kartoteki</th>
            <th scope="col" style="width:8rem"></th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$groups): ?>
          <tr><td colspan="3" class="text-center text-muted py-4">
            Nie znaleziono podejrzeń o duplikat. Kartoteka jest czysta.
          </td></tr>
        <?php else: foreach ($groups as $g):
          $badge = ['pewne' => 'bg-danger', 'mocne' => 'bg-warning text-dark', 'słabe' => 'bg-secondary'][$g['strength']] ?? 'bg-secondary';
        ?>
          <tr>
            <td>
              <span class="badge <?= $badge ?>"><?= h($g['strength']) ?></span>
              <div class="text-muted" style="font-size:.74rem"><?= h($g['reason']) ?></div>
            </td>
            <td class="small">
              <?php foreach ($g['contacts'] as $c): ?>
              <div>
                <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$c['id'] ?>" class="text-decoration-none" target="_blank" rel="noopener">
                  <?= h($c['imie_nazwisko']) ?>
                </a>
                <span class="text-muted">
                  #<?= (int)$c['id'] ?>
                  <?= $c['email'] ? ' · ' . h($c['email']) : '' ?>
                  <?= $c['telefon'] ? ' · ' . h($c['telefon']) : '' ?>
                </span>
              </div>
              <?php endforeach; ?>
            </td>
            <td class="text-end">
              <?php if (count($g['contacts']) === 2): ?>
              <a class="btn btn-sm btn-crm-outline"
                 href="?a=<?= (int)$g['contacts'][0]['id'] ?>&b=<?= (int)$g['contacts'][1]['id'] ?>">Porównaj</a>
              <?php else: ?>
              <span class="text-muted" style="font-size:.74rem">
                <?= count($g['contacts']) ?> kartoteki — scalaj parami
              </span>
              <div class="mt-1">
                <a class="btn btn-sm btn-crm-outline"
                   href="?a=<?= (int)$g['contacts'][0]['id'] ?>&b=<?= (int)$g['contacts'][1]['id'] ?>">Zacznij</a>
              </div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/includes/footer_crm.php'; ?>
