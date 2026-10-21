<?php
/**
 * karty30/ti/dydaktyk/_umowa_ewidencja.php — sekcja „Umowa zlecenie i e-ewidencja godzin” w zakładce Formalności.
 *
 * Tylko odczyt. Umowy zlecenie prowadzącego z zaznaczonym „Dydaktyka — udostępnij dane do modułu”
 * (powiązane kontem, e-mailem albo imieniem i nazwiskiem — jak e-ewidencja z protokołów). Dla wybranej
 * umowy: miesiące z liczbą godzin, wpisy wybranego miesiąca i PDF-y z podpisanych protokołów.
 * Parametry: ?tab=formalnosci&um_u=<id umowy>&um_m=RRRR-MM (#umowa).
 * Dawna osobna strona umowa.php przekierowuje tutaj.
 *
 * Wymaga: $uid (index.php). Zmienne lokalne z prefiksem $_um, żeby nie kolidować z index.php.
 */
require_once dirname(__DIR__, 3) . '/includes/ti_protocols.php';
require_once dirname(__DIR__, 3) . '/includes/zlecenie_rachunki.php';   // kolumna dydaktyka_udostepnij
ti_protocol_ew_migrate();

$_um_usr = db_one("SELECT email, name FROM users WHERE id = ?", [(int)$uid]) ?: ['email' => '', 'name' => ''];
$_um_list = [];
try {
    $_um_list = db_all(
        "SELECT * FROM umowy_zlecenie
          WHERE status NOT IN ('anulowana','odrzucona')   -- projekt i w realizacji też (dydaktyka)
            AND COALESCE(dydaktyka_udostepnij, 0) = 1
            AND (ti_instructor_user_id = ?
                 OR (TRIM(COALESCE(?, '')) <> '' AND lower(email) = lower(?))
                 OR (TRIM(COALESCE(?, '')) <> '' AND lower(TRIM(imie_nazwisko)) = lower(TRIM(?))))
          ORDER BY COALESCE(data_rozpoczecia, '') DESC, id DESC",
        [(int)$uid, (string)$_um_usr['email'], (string)$_um_usr['email'], (string)$_um_usr['name'], (string)$_um_usr['name']]
    );
} catch (\Throwable $e) {
    $_um_list = [];
}
// Umowy tej osoby pominięte w e-ewidencji — żeby powiedzieć wprost, dlaczego (status albo brak zaznaczenia).
$_um_pomin = [];
if (!$_um_list) {
    try {
        $_um_pomin = db_all(
            "SELECT id, numer_umowy, nr_rejestru, nr_roboczy, status, COALESCE(dydaktyka_udostepnij, 0) AS dyd FROM umowy_zlecenie
              WHERE ti_instructor_user_id = ?
                 OR (TRIM(COALESCE(?, '')) <> '' AND lower(email) = lower(?))
                 OR (TRIM(COALESCE(?, '')) <> '' AND lower(TRIM(imie_nazwisko)) = lower(TRIM(?)))
              ORDER BY id DESC",
            [(int)$uid, (string)$_um_usr['email'], (string)$_um_usr['email'], (string)$_um_usr['name'], (string)$_um_usr['name']]);
    } catch (\Throwable $e) { $_um_pomin = []; }
}
$_um_nr = fn(array $u) => trim((string)($u['numer_umowy'] ?: ($u['nr_rejestru'] ?? '') ?: ($u['nr_roboczy'] ?? ''))) ?: ('#' . $u['id']);
$_um_d  = fn($v) => $v ? date('d.m.Y', strtotime((string)$v)) : '';
$_um_hf = fn($x) => rtrim(rtrim(number_format((float)$x, 2, ',', ' '), '0'), ',');
$_um_q  = fn(int $u, string $m = '') => 'index.php?tab=formalnosci&amp;um_u=' . $u . ($m !== '' ? '&amp;um_m=' . h($m) : '') . '#umowa';

$_um_sel = null;
foreach ($_um_list as $_u) if ((int)$_u['id'] === (int)($_GET['um_u'] ?? 0)) $_um_sel = $_u;
$_um_sel ??= $_um_list[0] ?? null;

$_um_mies = []; $_um_wpisy = []; $_um_m = ''; $_um_prot = [];
if ($_um_sel) {
    $_um_mies = db_all(
        "SELECT substr(data, 1, 7) AS ym, COUNT(*) AS n, SUM(godziny) AS godz
           FROM zlecenie_ewidencja_wpisy WHERE contract_id = ? GROUP BY substr(data, 1, 7) ORDER BY ym DESC",
        [(int)$_um_sel['id']]
    );
    $_um_m = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['um_m'] ?? '')) ? (string)$_GET['um_m'] : (string)($_um_mies[0]['ym'] ?? '');
    if ($_um_m !== '') {
        $_um_wpisy = db_all("SELECT * FROM zlecenie_ewidencja_wpisy WHERE contract_id = ? AND substr(data, 1, 7) = ? ORDER BY data, id",
            [(int)$_um_sel['id'], $_um_m]);
        $_um_prot = db_all(
            "SELECT p.course_id, p.year_month, p.ew_godziny, p.ew_zgoda_at, COALESCE(NULLIF(c.display_name, ''), c.name) AS course_name
               FROM k30_ti_protocols p JOIN k30_ti_courses c ON c.id = p.course_id
              WHERE p.ew_umowa_id = ? AND p.year_month = ? AND p.ew_zgoda_at IS NOT NULL ORDER BY c.name",
            [(int)$_um_sel['id'], $_um_m]);
    }
}
$_um_zr = fn(string $z) => ['karty30' => 'protokół zajęć TI', 'reczne' => 'wpis ręczny', 'reczny' => 'wpis ręczny', 'import' => 'import'][$z] ?? ($z !== '' ? $z : '—');
?>
<section class="mb-4" id="umowa" aria-labelledby="umH" tabindex="-1">
  <h2 class="h5 fw-bold mb-1" id="umH"><i class="bi bi-journal-text text-primary me-2" aria-hidden="true"></i>Umowa zlecenie i e-ewidencja godzin</h2>
  <p class="text-body-secondary small mb-3">Godziny trafiają do e-ewidencji po podpisaniu protokołu i Twojej zgodzie na przepisanie
    (pełne godziny — każda rozpoczęta liczy się jako pełna).</p>

  <?php if (!$_um_list): ?>
    <?php if ($_um_pomin): ?>
    <div class="card border-0 shadow-sm mb-2"><div class="card-body">
      <p class="mb-2">Twoja umowa zlecenie jest w systemie, ale e-ewidencja jej jeszcze nie obejmuje:</p>
      <ul class="mb-0">
        <?php foreach ($_um_pomin as $_u): $_pow = [];
          if (in_array($_u['status'], ['anulowana', 'odrzucona'], true))
              $_pow[] = 'ma status „' . (function_exists('contractStatusLabel') ? contractStatusLabel($_u['status']) : ucfirst((string)$_u['status'])) . '”';
          if (!(int)$_u['dyd']) $_pow[] = 'nie ma zaznaczonego pola „Dydaktyka — udostępnij dane do modułu”'; ?>
        <li>Umowa <strong><?= h($_um_nr($_u)) ?></strong> <?= h(implode(' i ', $_pow)) ?>.</li>
        <?php endforeach; ?>
      </ul>
    </div></div>
    <?php endif; ?>
    <div class="card border-0 shadow-sm"><div class="card-body text-body-secondary">
      Nie znaleźliśmy Twojej umowy zlecenie udostępnionej dydaktyce. Jeśli powinna być, poproś o zaznaczenie w umowie pola
      <strong>„Dydaktyka — udostępnij dane do modułu”</strong> (i wskazanie Twojego konta jako prowadzącego w e-dzienniku).
      Dokumenty do umowy z <a href="protokoly_moje.php">Protokołów</a> i tak możesz wygenerować — pola umowy mają wtedy „----”.
    </div></div>
  <?php else: ?>

    <?php if (count($_um_list) > 1): ?>
    <nav aria-label="Wybierz umowę" class="mb-2"><ul class="nav nav-pills gap-1">
      <?php foreach ($_um_list as $_u): $_on = (int)$_u['id'] === (int)$_um_sel['id']; ?>
      <li class="nav-item"><a class="nav-link<?= $_on ? ' active' : '' ?>" href="<?= $_um_q((int)$_u['id']) ?>"<?= $_on ? ' aria-current="page"' : '' ?>>Umowa nr <?= h($_um_nr($_u)) ?></a></li>
      <?php endforeach; ?>
    </ul></nav>
    <?php endif; ?>

    <p class="mb-2">Umowa zlecenie nr <strong><?= h($_um_nr($_um_sel)) ?></strong> — <?= h((string)$_um_sel['imie_nazwisko']) ?>
      <span class="text-body-secondary small">(<?= h(trim(($_um_sel['data_rozpoczecia'] ? 'od ' . $_um_d($_um_sel['data_rozpoczecia']) : '')
        . (!empty($_um_sel['bezterminowa']) ? ' — bezterminowo' : ($_um_sel['data_zakonczenia'] ? ' do ' . $_um_d($_um_sel['data_zakonczenia']) : '')))) ?: 'bez dat' ?>)</span></p>

    <div class="row g-3">
      <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
          <h3 class="h6 fw-semibold mb-2">Miesiące</h3>
          <?php if (!$_um_mies): ?>
            <p class="small text-body-secondary mb-0">Brak wpisów w ewidencji tej umowy.</p>
          <?php else: ?>
          <table class="table table-sm mb-0">
            <caption class="visually-hidden">Liczba godzin w ewidencji umowy w poszczególnych miesiącach</caption>
            <thead><tr><th scope="col">Miesiąc</th><th scope="col" class="text-end">Wpisy</th><th scope="col" class="text-end">Godziny</th></tr></thead>
            <tbody>
            <?php $_tot = 0; foreach ($_um_mies as $_mm): $_tot += (float)$_mm['godz']; $_on = $_mm['ym'] === $_um_m; ?>
              <tr<?= $_on ? ' class="table-primary"' : '' ?>><td><a href="<?= $_um_q((int)$_um_sel['id'], $_mm['ym']) ?>"<?= $_on ? ' aria-current="true"' : '' ?>><?= h(ti_pdf_month_label($_mm['ym'])) ?></a></td>
                <td class="text-end"><?= (int)$_mm['n'] ?></td><td class="text-end"><?= h($_um_hf($_mm['godz'])) ?></td></tr>
            <?php endforeach; ?>
              <tr class="fw-bold"><td>Razem</td><td></td><td class="text-end"><?= h($_um_hf($_tot)) ?></td></tr>
            </tbody>
          </table>
          <?php endif; ?>
        </div></div>
      </div>
      <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
          <h3 class="h6 fw-semibold mb-2">E-ewidencja<?= $_um_m !== '' ? ' — ' . h(ti_pdf_month_label($_um_m)) : '' ?></h3>
          <?php if (!$_um_wpisy): ?>
            <p class="small text-body-secondary mb-0">Wybierz miesiąc z listy.</p>
          <?php else: ?>
          <table class="table table-sm table-striped">
            <caption class="visually-hidden">Wpisy ewidencji godzin umowy w miesiącu</caption>
            <thead><tr><th scope="col">Data</th><th scope="col" class="text-end">Godziny</th><th scope="col">Opis</th><th scope="col">Skąd</th></tr></thead>
            <tbody>
            <?php $_sm = 0; foreach ($_um_wpisy as $_w): $_sm += (float)$_w['godziny']; ?>
              <tr><td class="text-nowrap"><?= h($_um_d($_w['data'])) ?></td><td class="text-end"><?= h($_um_hf($_w['godziny'])) ?></td>
                  <td><?= h((string)$_w['opis']) ?></td><td class="small"><?= h($_um_zr((string)$_w['zrodlo'])) ?></td></tr>
            <?php endforeach; ?>
              <tr class="fw-bold"><td>Razem</td><td class="text-end"><?= h($_um_hf($_sm)) ?></td><td colspan="2"></td></tr>
            </tbody>
          </table>
          <?php endif; ?>
          <?php if ($_um_prot): ?>
          <h4 class="h6 fw-semibold mt-3 mb-2">Dokumenty z podpisanych protokołów</h4>
          <ul class="list-unstyled mb-0">
            <?php foreach ($_um_prot as $_p): $_pq = 'course=' . (int)$_p['course_id'] . '&amp;ym=' . h(urlencode($_p['year_month'])); ?>
            <li class="mb-2"><strong><?= h((string)$_p['course_name']) ?></strong> — <?= h($_um_hf($_p['ew_godziny'])) ?> h
              <span class="small text-body-secondary">(zgoda <?= h(date('d.m.Y', strtotime((string)$_p['ew_zgoda_at'] . ' UTC'))) ?>)</span><br>
              <a class="btn btn-sm btn-outline-secondary mt-1" href="protokol_pdf.php?ewidencja=1&amp;<?= $_pq ?>" target="_blank" rel="noopener">
                <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>E-ewidencja (PDF)<span class="visually-hidden"> — <?= h((string)$_p['course_name']) ?>, nowa karta</span></a>
              <a class="btn btn-sm btn-outline-secondary mt-1" href="protokol_pdf.php?obecnosc=1&amp;<?= $_pq ?>" target="_blank" rel="noopener">
                <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Lista obecności (PDF)<span class="visually-hidden"> — <?= h((string)$_p['course_name']) ?>, nowa karta</span></a></li>
            <?php endforeach; ?>
          </ul>
          <?php endif; ?>
        </div></div>
      </div>
    </div>
  <?php endif; ?>
</section>
