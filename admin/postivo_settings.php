<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/postivo.php';

require_role('admin');
$PAGE_TITLE = 'Postivo (poczta)';

$cfg = [
    'postivo_enabled'             => postivo_setting('postivo_enabled'),
    'postivo_api_key'             => postivo_setting('postivo_api_key'),
    'postivo_carrier_id'          => postivo_setting('postivo_carrier_id'),
    'postivo_service_id'          => postivo_setting('postivo_service_id'),
    'postivo_paper_id'            => postivo_setting('postivo_paper_id'),
    'postivo_envelope_id'         => postivo_setting('postivo_envelope_id'),
    'postivo_color_print'         => postivo_setting('postivo_color_print'),
    'postivo_duplex_print'        => postivo_setting('postivo_duplex_print'),
    'postivo_envelope_color_print'=> postivo_setting('postivo_envelope_color_print'),
    'postivo_sender_name'         => postivo_setting('postivo_sender_name'),
    'postivo_return_address'      => postivo_setting('postivo_return_address'),
];

$client     = new PostivoClient();
$configured = $client->is_configured();
$enabled    = $cfg['postivo_enabled'] === '1';
$test_result = null;

// ── Obsługa POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? 'save';

    if ($action === 'save') {
        $api_key = trim($_POST['postivo_api_key'] ?? '');

        $save = [
            'postivo_enabled'              => !empty($_POST['postivo_enabled']) ? '1' : '0',
            'postivo_api_key'              => $api_key ?: $cfg['postivo_api_key'],
            'postivo_carrier_id'           => trim($_POST['postivo_carrier_id']           ?? ''),
            'postivo_service_id'           => trim($_POST['postivo_service_id']           ?? ''),
            'postivo_paper_id'             => trim($_POST['postivo_paper_id']             ?? ''),
            'postivo_envelope_id'          => trim($_POST['postivo_envelope_id']          ?? ''),
            'postivo_color_print'          => !empty($_POST['postivo_color_print'])          ? '1' : '0',
            'postivo_duplex_print'         => !empty($_POST['postivo_duplex_print'])         ? '1' : '0',
            'postivo_envelope_color_print' => !empty($_POST['postivo_envelope_color_print']) ? '1' : '0',
            'postivo_sender_name'          => trim($_POST['postivo_sender_name']          ?? ''),
            'postivo_return_address'       => trim($_POST['postivo_return_address']       ?? ''),
        ];

        foreach ($save as $k => $v) {
            $exists = db_one("SELECT 1 FROM settings WHERE key_=?", [$k]);
            if ($exists) {
                db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$v, $k]);
            } else {
                db()->prepare("INSERT INTO settings (key_, value) VALUES (?,?)")->execute([$k, $v]);
            }
        }

        flash_set('success', 'Ustawienia Postivo.pl zapisane.');
        header('Location: postivo_settings.php');
        exit;
    }

    if ($action === 'test') {
        $client = new PostivoClient();
        if (!$client->is_configured()) {
            $test_result = ['ok' => false, 'msg' => 'Brak klucza API — najpierw zapisz konfigurację.'];
        } else {
            $test_result = $client->ping();
        }
    }
}

// ── Załaduj metadane z API (nośniki, usługi, papiery, koperty) ────────────────
$meta       = null;
$meta_error = null;
if ($configured) {
    try {
        $meta = $client->get_metadata();
    } catch (RuntimeException $e) {
        $meta_error = $e->getMessage();
    }
}

$carriers           = $meta['carriers']           ?? [];
$papers             = $meta['papers']             ?? [];
$envelope_templates = $meta['envelope_templates'] ?? [];

// Spłaszcz koperty do prostej listy [{envelope_id, envelope_name, max_sheets, group_name}]
$envelopes = [];
foreach ($envelope_templates as $group) {
    foreach ($group['envelope'] ?? [] as $env) {
        $envelopes[] = array_merge($env, ['group_name' => $group['envelope_group_name'] ?? '']);
    }
}

// Znajdź wybrane nazwy dla panelu statusu
$carrier_name = '';
$service_name = '';
$saved_cid    = (int)$cfg['postivo_carrier_id'];
$saved_sid    = (int)$cfg['postivo_service_id'];
foreach ($carriers as $c) {
    if ($c['carrier_id'] === $saved_cid) {
        $carrier_name = $c['carrier_name'];
        foreach ($c['services'] as $s) {
            if ($s['service_id'] === $saved_sid) {
                $service_name = $s['service_name'];
                break;
            }
        }
        break;
    }
}

include dirname(__DIR__) . '/includes/header.php';
?>

<style>
/* ── Kreator konfiguracji wysyłki (nośnik/usługa → papier/druk → podsumowanie) ── */
.pv-steps { display:flex; align-items:center; gap:.4rem; margin-bottom:1.1rem; flex-wrap:wrap }
.pv-dot { display:flex; align-items:center; gap:.45rem; border:none; background:transparent;
  padding:.15rem .3rem; border-radius:8px; cursor:pointer; color:#9CA3AF }
.pv-dot-num { display:inline-flex; align-items:center; justify-content:center; width:1.5rem; height:1.5rem;
  border-radius:50%; background:#F3F4F6; color:#6B7280; font-size:.78rem; font-weight:700; flex-shrink:0 }
.pv-dot-lbl { font-size:.8rem; font-weight:600; white-space:nowrap }
.pv-dot.active .pv-dot-num { background:var(--bs-primary,#0d6efd); color:#fff }
.pv-dot.active .pv-dot-lbl { color:#111827 }
.pv-dot.is-done .pv-dot-num { background:#DCFCE7; color:#15803D }
.pv-dot.is-done .pv-dot-num::before { content:"\2713" }
.pv-dot-sep { flex:1 1 1rem; min-width:.75rem; height:1px; background:#E5E7EB; align-self:center }
.pv-nav { display:flex; align-items:center; gap:.6rem; margin-top:1.2rem; padding-top:.9rem; border-top:1px solid #F1F2F4 }
.pv-nav-hint { flex:1; text-align:center }
.pv-summary dl { display:grid; grid-template-columns:auto 1fr; gap:.35rem .9rem; margin:0; font-size:.86rem }
.pv-summary dt { color:#6B7280; font-weight:600 }
.pv-summary dd { margin:0; color:#111827 }
@media (max-width:420px) { .pv-dot-lbl { display:none } }
</style>

<div class="d-flex align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-mailbox text-primary"></i> Postivo.pl — wysyłka listów</h4>
  <?php if ($enabled && $configured): ?>
    <span class="badge bg-success">Aktywne</span>
  <?php elseif ($enabled): ?>
    <span class="badge bg-warning text-dark">Włączone — wymaga klucza API</span>
  <?php else: ?>
    <span class="badge bg-secondary">Wyłączone</span>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<div class="row g-3">
<div class="col-xl-7">

<form method="post" id="postivoForm">
  <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
  <input type="hidden" name="_action" value="save">

  <!-- ── Aktywacja ──────────────────────────────────────────────────────────── -->
  <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-toggles"></i> Aktywacja</div>
    <div class="card-body">
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" role="switch"
               name="postivo_enabled" id="postivo_enabled" value="1"
               <?= $enabled ? 'checked' : '' ?>>
        <label class="form-check-label fw-semibold" for="postivo_enabled">
          Wysyłka listów przez Postivo.pl aktywna
        </label>
      </div>
      <div class="form-text">
        Gdy włączone, na stronie podglądu pisma pojawi się sekcja <em>Wysyłka pocztą (Postivo.pl)</em>
        umożliwiająca nadanie listu poleconego.
      </div>
    </div>
  </div>

  <!-- ── Klucz API ──────────────────────────────────────────────────────────── -->
  <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-key"></i> Autoryzacja API</div>
    <div class="card-body">
      <label class="form-label fw-semibold small">
        Klucz API <span class="text-danger">*</span>
      </label>
      <input type="password" name="postivo_api_key"
             class="form-control form-control-sm font-monospace"
             placeholder="<?= $configured ? '(zapisany — zostaw puste by nie zmieniać)' : 'Wklej klucz API z panelu Postivo.pl' ?>"
             autocomplete="new-password">
      <?php if ($configured): ?>
      <div class="form-text text-success">
        <i class="bi bi-check-circle"></i> Klucz API zapisany.
      </div>
      <?php else: ?>
      <div class="form-text">
        Pobierz klucz API w panelu <a href="https://postivo.pl" target="_blank" rel="noopener">postivo.pl</a>
        (Ustawienia → API). Po zapisaniu klucza strona automatycznie załaduje dostępne nośniki i usługi.
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Konfiguracja wysyłki ───────────────────────────────────────────────── -->
  <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
      <span><i class="bi bi-send"></i> Konfiguracja wysyłki</span>
      <?php if ($configured && $meta_error): ?>
        <span class="badge bg-warning text-dark small">
          <i class="bi bi-exclamation-triangle"></i> Błąd ładowania opcji
        </span>
      <?php elseif ($configured && $meta): ?>
        <span class="badge bg-success small">
          <i class="bi bi-check-circle"></i> Opcje załadowane z API
        </span>
      <?php endif; ?>
    </div>
    <div class="card-body">

      <?php if ($meta_error): ?>
      <div class="alert alert-warning py-2 small mb-3">
        <i class="bi bi-exclamation-triangle me-1"></i>
        Nie udało się załadować opcji z API: <?= h($meta_error) ?>
        Wartości można wpisać ręcznie (ID numeryczne).
      </div>
      <?php endif; ?>

      <?php if (!$configured): ?>
      <div class="alert alert-info py-2 small mb-0">
        <i class="bi bi-info-circle me-1"></i>
        Najpierw zapisz klucz API — opcje nośnika i usługi załadują się automatycznie z Postivo.pl.
      </div>
      <?php else: ?>

      <div class="pv-wizard">

        <div class="pv-steps" role="tablist" aria-label="Kroki konfiguracji wysyłki">
          <button type="button" class="pv-dot active" data-step="1" aria-selected="true">
            <span class="pv-dot-num">1</span><span class="pv-dot-lbl">Nośnik i usługa</span>
          </button>
          <span class="pv-dot-sep" aria-hidden="true"></span>
          <button type="button" class="pv-dot" data-step="2" aria-selected="false">
            <span class="pv-dot-num">2</span><span class="pv-dot-lbl">Papier i druk</span>
          </button>
          <span class="pv-dot-sep" aria-hidden="true"></span>
          <button type="button" class="pv-dot" data-step="3" aria-selected="false">
            <span class="pv-dot-num">3</span><span class="pv-dot-lbl">Podsumowanie</span>
          </button>
        </div>

        <!-- Krok 1: Nośnik + Usługa -->
        <div class="pv-pane" data-pane="1">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold small" for="postivo_carrier_id">
                Nośnik <span class="text-danger">*</span>
              </label>
              <?php if ($carriers): ?>
              <select name="postivo_carrier_id" id="postivo_carrier_id" class="form-select form-select-sm" required>
                <option value="">— wybierz nośnika —</option>
                <?php foreach ($carriers as $c): ?>
                <option value="<?= $c['carrier_id'] ?>" <?= $saved_cid === $c['carrier_id'] ? 'selected' : '' ?>>
                  <?= h($c['carrier_name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
              <?php else: ?>
              <input type="number" name="postivo_carrier_id" id="postivo_carrier_id" min="1"
                     class="form-control form-control-sm font-monospace"
                     value="<?= h($cfg['postivo_carrier_id']) ?>" placeholder="ID nośnika">
              <?php endif; ?>
            </div>

            <div class="col-12">
              <label class="form-label fw-semibold small" for="postivo_service_id">
                Usługa <span class="text-danger">*</span>
              </label>
              <?php if ($carriers): ?>
              <select name="postivo_service_id" id="postivo_service_id" class="form-select form-select-sm" required>
                <option value="">— najpierw wybierz nośnika —</option>
              </select>
              <?php else: ?>
              <input type="number" name="postivo_service_id" id="postivo_service_id" min="1"
                     class="form-control form-control-sm font-monospace"
                     value="<?= h($cfg['postivo_service_id']) ?>" placeholder="ID usługi">
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Krok 2: Papier + Koperta + opcje druku -->
        <div class="pv-pane" data-pane="2" hidden>
          <div class="row g-3">
            <div class="col-sm-6">
              <label class="form-label fw-semibold small" for="postivo_paper_id">
                Papier <span class="text-muted fw-normal">(opcjonalnie)</span>
              </label>
              <?php if ($papers): ?>
              <select name="postivo_paper_id" id="postivo_paper_id" class="form-select form-select-sm">
                <option value="">— domyślny —</option>
                <?php foreach ($papers as $p): ?>
                <option value="<?= $p['paper_id'] ?>" <?= (int)$cfg['postivo_paper_id'] === $p['paper_id'] ? 'selected' : '' ?>>
                  <?= h($p['paper_name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
              <?php else: ?>
              <input type="number" name="postivo_paper_id" id="postivo_paper_id" min="1"
                     class="form-control form-control-sm font-monospace"
                     value="<?= h($cfg['postivo_paper_id']) ?>" placeholder="ID papieru (opcja)">
              <?php endif; ?>
            </div>

            <div class="col-sm-6">
              <label class="form-label fw-semibold small" for="postivo_envelope_id">
                Koperta <span class="text-muted fw-normal">(opcjonalnie)</span>
              </label>
              <?php if ($envelopes): ?>
              <select name="postivo_envelope_id" id="postivo_envelope_id" class="form-select form-select-sm">
                <option value="">— domyślna —</option>
                <?php foreach ($envelope_templates as $group): ?>
                <?php if (empty($group['envelope'])) continue; ?>
                <optgroup label="<?= h($group['envelope_group_name'] ?: 'Koperty') ?>">
                  <?php foreach ($group['envelope'] as $e): ?>
                  <option value="<?= $e['envelope_id'] ?>" <?= (int)$cfg['postivo_envelope_id'] === $e['envelope_id'] ? 'selected' : '' ?>>
                    <?= h($e['envelope_name']) ?>
                    <?php if ($e['max_sheets']): ?>(max <?= $e['max_sheets'] ?> ark.)<?php endif; ?>
                  </option>
                  <?php endforeach; ?>
                </optgroup>
                <?php endforeach; ?>
              </select>
              <?php else: ?>
              <input type="number" name="postivo_envelope_id" id="postivo_envelope_id" min="1"
                     class="form-control form-control-sm font-monospace"
                     value="<?= h($cfg['postivo_envelope_id']) ?>" placeholder="ID koperty (opcja)">
              <?php endif; ?>
            </div>

            <div class="col-12">
              <label class="form-label fw-semibold small d-block">Opcje druku</label>
              <div class="d-flex flex-wrap gap-3">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="postivo_color_print"
                         id="postivo_color_print" value="1"
                         <?= $cfg['postivo_color_print'] === '1' ? 'checked' : '' ?>>
                  <label class="form-check-label small" for="postivo_color_print">
                    Druk kolorowy
                  </label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="postivo_duplex_print"
                         id="postivo_duplex_print" value="1"
                         <?= $cfg['postivo_duplex_print'] === '1' ? 'checked' : '' ?>>
                  <label class="form-check-label small" for="postivo_duplex_print">
                    Druk dwustronny
                  </label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="postivo_envelope_color_print"
                         id="postivo_envelope_color_print" value="1"
                         <?= $cfg['postivo_envelope_color_print'] === '1' ? 'checked' : '' ?>>
                  <label class="form-check-label small" for="postivo_envelope_color_print">
                    Druk kolorowy koperty
                  </label>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Krok 3: Podsumowanie -->
        <div class="pv-pane" data-pane="3" hidden>
          <div id="pvSummary" class="pv-summary"></div>
          <div class="form-text mt-2">
            Wygląda dobrze? Kliknij <strong>Zapisz konfigurację</strong> na dole strony.
          </div>
        </div>

        <div class="pv-nav">
          <button type="button" class="btn btn-sm btn-outline-secondary" id="pvBack" disabled>
            <i class="bi bi-arrow-left"></i> Wstecz
          </button>
          <span class="pv-nav-hint text-muted small" id="pvHint"></span>
          <button type="button" class="btn btn-sm btn-primary" id="pvNext">
            Dalej <i class="bi bi-arrow-right"></i>
          </button>
        </div>

      </div><!-- /pv-wizard -->
      <?php endif; // configured ?>

    </div>
  </div>

  <!-- ── Dane nadawcy ───────────────────────────────────────────────────────── -->
  <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-person-vcard"></i> Dane nadawcy (informacyjne)</div>
    <div class="card-body">
      <div class="mb-3">
        <label class="form-label fw-semibold small">Nazwa nadawcy</label>
        <input type="text" name="postivo_sender_name"
               class="form-control form-control-sm"
               value="<?= h($cfg['postivo_sender_name']) ?>"
               placeholder="np. Fundacja XYZ">
        <div class="form-text">
          Nadawca jest konfigurowany w koncie Postivo.pl (panel → Nadawcy). To pole jest pomocnicze — przechowuje nazwę dla celów audytowych.
        </div>
      </div>
      <div class="mb-0">
        <label class="form-label fw-semibold small">Adres zwrotny</label>
        <textarea name="postivo_return_address" rows="3"
                  class="form-control form-control-sm"
                  placeholder="ul. Przykładowa 1&#10;00-001 Warszawa"><?= h($cfg['postivo_return_address']) ?></textarea>
        <div class="form-text">
          Adres zwrotny do wglądu. Rzeczywisty adres zwrotny pochodzi z konta Postivo.pl.
        </div>
      </div>
    </div>
  </div>

  <div class="d-flex gap-2 mb-3">
    <button type="submit" class="btn btn-primary">
      <i class="bi bi-check-lg"></i> Zapisz konfigurację
    </button>
  </div>
</form>

<!-- ── Test połączenia ────────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold"><i class="bi bi-wifi"></i> Test połączenia</div>
  <div class="card-body">
    <?php if ($test_result !== null): ?>
    <div class="alert alert-<?= $test_result['ok'] === true ? 'success' : 'danger' ?> py-2 small mb-3 d-flex align-items-start gap-2">
      <i class="bi bi-<?= $test_result['ok'] ? 'check-circle-fill' : 'x-circle-fill' ?> mt-1 flex-shrink-0"></i>
      <?= h($test_result['msg']) ?>
    </div>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="test">
      <button type="submit" class="btn btn-sm btn-outline-primary" <?= !$configured ? 'disabled' : '' ?>>
        <i class="bi bi-arrow-right-circle"></i> Testuj połączenie z API
      </button>
    </form>
    <div class="form-text mt-2">
      Wysyła zapytanie do GET /account i sprawdza poprawność klucza. Przy sukcesie pokazuje saldo konta.
    </div>
  </div>
</div>

</div><!-- /col-xl-7 -->

<!-- ── Panel informacyjny ──────────────────────────────────────────────────── -->
<div class="col-xl-5">

  <!-- Status -->
  <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-shield-check"></i> Status konfiguracji</div>
    <div class="card-body small">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="text-muted">Moduł</span>
        <span class="badge <?= $enabled ? 'bg-success' : 'bg-secondary' ?>">
          <?= $enabled ? 'Włączony' : 'Wyłączony' ?>
        </span>
      </div>
      <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="text-muted">Klucz API</span>
        <span class="badge <?= $configured ? 'bg-success' : 'bg-danger' ?>">
          <?= $configured ? 'Zapisany' : 'Brak' ?>
        </span>
      </div>
      <?php
        $has_ship_cfg = ($saved_cid && $saved_sid) || postivo_setting('postivo_config_id');
      ?>
      <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="text-muted">Nośnik</span>
        <span class="fw-semibold text-end" style="max-width:60%">
          <?= $carrier_name ? h($carrier_name) : ($saved_cid ? 'ID ' . $saved_cid : '—') ?>
        </span>
      </div>
      <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="text-muted">Usługa</span>
        <span class="fw-semibold text-end" style="max-width:60%">
          <?= $service_name ? h($service_name) : ($saved_sid ? 'ID ' . $saved_sid : '—') ?>
        </span>
      </div>
      <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="text-muted">Druk kolorowy</span>
        <span class="badge <?= $cfg['postivo_color_print'] === '1' ? 'bg-primary' : 'bg-secondary' ?>">
          <?= $cfg['postivo_color_print'] === '1' ? 'Tak' : 'Nie' ?>
        </span>
      </div>
      <div class="d-flex justify-content-between align-items-center">
        <span class="text-muted">Konfiguracja wysyłki</span>
        <span class="badge <?= $has_ship_cfg ? 'bg-success' : 'bg-danger' ?>">
          <?= $has_ship_cfg ? 'Gotowa' : 'Brak' ?>
        </span>
      </div>
    </div>
  </div>

  <!-- Co to jest Postivo.pl -->
  <div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-info-circle"></i> Czym jest Postivo.pl?</div>
    <div class="card-body small">
      <p class="mb-2">
        <a href="https://postivo.pl" target="_blank" rel="noopener"><strong>Postivo.pl</strong></a>
        to polska usługa wysyłki fizycznych listów przez internet. Wystarczy załączyć plik PDF —
        Postivo drukuje, składa, kopertuje i nadaje list polecony w Twoim imieniu.
      </p>
      <p class="mb-1 fw-semibold">Jak zacząć:</p>
      <ol class="ps-3 mb-2">
        <li>Zarejestruj konto na <a href="https://postivo.pl" target="_blank" rel="noopener">postivo.pl</a></li>
        <li>Doładuj konto lub podaj dane do faktury</li>
        <li>Pobierz klucz API z panelu (Ustawienia → API)</li>
        <li>Wklej klucz i zapisz — opcje nośnika/usługi załadują się automatycznie</li>
        <li>Wybierz nośnik, usługę i opcje druku</li>
      </ol>
      <p class="mb-0 fw-semibold">Cennik:</p>
      <ul class="ps-3 mb-0">
        <li>Cena zależy od wybranej usługi i opcji druku</li>
        <li>Druk i kopertowanie wliczone w cenę</li>
        <li>Szczegóły na <a href="https://postivo.pl" target="_blank" rel="noopener">postivo.pl</a></li>
      </ul>
    </div>
  </div>

  <!-- Jak to działa w systemie -->
  <div class="card shadow-sm">
    <div class="card-header fw-semibold"><i class="bi bi-diagram-3"></i> Jak to działa</div>
    <div class="card-body small">
      <p class="mb-2">
        Po skonfigurowaniu, na stronie podglądu każdego pisma pojawi się sekcja
        <em>Wysyłka pocztą</em>. Aby wysłać list:
      </p>
      <ol class="ps-3 mb-2">
        <li>Do pisma musi być załączony plik PDF</li>
        <li>Wypełnij adres odbiorcy w formularzu</li>
        <li>Kliknij <em>Wyślij listem poleconym</em></li>
        <li>System przekaże PDF do Postivo.pl z wybraną konfiguracją</li>
        <li>Status przesyłki możesz odświeżać na stronie pisma</li>
      </ol>
      <p class="mb-0 text-muted">
        Wysyłka jest bezpowrotna — sprawdź adres przed zleceniem.
      </p>
    </div>
  </div>

</div><!-- /col-xl-5 -->
</div><!-- /row -->

<?php if ($carriers): ?>
<script>
(function () {
  const CARRIERS = <?= json_encode($carriers, JSON_UNESCAPED_UNICODE) ?>;
  const savedSid = <?= (int)$cfg['postivo_service_id'] ?>;

  const carrierSel = document.getElementById('postivo_carrier_id');
  const serviceSel = document.getElementById('postivo_service_id');
  if (!carrierSel || !serviceSel) return;

  function updateServices() {
    const cid = parseInt(carrierSel.value, 10);
    serviceSel.innerHTML = '<option value="">— wybierz usługę —</option>';
    if (!cid) return;
    const carrier = CARRIERS.find(c => c.carrier_id === cid);
    if (!carrier) return;
    carrier.services.forEach(s => {
      const opt = document.createElement('option');
      opt.value = s.service_id;
      opt.textContent = s.service_name
        + (s.service_return_fee ? ' (zwrot: ' + s.service_return_fee.toFixed(2) + ' zł)' : '');
      if (s.service_id === savedSid) opt.selected = true;
      serviceSel.appendChild(opt);
    });
  }

  carrierSel.addEventListener('change', updateServices);
  updateServices();
})();
</script>
<?php endif; ?>

<?php if ($configured): ?>
<script>
(function () {
  var wizard = document.querySelector('.pv-wizard');
  if (!wizard) return;

  var dots  = Array.prototype.slice.call(wizard.querySelectorAll('.pv-dot'));
  var panes = Array.prototype.slice.call(wizard.querySelectorAll('.pv-pane'));
  var back  = document.getElementById('pvBack');
  var next  = document.getElementById('pvNext');
  var hint  = document.getElementById('pvHint');
  var step  = 1;
  var TOTAL = panes.length;

  function fieldLabel(id, emptyLabel) {
    var el = document.getElementById(id);
    if (!el) return '';
    if (el.tagName === 'SELECT') {
      if (!el.value) return emptyLabel;
      return el.options[el.selectedIndex].textContent.trim();
    }
    return el.value ? el.value : emptyLabel;
  }

  function buildSummary() {
    var box = document.getElementById('pvSummary');
    if (!box) return;
    var color   = document.getElementById('postivo_color_print');
    var duplex  = document.getElementById('postivo_duplex_print');
    var envColor = document.getElementById('postivo_envelope_color_print');
    var rows = [
      ['Nośnik',  fieldLabel('postivo_carrier_id',  '— nie wybrano —')],
      ['Usługa',  fieldLabel('postivo_service_id',  '— nie wybrano —')],
      ['Papier',  fieldLabel('postivo_paper_id',    'domyślny')],
      ['Koperta', fieldLabel('postivo_envelope_id', 'domyślna')],
      ['Druk',    [
                    color   && color.checked   ? 'kolorowy'          : 'czarno-biały',
                    duplex  && duplex.checked  ? 'dwustronny'        : 'jednostronny',
                    envColor && envColor.checked ? 'koperta kolorowa' : null,
                  ].filter(Boolean).join(', ')],
    ];
    box.innerHTML = '<dl>' + rows.map(function (r) {
      return '<dt>' + r[0] + '</dt><dd>' + r[1].replace(/[<>&]/g, function (c) {
        return {'<':'&lt;','>':'&gt;','&':'&amp;'}[c];
      }) + '</dd>';
    }).join('') + '</dl>';
  }

  function requiredOk(n) {
    // Krok 1 wymaga nośnika i usługi — bez tego dalsze kroki nie mają sensu.
    if (n !== 1) return true;
    var c = document.getElementById('postivo_carrier_id');
    var s = document.getElementById('postivo_service_id');
    return !!(c && c.value) && !!(s && s.value);
  }

  function show(n) {
    n = Math.max(1, Math.min(TOTAL, n));
    step = n;
    panes.forEach(function (p) { p.hidden = parseInt(p.dataset.pane, 10) !== n; });
    dots.forEach(function (d) {
      var dn = parseInt(d.dataset.step, 10);
      d.classList.toggle('active', dn === n);
      d.classList.toggle('is-done', dn < n);
      d.setAttribute('aria-selected', dn === n ? 'true' : 'false');
    });
    back.disabled = n === 1;
    next.textContent = '';
    if (n === TOTAL) {
      next.innerHTML = 'Odśwież podsumowanie <i class="bi bi-arrow-clockwise"></i>';
      buildSummary();
    } else {
      next.innerHTML = 'Dalej <i class="bi bi-arrow-right"></i>';
    }
    hint.textContent = !requiredOk(1) ? 'Nośnik i usługa są wymagane, zanim przejdziesz dalej.' : '';
  }

  next.addEventListener('click', function () {
    if (!requiredOk(step)) { hint.textContent = 'Wybierz nośnik i usługę, żeby przejść dalej.'; return; }
    if (step === TOTAL) { buildSummary(); return; }
    show(step + 1);
  });
  back.addEventListener('click', function () { show(step - 1); });
  dots.forEach(function (d) {
    d.addEventListener('click', function () {
      var target = parseInt(d.dataset.step, 10);
      if (target > 1 && !requiredOk(1)) { hint.textContent = 'Wybierz najpierw nośnik i usługę (krok 1).'; return; }
      show(target);
    });
  });

  show(1);
})();
</script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
