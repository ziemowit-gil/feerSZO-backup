<?php
/**
 * Canva Creator — tworzenie projektów w Canva bez wychodzenia z SZO.
 * Wykorzystuje Canva „Design Button" SDK: przycisk otwiera edytor Canva
 * w oknie, a po publikacji zwraca link do gotowego projektu.
 * Dostęp: administrator lub użytkownik z dostępem do Canva.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/canva.php';
require_login();

$me           = current_user();
$is_adm       = is_admin();
$moduleOn     = canva_module_enabled();
$hasAcc       = $moduleOn && ($is_adm || canva_user_has_access((int)$me['id']));
$apiKey       = canva_button_api_key();
$PAGE_TITLE   = 'Canva Creator';

// Typy projektów (Design Button v2 — design.type) z polskimi etykietami i ikonami.
$TYPES = [
    ['SocialMedia',      'Post (social media)',  'bi-share'],
    ['InstagramPost',    'Instagram — post',     'bi-instagram'],
    ['InstagramStory',   'Instagram — story',    'bi-phone'],
    ['FacebookPost',     'Facebook — post',      'bi-facebook'],
    ['FacebookCover',    'Facebook — okładka',   'bi-image'],
    ['Poster',           'Plakat',               'bi-file-earmark-image'],
    ['Flyer',            'Ulotka',               'bi-file-earmark-richtext'],
    ['Presentation',     'Prezentacja',          'bi-easel'],
    ['A4Document',       'Dokument A4',          'bi-file-earmark-text'],
    ['Logo',             'Logo',                 'bi-bookmark-star'],
    ['Banner',           'Baner',                'bi-flag'],
    ['Card',             'Kartka / zaproszenie', 'bi-card-image'],
    ['Infographic',      'Infografika',          'bi-bar-chart'],
    ['Certificate',      'Dyplom / certyfikat',  'bi-award'],
];

include dirname(__DIR__) . '/includes/header.php';
?>
<div class="d-flex align-items-center mb-3 gap-2">
  <h4 class="mb-0 fw-bold" style="color:#7c3aed"><i class="bi bi-palette-fill me-2"></i>Canva Creator</h4>
  <?php if ($is_adm): ?>
  <a href="<?= APP_URL ?>/admin/canva.php" class="btn btn-sm btn-outline-secondary ms-auto"><i class="bi bi-gear me-1"></i>Ustawienia Canva</a>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<?php if (!$moduleOn): ?>
<div class="alert alert-secondary"><i class="bi bi-power me-1"></i>Funkcjonalności Canva są obecnie wyłączone w systemie.<?php if ($is_adm): ?> Włącz je w <a href="<?= APP_URL ?>/admin/canva.php">ustawieniach Canva</a>.<?php endif; ?></div>

<?php elseif (!$hasAcc): ?>
<div class="alert alert-warning"><i class="bi bi-lock me-1"></i>Nie masz dostępu do Canva. Poproś administratora o włączenie dostępu.</div>

<?php elseif ($apiKey === ''): ?>
<div class="alert alert-info">
  <i class="bi bi-info-circle me-1"></i>Kreator Canva nie jest jeszcze skonfigurowany
  <?php if ($is_adm): ?>— wpisz <strong>klucz API „Canva Button"</strong> w <a href="<?= APP_URL ?>/admin/canva.php">ustawieniach Canva</a>
  (klucz uzyskasz w <a href="https://www.canva.com/developers/" target="_blank" rel="noopener">Canva Developers</a> → Design Button).
  <?php else: ?>— skontaktuj się z administratorem.<?php endif; ?>
</div>

<?php else: ?>
<p class="text-muted" style="font-size:.88rem">Wybierz rodzaj projektu — otworzy się edytor Canva. Po kliknięciu <strong>„Opublikuj"</strong> projekt zapisze się na Twoim koncie Canva, a link pojawi się poniżej.</p>

<div class="row g-3" id="canvaTypes">
  <?php foreach ($TYPES as [$type, $label, $icon]): ?>
  <div class="col-6 col-md-4 col-lg-3">
    <button type="button" class="btn w-100 h-100 text-start canva-create" data-type="<?= h($type) ?>"
            style="border:1.5px solid #e9d5ff;background:#faf5ff;border-radius:12px;padding:.9rem 1rem;transition:.12s"
            onmouseover="this.style.background='#f3e8ff'" onmouseout="this.style.background='#faf5ff'">
      <div style="font-size:1.5rem;color:#7c3aed"><i class="bi <?= h($icon) ?>"></i></div>
      <div style="font-weight:600;font-size:.85rem;color:#4c1d95;margin-top:.25rem"><?= h($label) ?></div>
    </button>
  </div>
  <?php endforeach; ?>
</div>

<!-- Wynik publikacji -->
<div id="canvaResult" class="mt-4" style="display:none">
  <h6 class="text-muted text-uppercase fw-bold mb-2" style="font-size:.72rem;letter-spacing:.08em"><i class="bi bi-check-circle me-1"></i>Opublikowane projekty</h6>
  <div id="canvaResultList" class="row g-3"></div>
</div>

<div id="canvaStatus" class="text-muted mt-3" style="font-size:.82rem"></div>

<script src="https://sdk.canva.com/designbutton/v2/api.js"></script>
<script>
(function(){
  var KEY = <?= json_encode($apiKey) ?>;
  var statusEl = document.getElementById('canvaStatus');
  var api = null;

  function setStatus(t){ statusEl.textContent = t || ''; }

  if (!window.Canva || !Canva.DesignButton) { setStatus('Nie udało się załadować SDK Canva (sprawdź połączenie/wtyczki blokujące).'); return; }

  setStatus('Łączenie z Canva…');
  Canva.DesignButton.initialize({ apiKey: KEY }).then(function(a){
    api = a; setStatus('');
  }).catch(function(){ setStatus('Błąd inicjalizacji Canva — sprawdź klucz API w ustawieniach.'); });

  function addResult(opts){
    var box = document.getElementById('canvaResult');
    var list = document.getElementById('canvaResultList');
    box.style.display = 'block';
    var col = document.createElement('div');
    col.className = 'col-6 col-md-4 col-lg-3';
    var title = (opts && opts.designTitle) ? opts.designTitle : 'Projekt Canva';
    var img = (opts && opts.exportUrl) ? '<img src="'+opts.exportUrl+'" alt="" style="width:100%;border-radius:8px;border:1px solid #e9d5ff">' : '';
    var link = (opts && opts.exportUrl) ? opts.exportUrl : '#';
    col.innerHTML = '<div style="border:1px solid #e9d5ff;border-radius:10px;padding:.5rem;background:#fff">'
      + img
      + '<div style="font-size:.8rem;font-weight:600;margin:.4rem 0 .2rem">'+title.replace(/[<>&]/g,'')+'</div>'
      + '<a href="'+link+'" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-download me-1"></i>Pobierz / otwórz</a>'
      + '</div>';
    list.prepend(col);
  }

  document.querySelectorAll('.canva-create').forEach(function(btn){
    btn.addEventListener('click', function(){
      if (!api) { setStatus('Trwa łączenie z Canva — spróbuj za chwilę.'); return; }
      api.createDesign({
        design: { type: btn.dataset.type },
        onDesignPublish: function(opts){ addResult(opts); setStatus('Projekt opublikowany ✓'); },
        onDesignOpen: function(){ setStatus('Edytor Canva otwarty…'); },
        onDesignClose: function(){ setStatus(''); }
      });
    });
  });
})();
</script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
