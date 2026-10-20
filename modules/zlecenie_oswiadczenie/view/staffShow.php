<?php
/**
 * modules/zlecenie_oswiadczenie/view/staffShow.php — karta pakietu oświadczeń dla pracownika: odpowiedzi, dokumenty, link.
 * Wymaga: $osw, $contract (null — jeszcze bez umowy), $docs, $a, $account (konto oświadczeń albo null), $szoUser (konto SZO albo null),
 * $candidates (umowy do powiązania).
 */
$PAGE_TITLE = 'Oświadczenia zleceniobiorcy';
include dirname(__DIR__, 3) . '/includes/header.php';
$_oswO = $osw; $_oswDocs = $docs; $_oswBack = '';
$_link = $osw['token'] && (!$osw['token_expires'] || $osw['token_expires'] >= date('Y-m-d H:i:s')) ? route('osw.t.home', ['token' => $osw['token']]) : '';
[$_sl, $_sc] = OswService::summaryStatus($osw, $docs);
?>
<nav aria-label="Ścieżka" class="small mb-2"><a href="<?= h(route('osw.index')) ?>">Oświadczenia zleceniobiorców</a> › <?= h($a['nazwisko_imiona'] ?? $osw['invite_name'] ?? '') ?></nav>
<h1 class="h4"><?= h($a['nazwisko_imiona'] ?? $osw['invite_name'] ?? 'Oświadczenia') ?> <span class="badge text-bg-<?= h($_sc) ?> align-middle"><?= h($_sl) ?></span></h1>
<?= flash_html() ?>
<p class="small">
  <?php if ($contract): ?>Umowa: <a href="<?= h(rtrim(APP_URL, '/') . '/contracts/zlecenie/view.php?id=' . (int)$contract['id'] . '&tab=oswiadczenie') ?>"><?= h($contract['numer_umowy']) ?></a>
    (<?= h($contract['status']) ?>)<?= $osw['draft_created'] ? ' — projekt utworzony z oświadczeń' : '' ?>.
  <?php elseif (($osw['source'] ?? '') === 'public'): ?>Z publicznego kreatora — jeszcze bez umowy.
  <?php else: ?>Bez umowy — po złożeniu oświadczeń powstanie projekt umowy zlecenia.<?php endif; ?>
  <?php if ($osw['submitted_at']): ?> Złożono <?= h(date('d.m.Y H:i', (int)strtotime((string)$osw['submitted_at']))) ?><?= $osw['submitted_ip'] ? ', IP ' . h($osw['submitted_ip']) : '' ?>.<?php endif; ?>
</p>

<?php if ($account): ?>
<p class="small">Konto oświadczeń: <strong><?= h($account['email']) ?></strong> —
  <?= $osw['account_confirmed_at'] ? 'potwierdzone ' . h(date('d.m.Y H:i', (int)strtotime((string)$osw['account_confirmed_at']))) . (empty($account['password_hash']) ? '' : ', hasło ustawione') : 'link wysłany ' . h(date('d.m.Y H:i', (int)strtotime((string)$osw['account_mail_at']))) . ', czeka na kliknięcie' ?>.</p>
<div class="card border-0 shadow-sm mb-3" id="konto-sluzbowe"><div class="card-header bg-transparent fw-semibold">Konto w SZO</div>
  <div class="card-body small">
    <?php if (!empty($account['work_email'])): ?>
    <p class="mb-0">Przepięte na konto służbowe <strong><?= h($account['work_email']) ?></strong>
      <?= $account['work_linked_at'] ? '(' . h(date('d.m.Y H:i', (int)strtotime((string)$account['work_linked_at']))) . ')' : '' ?> —
      logowanie przez Microsoft 365, pełny panel<?= !empty($szoUser['microsoft_id']) ? ', pierwsze logowanie Microsoft wykonane' : ', czeka na pierwsze logowanie przez Microsoft' ?>.</p>
    <?php elseif ($szoUser): ?>
    <p>Konto lokalne SZO <strong><?= h($szoUser['email']) ?></strong> (#<?= (int)$szoUser['id'] ?>)<?= ($szoUser['portal_scope'] ?? '') === 'osw_only'
        ? ' — <span class="badge text-bg-secondary">tylko podgląd oświadczenia</span>' : ' — istniejące konto SZO, oświadczenie przypięte do niego' ?>.</p>
    <?php if (($szoUser['portal_scope'] ?? '') === 'osw_only' && can_edit()): ?>
    <form method="post" action="<?= h(route('osw.staffWork', ['id' => (int)$osw['id']])) ?>" class="row g-2 align-items-end"
          onsubmit="return confirm('Przepiąć konto na konto służbowe? Osoba będzie logować się wyłącznie przez Microsoft 365 i dostanie pełny panel.')">
      <?= Router::csrfField() ?>
      <div class="col-sm-8"><label class="form-label mb-1" for="osw-work">Konto służbowe Microsoft 365</label>
        <input class="form-control form-control-sm" id="osw-work" name="work_email" type="email" required placeholder="imie.nazwisko<?= h(OswAccount::WORK_DOMAIN) ?>"></div>
      <div class="col-sm-4"><button class="btn btn-sm btn-primary w-100">Przepnij na konto Microsoft</button></div>
      <p class="text-muted mb-0 mt-1">Ten sam rekord i historia; hasło lokalne zostanie wyłączone. Jeśli konto służbowe już istnieje w SZO, oświadczenia przejdą do niego, a konto lokalne zostanie wyłączone.</p>
    </form>
    <?php endif; ?>
    <?php else: ?>
    <p class="mb-0 text-muted">Konto SZO powstanie, gdy osoba kliknie link z e-maila i ustawi hasło.</p>
    <?php endif; ?>
  </div></div>
<?php endif; ?>

<?php if (!$contract && $osw['status'] === 'zlozony' && can_edit()): ?>
<div class="card border-0 shadow-sm mb-3"><div class="card-header bg-transparent fw-semibold">Umowa zlecenia</div>
  <div class="card-body small">
    <form method="post" action="<?= h(route('osw.staffLink', ['id' => (int)$osw['id']])) ?>" class="row g-2 align-items-end mb-2">
      <?= Router::csrfField() ?>
      <?php if ($candidates): ?>
      <div class="col-sm-6"><label class="form-label small" for="osw-c">Umowy tej osoby (PESEL / imię i nazwisko)</label>
        <select class="form-select form-select-sm" id="osw-c" name="contract_id"><option value="">— wybierz —</option>
        <?php foreach ($candidates as $_c): ?><option value="<?= (int)$_c['id'] ?>"><?= h($_c['numer_umowy'] . ' · ' . $_c['imie_nazwisko'] . ' · ' . $_c['status']) ?></option><?php endforeach; ?></select></div>
      <?php endif; ?>
      <div class="col-sm-4"><label class="form-label small" for="osw-nr"><?= $candidates ? 'albo numer umowy' : 'Numer umowy zlecenia' ?></label>
        <input class="form-control form-control-sm" id="osw-nr" name="numer" placeholder="np. ZLE/0012/2026"></div>
      <div class="col-sm-2"><button class="btn btn-sm btn-primary w-100">Powiąż</button></div>
    </form>
    <form method="post" action="<?= h(route('osw.staffProject', ['id' => (int)$osw['id']])) ?>">
      <?= Router::csrfField() ?>
      <button class="btn btn-sm btn-outline-primary">Utwórz projekt umowy zlecenia z tych danych</button>
      <span class="text-body-secondary ms-1">status „projekt”, dane osoby, adres, urząd skarbowy, rachunek</span>
    </form>
  </div></div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3"><div class="card-header bg-transparent fw-semibold">Dokumenty</div>
  <div class="card-body"><?php include __DIR__ . '/_staffDocs.php'; ?></div></div>

<?php if ($_link && empty($osw['account_confirmed_at'])): ?>
<div class="card border-0 shadow-sm mb-3"><div class="card-body small">
  <div class="input-group input-group-sm">
    <label class="input-group-text" for="osw-link">Link zleceniobiorcy</label>
    <input class="form-control font-monospace" id="osw-link" value="<?= h($_link) ?>" readonly>
    <button class="btn btn-outline-primary" type="button" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('osw-link').value);this.textContent='Skopiowano'">Kopiuj</button>
  </div>
  <div class="text-body-secondary mt-1">Ważny do <?= h(date('d.m.Y', (int)strtotime((string)$osw['token_expires']))) ?>.</div>
</div></div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3"><div class="card-header bg-transparent fw-semibold">Odpowiedzi z kreatora<?= $osw['status'] === 'roboczy' ? ' (szkic, krok ' . (int)$osw['step'] . '/6)' : '' ?></div>
  <div class="card-body">
  <?php foreach (OswForm::summary($a) as $_s => $_rows): ?>
    <h2 class="h6 mt-2"><?= $_s ?>. <?= h(OswForm::STEPS[$_s][1]) ?></h2>
    <dl class="row small mb-2"><?php foreach ($_rows as [$_l, $_v]): ?><dt class="col-sm-5 fw-normal text-body-secondary"><?= h($_l) ?></dt><dd class="col-sm-7 mb-1"><?= h($_v !== '' ? $_v : '—') ?></dd><?php endforeach; ?></dl>
  <?php endforeach; ?>
  <?php if ($osw['status'] !== 'roboczy'): ?><p class="small text-body-secondary mb-0">Podpowiedź ZUS: <?= h(OswForm::zusHint($a)) ?></p><?php endif; ?>
  </div></div>
<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>
