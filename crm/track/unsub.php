<?php
/**
 * crm/track/unsub.php — publiczna strona wypisania z wysyłek mailowych.
 *
 * Wypisanie działa teraz NA CEL kampanii (crm_consents), a nie tylko przez
 * globalną flagę crm_contacts.email_opt_out: ktoś, kto nie chce newslettera,
 * nie musi tracić zaproszeń na wydarzenia. Kampania bez zadeklarowanego celu
 * wypisuje globalnie — jak dotąd. Niezależnie od celu strona daje też jawny
 * przycisk „wypisz ze wszystkiego", bo dla części odbiorców klik w stopce
 * znaczy właśnie to.
 *
 * DLACZEGO POTWIERDZENIE, A NIE AKCJA NA WEJŚCIU: filtry antyspamowe i
 * podglądy linków w Gmailu/Outlooku pobierają adresy ze stopki bez udziału
 * człowieka. Wypisywanie na samo GET zapisywałoby wycofania zgody, których
 * nikt nie zgłosił — a to trafia do rejestru zgód jako fakt. Jeden klik
 * potwierdzenia kosztuje odbiorcę sekundę i eliminuje fałszywe wpisy.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/crm_consent.php';

$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$scope = (string)($_POST['scope'] ?? '');   // '' = tylko pokaż, 'purpose' | 'all' = wykonaj

$rcpt = null; $campaign = null; $purpose = null; $done = null; $error = false;

if ($token !== '') {
    try {
        $rcpt = db_one("SELECT id, campaign_id, contact_id FROM crm_campaign_recipients WHERE tracking_token=?", [$token]);
        if ($rcpt) {
            $campaign = db_one("SELECT id, name, purpose_id FROM crm_campaigns WHERE id=?", [(int)$rcpt['campaign_id']]);
            $pid      = (int)($campaign['purpose_id'] ?? 0);
            if ($pid > 0) $purpose = crm_consent_purpose($pid);
        }
    } catch (\Throwable $e) { $error = true; }
}

if ($rcpt && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($scope, ['purpose', 'all'], true)) {
    $contact_id = (int)$rcpt['contact_id'];
    $ip         = $_SERVER['REMOTE_ADDR'] ?? null;
    $detail     = 'Kampania: ' . (string)($campaign['name'] ?? '?');

    try {
        // Czy ten odbiorca wypisywał się już wcześniej — decyduje o liczniku kampanii.
        $first_time = empty(db_one("SELECT unsubscribed_at FROM crm_campaign_recipients WHERE id=?", [(int)$rcpt['id']])['unsubscribed_at']);

        // Ślad przy odbiorcy kampanii — niezależnie od zakresu wypisania.
        db()->prepare("UPDATE crm_campaign_recipients SET unsubscribed_at=COALESCE(unsubscribed_at, datetime('now')) WHERE id=?")
            ->execute([(int)$rcpt['id']]);

        if ($scope === 'purpose' && $purpose) {
            crm_consent_withdraw($contact_id, (int)$purpose['id'], [
                'source' => 'link', 'source_detail' => $detail, 'ip' => $ip,
            ]);
            $done = 'purpose';
        } else {
            // Globalnie: flaga opt-out (respektowana przez kampanie i automatyzacje)
            // ORAZ wycofanie każdej udzielonej zgody, żeby rejestr zgadzał się z flagą.
            $c = db_one("SELECT email_opt_out FROM crm_contacts WHERE id=?", [$contact_id]);
            if ($c && (int)$c['email_opt_out'] === 0) {
                db()->prepare("UPDATE crm_contacts SET email_opt_out=1, email_opt_out_at=datetime('now') WHERE id=?")
                    ->execute([$contact_id]);
            }
            foreach (crm_consent_purposes(false) as $p) {
                if (crm_consent_has($contact_id, (int)$p['id'])) {
                    crm_consent_withdraw($contact_id, (int)$p['id'], [
                        'source' => 'link', 'source_detail' => $detail . ' (wypisanie ze wszystkich wysyłek)', 'ip' => $ip,
                    ]);
                }
            }
            $done = 'all';
        }

        // Licznik kampanii podnosimy raz — przy pierwszym wypisaniu tego odbiorcy.
        // Bez tego dwa kliknięcia w ten sam link (albo „najpierw cel, potem
        // wszystko") liczyłyby się jako dwa wypisania.
        if ($first_time) {
            db()->prepare("UPDATE crm_campaigns SET unsubscribed_count=unsubscribed_count+1 WHERE id=?")
                ->execute([(int)$rcpt['campaign_id']]);
        }
    } catch (\Throwable $e) { $error = true; }
}

$valid = ($rcpt !== null && !$error);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<title><?= $done ? 'Wypisano z wysyłek' : 'Wypisanie z wysyłek' ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<style>
body{font-family:system-ui,-apple-system,sans-serif;background:#F9FAFB;color:#1F2937;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:1rem}
.box{background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:2rem;max-width:460px;width:100%}
h1{font-size:1.1rem;margin:0 0 .6rem}
p{font-size:.9rem;color:#4B5563;margin:0 0 1rem;line-height:1.5}
.purpose{font-weight:600;color:#1F2937}
button{font:inherit;font-size:.88rem;font-weight:600;border-radius:8px;padding:.6rem 1rem;cursor:pointer;width:100%;border:1px solid transparent}
.primary{background:#0176D3;color:#fff}
.primary:hover{background:#0161B0}
.secondary{background:#fff;color:#4B5563;border-color:#D1D5DB;margin-top:.55rem}
.secondary:hover{background:#F9FAFB}
.ok{color:#059669;font-size:1.6rem;line-height:1;margin-bottom:.5rem}
.muted{font-size:.78rem;color:#9CA3AF;margin:1rem 0 0}
</style>
</head>
<body>
<div class="box">

<?php if (!$valid): ?>
  <h1>Link nieprawidłowy lub wygasł</h1>
  <p>Ten link wypisania nie jest już aktywny. Jeśli nadal chcesz zrezygnować
     z wiadomości, odpowiedz na dowolną z nich — zajmiemy się tym ręcznie.</p>

<?php elseif ($done === 'purpose'): ?>
  <div class="ok" aria-hidden="true">&check;</div>
  <h1>Wypisano</h1>
  <p>Nie będziesz już otrzymywać wiadomości w kategorii
     <span class="purpose"><?= h($purpose['nazwa']) ?></span>.
     Pozostałe rodzaje wiadomości od nas nie są objęte tym wypisaniem.</p>
  <form method="post">
    <input type="hidden" name="t" value="<?= h($token) ?>">
    <input type="hidden" name="scope" value="all">
    <button type="submit" class="secondary">Wypisz mnie ze wszystkich wysyłek</button>
  </form>

<?php elseif ($done === 'all'): ?>
  <div class="ok" aria-hidden="true">&check;</div>
  <h1>Wypisano ze wszystkich wysyłek</h1>
  <p>Nie będziesz już otrzymywać od nas wiadomości informacyjnych ani
     marketingowych. Nadal możemy się kontaktować w sprawach, które prowadzimy
     razem z Tobą — na przykład w sprawie zawartej umowy.</p>

<?php else: ?>
  <h1>Wypisanie z wysyłek</h1>
  <?php if ($purpose): ?>
  <p>Potwierdź, że nie chcesz już otrzymywać wiadomości w kategorii
     <span class="purpose"><?= h($purpose['nazwa']) ?></span>.</p>
  <form method="post">
    <input type="hidden" name="t" value="<?= h($token) ?>">
    <input type="hidden" name="scope" value="purpose">
    <button type="submit" class="primary">Potwierdzam — nie chcę tych wiadomości</button>
  </form>
  <form method="post">
    <input type="hidden" name="t" value="<?= h($token) ?>">
    <input type="hidden" name="scope" value="all">
    <button type="submit" class="secondary">Wypisz mnie ze wszystkich wysyłek</button>
  </form>
  <?php else: ?>
  <p>Potwierdź, że nie chcesz już otrzymywać od nas wiadomości informacyjnych
     i marketingowych.</p>
  <form method="post">
    <input type="hidden" name="t" value="<?= h($token) ?>">
    <input type="hidden" name="scope" value="all">
    <button type="submit" class="primary">Potwierdzam wypisanie</button>
  </form>
  <?php endif; ?>
  <p class="muted">Klikasz świadomie — dlatego prosimy o potwierdzenie.
     Automaty sprawdzające linki w poczcie nie wypiszą Cię przez przypadek.</p>
<?php endif; ?>

</div>
</body>
</html>
