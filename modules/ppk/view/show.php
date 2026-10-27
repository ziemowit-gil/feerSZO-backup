<?php
/**
 * @var array $c umowa o pracę @var ?array $cur obowiązująca dziś wersja @var list<array> $versions @var list<array> $declarations
 * @var array $preview wynik PpkCalculator::calculateMonth @var string $month RRRR-MM @var int $baseGr @var ?string $autoDue data ponownego autozapisu
 * @var bool $canEdit
 */
$fd = static fn(?string $d): string => $d && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) ? "$m[3].$m[2].$m[1]" : '—';
$pct = static fn($bp): string => PpkRates::bpToPercent((int)$bp) . '%';
$st = [
    PpkCalculator::STATUS_PARTICIPANT => ['Uczestnik PPK', 'bi-check-circle-fill', 'text-success'],
    PpkCalculator::STATUS_RESIGNED    => ['Rezygnacja z wpłat', 'bi-x-circle-fill', 'text-danger'],
    PpkCalculator::STATUS_EXEMPT      => ['Niepodlegający', 'bi-dash-circle-fill', 'text-secondary'],
];
$stLabel = static function (?string $s) use ($st): string {
    if (!$s || !isset($st[$s])) return '<span class="text-muted"><i class="bi bi-question-circle" aria-hidden="true"></i> Brak ustawień PPK</span>';
    return '<span class="' . $st[$s][2] . ' fw-semibold"><i class="bi ' . $st[$s][1] . '" aria-hidden="true"></i> ' . htmlspecialchars($st[$s][0]) . '</span>';
};
$declSt = ['szkic' => 'Szkic — do podpisu', 'zlozona' => 'Złożona', 'anulowana' => 'Anulowana'];
$srcLabel = ['reczna' => 'ręcznie', 'deklaracja' => 'z druku', 'autozapis' => 'autozapis'];
$zl = static fn(int $gr): string => PpkCalculator::formatGr($gr) . ' zł';
?>
<nav aria-label="Ścieżka" class="small mb-2"><a href="<?= APP_URL ?>/contracts/praca/view.php?id=<?= (int)$c['id'] ?>" class="text-decoration-none"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Umowa o pracę <?= h($c['numer_umowy']) ?></a></nav>
<div class="d-flex align-items-center gap-3 mb-3">
  <div class="rounded-circle bg-primary bg-opacity-10 d-flex align-items-center justify-content-center" style="width:52px;height:52px;flex-shrink:0"><i class="bi bi-piggy-bank text-primary fs-4" aria-hidden="true"></i></div>
  <div>
    <h1 class="h4 mb-0">PPK — <?= h($c['imie_nazwisko']) ?></h1>
    <div class="text-muted small">Pracownicze Plany Kapitałowe · umowa <?= h($c['numer_umowy']) ?></div>
  </div>
</div>
<?= flash_html() ?>

<div class="row g-3">
<div class="col-lg-7">

<section class="card shadow-sm mb-3" aria-labelledby="ppk-h-now">
  <div class="card-body">
    <h2 class="h6 fw-bold" id="ppk-h-now">Stan na dziś</h2>
    <p class="mb-2"><?= $stLabel($cur['status'] ?? null) ?><?= $cur ? ' <span class="text-muted small">od ' . h($fd($cur['effective_from'])) . '</span>' : '' ?></p>
    <?php if ($cur && $cur['status'] === PpkCalculator::STATUS_PARTICIPANT): ?>
    <table class="table table-sm mb-0 w-auto">
      <caption class="visually-hidden">Obowiązujące stawki wpłat PPK</caption>
      <thead><tr><th scope="col">Wpłata</th><th scope="col" class="text-end">Podstawowa</th><th scope="col" class="text-end">Dodatkowa</th></tr></thead>
      <tbody>
        <tr><th scope="row" class="fw-normal">Finansowana przez pracownika</th><td class="text-end"><?= h($pct($cur['employee_base_bp'])) ?></td><td class="text-end"><?= h($pct($cur['employee_add_bp'])) ?></td></tr>
        <tr><th scope="row" class="fw-normal">Finansowana przez pracodawcę</th><td class="text-end"><?= h($pct($cur['employer_base_bp'])) ?></td><td class="text-end"><?= h($pct($cur['employer_add_bp'])) ?></td></tr>
      </tbody>
    </table>
    <?php elseif ($cur && $cur['status'] === PpkCalculator::STATUS_RESIGNED && $autoDue): ?>
    <p class="small text-muted mb-0">Wpłaty wstrzymane. Ponowny autozapis przewidziany od <strong><?= h($fd($autoDue)) ?></strong>, o ile pracownik nie złoży kolejnej rezygnacji.</p>
    <?php elseif (!$cur): ?>
    <p class="small text-muted mb-0">Nie dopisano jeszcze żadnych ustawień. Złóż druk PPKP (przystąpienie) albo dopisz wersję ręcznie poniżej.</p>
    <?php endif; ?>
  </div>
</section>

<section class="card shadow-sm mb-3" aria-labelledby="ppk-h-prev">
  <div class="card-body">
    <h2 class="h6 fw-bold" id="ppk-h-prev">Podgląd wpłat za miesiąc</h2>
    <form method="get" action="<?= h(route('ppk.show', ['id' => (int)$c['id']])) ?>" class="row g-2 align-items-end mb-3">
      <?= route_field('ppk.show', ['id' => (int)$c['id']]) ?>
      <div class="col-6 col-md-4"><label for="ppk-month" class="form-label small mb-1">Miesiąc</label><input type="month" id="ppk-month" name="miesiac" class="form-control form-control-sm" value="<?= h($month) ?>"></div>
      <div class="col-6 col-md-5"><label for="ppk-base" class="form-label small mb-1">Podstawa, zł (emerytalna i rentowa)</label><input type="text" inputmode="decimal" id="ppk-base" name="podstawa" class="form-control form-control-sm" value="<?= h(number_format($baseGr / 100, 2, ',', '')) ?>"></div>
      <div class="col-12 col-md-3"><button type="submit" class="btn btn-sm btn-outline-primary w-100">Przelicz</button></div>
    </form>
    <?php $p = $preview['payments'][0] ?? null; ?>
    <?php if ($p && !$p['applied']): ?>
      <p class="mb-0 text-muted"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Na dzień <?= h($fd($p['date'])) ?> pracownik <?= $p['status'] === PpkCalculator::STATUS_RESIGNED ? 'ma złożoną rezygnację' : ($p['status'] ? 'nie podlega PPK' : 'nie ma ustawień PPK') ?> — wpłaty wynoszą 0,00 zł.</p>
    <?php else: ?>
    <table class="table table-sm mb-2">
      <caption class="visually-hidden">Wpłaty PPK za <?= h($month) ?> od podstawy <?= h($zl($baseGr)) ?></caption>
      <thead><tr><th scope="col">Składnik</th><th scope="col" class="text-end">Kwota</th></tr></thead>
      <tbody>
        <tr><th scope="row" class="fw-normal">Wpłata podstawowa pracownika</th><td class="text-end"><?= h($zl($preview['employee_base'])) ?></td></tr>
        <tr><th scope="row" class="fw-normal">Wpłata dodatkowa pracownika</th><td class="text-end"><?= h($zl($preview['employee_add'])) ?></td></tr>
        <tr><th scope="row" class="fw-normal">Wpłata podstawowa pracodawcy</th><td class="text-end"><?= h($zl($preview['employer_base'])) ?></td></tr>
        <tr><th scope="row" class="fw-normal">Wpłata dodatkowa pracodawcy</th><td class="text-end"><?= h($zl($preview['employer_add'])) ?></td></tr>
        <tr class="table-light"><th scope="row">Do instytucji finansowej razem</th><td class="text-end fw-bold"><?= h($zl($preview['total_to_fund'])) ?></td></tr>
        <tr><th scope="row" class="fw-normal">Potrącenie z wynagrodzenia pracownika</th><td class="text-end"><?= h($zl($preview['employee_total'])) ?></td></tr>
        <tr><th scope="row" class="fw-normal">Przychód pracownika z tytułu składki pracodawcy (PIT)</th><td class="text-end"><?= h($zl($preview['employer_taxable_income'])) ?></td></tr>
      </tbody>
    </table>
    <?php endif; ?>
    <p class="small text-muted mb-0">Podgląd liczy wpłaty od jednej wypłaty z datą ostatniego dnia miesiąca. Składka pracodawcy jest przychodem (PIT), ale nie podstawą składek ZUS; podatek liczy system płac, którego SZO jeszcze nie ma.</p>
  </div>
</section>

<section class="card shadow-sm mb-3" aria-labelledby="ppk-h-hist">
  <div class="card-body">
    <h2 class="h6 fw-bold" id="ppk-h-hist">Historia ustawień</h2>
    <?php if (!$versions): ?><p class="text-muted mb-3">Brak wpisów.</p><?php else: ?>
    <div class="table-responsive mb-3">
      <table class="table table-sm align-middle">
        <caption class="visually-hidden">Historia ustawień PPK, od najnowszej</caption>
        <thead><tr><th scope="col">Od</th><th scope="col">Status</th><th scope="col">Pracownik (podst. + dod.)</th><th scope="col">Pracodawca (podst. + dod.)</th><th scope="col">Źródło</th></tr></thead>
        <tbody>
        <?php foreach ($versions as $v): $part = $v['status'] === PpkCalculator::STATUS_PARTICIPANT; ?>
          <tr>
            <th scope="row" class="fw-normal text-nowrap"><?= h($fd($v['effective_from'])) ?></th>
            <td><?= $stLabel($v['status']) ?></td>
            <td><?= $part ? h($pct($v['employee_base_bp']) . ' + ' . $pct($v['employee_add_bp'])) : '—' ?></td>
            <td><?= $part ? h($pct($v['employer_base_bp']) . ' + ' . $pct($v['employer_add_bp'])) : '—' ?></td>
            <td class="small"><?= h($srcLabel[$v['source']] ?? $v['source']) ?><?= $v['note'] ? ' · ' . h($v['note']) : '' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <?php if ($canEdit): ?>
    <details>
      <summary class="small fw-semibold" style="cursor:pointer">Dopisz wersję ręcznie (korekta, osoba niepodlegająca)</summary>
      <form method="post" action="<?= h(route('ppk.version', ['id' => (int)$c['id']])) ?>" class="row g-2 mt-1">
        <?= Router::csrfField() ?>
        <div class="col-6 col-md-3"><label for="v-from" class="form-label small mb-1">Obowiązuje od</label><input type="date" id="v-from" name="effective_from" class="form-control form-control-sm" required></div>
        <div class="col-6 col-md-3"><label for="v-st" class="form-label small mb-1">Status</label>
          <select id="v-st" name="status" class="form-select form-select-sm">
            <?php foreach ($st as $k => $meta): ?><option value="<?= h($k) ?>"><?= h($meta[0]) ?></option><?php endforeach; ?>
          </select></div>
        <div class="col-12"><p class="small text-muted mb-1">Stawki (w %) dotyczą statusu „Uczestnik PPK”; puste = 2 / 0 / 1,5 / 0.</p></div>
        <div class="col-6 col-md-3"><label for="v-eb" class="form-label small mb-1">Pracownik podstawowa (0,5–2)</label><input type="text" inputmode="decimal" id="v-eb" name="employee_base" class="form-control form-control-sm" placeholder="2"></div>
        <div class="col-6 col-md-3"><label for="v-ea" class="form-label small mb-1">Pracownik dodatkowa (0–2)</label><input type="text" inputmode="decimal" id="v-ea" name="employee_add" class="form-control form-control-sm" placeholder="0"></div>
        <div class="col-6 col-md-3"><label for="v-rb" class="form-label small mb-1">Pracodawca podstawowa (1,5)</label><input type="text" inputmode="decimal" id="v-rb" name="employer_base" class="form-control form-control-sm" placeholder="1,5"></div>
        <div class="col-6 col-md-3"><label for="v-ra" class="form-label small mb-1">Pracodawca dodatkowa (0–2,5)</label><input type="text" inputmode="decimal" id="v-ra" name="employer_add" class="form-control form-control-sm" placeholder="0"></div>
        <div class="col-12 col-md-9"><label for="v-note" class="form-label small mb-1">Notatka</label><input type="text" id="v-note" name="note" class="form-control form-control-sm" maxlength="500"></div>
        <div class="col-12 col-md-3 d-flex align-items-end"><button type="submit" class="btn btn-sm btn-primary w-100">Dopisz</button></div>
      </form>
    </details>
    <?php endif; ?>
  </div>
</section>

</div>
<div class="col-lg-5">

<section class="card shadow-sm mb-3" aria-labelledby="ppk-h-decl">
  <div class="card-body">
    <h2 class="h6 fw-bold" id="ppk-h-decl">Druki PPKR i PPKP</h2>
    <p class="small text-muted">PPKR — deklaracja rezygnacji z wpłat. PPKP — wniosek o wpłaty (przystąpienie). Wydrukuj druk do podpisu pracownika albo zarejestruj oświadczenie złożone elektronicznie; złożenie dopisuje wersję ustawień. Treść druków to projekt do zatwierdzenia przez kadry lub prawnika.</p>
    <?php if ($declarations): ?>
    <ul class="list-unstyled mb-3">
      <?php foreach ($declarations as $d): ?>
      <li class="border rounded p-2 mb-2">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
          <div><strong><?= h($d['number']) ?></strong> <span class="badge bg-secondary"><?= h($d['kind']) ?></span><br>
            <span class="small"><?= h($declSt[$d['status']] ?? $d['status']) ?><?php if ($d['status'] === 'zlozona'): ?> · <?= h($fd($d['declared_at'])) ?> · <?= h($d['form']) ?><?php endif; ?></span></div>
          <a class="btn btn-sm btn-outline-primary" href="<?= h(route('ppk.pdf', ['id' => (int)$d['id']])) ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF<span class="visually-hidden"> druku <?= h($d['number']) ?></span></a>
        </div>
        <?php if ($d['status'] === 'zlozona' && $d['proof']): ?><div class="small text-muted mt-1">Potwierdzenie: <?= h($d['proof']) ?></div><?php endif; ?>
        <?php if ($canEdit && $d['status'] === 'szkic'): ?>
        <details class="mt-2">
          <summary class="small fw-semibold" style="cursor:pointer">Zarejestruj złożenie</summary>
          <form method="post" action="<?= h(route('ppk.submit', ['id' => (int)$d['id']])) ?>" class="row g-2 mt-1">
            <?= Router::csrfField() ?>
            <div class="col-6"><label for="s-at-<?= (int)$d['id'] ?>" class="form-label small mb-1">Data złożenia</label><input type="date" id="s-at-<?= (int)$d['id'] ?>" name="declared_at" class="form-control form-control-sm" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required></div>
            <div class="col-6"><label for="s-fo-<?= (int)$d['id'] ?>" class="form-label small mb-1">Forma</label>
              <select id="s-fo-<?= (int)$d['id'] ?>" name="form" class="form-select form-select-sm"><option value="papierowa">Papierowa (podpis na wydruku)</option><option value="elektroniczna">Elektroniczna</option></select></div>
            <div class="col-12"><label for="s-pr-<?= (int)$d['id'] ?>" class="form-label small mb-1">Potwierdzenie <span class="fw-normal text-muted">(wymagane przy formie elektronicznej, np. identyfikator wiadomości lub podpisu)</span></label><input type="text" id="s-pr-<?= (int)$d['id'] ?>" name="proof" class="form-control form-control-sm" maxlength="500"></div>
            <div class="col-12"><label for="s-ef-<?= (int)$d['id'] ?>" class="form-label small mb-1">Obowiązuje od <span class="fw-normal text-muted">(puste = data złożenia)</span></label><input type="date" id="s-ef-<?= (int)$d['id'] ?>" name="effective_from" class="form-control form-control-sm"></div>
            <div class="col-12 d-flex gap-2"><button type="submit" class="btn btn-sm btn-primary">Zarejestruj</button></div>
          </form>
          <form method="post" action="<?= h(route('ppk.cancel', ['id' => (int)$d['id']])) ?>" class="mt-2" onsubmit="return confirm('Anulować ten druk?');">
            <?= Router::csrfField() ?>
            <button type="submit" class="btn btn-sm btn-outline-danger">Anuluj druk</button>
          </form>
        </details>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <?php if ($canEdit): ?>
    <form method="post" action="<?= h(route('ppk.declare', ['id' => (int)$c['id']])) ?>" class="mb-3">
      <?= Router::csrfField() ?>
      <input type="hidden" name="kind" value="PPKR">
      <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle me-1" aria-hidden="true"></i>Nowy druk PPKR (rezygnacja)</button>
    </form>
    <form method="post" action="<?= h(route('ppk.declare', ['id' => (int)$c['id']])) ?>" class="border-top pt-3">
      <?= Router::csrfField() ?>
      <input type="hidden" name="kind" value="PPKP">
      <p class="small fw-semibold mb-2">Nowy druk PPKP (przystąpienie) — stawki w %</p>
      <div class="row g-2">
        <div class="col-6"><label for="j-eb" class="form-label small mb-1">Pracownik podstawowa (0,5–2)</label><input type="text" inputmode="decimal" id="j-eb" name="employee_base" class="form-control form-control-sm" placeholder="2"></div>
        <div class="col-6"><label for="j-ea" class="form-label small mb-1">Pracownik dodatkowa (0–2)</label><input type="text" inputmode="decimal" id="j-ea" name="employee_add" class="form-control form-control-sm" placeholder="0"></div>
        <div class="col-6"><label for="j-rb" class="form-label small mb-1">Pracodawca podstawowa</label><input type="text" id="j-rb" name="employer_base" class="form-control form-control-sm" placeholder="1,5" readonly value="1,5"></div>
        <div class="col-6"><label for="j-ra" class="form-label small mb-1">Pracodawca dodatkowa (0–2,5)</label><input type="text" inputmode="decimal" id="j-ra" name="employer_add" class="form-control form-control-sm" placeholder="0"></div>
        <div class="col-12"><button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Przygotuj druk PPKP</button></div>
      </div>
    </form>
    <?php endif; ?>
  </div>
</section>

</div>
</div>
