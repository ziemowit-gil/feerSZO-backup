<?php
/**
 * Menu akcji pliku w repozytorium koszulki (Alpine).
 *
 * Zamiast kilkunastu ikon w jednym rzędzie: 2–3 akcje podstawowe zostają widoczne,
 * reszta trafia do jednego menu pogrupowanego tematycznie (Podgląd i edycja /
 * Wydruk / Podpis / Organizacja / strefa usuwania).
 *
 * Dlaczego Alpine, a nie dropdown Bootstrapa:
 *  - menu jest pozycjonowane `position:fixed` po współrzędnych z getBoundingClientRect(),
 *    więc nie przycinają go kontenery z overflow (zakładki, karty, przewijane listy)
 *    — to ten sam problem, który w listach umów trzeba było łatać popperem `strategy:'fixed'`;
 *  - nie dotykamy `.dropdown-menu`, więc nie da się wpaść w pułapkę z `display`
 *    nadpisującym stan `.show`.
 *
 * Ten plik NIE wypisuje niczego przy dołączeniu — style i komponent Alpine
 * emitowane są leniwie, przy pierwszym wywołaniu ezd_zal_menu() albo
 * ezd_kopia_menu_btn(). Dzięki temu można go bezpiecznie require_once'ować na
 * górze kontrolera, przed includes/header.php.
 */

require_once __DIR__ . '/ezd_kopia.php';   // ezd_kopia_menu_items(), EZD_KOPIA_TRYBY

/** Wypisuje style + komponent Alpine — raz na żądanie, przy pierwszym menu. */
function _ezd_zal_menu_assets(): void {
    static $done = false;
    if ($done) return;
    $done = true;
?>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js"></script>
<style>
[x-cloak]{display:none!important}

/* Pasek akcji w wierszu pliku */
.zm-bar{display:flex;align-items:center;gap:.25rem;flex-shrink:0}
.zm-btn{--bs-btn-padding-y:.1rem;--bs-btn-padding-x:.4rem;--bs-btn-font-size:.75rem;line-height:1.35}

/* Menu — position:fixed, żeby nie przycinały go zakładki ani karty */
.zm-menu{position:fixed;z-index:1045;min-width:16.5rem;max-width:min(22rem,calc(100vw - 1.5rem));
  background:#fff;border:1px solid #e2e8f0;border-radius:.6rem;
  box-shadow:0 .75rem 2rem rgba(15,23,42,.16),0 .125rem .35rem rgba(15,23,42,.08);
  padding:.35rem;font-size:.8rem;max-height:min(28rem,calc(100vh - 2rem));overflow-y:auto}
.zm-menu-hd{display:block;padding:.35rem .55rem .2rem;font-size:.62rem;font-weight:700;
  text-transform:uppercase;letter-spacing:.06em;color:#94a3b8}
.zm-item{display:flex;align-items:flex-start;gap:.55rem;width:100%;text-align:left;
  padding:.4rem .55rem;border:0;border-radius:.4rem;background:none;color:#1e293b;
  text-decoration:none;cursor:pointer;line-height:1.3}
.zm-item:hover,.zm-item:focus-visible{background:#f1f5f9;color:#0f172a;outline:none}
.zm-item > i:first-child{flex:0 0 1rem;margin-top:.1rem;text-align:center;color:#64748b;font-size:.92rem}
.zm-item:hover > i:first-child{color:#334155}
.zm-item-t{font-weight:600}
.zm-item-d{display:block;font-size:.7rem;color:#94a3b8;font-weight:400}
.zm-item:hover .zm-item-d{color:#64748b}
.zm-sep{height:1px;background:#eef2f7;margin:.3rem .25rem}
.zm-danger,.zm-danger > i:first-child{color:#b91c1c}
.zm-danger:hover{background:#fef2f2;color:#991b1b}
.zm-danger:hover > i:first-child{color:#991b1b}
.zm-item-accent > i:first-child{color:#6d28d9}
.zm-menu form{margin:0}

@media (prefers-color-scheme:dark){
  .zm-menu{background:#111827;border-color:#334155;box-shadow:0 .75rem 2rem rgba(0,0,0,.55)}
  .zm-item{color:#e2e8f0}
  .zm-item:hover,.zm-item:focus-visible{background:#1f2937;color:#f8fafc}
  .zm-sep{background:#1f2937}
}
</style>
<script>
document.addEventListener('alpine:init', function () {
  Alpine.data('zmMenu', function () {
    return {
      open: false, x: 0, y: 0,
      toggle: function (e) {
        if (this.open) { this.open = false; return; }
        // Zamknij inne otwarte menu (jedno naraz)
        window.dispatchEvent(new CustomEvent('zm-close-all'));
        this.place(e.currentTarget);
        this.open = true;
      },
      place: function (btn) {
        var r  = btn.getBoundingClientRect();
        var mw = 264, mh = 380;                       // przybliżenie na czas pierwszego renderu
        this.x = Math.max(8, Math.min(r.right - mw, window.innerWidth  - mw - 8));
        this.y = (r.bottom + mh > window.innerHeight - 8 && r.top - mh > 8)
               ? Math.max(8, r.top - mh)              // brak miejsca pod przyciskiem — otwórz w górę
               : r.bottom + 4;
      },
      close: function () { this.open = false; }
    };
  });
});
</script>
<?php
}

/**
 * Wypisuje pasek akcji pliku: akcje podstawowe + menu „więcej".
 *
 * Pozycje warunkowe są opt-in przez `allow`, bo nie każda strona ma obsługę POST
 * i modale, których dana akcja wymaga (np. `convert_pdf`, `pismo_from_zal` i
 * `move_grupa` obsługuje tylko widok koszulki).
 *
 * @param array $z   wiersz z ezd_zalaczniki (+ uploader, sp_web_url…)
 * @param array $opt [
 *   'can_act'   => bool   czy użytkownik może modyfikować (write + koszulka otwarta),
 *   'sprawa_id' => int,
 *   'sig'       => array  wynik ezd_signature_info() dla tego pliku,
 *   'del_field' => string nazwa pola id w akcji del_file: 'zal_id' (koszulka) | 'zid',
 *   'allow'     => array  dozwolone akcje warunkowe: convert_pdf, move_grupa,
 *                         pismo_from_zal, sign_req, rsign, email_preview, obiegi,
 *   'grupy'     => bool   czy koszulka ma zdefiniowane grupy plików,
 * ]
 */
function ezd_zal_menu(array $z, array $opt): void {
    _ezd_zal_menu_assets();

    $zid  = (int)$z['id'];
    $name = (string)$z['original_name'];
    $zext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    $can_act   = !empty($opt['can_act']);
    $sprawa_id = (int)($opt['sprawa_id'] ?? $z['sprawa_id'] ?? 0);
    $has_grupy = !empty($opt['grupy']);
    $sig       = (array)($opt['sig'] ?? ['signed' => false]);
    $del_field = ($opt['del_field'] ?? 'zal_id') === 'zid' ? 'zid' : 'zal_id';

    $allow  = (array)($opt['allow'] ?? []);
    $can    = function (string $k) use ($allow) { return in_array($k, $allow, true); };
    $obiegi = $can('obiegi') && module_enabled('obiegi_enabled');

    $is_pdf   = $zext === 'pdf';
    $is_mail  = in_array($zext, ['eml', 'msg'], true) && $can('email_preview');
    $is_img   = in_array($zext, ['png','jpg','jpeg','gif','webp'], true);
    $is_off   = in_array($zext, EZD_OFFICE_ONLINE_EXT, true);
    $sp_url   = trim((string)($z['sp_web_url'] ?? ''));
    $serve    = APP_URL . '/ezd/serve.php?id=' . $zid;
    $uid      = 'zm' . $zid;
    ?>
    <div class="zm-bar" x-data="zmMenu" @zm-close-all.window="close()" @keydown.escape.window="close()">

      <?php /* ── Akcje podstawowe: podgląd + pobranie ────────────────────── */ ?>
      <?php if ($is_pdf): ?>
      <a href="<?= h($serve) ?>" class="btn btn-outline-secondary zm-btn ezd-pdf-btn"
         data-url="<?= h($serve) ?>" data-name="<?= h($name) ?>" title="Podgląd PDF w okienku"><i class="bi bi-eye"></i></a>
      <?php elseif ($is_mail): ?>
      <button type="button" class="btn btn-outline-secondary zm-btn ezd-email-btn"
              data-id="<?= $zid ?>" data-name="<?= h($name) ?>" title="Podgląd wiadomości e-mail"><i class="bi bi-envelope-open"></i></button>
      <?php elseif ($is_img): ?>
      <a href="<?= h($serve) ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary zm-btn" title="Podgląd obrazu"><i class="bi bi-eye"></i></a>
      <?php elseif ($is_off): ?>
      <a href="<?= APP_URL ?>/ezd/office_online.php?id=<?= $zid ?>" target="_blank" rel="noopener"
         class="btn btn-outline-primary zm-btn" title="Otwórz w Office Online"><i class="bi bi-microsoft"></i></a>
      <?php endif; ?>

      <a href="<?= h($serve) ?>&dl=1" class="btn btn-outline-secondary zm-btn" title="Pobierz plik na dysk"><i class="bi bi-download"></i></a>

      <?php /* ── Menu „więcej" ──────────────────────────────────────────── */ ?>
      <button type="button" class="btn btn-outline-secondary zm-btn" @click="toggle($event)"
              :aria-expanded="open ? 'true' : 'false'" aria-haspopup="true"
              aria-controls="<?= $uid ?>" title="Wszystkie akcje dla tego pliku">
        <i class="bi bi-three-dots-vertical"></i>
      </button>

      <div class="zm-menu" id="<?= $uid ?>" role="menu" x-show="open" x-cloak
           @click.outside="close()" @click="close()"
           :style="'top:' + y + 'px; left:' + x + 'px'"
           x-transition.opacity.duration.120ms>

        <?php /* Podgląd i edycja */ ?>
        <span class="zm-menu-hd">Podgląd i edycja</span>
        <a class="zm-item" role="menuitem" href="<?= h($serve) ?>" target="_blank" rel="noopener">
          <i class="bi bi-box-arrow-up-right"></i><span><span class="zm-item-t">Otwórz w nowej karcie</span>
          <span class="zm-item-d">Plik w oryginalnej postaci</span></span>
        </a>
        <?php if ($is_off): ?>
        <a class="zm-item" role="menuitem" href="<?= APP_URL ?>/ezd/office_online.php?id=<?= $zid ?>" target="_blank" rel="noopener">
          <i class="bi bi-microsoft"></i><span><span class="zm-item-t">Edytuj w Office Online</span>
          <span class="zm-item-d">Word / Excel w przeglądarce</span></span>
        </a>
        <?php if ($sp_url !== ''): $scheme = in_array($zext, ['xls','xlsx'], true) ? 'ms-excel' : 'ms-word'; ?>
        <a class="zm-item" role="menuitem" href="<?= h($scheme) ?>:ofe|u|<?= rawurlencode($sp_url) ?>">
          <i class="bi bi-window-desktop"></i><span><span class="zm-item-t">Edytuj w aplikacji na komputerze</span>
          <span class="zm-item-d">Uruchomi Worda albo Excela</span></span>
        </a>
        <button type="button" class="zm-item ezd-oop-btn" role="menuitem"
                data-bs-toggle="modal" data-bs-target="#officeOnlinePullModal"
                data-zal="<?= $zid ?>" data-name="<?= h($name) ?>">
          <i class="bi bi-cloud-arrow-down"></i><span><span class="zm-item-t">Zapisz zmiany z Office Online</span>
          <span class="zm-item-d">Jako nowa wersja albo zastąpienie pliku</span></span>
        </button>
        <?php endif; ?>
        <?php elseif ($sp_url !== ''): ?>
        <a class="zm-item" role="menuitem" href="<?= h($sp_url) ?>" target="_blank" rel="noopener">
          <i class="bi bi-cloud-check"></i><span><span class="zm-item-t">Otwórz na SharePoint</span>
          <span class="zm-item-d">Kopia pliku zsynchronizowana w chmurze</span></span>
        </a>
        <?php endif; ?>

        <?php /* Wydruk */ ?>
        <div class="zm-sep"></div>
        <span class="zm-menu-hd">Wydruk kopii</span>
        <?php foreach (ezd_kopia_menu_items('zalacznik', $zid, $name) as $it): ?>
        <a class="zm-item ezd-pdf-btn" role="menuitem" href="<?= h($it['url']) ?>"
           data-url="<?= h($it['url']) ?>" data-name="<?= h($it['name']) ?>">
          <i class="bi <?= h($it['icon']) ?>"></i><span><span class="zm-item-t"><?= h($it['label']) ?></span>
          <span class="zm-item-d"><?= h($it['opis']) ?></span></span>
        </a>
        <?php endforeach; ?>
        <?php if ($can_act && $can('convert_pdf') && in_array($zext, EZD_PDF_CONVERTIBLE_EXT, true)): ?>
        <form method="post" onsubmit="return confirm('Przekonwertować „<?= h($name) ?>" na PDF?')">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="convert_pdf">
          <input type="hidden" name="zal_id" value="<?= $zid ?>">
          <button type="submit" class="zm-item" role="menuitem">
            <i class="bi bi-filetype-pdf"></i><span><span class="zm-item-t">Konwertuj na PDF</span>
            <span class="zm-item-d">Doda osobny plik PDF do koszulki</span></span>
          </button>
        </form>
        <?php endif; ?>

        <?php /* Podpis */ ?>
        <?php $show_sign = !empty($sig['signed']) || ($can_act && $is_pdf && $can('rsign')) || ($can_act && $can('sign_req')); ?>
        <?php if ($show_sign): ?>
        <div class="zm-sep"></div>
        <span class="zm-menu-hd">Podpis</span>
        <?php if (!empty($sig['signed'])): ?>
        <button type="button" class="zm-item ezd-sig-btn" role="menuitem"
                data-zal="<?= $zid ?>" data-file="<?= h($name) ?>"
                data-type="<?= h((string)($sig['type'] ?? '')) ?>" data-signer="<?= h((string)($sig['signer'] ?? '')) ?>"
                data-date="<?= h((string)($sig['signed_at'] ?? '')) ?>" data-reason="<?= h((string)($sig['reason'] ?? '')) ?>"
                data-location="<?= h((string)($sig['location'] ?? '')) ?>" data-note="<?= h((string)($sig['note'] ?? '')) ?>">
          <i class="bi bi-patch-check"></i><span><span class="zm-item-t">Sprawdź podpis elektroniczny</span>
          <span class="zm-item-d">Kto podpisał, kiedy i jakim certyfikatem</span></span>
        </button>
        <?php endif; ?>
        <?php if ($can_act && $is_pdf && $can('rsign')): ?>
        <button type="button" class="zm-item zm-item-accent ezd-rsign-btn" role="menuitem"
                data-zal-id="<?= $zid ?>" data-zal-name="<?= h($name) ?>">
          <i class="bi bi-pen-fill"></i><span><span class="zm-item-t">Podpisz kwalifikowanym</span>
          <span class="zm-item-d">rSign — podpis PAdES na tym pliku</span></span>
        </button>
        <?php endif; ?>
        <?php if ($can_act && $can('sign_req')): ?>
        <button type="button" class="zm-item ezd-sign-req-btn" role="menuitem"
                data-zal-id="<?= $zid ?>" data-zal-name="<?= h($name) ?>">
          <i class="bi bi-person-check"></i><span><span class="zm-item-t">Przekaż do podpisu</span>
          <span class="zm-item-d">Wyśle prośbę do wskazanej osoby</span></span>
        </button>
        <?php endif; ?>
        <?php endif; ?>

        <?php /* Organizacja */ ?>
        <?php $show_org = $can_act && (($has_grupy && $can('move_grupa')) || ($is_pdf && $can('pismo_from_zal')) || $obiegi); ?>
        <?php if ($show_org): ?>
        <div class="zm-sep"></div>
        <span class="zm-menu-hd">Organizacja</span>
        <?php if ($has_grupy && $can('move_grupa')): ?>
        <button type="button" class="zm-item ezd-move-grupa-btn" role="menuitem"
                data-bs-toggle="modal" data-bs-target="#zalMoveGroupModal"
                data-zal="<?= $zid ?>" data-name="<?= h($name) ?>" data-grupa="<?= (int)($z['grupa_id'] ?? 0) ?>">
          <i class="bi bi-folder-symlink"></i><span><span class="zm-item-t">Przenieś do grupy</span>
          <span class="zm-item-d">Zmieni grupę w repozytorium koszulki</span></span>
        </button>
        <?php endif; ?>
        <?php if ($is_pdf && $can('pismo_from_zal')): ?>
        <button type="button" class="zm-item" role="menuitem"
                data-bs-toggle="modal" data-bs-target="#pismoFromZalModal"
                data-zal="<?= $zid ?>" data-name="<?= h($name) ?>">
          <i class="bi bi-envelope-plus"></i><span><span class="zm-item-t">Zarejestruj jako pismo</span>
          <span class="zm-item-d">Utworzy pismo w koszulce z tego pliku</span></span>
        </button>
        <?php endif; ?>
        <?php if ($obiegi): ?>
        <a class="zm-item" role="menuitem"
           href="<?= APP_URL ?>/obiegi/new.php?ezd_sprawa_id=<?= $sprawa_id ?>&ezd_zalacznik_id=<?= $zid ?>">
          <i class="bi bi-diagram-2"></i><span><span class="zm-item-t">Uruchom obieg dokumentu</span>
          <span class="zm-item-d">Skieruje plik do procesu w module Obiegi</span></span>
        </a>
        <?php endif; ?>
        <?php endif; ?>

        <?php /* Usuwanie */ ?>
        <?php if ($can_act): ?>
        <div class="zm-sep"></div>
        <form method="post" onsubmit="return confirm('Usunąć plik „<?= h($name) ?>"?')">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="del_file">
          <input type="hidden" name="<?= $del_field ?>" value="<?= $zid ?>">
          <button type="submit" class="zm-item zm-danger" role="menuitem">
            <i class="bi bi-trash3"></i><span><span class="zm-item-t">Usuń plik</span>
            <span class="zm-item-d">Nieodwracalnie — z koszulki i z dysku</span></span>
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php
}

/**
 * Kompaktowe menu „Wydruk kopii" do pasków akcji dokumentu (pismo, dokument
 * wewnętrzny, umowa, zaświadczenie). Jeden przycisk z rozwijaną listą trzech
 * trybów — w toolbarze trzy osobne przyciski byłyby szumem.
 *
 * Zasoby (styl + komponent Alpine `zmMenu`) emituje sama, przy pierwszym użyciu.
 *
 * @param string $cls dodatkowe klasy przycisku (np. 'btn-sm')
 */
function ezd_kopia_menu_btn(string $type, int $id, string $name = '', string $cls = 'btn-sm'): void {
    _ezd_zal_menu_assets();

    $uid = 'kp' . preg_replace('/[^a-z0-9]+/i', '', $type) . $id;
    ?>
    <div class="d-inline-block" x-data="zmMenu" @zm-close-all.window="close()" @keydown.escape.window="close()">
      <button type="button" class="btn btn-outline-dark <?= h($cls) ?>" @click="toggle($event)"
              :aria-expanded="open ? 'true' : 'false'" aria-haspopup="true" aria-controls="<?= $uid ?>"
              title="Wydruk kopii dokumentu elektronicznego — wybierz tryb">
        <i class="bi bi-printer me-1"></i>Wydruk kopii
        <i class="bi bi-chevron-down ms-1" style="font-size:.65rem;opacity:.6"></i>
      </button>
      <div class="zm-menu" id="<?= $uid ?>" role="menu" x-show="open" x-cloak
           @click.outside="close()" @click="close()"
           :style="'top:' + y + 'px; left:' + x + 'px'"
           x-transition.opacity.duration.120ms>
        <span class="zm-menu-hd">Wybierz tryb wydruku</span>
        <?php foreach (ezd_kopia_menu_items($type, $id, $name) as $it): ?>
        <a class="zm-item ezd-pdf-btn" role="menuitem" href="<?= h($it['url']) ?>"
           data-url="<?= h($it['url']) ?>" data-name="<?= h($it['name']) ?>">
          <i class="bi <?= h($it['icon']) ?>"></i><span><span class="zm-item-t"><?= h($it['label']) ?></span>
          <span class="zm-item-d"><?= h($it['opis']) ?></span></span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php
}
