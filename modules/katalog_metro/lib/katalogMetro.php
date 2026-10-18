<?php
/**
 * modules/katalog_metro/lib/katalogMetro.php — kafle ekranu Start Metro dla Katalogu
 * współpracowników (/katalog = directory/): osoby, jednostki, szczegóły jednostki.
 *
 * Opiera się na zestawie modules/panel_metro (panelMetroAssets + .pvm .mt-grid/.mt-tile),
 * dokłada tylko to, czego tam brak: kafel osoby ze zdjęciem i kolor jednostki stały wg id.
 * Strony wołają katalogMetroAssets() raz, w treści, wewnątrz kontenera .pvm.
 */
require_once dirname(__DIR__, 2) . '/panel_metro/lib/panelMetro.php';
require_once dirname(__DIR__, 2) . '/org_levels/lib/orgLevels.php';

const KATALOG_ACCENT = '#0050ef';
const KATALOG_UNIT_COLORS = ['teal', 'blue', 'green', 'violet', 'orange', 'indigo', 'magenta', 'red'];

function katalogMetroAssets(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    panelMetroAssets(KATALOG_ACCENT);
    ?>
<style>
.pvm.ktm{max-width:1320px}
.pvm .ktm-person .ava{width:56px;height:56px;font-size:1.3rem;overflow:hidden;flex-shrink:0}
.pvm .ktm-person .ava img{width:100%;height:100%;object-fit:cover;display:block}
.pvm .ktm-person .lbl{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pvm .ktm-person.mt-tile--w{flex-direction:row;align-items:flex-end;gap:14px;justify-content:flex-start}
.pvm .ktm-person.mt-tile--w .ava{width:88px;height:88px;font-size:2rem;align-self:center}
.pvm .ktm-person.mt-tile--w > span:last-child{min-width:0;flex:1}
.pvm .ktm-person.mt-tile--l .ava{width:104px;height:104px;font-size:2.4rem}
.pvm .ktm-person.mt-tile--l .lbl{font-size:1.5rem;font-weight:300;white-space:normal}
.pvm .ktm-unit .val{font-size:1.6rem}
.pvm .ktm-unit .lbl{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;white-space:normal}
.pvm .ktm-unit.is-on{outline:4px solid var(--m-dark);outline-offset:-4px}
.pvm .ktm-search{display:flex;gap:0;max-width:640px;margin-top:1rem}
.pvm .ktm-search input,.pvm .ktm-search select{border:2px solid #8a8a8a;border-radius:0;padding:.55rem .75rem;font:inherit;font-size:1rem;background:var(--pvm-bg);color:var(--pvm-ink);min-width:0}
.pvm .ktm-search input{flex:1}
.pvm .ktm-search input:focus,.pvm .ktm-search select:focus{border-color:var(--pvm-acc);outline:0}
.pvm .ktm-search select{border-left:0;max-width:220px}
.pvm .ktm-search button{border:0;background:var(--pvm-acc);color:#fff;font-weight:600;padding:0 1.1rem;cursor:pointer}
.pvm .ktm-search button:hover{background:#1d1d1d}
.pvm .ktm-bar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.5rem;margin:0 0 .5rem}
.pvm .ktm-bar .mt-grp-h{margin:0}
.pvm .ktm-chip{display:inline-flex;align-items:center;gap:.35rem;background:var(--pvm-light);color:var(--pvm-ink);text-decoration:none;padding:.3rem .7rem;font-size:.84rem;font-weight:600}
.pvm .ktm-chip:hover{background:var(--pvm-light-h);color:var(--pvm-ink)}
.pvm .ktm-count{font-size:.85rem;color:var(--pvm-muted)}
.pvm .ktm-empty{background:var(--pvm-light);padding:2rem;text-align:center;color:var(--pvm-muted)}
.pvm .mt-grp-h{margin:0 0 .5rem;font-size:1.05rem;font-weight:300;color:var(--pvm-muted);letter-spacing:.01em}
.pvm .mt-grp{margin-bottom:1.75rem}
.pvm .mt-group p:last-child{margin-bottom:0}
.pvm .ktm-tags{display:flex;flex-wrap:wrap;gap:4px;list-style:none;margin:0;padding:0}
.pvm .ktm-tags li{background:var(--pvm-acc);color:#fff;font-weight:600;font-size:.85rem;padding:.3rem .7rem}
.pvm .ktm-detail{border-left:6px solid var(--c,var(--pvm-acc));background:var(--pvm-light);padding:1.1rem 1.25rem;margin-bottom:1.25rem}
.pvm .ktm-detail .mt-h2{margin-bottom:.2rem}
.pvm .ktm-meta{display:flex;flex-wrap:wrap;gap:.35rem 1.1rem;font-size:.86rem;color:var(--pvm-muted);margin:.1rem 0 .6rem}
.pvm .ktm-meta a{color:inherit}
.pvm [data-ktm-hide]{display:none!important}
.pvm .ktm-prof{display:grid;gap:1.5rem;grid-template-columns:1fr;align-items:start}
@media(min-width:1100px){.pvm .ktm-prof{grid-template-columns:minmax(0,1fr) 300px}}
.pvm .ktm-prof-top{display:grid;gap:8px;grid-template-columns:1fr;margin-bottom:1rem;align-items:stretch}
@media(min-width:720px){.pvm .ktm-prof-top{grid-template-columns:308px minmax(0,1fr)}}
.pvm .ktm-prof-card{grid-template-columns:repeat(2,150px)}
.pvm .ktm-prof-top > .mt-group{margin:0}
.pvm .ktm-prof-top .mt-kv{grid-template-columns:1fr}
@media(min-width:1280px){.pvm .ktm-prof-top .mt-kv{grid-template-columns:repeat(2,1fr)}}
.pvm .ktm-side{background:var(--pvm-light);padding:1rem}
.pvm .ktm-side h2{margin:0 0 .6rem;font-size:1.05rem;font-weight:600;color:var(--pvm-ink)}
.pvm .ktm-side ul{list-style:none;margin:0;padding:0;display:grid;gap:4px}
.pvm .ktm-side a{display:flex;align-items:center;gap:.75rem;padding:.45rem;background:var(--pvm-bg);color:var(--pvm-ink);text-decoration:none;min-height:52px}
.pvm .ktm-side a:hover{background:var(--pvm-light-h)}
.pvm .ktm-side .sq{width:40px;height:40px;flex-shrink:0;background:var(--c);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.25rem}
.pvm .ktm-side b{display:block;font-size:.9rem;font-weight:600;line-height:1.2}
.pvm .ktm-side small{display:block;font-size:.74rem;color:var(--pvm-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:200px}
:root[data-theme="hc"] .pvm .ktm-side a{border:2px solid #000}
</style>
    <?php
}

/** Kolor jednostki — stały dla danego id (Metro, kontrast z białym ≥ 4,5:1). */
function katalogUnitColor(int $unitId): string {
    return panelMetroColor(KATALOG_UNIT_COLORS[abs($unitId) % count(KATALOG_UNIT_COLORS)]);
}

function katalogInitials(array $p): string {
    $s = mb_substr((string)($p['first_name'] ?? ''), 0, 1) . mb_substr((string)($p['last_name'] ?? ''), 0, 1);
    if ($s === '') {
        foreach (array_slice(preg_split('/\s+/', trim((string)($p['name'] ?? $p['user_name'] ?? ''))), 0, 2) as $w) $s .= mb_substr($w, 0, 1);
    }
    return mb_strtoupper($s ?: '?');
}

/** Adres zatwierdzonego zdjęcia albo '' (plik musi istnieć — jak directory_avatar_html()). */
function katalogAvatarUrl(array $p): string {
    $f = basename((string)($p['avatar_file'] ?? ''));
    if ($f === '' || !is_file(dirname(__DIR__, 3) . '/uploads/avatars/' . $f)) return '';
    return APP_URL . '/uploads/avatars/' . rawurlencode($f);
}

/**
 * Kafel osoby. $p: id|user_id, first_name, last_name, name, email, position_name, unit_name,
 * avatar_file, phone (już po sprawdzeniu phone_public). $o: size ('', 'w'), color, tag, href.
 */
function katalogPersonTile(array $p, array $o = []): void {
    $uid     = (int)($p['user_id'] ?? $p['id'] ?? 0);
    $name    = directory_display_name($p) ?: (string)($p['user_name'] ?? $p['email'] ?? '');
    $pos     = (string)($p['position_name'] ?? '');
    $unit    = (string)($p['unit_name'] ?? '');
    $phone   = (string)($p['phone'] ?? '');
    $sub     = $o['sub'] ?? implode(' · ', array_filter([$pos, empty($o['hideUnit']) ? $unit : '']));
    $color   = $o['color'] ?? panelMetroColor(KATALOG_UNIT_COLORS[$uid % count(KATALOG_UNIT_COLORS)]);
    $size    = !empty($o['size']) ? ' mt-tile--' . $o['size'] : '';
    $img     = katalogAvatarUrl($p);
    $search  = mb_strtolower(implode(' ', [$name, $pos, $unit, $p['email'] ?? '', $phone]));
    $aria    = $o['aria'] ?? $name . ($pos ? ', ' . $pos : '') . ($unit ? ', ' . $unit : '') . ($phone ? ', tel. ' . $phone : '')
             . (!empty($o['tag']) ? ', ' . $o['tag'] : '') . ' — otwórz profil';
    ?>
    <a href="<?= h($o['href'] ?? APP_URL . '/directory/profile.php?id=' . $uid) ?>" class="mt-tile ktm-person<?= $size ?>"
       style="--c:<?= h($color) ?>" data-text="<?= h($search) ?>" aria-label="<?= h($aria) ?>">
      <?php if (!empty($o['tag'])): ?><span class="tag" aria-hidden="true"><?= h($o['tag']) ?></span><?php endif; ?>
      <span class="ava" aria-hidden="true"><?php if ($img): ?><img src="<?= h($img) ?>" alt=""><?php else: ?><?= h(katalogInitials($p)) ?><?php endif; ?></span>
      <span aria-hidden="true">
        <span class="lbl"><?= h($name) ?></span>
        <?php if ($sub !== ''): ?><span class="sub"><?= h($sub) ?></span><?php endif; ?>
        <?php if ($phone !== '' && !empty($o['size'])): ?><span class="sub"><i class="bi bi-telephone me-1"></i><?= h($phone) ?></span><?php endif; ?>
      </span>
    </a>
    <?php
}

/** Kafel jednostki. $u: id, name, code, member_count|cnt; $o: size, on (zaznaczona), sub, icon, external. */
function katalogUnitTile(array $u, string $href, array $o = []): void {
    $cnt  = (int)($u['member_count'] ?? $u['cnt'] ?? 0);
    $ext  = !empty($o['external']);
    $sub  = $o['sub'] ?? ($cnt === 1 ? '1 osoba' : $cnt . ' ' . ($cnt % 10 >= 2 && $cnt % 10 <= 4 && ($cnt % 100 < 10 || $cnt % 100 >= 20) ? 'osoby' : 'osób'));
    $size = !empty($o['size']) ? ' mt-tile--' . $o['size'] : '';
    $col  = $o['color'] ?? ($ext ? panelMetroColor('grey') : katalogUnitColor((int)$u['id']));
    $aria = ($ext ? '' : orgLevelLabel($u) . ': ') . ($u['name'] ?? '') . ($u['code'] ? ' (' . $u['code'] . ')' : '') . ', ' . $sub . (!empty($o['on']) ? ', wybrana' : '');
    ?>
    <a href="<?= h($href) ?>" class="mt-tile ktm-unit<?= $size ?><?= !empty($o['on']) ? ' is-on' : '' ?>" style="--c:<?= h($col) ?>"
       data-text="<?= h(mb_strtolower(($u['name'] ?? '') . ' ' . ($u['code'] ?? ''))) ?>"
       aria-label="<?= h($aria) ?>"<?= !empty($o['on']) ? ' aria-current="true"' : '' ?>>
      <?php if (!empty($u['code'])): ?><span class="tag" aria-hidden="true"><?= h($u['code']) ?></span><?php endif; ?>
      <i class="bi <?= h($o['icon'] ?? ($ext ? 'bi-buildings' : ORG_LEVEL_ICONS[orgLevelOf($u) ?: 'dzial'])) ?> ic" aria-hidden="true"></i>
      <span aria-hidden="true">
        <span class="lbl"><?= h($u['name'] ?? '') ?></span>
        <span class="sub"><?= h($sub) ?></span>
      </span>
    </a>
    <?php
}

/** Aktywni członkowie jednostki (kierownik pierwszy). */
function katalogUnitMembers(int $unitId): array {
    try {
        return db_all("
            SELECT om.user_id, om.position_name, om.is_head, om.phone_direct, om.phone_mobile,
                   u.first_name, u.last_name, u.name AS user_name, u.email,
                   COALESCE(up.phone_public,0) AS phone_public, COALESCE(up.avatar_file,'') AS avatar_file
            FROM org_members om
            JOIN users u ON u.id = om.user_id
            LEFT JOIN user_profiles up ON up.user_id = om.user_id
            WHERE om.unit_id = ? AND om.status = 'active'
              AND (om.valid_to IS NULL OR om.valid_to >= date('now'))
            ORDER BY om.is_head DESC, om.sort_order, u.last_name
        ", [$unitId]);
    } catch (\Throwable $e) { return []; }
}

/**
 * Szczegóły jednostki: pas z nazwą/opisem/kontaktem, kafle podjednostek, kafle członków.
 * $hrefUnit — wzorzec linku do innej jednostki (sprintf z id).
 */
function katalogUnitDetail(int $unitId, string $hrefUnit = '', string $idSuffix = ''): void {
    try { $unit = org_unit_get($unitId); } catch (\Throwable $e) { $unit = null; }
    if (!$unit) { echo '<div class="ktm-empty" role="status">Nie znaleziono jednostki.</div>'; return; }
    $hrefUnit = $hrefUnit ?: APP_URL . '/directory/org_chart.php?unit=%d#jednostka';
    $ext      = ($unit['kind'] ?? 'internal') === 'external';
    $members  = katalogUnitMembers($unitId);
    $children = [];
    try {
        $children = db_all("SELECT u.id, u.name, u.code,
                   (SELECT COUNT(*) FROM org_members m WHERE m.unit_id=u.id AND m.status='active' AND (m.valid_to IS NULL OR m.valid_to >= date('now'))) AS cnt
            FROM org_units u WHERE u.parent_id = ? AND u.status='active' ORDER BY u.sort_order, u.name", [$unitId]);
    } catch (\Throwable $e) {}
    $meta = [];
    if (!empty($unit['parent_name']))   $meta[] = '<a href="' . h(sprintf($hrefUnit, (int)$unit['parent_id'])) . '"><i class="bi bi-arrow-up-left me-1" aria-hidden="true"></i>' . h($unit['parent_name']) . '</a>';
    if (!empty($unit['location']))      $meta[] = '<span><i class="bi bi-geo-alt me-1" aria-hidden="true"></i>' . h($unit['location']) . '</span>';
    if (!empty($unit['phone']))         $meta[] = '<a href="tel:' . h($unit['phone']) . '"><i class="bi bi-telephone me-1" aria-hidden="true"></i>' . h($unit['phone']) . '</a>';
    $mail = $unit['email'] ?? ($unit['contact_email'] ?? '');
    if ($mail)                          $meta[] = '<a href="mailto:' . h($mail) . '"><i class="bi bi-envelope me-1" aria-hidden="true"></i>' . h($mail) . '</a>';
    if ($ext && !empty($unit['contact_person'])) $meta[] = '<span><i class="bi bi-person me-1" aria-hidden="true"></i>' . h($unit['contact_person']) . '</span>';
    if ($ext && !empty($unit['www']))   $meta[] = '<a href="' . h($unit['www']) . '" target="_blank" rel="noopener noreferrer"><i class="bi bi-globe me-1" aria-hidden="true"></i>strona<span class="visually-hidden"> (otwiera się w nowej karcie)</span></a>';
    $color = $ext ? panelMetroColor('grey') : katalogUnitColor($unitId);
    static $n = 0; $n++;
    $sx = $idSuffix !== '' ? '-' . $idSuffix : ($n > 1 ? '-' . $n : ''); // unikalne id, gdy na stronie są dwie karty (kafle + drzewo)
    ?>
    <section class="ktm-detail" style="--c:<?= h($color) ?>" aria-labelledby="ktm-unit-h<?= $sx ?>">
      <h2 class="mt-h2" id="ktm-unit-h<?= $sx ?>"><b><?= h($unit['name']) ?></b><?php if (!empty($unit['code'])): ?> <span style="font-size:.9rem;color:var(--pvm-muted)"><?= h($unit['code']) ?></span><?php endif; ?></h2>
      <?php if ($meta): ?><div class="ktm-meta"><?= implode('', $meta) ?></div><?php endif; ?>
      <?php if (!empty($unit['description'])): ?><p style="white-space:pre-wrap;margin:0;font-weight:300"><?= h($unit['description']) ?></p><?php endif; ?>
    </section>
    <?php if ($children): ?>
    <section class="mt-grp" aria-labelledby="ktm-sub-h<?= $sx ?>">
      <h3 class="mt-grp-h" id="ktm-sub-h<?= $sx ?>">Jednostki podległe</h3>
      <div class="mt-grid mt-grid--sm">
        <?php foreach ($children as $c) katalogUnitTile($c, sprintf($hrefUnit, (int)$c['id'])); ?>
      </div>
    </section>
    <?php endif; ?>
    <section class="mt-grp" aria-labelledby="ktm-mem-h<?= $sx ?>">
      <h3 class="mt-grp-h" id="ktm-mem-h<?= $sx ?>">Skład · <?= count($members) ?></h3>
      <?php if (!$members): ?>
      <div class="ktm-empty" role="status">Brak osób przypisanych do tej jednostki.</div>
      <?php else: ?>
      <div class="mt-grid">
        <?php foreach ($members as $m) {
            $m['phone'] = $m['phone_public'] ? ($m['phone_direct'] ?: $m['phone_mobile']) : '';
            katalogPersonTile($m, $m['is_head']
                ? ['size' => 'w', 'tag' => 'kierownik', 'hideUnit' => true]
                : ['hideUnit' => true]);
        } ?>
      </div>
      <?php endif; ?>
    </section>
    <?php
}

/** Filtr kafli na żywo: pole #ktm-q filtruje [data-text] w kontenerach [data-ktm-filter]. */
function katalogFilterScript(): void { ?>
<script>
(function () {
  var q = document.getElementById('ktm-q'); if (!q) return;
  var out = document.getElementById('ktm-live');
  function norm(s) { return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/ł/g, 'l'); }
  function run() {
    var t = norm(q.value.trim()), shown = 0;
    document.querySelectorAll('[data-ktm-filter] [data-text]').forEach(function (el) {
      var ok = !t || norm(el.getAttribute('data-text')).indexOf(t) !== -1;
      if (ok) { el.removeAttribute('data-ktm-hide'); if (el.classList.contains('ktm-person')) shown++; }
      else el.setAttribute('data-ktm-hide', '');
    });
    document.querySelectorAll('[data-ktm-filter]').forEach(function (g) {
      var any = g.querySelector('[data-text]:not([data-ktm-hide])');
      g.toggleAttribute('data-ktm-hide', !any && !!t);
    });
    if (out) out.textContent = t ? (shown + ' — wyniki filtrowania') : '';
  }
  var tm; q.addEventListener('input', function () { clearTimeout(tm); tm = setTimeout(run, 150); });
})();
</script>
<?php }
