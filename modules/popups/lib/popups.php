<?php
/**
 * modules/popups/lib/popups.php — komunikaty „popup” (okna informacyjne) w Panelu współpracownika.
 *
 * Zastępują zahardkodowane includes/welcome_notice.php i includes/mobywatel_notice.php: treść, odbiorcy (role),
 * miejsce (SZO / Panel / oba), daty od–do i wersja są w tabeli popups, edytowane w modules/popups/index.php.
 * Pokazują się po kolei, każdy raz na przeglądarkę (localStorage, klucz z wersją); podbicie wersji pokazuje go ponownie.
 * Pokazywane TYLKO w Panelu współpracownika (popupsRender('panel') z panel/includes/footer_panel.php); SZO (administratorzy, zarząd)
 * wywołuje popupsRender('szo') wyłącznie dla podglądu w edytorze.
 */

const POPUP_COLORS = [
    'blue'   => ['Niebieski',    '#2563eb', '#4f46e5'],
    'teal'   => ['Morski',       '#0b7285', '#1971c2'],
    'green'  => ['Zielony',      '#0f766e', '#15803d'],
    'orange' => ['Pomarańczowy', '#c2410c', '#ea580c'],
    'red'    => ['Czerwony',     '#b91c1c', '#dc2626'],
    'dark'   => ['Grafitowy',    '#1f2937', '#374151'],
];

function popupsMigrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS popups (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slug TEXT NOT NULL UNIQUE,
        title TEXT NOT NULL,
        body TEXT NOT NULL DEFAULT '',
        icon TEXT NOT NULL DEFAULT 'bi-info-circle-fill',
        color TEXT NOT NULL DEFAULT 'blue',
        shell TEXT NOT NULL DEFAULT 'both',
        roles TEXT NOT NULL DEFAULT '',
        starts_at TEXT, ends_at TEXT,
        is_active INTEGER NOT NULL DEFAULT 1,
        version INTEGER NOT NULL DEFAULT 1,
        btn_label TEXT NOT NULL DEFAULT '', btn_url TEXT NOT NULL DEFAULT '',
        bug_button INTEGER NOT NULL DEFAULT 0,
        legacy_key TEXT NOT NULL DEFAULT '', legacy_version TEXT NOT NULL DEFAULT '',
        sort INTEGER NOT NULL DEFAULT 100,
        created_at TEXT, updated_at TEXT
    )");
    // popupy są dla współpracowników (Panel); SZO to moduł administratorów i zarządu — bez popupów (poza podglądem w edytorze)
    db()->exec("UPDATE popups SET shell='panel' WHERE shell<>'panel'");
    // jednorazowy import dwóch dotychczasowych, zahardkodowanych okien (zachowują „już widziałem” z localStorage)
    if ((int)(db_one("SELECT COUNT(*) c FROM popups")['c'] ?? 0) === 0) {
        $now = date('Y-m-d H:i:s');
        $ins = db()->prepare("INSERT INTO popups (slug,title,body,icon,color,shell,btn_label,btn_url,bug_button,legacy_key,legacy_version,sort,created_at,updated_at)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $ins->execute(['witamy', 'Witamy w nowym systemie!',
            '<p>System został <strong>świeżo wdrożony</strong>. Pracujemy nad tym, aby działał bez zarzutu — ale na początku mogą zdarzyć się drobne usterki.</p>'
            . '<p><strong>Zauważyłeś błąd? Daj nam znać.</strong> Każde zgłoszenie pomaga nam szybciej poprawiać system. Skorzystaj z przycisku „Zgłoś błąd” albo z naszego helpdesku.</p>',
            'bi-rocket-takeoff-fill', 'blue', 'panel', 'Przejdź do helpdesku', '/helpdesk/new.php', 1,
            'feer_welcome_notice_dismissed', '2026-06-go-live', 10, $now, $now]);
        $ins->execute(['mobywatel', 'Niebawem: mObywatel',
            '<p>Pracujemy nad integracją z aplikacją <strong>mObywatel</strong>. Wkrótce udostępnimy w systemie dwie nowe możliwości:</p>'
            . '<ul><li><strong>Weryfikacja tożsamości</strong> — szybkie i bezpieczne potwierdzenie danymi z aplikacji mObywatel.</li>'
            . '<li><strong>eZawieranie umów</strong> — podpisywanie umów w pełni elektronicznie, bez drukowania i skanowania.</li></ul>'
            . '<p>Poinformujemy Cię, gdy funkcje będą gotowe do użycia.</p>',
            'bi-phone-fill', 'teal', 'panel', '', '', 0,
            'feer_mobywatel_notice_dismissed', '2026-07-mobywatel', 20, $now, $now]);
    }
}

/** Dozwolone znaczniki w treści; linki tylko http(s)/ścieżki lokalne, zawsze rel=noopener. */
function popupsSanitize(string $html): string {
    $html = strip_tags($html, '<p><br><strong><b><em><i><u><ul><ol><li><a><h4><h5><small>');
    $html = preg_replace('/<(\/?)(p|br|strong|b|em|i|u|ul|ol|li|h4|h5|small)\b[^>]*>/i', '<$1$2>', $html);
    $html = preg_replace_callback('/<a\b([^>]*)>/i', function ($m) {
        if (!preg_match('/href\s*=\s*(["\'])(.*?)\1/i', $m[1], $h)) return '<a>';
        $u = trim(html_entity_decode($h[2]));
        if (!preg_match('#^(https?://|/|mailto:)#i', $u) || str_starts_with($u, '//')) return '<a>';
        return '<a href="' . htmlspecialchars($u, ENT_QUOTES) . '" rel="noopener">';
    }, $html);
    return $html;
}

function popupsSafeUrl(string $u): string {
    $u = trim($u);
    if ($u === '') return '';
    if (preg_match('#^https?://#i', $u)) return $u;
    return ($u[0] === '/' && !str_starts_with($u, '//')) ? $u : '';
}

/** Okna aktywne dla danej osoby i powłoki ('szo'|'panel'), w kolejności pokazywania. */
function popupsActive(string $shell, ?array $user): array {
    if (!$user) return [];
    popupsMigrate();
    $now  = date('Y-m-d H:i:s');
    $rows = db_all("SELECT * FROM popups WHERE is_active=1 AND shell IN ('both', ?)
                    AND (starts_at IS NULL OR starts_at='' OR starts_at <= ?)
                    AND (ends_at IS NULL OR ends_at='' OR ends_at >= ?) ORDER BY sort, id", [$shell, $now, $now]);
    $role = (string)($user['role'] ?? '');
    return array_values(array_filter($rows, function ($p) use ($role) {
        $r = array_filter(array_map('trim', explode(',', (string)$p['roles'])));
        return !$r || in_array($role, $r, true);
    }));
}

/** Modale + skrypt kolejkujący. Podgląd (edytor): $GLOBALS['POPUPS_PREVIEW'] = id okna → pokaż tylko to, ignorując localStorage. */
function popupsRender(string $shell): void {
    $user = current_user();
    $prev = (int)($GLOBALS['POPUPS_PREVIEW'] ?? 0);
    if ($shell === 'szo' && !$prev) return;
    if ($prev) { popupsMigrate(); $p = db_one("SELECT * FROM popups WHERE id=?", [$prev]); $list = $p ? [$p] : []; }
    else $list = popupsActive($shell, $user);
    if (!$list) return;
    $meta = [];
    foreach ($list as $p) {
        $c   = POPUP_COLORS[$p['color']] ?? POPUP_COLORS['blue'];
        $id  = 'szoPopup' . (int)$p['id'];
        $url = popupsSafeUrl((string)$p['btn_url']);
        if ($url !== '' && $url[0] === '/') $url = APP_URL . $url;
        $icon = preg_match('/^bi-[a-z0-9-]+$/', (string)$p['icon']) ? $p['icon'] : 'bi-info-circle-fill';
        $meta[] = ['id' => $id, 'key' => 'feer_popup_' . $p['slug'], 'ver' => (string)$p['version'],
                   'lk' => (string)$p['legacy_key'], 'lv' => (string)$p['legacy_version']];
        ?>
<div class="modal fade" id="<?= $id ?>" tabindex="-1" aria-labelledby="<?= $id ?>L" aria-describedby="<?= $id ?>B" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg" style="border-radius:14px;overflow:hidden">
      <div class="modal-header border-0 text-white py-3" style="background:linear-gradient(135deg,<?= $c[1] ?> 0%,<?= $c[2] ?> 100%)">
        <h5 class="modal-title fw-semibold d-flex align-items-center" id="<?= $id ?>L" style="font-size:1.05rem">
          <i class="bi <?= h($icon) ?> me-2" aria-hidden="true"></i><?= h($p['title']) ?>
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Zamknij okno informacyjne"></button>
      </div>
      <div class="modal-body px-4 pt-3 pb-2" id="<?= $id ?>B" style="font-size:.92rem;line-height:1.55">
        <?= popupsSanitize((string)$p['body']) ?>
        <div class="form-check mt-3 mb-1">
          <input class="form-check-input" type="checkbox" id="<?= $id ?>X">
          <label class="form-check-label" for="<?= $id ?>X" style="font-size:.82rem;color:#64748b">Nie pokazuj tego okna ponownie</label>
        </div>
      </div>
      <div class="modal-footer border-0 px-4 pb-3 pt-1 d-flex flex-wrap gap-2">
        <?php if ($url !== '' && trim((string)$p['btn_label']) !== ''): ?>
        <a href="<?= h($url) ?>" class="btn btn-sm btn-outline-primary"><?= h($p['btn_label']) ?></a>
        <?php endif; ?>
        <?php if (!empty($p['bug_button'])): ?>
        <button type="button" class="btn btn-sm btn-danger" data-popup-bug="1"><i class="bi bi-bug-fill me-1" aria-hidden="true"></i>Zgłoś błąd</button>
        <?php endif; ?>
        <button type="button" class="btn btn-sm btn-primary ms-auto" data-bs-dismiss="modal">Rozumiem</button>
      </div>
    </div>
  </div>
</div>
<?php } ?>
<script>
(function(){
  if (typeof bootstrap === 'undefined') return;
  var items = <?= json_encode($meta) ?>, force = <?= $prev ? 'true' : 'false' ?>, queue = [];
  function seen(m){ try { return localStorage.getItem(m.key) === m.ver || (m.lk && localStorage.getItem(m.lk) === m.lv); } catch (e) { return false; } }
  function remember(m){ try { localStorage.setItem(m.key, m.ver); } catch (e) {} }
  items.forEach(function(m){ var el = document.getElementById(m.id); if (el && (force || !seen(m))) queue.push([m, el]); });
  var reduce = false; try { reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) {}
  function next(){
    var q = queue.shift(); if (!q) return;
    var m = q[0], el = q[1], cb = document.getElementById(m.id + 'X');
    if (reduce) el.classList.remove('fade');
    el.addEventListener('hide.bs.modal', function(){ if (!force && cb && cb.checked) remember(m); });
    el.addEventListener('hidden.bs.modal', next, {once: true});
    var bug = el.querySelector('[data-popup-bug]');
    if (bug) bug.addEventListener('click', function(){
      if (!force) remember(m);
      var b = document.getElementById('bugReportModal');
      if (b) el.addEventListener('hidden.bs.modal', function(){ try { bootstrap.Modal.getOrCreateInstance(b).show(); } catch (e) {} }, {once: true});
      bootstrap.Modal.getOrCreateInstance(el).hide();
    });
    bootstrap.Modal.getOrCreateInstance(el).show();
  }
  next();
})();
</script>
<?php
}
