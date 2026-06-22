<?php
/**
 * Wspólny nagłówek widoku umowy.
 *
 * Oczekiwane zmienne (ustawiane w każdym view.php przed include):
 *   $_cvh_type       string   — typ umowy ('zlecenie','wolontariat',...)
 *   $_cvh_id         int      — id rekordu
 *   $_cvh_row        array    — wiersz z bazy (numer_umowy, status, data_zawarcia, ...)
 *   $_cvh_icon       string   — klasa Bootstrap Icon (np. 'bi-person-lines-fill')
 *   $_cvh_label      string   — czytelna nazwa typu (np. 'Umowa zlecenie')
 *   $_cvh_person     string   — imię i nazwisko / nazwa firmy
 *   $_cvh_person_sub string   — podtytuł osoby (e-mail, stanowisko, rola...)
 *   $_cvh_amount     ?float   — główna kwota (null = brak)
 *   $_cvh_amount_lbl string   — etykieta kwoty ('Brutto','Wartość brutto',...)
 *   $_cvh_end_date   ?string  — data zakończenia / termin (null = bezterminowo/brak)
 *   $_cvh_subject    ?string  — krótki opis przedmiotu (null = pomiń)
 *   $_cvh_list_url   string   — URL listy umów (np. APP_URL.'/contracts/zlecenie/list.php')
 *   $_cvh_edit_url   string   — URL edycji (np. 'edit.php?id=5')
 *
 *   $_pending_term   array|null — oczekujący wniosek o rozwiązanie (opcjonalnie)
 *   $_m365_creds     array|null — jednorazowe dane logowania M365 (opcjonalnie)
 */

require_once __DIR__ . '/envelopes.php';
require_once __DIR__ . '/print_templates.php';

$_cvh_st       = STATUS_LABELS[$_cvh_row['status']] ?? ['label' => $_cvh_row['status'], 'class' => 'secondary'];
$_cvh_status   = $_cvh_row['status'];
$_cvh_locked   = contract_is_locked($_cvh_row); // status 'aneks' = blokada

// Pasek postępu trwania umowy
$_cvh_prog = null;
if (!empty($_cvh_row['data_zawarcia']) && !empty($_cvh_end_date)) {
    $s = strtotime($_cvh_row['data_zawarcia']);
    $e = strtotime($_cvh_end_date);
    $n = time();
    if ($e > $s) {
        $pct  = min(100, max(0, round(($n - $s) / ($e - $s) * 100)));
        $days = max(0, (int)(($e - $n) / 86400));
        $_cvh_prog = ['pct' => $pct, 'days_left' => $days, 'ended' => $n > $e];
    }
}

// Kolor akcentu na podstawie statusu
$_cvh_accent = match($_cvh_st['class']) {
    'primary'   => '#3b82f6',
    'info'      => '#0ea5e9',
    'success'   => '#22c55e',
    'warning'   => '#f59e0b',
    'danger'    => '#ef4444',
    default     => '#94a3b8',
};
?>

<?php if (!empty($_pending_term)): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 no-print mb-2 py-2">
  <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0"></i>
  <div class="small">
    <strong>Oczekujący wniosek o rozwiązanie</strong> —
    złożony przez <?= h($_pending_term['requester_name']) ?>
    dnia <?= date_pl($_pending_term['created_at']) ?>.
    <?php if (is_admin()): ?>
    <a href="<?= APP_URL ?>/admin/terminations.php?status=oczekuje" class="alert-link ms-2">Rozpatrz →</a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($_m365_creds)): ?>
<div class="alert alert-warning border-warning mb-2 small">
  <strong><i class="bi bi-key-fill"></i> Hasło jednorazowe (zapisz teraz!):</strong><br>
  <span class="d-inline-flex align-items-center gap-1 mt-1">
    Login: <code><?= h($_m365_creds['login']) ?></code>
    <button type="button" data-copy="<?= h($_m365_creds['login']) ?>" title="Kopiuj login"
            style="background:none;border:none;padding:0;color:#92400e;cursor:pointer;font-size:.85rem;line-height:1">
      <i class="bi bi-copy"></i>
    </button>
  </span>
  &nbsp;/&nbsp;
  <span class="d-inline-flex align-items-center gap-1">
    Hasło: <code><?= h($_m365_creds['pass']) ?></code>
    <button type="button" data-copy="<?= h($_m365_creds['pass']) ?>" title="Kopiuj hasło"
            style="background:none;border:none;padding:0;color:#92400e;cursor:pointer;font-size:.85rem;line-height:1">
      <i class="bi bi-copy"></i>
    </button>
  </span>
  <?php if ($_m365_creds['sent']): ?>
  <br><span class="text-success"><i class="bi bi-check-circle"></i> Mail wysłany na: <?= h($_m365_creds['email']) ?></span>
  <?php else: ?>
  <br><span class="text-warning"><i class="bi bi-exclamation-triangle"></i> Mail nie wysłany.</span>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Contract hero header ──────────────────────────────────────────── -->
<div class="contract-hero no-print mb-3"
     style="--cvh-accent: <?= $_cvh_accent ?>">

  <div class="contract-hero-top">

    <!-- Left: osoba (tytuł) + typ/numer (nadtytuł) -->
    <div class="contract-hero-id">
      <div class="contract-hero-eyebrow">
        <i class="bi <?= h($_cvh_icon) ?>"></i>
        <?= h($_cvh_label) ?>
        <?php if (!empty($_cvh_row['numer_umowy'])): ?>
        <span class="cvh-num"><?= h($_cvh_row['numer_umowy']) ?></span>
        <button type="button"
                data-copy="<?= h($_cvh_row['numer_umowy']) ?>"
                title="Kopiuj numer umowy"
                style="background:none;border:none;padding:0 0 0 .25rem;color:#94a3b8;cursor:pointer;font-size:.8rem;line-height:1;vertical-align:middle">
          <i class="bi bi-copy"></i>
        </button>
        <?php endif; ?>
      </div>
      <div class="contract-hero-title"><?= h($_cvh_person ?: $_cvh_label) ?></div>
      <?php if ($_cvh_person && $_cvh_person_sub): ?>
      <div class="contract-hero-subtitle">
        <?= h($_cvh_person_sub) ?>
        <?php if (filter_var($_cvh_person_sub, FILTER_VALIDATE_EMAIL)): ?>
        <button type="button"
                data-copy="<?= h($_cvh_person_sub) ?>"
                title="Kopiuj e-mail"
                style="background:none;border:none;padding:0;color:#94a3b8;cursor:pointer;font-size:.78rem;line-height:1;vertical-align:middle">
          <i class="bi bi-copy"></i>
        </button>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Right: actions -->
    <div class="contract-hero-actions">
      <?php if (can_edit() && !$_cvh_locked): ?>
      <a href="<?= h($_cvh_edit_url) ?>" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-pencil"></i> <span class="d-none d-sm-inline">Edytuj</span>
      </a>
      <?php elseif ($_cvh_locked): ?>
      <span class="btn btn-sm btn-outline-secondary disabled" title="Umowa zablokowana — zawarty aneks">
        <i class="bi bi-lock-fill"></i> <span class="d-none d-sm-inline">Zablokowana</span>
      </span>
      <?php endif; ?>
      <?php if (can_edit() && ($_cvh_type ?? '') === 'wolontariat'): ?>
      <a href="<?= APP_URL ?>/contracts/wolontariat/renew.php?id=<?= (int)($_cvh_id ?? 0) ?>"
         class="btn btn-sm btn-outline-success" title="Przedłuż porozumienie">
        <i class="bi bi-arrow-repeat"></i> <span class="d-none d-sm-inline">Przedłuż</span>
      </a>
      <?php endif; ?>

      <?php if (($_cvh_type ?? '') === 'wolontariat'): ?>
      <!-- Dropdown: Dokumenty -->
      <?php $_cvh_wid = (int)($_cvh_id ?? 0); $_cvh_burl = APP_URL . '/contracts/wolontariat/potwierdzenie.php?id=' . $_cvh_wid; ?>
      <div class="dropdown">
        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button"
                data-bs-toggle="dropdown" aria-expanded="false" title="Dokumenty, koperty i wydruki">
          <i class="bi bi-file-earmark-text"></i> <span class="d-none d-sm-inline">Dokumenty i wydruki</span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end" style="min-width:230px">
          <li><h6 class="dropdown-header"><i class="bi bi-folder2-open me-1"></i>Karta do segregatora</h6></li>
          <li class="d-flex align-items-center px-2 gap-1">
            <a class="dropdown-item flex-grow-1" href="<?= $_cvh_burl ?>&typ=wkladka&preview=1" target="_blank">
              <i class="bi bi-printer me-2 text-danger"></i>Drukuj / PDF
            </a>
            <form method="post" class="flex-shrink-0">
              <?= csrf_field() ?><input type="hidden" name="_action" value="queue_doc"><input type="hidden" name="doc_type" value="wolontariat_wkladka">
              <button type="submit" class="btn btn-sm btn-outline-warning py-0 px-1 border-0" title="Nie mam drukarki — dodaj do kolejki"><i class="bi bi-collection" style="font-size:.8rem"></i></button>
            </form>
          </li>
          <?php if (class_exists('ZipArchive')): ?>
          <li>
            <a class="dropdown-item" href="<?= $_cvh_burl ?>&typ=wkladka&format=docx">
              <i class="bi bi-file-earmark-word me-2 text-primary"></i>Pobierz DOCX
            </a>
          </li>
          <?php endif; ?>
          <li><hr class="dropdown-divider"></li>
          <li><h6 class="dropdown-header"><i class="bi bi-person-check me-1"></i>Potwierdzenie dla wolontariusza</h6></li>
          <li class="d-flex align-items-center px-2 gap-1">
            <a class="dropdown-item flex-grow-1" href="<?= $_cvh_burl ?>&typ=wolontariusz&preview=1" target="_blank">
              <i class="bi bi-printer me-2 text-danger"></i>Drukuj / PDF
            </a>
            <form method="post" class="flex-shrink-0">
              <?= csrf_field() ?><input type="hidden" name="_action" value="queue_doc"><input type="hidden" name="doc_type" value="wolontariat_confirm">
              <button type="submit" class="btn btn-sm btn-outline-warning py-0 px-1 border-0" title="Nie mam drukarki — dodaj do kolejki"><i class="bi bi-collection" style="font-size:.8rem"></i></button>
            </form>
          </li>
          <?php if (class_exists('ZipArchive')): ?>
          <li>
            <a class="dropdown-item" href="<?= $_cvh_burl ?>&typ=wolontariusz&format=docx">
              <i class="bi bi-file-earmark-word me-2 text-primary"></i>Pobierz DOCX
            </a>
          </li>
          <?php endif; ?>
          <li><hr class="dropdown-divider"></li>
          <li><h6 class="dropdown-header"><i class="bi bi-arrow-repeat me-1"></i>Aneks</h6></li>
          <li class="d-flex align-items-center px-2 gap-1">
            <a class="dropdown-item flex-grow-1" href="<?= APP_URL ?>/contracts/wolontariat/aneks.php?id=<?= $_cvh_wid ?>&format=pdf&preview=1" target="_blank">
              <i class="bi bi-printer me-2 text-danger"></i>Drukuj / PDF
            </a>
            <form method="post" class="flex-shrink-0">
              <?= csrf_field() ?><input type="hidden" name="_action" value="queue_doc"><input type="hidden" name="doc_type" value="wolontariat_aneks">
              <button type="submit" class="btn btn-sm btn-outline-warning py-0 px-1 border-0" title="Nie mam drukarki — dodaj do kolejki"><i class="bi bi-collection" style="font-size:.8rem"></i></button>
            </form>
          </li>
          <?php if (class_exists('ZipArchive')): ?>
          <li>
            <a class="dropdown-item" href="<?= APP_URL ?>/contracts/wolontariat/aneks.php?id=<?= $_cvh_wid ?>&format=docx">
              <i class="bi bi-file-earmark-word me-2 text-primary"></i>Pobierz DOCX
            </a>
          </li>
          <?php endif; ?>
          <li><hr class="dropdown-divider"></li>
          <li>
            <a class="dropdown-item" href="<?= APP_URL ?>/contracts/wolontariat/print.php?id=<?= $_cvh_wid ?>" target="_blank">
              <i class="bi bi-file-earmark-text me-2 text-secondary"></i>Wydruk umowy (pełny)
            </a>
          </li>
          <?php
            // Koperty i szablony pism — wspólne wzory (zamiast osobnego dropdownu „Wydruki", bez duplikatu)
            $_cvh_frag_src = 'contract_id=' . $_cvh_wid . '&type=' . rawurlencode((string)$_cvh_type);
            $_cvh_env_frag = function_exists('envelope_dropdown_html')       ? envelope_dropdown_html($_cvh_frag_src, ['fragment' => true]) : '';
            $_cvh_doc_frag = function_exists('print_template_dropdown_html')  ? print_template_dropdown_html($_cvh_frag_src, ['fragment' => true]) : '';
          ?>
          <li><hr class="dropdown-divider"></li>
          <?php if ($_cvh_env_frag !== ''): ?>
          <?= $_cvh_env_frag ?>
          <?php else: ?>
          <li><h6 class="dropdown-header"><i class="bi bi-envelope me-1"></i>Koperty</h6></li>
          <li><a class="dropdown-item" href="<?= $_cvh_burl ?>&typ=koperta_a4&preview=1" target="_blank"><i class="bi bi-printer me-2 text-danger"></i>Koperta A4 (210×297 mm)</a></li>
          <li><a class="dropdown-item" href="<?= $_cvh_burl ?>&typ=koperta_c4&preview=1" target="_blank"><i class="bi bi-printer me-2 text-danger"></i>Koperta C4 (229×324 mm)</a></li>
          <?php endif; ?>
          <?php if ($_cvh_doc_frag !== ''): ?><li><hr class="dropdown-divider"></li><?= $_cvh_doc_frag ?><?php endif; ?>
        </ul>
      </div>
      <?php endif; ?>

      <?php if (can_edit() && ($_cvh_type ?? '') === 'wolontariat'): ?>
      <?php if (empty($_pending_term) && defined('TERMINABLE_STATUSES') && in_array($_cvh_status, TERMINABLE_STATUSES)): ?>
      <button type="button"
              class="btn btn-sm btn-outline-danger"
              data-bs-toggle="modal"
              data-bs-target="#terminateModal"
              title="Złóż wniosek o rozwiązanie umowy">
        <i class="bi bi-x-circle"></i> <span class="d-none d-sm-inline">Rozwiąż</span>
      </button>
      <?php endif; ?>
      <?php endif; ?>
      <?php $_cvh_src = 'contract_id=' . (int)($_cvh_id ?? 0) . '&type=' . rawurlencode((string)($_cvh_type ?? '')); ?>
      <?php if (($_cvh_type ?? '') !== 'wolontariat'): /* dla wolontariatu wydruki są w dropdownie „Dokumenty i wydruki" */ ?>
      <?= wydruki_dropdown_html($_cvh_src) ?>
      <?php endif; ?>
      <button onclick="window.print()" class="btn btn-sm btn-outline-dark" title="Drukuj tę stronę">
        <i class="bi bi-printer"></i>
      </button>
      <a href="<?= h($_cvh_list_url) ?>" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> <span class="d-none d-sm-inline">Lista</span>
      </a>
    </div>

  </div><!-- /hero-top -->

  <!-- Stats row -->
  <div class="contract-hero-stats">

    <!-- Status (quick-change for editors) -->
    <div class="contract-hero-stat">
      <div class="cvh-stat-label">Status</div>
      <?php if (can_edit() && !$_cvh_locked): ?>
      <span class="d-inline-flex align-items-center gap-2">
        <select id="cvhStatusSelect"
                class="cvh-status-select cvh-status-<?= h($_cvh_st['class']) ?>"
                data-contract-id="<?= (int)$_cvh_row['id'] ?>"
                data-contract-type="<?= h($_cvh_type) ?>"
                onchange="cvhSetStatus(this)">
          <?php
          $_cvh_is_admin   = (current_user()['role'] ?? '') === 'admin';
          $_cvh_next_ok    = status_allowed_next($_cvh_status, $_cvh_is_admin);
          foreach (STATUS_LABELS as $_sv => $_sm):
            if ($_sv === 'aneks') continue; // nie można ręcznie ustawić aneksu
            if ($_sv !== $_cvh_status && !in_array($_sv, $_cvh_next_ok, true)) continue;
          ?>
          <option value="<?= h($_sv) ?>" <?= $_cvh_status === $_sv ? 'selected' : '' ?>>
            <?= h($_sm['label']) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <span id="cvhStatusSpinner" class="spinner-border spinner-border-sm text-secondary d-none" role="status" aria-hidden="true"></span>
      </span>
      <script>
      function cvhSetStatus(sel) {
        var prev = sel.dataset.prevValue || sel.value;
        // Zapamiętaj poprzednią wartość przy pierwszym uruchomieniu
        if (!sel.dataset.prevValue) sel.dataset.prevValue = sel.value;
        var newVal = sel.value;
        if (newVal === prev) return;

        var spinner = document.getElementById('cvhStatusSpinner');
        sel.disabled = true;
        if (spinner) spinner.classList.remove('d-none');

        csrfFetch(<?= json_encode(APP_URL . '/api/ajax.php') ?>, {
          action : 'set_status',
          id     : sel.dataset.contractId,
          type   : sel.dataset.contractType,
          value  : newVal
        }).then(function(res) {
          sel.disabled = false;
          if (spinner) spinner.classList.add('d-none');
          if (res.ok) {
            // Aktualizuj klasę koloru selekta
            sel.className = sel.className.replace(/cvh-status-\S+/g, '');
            sel.classList.add('cvh-status-select', 'cvh-status-' + res.badge_class);
            sel.dataset.prevValue = newVal;
            ajaxToast('Status zaktualizowany');
            // Hook procesowy (np. „Umowa do rozliczenia”) — obsługiwany przez widok danego typu umowy
            if (res.open_rozliczenie && typeof window.cvhOpenRozliczenie === 'function') {
              window.cvhOpenRozliczenie(res);
            }
          } else {
            // Przywróć poprzednią wartość
            sel.value = prev;
            ajaxToast(res.msg || 'Błąd zmiany statusu', 'error');
          }
        }).catch(function() {
          sel.disabled = false;
          if (spinner) spinner.classList.add('d-none');
          sel.value = prev;
          ajaxToast('Błąd połączenia', 'error');
        });
      }
      </script>
      <?php elseif ($_cvh_locked): ?>
      <span class="badge fs-6" style="background:#7c3aed">
        <i class="bi bi-file-earmark-diff me-1"></i><?= h($_cvh_st['label']) ?>
      </span>
      <?php else: ?>
      <span class="badge bg-<?= h($_cvh_st['class']) ?> fs-6"><?= h($_cvh_st['label']) ?></span>
      <?php if (!empty($_cvh_row['is_technical'])): ?>
      <span class="badge ms-1" style="background:#7c3aed;font-size:.7rem;vertical-align:middle">
        <i class="bi bi-clock-history me-1"></i>Współpraca przed 01.06.2026
      </span>
      <?php endif; ?>
      <?php endif; ?>
    </div>

    <!-- Amount -->
    <?php if ($_cvh_amount !== null && $_cvh_amount !== ''): ?>
    <div class="contract-hero-stat">
      <div class="cvh-stat-label"><?= h($_cvh_amount_lbl) ?></div>
      <div class="cvh-stat-val fw-bold text-success"><?= money((float)$_cvh_amount) ?></div>
    </div>
    <?php endif; ?>

    <!-- Dates -->
    <div class="contract-hero-stat">
      <div class="cvh-stat-label">Okres</div>
      <div class="cvh-stat-val">
        <?= date_pl($_cvh_row['data_zawarcia']) ?>
        <?php if ($_cvh_end_date): ?>
        <span class="text-muted mx-1">→</span>
        <span class="<?= ($_cvh_prog['ended'] ?? false) ? 'text-danger fw-semibold' : '' ?>">
          <?= date_pl($_cvh_end_date) ?>
        </span>
        <?php else: ?>
        <span class="text-muted">→ bezterminowo</span>
        <?php endif; ?>
      </div>
    </div>

    <!-- Subject snippet -->
    <?php if ($_cvh_subject): ?>
    <div class="contract-hero-stat contract-hero-stat--wide">
      <div class="cvh-stat-label">Przedmiot</div>
      <div class="cvh-stat-val" style="font-size:.85rem">
        <?= h(mb_strimwidth(strip_tags($_cvh_subject), 0, 120, '…')) ?>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /hero-stats -->

  <!-- Progress bar -->
  <?php if ($_cvh_prog): ?>
  <div class="contract-hero-progress">
    <div class="cvh-prog-bar">
      <div class="cvh-prog-fill <?= $_cvh_prog['pct'] > 85 ? 'cvh-prog-warn' : '' ?> <?= $_cvh_prog['ended'] ? 'cvh-prog-end' : '' ?>"
           style="width:<?= $_cvh_prog['pct'] ?>%"></div>
    </div>
    <div class="cvh-prog-label">
      <?php if ($_cvh_prog['ended']): ?>
        <span class="text-danger fw-semibold"><i class="bi bi-clock"></i> Umowa zakończona</span>
      <?php else: ?>
        <span class="text-muted"><i class="bi bi-clock"></i>
          Pozostało <strong><?= $_cvh_prog['days_left'] ?></strong> dni
        </span>
      <?php endif; ?>
      <span class="text-muted ms-auto"><?= $_cvh_prog['pct'] ?>%</span>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /contract-hero -->

<style>
.contract-hero {
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 2px 10px rgba(0,0,0,.07);
  padding: 1.1rem 1.4rem 1rem;
  margin-bottom: 1rem;
  border-left: 4px solid var(--cvh-accent, #3b82f6);
}
.contract-hero-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: .85rem;
}
.contract-hero-eyebrow {
  font-size: .72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .1em;
  color: #64748b;
  margin-bottom: .15rem;
}
.contract-hero-eyebrow i { margin-right: .3rem; }
.contract-hero-eyebrow .cvh-num { font-weight: 600; opacity: .6; margin-left: .45rem; letter-spacing: 0; }
.contract-hero-title {
  font-size: 1.5rem;
  font-weight: 800;
  color: #1e293b;
  letter-spacing: -.02em;
  line-height: 1.15;
}
.contract-hero-subtitle {
  font-size: .82rem;
  color: #64748b;
  margin-top: .1rem;
  display: inline-flex;
  align-items: center;
  gap: .3rem;
}
.contract-hero-actions {
  display: flex;
  gap: .4rem;
  flex-shrink: 0;
  align-items: flex-start;
  flex-wrap: wrap;
}
/* Stats row */
.contract-hero-stats {
  display: flex;
  flex-wrap: wrap;
  gap: .6rem 2rem;
  align-items: flex-start;
  border-top: 1px solid #f1f5f9;
  padding-top: .8rem;
}
.contract-hero-stat {
  min-width: 110px;
  flex: 0 1 auto;
}
.contract-hero-stat--wide {
  flex: 1 1 260px;
}
.cvh-stat-label {
  font-size: .65rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .09em;
  color: #94a3b8;
  margin-bottom: .15rem;
}
.cvh-stat-val  { font-size: .9rem; color: #1e293b; line-height: 1.3; }
.cvh-stat-sub  { font-size: .75rem; color: #64748b; }

/* Quick status select */
.cvh-status-select {
  appearance: none;
  border: none;
  outline: none;
  background: none;
  font-size: .9rem;
  font-weight: 700;
  padding: .1rem .9rem .1rem .1rem;
  cursor: pointer;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%2364748b'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right .1rem center;
  border-radius: .25rem;
  transition: background-color .12s;
}
.cvh-status-select:hover { background-color: #f8fafc; }
.cvh-status-select.cvh-status-primary   { color: #2563eb; }
.cvh-status-select.cvh-status-info      { color: #0284c7; }
.cvh-status-select.cvh-status-success   { color: #16a34a; }
.cvh-status-select.cvh-status-warning   { color: #d97706; }
.cvh-status-select.cvh-status-danger    { color: #dc2626; }
.cvh-status-select.cvh-status-secondary { color: #64748b; }
.cvh-status-select.cvh-status-teal      { color: #0d9488; }
.cvh-status-select.cvh-status-orange    { color: #ea580c; }
.cvh-status-select.cvh-status-indigo    { color: #6366f1; }

/* Progress */
.contract-hero-progress {
  margin-top: .75rem;
  padding-top: .6rem;
  border-top: 1px solid #f1f5f9;
}
.cvh-prog-bar {
  height: 6px;
  background: #e2e8f0;
  border-radius: 3px;
  overflow: hidden;
  margin-bottom: .35rem;
}
.cvh-prog-fill {
  height: 100%;
  background: #3b82f6;
  border-radius: 3px;
  transition: width .4s;
}
.cvh-prog-fill.cvh-prog-warn { background: #f59e0b; }
.cvh-prog-fill.cvh-prog-end  { background: #94a3b8; }
.cvh-prog-label {
  display: flex;
  font-size: .75rem;
}
@media (max-width: 576px) {
  .contract-hero-title { font-size: 1.2rem; }
  .contract-hero-stat { min-width: 100px; }
}
</style>
