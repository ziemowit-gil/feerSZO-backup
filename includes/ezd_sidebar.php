<?php
/** EZD Sidebar — partial ładowany przez header.php tylko na stronach /ezd/. */
$_ezd_sp = parse_url($_uri ?? ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '';

// Rola zawężona wyłącznie do Rejestru Przychodzących (RPW, np. 'ezd_biuro'):
// pokaż w sidebarze TYLKO Dziennik podawczy + Szukaj (w wersji RPW-only) —
// reszta sekcji i tak jest zablokowana przez require_login() (auth.php).
$_esb_rpw_only = function_exists('is_ezd_rpw_only') && is_ezd_rpw_only();

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
/** Pozycja menu: ikona + etykieta (+ odznaka); title = podpowiedź w zwiniętym pasku ikon. */
function _esb_link(string $href, string $icon, string $label, string $active, int $badge = 0, string $badgeStyle = ''): string {
    return '<a href="' . APP_URL . $href . '" class="ezd-sb-link' . $active . '" title="' . h($label) . '"' . ($active !== '' ? ' aria-current="page"' : '') . '>'
         . '<i class="bi ' . $icon . '" aria-hidden="true"></i><span class="ezd-sb-lbl">' . h($label) . '</span>'
         . ($badge ? '<span class="ezd-sb-badge"' . ($badgeStyle !== '' ? ' style="' . $badgeStyle . '"' : '') . '>' . $badge . '</span>' : '') . '</a>';
}
// „Ostatnie” — koszulki otwierane w tej sesji (zapis w ezd/sprawy/view.php), jak lista Ostatnie w Dynamics 365
$_esb_recent = array_slice((array)($_SESSION['ezd_recent'] ?? []), 0, 5);
?>
<aside class="ezd-sidebar ezd-sb-dyn" id="ezdSidebar" aria-label="Nawigacja EZD">
<script>try{if(localStorage.getItem('ezd_sb_collapsed')==='1'&&window.matchMedia('(min-width:992px)').matches)document.getElementById('ezdSidebar').classList.add('is-collapsed')}catch(e){}</script>

  <!-- Nagłówek menu: zwijanie do paska ikon (jak ☰ w Dynamics 365) + zamknięcie (mobile) -->
  <div class="ezd-sb-header">
    <button type="button" class="ezd-sb-collapse d-none d-lg-inline-flex" id="ezdSbCollapse" aria-controls="ezdSidebar" aria-expanded="true" title="Zwiń menu">
      <i class="bi bi-list" aria-hidden="true"></i><span class="visually-hidden">Zwiń lub rozwiń menu</span>
    </button>
    <div class="ezd-sb-module-id d-lg-none">
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

  <nav class="ezd-sb-nav" aria-label="Moduły EZD">

    <?php if ($_esb_rpw_only): ?>

    <?= _esb_link('/ezd/rpw/index.php', 'bi-mailbox2', 'Dziennik podawczy', _esb_a('/ezd/rpw/') && !_esb_a('/ezd/rpw/szukaj') ? ' active' : '', $_esb_rpw) ?>
    <?= _esb_link('/ezd/rpw/szukaj.php', 'bi-search', 'Szukaj', _esb_a('/ezd/rpw/szukaj')) ?>

    <?php else: ?>

    <?= _esb_link('/ezd/index.php', 'bi-house', 'Pulpit', _esb_exact('/ezd/index.php')) ?>
    <?php if ($_esb_recent): ?>
    <details class="ezd-sb-recent">
      <summary class="ezd-sb-link" title="Ostatnie"><i class="bi bi-clock-history" aria-hidden="true"></i><span class="ezd-sb-lbl">Ostatnie</span><i class="bi bi-chevron-down ezd-sb-chev" aria-hidden="true"></i></summary>
      <?php foreach ($_esb_recent as $_r): ?>
      <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= (int)$_r['id'] ?>" class="ezd-sb-sublink" title="<?= h($_r['znak'] . ' — ' . $_r['title']) ?>">
        <span class="ezd-sb-znak"><?= h($_r['znak']) ?></span><span class="ezd-sb-sub-t"><?= h($_r['title']) ?></span>
      </a>
      <?php endforeach; ?>
    </details>
    <?php endif; ?>
    <?= _esb_link('/ezd/szukaj.php', 'bi-search', 'Szukaj', _esb_a('/ezd/szukaj')) ?>
    <?php if (function_exists('can_edit') && can_edit()): ?>
    <a href="<?= APP_URL ?>/ezd/sprawy/add.php" class="ezd-sb-link ezd-sb-new" title="Nowa koszulka"><i class="bi bi-plus-lg" aria-hidden="true"></i><span class="ezd-sb-lbl">Nowa koszulka</span></a>
    <?php endif; ?>

    <div class="ezd-sb-section" role="presentation">Moja praca</div>
    <?= _esb_link('/ezd/zadania/index.php', 'bi-person-lines-fill', 'Moje zadania', _esb_a('/ezd/zadania/'), $_esb_dekr, 'background:#c25100') ?>
    <?= _esb_link('/ezd/sprawy/index.php', 'bi-folder2-open', 'Koszulki', _esb_a('/ezd/sprawy/')) ?>
    <?= _esb_link('/ezd/pisma/add.php', 'bi-envelope', 'Pisma', _esb_a('/ezd/pisma/')) ?>

    <div class="ezd-sb-section" role="presentation">Kancelaria</div>
    <?= _esb_link('/ezd/rpw/index.php', 'bi-mailbox2', 'Dziennik podawczy', _esb_a('/ezd/rpw/'), $_esb_rpw) ?>
    <?= _esb_link('/ezd/rpwy/index.php', 'bi-send', 'Książka nadawcza', _esb_a('/ezd/rpwy/'), $_esb_rpwy) ?>
    <?= _esb_link('/ezd/teczki/index.php', 'bi-archive', 'Segregatory aktowe', _esb_a('/ezd/teczki/')) ?>

    <div class="ezd-sb-section" role="presentation">Archiwistyka</div>
    <?= _esb_link('/ezd/jrwa/index.php', 'bi-tags', 'Wykaz akt (JRWA)', _esb_a('/ezd/jrwa/')) ?>
    <?= _esb_link('/ezd/archiwum/index.php', 'bi-archive-fill', 'Archiwum zakładowe', _esb_a('/ezd/archiwum/')) ?>

    <div class="ezd-sb-section" role="presentation">Rejestry</div>
    <?php if (!function_exists('module_enabled') || module_enabled('pelnomocnictwa_enabled')): ?>
    <?= _esb_link('/pelnomocnictwa/index.php', 'bi-person-badge', 'Pełnomocnictwa', _esb_a('/pelnomocnictwa/')) ?>
    <?php endif; ?>
    <?= _esb_link('/ezd/zaswiadczenia/index.php', 'bi-award', 'Zaświadczenia', _esb_a('/ezd/zaswiadczenia/')) ?>
    <?= _esb_link('/ezd/wolontariusze/index.php', 'bi-heart', 'Wolontariusze', _esb_a('/ezd/wolontariusze/')) ?>

    <?php if (function_exists('is_admin') && is_admin()): ?>
    <div class="ezd-sb-section" role="presentation">Konfiguracja</div>
    <?= _esb_link('/admin/ezd_settings.php', 'bi-gear', 'Ustawienia EZD', _esb_a('/admin/ezd_settings')) ?>
    <?= _esb_link('/admin/ezd_szablony.php', 'bi-file-earmark-text', 'Szablony pism', _esb_a('/admin/ezd_szablony')) ?>
    <?= _esb_link('/admin/ezd_access_matrix.php', 'bi-shield-check', 'Macierz dostępu', _esb_a('/admin/ezd_access_matrix')) ?>
    <?php endif; ?>

    <?php endif; ?>

  </nav>

  <!-- Przełącznik obszaru (jak na dole mapy witryny Dynamics 365): bieżący moduł + powrót do SZO -->
  <div class="ezd-sb-foot">
    <a href="<?= APP_URL ?>/index.php" class="ezd-sb-back ezd-sb-area" title="Powrót do SZO">
      <span class="ezd-sb-area-tile" aria-hidden="true">EZ</span>
      <span class="ezd-sb-lbl"><span class="ezd-sb-area-n">Wirtualne biurko</span><span class="ezd-sb-area-s">Powrót do SZO</span></span>
      <i class="bi bi-chevron-expand ezd-sb-chev" aria-hidden="true"></i>
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
  var col = document.getElementById('ezdSbCollapse');
  if (!sb) return;
  function open()  { sb.classList.add('show');  if (bd) bd.classList.add('show'); }
  function close() { sb.classList.remove('show'); if (bd) bd.classList.remove('show'); }
  if (tog) tog.addEventListener('click', open);
  if (cls) cls.addEventListener('click', close);
  if (bd)  bd.addEventListener('click', close);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && sb.classList.contains('show')) close();
  });
  // Zwijanie do paska ikon (desktop); stan w localStorage — tylko wygoda tej przeglądarki
  function syncCol() {
    var c = sb.classList.contains('is-collapsed');
    if (col) { col.setAttribute('aria-expanded', c ? 'false' : 'true'); col.title = c ? 'Rozwiń menu' : 'Zwiń menu'; }
  }
  syncCol();
  if (col) col.addEventListener('click', function () {
    sb.classList.toggle('is-collapsed');
    try { localStorage.setItem('ezd_sb_collapsed', sb.classList.contains('is-collapsed') ? '1' : '0'); } catch (e) {}
    syncCol();
  });
  // „Ostatnie” w zwiniętym menu: rozwiń menu, żeby lista była czytelna
  var rec = sb.querySelector('.ezd-sb-recent');
  if (rec) rec.addEventListener('toggle', function () {
    if (rec.open && sb.classList.contains('is-collapsed')) { sb.classList.remove('is-collapsed'); syncCol(); }
  });
})();
</script>
