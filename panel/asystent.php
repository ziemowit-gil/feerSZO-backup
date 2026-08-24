<?php
/**
 * panel/asystent.php — asystent AI w pełnym oknie, dla panelu wolontariusza.
 *
 * Sam czat to includes/asystent_widget.php w trybie inline ($ASAI_WIDGET_INLINE),
 * więc pływający widżet z nagłówków i ten ekran to jedna implementacja rozmowy.
 * Ta strona dokłada tylko obudowę: nagłówek, wyjaśnienie zakresu i skróty.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/asystent_ai.php';

require_login();
panel_require_enabled('asystent', 'Asystent AI');

// Ten ekran JEST asystentem — pływający przycisk byłby drugą kopią tej rozmowy.
define('ASAI_WIDGET_SUPPRESS', true);

$PAGE_TITLE = 'Asystent AI';
$user       = current_user();
$ai_ready   = asai_enabled();

$_is_volunteer_only = is_viewer()
    && !db_one("SELECT id FROM users WHERE id=? AND k30_consultant=1", [(int)$user['id']]);

if ($_is_volunteer_only) {
    include __DIR__ . '/includes/header_panel.php';
} else {
    include dirname(__DIR__) . '/includes/header.php';
}
?>

<div class="pv-wrap">

<div class="pv-page-header">
  <div class="pv-page-head-main">
    <a href="<?= APP_URL ?>/panel/index.php" class="pv-page-back"><i class="bi bi-arrow-left" aria-hidden="true"></i> Panel</a>
    <h1 class="pv-page-title"><i class="bi bi-stars" aria-hidden="true"></i>Asystent AI</h1>
    <p class="pv-page-sub">Zapytaj o procedury organizacji, o obsługę systemu i o swoje sprawy</p>
  </div>
</div>

<?php if (!$ai_ready): ?>
  <div class="alert alert-warning d-flex align-items-start gap-2" role="alert">
    <i class="bi bi-exclamation-triangle-fill mt-1" aria-hidden="true"></i>
    <div>
      <strong>Asystent nie jest jeszcze skonfigurowany.</strong><br>
      Administrator musi zapisać klucz Anthropic API w <em>Ustawieniach AI</em>.
      W tej chwili odpowiedzi nie będą generowane.
    </div>
  </div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-8">
    <?php
      $ASAI_WIDGET_INLINE = true;
      $ASAI_WIDGET_SCOPE  = 'panel_asystent';
      require dirname(__DIR__) . '/includes/asystent_widget.php';
    ?>
  </div>

  <div class="col-lg-4">
    <div class="tz-card mb-3">
      <div class="tz-card__hd"><i class="bi bi-question-circle me-2" aria-hidden="true"></i>O co możesz zapytać</div>
      <div class="tz-card__bd">
        <ul class="mb-0 ps-3 small" style="line-height:1.7">
          <li><strong>Procedury i dokumenty</strong> — „jakie zasady obowiązują przy…", „co mówi uchwała o…"</li>
          <li><strong>Obsługa systemu</strong> — „gdzie wpiszę godziny", „jak złożyć wniosek o zaświadczenie"</li>
          <li><strong>Twoje sprawy</strong> — „ile mam otwartych zadań", „jaki jest status mojego wniosku"</li>
          <li><strong>Komunikaty</strong> — „co nowego ogłosiła organizacja"</li>
        </ul>
      </div>
    </div>

    <div class="tz-card mb-3">
      <div class="tz-card__hd"><i class="bi bi-shield-check me-2" aria-hidden="true"></i>Zakres i prywatność</div>
      <div class="tz-card__bd small text-muted" style="line-height:1.65">
        Asystent widzi <strong>tylko Twoje dane</strong> i te dokumenty, do których masz dostęp
        w systemie — nigdy spraw innych osób. Treść pytania trafia do dziennika systemowego
        (bez odpowiedzi), a odpowiedzi generuje model AI, więc przy decyzjach formalnych
        sprawdź wskazane źródło.
      </div>
    </div>

    <div class="tz-card">
      <div class="tz-card__hd"><i class="bi bi-box-arrow-up-right me-2" aria-hidden="true"></i>Gdy asystent nie wystarczy</div>
      <div class="tz-card__bd d-grid gap-2">
        <a class="btn btn-outline-secondary btn-sm text-start" href="<?= APP_URL ?>/panel/pomoc.php">
          <i class="bi bi-life-preserver me-2" aria-hidden="true"></i>Pomoc i FAQ panelu
        </a>
        <a class="btn btn-outline-secondary btn-sm text-start" href="<?= APP_URL ?>/panel/helpdesk.php">
          <i class="bi bi-headset me-2" aria-hidden="true"></i>Zgłoszenie do Helpdesku IT
        </a>
        <?php if (module_enabled('messages_enabled') && panel_visible('wiadomosci')): ?>
        <a class="btn btn-outline-secondary btn-sm text-start" href="<?= APP_URL ?>/panel/messages.php">
          <i class="bi bi-chat-left-text me-2" aria-hidden="true"></i>Napisz do administracji
        </a>
        <?php endif; ?>
        <?php if (panel_visible('wnioski')): ?>
        <a class="btn btn-outline-secondary btn-sm text-start" href="<?= APP_URL ?>/panel/apply.php">
          <i class="bi bi-send me-2" aria-hidden="true"></i>Złóż wniosek lub pismo
        </a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

</div><!-- /pv-wrap -->

<?php
if ($_is_volunteer_only) {
    include __DIR__ . '/includes/footer_panel.php';
} else {
    include dirname(__DIR__) . '/includes/footer.php';
}
