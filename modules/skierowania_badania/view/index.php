<?php
/**
 * modules/skierowania_badania/view/index.php — rejestr skierowań na badania lekarskie.
 * Zmienne: $rows, $f (filtry), $expiring (orzeczenia wygasające w 30 dni). Kontroler: SkierowaniaController::index.
 */
$PAGE_TITLE = 'Skierowania na badania lekarskie';
include dirname(__DIR__, 3) . '/includes/header.php';
$fmt = fn($d) => $d ? date('d.m.Y', strtotime((string)$d)) : '—';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-heart-pulse text-primary" aria-hidden="true"></i> Skierowania na badania lekarskie</h4>
  <a class="btn btn-sm btn-primary" href="<?= h(route('skb.create')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> Nowe skierowanie</a>
</div>
<p class="text-body-secondary small">Medycyna pracy dla osób z porozumieniem wolontariackim albo umową zlecenia. Skierowanie wystawiasz do umowy; PDF (dwa egzemplarze) trafia do koszulki EZD tej umowy.</p>
<?= flash_html() ?>

<?php if ($expiring): ?>
<div class="alert alert-warning py-2"><i class="bi bi-hourglass-split" aria-hidden="true"></i>
  Orzeczenia wygasające w ciągu 30 dni (lub już nieważne): <strong><?= (int)$expiring ?></strong> —
  <a href="<?= h(route('skb.index', ['wygasa' => 30])) ?>">pokaż</a> i wystaw skierowanie na badania okresowe.</div>
<?php endif; ?>

<form method="get" action="<?= h(route('skb.index')) ?>" class="row g-2 align-items-end mb-3">
  <div class="col-sm-4"><label class="form-label small" for="f-q">Szukaj</label><input id="f-q" class="form-control form-control-sm" name="q" value="<?= h($f['q']) ?>" placeholder="osoba, numer, stanowisko"></div>
  <div class="col-sm-2"><label class="form-label small" for="f-t">Umowa</label>
    <select id="f-t" class="form-select form-select-sm" name="typ"><option value="">wszystkie</option>
      <option value="pre" <?= $f['typ'] === 'pre' ? 'selected' : '' ?>>przed zawarciem</option>
      <?php foreach (SKB_TYPY as $k => $l): ?><option value="<?= h($k) ?>" <?= $f['typ'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
  <div class="col-sm-3"><label class="form-label small" for="f-s">Status</label>
    <select id="f-s" class="form-select form-select-sm" name="status"><option value="">wszystkie</option>
      <?php foreach (SKB_STATUSY as $k => [$l]): ?><option value="<?= h($k) ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
  <div class="col-sm-3 d-flex gap-2"><button class="btn btn-sm btn-outline-primary">Filtruj</button>
    <?php if (array_filter($f)): ?><a class="btn btn-sm btn-link" href="<?= h(route('skb.index')) ?>">Wyczyść</a><?php endif; ?></div>
</form>

<div class="table-responsive"><table class="table table-sm table-hover align-middle">
  <caption class="visually-hidden">Lista skierowań na badania lekarskie</caption>
  <thead><tr><th scope="col">Numer</th><th scope="col">Osoba</th><th scope="col">Umowa</th><th scope="col">Rodzaj</th><th scope="col">Wystawiono</th><th scope="col">Status</th><th scope="col">Ważne do</th><th scope="col">EZD</th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $s): [$sl, $sc] = SKB_STATUSY[$s['status']] ?? [$s['status'], 'secondary'];
        $soon = $s['status'] === 'zrealizowane' && $s['wazne_do'] && $s['wazne_do'] <= date('Y-m-d', strtotime('+30 days')); ?>
    <tr>
      <td><a href="<?= h(route('skb.show', ['id' => $s['id']])) ?>"><?= h($s['numer']) ?></a></td>
      <td><?= h($s['imie_nazwisko']) ?><div class="small text-body-secondary"><?= h(mb_strimwidth((string)$s['stanowisko'], 0, 60, '…')) ?></div></td>
      <td><?php if (skbIsPre($s)): ?><span class="badge text-bg-warning">przed zawarciem</span> <span class="small"><?= h(mb_strtolower(SKB_TYPY[$s['contract_type']] ?? '')) ?></span>
        <?php else: ?><a href="<?= h(skbContractUrl($s['contract_type'], (int)$s['contract_id'])) ?>"><?= h(SKB_TYPY[$s['contract_type']] ?? $s['contract_type']) ?> #<?= (int)$s['contract_id'] ?></a><?php endif; ?></td>
      <td><?= h(SKB_RODZAJE[$s['rodzaj']] ?? $s['rodzaj']) ?></td>
      <td><?= $fmt($s['data_wystawienia']) ?></td>
      <td><span class="badge text-bg-<?= h($sc) ?>"><?= h($sl) ?></span></td>
      <td class="<?= $soon ? 'text-danger fw-semibold' : '' ?>"><?= $fmt($s['wazne_do']) ?></td>
      <td><?php if ($s['ezd_sprawa_id']): ?><a href="<?= h(skbEzdUrl((int)$s['ezd_sprawa_id'])) ?>" target="_blank" rel="noopener">tak<span class="visually-hidden"> — otwórz koszulkę</span></a><?php else: ?><span class="text-body-secondary">nie</span><?php endif; ?></td>
      <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= h(route('skb.pdf', ['id' => $s['id']])) ?>" target="_blank" rel="noopener"><i class="bi bi-filetype-pdf" aria-hidden="true"></i> PDF<span class="visually-hidden"> <?= h($s['numer']) ?></span></a></td>
    </tr>
  <?php endforeach; if (!$rows): ?>
    <tr><td colspan="9" class="text-center text-body-secondary py-4">Brak skierowań<?= array_filter($f) ? ' dla tych filtrów' : '' ?>.</td></tr>
  <?php endif; ?>
  </tbody>
</table></div>

<?php if (is_admin()): ?>
<details class="card mt-4"><summary class="card-header">Ustawienia skierowań</summary>
<form method="post" action="<?= h(route('skb.settings')) ?>" class="card-body row g-3">
  <?= csrf_field() ?>
  <div class="col-md-4"><label class="form-label" for="st-m">Miejscowość wystawienia</label><input id="st-m" class="form-control" name="miejscowosc" value="<?= h(skbSetting('miejscowosc')) ?>"></div>
  <div class="col-md-8"><label class="form-label" for="st-p">Podpisujący (imię, nazwisko, funkcja)</label><input id="st-p" class="form-control" name="podpisujacy" value="<?= h(skbSetting('podpisujacy')) ?>" placeholder="np. Jan Kowalski — Prezes Zarządu"></div>
  <div class="col-12"><label class="form-label" for="st-pl">Placówka medycyny pracy (domyślnie)</label><input id="st-pl" class="form-control" name="placowka" value="<?= h(skbSetting('placowka')) ?>" placeholder="nazwa i adres przychodni, z którą Fundacja ma umowę"></div>
  <div class="col-md-6"><label class="form-label" for="st-z">Podstawa prawna — zlecenie</label><textarea id="st-z" class="form-control" rows="3" name="podstawa_zlecenie"><?= h(skbSetting('podstawa_zlecenie')) ?></textarea></div>
  <div class="col-md-6"><label class="form-label" for="st-w">Podstawa prawna — wolontariat</label><textarea id="st-w" class="form-control" rows="3" name="podstawa_wolontariat"><?= h(skbSetting('podstawa_wolontariat')) ?></textarea></div>
  <div class="col-12 form-check ms-2"><input class="form-check-input" type="checkbox" id="st-e" name="ezd_auto" value="1" <?= skbSetting('ezd_auto') !== '0' ? 'checked' : '' ?>><label class="form-check-label" for="st-e">Rejestruj skierowanie w EZD od razu po wystawieniu</label></div>
  <div class="col-12"><button class="btn btn-primary btn-sm">Zapisz ustawienia</button></div>
</form></details>
<?php endif; ?>
<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>
