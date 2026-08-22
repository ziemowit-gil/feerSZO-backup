<?php
/**
 * crm/oferta.php — PUBLICZNA strona oferty dla klienta (dostęp po tokenie).
 *
 * Bez logowania. Klient widzi dokument oferty, wybiera wariant i:
 *   • akceptuje ofertę,
 *   • ODRĘBNIE POTWIERDZA warunki — wymagane, gdy odbiorcą jest OSOBA FIZYCZNA
 *     (oświadczenia + imię i nazwisko, zapis czasu i adresu IP),
 *   • albo odrzuca ofertę z podaniem powodu.
 *
 * Strona jest samodzielna — celowo NIE używa powłoki CRM (ta wymaga IKA).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';
require_once dirname(__DIR__) . '/includes/crm_offers.php';

crm_offers_migrate();

$token = trim((string)($_GET['t'] ?? $_POST['t'] ?? ''));
$offer = $token !== '' ? crm_offer_get_by_token($token) : null;
$org   = crm_offer_org_block();
$msg   = null;
$msg_t = 'info';

if ($offer) {
    $offer_id   = (int)$offer['id'];
    $needs_conf = crm_offer_requires_confirmation($offer);
    $expired    = !empty($offer['valid_until']) && strtotime((string)$offer['valid_until']) < strtotime(date('Y-m-d'));
    $decided    = in_array($offer['status'], ['zaakceptowana', 'odrzucona', 'zrealizowana', 'anulowana'], true);
    $open       = in_array($offer['status'], ['wyslana'], true) && !$expired;

    // ── Decyzja klienta ─────────────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $act = (string)($_POST['act'] ?? '');

        if (!$open) {
            $msg = 'Ta oferta nie przyjmuje już decyzji online. Prosimy o kontakt z osobą prowadzącą sprawę.';
            $msg_t = 'warn';
        } elseif ($act === 'reject') {
            crm_offer_set_status($offer_id, 'odrzucona', [
                'event'         => 'rejected',
                'actor_id'      => null,
                'actor_label'   => 'klient',
                'reject_reason' => trim((string)($_POST['reason'] ?? '')),
                'detail'        => trim((string)($_POST['reason'] ?? '')) ?: 'Odrzucone przez klienta.',
            ]);
            $msg = 'Dziękujemy za odpowiedź. Zapisaliśmy informację o odrzuceniu oferty.';
            $msg_t = 'info';
        } elseif ($act === 'accept') {
            $vid  = (int)($_POST['variant_id'] ?? 0);
            $name = trim((string)($_POST['confirmed_name'] ?? ''));
            $vok  = $vid && crm_one("SELECT id FROM crm_offer_variants WHERE id=? AND offer_id=?", [$vid, $offer_id]);

            if (!$vok) {
                $msg = 'Wybierz wariant oferty.';
                $msg_t = 'warn';
            } elseif ($needs_conf && (empty($_POST['s1']) || empty($_POST['s2']) || empty($_POST['s3']) || $name === '')) {
                $msg = 'Aby potwierdzić ofertę, zaznacz wszystkie oświadczenia i podaj imię oraz nazwisko.';
                $msg_t = 'warn';
            } else {
                $statements = [];
                if ($needs_conf) {
                    $statements = [
                        'Zapoznałam/em się z treścią oferty i akceptuję jej warunki, w tym cenę i termin realizacji.',
                        'Potwierdzam poprawność moich danych wskazanych w ofercie.',
                        'Przyjmuję do wiadomości, że realizacja usługi rozpocznie się na podstawie tego potwierdzenia.',
                    ];
                }
                crm_offer_record_confirmation($offer_id, [
                    'variant_id'      => $vid,
                    'method'          => 'online',
                    'confirmed_name'  => $name ?: (string)$offer['contact_name'],
                    'confirmed_email' => (string)$offer['contact_email'],
                    'statements'      => $statements,
                    'note'            => 'Potwierdzenie online przez stronę oferty.',
                ], null);

                crm_offer_set_status($offer_id, 'zaakceptowana', [
                    'event'               => 'accepted',
                    'actor_id'            => null,
                    'actor_label'         => $name ?: 'klient',
                    'selected_variant_id' => $vid,
                    'recalc'              => true,
                    'detail'              => 'Akceptacja i potwierdzenie online.',
                ]);

                // Powiadomienie opiekuna oferty
                try {
                    $ownr = (int)($offer['owner_id'] ?? 0);
                    $mail = $ownr ? (db_one("SELECT email FROM users WHERE id=?", [$ownr])['email'] ?? '') : '';
                    if ($mail) {
                        require_once dirname(__DIR__) . '/includes/mail_queue.php';
                        $b = '<p>Klient <strong>' . h((string)$offer['contact_name']) . '</strong> zaakceptował i potwierdził ofertę <strong>'
                           . h((string)$offer['offer_number']) . '</strong>.</p>';
                        mail_queue_add($mail, '', 'Oferta ' . $offer['offer_number'] . ' — zaakceptowana',
                            _feer_email_tpl($b, 'Oferta zaakceptowana',
                                APP_URL . '/crm/offers/view.php?id=' . $offer_id, 'Otwórz ofertę w CRM'),
                            '', 'crm_offer', $offer_id, '', false);
                    }
                } catch (\Throwable $e) { error_log('[oferta.php] mail: ' . $e->getMessage()); }

                $msg = 'Dziękujemy! Oferta została zaakceptowana i potwierdzona. Skontaktujemy się w sprawie realizacji.';
                $msg_t = 'ok';
            }
        }
        // Odśwież stan po decyzji
        $offer      = crm_offer_get_by_token($token);
        $needs_conf = $offer ? crm_offer_requires_confirmation($offer) : false;
        $decided    = $offer && in_array($offer['status'], ['zaakceptowana', 'odrzucona', 'zrealizowana', 'anulowana'], true);
        $open       = $offer && in_array($offer['status'], ['wyslana'], true) && !$expired;
    } else {
        crm_offer_mark_viewed($offer_id);
    }

    $full = $offer ? crm_offer_full($offer_id) : null;
    $conf = $offer ? crm_offer_confirmation($offer_id) : null;
}
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= $offer ? 'Oferta ' . h($offer['offer_number']) : 'Oferta' ?><?= $org['name'] ? ' — ' . h($org['name']) : '' ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root { --brand:#194E31; --brand2:#2E844A; }
* { box-sizing:border-box }
body { margin:0; background:#EEF1F4; color:#111827;
  font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; }
.top { background:var(--brand); color:#fff; padding:.7rem 1rem; }
.top .wrap { max-width:900px; margin:0 auto; display:flex; align-items:center; gap:.6rem; font-weight:700 }
.wrap { max-width:900px; margin:0 auto; padding:0 1rem }
.sheet { background:#fff; border-radius:12px; padding:26px 28px; margin:18px auto; box-shadow:0 2px 14px rgba(0,0,0,.07) }
.panel { background:#fff; border-radius:12px; padding:20px 22px; margin:16px auto; box-shadow:0 2px 14px rgba(0,0,0,.07) }
.alert { border-radius:9px; padding:.7rem .9rem; font-size:.9rem; margin:14px auto }
.alert-ok { background:#EFF7ED; border:1px solid #86C79A; color:#14532D }
.alert-warn { background:#FFF7ED; border:1px solid #FBBF77; color:#7C2D12 }
.alert-info { background:#EEF4FF; border:1px solid #A5C4FF; color:#1E3A8A }
.vopt { border:1px solid #D9DEE5; border-radius:9px; padding:.7rem .85rem; margin-bottom:.6rem; cursor:pointer; display:block }
.vopt:hover { border-color:var(--brand2); background:#F8FBF8 }
.vopt input { margin-right:.5rem }
.vopt.rec { border-color:var(--brand2) }
.stmt { display:flex; gap:.5rem; align-items:flex-start; font-size:.88rem; margin-bottom:.55rem }
.btn { border:none; border-radius:8px; padding:.6rem 1.1rem; font-weight:600; cursor:pointer; font-size:.92rem }
.btn-primary { background:var(--brand2); color:#fff }
.btn-outline { background:#fff; border:1px solid #D1D5DB; color:#374151 }
.lbl { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:#6B7280 }
.inp { width:100%; padding:.45rem .6rem; border:1px solid #D1D5DB; border-radius:7px; font-size:.92rem }
.foot { text-align:center; font-size:.76rem; color:#6B7280; padding:14px 0 26px }
<?= crm_offer_document_css() ?>
@media print { .top,.panel,.foot { display:none } .sheet { box-shadow:none; margin:0; border-radius:0 } body { background:#fff } }
</style>
</head>
<body>

<div class="top"><div class="wrap">
  <i class="bi bi-file-earmark-ruled-fill" aria-hidden="true"></i>
  <span><?= h($org['name'] ?: 'Oferta') ?></span>
  <?php if ($offer): ?><span style="margin-left:auto;font-weight:400;font-size:.85rem">Oferta <?= h($offer['offer_number']) ?></span><?php endif; ?>
</div></div>

<div class="wrap">

<?php if (!$offer): ?>
  <div class="panel" style="text-align:center">
    <i class="bi bi-exclamation-circle" style="font-size:2rem;color:#B45309" aria-hidden="true"></i>
    <h1 style="font-size:1.1rem">Nie znaleziono oferty</h1>
    <p style="font-size:.9rem;color:#6B7280">
      Link jest nieprawidłowy albo utracił ważność. Prosimy o kontakt z osobą, która przekazała ofertę.
    </p>
  </div>
<?php else: ?>

  <?php if ($msg): ?>
  <div class="alert alert-<?= $msg_t ?>" role="status"><?= h($msg) ?></div>
  <?php endif; ?>

  <?php if ($expired && !$decided): ?>
  <div class="alert alert-warn" role="status">
    <strong>Termin ważności oferty minął</strong> (<?= h(date_pl($offer['valid_until'])) ?>).
    Prosimy o kontakt — przygotujemy aktualną wersję.
  </div>
  <?php endif; ?>

  <div class="sheet"><div class="of-doc">
    <?= crm_offer_document_html($full, ['internal' => false]) ?>
  </div></div>

  <?php if ($decided): ?>
    <div class="panel">
      <div class="lbl">Status oferty</div>
      <h2 style="font-size:1.05rem;margin:.2rem 0 .4rem">
        <?= h(CRM_OFFER_STATUSES[$offer['status']]['label'] ?? $offer['status']) ?>
      </h2>
      <?php if ($conf): ?>
      <p style="font-size:.88rem;margin:0">
        Potwierdzenie zarejestrowane <?= h(date('d.m.Y H:i', strtotime((string)$conf['confirmed_at']))) ?>
        <?= $conf['confirmed_name'] ? ' — ' . h($conf['confirmed_name']) : '' ?>.
      </p>
      <?php endif; ?>
      <p style="font-size:.85rem;color:#6B7280;margin:.5rem 0 0">
        W razie pytań prosimy o kontakt<?= $org['email'] ? ': ' . h($org['email']) : '' ?>.
      </p>
    </div>
  <?php elseif ($open): ?>
    <form method="post" class="panel" id="decisionForm">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="t" value="<?= h($token) ?>">
      <input type="hidden" name="act" value="accept">

      <div class="lbl">Krok 1 — wybór wariantu</div>
      <div style="margin:.5rem 0 1rem">
        <?php foreach (($full['variants'] ?? []) as $v): ?>
        <label class="vopt<?= (int)$v['is_recommended'] === 1 ? ' rec' : '' ?>">
          <input type="radio" name="variant_id" value="<?= (int)$v['id'] ?>" required
                 <?= (int)$v['is_recommended'] === 1 ? 'checked' : '' ?>>
          <strong><?= h($v['code']) ?> — <?= h($v['name']) ?></strong>
          <span style="float:right;font-weight:700"><?= h(crm_offer_money((float)$v['total_gross'], (string)$full['currency'])) ?></span>
          <?php if ($v['description']): ?>
          <div style="font-size:.84rem;color:#6B7280;margin-top:.2rem"><?= nl2br(h($v['description'])) ?></div>
          <?php endif; ?>
        </label>
        <?php endforeach; ?>
      </div>

      <?php if ($needs_conf): ?>
      <div class="lbl">Krok 2 — potwierdzenie warunków (wymagane)</div>
      <div style="background:#FFF7ED;border:1px dashed #EA580C;border-radius:9px;padding:.8rem .9rem;margin:.5rem 0 1rem">
        <p style="font-size:.85rem;margin:0 0 .6rem">
          Oferta skierowana jest do osoby fizycznej — realizacja rozpocznie się dopiero po Państwa potwierdzeniu.
        </p>
        <label class="stmt"><input type="checkbox" name="s1" value="1" required>
          <span>Zapoznałam/em się z treścią oferty i akceptuję jej warunki, w tym cenę i termin realizacji.</span></label>
        <label class="stmt"><input type="checkbox" name="s2" value="1" required>
          <span>Potwierdzam poprawność moich danych wskazanych w ofercie.</span></label>
        <label class="stmt"><input type="checkbox" name="s3" value="1" required>
          <span>Przyjmuję do wiadomości, że realizacja usługi rozpocznie się na podstawie tego potwierdzenia.</span></label>
        <div style="margin-top:.6rem">
          <label class="lbl" for="cname">Imię i nazwisko osoby potwierdzającej</label>
          <input class="inp" id="cname" name="confirmed_name" required
                 value="<?= h($full['contact_type'] === 'osoba' ? (string)$full['contact_name'] : '') ?>">
        </div>
        <p style="font-size:.75rem;color:#7C2D12;margin:.55rem 0 0">
          Zapisujemy datę, godzinę i adres IP potwierdzenia — służy to wyłącznie udokumentowaniu Państwa decyzji.
        </p>
      </div>
      <?php else: ?>
      <input type="hidden" name="confirmed_name" value="<?= h((string)$full['contact_name']) ?>">
      <?php endif; ?>

      <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center">
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-check2-circle" aria-hidden="true"></i>
          <?= $needs_conf ? ' Potwierdzam i akceptuję ofertę' : ' Akceptuję ofertę' ?>
        </button>
        <button type="button" class="btn btn-outline" onclick="document.getElementById('rejectBox').style.display='block';this.style.display='none'">
          Nie jestem zainteresowany
        </button>
        <span style="font-size:.8rem;color:#6B7280">Oferta ważna do <?= h(date_pl($full['valid_until'])) ?></span>
      </div>
    </form>

    <form method="post" class="panel" id="rejectBox" style="display:none">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="t" value="<?= h($token) ?>">
      <input type="hidden" name="act" value="reject">
      <div class="lbl">Odrzucenie oferty</div>
      <p style="font-size:.86rem;color:#6B7280;margin:.3rem 0 .5rem">
        Prosimy o krótką informację — pomoże nam lepiej przygotować kolejne propozycje.
      </p>
      <input class="inp" name="reason" placeholder="np. cena, termin, wybrano inne rozwiązanie" style="margin-bottom:.6rem">
      <button class="btn btn-outline" type="submit">Wyślij odpowiedź</button>
    </form>
  <?php endif; ?>

  <div class="foot">
    <?= h($org['name']) ?><?= $org['email'] ? ' · ' . h($org['email']) : '' ?><?= $org['tel'] ? ' · ' . h($org['tel']) : '' ?>
    <div style="margin-top:.3rem">Dokument wygenerowany przez system SZO.</div>
  </div>

<?php endif; ?>
</div>
</body>
</html>
