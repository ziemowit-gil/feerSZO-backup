<?php
/**
 * karty30/ti/dydaktyk/konsultacjeUstawienia.php — Ustawienia konsultacji (kierownik): link do Polityki Prywatności
 * i regulaminu, treść zgody RODO i akceptacji regulaminu ({polityka}, {regulamin} → linki), słowniki danych zgłoszenia
 * na karcie konsultacji (zmiana nazw, dodawanie, wyłączanie — wyłączone zostają opisane na starszych kartach).
 * Słowniki są w Słowniku Centralnym (kategoria „Konsultacje TI”, TiConsultations::DICT_CENTRAL) — ten ekran je tam zapisuje.
 */
require __DIR__ . '/_rezerwacje_nav.php';
if (!$staff) { flash_set('warning', 'Ustawienia konsultacji zmienia kierownik.'); header('Location: rezerwacje.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = (string)($_POST['_op'] ?? '');
    if ($op === 'consents') {
        $err = [];
        foreach (['ti_consult_privacy_url' => 'privacy_url', 'ti_consult_terms_url' => 'terms_url'] as $key => $f) {
            $u = trim((string)($_POST[$f] ?? ''));
            if ($u !== '' && !preg_match('#^https?://\S+$#i', $u)) { $err[] = 'Adres musi zaczynać się od http:// lub https://.'; continue; }
            org_setting_set($key, $u);
        }
        org_setting_set('ti_consult_rodo_text', mb_substr(trim((string)($_POST['rodo_text'] ?? '')), 0, 1000));
        org_setting_set('ti_consult_terms_text', mb_substr(trim((string)($_POST['terms_text'] ?? '')), 0, 1000));
        flash_set($err ? 'warning' : 'success', $err ? implode(' ', array_unique($err)) : 'Zapisano linki i treść zgód. Nowe karty zapiszą przy zgodzie aktualny tekst.');
    } elseif ($op === 'invite') {
        // Konsultant zewnętrzny: link z zaproszeniem, osoba sama zakłada konto (tylko tryb Konsultacje)
        require_once dirname(__DIR__, 3) . '/modules/ti_consultant_access/logic/consultantAccess.php';
        $r = TiConsultantAccess::inviteCreate((string)($_POST['name'] ?? ''), (string)($_POST['email'] ?? ''), $uid, tiPanelUrl('dydaktyk', ''));
        if (!$r['ok']) flash_set('danger', (string)$r['error']);
        else {
            $sent = TiConsultantAccess::inviteMail(strtolower(trim((string)$_POST['email'])), trim((string)$_POST['name']), (string)$r['url'], $byName);
            $_SESSION['ti_invite_link'] = (string)$r['url'];
            flash_set('success', 'Zaproszenie utworzone' . ($sent ? ' i wysłane e-mailem.' : ' — e-maila nie udało się wysłać, przekaż link ręcznie.'));
        }
    } elseif ($op === 'invite_revoke') {
        require_once dirname(__DIR__, 3) . '/modules/ti_consultant_access/logic/consultantAccess.php';
        TiConsultantAccess::inviteRevoke((int)($_POST['id'] ?? 0), $uid);
        flash_set('success', 'Zaproszenie cofnięte.');
    } elseif ($op === 'dicts') {
        try { TiConsultations::saveDicts((array)($_POST['d'] ?? [])); flash_set('success', 'Zapisano słowniki zgłoszenia w Słowniku Centralnym.'); }
        catch (\Throwable $e) { flash_set('error', 'Nie zapisano słowników: ' . $e->getMessage()); }
    } elseif ($op === 'dicts_reset') {
        try { TiConsultations::resetDicts(); flash_set('success', 'Przywrócono domyślne nazwy pozycji; pozycje dodane później są wyłączone.'); }
        catch (\Throwable $e) { flash_set('error', 'Nie przywrócono słowników: ' . $e->getMessage()); }
    }
    header('Location: konsultacjeUstawienia.php'); exit;
}
$all = TiConsultations::dicts(); $act = TiConsultations::dicts(true);
require_once dirname(__DIR__, 3) . '/modules/ti_consultant_access/logic/consultantAccess.php';
$invites = TiConsultantAccess::invites();
$inviteLink = (string)($_SESSION['ti_invite_link'] ?? ''); unset($_SESSION['ti_invite_link']);   // pokazywany raz
$RZ_TITLE = 'Ustawienia konsultacji'; $RZ_CUR = 'konsultacjeUstawienia.php';
include __DIR__ . '/_rezerwacje_head.php';
?>
<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1000px">
<h1 class="h4 fw-bold mb-2"><i class="bi bi-gear text-primary me-2" aria-hidden="true"></i>Ustawienia konsultacji</h1>
<?php rz_nav('ustawienia'); ?>
<?= flash_html() ?>

<form method="post" class="card border-0 shadow-sm mb-4"><div class="card-body">
  <h2 class="h6 fw-bold">Zgody i linki</h2>
  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="consents">
  <div class="row g-2 mb-2">
    <div class="col-md-6"><label class="form-label small mb-0" for="us-pp">Link do Polityki Prywatności</label><input class="form-control form-control-sm" type="url" id="us-pp" name="privacy_url" placeholder="https://…" value="<?= h(org_setting('ti_consult_privacy_url')) ?>"></div>
    <div class="col-md-6"><label class="form-label small mb-0" for="us-tu">Link do regulaminu konsultacji</label><input class="form-control form-control-sm" type="url" id="us-tu" name="terms_url" placeholder="https://…" value="<?= h(org_setting('ti_consult_terms_url')) ?>"></div>
  </div>
  <label class="form-label small mb-0" for="us-rodo">Treść zgody RODO</label>
  <textarea class="form-control form-control-sm mb-1" id="us-rodo" name="rodo_text" rows="2" placeholder="<?= h(TiConsultations::RODO_DEFAULT) ?>"><?= h(org_setting('ti_consult_rodo_text')) ?></textarea>
  <label class="form-label small mb-0" for="us-terms">Treść akceptacji regulaminu</label>
  <textarea class="form-control form-control-sm mb-1" id="us-terms" name="terms_text" rows="2" placeholder="<?= h(TiConsultations::TERMS_DEFAULT) ?>"><?= h(org_setting('ti_consult_terms_text')) ?></textarea>
  <div class="form-text mb-2">Puste pole = tekst domyślny. <code>{polityka}</code> i <code>{regulamin}</code> zamieniają się na linki powyżej. Przy każdej zgodzie karta zapisuje tekst obowiązujący w chwili wyrażenia zgody.</div>
  <p class="small mb-2"><strong>Podgląd:</strong> <?= TiConsultations::consentHtml('rodo') ?> · <?= TiConsultations::consentHtml('terms') ?></p>
  <button class="btn btn-sm btn-primary">Zapisz</button>
</div></form>

<div class="card border-0 shadow-sm mb-4"><div class="card-body">
  <h2 class="h6 fw-bold">Konsultanci zewnętrzni — zaproszenia</h2>
  <p class="small text-body-secondary">Osoba spoza organizacji (bez Microsoft 365) dostaje e-mailem link ważny <?= TiConsultantAccess::INVITE_DAYS ?> dni,
    sama ustawia hasło i loguje się na stronie logowania TI adresem e-mail + kodem z aplikacji. Konto widzi <strong>tylko Konsultacje</strong>.
    Konsultanci z kadry wchodzą przez Microsoft 365 albo ustawiają sobie <a href="kontoZastepcze.php">login zastępczy</a>.</p>
  <?php if ($inviteLink !== ''): ?>
  <div class="alert alert-info small py-2" role="status">Link zaproszenia (pokazujemy raz — skopiuj, jeśli e-mail nie dotrze):<br>
    <input class="form-control form-control-sm font-monospace mt-1" readonly value="<?= h($inviteLink) ?>" onfocus="this.select()" aria-label="Link zaproszenia"></div>
  <?php endif; ?>
  <form method="post" class="row g-2 align-items-end mb-3">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="invite">
    <div class="col-md-5"><label class="form-label small mb-0" for="inv-name">Imię i nazwisko</label><input class="form-control form-control-sm" id="inv-name" name="name" required maxlength="120"></div>
    <div class="col-md-5"><label class="form-label small mb-0" for="inv-email">E-mail</label><input class="form-control form-control-sm" type="email" id="inv-email" name="email" required maxlength="190"></div>
    <div class="col-md-2"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-send me-1" aria-hidden="true"></i>Zaproś</button></div>
  </form>
  <?php if ($invites): ?>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0 small">
    <thead><tr><th scope="col">Osoba</th><th scope="col">Wysłane</th><th scope="col">Stan</th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead>
    <tbody>
    <?php foreach ($invites as $iv_): $exp = strtotime((string)$iv_['expires_at']) < time();
      $state = $iv_['used_at'] ? 'konto założone ' . date('d.m.Y', strtotime((string)$iv_['used_at'])) : ($iv_['revoked_at'] ? 'cofnięte' : ($exp ? 'wygasło' : 'czeka do ' . date('d.m.Y', strtotime((string)$iv_['expires_at'])))); ?>
      <tr><td><?= h($iv_['name']) ?><div class="text-body-secondary"><?= h($iv_['email']) ?></div></td>
        <td><?= h(date('d.m.Y', strtotime((string)$iv_['created_at']))) ?><?= $iv_['by_name'] ? '<div class="text-body-secondary">' . h($iv_['by_name']) . '</div>' : '' ?></td>
        <td><?= h($state) ?></td>
        <td class="text-end"><?php if (!$iv_['used_at'] && !$iv_['revoked_at'] && !$exp): ?>
          <form method="post" onsubmit="return confirm('Cofnąć zaproszenie? Link przestanie działać.')"><input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
            <input type="hidden" name="_op" value="invite_revoke"><input type="hidden" name="id" value="<?= (int)$iv_['id'] ?>"><button class="btn btn-sm btn-outline-danger py-0">Cofnij</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div></div>

<form method="post" class="card border-0 shadow-sm mb-4"><div class="card-body">
  <h2 class="h6 fw-bold">Słowniki zgłoszenia na karcie konsultacji</h2>
  <p class="small text-body-secondary">Zmień nazwę, odznacz „aktywna”, aby ukryć pozycję w formularzu (na starszych kartach i wydrukach zostaje opisana), dopisz nowe pozycje w pustych wierszach.
    Listy są w <strong>Słowniku Centralnym</strong> SZO (Admin › Słownik Centralny › Konsultacje TI) — zmiany stąd trafiają tam, a tam można też zmienić kolejność i okres obowiązywania pozycji.</p>
  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="dicts">
  <div class="row g-3">
  <?php foreach (TiConsultations::DICT_NAMES as $d => $dn): $i = 0; ?>
    <fieldset class="col-md-6"><legend class="small fw-bold mb-1"><?= h($dn) ?></legend>
      <?php foreach ($all[$d] as $k => $l): ?>
      <div class="input-group input-group-sm mb-1">
        <div class="input-group-text"><input class="form-check-input mt-0" type="checkbox" name="d[<?= h($d) ?>][<?= $i ?>][on]" value="1" aria-label="Aktywna: <?= h($l) ?>"<?= isset($act[$d][$k]) ? ' checked' : '' ?>></div>
        <input type="hidden" name="d[<?= h($d) ?>][<?= $i ?>][key]" value="<?= h($k) ?>">
        <input class="form-control" name="d[<?= h($d) ?>][<?= $i ?>][label]" value="<?= h($l) ?>" aria-label="Nazwa pozycji (<?= h($dn) ?>)">
      </div>
      <?php $i++; endforeach; ?>
      <?php for ($n = 0; $n < 2; $n++, $i++): ?>
      <div class="input-group input-group-sm mb-1">
        <div class="input-group-text"><input class="form-check-input mt-0" type="checkbox" name="d[<?= h($d) ?>][<?= $i ?>][on]" value="1" checked aria-label="Aktywna — nowa pozycja"></div>
        <input class="form-control" name="d[<?= h($d) ?>][<?= $i ?>][label]" placeholder="nowa pozycja…" aria-label="Nowa pozycja (<?= h($dn) ?>)">
      </div>
      <?php endfor; ?>
    </fieldset>
  <?php endforeach; ?>
  </div>
  <div class="d-flex gap-2 mt-2"><button class="btn btn-sm btn-primary">Zapisz słowniki</button></div>
</div></form>
<form method="post" onsubmit="return confirm('Przywrócić domyślne słowniki? Domyślne pozycje wrócą do pierwotnych nazw, a pozycje dodane później zostaną wyłączone (na starszych kartach zostają opisane).')">
  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="dicts_reset">
  <button class="btn btn-sm btn-link text-danger px-0">Przywróć domyślne słowniki</button>
</form>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
