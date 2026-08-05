<?php
/**
 * panel/zgody.php — „Zgody i Oświadczenia": zgoda przedstawiciela ustawowego
 * na wolontariat małoletniego dziecka + RODO (co 6 miesięcy, na klik).
 *
 * Widoczne dla opiekuna zalogowanego na WŁASNE konto — dopasowanie po adresie
 * e-mail do umowy_wolontariat.rodzic_email (nie przez ctx_enter_child).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/guardian_consent.php';
require_once dirname(__DIR__) . '/includes/approval.php';

require_login();

$user  = current_user();
$email = trim($user['email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $contract_id = (int)($_POST['contract_id'] ?? 0);
    $contract = db_one(
        "SELECT * FROM umowy_wolontariat WHERE id=? AND rodzic_email=? AND niepelnoletni=1",
        [$contract_id, $email]
    );
    if (!$contract) {
        flash_set('danger', 'Nie znaleziono umowy powiązanej z Twoim kontem.');
    } elseif (empty($_POST['consent'])) {
        flash_set('danger', 'Zaznacz oświadczenie, że zapoznałeś/aś się z treścią.');
    } elseif (trim($_POST['adres'] ?? '') === '' || trim($_POST['dowod_seria_nr'] ?? '') === '' || trim($_POST['telefon'] ?? '') === '') {
        flash_set('danger', 'Uzupełnij wszystkie pola formularza.');
    } else {
        $expires = guardian_consent_save($contract_id, [
            'adres'          => $_POST['adres'],
            'dowod_seria_nr' => $_POST['dowod_seria_nr'],
            'telefon'        => $_POST['telefon'],
        ]);
        log_contract_action('wolontariat', $contract_id, (int)$user['id'], 'note',
            'Przedstawiciel ustawowy złożył zgodę na wolontariat + RODO (ważna do ' . date('d.m.Y', strtotime($expires)) . ').');

        // Zamknij ewentualną otwartą sprawę EZD dot. zaproszenia do odnowienia zgody.
        try {
            require_once dirname(__DIR__) . '/includes/ezd.php';
            $sprawa_id = (int)($contract['zgoda_przedstawiciela_ezd_sprawa_id'] ?? 0);
            if ($sprawa_id) {
                $sprawa = ezd_sprawa_get($sprawa_id);
                if ($sprawa && $sprawa['status'] !== 'closed') {
                    ezd_sprawa_update($sprawa_id, [
                        'title'       => $sprawa['title'],
                        'description' => $sprawa['description'] . "\n\nZgoda złożona przez przedstawiciela ustawowego w panelu " . date('Y-m-d H:i') . '.',
                        'status'      => 'closed',
                    ], (int)$user['id']);
                }
            }
        } catch (\Throwable $e) {}

        unset($_SESSION['gc_consent_nudge_snoozed']);
        flash_set('success', 'Dziękujemy — zgoda została zapisana i jest ważna do ' . date('d.m.Y', strtotime($expires)) . '.');
        header('Location: ' . APP_URL . '/panel/zgody.php');
        exit;
    }
}

$pending = guardian_consent_pending_for_email($email);

$_is_volunteer_only = is_viewer() && !db_one("SELECT id FROM users WHERE id=? AND k30_consultant=1", [(int)$user['id']]);
if ($_is_volunteer_only) {
    include __DIR__ . '/includes/header_panel.php';
} else {
    $PAGE_TITLE = 'Zgody i Oświadczenia';
    include dirname(__DIR__) . '/includes/header.php';
}
require_once __DIR__ . '/includes/pv_ui.php';
?>

<div class="pv-wrap">

<?php pv_page_header('Zgody i Oświadczenia', [
    'icon' => 'bi-file-earmark-check',
    'back' => ['url' => APP_URL . '/panel/', 'label' => 'Panel'],
]); ?>

<?= function_exists('flash_html') ? flash_html() : '' ?>

<?php if (!$pending): ?>
<div class="pv-empty" role="status">
  <i class="bi bi-check2-circle" aria-hidden="true"></i>
  <div class="pv-empty-title">Brak zgód do odnowienia</div>
  <div class="pv-empty-sub">
    Wszystkie wymagane zgody przedstawiciela ustawowego są aktualne, albo żadna umowa
    powiązana z Twoim adresem e-mail (<?= h($email) ?>) nie wymaga takiej zgody.
  </div>
</div>
<?php endif; ?>

<?php foreach ($pending as $c): $cid = (int)$c['id']; ?>
<div class="tz-card" role="region" aria-labelledby="gc-heading-<?= $cid ?>">
  <div class="tz-card__hd">
    <i class="bi bi-file-earmark-person" aria-hidden="true"></i>
    <div id="gc-heading-<?= $cid ?>">
      <span><?= h($c['imie_nazwisko']) ?></span>
      <span class="fw-normal d-block" style="color:var(--tz-muted);font-size:.85rem">Umowa <?= h($c['numer_umowy']) ?></span>
    </div>
    <span class="tz-badge tz-badge--wait ms-auto" aria-label="Status: wymaga podpisu">Wymaga podpisu</span>
  </div>
  <div class="tz-card__bd">

    <div class="overflow-auto rounded-3 p-3 mb-3"
         style="max-height:420px;background:var(--tz-bg-sub);border:1px solid var(--tz-line);font-size:.92rem;line-height:1.6;color:var(--tz-ink)"
         tabindex="0"
         role="document"
         aria-label="Treść oświadczenia przedstawiciela ustawowego">
      <?= guardian_consent_statement_html($c, $user['name'] ?? '') ?>
    </div>

    <form method="post" aria-label="Formularz zgody dla <?= h($c['imie_nazwisko']) ?>">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="contract_id" value="<?= $cid ?>">

      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <label class="form-label small fw-semibold" for="adres-<?= $cid ?>">
            Adres zamieszkania (przedstawiciela)
          </label>
          <input type="text" id="adres-<?= $cid ?>" name="adres"
                 class="form-control form-control-sm"
                 value="<?= h($c['zgoda_przedstawiciela_adres'] ?? '') ?>"
                 required aria-required="true"
                 autocomplete="street-address">
        </div>
        <div class="col-sm-3">
          <label class="form-label small fw-semibold" for="dowod-<?= $cid ?>">
            Seria i nr dowodu osobistego
          </label>
          <input type="text" id="dowod-<?= $cid ?>" name="dowod_seria_nr"
                 class="form-control form-control-sm"
                 value="<?= h($c['zgoda_przedstawiciela_dowod'] ?? '') ?>"
                 required aria-required="true" autocomplete="off">
        </div>
        <div class="col-sm-3">
          <label class="form-label small fw-semibold" for="tel-<?= $cid ?>">
            Numer telefonu
          </label>
          <input type="text" id="tel-<?= $cid ?>" name="telefon"
                 class="form-control form-control-sm"
                 value="<?= h($c['rodzic_telefon'] ?? '') ?>"
                 required aria-required="true"
                 inputmode="tel" autocomplete="tel">
        </div>
      </div>

      <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox"
               id="consent-<?= $cid ?>" name="consent" value="1"
               required aria-required="true">
        <label class="form-check-label small" for="consent-<?= $cid ?>">
          Oświadczam, że zapoznałem/am się z treścią Oświadczenia przedstawiciela ustawowego
          powyżej i <strong>wyrażam zgodę</strong> zgodnie z jej treścią.
        </label>
      </div>

      <button type="submit" class="tz-btn">
        <i class="bi bi-check2-circle" aria-hidden="true"></i>Potwierdzam i wyrażam zgodę
      </button>
    </form>

  </div>
</div>
<?php endforeach; ?>

</div><!-- /.pv-wrap -->

<?php
if ($_is_volunteer_only) { include __DIR__ . '/includes/footer_panel.php'; }
else { include dirname(__DIR__) . '/includes/footer.php'; }
?>
