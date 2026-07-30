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
  <div class="d-flex gap-2 align-items-center">
    <form method="post" onsubmit="return confirm('Wygenerować nowy link? Obecny link natychmiast przestanie działać u wszystkich, którzy go mają.');">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="chatbot_rotate">
      <button type="submit" class="btn btn-outline-danger btn-sm">
        <i class="bi bi-arrow-repeat me-1"></i>Wygeneruj nowy link
      </button>
    </form>
    <span class="small text-muted">Rotacja unieważnia poprzedni adres.</span>
  </div>
  <script>
  document.getElementById('cbCopy')?.addEventListener('click', function(){
    const inp = document.getElementById('cbLink');
    navigator.clipboard.writeText(inp.value).then(()=>{
      const i=this.querySelector('i'); i.className='bi bi-check2 text-success';
      setTimeout(()=>i.className='bi bi-clipboard', 1500);
    }).catch(()=>{ inp.select(); document.execCommand('copy'); });
  });
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
    <li>Biuro → Procedury → <strong>Asystent AI</strong> — agent przeszukujący procedury i dokumentację, z odpowiedziami i źródłami</li>
    <li><strong>Publiczny asystent</strong> — link <code>/chatbot/{token}</code> do udostępnienia współpracownikom w intranecie (bez logowania)</li>
  </ul>
</div>
</div>
</div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
