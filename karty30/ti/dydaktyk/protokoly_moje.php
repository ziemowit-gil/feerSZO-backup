<?php
/**
 * karty30/ti/dydaktyk/protokoly_moje.php — Protokoły miesięczne (widok prowadzącego).
 *
 * Prosty kreator: lista miesięcy do zamknięcia (per kurs) → podsumowanie
 * (lekcje, frekwencja) → "Zatwierdź protokół". Osobny tor od protokoly.php
 * (widok kierownika, protokoły per-okres) — patrz includes/ti_protocols.php.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_protocols.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_protocol_numbers/logic/protocolNumbers.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/x509_login.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/webauthn.php';
require_once dirname(dirname(dirname(__DIR__))) . '/inc/tiCertStore/tiCertStore.php';
webauthn_migrate();

$me      = dyd_require();
$uid     = (int)$me['user_id'];
$me_name = (string)($me['name'] ?? '');
karty30_migrate();
ti_protocols_migrate();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = $_POST['_op'] ?? '';

    // Zgoda prowadzącego na przepisanie godzin z podpisanego protokołu do ewidencji umowy zlecenie
    // (= podpisane potwierdzenie realizacji umowy za ten miesiąc).
    if ($op === 'ewidencja_zgoda') {
        $cid = (int)($_POST['course_id'] ?? 0);
        $ym  = (string)($_POST['year_month'] ?? '');
        $wroc = 'protokoly_moje.php?podpisano=1&course=' . $cid . '&ym=' . urlencode($ym);
        try {
            if (!$cid || !preg_match('/^\d{4}-\d{2}$/', $ym) || !dyd_owns_course($uid, $cid)) throw new \RuntimeException('Nieprawidłowe dane protokołu.');
            $pr = db_one("SELECT p.*, c.name AS course_name FROM k30_ti_protocols p JOIN k30_ti_courses c ON c.id = p.course_id
                           WHERE p.course_id = ? AND p.year_month = ? AND p.status = 'approved'", [$cid, $ym]);
            if (!$pr) throw new \RuntimeException('Zgodę można wyrazić dopiero po podpisaniu protokołu.');
            $umowa = null;
            foreach (ti_protocol_zlecenie_umowy($pr) as $_u) if ((int)$_u['id'] === (int)($_POST['umowa_id'] ?? 0)) $umowa = $_u;
            if (!$umowa) throw new \RuntimeException('Wybierz umowę zlecenie, do której przepisać godziny.');
            $r = ti_protocol_ewidencja_zgoda($pr, $umowa, $uid, $me_name);
            if (!empty($r['pominieto'])) {
                flash_set('info', 'Za ' . ti_pdf_month_label($ym) . ' godzin nie przepisujemy do umowy — przepisywanie działa od października 2026, wcześniejsze miesiące są rozliczone w rachunkach jak dotąd.');
                header('Location: ' . $wroc); exit;
            }
            flash_set('success', 'Godziny przepisane do umowy (' . number_format($r['godziny'], 2, ',', ' ') . ' h). Kierownik dostał informację o nowej e-ewidencji.');
        } catch (\Throwable $e) {
            flash_set('danger', $e->getMessage());
        }
        header('Location: ' . $wroc); exit;
    }

    // Kierownik wycofuje zatwierdzony protokół (z podaniem powodu — trafia do śladu zmian).
    if ($op === 'withdraw') {
        $pid = (int)($_POST['protocol_id'] ?? 0);
        $pr  = $pid ? ti_protocol_get($pid) : null;
        try {
            if (!dyd_is_staff()) throw new \RuntimeException('Wycofać protokół może tylko kierownik.');
            if (!$pr || !dyd_owns_course($uid, (int)$pr['course_id'])) throw new \RuntimeException('Nie ma takiego protokołu.');
            ti_protocol_unlock($pid, $uid, $me_name, (string)($_POST['reason'] ?? ''));
            flash_set('success', 'Protokół wycofany — prowadzący może go poprawić i zatwierdzić ponownie. Powód zapisano w śladzie zmian.');
        } catch (\Throwable $e) {
            flash_set('danger', $e->getMessage());
        }
        header('Location: protokoly_moje.php'); exit;
    }

    // Kod SMS do podpisu protokołu — wysyłany na telefon zalogowanego prowadzącego.
    if ($op === 'send_otp') {
        $err = ti_protocol_otp_send($uid);
        flash_set($err === '' ? 'success' : 'danger', $err === '' ? 'Wysłaliśmy kod SMS (ważny 5 minut). Wpisz go przy zatwierdzaniu.' : $err);
        header('Location: protokoly_moje.php?course=' . (int)($_POST['course_id'] ?? 0) . '&ym=' . urlencode((string)($_POST['year_month'] ?? ''))); exit;
    }

    if ($op === 'approve_month') {
        $cid = (int)($_POST['course_id'] ?? 0);
        $ym  = (string)($_POST['year_month'] ?? '');
        if (!$cid || !preg_match('/^\d{4}-\d{2}$/', $ym) || $ym > date('Y-m') || !dyd_owns_course($uid, $cid)) {
            flash_set('danger', 'Nieprawidłowe dane protokołu.');
            header('Location: protokoly_moje.php'); exit;
        }
        // Wprost na bazie. Wcześniejsze samo-wywołanie api_protocols.php przez HTTP
        // zawsze kończyło się 403 (brak tokenu CSRF) i blokowało się na zamku pliku
        // sesji tego samego żądania — dawało tylko 3 s opóźnienia przed fallbackiem.
        try {
            if (!ti_protocol_can_approve($uid, $cid, dyd_is_staff())) throw new \RuntimeException(TI_PROTOCOL_APPROVE_DENIED);
            $prot = ti_protocol_get_or_create_for_month($cid, $ym);
            // Zatwierdzenie wymaga podpisu certyfikatem prowadzącego (.p12 + hasło) — weryfikowanego na serwerze.
            // Certyfikat zapisany w systemie („Odczytaj certyfikat”) albo — dla starszych certyfikatów,
            // które przyszły mailem — plik wgrany z dysku.
            $p12 = '';
            if (!empty($_POST['cert_z_serwera'])) {
                $zapisany = tiCertStoreActive($uid, true);
                if (!$zapisany) {
                    // Certyfikat jest na liście (formularz go pokazał), ale pliku nie da się odczytać z magazynu —
                    // wcześniej wyglądało to jak „brak certyfikatu”. Najczęściej: zmienił się klucz szyfrujący.
                    $_jest = tiCertStoreActive($uid);
                    error_log('[protokoly_moje] cert_z_serwera: ' . ($_jest ? 'wpis jest, odszyfrowanie nieudane' : 'brak wpisu w magazynie') . ' (user ' . $uid . ')');
                    throw new \RuntimeException($_jest
                        ? 'Certyfikat jest zapisany w systemie, ale nie udało się go odczytać z magazynu. Wygeneruj certyfikat ponownie w „Mój certyfikat” albo zgłoś to administratorowi.'
                        : 'Nie znaleziono aktywnego certyfikatu zapisanego w systemie. Wygeneruj go w „Mój certyfikat”.');
                }
                $p12 = (string)$zapisany['p12'];
            } elseif (!empty($_FILES['p12']['tmp_name']) && is_uploaded_file($_FILES['p12']['tmp_name']) && (int)$_FILES['p12']['size'] <= 1048576) {
                $p12 = (string)file_get_contents($_FILES['p12']['tmp_name']);
            }
            if ($p12 === '') throw new \RuntimeException('Zatwierdzenie wymaga podpisu certyfikatem: kliknij „Odczytaj certyfikat” albo dołącz plik .p12. Brak certyfikatu? Wygeneruj go w „Mój certyfikat”.');
            ti_protocol_approve_signed((int)$prot['id'], $uid, $me_name, $p12, (string)($_POST['p12_password'] ?? ''), (string)($_POST['otp'] ?? ''));
            // Sukces: potwierdzenie na całą stronę (widok ?podpisano=1).
            header('Location: protokoly_moje.php?podpisano=1&course=' . $cid . '&ym=' . urlencode($ym)); exit;
        } catch (\Throwable $e) {
            flash_set('danger', $e->getMessage());
        }
        header('Location: protokoly_moje.php'); exit;
    }
}

$pending = ti_protocol_pending_months_for_instructor($uid);
$closed  = ti_protocol_closed_months_for_instructor($uid);
$months  = ti_protocol_months_for_instructor($uid);

// Krok 2 kreatora: konkretny kurs+miesiąc wybrany z listy — pokaż podsumowanie.
$open_cid = (int)($_GET['course'] ?? 0);
$open_ym  = (string)($_GET['ym'] ?? '');
$open_row = null;
if ($open_cid && preg_match('/^\d{4}-\d{2}$/', $open_ym)) {
    foreach ($pending as $p) {
        if ($p['course_id'] === $open_cid && $p['year_month'] === $open_ym) { $open_row = $p; break; }
    }
    // Z listy miesięcy: bieżący miesiąc można zamknąć wcześniej (np. 25., gdy
    // nie ma już zajęć), nawet zanim trafi do „do zamknięcia”.
    if (!$open_row) {
        foreach ($months as $mr) {
            if ($mr['course_id'] === $open_cid && $mr['year_month'] === $open_ym && $mr['state'] !== 'approved') {
                $open_row = $mr + ['is_overdue' => $mr['state'] === 'overdue', 'is_current' => $mr['state'] === 'current'];
                break;
            }
        }
    }
}
$open_summary = $open_row ? ti_protocol_month_summary($open_cid, $open_ym) : null;

$_mon_names = [1=>'styczeń',2=>'luty',3=>'marzec',4=>'kwiecień',5=>'maj',6=>'czerwiec',
               7=>'lipiec',8=>'sierpień',9=>'wrzesień',10=>'październik',11=>'listopad',12=>'grudzień'];
/** Przycisk „Wycofaj” (tylko kierownik): rozwijany formularz z obowiązkowym powodem. */
$wycofaj_html = function (int $pid, string $opis): string {
    if (!dyd_is_staff()) return '';
    $id = 'wyc' . $pid . '_' . substr(md5($opis), 0, 4);
    return '<details class="d-inline-block text-start ms-1"><summary class="btn btn-sm btn-outline-danger py-0">'
         . '<i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Wycofaj<span class="visually-hidden"> protokół: ' . h($opis) . '</span></summary>'
         . '<form method="post" class="card card-body p-2 mt-1 small" style="min-width:16rem">'
         . '<input type="hidden" name="_token" value="' . h(dyd_token()) . '">'
         . '<input type="hidden" name="_op" value="withdraw"><input type="hidden" name="protocol_id" value="' . $pid . '">'
         . '<label class="form-label mb-1 fw-semibold" for="' . $id . '">Powód wycofania</label>'
         . '<textarea class="form-control form-control-sm mb-2" id="' . $id . '" name="reason" rows="2" required '
         . 'placeholder="np. błędna liczba godzin 12.10"></textarea>'
         . '<button type="submit" class="btn btn-sm btn-danger">Wycofaj protokół</button>'
         . '<span class="text-body-secondary mt-1">Prowadzący będzie mógł go poprawić i zatwierdzić ponownie.</span></form></details>';
};

$fmt_ym = function (string $ym) use ($_mon_names): string {
    [$y, $m] = explode('-', $ym);
    return ($_mon_names[(int)$m] ?? $ym) . ' ' . $y;
};

$KP_TITLE  = 'Protokoły — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $me_name, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<?php
// ── Po poprawnym podpisaniu: potwierdzenie na całą stronę ────────────────────
$_pd_cid = (int)($_GET['course'] ?? 0);
$_pd_ym  = (string)($_GET['ym'] ?? '');
$_pd = (!empty($_GET['podpisano']) && $_pd_cid && preg_match('/^\d{4}-\d{2}$/', $_pd_ym) && dyd_owns_course($uid, $_pd_cid))
    ? db_one("SELECT p.*, c.name AS course_name FROM k30_ti_protocols p JOIN k30_ti_courses c ON c.id = p.course_id
               WHERE p.course_id = ? AND p.year_month = ? AND p.status = 'approved'", [$_pd_cid, $_pd_ym])
    : null;
if ($_pd):
    $_pd_zero  = ti_protocol_is_zero(ti_protocol_get((int)$_pd['id']) ?: $_pd);
    $_pd_klucz = str_starts_with((string)($_pd['sign_fingerprint'] ?? ''), 'yubikey:');
    // Sprawa w EZD, do której trafił zatwierdzony protokół (koszulka JRWA 384) — gdy EZD jest włączone.
    $_pd_ezd = null;
    try {
        require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_protokoly_ezd/logic/ti_protokoly_ezd.php';
        require_once dirname(dirname(dirname(__DIR__))) . '/includes/ezd.php';
        if (function_exists('ezd_sprawy_by_ref')) $_pd_ezd = ezd_sprawy_by_ref(TI_PROT_EZD_REF_TYPE, (int)$_pd['id'])[0] ?? null;
    } catch (\Throwable $e) { $_pd_ezd = null; }
?>
<main id="main" class="dyd-wrap">
<div class="container-fluid py-5" style="max-width:760px">
  <section class="card border-0 shadow-sm text-center" aria-labelledby="pdTytul">
    <div class="card-body p-4 p-md-5">
      <div class="mx-auto mb-3 d-flex align-items-center justify-content-center"
           style="width:96px;height:96px;background:#15803d;color:#fff;font-size:3.2rem" aria-hidden="true">
        <i class="bi bi-check-lg"></i>
      </div>
      <h1 class="h3 fw-bold mb-2" id="pdTytul" tabindex="-1"><?= $_pd_zero ? 'Informacja podpisana' : 'Protokół podpisany' ?></h1>
      <p class="fs-5 mb-4">
        <?= $_pd_zero ? 'Informacja o braku zajęć (0 godzin)' : 'Protokół' ?> za <strong><?= h($fmt_ym($_pd_ym)) ?></strong>
        — <strong><?= h((string)$_pd['course_name']) ?></strong> — został podpisany i zamknięty.
      </p>
      <dl class="row text-start small mx-auto mb-4" style="max-width:32rem">
        <dt class="col-sm-5 fw-normal text-body-secondary">Podpisał(a)</dt><dd class="col-sm-7 fw-semibold"><?= h((string)($_pd['approved_name'] ?: $me_name)) ?></dd>
        <dt class="col-sm-5 fw-normal text-body-secondary">Data podpisu</dt><dd class="col-sm-7"><?= h(date('d.m.Y H:i', strtotime((string)($_pd['sign_at'] ?: $_pd['approved_at']) . ' UTC'))) /* datetime('now') w SQLite = UTC */ ?></dd>
        <dt class="col-sm-5 fw-normal text-body-secondary">Sposób podpisu</dt><dd class="col-sm-7"><?= $_pd_klucz ? 'Klucz bezpieczeństwa (passkey / YubiKey)' : 'Certyfikat i kod SMS' ?></dd>
        <dt class="col-sm-5 fw-normal text-body-secondary">Sprawa w EZD</dt>
        <dd class="col-sm-7"><?php if ($_pd_ezd): ?><strong class="fs-6"><?= h((string)($_pd_ezd['znak_sprawy'] ?: ('#' . $_pd_ezd['id']))) ?></strong><?php else: ?><span class="text-body-secondary">nie zarejestrowano (EZD wyłączone lub błąd rejestracji)</span><?php endif; ?></dd>
        <?php if (!$_pd_klucz && trim((string)($_pd['sign_subject'] ?? '')) !== ''): ?>
        <dt class="col-sm-5 fw-normal text-body-secondary">Certyfikat</dt><dd class="col-sm-7"><?= h((string)$_pd['sign_subject']) ?></dd>
        <?php endif; ?>
        <?php if (!$_pd_klucz && trim((string)($_pd['sign_fingerprint'] ?? '')) !== ''): ?>
        <dt class="col-sm-5 fw-normal text-body-secondary">Odcisk certyfikatu</dt><dd class="col-sm-7"><code><?= h(strtoupper(implode(':', str_split(substr((string)$_pd['sign_fingerprint'], 0, 16), 2)))) ?>…</code></dd>
        <?php endif; ?>
      </dl>
      <div class="d-flex flex-wrap gap-2 justify-content-center">
        <a class="btn btn-success btn-lg" href="protokol_pdf.php?id=<?= (int)$_pd['id'] ?>">
          <i class="bi bi-file-earmark-arrow-down me-1" aria-hidden="true"></i>Pobierz podpisany dokument (PDF)
        </a>
        <a class="btn btn-outline-success btn-lg" href="protokol_pdf.php?dziennik=1&amp;course=<?= (int)$_pd['course_id'] ?>&amp;ym=<?= h(urlencode($_pd_ym)) ?>">
          <i class="bi bi-journal-bookmark me-1" aria-hidden="true"></i>Dziennik zajęć za miesiąc (PDF)
        </a>
        <a class="btn btn-outline-secondary btn-lg" href="protokoly_moje.php">
          <i class="bi bi-list-check me-1" aria-hidden="true"></i>Wróć do listy protokołów
        </a>
      </div>
    </div>
  </section>

  <?php
    // ── E-ewidencja godzin do umowy zlecenie: podgląd PDF i zgoda na przepisanie godzin ──
    ti_protocol_ew_migrate();
    $_ew_prot  = (ti_protocol_get((int)$_pd['id']) ?: $_pd) + ['course_name' => $_pd['course_name']];
    $_ew_row   = db_one("SELECT ew_umowa_id, ew_zgoda_at, ew_zgoda_name, ew_godziny FROM k30_ti_protocols WHERE id = ?", [(int)$_pd['id']]) ?: [];
    $_ew_umowy = ti_protocol_zlecenie_umowy($_ew_prot);
    $_ew_wyb   = null;
    if (!empty($_ew_row['ew_umowa_id'])) { foreach ($_ew_umowy as $_u) if ((int)$_u['id'] === (int)$_ew_row['ew_umowa_id']) $_ew_wyb = $_u; }
    elseif (count($_ew_umowy) === 1) $_ew_wyb = $_ew_umowy[0];
    elseif (!empty($_GET['umowa'])) { foreach ($_ew_umowy as $_u) if ((int)$_u['id'] === (int)$_GET['umowa']) $_ew_wyb = $_u; }
    $_ew_nr = fn(array $u) => trim((string)($u['numer_umowy'] ?: ($u['nr_rejestru'] ?? '') ?: ('#' . $u['id'])));
    $_ew_q  = 'course=' . (int)$_pd['course_id'] . '&ym=' . urlencode($_pd_ym);
  ?>
  <?= flash_html() ?>
  <section class="card border-0 shadow-sm mt-4" aria-labelledby="ewH">
    <div class="card-body p-4">
      <h2 class="h5 fw-bold mb-1" id="ewH"><i class="bi bi-journal-text me-2" aria-hidden="true"></i>E-ewidencja godzin do umowy zlecenie</h2>
      <?php if (!$_ew_umowy && empty($_ew_row['ew_umowa_id'])): ?>
        <p class="text-body-secondary">Nie znaleźliśmy w systemie Twojej umowy zlecenie na ten miesiąc — dokumenty możesz wygenerować,
          pola umowy mają „----”. Jeśli umowa powinna być, poproś o zaznaczenie w niej pola „Dydaktyka — udostępnij dane do modułu”.</p>
        <div class="d-flex flex-wrap gap-2">
          <a class="btn btn-outline-secondary" href="protokol_pdf.php?ewidencja=1&amp;<?= h($_ew_q) ?>" target="_blank" rel="noopener">
            <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Lista godzin do umowy (PDF)<span class="visually-hidden"> — otwiera się w nowej karcie</span></a>
          <a class="btn btn-outline-secondary" href="protokol_pdf.php?obecnosc=1&amp;<?= h($_ew_q) ?>" target="_blank" rel="noopener">
            <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Lista obecności do umowy (PDF)<span class="visually-hidden"> — otwiera się w nowej karcie</span></a>
        </div>
      <?php elseif (!empty($_ew_row['ew_zgoda_at'])): ?>
        <div class="alert alert-success mb-3" role="status">
          <strong>Godziny przepisane do umowy<?= $_ew_wyb ? ' nr ' . h($_ew_nr($_ew_wyb)) : '' ?>.</strong>
          <?= h(date('d.m.Y H:i', strtotime((string)$_ew_row['ew_zgoda_at'] . ' UTC'))) ?> — <?= h((string)$_ew_row['ew_zgoda_name']) ?>,
          <?= h(number_format((float)$_ew_row['ew_godziny'], 2, ',', ' ')) ?> h. Zgoda jest podpisanym potwierdzeniem realizacji umowy za ten miesiąc.
        </div>
        <a class="btn btn-outline-secondary" href="protokol_pdf.php?ewidencja=1&amp;<?= h($_ew_q) ?>" target="_blank" rel="noopener">
          <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Pobierz listę godzin do umowy — e-ewidencję (PDF)<span class="visually-hidden"> — otwiera się w nowej karcie</span></a>
        <a class="btn btn-outline-secondary" href="protokol_pdf.php?obecnosc=1&amp;<?= h($_ew_q) ?>" target="_blank" rel="noopener">
          <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Pobierz listę obecności do umowy (PDF)<span class="visually-hidden"> — otwiera się w nowej karcie</span></a>
      <?php elseif (!ti_protocol_ew_przepisanie_aktywne($_pd_ym)): ?>
        <p class="text-body-secondary">Za <?= h($fmt_ym($_pd_ym)) ?> godzin nie przepisujemy do ewidencji umowy — przepisywanie działa
          <strong>od października 2026</strong>, wcześniejsze miesiące są rozliczone w rachunkach jak dotąd. Dokumenty możesz pobrać:</p>
        <div class="d-flex flex-wrap gap-2">
          <a class="btn btn-outline-secondary" href="protokol_pdf.php?ewidencja=1&amp;<?= h($_ew_q) ?><?= $_ew_wyb ? '&amp;umowa=' . (int)$_ew_wyb['id'] : '' ?>" target="_blank" rel="noopener">
            <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Lista godzin do umowy (PDF)<span class="visually-hidden"> — otwiera się w nowej karcie</span></a>
          <a class="btn btn-outline-secondary" href="protokol_pdf.php?obecnosc=1&amp;<?= h($_ew_q) ?><?= $_ew_wyb ? '&amp;umowa=' . (int)$_ew_wyb['id'] : '' ?>" target="_blank" rel="noopener">
            <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Lista obecności do umowy (PDF)<span class="visually-hidden"> — otwiera się w nowej karcie</span></a>
        </div>
      <?php else: ?>
        <?php if (count($_ew_umowy) > 1): ?>
        <form method="get" class="mb-3">
          <input type="hidden" name="podpisano" value="1"><input type="hidden" name="course" value="<?= (int)$_pd['course_id'] ?>">
          <input type="hidden" name="ym" value="<?= h($_pd_ym) ?>">
          <fieldset>
            <legend class="h6 fw-semibold">Masz kilka umów zlecenie na ten miesiąc. Do której przepisać godziny?</legend>
            <?php foreach ($_ew_umowy as $_u): ?>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="umowa" id="ewU<?= (int)$_u['id'] ?>" value="<?= (int)$_u['id'] ?>"
                     <?= $_ew_wyb && (int)$_ew_wyb['id'] === (int)$_u['id'] ? 'checked' : '' ?> required>
              <label class="form-check-label" for="ewU<?= (int)$_u['id'] ?>">
                Umowa nr <strong><?= h($_ew_nr($_u)) ?></strong> — <?= h((string)$_u['imie_nazwisko']) ?>
                <?php if (!empty($_u['przedmiot_zlecenia'])): ?><span class="text-body-secondary small">(<?= h(mb_strimwidth((string)$_u['przedmiot_zlecenia'], 0, 80, '…')) ?>)</span><?php endif; ?>
              </label>
            </div>
            <?php endforeach; ?>
          </fieldset>
          <button type="submit" class="btn btn-outline-primary btn-sm mt-2">Pokaż e-ewidencję dla tej umowy</button>
        </form>
        <?php endif; ?>

        <?php if ($_ew_wyb): ?>
        <p class="small text-body-secondary mb-2">Umowa nr <strong><?= h($_ew_nr($_ew_wyb)) ?></strong> — <?= h((string)$_ew_wyb['imie_nazwisko']) ?>.
          Sprawdź e-ewidencję poniżej.</p>
        <iframe title="Podgląd e-ewidencji godzin do umowy nr <?= h($_ew_nr($_ew_wyb)) ?>" class="border w-100 mb-3" style="min-height:min(70vh,44rem)"
                src="protokol_pdf.php?ewidencja=1&amp;<?= h($_ew_q) ?>&amp;umowa=<?= (int)$_ew_wyb['id'] ?>"></iframe>
        <form method="post">
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op" value="ewidencja_zgoda">
          <input type="hidden" name="course_id" value="<?= (int)$_pd['course_id'] ?>">
          <input type="hidden" name="year_month" value="<?= h($_pd_ym) ?>">
          <input type="hidden" name="umowa_id" value="<?= (int)$_ew_wyb['id'] ?>">
          <div class="alert alert-info small" id="ewInfo">
            <strong>To jest podpisane potwierdzenie realizacji umowy.</strong> Klikając, potwierdzasz wykonanie zlecenia
            z umowy nr <?= h($_ew_nr($_ew_wyb)) ?> w tej grupie w miesiącu <?= h($fmt_ym($_pd_ym)) ?> — w liczbie godzin z e-ewidencji powyżej.
            Godziny zostaną przepisane do ewidencji umowy, a kierownik dostanie informację o nowej e-ewidencji.
          </div>
          <button type="submit" class="btn btn-success btn-lg" aria-describedby="ewInfo">
            <i class="bi bi-check2-square me-1" aria-hidden="true"></i>Wyrażam zgodę na przepisanie godzin do umowy
          </button>
        </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </section>
</div>
</main>
<script>document.getElementById('pdTytul').focus();</script>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; exit; endif; ?>

<main id="main" class="dyd-wrap">
<?php /* Zatwierdzanie (kroki + podgląd dokumentów) potrzebuje szerokiej strony; lista protokołów — wąskiej. */ ?>
<div class="container-fluid py-3" style="max-width:<?= $open_row ? '1500px' : '760px' ?>">
<?= flash_html() ?>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h1 class="h4 mb-0 fw-bold"><i class="bi bi-journal-check text-primary me-2" aria-hidden="true"></i>Protokoły</h1>
  <?php if ($pending): ?><span class="badge bg-warning text-dark"><?= count($pending) ?></span><?php endif; ?>
  <a href="index.php?tab=pulpit" class="btn btn-sm btn-outline-secondary ms-auto">
    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Pulpit
  </a>
</div>

<div class="alert alert-info small py-2 mb-3" role="note">
  <div class="fw-semibold mb-1"><i class="bi bi-info-circle" aria-hidden="true"></i> Co się zmieniło przy zatwierdzaniu protokołu</div>
  <ul class="mb-2 ps-3">
    <li><strong>Podpis certyfikatem.</strong> Zatwierdzasz swoim certyfikatem i jego hasłem. Hasła nie zapisujemy w systemie.</li>
    <li><strong>Kod SMS.</strong> Przed podpisem kliknij „Wyślij kod SMS” i wpisz kod (ważny 5 minut, jednorazowy).</li>
    <li><strong>Certyfikat od 11.10.2026.</strong> Wygenerujesz go sam w <a href="moj_certyfikat.php">Mój certyfikat</a>: hasło ustawiasz sam w kreatorze. Certyfikat zostaje zapisany w systemie — przy podpisie klikasz „Odczytaj certyfikat”. Mailem dostaniesz tylko informację o wygenerowaniu.</li>
    <li><strong>Klucz bezpieczeństwa.</strong> Zamiast certyfikatu możesz zatwierdzić passkeyem lub YubiKeyem — klucz dodasz w <a href="moj_certyfikat.php">Mój certyfikat</a>.</li>
    <li><strong>Hasło własne:</strong> min. 16 znaków, co najmniej 3 rodzaje znaków (małe, wielkie litery, cyfry, znaki specjalne).</li>
  </ul>
  <p class="mb-0">Brak telefonu w koncie? Poproś kierownika TI o jego uzupełnienie.</p>
</div>

<?php if ($open_row): ?>
<!-- ═══ KROK 2: podsumowanie i potwierdzenie ═══════════════════════════ -->
<div class="card border-0 shadow-sm">
  <div class="card-header fw-semibold">
    <i class="bi bi-journal-text me-2 text-primary" aria-hidden="true"></i>
    <?= h($open_row['course_name']) ?> — <?= h($fmt_ym($open_ym)) ?>
  </div>
  <div class="card-body">
    <?php if ($open_row['is_overdue']): ?>
    <div class="alert alert-warning py-2 small mb-3">
      <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Ten miesiąc już się skończył — zamknij protokół najszybciej jak możesz.
    </div>
    <?php else: ?>
    <div class="alert alert-info py-2 small mb-3">
      <i class="bi bi-info-circle me-1" aria-hidden="true"></i>Bieżący miesiąc — zwykle zamyka się go po zakończeniu, ale możesz też teraz.
    </div>
    <?php endif; ?>

    <?php if ((int)($open_summary['planned_left'] ?? 0) > 0): ?>
    <div class="alert alert-warning py-2 small mb-3" role="alert">
      <i class="bi bi-calendar-x me-1" aria-hidden="true"></i>
      W tym miesiącu są jeszcze <strong><?= (int)$open_summary['planned_left'] ?></strong> zaplanowane lekcje od dziś.
      Po zamknięciu protokołu nie wejdą do niego — zamknij miesiąc tylko, jeśli tych zajęć już nie będzie
      (np. odwołaj je wcześniej).
    </div>
    <?php endif; ?>

    <dl class="row mb-3">
      <dt class="col-sm-6">Lekcje odbyte</dt>
      <dd class="col-sm-6"><?= (int)$open_summary['lessons_held'] ?> z <?= (int)$open_summary['lessons_total'] ?> zaplanowanych</dd>
      <dt class="col-sm-6">Średnia frekwencja</dt>
      <dd class="col-sm-6"><?= $open_summary['attendance_pct'] !== null ? (int)$open_summary['attendance_pct'] . '%' : '—' ?></dd>
    </dl>

    <p class="text-body-secondary small">
      Zatwierdzenie zamyka protokół za ten miesiąc — nie da się go już cofnąć samodzielnie
      (odblokować może kierownik, z podaniem powodu).
    </p>

    <a href="protokoly_moje.php" class="btn btn-outline-secondary btn-sm mb-3">
      <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Wstecz do listy protokołów
    </a>
<?php if (empty($open_row['can_approve']) && !dyd_is_staff()): ?>
    <p class="small text-body-secondary mb-0">
      <i class="bi bi-lock me-1" aria-hidden="true"></i>Protokół zatwierdza główny prowadzący kursu albo kierownik.
    </p>
<?php else: ?>
    <?php
      $_has_cert = x509_user_has_active_cert($uid);
      $_has_key  = (bool)webauthn_get_credentials($uid);
      // Bez certyfikatu, ale z kluczem (passkey/YubiKey) — od razu proponujemy klucz;
      // SMS i podpis .p12 są wtedy niepotrzebne.
      // „Nie mogę skorzystać z passkey” (?metoda=zwykla) — wracamy do zwykłej metody.
      $_key_first = !$_has_cert && $_has_key && ($_GET['metoda'] ?? '') !== 'zwykla';
      $_cid = (int)$open_row['course_id'];
      $_zero = ti_protocol_is_zero(ti_protocol_month_stub($_cid, $open_ym));   // 0 godzin → sama informacja zamiast protokołu
      // Certyfikat zapisany w systemie (od 11.10.2026) — przy podpisie „Odczytaj certyfikat”.
      $_cert_srv = $_has_cert ? tiCertStoreActive($uid) : null;
      $_odczytany = $_cert_srv && !empty($_GET['odczytaj']);
    ?>
    <?php /* Formularz zatwierdzenia jest pusty: pola kroków i przycisk w okienku godzin
             należą do niego przez atrybut form="pmApproveForm" — dzięki temu kroki mogą
             być osobnymi kartami, a wysyłka kodu SMS osobnym formularzem. */ ?>
    <form method="post" id="pmApproveForm" enctype="multipart/form-data">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op" value="approve_month">
      <input type="hidden" name="course_id" value="<?= $_cid ?>">
      <input type="hidden" name="year_month" value="<?= h($open_ym) ?>">
    </form>

    <style>
      .pm-nr{flex:0 0 2.25rem;height:2.25rem;display:flex;align-items:center;justify-content:center;
        background:#0f6fa8;color:#fff;font-weight:700;font-size:1.05rem}
    </style>
    <?php if ($_zero): ?>
    <div class="alert alert-warning d-flex gap-2 mb-3" role="status">
      <i class="bi bi-hourglass fs-5 flex-shrink-0" aria-hidden="true"></i>
      <div><strong>W tym miesiącu 0 godzin.</strong> Protokół zajęć nie jest generowany — drukujesz i podpisujesz tylko
        informację, że zajęć nie było. Podpisujesz ją tak samo jak protokół (certyfikatem albo kluczem).</div>
    </div>
    <?php endif; ?>
    <div class="row g-4">
    <div class="col-xl-5 col-lg-6 pm-kroki">
    <?php if ($_key_first): ?>
      <section class="card border-success mb-3" aria-labelledby="pmKluczH">
        <div class="card-body">
          <h3 class="h6 fw-semibold mb-1" id="pmKluczH"><i class="bi bi-usb-drive text-success me-1" aria-hidden="true"></i>Zatwierdź kluczem bezpieczeństwa</h3>
          <p class="small mb-3" id="pmYkOpis">Masz zarejestrowany klucz (passkey lub YubiKey) — wystarczy go użyć. Nie potrzebujesz certyfikatu ani kodu SMS.
            Klucz potwierdza, że to Ty zatwierdzasz protokół i że treść ewidencji godzin się nie zmieniła.</p>
          <div class="d-flex flex-wrap gap-2 align-items-center">
            <button type="button" class="btn btn-success" id="pmYkBtn" data-course="<?= $_cid ?>" data-ym="<?= h($open_ym) ?>"
                    aria-describedby="pmYkOpis pmYkStan">
              <i class="bi bi-usb-drive me-1" aria-hidden="true"></i>Zatwierdź kluczem bezpieczeństwa
            </button>
            <a class="btn btn-outline-secondary" href="protokoly_moje.php?course=<?= $_cid ?>&amp;ym=<?= h(urlencode($open_ym)) ?>&amp;metoda=zwykla">
              <i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>Nie mogę skorzystać z passkey
            </a>
          </div>
          <div class="visually-hidden" role="status" aria-live="polite" id="pmYkStan"></div>
          <div class="alert alert-danger small py-2 mt-2 mb-0 d-none" role="alert" id="pmYkBlad"></div>
        </div>
      </section>

    <?php elseif ($_has_cert): ?>
      <h3 class="h6 fw-semibold mb-1">Jak zatwierdzić protokół certyfikatem</h3>
      <p class="small text-body-secondary mb-2">Zrób po kolei trzy kroki. Przygotuj telefon i plik certyfikatu z maila.</p>
      <ol class="list-unstyled mb-0">
        <li class="card mb-2"><div class="card-body d-flex gap-3">
          <span class="pm-nr" aria-hidden="true">1</span>
          <div class="flex-grow-1">
            <h4 class="h6 fw-semibold mb-1"><span class="visually-hidden">Krok 1: </span>Poproś o kod SMS</h4>
            <p class="small text-body-secondary mb-2">Kliknij przycisk. Na telefon zapisany w Twoim koncie przyjdzie kod z 6 cyfr.
              Masz 5 minut, żeby go wpisać w kroku 2. Kod działa tylko raz.</p>
            <form method="post">
              <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
              <input type="hidden" name="_op" value="send_otp">
              <input type="hidden" name="course_id" value="<?= $_cid ?>">
              <input type="hidden" name="year_month" value="<?= h($open_ym) ?>">
              <button type="submit" class="btn btn-outline-primary"><i class="bi bi-chat-dots me-1" aria-hidden="true"></i>Wyślij kod SMS</button>
            </form>
          </div>
        </div></li>
        <li class="card mb-2"><div class="card-body d-flex gap-3">
          <span class="pm-nr" aria-hidden="true">2</span>
          <div class="flex-grow-1">
            <?php if ($_cert_srv): ?>
            <h4 class="h6 fw-semibold mb-1" id="pmKrok2"><span class="visually-hidden">Krok 2: </span>Odczytaj certyfikat i wpisz hasło oraz kod</h4>
            <p class="small text-body-secondary mb-2">Twój certyfikat jest zapisany w systemie. Kliknij „Odczytaj certyfikat”,
              sprawdź, czy to Twój, potem wpisz hasło do niego i kod z SMS-a.</p>
            <a class="btn btn-outline-primary mb-2<?= $_odczytany ? ' d-none' : '' ?>" id="pmOdczytaj"
               href="protokoly_moje.php?course=<?= $_cid ?>&amp;ym=<?= h(urlencode($open_ym)) ?>&amp;odczytaj=1#pmKrok2"
               aria-controls="pmCertInfo">
              <i class="bi bi-file-earmark-lock me-1" aria-hidden="true"></i>Odczytaj certyfikat
            </a>
            <div class="alert alert-success small py-2 mb-2" id="pmCertInfo" <?= $_odczytany ? '' : 'hidden' ?>>
              <div class="fw-semibold mb-1"><i class="bi bi-patch-check me-1" aria-hidden="true"></i>Odczytano certyfikat</div>
              <div>Numer: <strong><?= h((string)($_cert_srv['numer'] ?: '—')) ?></strong></div>
              <div>Wystawiony dla: <strong><?= h((string)$_cert_srv['subject_cn']) ?></strong></div>
              <div>Ważny do: <strong><?= h(date('d.m.Y', strtotime((string)$_cert_srv['valid_to']))) ?></strong></div>
            </div>
            <input type="hidden" name="cert_z_serwera" value="1" form="pmApproveForm" id="pmCertSrv" <?= $_odczytany ? '' : 'disabled' ?>>
            <div class="visually-hidden" role="status" aria-live="polite" id="pmCertStan"></div>
            <?php else: ?>
            <h4 class="h6 fw-semibold mb-1"><span class="visually-hidden">Krok 2: </span>Dodaj certyfikat i wpisz hasło oraz kod</h4>
            <p class="small text-body-secondary mb-2">Twój certyfikat przyszedł mailem przed 11.10.2026 — wybierz plik, wpisz hasło do niego i kod z SMS-a.
              Nowe certyfikaty są zapisywane w systemie (wygenerujesz je w <a href="moj_certyfikat.php">Mój certyfikat</a>).</p>
            <label class="form-label small fw-semibold mb-1" for="pmP12">Plik certyfikatu (.p12)</label>
            <input type="file" class="form-control mb-2" id="pmP12" name="p12" accept=".p12,.pfx" required form="pmApproveForm">
            <?php endif; ?>
            <label class="form-label small fw-semibold mb-1" for="pmP12Pwd">Hasło do certyfikatu</label>
            <input type="password" class="form-control mb-2" id="pmP12Pwd" name="p12_password" autocomplete="off" required form="pmApproveForm">
            <label class="form-label small fw-semibold mb-1" for="pmOtp">Kod z SMS-a — 6 cyfr z kroku 1</label>
            <input type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" class="form-control mb-1" id="pmOtp" name="otp"
                   autocomplete="one-time-code" required form="pmApproveForm" aria-describedby="pmOtpHint">
            <p class="small text-body-secondary mb-0" id="pmOtpHint">
              <?php if ($_cert_srv): ?>
              Certyfikat jest zapisany w systemie i zaszyfrowany Twoim hasłem. Hasła nie zapisujemy — znasz je tylko Ty i podajesz przy każdym podpisie.
              <?php else: ?>
              Wgrany plik służy tylko do tego podpisu i nie jest zapisywany. Hasła nie zapisujemy — znasz je tylko Ty.
              <?php endif; ?>
            </p>
          </div>
        </div></li>
        <li class="card mb-2"><div class="card-body d-flex gap-3">
          <span class="pm-nr" aria-hidden="true">3</span>
          <div class="flex-grow-1">
            <h4 class="h6 fw-semibold mb-1"><span class="visually-hidden">Krok 3: </span>Sprawdź godziny i podpisz</h4>
            <p class="small text-body-secondary mb-2">Otworzy się lista godzin z tego miesiąca. Sprawdź daty i liczbę godzin,
              zaznacz „Sprawdziłem/am listę godzin” i kliknij „Akceptuję”. Protokół zostanie podpisany i zamknięty.</p>
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#pmHoursCheck">
              <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Zatwierdź protokół
            </button>
          </div>
        </div></li>
      </ol>

    <?php else: ?>
      <div class="alert alert-warning small py-2 mb-3">
        <div class="fw-semibold"><i class="bi bi-shield-exclamation" aria-hidden="true"></i> Nie masz certyfikatu do podpisu</div>
        <p class="mb-0">Certyfikat to elektroniczny odpowiednik Twojego podpisu: potwierdza, że to Ty zatwierdziłeś protokół i że jego treść się nie zmieniła.
          Wygeneruj go w <a href="moj_certyfikat.php">Mój certyfikat</a> (hasło ustawiasz sam) albo dodaj tam klucz bezpieczeństwa (passkey lub YubiKey) i zatwierdzaj nim.</p>
      </div>
    <?php endif; ?>

    <?php if ($_has_key && !$_key_first): ?>
      <?php /* Klucz jako alternatywa dla certyfikatu — osobna, spokojniejsza karta pod krokami. */ ?>
      <section class="card bg-body-tertiary border-0 mt-3" aria-labelledby="pmAltH">
        <div class="card-body">
          <h3 class="h6 fw-semibold mb-1" id="pmAltH">Albo: zatwierdź kluczem bezpieczeństwa</h3>
          <p class="small text-body-secondary mb-2" id="pmYkOpis">Masz passkey lub YubiKey? Możesz pominąć kroki 1–3: kliknij przycisk
            i dotknij klucza albo potwierdź w telefonie. Nie potrzebujesz pliku, hasła ani kodu SMS.</p>
          <button type="button" class="btn btn-outline-success" id="pmYkBtn" data-course="<?= $_cid ?>" data-ym="<?= h($open_ym) ?>"
                  aria-describedby="pmYkOpis pmYkStan">
            <i class="bi bi-usb-drive me-1" aria-hidden="true"></i>Zatwierdź kluczem (passkey / YubiKey)
          </button>
          <div class="visually-hidden" role="status" aria-live="polite" id="pmYkStan"></div>
          <div class="alert alert-danger small py-2 mt-2 mb-0 d-none" role="alert" id="pmYkBlad"></div>
        </div>
      </section>
    <?php endif; ?>
    </div>

    <?php
      // Prawa kolumna: podgląd dokumentów, które powstaną po zatwierdzeniu.
      $_pv_q   = 'course=' . $_cid . '&ym=' . urlencode($open_ym);
      // 0 godzin: pełnego protokołu nie ma — do podglądu i podpisu jest tylko informacja o braku zajęć.
      $_pv_dok = $_zero ? [
          'prot' => ['label' => 'Informacja: 0 godzin', 'opis' => 'W tym miesiącu nie było zajęć — zamiast protokołu podpisujesz tę informację.',
                     'url' => 'protokol_pdf.php?preview=1&' . $_pv_q],
      ] : [
          'prot'    => ['label' => 'Protokół zajęć', 'opis' => 'Dokument, który po zatwierdzeniu trafi do akt (i do EZD).',
                        'url' => 'protokol_pdf.php?preview=1&' . $_pv_q],
          'godziny' => ['label' => 'Lista godzin', 'opis' => 'Daty i liczba godzin — tę treść obejmuje Twój podpis.',
                        'url' => 'protokol_pdf.php?hours=1&' . $_pv_q],
      ];
      // Trzeci i czwarty dokument: e-ewidencja godzin i e-lista obecności do umowy zlecenie.
      // Bez umowy w systemie też się generują — pola umowy mają wtedy „----”.
      if (!$_zero) {
          $_pv_umowy = ti_protocol_zlecenie_umowy(ti_protocol_month_stub($_cid, $open_ym));
          $_pv_u     = $_pv_umowy ? '&umowa=' . (int)$_pv_umowy[0]['id'] : '';
          $_pv_po    = $_pv_umowy ? ' Po podpisaniu protokołu sprawdzisz ją' . (count($_pv_umowy) > 1 ? ', wybierzesz umowę' : '')
                                    . ' i wyrazisz zgodę na przepisanie godzin.'
                                  : ' Nie znaleźliśmy Twojej umowy zlecenie — pola umowy mają „----”.';
          $_pv_dok['ewidencja'] = ['label' => 'Lista godzin do umowy',
              'opis' => 'E-ewidencja godzin do umowy zlecenie — osobny dokument.' . $_pv_po,
              'url' => 'protokol_pdf.php?ewidencja=1&' . $_pv_q . $_pv_u];
          $_pv_dok['obecnosc'] = ['label' => 'Lista obecności do umowy',
              'opis' => 'E-lista obecności do umowy zlecenie: Twoje zajęcia w miesiącu, godziny i obecni kursanci.'
                      . ($_pv_umowy ? '' : ' Nie znaleźliśmy Twojej umowy zlecenie — pola umowy mają „----”.'),
              'url' => 'protokol_pdf.php?obecnosc=1&' . $_pv_q . $_pv_u];
      }
      // Dziennik zajęć grupy (cały kurs, bez danych osobowych) — generuje się razem z dokumentami protokołu.
      $_pv_dok['dziennik'] = ['label' => 'Dziennik zajęć',
          'opis' => 'Dziennik zajęć grupy za ten miesiąc (terminy, obecności, tematy, oceny) — bez danych osobowych kursantów.',
          'url' => 'protokol_pdf.php?dziennik=1&' . $_pv_q];
    ?>
    <div class="col-xl-7 col-lg-6">
      <section class="card h-100" aria-labelledby="pmPodgladH">
        <div class="card-body d-flex flex-column">
          <h3 class="h6 fw-semibold mb-1" id="pmPodgladH"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Podgląd dokumentów</h3>
          <p class="small text-body-secondary mb-2">Tak będą wyglądać dokumenty po zatwierdzeniu. Podgląd ma napis „PODGLĄD” — nie jest jeszcze podpisany.</p>
          <div class="btn-group btn-group-sm mb-2" role="group" aria-label="Wybierz dokument do podglądu">
            <?php $_pv_first = true; foreach ($_pv_dok as $_k => $_d): ?>
            <button type="button" class="btn btn-outline-primary<?= $_pv_first ? ' active' : '' ?>" data-pv="<?= h($_k) ?>"
                    data-url="<?= h($_d['url']) ?>" data-opis="<?= h($_d['opis']) ?>" aria-pressed="<?= $_pv_first ? 'true' : 'false' ?>"
                    aria-controls="pmPodgladRamka"><?= h($_d['label']) ?></button>
            <?php $_pv_first = false; endforeach; ?>
          </div>
          <p class="small mb-2" id="pmPodgladOpis" aria-live="polite"><?= h($_pv_dok['prot']['opis']) ?></p>
          <iframe id="pmPodgladRamka" title="Podgląd dokumentu: <?= h($_pv_dok['prot']['label']) ?>" src="<?= h($_pv_dok['prot']['url']) ?>"
                  class="border flex-grow-1 w-100" style="min-height:min(80vh,56rem)" loading="lazy"></iframe>
          <a class="small mt-2" id="pmPodgladNowe" href="<?= h($_pv_dok['prot']['url']) ?>" target="_blank" rel="noopener">
            Otwórz podgląd w nowej karcie<span class="visually-hidden"> (nowe okno)</span>
          </a>
        </div>
      </section>
      <script>
      // „Odczytaj certyfikat” bez przeładowania strony (bez JS działa zwykły link z ?odczytaj=1).
      (function () {
        var a = document.getElementById('pmOdczytaj'); if (!a) return;
        a.addEventListener('click', function (e) {
          e.preventDefault();
          document.getElementById('pmCertInfo').hidden = false;
          document.getElementById('pmCertSrv').disabled = false;
          a.classList.add('d-none');
          document.getElementById('pmCertStan').textContent = 'Odczytano certyfikat. Wpisz hasło do certyfikatu.';
          var p = document.getElementById('pmP12Pwd'); if (p) p.focus();
        });
      })();
      </script>
      <script>
      (function () {
        var ramka = document.getElementById('pmPodgladRamka'), opis = document.getElementById('pmPodgladOpis'),
            nowe = document.getElementById('pmPodgladNowe');
        document.querySelectorAll('[data-pv]').forEach(function (b) {
          b.addEventListener('click', function () {
            document.querySelectorAll('[data-pv]').forEach(function (x) { x.classList.remove('active'); x.setAttribute('aria-pressed', 'false'); });
            b.classList.add('active'); b.setAttribute('aria-pressed', 'true');
            ramka.src = b.dataset.url; nowe.href = b.dataset.url;
            ramka.title = 'Podgląd dokumentu: ' + b.textContent.trim();
            opis.textContent = b.dataset.opis;
          });
        });
      })();
      </script>
    </div>
    </div>

    <?php if ($_has_key): ?>
        <script>
        (function () {
          var b = document.getElementById('pmYkBtn'); if (!b) return;
          var base = <?= json_encode(APP_URL . '/karty30/ti/dydaktyk/yk_protocol.php') ?>;
          var tok  = <?= json_encode(dyd_token()) ?>;
          function b64uToBuf(s) { s = s.replace(/-/g, '+').replace(/_/g, '/'); while (s.length % 4) s += '=';
            var bin = atob(s), a = new Uint8Array(bin.length); for (var i = 0; i < bin.length; i++) a[i] = bin.charCodeAt(i); return a.buffer; }
          function bufToB64u(buf) { var u = new Uint8Array(buf), s = ''; for (var i = 0; i < u.length; i++) s += String.fromCharCode(u[i]);
            return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); }
          var stan = document.getElementById('pmYkStan'), blad = document.getElementById('pmYkBlad');
          function koniec() { b.disabled = false; b.removeAttribute('aria-busy'); }
          if (!window.PublicKeyCredential || !navigator.credentials) {
            b.disabled = true;
            blad.textContent = 'Ta przeglądarka nie obsługuje kluczy bezpieczeństwa. Otwórz panel w aktualnym Chrome, Edge, Firefox lub Safari.';
            blad.classList.remove('d-none');
            return;
          }
          b.addEventListener('click', async function () {
            if (!confirm('Sprawdziłeś/aś ewidencję godzin? Zatwierdzenie kluczem zamyka protokół.')) return;
            b.disabled = true; b.setAttribute('aria-busy', 'true'); blad.classList.add('d-none');
            stan.textContent = 'Dotknij klucza bezpieczeństwa albo potwierdź w urządzeniu.';
            try {
              var r = await fetch(base + '?action=begin&course_id=' + b.dataset.course + '&year_month=' + encodeURIComponent(b.dataset.ym), { credentials: 'same-origin' });
              var j = await r.json(); if (!r.ok) throw new Error(j.error || 'Nie udało się rozpocząć podpisu.');
              var o = j.data;
              var cred = await navigator.credentials.get({ publicKey: {
                challenge: b64uToBuf(o.challenge), rpId: o.rpId,
                allowCredentials: (o.allowCredentials || []).map(function (c) { return { type: 'public-key', id: b64uToBuf(c.id) }; }),
                userVerification: o.userVerification || 'preferred', timeout: o.timeout || 60000 } });
              var resp = { id: bufToB64u(cred.rawId), rawId: bufToB64u(cred.rawId), type: cred.type, response: {
                clientDataJSON: bufToB64u(cred.response.clientDataJSON),
                authenticatorData: bufToB64u(cred.response.authenticatorData),
                signature: bufToB64u(cred.response.signature) } };
              var r2 = await fetch(base + '?action=complete', { method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': tok },
                body: JSON.stringify({ course_id: Number(b.dataset.course), year_month: b.dataset.ym, response: resp }) });
              var j2 = await r2.json(); if (!r2.ok) throw new Error(j2.error || 'Nie udało się zatwierdzić kluczem.');
              stan.textContent = 'Protokół zatwierdzony. Wracam do listy protokołów.';
              location.href = 'protokoly_moje.php?podpisano=1&course=' + encodeURIComponent(b.dataset.course) + '&ym=' + encodeURIComponent(b.dataset.ym);
            } catch (e) {
              stan.textContent = '';
              blad.textContent = (e && e.name === 'NotAllowedError')
                ? 'Zatwierdzanie kluczem przerwane albo minął czas. Spróbuj ponownie.'
                : (e && e.message ? e.message : 'Nie udało się zatwierdzić kluczem.');
              blad.classList.remove('d-none');
              koniec();
            }
          });
        })();
        </script>
    <?php endif; ?>
<?php endif; ?>
  </div>
</div>

<?= ti_protocol_hours_check_modal(
    'pmHoursCheck',
    ti_protocol_month_stub((int)$open_row['course_id'], $open_ym),
    'protokol_pdf.php?hours=1&course=' . (int)$open_row['course_id'] . '&ym=' . urlencode($open_ym),
    'pmApproveForm',
    'Akceptuję i zatwierdzam protokół'
) ?>

<?php else: ?>
<!-- ═══ KROK 1: lista miesięcy do zamknięcia ═══════════════════════════ -->
<?php if (!$pending): ?>
<div class="alert alert-success d-flex align-items-center gap-2" role="status">
  <i class="bi bi-check-circle-fill flex-shrink-0" aria-hidden="true"></i>
  <span>Nic do zrobienia — wszystkie protokoły są zamknięte.</span>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
  <div class="card-header fw-semibold">Miesiące do zamknięcia</div>
  <ul class="list-group list-group-flush">
    <?php foreach ($pending as $p): ?>
    <li class="list-group-item d-flex align-items-center gap-3 flex-wrap">
      <div class="flex-grow-1">
        <div class="fw-semibold"><?= h($p['course_name']) ?></div>
        <div class="text-body-secondary small"><?= h($fmt_ym($p['year_month'])) ?></div>
      </div>
      <?php if ($p['is_overdue']): ?>
      <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>zaległy</span>
      <?php else: ?>
      <span class="badge bg-secondary">bieżący</span>
      <?php endif; ?>
      <a href="protokoly_moje.php?course=<?= (int)$p['course_id'] ?>&ym=<?= h($p['year_month']) ?>" class="btn btn-sm btn-primary">
        <i class="bi bi-journal-check me-1" aria-hidden="true"></i>Zamknij protokół
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php if ($months): ?>
<?php $_mstate = [
    'approved' => ['success', 'zatwierdzony'],
    'overdue'  => ['warning text-dark', 'zaległy'],
    'current'  => ['primary', 'bieżący'],
    'empty'    => ['light text-dark border', 'bez zajęć'],
]; ?>
<?php
// Na start tylko bieżące, zaległe i ostatnie 2 miesiące; starsze pod przyciskiem.
$_cut   = date('Y-m', strtotime('-2 months'));
$_n_old = count(array_filter($months, fn($m) => !in_array($m['state'], ['current', 'overdue'], true) && $m['year_month'] < $_cut));
?>
<div class="card border-0 shadow-sm mt-3">
  <div class="card-header fw-semibold">Miesiące</div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <caption class="visually-hidden">Miesiące i stan protokołów per grupa</caption>
      <thead><tr>
        <th scope="col">Miesiąc</th><th scope="col">Grupa</th><th scope="col">Numer</th><th scope="col">Stan</th>
        <th scope="col" class="text-end">Zajęcia odbyte</th><th scope="col" class="text-end">Akcja</th>
      </tr></thead>
      <tbody>
        <?php foreach ($months as $mr): [$_bc, $_bl] = $_mstate[$mr['state']];
              $_old = !in_array($mr['state'], ['current', 'overdue'], true) && $mr['year_month'] < $_cut; ?>
        <tr<?= $_old ? ' class="month-old d-none"' : '' ?>>
          <td class="text-nowrap"><?= h($fmt_ym($mr['year_month'])) ?></td>
          <td><?= h($mr['course_name']) ?></td>
          <td class="font-monospace small text-nowrap"><?= h(TiProtocolNumbers::forCourseMonth((int)$mr['course_id'], (string)$mr['year_month'])) ?></td>
          <td><span class="badge bg-<?= $_bc ?>"><?= h($_bl) ?></span></td>
          <td class="text-end"><?= (int)$mr['lessons_held'] ?><?php if ($mr['state'] === 'current' && $mr['planned_left'] > 0): ?>
            <span class="text-body-secondary small">(+<?= (int)$mr['planned_left'] ?> zaplan.)</span><?php endif; ?></td>
          <td class="text-end text-nowrap">
            <?php if ($mr['state'] === 'approved'): ?>
              <a href="protokol_pdf.php?id=<?= (int)$mr['protocol_id'] ?>" class="btn btn-sm btn-outline-secondary py-0"
                 aria-label="PDF protokołu: <?= h($mr['course_name']) ?>, <?= h($fmt_ym($mr['year_month'])) ?>"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> PDF</a>
              <?= $wycofaj_html((int)$mr['protocol_id'], $mr['course_name'] . ', ' . $fmt_ym($mr['year_month'])) ?>
            <?php elseif ($mr['state'] === 'empty'): ?>
              <span class="text-body-secondary small">niewymagany</span>
            <?php elseif ($mr['can_approve'] || dyd_is_staff()): ?>
              <a href="protokoly_moje.php?course=<?= (int)$mr['course_id'] ?>&ym=<?= h($mr['year_month']) ?>" class="btn btn-sm btn-primary py-0">
                <i class="bi bi-journal-check me-1" aria-hidden="true"></i>Zamknij<?= $mr['state'] === 'current' ? ' teraz' : '' ?>
              </a>
            <?php else: ?>
              <span class="text-body-secondary small"><i class="bi bi-lock" aria-hidden="true"></i> główny prowadzący</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($_n_old > 0): ?>
  <div class="card-footer bg-white text-center">
    <button type="button" class="btn btn-sm btn-link" id="pmShowOld" aria-expanded="false" aria-controls="pmMonths">
      Pokaż starsze miesiące (<?= $_n_old ?>)
    </button>
  </div>
  <script>
  (function () {
    var b = document.getElementById('pmShowOld');
    b.addEventListener('click', function () {
      var open = b.getAttribute('aria-expanded') === 'true';
      document.querySelectorAll('tr.month-old').forEach(function (tr) { tr.classList.toggle('d-none', open); });
      b.setAttribute('aria-expanded', open ? 'false' : 'true');
      b.textContent = open ? 'Pokaż starsze miesiące (<?= $_n_old ?>)' : 'Ukryj starsze miesiące';
    });
  })();
  </script>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php
// ── Numery protokołów PRT/<kod grupy>/<MM>/<RR> — do wpisania na dokumentach (także papierowych) ──
$_pn_courses = dyd_is_staff() ? k30_ti_courses(true) : k30_ti_instructor_courses($uid, true);
$_pn_codes = [];
foreach ($_pn_courses as $_c) $_pn_codes[(int)$_c['id']] = ['name' => (string)$_c['name'], 'code' => TiProtocolNumbers::code((int)$_c['id'])];
$_pn_ym = date('Y-m');
?>
<?php if ($_pn_codes): ?>
<section class="card border-0 shadow-sm mt-3" aria-labelledby="pnH">
  <div class="card-header fw-semibold" id="pnH"><i class="bi bi-hash me-1 text-primary" aria-hidden="true"></i>Numery protokołów</div>
  <div class="card-body">
    <p class="small text-body-secondary mb-2">Numer ma postać <span class="font-monospace">PRT/kod grupy/MM/RR</span> i nadaje się sam przy protokole. Tutaj sprawdzisz go wcześniej — np. do wpisania na papierowej liście obecności. Protokół założony później dostanie ten sam numer.</p>
    <div class="row g-2 align-items-end">
      <div class="col-sm-6"><label class="form-label small mb-0" for="pnC">Grupa</label>
        <select class="form-select form-select-sm" id="pnC"><?php foreach ($_pn_codes as $_id => $_x): ?><option value="<?= $_id ?>"><?= h($_x['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-sm-3"><label class="form-label small mb-0" for="pnM">Miesiąc</label>
        <input class="form-control form-control-sm" type="month" id="pnM" value="<?= h($_pn_ym) ?>"></div>
      <div class="col-sm-3 d-flex gap-1 align-items-center">
        <output class="font-monospace fw-bold" id="pnOut" for="pnC pnM" aria-live="polite"></output>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="pnCopy"><i class="bi bi-clipboard" aria-hidden="true"></i><span class="visually-hidden">Kopiuj numer</span></button>
      </div>
    </div>
    <details class="mt-3"><summary class="small fw-semibold">Wszystkie moje grupy — bieżący i poprzedni miesiąc</summary>
      <table class="table table-sm mt-2 mb-0"><caption class="visually-hidden">Numery protokołów grup</caption>
        <thead><tr><th scope="col">Grupa</th><th scope="col"><?= h(date('m.Y', strtotime('first day of last month'))) ?></th><th scope="col"><?= h(date('m.Y')) ?></th></tr></thead>
        <tbody><?php foreach ($_pn_codes as $_id => $_x): ?><tr><th scope="row" class="fw-normal small"><?= h($_x['name']) ?></th>
          <td class="font-monospace small"><?= h(TiProtocolNumbers::forCourseMonth($_id, date('Y-m', strtotime('first day of last month')))) ?></td>
          <td class="font-monospace small"><?= h(TiProtocolNumbers::forCourseMonth($_id, $_pn_ym)) ?></td></tr><?php endforeach; ?></tbody>
      </table>
    </details>
  </div>
</section>
<script>
(function () {
  var codes = <?= json_encode(array_map(fn($x) => $x['code'], $_pn_codes), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
  var c = document.getElementById('pnC'), m = document.getElementById('pnM'), out = document.getElementById('pnOut'), cp = document.getElementById('pnCopy');
  function upd() { var v = m.value.split('-'); out.textContent = (v.length === 2 && codes[c.value]) ? 'PRT/' + codes[c.value] + '/' + v[1] + '/' + v[0].slice(2) : ''; }
  c.addEventListener('change', upd); m.addEventListener('change', upd); upd();
  cp.addEventListener('click', function () { if (navigator.clipboard && out.textContent) navigator.clipboard.writeText(out.textContent).then(function () { cp.innerHTML = '<i class="bi bi-check2" aria-hidden="true"></i><span class="visually-hidden">Skopiowano</span>'; }); });
})();
</script>
<?php endif; ?>

<?php if ($closed): ?>
<details class="card border-0 shadow-sm mt-3">
  <summary class="card-header fw-semibold" style="cursor:pointer">Kto zatwierdził i podpis (<?= count($closed) ?>)</summary>
  <ul class="list-group list-group-flush">
    <?php foreach ($closed as $c): ?>
    <li class="list-group-item d-flex align-items-center gap-3 flex-wrap">
      <div class="flex-grow-1">
        <div class="fw-semibold"><?= h($c['course_name']) ?></div>
        <div class="text-body-secondary small">
          <?= h($fmt_ym($c['year_month'])) ?>
          <?php if (!empty($c['approved_at'])): ?> · zatwierdził <?= h($c['approved_name'] ?: '—') ?>, <?= h(date('d.m.Y', strtotime((string)$c['approved_at']))) ?><?php if (!empty($c['sign_subject'])): ?> · podpis certyfikatem: <?= h($c['sign_subject']) ?><?php endif; ?><?php endif; ?>
        </div>
      </div>
      <span class="badge bg-success">zatwierdzony</span>
      <a href="protokol_pdf.php?id=<?= (int)$c['protocol_id'] ?>" class="btn btn-sm btn-outline-secondary"
         aria-label="Pobierz PDF protokołu: <?= h($c['course_name']) ?>, <?= h($fmt_ym($c['year_month'])) ?>">
        <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF
      </a>
      <?= $wycofaj_html((int)$c['protocol_id'], $c['course_name'] . ', ' . $fmt_ym($c['year_month'])) ?>
    </li>
    <?php endforeach; ?>
  </ul>
</details>
<?php endif; ?>
<?php endif; ?>

</div>
</main>

<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
