<?php
/**
 * strategy/reports/foundation_report.php
 * Formularz sprawozdania z działalności fundacji (rozp. MS 20.12.2022).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/strategy.php';
require_once dirname(dirname(__DIR__)) . '/includes/foundation_report.php';

require_login();
$PAGE_TITLE = 'Sprawozdanie z działalności fundacji';

$rok    = (int)($_GET['rok'] ?? date('Y') - 1);
$errors = [];

// ── Wczytaj lub utwórz sprawozdanie ──────────────────────────────────────────
$report = db_one("SELECT * FROM foundation_reports WHERE rok=?", [$rok]);
$is_new = !$report;

// Auto-dane z systemu
$org   = freport_org_data();
$auto_iii = freport_calc_iii($rok);
$auto_v   = freport_calc_v($rok);
$cele_auto = freport_cele_statutowe();

// Dane dla formularza (z bazy lub auto)
$iii  = $report ? (json_decode($report['iii_json'] ?? '{}', true) ?: []) : [];
$iv   = $report ? (json_decode($report['iv_json']  ?? '{}', true) ?: []) : [];
$v    = $report ? (json_decode($report['v_json']   ?? '{}', true) ?: []) : [];
$vii  = $report ? (json_decode($report['vii_json'] ?? '{}', true) ?: []) : [];

function _r(array $data, string $key, $default = ''): mixed {
    return $data[$key] ?? $default;
}

// ── POST — zapis ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? 'save';

    if ($action === 'save' || $action === 'submit') {
        $rok_post = (int)($_POST['rok'] ?? $rok);

        // Sekcje JSON
        $iii_post = [
            'statutowe_przelew'  => str_replace(',','.',$_POST['iii_stat_przelew'] ?? '0'),
            'statutowe_gotowka'  => str_replace(',','.',$_POST['iii_stat_gotowka'] ?? '0'),
            'statutowe_inne'     => str_replace(',','.',$_POST['iii_stat_inne']    ?? '0'),
            'gosp_przelew'       => str_replace(',','.',$_POST['iii_gosp_przelew'] ?? '0'),
            'gosp_gotowka'       => str_replace(',','.',$_POST['iii_gosp_gotowka'] ?? '0'),
            'pozostale_przelew'  => str_replace(',','.',$_POST['iii_poz_przelew']  ?? '0'),
            'odpłatna'           => str_replace(',','.',$_POST['iii_odplatna']     ?? '0'),
            'srodki_publiczne'   => str_replace(',','.',$_POST['iii_publ']         ?? '0'),
            'budzet_panstwa'     => str_replace(',','.',$_POST['iii_budpan']       ?? '0'),
            'budzet_jst'         => str_replace(',','.',$_POST['iii_budjst']       ?? '0'),
            'spadki_zapisy'      => str_replace(',','.',$_POST['iii_spadki']       ?? '0'),
            'darowizny'          => str_replace(',','.',$_POST['iii_darowizny']    ?? '0'),
            'inne_zrodla'        => $_POST['iii_inne_zrodla'] ?? '',
            'dochod_gosp'        => str_replace(',','.',$_POST['iii_dochod_gosp']  ?? '0'),
            'procent_gosp'       => str_replace(',','.',$_POST['iii_procent_gosp'] ?? '0'),
        ];

        $iv_post = [
            'cele_stat_przelew'   => str_replace(',','.',$_POST['iv_cs_przelew']  ?? '0'),
            'cele_stat_gotowka'   => str_replace(',','.',$_POST['iv_cs_gotowka']  ?? '0'),
            'adm_przelew'         => str_replace(',','.',$_POST['iv_adm_przelew'] ?? '0'),
            'adm_gotowka'         => str_replace(',','.',$_POST['iv_adm_gotowka'] ?? '0'),
            'gosp_przelew'        => str_replace(',','.',$_POST['iv_gosp_przelew']?? '0'),
            'pozostale_przelew'   => str_replace(',','.',$_POST['iv_poz_przelew'] ?? '0'),
        ];

        $v_post = [
            'praca_liczba'        => (int)($_POST['v_praca_liczba']   ?? 0),
            'gosp_liczba'         => (int)($_POST['v_gosp_liczba']    ?? 0),
            'praca_brutto'        => str_replace(',','.',$_POST['v_praca_brutto']  ?? '0'),
            'umowy_cyw_brutto'    => str_replace(',','.',$_POST['v_cyw_brutto']    ?? '0'),
            'zarzad_wynagrodzenia'=> str_replace(',','.',$_POST['v_zarzad_wynagr'] ?? '0'),
        ];

        $vii_post = [
            'rachunki'     => $_POST['vii_rachunki']     ?? '',
            'skok'         => $_POST['vii_skok']         ?? '',
            'gotowka'      => str_replace(',','.',$_POST['vii_gotowka'] ?? '0'),
            'obligacje'    => $_POST['vii_obligacje']    ?? '',
            'nieruchomosci'=> $_POST['vii_nieruch']      ?? '',
            'srodki_trwale'=> $_POST['vii_srt']          ?? '',
            'aktywa'       => str_replace(',','.',$_POST['vii_aktywa']  ?? '0'),
            'zobowiazania' => str_replace(',','.',$_POST['vii_zobowiaz']?? '0'),
        ];

        $data = [
            'rok'                 => $rok_post,
            'status'              => $action === 'submit' ? 'złożone' : 'roboczy',
            'organ_nadzoru'       => trim($_POST['organ_nadzoru'] ?? ''),
            'ii_zasady_formy'     => trim($_POST['ii_zasady_formy'] ?? ''),
            'ii_zdarzenia_prawne' => trim($_POST['ii_zdarzenia_prawne'] ?? ''),
            'ii_dzialalnosc_gosp' => isset($_POST['ii_dzialalnosc_gosp']) ? 1 : 0,
            'ii_pkd'              => trim($_POST['ii_pkd'] ?? ''),
            'ii_uchwaly'          => trim($_POST['ii_uchwaly'] ?? ''),
            'iii_json'            => json_encode($iii_post),
            'iv_json'             => json_encode($iv_post),
            'v_json'              => json_encode($v_post),
            'vi_pozyczki'         => isset($_POST['vi_pozyczki']) ? 1 : 0,
            'vi_wysokosc'         => trim($_POST['vi_wysokosc']         ?? ''),
            'vi_pozyczkobiorcy'   => trim($_POST['vi_pozyczkobiorcy']   ?? ''),
            'vi_podstawa_stat'    => trim($_POST['vi_podstawa_stat']    ?? ''),
            'vii_json'            => json_encode($vii_post),
            'viii_opis'           => trim($_POST['viii_opis']           ?? ''),
            'ix_zobowiazania'     => trim($_POST['ix_zobowiazania']     ?? ''),
            'ix_deklaracje'       => trim($_POST['ix_deklaracje']       ?? ''),
            'x_aml'               => isset($_POST['x_aml'])  ? 1 : 0,
            'xi_platnosci'        => trim($_POST['xi_platnosci']        ?? ''),
            'xii_kontrola'        => isset($_POST['xii_kontrola']) ? 1 : 0,
            'xii_wyniki'          => trim($_POST['xii_wyniki']          ?? ''),
            'notatki'             => trim($_POST['notatki']             ?? ''),
            'updated_at'          => date('Y-m-d H:i:s'),
        ];

        if ($is_new) {
            $data['created_by'] = current_user()['id'];
            db_insert('foundation_reports', $data);
        } else {
            $id = $report['id'];
            $set = implode(', ', array_map(fn($k) => "`{$k}`=:{$k}", array_keys($data)));
            $data[':id'] = $id;
            db()->prepare("UPDATE foundation_reports SET {$set} WHERE id=:id")->execute($data);
        }
        flash_set('success', $action === 'submit' ? 'Sprawozdanie oznaczone jako złożone.' : 'Sprawozdanie zapisane.');
        header("Location: foundation_report.php?rok={$rok_post}"); exit;
    }

    if ($action === 'delete') {
        if ($report) {
            db()->prepare("DELETE FROM foundation_reports WHERE id=?")->execute([$report['id']]);
            flash_set('success', 'Sprawozdanie usunięte.');
        }
        header('Location: index.php'); exit;
    }
}

// ── Wartości wyświetlane w formularzu ─────────────────────────────────────────
$v_show_praca  = _r($v,'praca_liczba', $auto_v['umowy_praca_liczba']);
$v_show_pbrutto = _r($v,'praca_brutto', number_format($auto_v['umowy_praca_brutto'],2,',',''));
$v_show_cbrutto = _r($v,'umowy_cyw_brutto', number_format($auto_v['umowy_cywilne_brutto'],2,',',''));

$iii_stat_auto = $auto_iii['przychody_statutowe_przelew'];
$iii_darow_auto = $auto_iii['darowizny'];

$status_labels = ['roboczy'=>'Roboczy', 'złożone'=>'Złożone', 'zatwierdzone'=>'Zatwierdzone'];
$status_colors = ['roboczy'=>'bg-secondary', 'złożone'=>'bg-primary', 'zatwierdzone'=>'bg-success'];

include dirname(__DIR__) . '/includes/header_strategy.php';
?>

<div class="d-flex align-items-center gap-3 mb-1 flex-wrap">
  <div>
    <h1 style="font-size:1.3rem;font-weight:800;margin:0">
      <i class="bi bi-file-earmark-text me-2" style="color:var(--strat-accent)"></i>
      Sprawozdanie z działalności fundacji
    </h1>
    <div class="text-muted small">
      Rok sprawozdawczy: <strong><?= $rok ?></strong>
      <?php if ($report): ?>
      &nbsp;<span class="badge <?= $status_colors[$report['status']??'roboczy'] ?>">
        <?= $status_labels[$report['status']??'roboczy'] ?>
      </span>
      <?php endif; ?>
    </div>
  </div>
  <div class="ms-auto d-flex gap-2 flex-wrap">
    <!-- Wybór roku -->
    <form method="get" class="d-flex gap-1 align-items-center">
      <label class="small text-muted fw-semibold mb-0">Rok:</label>
      <select name="rok" class="form-select form-select-sm" style="width:80px" onchange="this.form.submit()">
        <?php for ($y = date('Y'); $y >= 2018; $y--): ?>
        <option value="<?= $y ?>" <?= $y===$rok?'selected':'' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
    </form>
    <?php if ($report): ?>
    <a href="foundation_report_print.php?rok=<?= $rok ?>" target="_blank"
       class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-printer me-1"></i>Drukuj / PDF
    </a>
    <?php endif; ?>
  </div>
</div>

<!-- Pasek postępu uzupełnienia -->
<?php
$filled = 0; $total = 12;
if ($report) {
    if ($report['ii_zasady_formy'])     $filled++;
    if ($report['ii_zdarzenia_prawne']) $filled++;
    if (array_sum(array_map('floatval', $iii))) $filled++;
    if (array_sum(array_map('floatval', $iv)))  $filled++;
    if ($report['organ_nadzoru'])       $filled++;
    $filled += 7; // reszta z auto-danych
}
$pct_fill = $report ? min(100, round($filled/$total*100)) : 0;
?>
<div class="d-flex align-items-center gap-2 mb-4 mt-2">
  <div class="progress flex-grow-1" style="height:6px">
    <div class="progress-bar" style="width:<?= $pct_fill ?>%;background:var(--strat-accent)"></div>
  </div>
  <span class="text-muted" style="font-size:.75rem;white-space:nowrap"><?= $pct_fill ?>% uzupełnione</span>
  <?php if (!$is_new && $auto_iii['przychody_statutowe_przelew'] > 0): ?>
  <span class="badge" style="background:var(--strat-accent-bg);color:var(--strat-accent);font-size:.68rem">
    <i class="bi bi-magic me-1"></i>auto-uzupełnione z systemu
  </span>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<form method="post" id="reportForm">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
<input type="hidden" name="rok" value="<?= $rok ?>">

<!-- Nawigacja sekcji -->
<nav class="d-flex gap-1 flex-wrap mb-4" style="font-size:.78rem">
  <?php foreach(['I'=>'Dane fundacji','II'=>'Działalność','III'=>'Przychody','IV'=>'Koszty','V'=>'Zatrudnienie','VI'=>'Pożyczki','VII'=>'Środki','VIII'=>'Zlecon.','IX'=>'Podatki','X-XII'=>'Inne'] as $k=>$l): ?>
  <a href="#sec<?= $k ?>" class="btn btn-sm btn-outline-secondary py-0 px-2"><?= $k ?>. <?= $l ?></a>
  <?php endforeach; ?>
</nav>

<!-- ═══════════════════════════════════════════════════════
     I. Dane fundacji
═══════════════════════════════════════════════════════ -->
<div class="card shadow-sm mb-3" id="secI">
  <div class="card-header fw-bold" style="background:var(--strat-accent-bg);color:var(--strat-accent)">
    <i class="bi bi-building me-1"></i>I. Dane fundacji
    <span class="badge bg-success ms-2 fw-normal" style="font-size:.65rem">
      <i class="bi bi-lightning me-1"></i>Auto z ustawień
    </span>
  </div>
  <div class="card-body">
    <div class="mb-2">
      <label class="form-label fw-semibold small">Organ sprawujący nadzór</label>
      <input name="organ_nadzoru" class="form-control form-control-sm"
             placeholder="np. Minister Sprawiedliwości / Starosta..."
             value="<?= h($report['organ_nadzoru'] ?? '') ?>">
    </div>
    <div class="row g-3">
      <div class="col-md-6">
        <div class="p-3 rounded border bg-light small">
          <div class="fw-bold mb-2 text-muted" style="font-size:.7rem;letter-spacing:.08em;text-transform:uppercase">
            Dane organizacji (z ustawień systemu)
          </div>
          <table class="table table-sm table-borderless mb-0" style="font-size:.82rem">
            <tr><td class="text-muted pe-2">Nazwa:</td><td><strong><?= h($org['nazwa']) ?></strong></td></tr>
            <tr><td class="text-muted pe-2">Adres:</td><td><?= h($org['adres']) ?></td></tr>
            <tr><td class="text-muted pe-2">NIP:</td><td><?= h($org['nip']) ?></td></tr>
            <tr><td class="text-muted pe-2">KRS:</td><td><?= h($org['krs']) ?></td></tr>
            <tr><td class="text-muted pe-2">REGON:</td><td><?= h($org['regon']) ?: '<span class="text-danger">— brak w ustawieniach</span>' ?></td></tr>
            <tr><td class="text-muted pe-2">E-mail:</td><td><?= h($org['email']) ?></td></tr>
          </table>
          <a href="<?= APP_URL ?>/admin/org_settings.php" class="btn btn-sm btn-link p-0 mt-1" style="font-size:.75rem">
            <i class="bi bi-pencil me-1"></i>Edytuj w ustawieniach →
          </a>
        </div>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold small">I.8 Wszystkie cele statutowe</label>
        <div class="alert alert-info py-2 mb-2" style="font-size:.76rem">
          <i class="bi bi-magic me-1"></i>
          Auto z modułu Strategii (<?= count(explode("\n", $cele_auto)) ?> aktywnych celów).
          <a href="<?= APP_URL ?>/strategy/objectives/index.php" target="_blank">Edytuj cele →</a>
        </div>
        <textarea name="ii_zasady_formy" class="form-control form-control-sm" rows="5"
                  placeholder="Opis celów statutowych fundacji..."><?= h($report['ii_zasady_formy'] ?? $cele_auto) ?></textarea>
        <div class="form-text">Opisz zasady, formy i zakres działalności statutowej z realizacją celów.</div>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     II. Charakterystyka działalności
═══════════════════════════════════════════════════════ -->
<div class="card shadow-sm mb-3" id="secII">
  <div class="card-header fw-bold" style="background:var(--strat-accent-bg);color:var(--strat-accent)">
    <i class="bi bi-journal-text me-1"></i>II. Charakterystyka działalności w roku <?= $rok ?>
  </div>
  <div class="card-body">
    <div class="mb-3">
      <label class="form-label fw-semibold small">II.2 Opis głównych zdarzeń prawnych o skutkach finansowych</label>
      <textarea name="ii_zdarzenia_prawne" class="form-control form-control-sm" rows="4"
                placeholder="Opis najważniejszych zdarzeń prawnych w roku sprawozdawczym..."><?= h($report['ii_zdarzenia_prawne'] ?? '') ?></textarea>
    </div>
    <div class="row g-3">
      <div class="col-md-4">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="ii_dzialalnosc_gosp"
                 id="ii_gosp" value="1" <?= ($report['ii_dzialalnosc_gosp']??0)?'checked':'' ?>>
          <label class="form-check-label fw-semibold small" for="ii_gosp">
            II.3 Fundacja prowadziła działalność gospodarczą
          </label>
        </div>
      </div>
      <div class="col-md-8">
        <label class="form-label fw-semibold small">II.4 Kody PKD działalności gospodarczej</label>
        <input name="ii_pkd" class="form-control form-control-sm"
               placeholder="np. 85.52.Z — Pozaszkolne formy edukacji artystycznej"
               value="<?= h($report['ii_pkd'] ?? '') ?>">
      </div>
    </div>
    <div class="mt-3">
      <label class="form-label fw-semibold small">II.5 Uchwały zarządu/rady</label>
      <textarea name="ii_uchwaly" class="form-control form-control-sm" rows="2"
                placeholder="Opis podjętych uchwał lub — jeśli brak"><?= h($report['ii_uchwaly'] ?? '') ?></textarea>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     III. Przychody
═══════════════════════════════════════════════════════ -->
<div class="card shadow-sm mb-3" id="secIII">
  <div class="card-header fw-bold d-flex align-items-center gap-2" style="background:var(--strat-accent-bg);color:var(--strat-accent)">
    <i class="bi bi-cash-coin me-1"></i>III. Informacja o wysokości uzyskanych przychodów
    <?php if ($auto_iii['przychody_statutowe_przelew'] > 0): ?>
    <span class="badge bg-success fw-normal ms-auto" style="font-size:.65rem">
      <i class="bi bi-lightning me-1"></i>Auto z grantów: <?= freport_kwota($auto_iii['przychody_statutowe_przelew']) ?> PLN
    </span>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <div class="table-responsive">
    <table class="table table-bordered table-sm align-middle" style="font-size:.82rem">
      <thead class="table-light">
        <tr>
          <th>Pozycja</th>
          <th style="width:160px">Przelew (PLN)</th>
          <th style="width:120px">Gotówka (PLN)</th>
          <th style="width:120px">Inne (PLN)</th>
        </tr>
      </thead>
      <tbody>
        <?php
        function _fi(string $name, string $auto='', mixed $val=''): string {
            $v = $val !== '' ? $val : ($auto ?: '0,00');
            return "<input name=\"{$name}\" type=\"text\" class=\"form-control form-control-sm text-end font-monospace\" value=\"".h($v)."\" placeholder=\"0,00\">";
        }
        $s_przelew = _r($iii,'statutowe_przelew', $auto_iii['przychody_statutowe_przelew'] > 0 ? freport_kwota($auto_iii['przychody_statutowe_przelew']) : '');
        ?>
        <tr class="table-info"><td colspan="4" class="fw-bold" style="font-size:.75rem">III.1 Łączna kwota przychodów</td></tr>
        <tr>
          <td>a. Przychody z działalności statutowej</td>
          <td><?= _fi('iii_stat_przelew', '', _r($iii,'statutowe_przelew',$auto_iii['przychody_statutowe_przelew']>0?freport_kwota($auto_iii['przychody_statutowe_przelew']):'')) ?></td>
          <td><?= _fi('iii_stat_gotowka','',_r($iii,'statutowe_gotowka','')) ?></td>
          <td><?= _fi('iii_stat_inne','',_r($iii,'statutowe_inne','')) ?></td>
        </tr>
        <tr>
          <td>b. Przychody z działalności gospodarczej</td>
          <td><?= _fi('iii_gosp_przelew','',_r($iii,'gosp_przelew','')) ?></td>
          <td><?= _fi('iii_gosp_gotowka','',_r($iii,'gosp_gotowka','')) ?></td>
          <td><input class="form-control form-control-sm" disabled placeholder="—"></td>
        </tr>
        <tr>
          <td>c. Pozostałe przychody</td>
          <td><?= _fi('iii_poz_przelew','',_r($iii,'pozostale_przelew','')) ?></td>
          <td><input class="form-control form-control-sm" disabled placeholder="—"></td>
          <td><input class="form-control form-control-sm" disabled placeholder="—"></td>
        </tr>
        <tr class="table-warning"><td colspan="4" class="fw-bold" style="font-size:.75rem">III.2 Źródła przychodów</td></tr>
        <?php
        $src = [
            ['iii_odplatna',  'a. Działalność odpłatna w ramach celów stat.',    _r($iii,'odpłatna','')],
            ['iii_publ',      'b. Ze źródeł publicznych ogółem',                 _r($iii,'srodki_publiczne', $auto_iii['srodki_publiczne']>0?freport_kwota($auto_iii['srodki_publiczne']):'')],
            ['iii_budpan',    '— ze środków budżetu państwa',                    _r($iii,'budzet_panstwa', $auto_iii['budzet_panstwa']>0?freport_kwota($auto_iii['budzet_panstwa']):'')],
            ['iii_budjst',    '— ze środków budżetu JST',                        _r($iii,'budzet_jst', $auto_iii['budzet_jst']>0?freport_kwota($auto_iii['budzet_jst']):'')],
            ['iii_spadki',    'c. Ze spadków, zapisów',                          _r($iii,'spadki_zapisy','')],
            ['iii_darowizny', 'd. Z darowizn',                                   _r($iii,'darowizny', $auto_iii['darowizny']>0?freport_kwota($auto_iii['darowizny']):'')],
        ];
        foreach ($src as [$name, $label, $val]):
        ?>
        <tr>
          <td class="<?= str_starts_with($name,'iii_bud')?'ps-4':'' ?>"><?= $label ?></td>
          <td><?= _fi($name, '', $val) ?></td>
          <td colspan="2" class="text-muted text-center small">—</td>
        </tr>
        <?php endforeach; ?>
        <tr>
          <td>e. Inne źródła</td>
          <td colspan="3">
            <input name="iii_inne_zrodla" class="form-control form-control-sm"
                   placeholder="Opis innych źródeł..."
                   value="<?= h(_r($iii,'inne_zrodla','')) ?>">
          </td>
        </tr>
      </tbody>
    </table>
    </div>
    <?php if ($report && ($report['ii_dzialalnosc_gosp'] ?? 0)): ?>
    <div class="row g-3 mt-1">
      <div class="col-md-6">
        <label class="form-label fw-semibold small">III.3a Dochód z działalności gospodarczej (PLN)</label>
        <input name="iii_dochod_gosp" class="form-control form-control-sm font-monospace text-end"
               value="<?= h(_r($iii,'dochod_gosp','')) ?>" placeholder="0,00">
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold small">III.3b Udział % przych. gosp. w całości</label>
        <input name="iii_procent_gosp" class="form-control form-control-sm font-monospace text-end"
               value="<?= h(_r($iii,'procent_gosp','')) ?>" placeholder="0,00">
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     IV. Koszty
═══════════════════════════════════════════════════════ -->
<div class="card shadow-sm mb-3" id="secIV">
  <div class="card-header fw-bold" style="background:var(--strat-accent-bg);color:var(--strat-accent)">
    <i class="bi bi-receipt me-1"></i>IV. Informacja o poniesionych kosztach
  </div>
  <div class="card-body">
    <div class="table-responsive">
    <table class="table table-bordered table-sm align-middle" style="font-size:.82rem">
      <thead class="table-light">
        <tr><th>Pozycja</th><th style="width:150px">Przelew (PLN)</th><th style="width:120px">Gotówka (PLN)</th><th style="width:120px">Inne</th></tr>
      </thead>
      <tbody>
        <?php
        $costs = [
            ['iv_cs_przelew','iv_cs_gotowka',   'Koszty realizacji celów statutowych', _r($iv,'cele_stat_przelew',''),  _r($iv,'cele_stat_gotowka','')],
            ['iv_adm_przelew','iv_adm_gotowka', 'Koszty administracyjne',              _r($iv,'adm_przelew',''),        _r($iv,'adm_gotowka','')],
            ['iv_gosp_przelew','',              'Koszty działalności gospodarczej',    _r($iv,'gosp_przelew',''),       ''],
            ['iv_poz_przelew','',               'Pozostałe koszty',                    _r($iv,'pozostale_przelew',''),  ''],
        ];
        foreach ($costs as [$np, $ng, $label, $vp, $vg]):
        ?>
        <tr>
          <td><?= $label ?></td>
          <td><?= _fi($np,'', $vp) ?></td>
          <td><?= $ng ? _fi($ng,'', $vg) : '<input class="form-control form-control-sm" disabled placeholder="—">' ?></td>
          <td><input class="form-control form-control-sm" disabled placeholder="—"></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     V. Zatrudnienie
═══════════════════════════════════════════════════════ -->
<div class="card shadow-sm mb-3" id="secV">
  <div class="card-header fw-bold d-flex align-items-center gap-2" style="background:var(--strat-accent-bg);color:var(--strat-accent)">
    <i class="bi bi-people me-1"></i>V. Informacja o zatrudnieniu i wynagrodzeniu
    <span class="badge bg-success fw-normal ms-auto" style="font-size:.65rem">
      <i class="bi bi-lightning me-1"></i>Auto z rejestrów umów
    </span>
  </div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label fw-semibold small">V.1 Zatrudnieni na umowę o pracę</label>
        <input name="v_praca_liczba" type="number" min="0" class="form-control form-control-sm"
               value="<?= h($v_show_praca) ?>">
        <div class="form-text text-success"><i class="bi bi-lightning"></i> Auto: <?= $auto_v['umowy_praca_liczba'] ?> umów o pracę w <?= $rok ?></div>
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold small">V.2 Zatrudnieni wyłącznie w dz. gosp.</label>
        <input name="v_gosp_liczba" type="number" min="0" class="form-control form-control-sm"
               value="<?= h(_r($v,'gosp_liczba',0)) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold small">V.3a Z tytułu umów o pracę (brutto PLN)</label>
        <input name="v_praca_brutto" class="form-control form-control-sm font-monospace text-end"
               value="<?= h($v_show_pbrutto) ?>">
        <div class="form-text text-success"><i class="bi bi-lightning"></i> Auto: <?= freport_kwota($auto_v['umowy_praca_brutto']) ?> PLN</div>
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold small">V.3b Z tytułu umów cywilnoprawnych (brutto PLN)</label>
        <input name="v_cyw_brutto" class="form-control form-control-sm font-monospace text-end"
               value="<?= h($v_show_cbrutto) ?>">
        <div class="form-text text-success">
          <i class="bi bi-lightning"></i> Auto: <?= freport_kwota($auto_v['umowy_cywilne_brutto']) ?> PLN
          (<?= $auto_v['umowy_zlecenie_liczba'] ?> zlecenia, <?= $auto_v['umowy_dzielo_liczba'] ?> dzieła)
        </div>
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold small">V.3c Wynagrodzenia zarządu i organów (PLN)</label>
        <input name="v_zarzad_wynagr" class="form-control form-control-sm font-monospace text-end"
               value="<?= h(_r($v,'zarzad_wynagrodzenia','')) ?>" placeholder="0,00 lub —">
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     VI. Pożyczki
═══════════════════════════════════════════════════════ -->
<div class="card shadow-sm mb-3" id="secVI">
  <div class="card-header fw-bold" style="background:var(--strat-accent-bg);color:var(--strat-accent)">
    <i class="bi bi-bank me-1"></i>VI. Informacja o udzielonych pożyczkach
  </div>
  <div class="card-body">
    <div class="form-check mb-3">
      <input class="form-check-input" type="checkbox" name="vi_pozyczki" id="vi_poz"
             value="1" <?= ($report['vi_pozyczki']??0)?'checked':'' ?>>
      <label class="form-check-label fw-semibold" for="vi_poz">
        Fundacja udzielała pożyczek pieniężnych w roku <?= $rok ?>
      </label>
    </div>
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label fw-semibold small">VI.2 Wysokość udzielonych pożyczek</label>
        <input name="vi_wysokosc" class="form-control form-control-sm"
               value="<?= h($report['vi_wysokosc'] ?? '') ?>" placeholder="—">
      </div>
      <div class="col-md-8">
        <label class="form-label fw-semibold small">VI.3 Pożyczkobiorcy i warunki</label>
        <input name="vi_pozyczkobiorcy" class="form-control form-control-sm"
               value="<?= h($report['vi_pozyczkobiorcy'] ?? '') ?>" placeholder="—">
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold small">VI.4 Podstawa statutowa</label>
        <input name="vi_podstawa_stat" class="form-control form-control-sm"
               value="<?= h($report['vi_podstawa_stat'] ?? '') ?>" placeholder="—">
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     VII. Środki fundacji
═══════════════════════════════════════════════════════ -->
<div class="card shadow-sm mb-3" id="secVII">
  <div class="card-header fw-bold" style="background:var(--strat-accent-bg);color:var(--strat-accent)">
    <i class="bi bi-safe me-1"></i>VII. Środki fundacji (stan na 31.12.<?= $rok ?>)
  </div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-12">
        <label class="form-label fw-semibold small">VII.1 Rachunki płatnicze (bank, saldo)</label>
        <textarea name="vii_rachunki" class="form-control form-control-sm" rows="3"
                  placeholder="np. PKO BP SA, konto nr XX XXXX ... — saldo: X XXX,XX PLN"><?= h(_r($vii,'rachunki','')) ?></textarea>
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold small">VII.3 Kwota w gotówce (PLN)</label>
        <input name="vii_gotowka" class="form-control form-control-sm font-monospace text-end"
               value="<?= h(_r($vii,'gotowka','')) ?>" placeholder="0,00">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold small">VII.7 Aktywa (PLN)</label>
        <input name="vii_aktywa" class="form-control form-control-sm font-monospace text-end"
               value="<?= h(_r($vii,'aktywa','')) ?>" placeholder="0,00">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold small">VII.7 Zobowiązania (PLN)</label>
        <input name="vii_zobowiaz" class="form-control form-control-sm font-monospace text-end"
               value="<?= h(_r($vii,'zobowiazania','')) ?>" placeholder="0,00">
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold small">VII.4–6 Obligacje, nieruchomości, środki trwałe</label>
        <textarea name="vii_obligacje" class="form-control form-control-sm" rows="2"
                  placeholder="Opis nabytych obligacji, nieruchomości, środków trwałych lub —"><?= h(_r($vii,'obligacje','')) ?></textarea>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     VIII–XII
═══════════════════════════════════════════════════════ -->
<div class="card shadow-sm mb-3" id="secVIII">
  <div class="card-header fw-bold" style="background:var(--strat-accent-bg);color:var(--strat-accent)">
    <i class="bi bi-clipboard-check me-1"></i>VIII–XII. Działalność zlecona, podatki, AML, kontrole
  </div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-12">
        <label class="form-label fw-semibold small">VIII. Działalność zlecona przez podmioty państwowe/samorządowe</label>
        <textarea name="viii_opis" class="form-control form-control-sm" rows="3"
                  placeholder="Opis działalności zleconej, dotacji, zamówień publicznych lub —"><?= h($report['viii_opis'] ?? '') ?></textarea>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold small">IX.1 Zobowiązania podatkowe</label>
        <textarea name="ix_zobowiazania" class="form-control form-control-sm" rows="2"
                  placeholder="Opis zobowiązań podatkowych lub — jeśli nie dotyczy"><?= h($report['ix_zobowiazania'] ?? '') ?></textarea>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold small">IX.2 Deklaracje podatkowe</label>
        <textarea name="ix_deklaracje" class="form-control form-control-sm" rows="2"
                  placeholder="Informacja o składanych deklaracjach (CIT, VAT itp.)"><?= h($report['ix_deklaracje'] ?? '') ?></textarea>
      </div>
      <div class="col-md-6">
        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" name="x_aml" id="x_aml"
                 value="1" <?= ($report['x_aml']??0)?'checked':'' ?>>
          <label class="form-check-label fw-semibold" for="x_aml">
            X. Fundacja jest instytucją obowiązaną (AML)
          </label>
        </div>
        <textarea name="xi_platnosci" class="form-control form-control-sm mt-2" rows="2"
                  placeholder="XI. Płatności gotówkowe >= 10 000 EUR (data, kwota) lub —"><?= h($report['xi_platnosci'] ?? '') ?></textarea>
      </div>
      <div class="col-md-6">
        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" name="xii_kontrola" id="xii_kontr"
                 value="1" <?= ($report['xii_kontrola']??0)?'checked':'' ?>>
          <label class="form-check-label fw-semibold" for="xii_kontr">
            XII. W fundacji była przeprowadzona kontrola
          </label>
        </div>
        <textarea name="xii_wyniki" class="form-control form-control-sm mt-2" rows="2"
                  placeholder="XII.2 Wyniki kontroli (podmiot, wyniki pozytywne i negatywne)"><?= h($report['xii_wyniki'] ?? '') ?></textarea>
      </div>
    </div>

    <hr>
    <div>
      <label class="form-label fw-semibold small">Notatki wewnętrzne (nie drukowane)</label>
      <textarea name="notatki" class="form-control form-control-sm" rows="2"
                placeholder="Notatki dla zespołu..."><?= h($report['notatki'] ?? '') ?></textarea>
    </div>
  </div>
</div>

<!-- Przyciski -->
<div class="d-flex gap-2 flex-wrap pb-4">
  <button type="submit" name="_action" value="save" class="btn btn-sm"
          style="background:var(--strat-accent);color:#fff;border:none;min-width:120px">
    <i class="bi bi-floppy me-1"></i>Zapisz roboczy
  </button>
  <button type="submit" name="_action" value="submit" class="btn btn-primary btn-sm"
          onclick="return confirm('Oznaczyć sprawozdanie jako złożone?')">
    <i class="bi bi-send-check me-1"></i>Oznacz jako złożone
  </button>
  <?php if ($report): ?>
  <a href="foundation_report_print.php?rok=<?= $rok ?>" target="_blank"
     class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-printer me-1"></i>Drukuj / PDF
  </a>
  <button type="submit" name="_action" value="delete" class="btn btn-outline-danger btn-sm ms-auto"
          onclick="return confirm('Usunąć sprawozdanie?')">
    <i class="bi bi-trash me-1"></i>Usuń
  </button>
  <?php endif; ?>
</div>
</form>

<?php include dirname(__DIR__) . '/includes/footer_strategy.php'; ?>
