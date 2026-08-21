<?php
/** EZD Sidebar — partial ładowany przez header.php tylko na stronach /ezd/. */
$_ezd_sp = parse_url($_uri ?? ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '';

// Pobierz odznaki (ostrożnie — te pliki mogą nie być jeszcze załadowane)
$_esb_rpw = $_esb_rpwy = $_esb_dekr = 0;
try {
    if (function_exists('ezd_rpw_stats')) {
        $__rs       = ezd_rpw_stats();
        $_esb_rpw   = (int)($__rs['koszulka'] ?? 0);
    }
    if (function_exists('ezd_rpwy_stats')) {
        $__ws       = ezd_rpwy_stats();
        $_esb_rpwy  = (int)($__ws['do_nadania'] ?? 0);
    }
    if (function_exists('db_one') && function_exists('current_user') && ($__cu = current_user())) {
        $_esb_dekr  = (int)(db_one(
            "SELECT COUNT(*) c FROM ezd_dekretacje WHERE wykonawca_id=? AND status='oczekuje'",
            [(int)$__cu['id']]
        )['c'] ?? 0);
    }
} catch (\Throwable $e) {}

// Pomocnik: zwraca ' active' gdy ścieżka pasuje
function _esb_a(string $segment): string {
    global $_ezd_sp;
    return str_contains($_ezd_sp, $segment) ? ' active' : '';
}
function _esb_exact(string $path): string {
    global $_ezd_sp;
    return (rtrim($_ezd_sp, '/') === rtrim($path, '/')) ? ' active' : '';
}
?>
<aside class="ezd-sidebar" id="ezdSidebar" aria-label="Nawigacja EZD">

  <!-- Nagłówek sidebara: identyfikator modułu + przycisk zamknięcia (mobile) -->
  <div class="ezd-sb-header">
    <div class="ezd-sb-module-id">
      <span class="ezd-sb-mod-icon"><i class="bi bi-building-gear" aria-hidden="true"></i></span>
      <div>
        <div class="ezd-sb-mod-name">Wirtualne biurko</div>
        <div class="ezd-sb-mod-tag">EZD</div>
      </div>
    </div>
    <button type="button" class="ezd-sb-close d-lg-none" id="ezdSidebarClose" aria-label="Zamknij panel nawigacji">
      <i class="bi bi-x-lg" aria-hidden="true"></i>
    </button>
  </div>

  <?php if (function_exists('can_edit') && can_edit()): ?>
  <div class="ezd-sb-actions">
    <a href="<?= APP_URL ?>/ezd/sprawy/add.php" class="btn btn-primary btn-sm w-100 d-flex align-items-center justify-content-center gap-1">
      <i class="bi bi-folder-plus" aria-hidden="true"></i>Nowa koszulka
    </a>
  </div>
  <?php endif; ?>

  <nav class="ezd-sb-nav" aria-label="Moduły EZD">

    <a href="<?= APP_URL ?>/ezd/index.php"
       class="ezd-sb-link<?= _esb_exact('/ezd/index.php') ?>">
      <i class="bi bi-house" aria-hidden="true"></i><span>Pulpit</span>
    </a>

    <a href="<?= APP_URL ?>/ezd/szukaj.php"
       class="ezd-sb-link<?= _esb_a('/ezd/szukaj') ?>">
      <i class="bi bi-search" aria-hidden="true"></i><span>Szukaj</span>
    </a>

    <a href="<?= APP_URL ?>/ezd/rpw/index.php"
       class="ezd-sb-link<?= _esb_a('/ezd/rpw/') ?>">
      <i class="bi bi-mailbox2" aria-hidden="true"></i><span>Dziennik podawczy</span>
      <?php if ($_esb_rpw): ?>
      <span class="ezd-sb-badge"><?= $_esb_rpw ?></span>
      <?php endif; ?>
    </a>

    <a href="<?= APP_URL ?>/ezd/rpwy/index.php"
       class="ezd-sb-link<?= _esb_a('/ezd/rpwy/') ?>">
      <i class="bi bi-send" aria-hidden="true"></i><span>Książka nadawcza</span>
      <?php if ($_esb_rpwy): ?>
      <span class="ezd-sb-badge"><?= $_esb_rpwy ?></span>
      <?php endif; ?>
    </a>

    <a href="<?= APP_URL ?>/ezd/sprawy/index.php"
       class="ezd-sb-link<?= _esb_a('/ezd/sprawy/') ?>">
      <i class="bi bi-folder2-open" aria-hidden="true"></i><span>Koszulki</span>
    </a>

    <a href="<?= APP_URL ?>/ezd/pisma/add.php"
       class="ezd-sb-link<?= _esb_a('/ezd/pisma/') ?>">
      <i class="bi bi-envelope" aria-hidden="true"></i><span>Pisma</span>
    </a>

    <a href="<?= APP_URL ?>/ezd/teczki/index.php"
       class="ezd-sb-link<?= _esb_a('/ezd/teczki/') ?>">
      <i class="bi bi-archive" aria-hidden="true"></i><span>Segregatory aktowe</span>
    </a>

    <a href="<?= APP_URL ?>/ezd/zadania/index.php"
       class="ezd-sb-link<?= _esb_a('/ezd/zadania/') ?>">
      <i class="bi bi-person-lines-fill" aria-hidden="true"></i><span>Moje zadania</span>
      <?php if ($_esb_dekr): ?>
      <span class="ezd-sb-badge" style="background:#d97706"><?= $_esb_dekr ?></span>
      <?php endif; ?>
    </a>

    <div class="ezd-sb-section">Archiwistyka</div>

    <a href="<?= APP_URL ?>/ezd/jrwa/index.php"
       class="ezd-sb-link<?= _esb_a('/ezd/jrwa/') ?>">
      <i class="bi bi-tags" aria-hidden="true"></i><span>Wykaz akt (JRWA)</span>
    </a>

    <a href="<?= APP_URL ?>/ezd/archiwum/index.php"
       class="ezd-sb-link<?= _esb_a('/ezd/archiwum/') ?>">
      <i class="bi bi-archive-fill" aria-hidden="true"></i><span>Archiwum zakładowe</span>
    </a>

    <div class="ezd-sb-section">Rejestry</div>

    <a href="<?= APP_URL ?>/ezd/pelnomocnictwa/index.php"
       class="ezd-sb-link<?= _esb_a('/ezd/pelnomocnictwa/') ?>">
      <i class="bi bi-person-vcard" aria-hidden="true"></i><span>Pełnomocnictwa</span>
    </a>

    <a href="<?= APP_URL ?>/ezd/zaswiadczenia/index.php"
       class="ezd-sb-link<?= _esb_a('/ezd/zaswiadczenia/') ?>">
      <i class="bi bi-award" aria-hidden="true"></i><span>Zaświadczenia</span>
    </a>

    <a href="<?= APP_URL ?>/ezd/wolontariusze/index.php"
       class="ezd-sb-link<?= _esb_a('/ezd/wolontariusze/') ?>">
      <i class="bi bi-heart" aria-hidden="true"></i><span>Wolontariusze</span>
    </a>

    <?php if (function_exists('is_admin') && is_admin()): ?>
    <div class="ezd-sb-section">Konfiguracja</div>

    <a href="<?= APP_URL ?>/admin/ezd_settings.php"
       class="ezd-sb-link<?= _esb_a('/admin/ezd_settings') ?>">
      <i class="bi bi-gear" aria-hidden="true"></i><span>Ustawienia EZD</span>
    </a>

    <a href="<?= APP_URL ?>/admin/ezd_szablony.php"
       class="ezd-sb-link<?= _esb_a('/admin/ezd_szablony') ?>">
      <i class="bi bi-file-earmark-text" aria-hidden="true"></i><span>Szablony pism</span>
    </a>

    <a href="<?= APP_URL ?>/admin/ezd_access_matrix.php"
       class="ezd-sb-link<?= _esb_a('/admin/ezd_access_matrix') ?>">
      <i class="bi bi-shield-check" aria-hidden="true"></i><span>Macierz dostępu</span>
    </a>
    <?php endif; ?>

  </nav>

  <div class="ezd-sb-foot">
    <a href="<?= APP_URL ?>/index.php" class="ezd-sb-back">
      <i class="bi bi-arrow-left-circle" aria-hidden="true"></i><span>Powrót do SZO</span>
    </a>
  </div>

</aside>

<!-- Mobile backdrop (wyłącza sidebar) -->
<div class="ezd-sb-backdrop" id="ezdSidebarBackdrop" aria-hidden="true"></div>

<script>
(function () {
  var sb  = document.getElementById('ezdSidebar');
  var bd  = document.getElementById('ezdSidebarBackdrop');
  var cls = document.getElementById('ezdSidebarClose');
  var tog = document.getElementById('ezdSbToggle');
  if (!sb) return;
  function open()  { sb.classList.add('show');  if (bd) bd.classList.add('show'); }
  function close() { sb.classList.remove('show'); if (bd) bd.classList.remove('show'); }
  if (tog) tog.addEventListener('click', open);
  if (cls) cls.addEventListener('click', close);
  if (bd)  bd.addEventListener('click', close);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && sb.classList.contains('show')) close();
  });
})();
</script>
