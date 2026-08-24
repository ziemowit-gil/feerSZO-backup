<?php
/**
 * procedures/asystent.php — asystent AI w pełnym oknie, widok dla pracowników.
 *
 * Sam czat to includes/asystent_widget.php w trybie inline, więc pływający widżet
 * w nagłówkach modułów i ten ekran mają jedną implementację rozmowy. Strona dokłada
 * obudowę: opis zakresu, skrót do procedur i zarządzanie linkiem publicznym.
 *
 * Asystent NIE jest częścią modułu procedur (odpowiada też o funkcje systemu,
 * komunikaty i dane konta), więc bramką jest tylko logowanie i klucz API.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/asystent_ai.php';

require_login();

// Ten ekran JEST asystentem — pływający przycisk byłby drugą kopią tej rozmowy.
define('ASAI_WIDGET_SUPPRESS', true);

$PAGE_TITLE = 'Asystent AI';
$ai_ready   = asai_enabled();

include dirname(__DIR__) . '/includes/header.php';
?>

<style>
.asai-wrap { max-width: 1100px; margin: 0 auto; }
.asai-hero {
    background: linear-gradient(135deg,#eff6ff 0%,#f5f3ff 100%);
    border: 1.5px solid #e2e8f0; border-radius: 16px;
    padding: 1.15rem 1.3rem; margin-bottom: 1rem;
}
.asai-side .card { border: 1.5px solid #e2e8f0; border-radius: 14px; }
.asai-side .card-header { background: #fbfaff; font-size: .82rem; font-weight: 700; }
</style>

<div class="asai-wrap">

  <div class="asai-hero d-flex align-items-start gap-3">
    <div style="width:46px;height:46px;border-radius:12px;background:#ede9fe;color:#6d28d9;display:flex;align-items:center;justify-content:center;font-size:1.5rem;flex-shrink:0">
      <i class="bi bi-stars" aria-hidden="true"></i>
    </div>
    <div class="flex-grow-1">
      <h4 class="mb-1 fw-bold" style="color:#1e293b">Asystent AI — wiedza organizacji i obsługa SZO</h4>
      <div class="text-muted" style="font-size:.84rem">
        Zadaj pytanie po polsku. Agent sam przeszuka <strong>procedury, dokumenty, uchwały,
        zasady i komunikaty</strong>, sprawdzi <strong>funkcje systemu</strong> (gdzie kliknąć,
        jakie kroki) oraz — na pytanie o „moje" sprawy — <strong>Twoje własne dane</strong>
        w SZO. Odpowiedź wskazuje źródła.
      </div>
    </div>
    <div class="d-flex flex-column gap-2 flex-shrink-0">
      <a href="<?= APP_URL ?>/procedures/index.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-journal-bookmark-fill me-1" aria-hidden="true"></i>Procedury
      </a>
      <?php if (is_admin()):
        $pub_url = asai_public_url();
        if (asai_public_enabled() && $pub_url): ?>
        <a href="<?= htmlspecialchars($pub_url, ENT_QUOTES) ?>" target="_blank" rel="noopener"
           class="btn btn-sm btn-outline-secondary" title="Publiczny link do udostępnienia w intranecie">
          <i class="bi bi-share me-1" aria-hidden="true"></i>Link publiczny
        </a>
      <?php else: ?>
        <a href="<?= APP_URL ?>/admin/ai_settings.php#chatbot" class="btn btn-sm btn-outline-secondary"
           title="Włącz publiczny link do asystenta">
          <i class="bi bi-share me-1" aria-hidden="true"></i>Udostępnij
        </a>
      <?php endif; endif; ?>
    </div>
  </div>

  <?php if (!$ai_ready): ?>
  <div class="alert alert-warning d-flex align-items-center gap-2" role="alert">
    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
    <div>
      Integracja AI nie jest skonfigurowana.
      <?php if (is_admin()): ?>
        Dodaj klucz w <a href="<?= APP_URL ?>/admin/ai_settings.php">Admin → Ustawienia AI</a>.
      <?php else: ?>
        Poproś administratora o skonfigurowanie klucza Anthropic API.
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-lg-8">
      <?php
        $ASAI_WIDGET_INLINE = true;
        $ASAI_WIDGET_SCOPE  = 'procedury_asystent';
        require dirname(__DIR__) . '/includes/asystent_widget.php';
      ?>
    </div>

    <div class="col-lg-4 asai-side">
      <div class="card mb-3">
        <div class="card-header"><i class="bi bi-search me-2" aria-hidden="true"></i>Co przeszukuje</div>
        <div class="card-body small text-muted" style="line-height:1.7">
          <div><i class="bi bi-journal-text me-2" aria-hidden="true"></i>Procedury i ich załączniki</div>
          <div><i class="bi bi-folder2-open me-2" aria-hidden="true"></i>Dokumenty organizacji</div>
          <div><i class="bi bi-file-ruled me-2" aria-hidden="true"></i>Uchwały i zarządzenia</div>
          <div><i class="bi bi-building-heart me-2" aria-hidden="true"></i>Zasady organizacji</div>
          <div><i class="bi bi-megaphone me-2" aria-hidden="true"></i>Komunikaty organizacji</div>
          <div><i class="bi bi-grid-3x3-gap me-2" aria-hidden="true"></i>Funkcje i ekrany SZO</div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header"><i class="bi bi-shield-check me-2" aria-hidden="true"></i>Zakres uprawnień</div>
        <div class="card-body small text-muted" style="line-height:1.65">
          Agent zna Twoje uprawnienia: podpowiada ekrany, do których faktycznie masz dostęp,
          i pokazuje wyłącznie <strong>Twoje własne</strong> umowy, godziny, zadania i wnioski.
          Treść pytania trafia do dziennika systemowego (bez odpowiedzi).
        </div>
      </div>

      <?php if (is_admin()): ?>
      <div class="card">
        <div class="card-header"><i class="bi bi-sliders me-2" aria-hidden="true"></i>Administracja</div>
        <div class="card-body d-grid gap-2">
          <a class="btn btn-outline-secondary btn-sm text-start" href="<?= APP_URL ?>/admin/ai_settings.php">
            <i class="bi bi-robot me-2" aria-hidden="true"></i>Ustawienia AI i widżet
          </a>
          <a class="btn btn-outline-secondary btn-sm text-start" href="<?= APP_URL ?>/admin/menu_config.php">
            <i class="bi bi-list-nested me-2" aria-hidden="true"></i>Widoczność w panelu
          </a>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
