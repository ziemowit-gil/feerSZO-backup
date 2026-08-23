<?php
/**
 * crm/campaign/editor.php — wizualny edytor newsletterów (bloki, przeciągnij-i-upuść).
 *
 * ARCHITEKTURA — dlaczego kanwa jest iframem, a nie drugą implementacją bloków:
 * treść renderuje WYŁĄCZNIE serwer (includes/crm_email_render.php). Kanwa to
 * iframe z tym samym HTML-em, który pójdzie do odbiorcy, tylko z dodanymi
 * atrybutami data-cem-block (tryb `editable`). Zaznaczanie, przeciąganie i
 * upuszczanie działa na tych znacznikach. Dzięki temu podgląd nie może kłamać —
 * nie ma osobnego renderera w JS, który rozjeżdża się z serwerowym.
 *
 * DOSTĘPNOŚĆ: natywne HTML5 drag&drop nie działa z klawiatury ani na ekranach
 * dotykowych, więc obok kanwy jest lista bloków ze strzałkami góra/dół i
 * przyciskami dodawania. Każdą operację można wykonać bez myszy.
 *
 * ?id= — edycja kampanii (szkic lub zaplanowana). Brak → tworzy nowy szkic.
 */

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_campaign.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_migrate();

$can_write = can_write('crm') || is_admin();
if (!$can_write) { http_response_code(403); exit('Brak uprawnień.'); }

$id = (int)($_GET['id'] ?? 0);

// Nowa kampania: szkic ze startowym dokumentem, żeby edytor nigdy nie startował
// z pustej kanwy (puste pole to najgorszy możliwy punkt startu).
if (!$id) {
    $id = db_insert('crm_campaigns', [
        'name'        => 'Newsletter ' . date('d.m.Y H:i'),
        'subject'     => '',
        'status'      => 'draft',
        'design_json' => json_encode(crm_email_starter_design(), JSON_UNESCAPED_UNICODE),
        'created_by'  => (int)(current_user()['id'] ?? 0) ?: null,
    ]);
    header('Location: ' . APP_URL . '/crm/campaign/editor.php?id=' . $id);
    exit;
}

$campaign = db_one("SELECT * FROM crm_campaigns WHERE id=?", [$id]);
if (!$campaign) { http_response_code(404); exit('Nie znaleziono kampanii.'); }

// Kampania w trakcie wysyłki ma zamrożoną treść — edytor nie ma czego tu robić.
if (!in_array($campaign['status'], ['draft', 'scheduled'], true)) {
    flash_set('error', 'Kampania jest już wysyłana — treści nie da się zmienić.');
    header('Location: ' . APP_URL . '/crm/campaign/view.php?id=' . $id);
    exit;
}

$design = crm_campaign_design($campaign) ?? crm_email_starter_design();

// Dane dla UI
$DEFS     = crm_email_block_defs();
$FONTS    = crm_email_font_stacks();
$SOCIAL   = crm_email_social_networks();
$TOKENS   = crm_email_merge_tokens();
$SEG_F    = crm_segment_fields();
$SEG_OPS  = crm_segment_operators();

$all_tags = array_column(db_all("SELECT DISTINCT tag FROM crm_tags ORDER BY tag"), 'tag');
$groups   = db_all("SELECT id, name FROM crm_groups ORDER BY name");
$purposes = array_filter(crm_consent_purposes(true), fn($p) => in_array($p['channel'], ['email', 'any'], true));
$templates = db_all("SELECT id, name FROM crm_templates WHERE channel='email' AND design_json IS NOT NULL AND TRIM(design_json) <> '' ORDER BY name");

$seg_cfg    = crm_campaign_segment_config($campaign);
$seg_filter = ($campaign['segment_type'] ?? '') === 'filter'
    ? (json_decode((string)($campaign['segment_filter'] ?? '{}'), true) ?: ['op' => 'and', 'rules' => []])
    : ['op' => 'and', 'rules' => []];

$boot = [
    'id'        => $id,
    'csrf'      => csrf_token(),
    'api'       => APP_URL . '/crm/campaign/api.php',
    'upload'    => APP_URL . '/crm/mosaico/upload.php',
    'design'    => $design,
    'defs'      => $DEFS,
    'fonts'     => $FONTS,
    'social'    => $SOCIAL,
    'tokens'    => $TOKENS,
    'name'      => (string)$campaign['name'],
    'subject'   => (string)$campaign['subject'],
    'preheader' => (string)($campaign['preheader'] ?? ''),
    'segment'   => [
        'type'      => (string)($campaign['segment_type'] ?? 'tags'),
        'tags'      => array_values((array)($seg_cfg['tags'] ?? [])),
        'group_ids' => array_map('intval', (array)($seg_cfg['group_ids'] ?? [])),
        'filter'    => $seg_filter,
        'purpose'   => (int)($campaign['purpose_id'] ?? 0),
    ],
    'segFields' => $SEG_F,
    'segOps'    => $SEG_OPS,
];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Edytor newslettera — <?= h($campaign['name']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" rel="stylesheet">
<style>
  :root{--cem-primary:#0176D3;--cem-border:#E2E8F0}
  html,body{height:100%}
  body{margin:0;background:#F1F5F9;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;overflow:hidden}
  .cem-app{display:flex;flex-direction:column;height:100vh}
  .cem-top{display:flex;align-items:center;gap:.6rem;height:56px;padding:0 .9rem;background:#fff;border-bottom:1px solid var(--cem-border);flex:0 0 auto}
  .cem-main{display:flex;flex:1 1 auto;min-height:0}
  .cem-side{width:270px;flex:0 0 270px;background:#fff;border-right:1px solid var(--cem-border);overflow-y:auto;padding:.9rem}
  .cem-insp{width:310px;flex:0 0 310px;background:#fff;border-left:1px solid var(--cem-border);overflow-y:auto;padding:.9rem}
  .cem-canvas{flex:1 1 auto;overflow:auto;padding:1.4rem;display:flex;justify-content:center;align-items:flex-start}
  .cem-frame{border:0;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.12);width:640px;min-height:70vh;transition:width .18s ease}
  .cem-frame.mobile{width:390px}
  .cem-lbl{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#64748B;margin:0 0 .5rem}
  .cem-tiles{display:grid;grid-template-columns:1fr 1fr;gap:.4rem}
  .cem-tile{display:flex;flex-direction:column;align-items:center;gap:.25rem;padding:.6rem .3rem;border:1px solid var(--cem-border);
            border-radius:.5rem;background:#F8FAFC;cursor:grab;font-size:.7rem;font-weight:600;color:#334155;text-align:center}
  .cem-tile:hover{border-color:var(--cem-primary);background:#EFF6FF;color:var(--cem-primary)}
  .cem-tile:focus-visible{outline:2px solid var(--cem-primary);outline-offset:1px}
  .cem-tile i{font-size:1.05rem}
  .cem-row{display:flex;align-items:center;gap:.35rem;padding:.35rem .45rem;border:1px solid transparent;border-radius:.4rem;font-size:.78rem}
  .cem-row:hover{background:#F1F5F9}
  .cem-row.sel{background:#EFF6FF;border-color:#BFDBFE;color:var(--cem-primary);font-weight:600}
  .cem-row button{border:0;background:none;color:#94A3B8;padding:0 .15rem;line-height:1}
  .cem-row button:hover{color:#334155}
  .cem-row .nm{flex:1 1 auto;text-align:left;background:none;border:0;color:inherit;font:inherit;cursor:pointer;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .cem-f{margin-bottom:.7rem}
  .cem-f label{display:block;font-size:.7rem;font-weight:600;color:#475569;margin-bottom:.2rem}
  .cem-f .hint{font-size:.66rem;color:#94A3B8;margin-top:.15rem}
  .cem-f input[type=text],.cem-f input[type=url],.cem-f input[type=number],.cem-f select,.cem-f textarea{
     width:100%;font-size:.8rem;padding:.32rem .45rem;border:1px solid #CBD5E1;border-radius:.35rem}
  .cem-f input[type=color]{width:100%;height:32px;padding:2px;border:1px solid #CBD5E1;border-radius:.35rem}
  .cem-item{border:1px solid var(--cem-border);border-radius:.4rem;padding:.45rem;margin-bottom:.4rem;background:#F8FAFC}
  .cem-status{font-size:.72rem;color:#64748B;min-width:104px}
  .cem-warn{font-size:.72rem;background:#FFFBEB;border:1px solid #FDE68A;color:#92400E;border-radius:.4rem;padding:.45rem .55rem;margin-bottom:.6rem}
  .ql-container{font-size:.85rem}
  .ql-editor{min-height:130px}
  .cem-tokens button{font-size:.65rem;padding:.1rem .3rem;margin:0 .15rem .15rem 0}
</style>
</head>
<body>
<div class="cem-app">

  <!-- ── Pasek narzędzi ─────────────────────────────────────────────────── -->
  <header class="cem-top">
    <a href="<?= APP_URL ?>/crm/campaign/index.php" class="btn btn-sm btn-light border" title="Wróć do listy kampanii">
      <i class="bi bi-arrow-left"></i>
    </a>
    <input id="cName" class="form-control form-control-sm" style="max-width:230px" value="<?= h($campaign['name']) ?>"
           aria-label="Nazwa kampanii">
    <span class="cem-status" id="cStatus" role="status" aria-live="polite">Zapisano</span>

    <div class="ms-auto d-flex align-items-center gap-2">
      <div class="btn-group btn-group-sm" role="group" aria-label="Cofnij / ponów">
        <button class="btn btn-light border" id="bUndo" title="Cofnij (Ctrl+Z)"><i class="bi bi-arrow-counterclockwise"></i></button>
        <button class="btn btn-light border" id="bRedo" title="Ponów (Ctrl+Shift+Z)"><i class="bi bi-arrow-clockwise"></i></button>
      </div>
      <div class="btn-group btn-group-sm" role="group" aria-label="Szerokość podglądu">
        <button class="btn btn-dark" id="bDesktop" aria-pressed="true">Desktop</button>
        <button class="btn btn-light border" id="bMobile" aria-pressed="false">Telefon</button>
      </div>
      <div class="form-check form-switch mb-0" title="Podstawia dane pierwszego odbiorcy z segmentu">
        <input class="form-check-input" type="checkbox" id="bPers">
        <label class="form-check-label" for="bPers" style="font-size:.72rem">Dane odbiorcy</label>
      </div>
      <button class="btn btn-sm btn-light border" id="bTest"><i class="bi bi-send-check me-1"></i>Test</button>
      <div class="dropdown">
        <button class="btn btn-sm btn-light border dropdown-toggle" data-bs-toggle="dropdown">Więcej</button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><a class="dropdown-item" href="<?= APP_URL ?>/crm/campaign/api.php?_action=export&id=<?= $id ?>">
            <i class="bi bi-download me-2"></i>Pobierz HTML</a></li>
          <li><button class="dropdown-item" id="bTpl"><i class="bi bi-bookmark-plus me-2"></i>Zapisz jako szablon</button></li>
          <?php if ($templates): ?>
          <li><hr class="dropdown-divider"></li>
          <li><h6 class="dropdown-header">Wczytaj szablon</h6></li>
          <?php foreach ($templates as $t): ?>
          <li><button class="dropdown-item cem-load-tpl" data-tpl="<?= (int)$t['id'] ?>"><?= h($t['name']) ?></button></li>
          <?php endforeach; ?>
          <?php endif; ?>
        </ul>
      </div>
      <button class="btn btn-sm btn-primary" id="bSend"><i class="bi bi-people-fill me-1"></i>Odbiorcy i wysyłka</button>
    </div>
  </header>

  <div class="cem-main">

    <!-- ── Paleta + lista bloków ────────────────────────────────────────── -->
    <aside class="cem-side">
      <p class="cem-lbl">Dodaj blok</p>
      <div id="palette"></div>
      <p class="text-muted" style="font-size:.66rem;line-height:1.4">
        Przeciągnij kafelek na kanwę albo naciśnij Enter, żeby dodać na końcu.
      </p>

      <hr class="my-3">
      <p class="cem-lbl">Bloki wiadomości</p>
      <div id="blockList"></div>

      <hr class="my-3">
      <button class="btn btn-sm btn-light border w-100" id="bGlobal">
        <i class="bi bi-palette me-1"></i>Styl globalny
      </button>
    </aside>

    <!-- ── Kanwa ───────────────────────────────────────────────────────── -->
    <div class="cem-canvas">
      <iframe id="canvas" class="cem-frame" title="Podgląd wiadomości"></iframe>
    </div>

    <!-- ── Inspektor ───────────────────────────────────────────────────── -->
    <aside class="cem-insp">
      <div id="warnBox"></div>

      <div class="cem-f">
        <label for="cSubject">Temat wiadomości</label>
        <input type="text" id="cSubject" value="<?= h($campaign['subject']) ?>" maxlength="250">
      </div>
      <div class="cem-f">
        <label for="cPre">Tekst podglądu (preheader)</label>
        <input type="text" id="cPre" value="<?= h($campaign['preheader'] ?? '') ?>" maxlength="250">
        <div class="hint">Pojawia się w skrzynce obok tematu. Bez niego skrzynka pokaże początek treści.</div>
      </div>
      <hr class="my-3">
      <div id="inspector"></div>
    </aside>
  </div>
</div>

<!-- ── Modal: odbiorcy i wysyłka ──────────────────────────────────────────── -->
<div class="modal fade" id="mSend" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Odbiorcy i wysyłka</h5>
        <button class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button></div>
      <div class="modal-body">

        <div class="mb-3">
          <label class="form-label fw-semibold" style="font-size:.82rem">Kto ma dostać wiadomość</label>
          <select class="form-select form-select-sm" id="segType">
            <option value="tags">Kontakty z wybranymi tagami</option>
            <option value="groups">Kontakty z wybranych grup</option>
            <option value="filter">Segment warunkowy (zaawansowany)</option>
            <option value="all">Wszystkie aktywne kontakty</option>
          </select>
        </div>

        <div class="mb-3 seg-pane" data-pane="tags">
          <label class="form-label" style="font-size:.8rem">Tagi</label>
          <select class="form-select form-select-sm" id="segTags" multiple size="7">
            <?php foreach ($all_tags as $t): ?><option value="<?= h($t) ?>"><?= h($t) ?></option><?php endforeach; ?>
          </select>
        </div>

        <div class="mb-3 seg-pane" data-pane="groups" hidden>
          <label class="form-label" style="font-size:.8rem">Grupy</label>
          <select class="form-select form-select-sm" id="segGroups" multiple size="7">
            <?php foreach ($groups as $g): ?><option value="<?= (int)$g['id'] ?>"><?= h($g['name']) ?></option><?php endforeach; ?>
          </select>
        </div>

        <div class="mb-3 seg-pane" data-pane="filter" hidden>
          <div class="d-flex align-items-center gap-2 mb-2">
            <label class="form-label mb-0" style="font-size:.8rem">Warunki</label>
            <select class="form-select form-select-sm" id="filtGlue" style="width:auto">
              <option value="and">spełnione wszystkie</option>
              <option value="or">spełniony dowolny</option>
            </select>
            <button class="btn btn-sm btn-light border ms-auto" id="bAddRule"><i class="bi bi-plus-lg"></i> Warunek</button>
          </div>
          <div id="filtRules"></div>
        </div>

        <?php if ($purposes): ?>
        <div class="mb-3">
          <label class="form-label" style="font-size:.8rem">Cel przetwarzania (zgoda)</label>
          <select class="form-select form-select-sm" id="segPurpose">
            <option value="0">Bez celu — wysyłka do wszystkich, którzy nie mają opt-outu</option>
            <?php foreach ($purposes as $p): ?>
            <option value="<?= (int)$p['id'] ?>"><?= h($p['nazwa']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text" style="font-size:.72rem">
            Po wskazaniu celu wysyłka obejmie tylko kontakty z aktualną zgodą na ten cel.
          </div>
        </div>
        <?php endif; ?>

        <div class="alert alert-light border py-2 mb-3" id="audBox" style="font-size:.82rem">
          <span class="text-muted">Sprawdzam liczbę odbiorców…</span>
        </div>

        <div class="mb-2">
          <label class="form-label fw-semibold" style="font-size:.82rem">Kiedy wysłać</label>
          <div class="form-check"><input class="form-check-input" type="radio" name="when" id="wNow" value="now" checked>
            <label class="form-check-label" for="wNow" style="font-size:.84rem">Teraz</label></div>
          <div class="form-check"><input class="form-check-input" type="radio" name="when" id="wSched" value="schedule">
            <label class="form-check-label" for="wSched" style="font-size:.84rem">Zaplanuj na termin</label></div>
          <input type="datetime-local" class="form-control form-control-sm mt-2" id="schedAt" disabled
                 value="<?= $campaign['scheduled_at'] ? h(date('Y-m-d\TH:i', strtotime($campaign['scheduled_at']))) : '' ?>">
        </div>

        <div id="sendProblems"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-light border" data-bs-dismiss="modal">Anuluj</button>
        <button class="btn btn-primary" id="bConfirmSend">Wyślij</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Modal: wysyłka testowa ─────────────────────────────────────────────── -->
<div class="modal fade" id="mTest" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Wyślij test</h5>
      <button class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button></div>
    <div class="modal-body">
      <label class="form-label" style="font-size:.8rem" for="testMail">Adres e-mail</label>
      <input type="email" class="form-control form-control-sm" id="testMail"
             value="<?= h(current_user()['email'] ?? '') ?>">
      <div class="form-text" style="font-size:.72rem">
        Wiadomość testowa nie ma trackingu ani działającego linku wypisania.
      </div>
      <div id="testMsg" class="mt-2"></div>
    </div>
    <div class="modal-footer"><button class="btn btn-primary btn-sm" id="bDoTest">Wyślij test</button></div>
  </div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>
<script>
window.CEM_BOOT = <?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= APP_URL ?>/assets/js/crm-newsletter-editor.js?v=<?= @filemtime(dirname(dirname(__DIR__)) . '/assets/js/crm-newsletter-editor.js') ?: time() ?>"></script>
</body>
</html>
