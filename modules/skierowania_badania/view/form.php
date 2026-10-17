<?php
/**
 * modules/skierowania_badania/view/form.php — formularz nowego skierowania i karta istniejącego (edycja, PDF, EZD, wynik).
 * Zmienne: $s (dane / wiersz; bez 'id' = nowe), $c (umowa), $history (skierowania tej umowy).
 */
$isNew  = empty($s['id']);
$type   = (string)$s['contract_type'];
$cid    = (int)$s['contract_id'];
$locked = !$isNew && $s['status'] === 'anulowane';
$v      = fn($k) => h((string)($s[$k] ?? ''));
$fmt    = fn($d) => $d ? date('d.m.Y', strtotime((string)$d)) : '—';
$saved  = [];   // [grupa][nazwa] => wielkość
$own    = [];   // [grupa] => linie „nazwa | wielkość”
foreach (skbFactors($s) as $fa) {
    if (!empty($fa['own'])) $own[$fa['g']][] = $fa['name'] . ($fa['wielkosc'] !== '' ? ' | ' . $fa['wielkosc'] : '');
    else $saved[$fa['g']][$fa['name']] = (string)$fa['wielkosc'];
}
$nrUmowy = skbContractNumber($c);
$PAGE_TITLE = $isNew ? 'Nowe skierowanie na badania' : 'Skierowanie ' . $s['numer'];
include dirname(__DIR__, 3) . '/includes/header.php';
?>
<nav aria-label="Ścieżka"><ol class="breadcrumb small"><li class="breadcrumb-item"><a href="<?= h(route('skb.index')) ?>">Skierowania na badania</a></li><li class="breadcrumb-item active" aria-current="page"><?= h($isNew ? 'Nowe' : $s['numer']) ?></li></ol></nav>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
  <h4 class="mb-0"><i class="bi bi-heart-pulse text-primary" aria-hidden="true"></i> <?= h($PAGE_TITLE) ?>
    <?php if (!$isNew): [$sl, $sc] = SKB_STATUSY[$s['status']]; ?><span class="badge text-bg-<?= h($sc) ?> fs-6 align-middle"><?= h($sl) ?></span><?php endif; ?></h4>
  <?php if (!$isNew): ?>
  <div class="d-flex flex-wrap gap-2">
    <a class="btn btn-sm btn-outline-primary" href="<?= h(route('skb.pdf', ['id' => $s['id']])) ?>" target="_blank" rel="noopener"><i class="bi bi-filetype-pdf" aria-hidden="true"></i> PDF (2 egz.)</a>
    <?php if (skbEzdActive() && !$locked): ?>
    <form method="post" action="<?= h(route('skb.ezd', ['id' => $s['id']])) ?>"><?= csrf_field() ?>
      <button class="btn btn-sm btn-outline-success"><i class="bi bi-folder-plus" aria-hidden="true"></i> <?= $s['ezd_pismo_id'] ? 'Zarejestruj nową wersję w EZD' : 'Zarejestruj w EZD' ?></button></form>
    <?php endif; ?>
    <?php if ($s['ezd_sprawa_id']): ?><a class="btn btn-sm btn-success" href="<?= h(skbEzdUrl((int)$s['ezd_sprawa_id'])) ?>" target="_blank" rel="noopener"><i class="bi bi-folder2-open" aria-hidden="true"></i> Koszulka EZD</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<p class="text-body-secondary small mb-3">
  <?= h(SKB_TYPY[$type] ?? $type) ?><?= $nrUmowy !== '' ? ' nr ' . h($nrUmowy) : '' ?> —
  <a href="<?= h(skbContractUrl($type, $cid)) ?>"><?= h((string)($c['imie_nazwisko'] ?? '')) ?></a>
  <?php if (!$isNew): ?> · wystawiono <?= $fmt($s['data_wystawienia']) ?> (<?= h($s['created_name']) ?>)<?php if ($s['ezd_at']): ?> · w EZD od <?= h(substr((string)$s['ezd_at'], 0, 16)) ?><?php endif; ?><?php endif; ?>
</p>
<?= flash_html() ?>

<form method="post" action="<?= h($isNew ? route('skb.store') : route('skb.update', ['id' => $s['id']])) ?>" id="skb-form">
  <?= csrf_field() ?>
  <input type="hidden" name="contract_type" value="<?= h($type) ?>"><input type="hidden" name="contract_id" value="<?= $cid ?>">
  <fieldset <?= $locked ? 'disabled' : '' ?>>
  <div class="card mb-3"><div class="card-header">Osoba kierowana</div><div class="card-body row g-3">
    <div class="col-md-4"><span class="form-label d-block">Rodzaj badania</span>
      <div class="btn-group" role="group" aria-label="Rodzaj badania">
      <?php foreach (SKB_RODZAJE as $k => $l): ?>
        <input type="radio" class="btn-check" name="rodzaj" id="r-<?= $k ?>" value="<?= $k ?>" <?= $s['rodzaj'] === $k ? 'checked' : '' ?>><label class="btn btn-outline-primary btn-sm" for="r-<?= $k ?>"><?= h($l) ?></label>
      <?php endforeach; ?></div></div>
    <div class="col-md-8"><label class="form-label" for="s-n">Imię i nazwisko *</label><input id="s-n" class="form-control" name="imie_nazwisko" value="<?= $v('imie_nazwisko') ?>" required></div>
    <div class="col-md-4"><label class="form-label" for="s-p">PESEL</label><input id="s-p" class="form-control" name="pesel" value="<?= $v('pesel') ?>" inputmode="numeric" maxlength="11" autocomplete="off"></div>
    <div class="col-md-8"><label class="form-label" for="s-d">Dokument tożsamości (gdy brak PESEL)</label><input id="s-d" class="form-control" name="dokument" value="<?= $v('dokument') ?>" placeholder="nazwa, seria i numer dokumentu"></div>
    <div class="col-12"><label class="form-label" for="s-a">Adres zamieszkania</label><input id="s-a" class="form-control" name="adres" value="<?= $v('adres') ?>"></div>
    <div class="col-12"><span class="form-label d-block">Osoba</span>
      <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="podejmujacy" id="pj1" value="1" <?= (int)$s['podejmujacy'] === 1 ? 'checked' : '' ?>><label class="form-check-label" for="pj1">podejmuje czynności (badanie przed rozpoczęciem)</label></div>
      <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="podejmujacy" id="pj0" value="0" <?= (int)$s['podejmujacy'] === 0 ? 'checked' : '' ?>><label class="form-check-label" for="pj0">już wykonuje czynności</label></div></div>
  </div></div>

  <div class="card mb-3"><div class="card-header">Stanowisko i warunki</div><div class="card-body row g-3">
    <div class="col-12"><label class="form-label" for="s-s">Stanowisko / rodzaj czynności *</label><input id="s-s" class="form-control" name="stanowisko" value="<?= $v('stanowisko') ?>" required></div>
    <div class="col-12"><label class="form-label" for="s-o">Opis warunków wykonywania czynności</label><textarea id="s-o" class="form-control" rows="3" name="opis_warunkow"><?= $v('opis_warunkow') ?></textarea></div>
    <div class="col-12"><label class="form-label" for="s-pl">Placówka medycyny pracy</label><input id="s-pl" class="form-control" name="placowka" value="<?= $v('placowka') ?>" placeholder="opcjonalnie — trafi do nagłówka „Do:”"></div>
  </div></div>

  <div class="card mb-3"><div class="card-header d-flex flex-wrap align-items-center gap-2">
      <span>Czynniki szkodliwe i uciążliwe — zaznaczono: <strong id="skb-count" aria-live="polite">0</strong></span>
      <span class="ms-auto small">Wstaw zestaw:</span>
      <?php foreach (SKB_ZESTAWY as $k => [$l]): ?><button type="button" class="btn btn-sm btn-outline-secondary" data-skb-set="<?= h($k) ?>"><?= h($l) ?></button><?php endforeach; ?>
    </div>
    <div class="card-body">
      <p class="small text-body-secondary">Przy zaznaczonym czynniku wpisz wielkość narażenia lub aktualny wynik pomiaru (jeśli są). Grupa bez zaznaczeń drukuje się jako „brak”. Inne czynniki: jedna linia = jeden czynnik, opcjonalnie „nazwa | wielkość”.</p>
      <?php foreach (SKB_CZYNNIKI as $g => [$label, $list]): $any = !empty($saved[$g]) || !empty($own[$g]); ?>
      <details class="mb-2 border rounded" <?= $any ? 'open' : '' ?>>
        <summary class="px-2 py-1 fw-semibold"><?= h($g . '. ' . $label) ?></summary>
        <div class="px-2 pb-2">
        <?php foreach ($list as $i => $name): $on = array_key_exists($name, $saved[$g] ?? []); ?>
          <div class="row g-1 align-items-center py-1 border-bottom">
            <div class="col-md-7 form-check ps-5"><input class="form-check-input skb-f" type="checkbox" name="czynnik[<?= $g ?>][<?= $i ?>]" value="1" id="cz-<?= $g ?>-<?= $i ?>" data-g="<?= $g ?>" data-name="<?= h($name) ?>" <?= $on ? 'checked' : '' ?>>
              <label class="form-check-label" for="cz-<?= $g ?>-<?= $i ?>"><?= h($name) ?></label></div>
            <div class="col-md-5"><label class="visually-hidden" for="wz-<?= $g ?>-<?= $i ?>">Wielkość narażenia: <?= h($name) ?></label>
              <input class="form-control form-control-sm" id="wz-<?= $g ?>-<?= $i ?>" name="wielkosc[<?= $g ?>][<?= $i ?>]" value="<?= h($saved[$g][$name] ?? '') ?>" placeholder="wielkość narażenia / wynik pomiaru"></div>
          </div>
        <?php endforeach; ?>
          <label class="form-label small mt-2" for="in-<?= $g ?>">Inne czynniki w tej grupie</label>
          <textarea class="form-control form-control-sm skb-own" id="in-<?= $g ?>" rows="2" name="inne[<?= $g ?>]"><?= h(implode("\n", $own[$g] ?? [])) ?></textarea>
        </div>
      </details>
      <?php endforeach; ?>
    </div></div>

  <div class="card mb-3"><div class="card-header">Wystawienie</div><div class="card-body row g-3">
    <div class="col-md-3"><label class="form-label" for="s-m">Miejscowość</label><input id="s-m" class="form-control" name="miejscowosc" value="<?= $v('miejscowosc') ?>"></div>
    <div class="col-md-3"><label class="form-label" for="s-dt">Data wystawienia</label><input id="s-dt" type="date" class="form-control" name="data_wystawienia" value="<?= $v('data_wystawienia') ?>" <?= $isNew ? '' : 'readonly aria-describedby="s-dt-h"' ?>><?php if (!$isNew): ?><div id="s-dt-h" class="form-text">Data i numer zostają z wystawienia.</div><?php endif; ?></div>
    <div class="col-md-6"><label class="form-label" for="s-pd">Podpisujący</label><input id="s-pd" class="form-control" name="podpisujacy" value="<?= $v('podpisujacy') ?>"></div>
    <div class="col-12"><label class="form-label" for="s-u">Uwagi (drukowane)</label><textarea id="s-u" class="form-control" rows="2" name="uwagi"><?= $v('uwagi') ?></textarea></div>
  </div></div>
  <button class="btn btn-primary"><?= $isNew ? '<i class="bi bi-check2" aria-hidden="true"></i> Wystaw skierowanie' . (skbEzdActive() && skbSetting('ezd_auto') !== '0' ? ' i zarejestruj w EZD' : '') : 'Zapisz zmiany' ?></button>
  </fieldset>
</form>

<?php if (!$isNew && !$locked): ?>
<div class="row g-3 mt-2">
  <div class="col-lg-8"><form method="post" action="<?= h(route('skb.result', ['id' => $s['id']])) ?>" class="card"><?= csrf_field() ?>
    <div class="card-header">Wynik badania (orzeczenie lekarskie)</div><div class="card-body row g-2 align-items-end">
      <div class="col-sm-4"><label class="form-label" for="w-d">Data badania</label><input id="w-d" type="date" class="form-control form-control-sm" name="data_badania" value="<?= $v('data_badania') ?>" required></div>
      <div class="col-sm-4"><label class="form-label" for="w-w">Orzeczenie</label><select id="w-w" class="form-select form-select-sm" name="wynik" required><option value="">—</option>
        <?php foreach (SKB_WYNIKI as $k => $l): ?><option value="<?= $k ?>" <?= $s['wynik'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
      <div class="col-sm-4"><label class="form-label" for="w-do">Ważne do</label><input id="w-do" type="date" class="form-control form-control-sm" name="wazne_do" value="<?= $v('wazne_do') ?>"></div>
      <div class="col-12"><button class="btn btn-sm btn-success">Zapisz wynik</button></div>
    </div></form></div>
  <div class="col-lg-4"><form method="post" action="<?= h(route('skb.cancel', ['id' => $s['id']])) ?>" class="card h-100"><?= csrf_field() ?>
    <div class="card-header">Anulowanie</div><div class="card-body"><p class="small">Skierowanie wystawione błędnie albo niewykorzystane. Wpis w EZD zostaje.</p>
    <button class="btn btn-sm btn-outline-danger">Anuluj skierowanie</button></div></form></div>
</div>
<?php endif; ?>

<?php $others = array_values(array_filter($history, fn($h) => (int)$h['id'] !== (int)($s['id'] ?? 0))); if ($others): ?>
<h5 class="mt-4">Inne skierowania do tej umowy</h5>
<ul class="list-unstyled small">
<?php foreach ($others as $o): ?><li><a href="<?= h(route('skb.show', ['id' => $o['id']])) ?>"><?= h($o['numer']) ?></a> — <?= h(SKB_RODZAJE[$o['rodzaj']]) ?>, <?= $fmt($o['data_wystawienia']) ?>, <?= h(SKB_STATUSY[$o['status']][0]) ?><?= $o['wazne_do'] ? ', ważne do ' . $fmt($o['wazne_do']) : '' ?></li><?php endforeach; ?>
</ul>
<?php endif; ?>

<script>
(function () {
  const sets = <?= json_encode(array_map(fn($z) => $z[1], SKB_ZESTAWY), JSON_UNESCAPED_UNICODE) ?>;
  const form = document.getElementById('skb-form');
  const count = () => {
    let n = form.querySelectorAll('.skb-f:checked').length;
    form.querySelectorAll('.skb-own').forEach(t => n += t.value.split('\n').filter(l => l.trim() !== '').length);
    document.getElementById('skb-count').textContent = n;
  };
  form.addEventListener('change', count); form.addEventListener('input', e => { if (e.target.classList.contains('skb-own')) count(); });
  document.querySelectorAll('[data-skb-set]').forEach(b => b.addEventListener('click', () => {
    const set = sets[b.dataset.skbSet] || {};
    Object.entries(set).forEach(([g, names]) => form.querySelectorAll('.skb-f[data-g="' + g + '"]').forEach(cb => {
      if (names.includes(cb.dataset.name)) { cb.checked = true; cb.closest('details').open = true; }
    }));
    count();
  }));
  count();
})();
</script>
<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>
