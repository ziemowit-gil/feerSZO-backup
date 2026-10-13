<?php
/**
 * karty30/ti/dydaktyk/konsultacjeKlient.php — karta klienta konsultacji: podgląd (dane, rezerwacje, karty, kolejka,
 * konto, umowy PFRON), edycja i nowy klient (?nowy=1). Usunięcie — tylko kadra, tylko osoby bez zapisów/rozliczeń/
 * podpisanych kart. Logika: modules/ti_consult_clients. Odczyt i edycja w rejestrze dostępu RODO.
 */
require __DIR__ . '/_rezerwacje_nav.php';
require_once dirname(__DIR__, 3) . '/modules/ti_consult_clients/logic/consultClients.php';

$id = (int)($_GET['id'] ?? 0);
$isNew = !$id && !empty($_GET['nowy']);
$c = $id ? TiConsultClients::get($id) : null;
if ($id && !$c) { flash_set('danger', 'Nie znaleziono klienta.'); header('Location: konsultacjeKlienci.php'); exit; }
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = (string)($_POST['_op'] ?? '');
    if ($op === 'save') {
        $r = TiConsultClients::fromPost($_POST);
        $errors = $r['errors'];
        if (!$errors) {
            $kons = ($_POST['save_as'] ?? '') === 'kons';   // drugi przycisk: „Zapisz do konsultacji”
            if ($c) { TiConsultClients::update($id, $r['row'], $kons ? true : null); try { k30_log_access('client', $id, 'edit', $r['row']['name']); } catch (\Throwable $e) {} flash_set('success', $kons ? 'Dane zapisane — klient oznaczony jako klient konsultacji.' : 'Dane klienta zapisane.'); }
            else { $id = TiConsultClients::create($r['row'], $uid, $kons); flash_set('success', $kons ? 'Klient dodany i zapisany do konsultacji — jest już na liście klientów konsultacji.' : 'Klient dodany do kartoteki (bez oznaczenia konsultacji — pojawi się na liście po pierwszej rezerwacji albo w zakresie „cała kartoteka”).'); }
            header('Location: konsultacjeKlient.php?id=' . $id); exit;
        }
    } elseif ($op === 'delete' && $c && $staff) {
        $why = TiConsultClients::delete($id);
        if ($why === '') { flash_set('success', 'Klient usunięty z kartoteki.'); header('Location: konsultacjeKlienci.php'); exit; }
        flash_set('danger', 'Nie usunięto: ' . $why);
        header('Location: konsultacjeKlient.php?id=' . $id); exit;
    }
}
if ($c && $_SERVER['REQUEST_METHOD'] === 'GET' && empty($_GET['edytuj'])) { try { k30_log_access('client', $id, 'view', (string)$c['name']); } catch (\Throwable $e) {} }
$edit = $isNew || ($c && !empty($_GET['edytuj']));
$v = fn(string $k, $d = '') => $_POST[$k] ?? ($c[$k] ?? $d);
$P = $c ? TiConsultClients::profile($id) : [];

$RZ_TITLE = $isNew ? 'Nowy klient' : ($edit ? 'Edycja klienta' : 'Klient'); $RZ_CUR = $isNew ? 'konsultacjeKlient.php?nowy=1' : 'konsultacjeKlienci.php';
include __DIR__ . '/_rezerwacje_head.php';
?>
<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1100px">
<nav aria-label="Okruszki" class="small mb-2"><a href="konsultacjeKlienci.php">Klienci</a> › <?= $isNew ? 'Nowy klient' : h($c['name']) ?></nav>
<?= flash_html() ?>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<?php if ($edit): ?>
<h1 class="h4 fw-bold"><i class="bi bi-person<?= $isNew ? '-plus' : '-gear' ?> text-primary me-2" aria-hidden="true"></i><?= $isNew ? 'Nowy klient' : 'Edycja: ' . h($c['name']) ?></h1>
<form method="post" class="card border-0 shadow-sm"><div class="card-body">
  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="save">
  <div class="row g-3">
    <div class="col-md-6"><label class="form-label fw-semibold" for="c-name">Imię i nazwisko <span class="text-danger" aria-hidden="true">*</span></label><input class="form-control" id="c-name" name="name" required maxlength="200" value="<?= h((string)$v('name')) ?>"></div>
    <div class="col-md-3"><label class="form-label" for="c-dob">Data urodzenia</label><input class="form-control" type="date" id="c-dob" name="date_of_birth" value="<?= h((string)$v('date_of_birth')) ?>"></div>
    <div class="col-md-3"><label class="form-label" for="c-g">Płeć</label><select class="form-select" id="c-g" name="gender"><?php foreach (TiConsultClients::GENDER as $k => $l): ?><option value="<?= h($k) ?>"<?= (string)$v('gender') === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label" for="c-email">E-mail</label><input class="form-control" type="email" id="c-email" name="email" value="<?= h((string)$v('email')) ?>"></div>
    <div class="col-md-4"><label class="form-label" for="c-phone">Telefon</label><input class="form-control" type="tel" id="c-phone" name="phone" value="<?= h((string)$v('phone')) ?>"><div class="form-text">Na ten numer idą SMS-y z loginem, kodem i przypomnieniami.</div></div>
    <div class="col-md-4"><label class="form-label" for="c-pcm">Preferowany kontakt</label><select class="form-select" id="c-pcm" name="preferred_contact_method"><?php foreach (TiConsultClients::CONTACT as $k => $l): ?><option value="<?= h($k) ?>"<?= (string)$v('preferred_contact_method', 'email') === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-8"><label class="form-label" for="c-addr">Adres</label><input class="form-control" id="c-addr" name="address" value="<?= h((string)$v('address')) ?>"></div>
    <div class="col-md-4"><label class="form-label" for="c-pesel">PESEL</label><input class="form-control" id="c-pesel" name="pesel" inputmode="numeric" maxlength="11" value="<?= h((string)$v('pesel')) ?>"><div class="form-text">Potrzebny do umowy PFRON; inaczej opcjonalny.</div></div>
    <div class="col-md-4"><label class="form-label" for="c-st">Status</label><select class="form-select" id="c-st" name="status"><?php foreach (TiConsultClients::statuses() as $k => $s): ?><option value="<?= h($k) ?>"<?= (string)$v('status', 'enrolled') === $k ? ' selected' : '' ?>><?= h($s['label']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label" for="c-ah">Indywidualny limit bezpłatnych godzin</label><input class="form-control" type="number" step="0.5" min="0" id="c-ah" name="available_hours" value="<?= h((string)$v('available_hours', '0')) ?>"><div class="form-text">0 = limit ogólny z ustawień.</div></div>
    <div class="col-md-4 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" id="c-cons" name="consent" value="1"<?= $v('consent') ? ' checked' : '' ?>><label class="form-check-label" for="c-cons">Zgoda na przetwarzanie danych (RODO)</label></div></div>
    <div class="col-md-6"><label class="form-label" for="c-prob">Problem / potrzeba</label><textarea class="form-control" id="c-prob" name="problem" rows="3"><?= h((string)$v('problem')) ?></textarea></div>
    <div class="col-md-6"><label class="form-label" for="c-eq">Sprzęt i oprogramowanie</label><textarea class="form-control" id="c-eq" name="equipment" rows="3"><?= h((string)$v('equipment')) ?></textarea></div>
    <div class="col-12"><label class="form-label" for="c-notes">Notatki (widzi tylko kadra)</label><textarea class="form-control" id="c-notes" name="notes" rows="2"><?= h((string)$v('notes')) ?></textarea></div>
  </div>
  <div class="mt-3 d-flex flex-wrap gap-2 align-items-center">
    <button class="btn btn-outline-primary" name="save_as" value="plain"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz</button>
    <button class="btn btn-primary" name="save_as" value="kons"<?= $c && (int)($c['consult_client'] ?? 0) === 1 ? ' hidden' : '' ?>><i class="bi bi-clipboard2-check me-1" aria-hidden="true"></i>Zapisz do konsultacji</button>
    <a class="btn btn-outline-secondary" href="<?= $c ? 'konsultacjeKlient.php?id=' . $id : 'konsultacjeKlienci.php' ?>">Anuluj</a>
    <span class="small text-body-secondary"><?= $c && (int)($c['consult_client'] ?? 0) === 1 ? 'Klient konsultacji.' : '„Zapisz do konsultacji” — osoba od razu na liście klientów konsultacji; samo „Zapisz” — tylko do kartoteki.' ?></span>
  </div>
</div></form>

<?php else: $st = K30_CLIENT_STATUSES[$c['status']] ?? ['label' => $c['status'], 'color' => '#555', 'bg' => '#eee']; $acc = $P['account']; ?>
<div class="d-flex flex-wrap align-items-start gap-2 mb-3">
  <div><h1 class="h4 fw-bold mb-0"><?= h($c['name']) ?></h1>
    <span class="badge mt-1" style="background:<?= h($st['bg']) ?>;color:#111827;border:1px solid <?= h($st['color']) ?>"><?= h($st['label']) ?></span><?php if ((int)($c['consult_client'] ?? 0) === 1): ?> <span class="badge text-bg-info mt-1">klient konsultacji</span><?php endif; ?>
    <?php foreach ($P['groups'] as $g): ?><span class="badge text-bg-light border mt-1"><?= (int)$g['simple_mode'] ? 'SzK ' : 'grupa ' ?><?= h($g['name']) ?></span><?php endforeach; ?></div>
  <div class="ms-auto d-flex flex-wrap gap-2">
    <a class="btn btn-sm btn-primary" href="rezerwacja.php?nowa=1&amp;client_id=<?= $id ?>"><i class="bi bi-calendar-plus me-1" aria-hidden="true"></i>Nowa rezerwacja</a>
    <a class="btn btn-sm btn-outline-primary" href="konsultacja.php?nowa=1&amp;client_id=<?= $id ?>"><i class="bi bi-clipboard2-plus me-1" aria-hidden="true"></i>Nowa karta</a>
    <a class="btn btn-sm btn-outline-secondary" href="kolejka.php?add_client=<?= $id ?>"><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Do kolejki</a>
    <a class="btn btn-sm btn-outline-secondary" href="konsultacjePfron.php?client_id=<?= $id ?>"><i class="bi bi-building-fill-check me-1" aria-hidden="true"></i>Umowa PFRON</a>
    <a class="btn btn-sm btn-outline-secondary" href="konsultacjeKlient.php?id=<?= $id ?>&amp;edytuj=1"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edytuj</a>
  </div>
</div>
<div class="row g-3">
  <div class="col-lg-5">
    <section class="card border-0 shadow-sm mb-3" aria-labelledby="kd-h"><div class="card-header fw-semibold" id="kd-h">Dane</div><div class="card-body small"><dl class="row mb-0">
      <dt class="col-5 fw-normal text-body-secondary">E-mail</dt><dd class="col-7"><?= h($c['email'] ?: '—') ?></dd>
      <dt class="col-5 fw-normal text-body-secondary">Telefon</dt><dd class="col-7"><?= h($c['phone'] ?: '—') ?></dd>
      <dt class="col-5 fw-normal text-body-secondary">Preferowany kontakt</dt><dd class="col-7"><?= h(TiConsultClients::CONTACT[$c['preferred_contact_method'] ?? ''] ?? '—') ?></dd>
      <dt class="col-5 fw-normal text-body-secondary">Data urodzenia</dt><dd class="col-7"><?= $c['date_of_birth'] ? h(date('d.m.Y', strtotime((string)$c['date_of_birth']))) : '—' ?></dd>
      <dt class="col-5 fw-normal text-body-secondary">Adres</dt><dd class="col-7"><?= h($c['address'] ?: '—') ?></dd>
      <dt class="col-5 fw-normal text-body-secondary">PESEL</dt><dd class="col-7"><?= $c['pesel'] ? 'podany' : '—' ?></dd>
      <dt class="col-5 fw-normal text-body-secondary">Zgoda RODO</dt><dd class="col-7"><?= (int)$c['consent'] ? 'tak' : 'brak' ?></dd>
      <dt class="col-5 fw-normal text-body-secondary">Godziny (wykorzystane)</dt><dd class="col-7"><?= h(rtrim(rtrim(number_format((float)$c['used'], 2, ',', ''), '0'), ',')) ?> h<?= (float)$c['available_hours'] > 0 ? ' / limit ' . h(rtrim(rtrim(number_format((float)$c['available_hours'], 2, ',', ''), '0'), ',')) . ' h' : '' ?></dd>
      <?php if ($c['problem']): ?><dt class="col-5 fw-normal text-body-secondary">Problem / potrzeba</dt><dd class="col-7"><?= nl2br(h($c['problem'])) ?></dd><?php endif; ?>
      <?php if ($c['equipment']): ?><dt class="col-5 fw-normal text-body-secondary">Sprzęt</dt><dd class="col-7"><?= nl2br(h($c['equipment'])) ?></dd><?php endif; ?>
      <?php if ($c['notes']): ?><dt class="col-5 fw-normal text-body-secondary">Notatki (kadra)</dt><dd class="col-7"><?= nl2br(h($c['notes'])) ?></dd><?php endif; ?>
    </dl></div></section>
    <section class="card border-0 shadow-sm mb-3" aria-labelledby="ka-h"><div class="card-header fw-semibold" id="ka-h">Konto w panelu</div><div class="card-body small">
      <?php if ($acc): ?>login <span class="font-monospace"><?= h($acc['login']) ?></span> · <?= (int)$acc['is_active'] ? 'aktywne' : 'wyłączone' ?><?= (int)$acc['must_change_password'] ? ' · czeka na pierwsze logowanie' : '' ?><?= $acc['last_login'] ? ' · ostatnio ' . h(date('d.m.Y', strtotime((string)$acc['last_login']))) : '' ?>
        <div class="mt-1"><a href="konta.php?selected=<?= (int)$acc['id'] ?>">Zarządzaj kontem (hasło, tryb panelu, dokument)</a></div>
      <?php else: ?>Brak konta — powstanie samo przy pierwszej rezerwacji albo karcie (login „k” + cyfry, hasło SMS-em).<?php endif; ?>
    </div></section>
    <?php if ($P['pfron']): ?><section class="card border-0 shadow-sm mb-3" aria-labelledby="kp-h"><div class="card-header fw-semibold" id="kp-h">Umowy PFRON</div><ul class="list-group list-group-flush small">
      <?php foreach ($P['pfron'] as $pc): ?><li class="list-group-item"><a href="konsultacjePfron.php?id=<?= (int)$pc['id'] ?>"><?= h($pc['contract_number']) ?></a> · <?= h(K30_PFRON_CONTRACT_STATUSES[$pc['status']]['label'] ?? $pc['status']) ?> · <?= h(rtrim(rtrim(number_format((float)$pc['hours_used'], 2, ',', ''), '0'), ',')) ?> / <?= h(rtrim(rtrim(number_format((float)$pc['hours_limit'], 2, ',', ''), '0'), ',')) ?> h</li><?php endforeach; ?></ul></section><?php endif; ?>
  </div>
  <div class="col-lg-7">
    <section class="card border-0 shadow-sm mb-3" aria-labelledby="kr-h"><div class="card-header fw-semibold" id="kr-h">Rezerwacje terminów <span class="badge text-bg-secondary"><?= count($P['bookings']) ?></span></div>
      <?php if (!$P['bookings']): ?><div class="card-body small text-body-secondary">Brak rezerwacji.</div><?php else: ?><div class="table-responsive"><table class="table table-sm mb-0 small"><caption class="visually-hidden">Rezerwacje</caption>
        <thead><tr><th scope="col">Termin</th><th scope="col">Konsultant</th><th scope="col">Status</th><th scope="col" class="text-end">Kwota</th></tr></thead>
        <tbody><?php foreach ($P['bookings'] as $b): ?><tr><th scope="row" class="fw-normal text-nowrap"><a href="rezerwacja.php?id=<?= (int)$b['id'] ?>"><?= h(date('d.m.Y H:i', strtotime((string)$b['start_time']))) ?></a></th><td><?= h($b['consultant'] ?: '—') ?></td><td><?= rz_badge((string)$b['status']) ?></td><td class="text-end"><?= (float)$b['amount_due'] > 0 ? h(number_format((float)$b['amount_due'], 2, ',', ' ')) . ' zł' : h(K30_BILLING_TYPES[$b['billing_type']]['label'] ?? '') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
    <section class="card border-0 shadow-sm mb-3" aria-labelledby="kc-h"><div class="card-header fw-semibold" id="kc-h">Karty konsultacji <span class="badge text-bg-secondary"><?= count($P['cards']) ?></span></div>
      <?php if (!$P['cards']): ?><div class="card-body small text-body-secondary">Brak kart.</div><?php else: ?><ul class="list-group list-group-flush small"><?php foreach ($P['cards'] as $k): ?><li class="list-group-item d-flex flex-wrap gap-2 align-items-center"><a class="font-monospace" href="konsultacja.php?id=<?= (int)$k['id'] ?>"><?= h($k['card_no']) ?></a><span><?= h(date('d.m.Y', strtotime((string)$k['consultation_datetime']))) ?><?= $k['consultant'] ? ' · ' . h($k['consultant']) : '' ?></span><?= TiConsultations::stageBadge($k) ?></li><?php endforeach; ?></ul><?php endif; ?></section>
    <?php if ($P['queue']): ?><section class="card border-0 shadow-sm mb-3" aria-labelledby="kq-h"><div class="card-header fw-semibold" id="kq-h">Kolejka oczekujących</div><ul class="list-group list-group-flush small"><?php foreach ($P['queue'] as $q): ?><li class="list-group-item"><?= h(date('d.m.Y', strtotime((string)$q['created_at']))) ?> · <?= h(K30_WAIT_PRIORITIES[$q['priority']]['label'] ?? $q['priority']) ?> · <?= h(K30_WAIT_STATUSES[$q['status']]['label'] ?? $q['status']) ?><?= $q['reason'] ? ' — ' . h($q['reason']) : '' ?></li><?php endforeach; ?></ul></section><?php endif; ?>
    <?php if ($staff): ?><form method="post" class="mt-2" onsubmit="return confirm('Usunąć klienta z kartoteki? Rezerwacje, robocze karty i wpisy kolejki tej osoby zostaną usunięte.')"><input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="delete"><button class="btn btn-sm btn-link text-danger px-0">Usuń klienta</button></form><?php endif; ?>
  </div>
</div>
<?php endif; ?>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
