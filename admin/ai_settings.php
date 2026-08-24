<?php
/**
 * admin/ai_settings.php — Konfiguracja integracji AI (Anthropic Claude).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/asystent_ai.php';

require_role('admin');
$PAGE_TITLE = 'Ustawienia AI';

$api_key = db_one("SELECT value FROM settings WHERE key_='anthropic_api_key'")['value'] ?? '';
$model   = db_one("SELECT value FROM settings WHERE key_='anthropic_model'")['value'] ?: 'claude-haiku-4-5-20251001';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? 'save_ai';

    // ── Publiczny asystent (link /chatbot/{token}) ──────────────────────────
    if ($action === 'chatbot_public') {
        $enabled = isset($_POST['chatbot_public_enabled']) ? '1' : '0';
        asai_setting_set('chatbot_public_enabled', $enabled);
        if ($enabled === '1' && asai_public_token() === '') asai_public_generate_token();
        flash_set('success', 'Ustawienia publicznego asystenta zapisane.');
        header('Location: ai_settings.php#chatbot'); exit;
    }
    if ($action === 'chatbot_rotate') {
        asai_public_generate_token();
        flash_set('success', 'Wygenerowano nowy link. Poprzedni link przestał działać.');
        header('Location: ai_settings.php#chatbot'); exit;
    }

    // ── Widżet asystenta w modułach ─────────────────────────────────────────
    if ($action === 'asystent_widget') {
        asai_setting_set('asystent_widget_enabled', isset($_POST['asystent_widget_enabled']) ? '1' : '0');
        flash_set('success', 'Ustawienia widżetu asystenta zapisane.');
        header('Location: ai_settings.php#asystent'); exit;
    }

    // ── Zapis kluczy / modelu AI ────────────────────────────────────────────
    $new_key   = trim($_POST['anthropic_api_key'] ?? '');
    $new_model = trim($_POST['anthropic_model'] ?? '');

    $save = [
        'anthropic_api_key' => $new_key   ?: $api_key,
        'anthropic_model'   => $new_model ?: $model,
    ];
    foreach ($save as $k => $v) {
        $exists = db_one("SELECT 1 FROM settings WHERE key_=?", [$k]);
        if ($exists) db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$v, $k]);
        else         db()->prepare("INSERT INTO settings (key_,value) VALUES (?,?)")->execute([$k, $v]);
    }
    $api_key = $save['anthropic_api_key'];
    $model   = $save['anthropic_model'];
    flash_set('success', 'Ustawienia AI zapisane.');
    header('Location: ai_settings.php'); exit;
}

$widget_enabled  = (db_one("SELECT value FROM settings WHERE key_='asystent_widget_enabled'")['value'] ?? '') !== '0';
$chatbot_enabled = (db_one("SELECT value FROM settings WHERE key_='chatbot_public_enabled'")['value'] ?? '') === '1';
$chatbot_url     = asai_public_url();

include dirname(__DIR__) . '/includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="index.php">Admin</a></li>
    <li class="breadcrumb-item active">Ustawienia AI</li>
  </ol>
</nav>

<div class="d-flex align-items-center gap-2 mb-3">
  <h4 class="mb-0"><i class="bi bi-stars me-2 text-primary"></i>Ustawienia AI</h4>
  <?php if ($api_key): ?>
  <span class="badge bg-success">Aktywne</span>
  <?php else: ?>
  <span class="badge bg-secondary">Nieaktywne — brak klucza API</span>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<div class="row">
<div class="col-lg-6">
<div class="card shadow-sm">
<div class="card-header fw-semibold"><i class="bi bi-robot me-1"></i>Anthropic Claude</div>
<div class="card-body">
<form method="post">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

  <div class="mb-3">
    <label class="form-label fw-semibold small">Klucz API Anthropic <span class="text-danger">*</span></label>
    <input type="password" name="anthropic_api_key" class="form-control form-control-sm font-monospace"
           placeholder="<?= $api_key ? '(zapisany — zostaw puste by nie zmieniać)' : 'sk-ant-...' ?>"
           autocomplete="new-password">
    <?php if ($api_key): ?>
    <div class="form-text text-success"><i class="bi bi-check-circle"></i> Klucz API zapisany.</div>
    <?php else: ?>
    <div class="form-text">Klucz znajdziesz w panelu <a href="https://console.anthropic.com/settings/keys" target="_blank">console.anthropic.com</a>.</div>
    <?php endif; ?>
  </div>

  <div class="mb-4">
    <label class="form-label fw-semibold small">Model</label>
    <select name="anthropic_model" class="form-select form-select-sm">
      <option value="claude-haiku-4-5-20251001" <?= $model === 'claude-haiku-4-5-20251001' ? 'selected' : '' ?>>
        claude-haiku-4-5 — szybki, tani (domyślny)
      </option>
      <option value="claude-sonnet-4-6" <?= $model === 'claude-sonnet-4-6' ? 'selected' : '' ?>>
        claude-sonnet-4-6 — lepszy, droższy
      </option>
      <option value="claude-opus-4-8" <?= $model === 'claude-opus-4-8' ? 'selected' : '' ?>>
        claude-opus-4-8 — najlepszy (zalecany do klasyfikacji JRWA)
      </option>
    </select>
    <div class="form-text">Model używany do generowania treści w CRM oraz asystenta JRWA w EZD.</div>
  </div>

  <input type="hidden" name="_action" value="save_ai">
  <button type="submit" class="btn btn-primary btn-sm">
    <i class="bi bi-check2 me-1"></i>Zapisz
  </button>
</form>
</div>
</div>

<div class="card shadow-sm mt-3" id="asystent" style="scroll-margin-top:1rem">
<div class="card-header fw-semibold d-flex align-items-center gap-2">
  <i class="bi bi-stars"></i>Asystent w systemie (dla zalogowanych)
  <?php if ($widget_enabled && $api_key): ?>
    <span class="badge bg-success ms-auto">Aktywny</span>
  <?php else: ?>
    <span class="badge bg-secondary ms-auto">Wyłączony</span>
  <?php endif; ?>
</div>
<div class="card-body">
  <p class="small text-muted mb-2">
    Pływający przycisk asystenta w nagłówku <strong>każdego modułu</strong> (panel wolontariusza,
    zadania, CRM, katalog, wydarzenia, poczta, Karty 30, strategia) oraz pełne okno rozmowy:
    <code>/panel/asystent.php</code> i <code>/procedures/asystent.php</code>.
  </p>
  <p class="small text-muted">
    W trybie zalogowanym asystent zna <strong>uprawnienia rozmówcy</strong> — podpowiada tylko
    dostępne dla niego ekrany i pokazuje wyłącznie jego własne umowy, godziny, zadania i wnioski.
    Pozycję „Asystent AI" w panelu wolontariusza możesz osobno ukryć w
    <a href="<?= APP_URL ?>/admin/menu_config.php">Widoczności menu</a>.
  </p>

  <form method="post">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="_action" value="asystent_widget">
    <div class="form-check form-switch mb-2">
      <input class="form-check-input" type="checkbox" role="switch" id="aswEnabled"
             name="asystent_widget_enabled" <?= $widget_enabled ? 'checked' : '' ?>
             <?= $api_key ? '' : 'disabled' ?>>
      <label class="form-check-label small" for="aswEnabled">
        Pokazuj pływający przycisk asystenta w modułach
      </label>
    </div>
    <?php if (!$api_key): ?>
      <div class="form-text text-warning"><i class="bi bi-exclamation-triangle"></i> Najpierw zapisz klucz API Anthropic.</div>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check2 me-1"></i>Zapisz</button>
  </form>
</div>
</div>

<div class="card shadow-sm mt-3" id="chatbot" style="scroll-margin-top:1rem">
<div class="card-header fw-semibold d-flex align-items-center gap-2">
  <i class="bi bi-chat-dots"></i>Publiczny asystent (link do intranetu)
  <?php if ($chatbot_enabled && $chatbot_url): ?>
    <span class="badge bg-success ms-auto">Aktywny</span>
  <?php else: ?>
    <span class="badge bg-secondary ms-auto">Wyłączony</span>
  <?php endif; ?>
</div>
<div class="card-body">
  <p class="small text-muted">
    Udostępnij asystenta AI współpracownikom <strong>bez logowania</strong> — pod stałym linkiem
    <code>/chatbot/{token}</code>. Link możesz swobodnie skopiować i wkleić w intranecie.
    Każdy z linkiem może zadawać pytania o procedury i dokumentację.
  </p>

  <form method="post" class="mb-3">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="_action" value="chatbot_public">
    <div class="form-check form-switch mb-2">
      <input class="form-check-input" type="checkbox" role="switch" id="cbEnabled"
             name="chatbot_public_enabled" <?= $chatbot_enabled ? 'checked' : '' ?>
             <?= $api_key ? '' : 'disabled' ?>>
      <label class="form-check-label small" for="cbEnabled">Włącz publiczny link do asystenta</label>
    </div>
    <?php if (!$api_key): ?>
      <div class="form-text text-warning"><i class="bi bi-exclamation-triangle"></i> Najpierw zapisz klucz API Anthropic.</div>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check2 me-1"></i>Zapisz</button>
  </form>

  <?php if ($chatbot_enabled && $chatbot_url): ?>
  <label class="form-label fw-semibold small mb-1">Link do udostępnienia</label>
  <div class="input-group input-group-sm mb-2">
    <input type="text" id="cbLink" class="form-control font-monospace" readonly value="<?= h($chatbot_url) ?>">
    <button class="btn btn-outline-secondary" type="button" id="cbCopy" title="Kopiuj">
      <i class="bi bi-clipboard"></i>
    </button>
    <a class="btn btn-outline-secondary" href="<?= h($chatbot_url) ?>" target="_blank" rel="noopener" title="Otwórz">
      <i class="bi bi-box-arrow-up-right"></i>
    </a>
  </div>
  <div class="d-flex gap-2 align-items-center mb-3">
    <form method="post" onsubmit="return confirm('Wygenerować nowy link? Obecny link natychmiast przestanie działać u wszystkich, którzy go mają.');">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="chatbot_rotate">
      <button type="submit" class="btn btn-outline-danger btn-sm">
        <i class="bi bi-arrow-repeat me-1"></i>Wygeneruj nowy link
      </button>
    </form>
    <span class="small text-muted">Rotacja unieważnia poprzedni adres.</span>
  </div>

  <?php
    $embed_token = asai_public_token();
    $embed_js    = APP_URL . '/chatbot/embed.js';
    $embed_snip  = '<script async src="' . $embed_js . '"' . "\n"
                 . '        data-token="' . $embed_token . '"' . "\n"
                 . '        data-title="Asystent AI"' . "\n"
                 . '        data-accent="#2563eb"></script>';
    $embed_iframe = '<iframe src="' . $chatbot_url . '"' . "\n"
                  . '        width="100%" height="600"' . "\n"
                  . '        style="border:none;border-radius:16px"' . "\n"
                  . '        title="Asystent AI" allow="clipboard-write">' . "\n"
                  . '</iframe>';
  ?>
  <hr class="my-2">
  <p class="small fw-semibold mb-1"><i class="bi bi-code-slash me-1"></i>Osadź na stronie</p>
  <p class="small text-muted mb-2">
    Skopiuj jeden z poniższych snippetów i wklej na dowolnej stronie (intranet, strona www organizacji).
  </p>

  <ul class="nav nav-tabs nav-tabs-sm mb-2" id="embedTabs" role="tablist" style="font-size:.8rem">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabWidget" type="button">
      <i class="bi bi-chat-dots me-1"></i>Pływający przycisk
    </button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabIframe" type="button">
      <i class="bi bi-window me-1"></i>Osadzony w stronie
    </button></li>
  </ul>

  <div class="tab-content">
    <div class="tab-pane fade show active" id="tabWidget">
      <div class="position-relative">
        <pre id="cbSnipWidget" class="bg-light border rounded p-2 small font-monospace mb-1" style="white-space:pre-wrap;word-break:break-all;font-size:.72rem"><?= htmlspecialchars($embed_snip, ENT_QUOTES) ?></pre>
        <button class="btn btn-sm btn-outline-secondary position-absolute top-0 end-0 m-1" id="cbCopyWidget" title="Kopiuj snippet">
          <i class="bi bi-clipboard"></i>
        </button>
      </div>
      <div class="form-text">Pojawi się pływający przycisk <i class="bi bi-robot"></i> w prawym dolnym rogu strony.
        Możesz zmienić <code>data-accent</code> na kolor organizacji lub dodać <code>data-side="left"</code>.</div>
    </div>
    <div class="tab-pane fade" id="tabIframe">
      <div class="position-relative">
        <pre id="cbSnipIframe" class="bg-light border rounded p-2 small font-monospace mb-1" style="white-space:pre-wrap;word-break:break-all;font-size:.72rem"><?= htmlspecialchars($embed_iframe, ENT_QUOTES) ?></pre>
        <button class="btn btn-sm btn-outline-secondary position-absolute top-0 end-0 m-1" id="cbCopyIframe" title="Kopiuj snippet">
          <i class="bi bi-clipboard"></i>
        </button>
      </div>
      <div class="form-text">Chatbot pojawi się w miejscu wklejenia kodu — przydatny na dedykowanej podstronie lub w portalu intranetowym.</div>
    </div>
  </div>

  <script>
  (function(){
    function copySnip(srcId, btnId){
      const btn = document.getElementById(btnId);
      if (!btn) return;
      btn.addEventListener('click', function(){
        const txt = document.getElementById(srcId).textContent;
        navigator.clipboard.writeText(txt).then(()=>{
          const i = this.querySelector('i'); i.className = 'bi bi-check2 text-success';
          setTimeout(()=>i.className = 'bi bi-clipboard', 1800);
        }).catch(()=>{ const el=document.getElementById(srcId); const r=document.createRange(); r.selectNode(el); window.getSelection().removeAllRanges(); window.getSelection().addRange(r); document.execCommand('copy'); });
      });
    }
    document.getElementById('cbCopy')?.addEventListener('click', function(){
      const inp = document.getElementById('cbLink');
      navigator.clipboard.writeText(inp.value).then(()=>{
        const i=this.querySelector('i'); i.className='bi bi-check2 text-success';
        setTimeout(()=>i.className='bi bi-clipboard', 1500);
      }).catch(()=>{ inp.select(); document.execCommand('copy'); });
    });
    copySnip('cbSnipWidget','cbCopyWidget');
    copySnip('cbSnipIframe','cbCopyIframe');
  })();
  </script>
  <?php endif; ?>
</div>
</div>

<div class="card shadow-sm mt-3">
<div class="card-header fw-semibold"><i class="bi bi-info-circle me-1"></i>Gdzie działa AI?</div>
<div class="card-body small text-muted">
  <ul class="mb-0 ps-3">
    <li>CRM → Komunikacja → przycisk <strong>Wygeneruj AI</strong> w edytorze e-mail</li>
    <li>CRM → Masowa wysyłka → przycisk <strong>Wygeneruj AI</strong></li>
    <li>EZD → Koszulki → <strong>Przerejestrowanie (AI)</strong> — kwalifikacja spraw do Nowego JRWA</li>
    <li><strong>Asystent AI</strong> — pływający przycisk w nagłówku każdego modułu oraz pełne okno:
        Biuro → Procedury → Asystent AI i Panel wolontariusza → Asystent AI. Odpowiada o procedury,
        dokumenty, uchwały, zasady i komunikaty, o funkcje samego SZO (gdzie kliknąć, jakie kroki)
        oraz o własne sprawy rozmówcy (umowy, godziny, zadania, wnioski)</li>
    <li><strong>Publiczny asystent</strong> — link <code>/chatbot/{token}</code> do udostępnienia współpracownikom w intranecie (bez logowania, bez dostępu do danych osobowych)</li>
  </ul>
</div>
</div>
</div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
