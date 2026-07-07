<?php
/**
 * includes/print_templates.php
 * Uniwersalny silnik wzorów wydruków (certyfikaty, dyplomy, podziękowania, pisma, własne).
 *
 * Wzór = "koperta" układu: tło graficzne, nagłówek organizacji, tytuł, treść HTML
 * ze zmiennymi {…} oraz sloty (podpis / pieczęć / QR). Renderowanie współdzielone
 * między podgląd (print/render.php) a faktyczny wydruk (np. certificates/print.php).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

/* ── Migracja ─────────────────────────────────────────────────────────────── */
function pt_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    db()->exec("CREATE TABLE IF NOT EXISTS print_templates (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        name             TEXT    NOT NULL,
        category         TEXT    NOT NULL DEFAULT 'zaswiadczenie',
        description      TEXT    NULL,
        body             TEXT    NOT NULL DEFAULT '',
        options          TEXT    NULL,                 -- JSON: układ, sloty, tło
        background_image TEXT    NULL,                 -- ścieżka względna w uploads/
        is_active        INTEGER NOT NULL DEFAULT 1,
        is_default       INTEGER NOT NULL DEFAULT 0,   -- domyślny w obrębie kategorii
        created_by       INTEGER NULL,
        created_at       DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
        updated_at       DATETIME NOT NULL DEFAULT (datetime('now','localtime'))
    )");

    // Powiązanie wniosku o zaświadczenie z wybranym wzorem wydruku.
    try { db()->exec("ALTER TABLE certificate_requests ADD COLUMN print_template_id INTEGER"); } catch (\Throwable $e) {}
}

/* ── Kategorie ────────────────────────────────────────────────────────────── */
function pt_categories(): array {
    return [
        'zaswiadczenie' => 'Zaświadczenie',
        'dyplom'        => 'Dyplom',
        'podziekowanie' => 'Podziękowanie',
        'pismo'         => 'Pismo',
        'wlasne'        => 'Własne',
    ];
}

function pt_category_label(string $cat): string {
    return pt_categories()[$cat] ?? $cat;
}

/* ── Domyślne opcje układu ────────────────────────────────────────────────── */
function pt_default_options(): array {
    return [
        'orientation'     => 'portrait',   // portrait | landscape
        'show_org_header' => true,
        'show_title'      => true,
        'title_text'      => '',            // pusty → użyj nazwy wzoru
        'signature_slot'  => true,
        'signature_label' => 'Podpis osoby upoważnionej',
        'stamp_slot'      => false,
        'qr_slot'         => false,
        'accent'          => '#1e3a5f',
        'font'            => 'montserrat',     // montserrat | arial
        'title_style'     => 'classic_line',   // classic_line | banner | minimal | plain
    ];
}

/** Stos czcionek CSS dla wybranej opcji. */
function pt_font_stack(string $font): string {
    return $font === 'arial'
        ? "Arial,'Helvetica Neue',Helvetica,sans-serif"
        : "'Montserrat','Segoe UI',Arial,sans-serif";
}

function pt_options(array $tpl): array {
    $o = pt_default_options();
    if (!empty($tpl['options'])) {
        $dec = json_decode($tpl['options'], true);
        if (is_array($dec)) $o = array_merge($o, $dec);
    }
    return $o;
}

/* ── Lista zmiennych (pogrupowana) ────────────────────────────────────────── */
function pt_variables(): array {
    return [
        'Treść' => [
            '{tresc_dokumentu}' => 'Właściwa treść (np. wygenerowana treść zaświadczenia)',
        ],
        'Organizacja' => [
            '{org_nazwa}'    => 'Pełna nazwa organizacji',
            '{org_adres}'    => 'Adres siedziby',
            '{org_miasto}'   => 'Miasto siedziby',
            '{org_nip}'      => 'NIP',
            '{org_krs}'      => 'KRS',
            '{org_regon}'    => 'REGON',
            '{data_dzisiaj}' => 'Dzisiejsza data (dd.mm.rrrr)',
        ],
        'Osoba' => [
            '{imie_nazwisko}' => 'Imię i nazwisko',
            '{pesel}'         => 'PESEL',
            '{adres}'         => 'Adres zamieszkania',
            '{email}'         => 'E-mail',
            '{telefon}'       => 'Telefon',
        ],
        'Dokument' => [
            '{numer_dokumentu}'   => 'Numer dokumentu / zaświadczenia',
            '{data_wydania}'      => 'Data wydania (dd.mm.rrrr)',
            '{cel}'               => 'Cel wydania',
            '{wystawiajacy}'      => 'Osoba wystawiająca',
            '{kod_weryfikacyjny}' => 'Kod do weryfikacji online',
            '{url_weryfikacji}'   => 'Adres strony weryfikacji',
        ],
        'Umowa' => [
            '{numer_umowy}'   => 'Numer umowy/porozumienia',
            '{data_zawarcia}' => 'Data zawarcia',
            '{data_od}'       => 'Data rozpoczęcia',
            '{data_do}'       => 'Data zakończenia',
            '{przedmiot}'     => 'Przedmiot / zakres',
            '{miejsce}'       => 'Miejsce świadczenia',
        ],
    ];
}

/* ── Budowa mapy zmienna→wartość ──────────────────────────────────────────────
 * $ctx: ['row' => wiersz_umowy, 'req' => wniosek_certyfikatu, 'type' => typ, 'sample' => bool]
 */
function pt_build_map(array $ctx = []): array {
    $row    = $ctx['row'] ?? [];
    $req    = $ctx['req'] ?? [];
    $sample = !empty($ctx['sample']);
    $fmt    = fn(?string $d) => $d ? date('d.m.Y', strtotime($d)) : '';

    $org = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');

    // Treść dokumentu — z certyfikatu (akapity) lub przykładowa.
    $tresc = '';
    if (!empty($req['certificate_content'])) {
        $tresc = pt_text_to_paragraphs($req['certificate_content']);
    } elseif ($sample) {
        $tresc = '<p>Tutaj pojawi się właściwa treść dokumentu. Zmienną <code>{tresc_dokumentu}'
               . '</code> zastąpi tekst wygenerowany przy wydawaniu (np. zaświadczenia).</p>';
    }

    $imie = $row['imie_nazwisko'] ?? ($sample ? 'Jan Kowalski' : '');

    // Kod / URL weryfikacji (publiczna weryfikacja zaświadczenia).
    $verify_code = ''; $verify_url = '';
    if (!empty($req['id']) && function_exists('cert_ensure_verify_code')) {
        $verify_code = cert_ensure_verify_code((int)$req['id']);
        $verify_url  = certificate_verify_url($verify_code);
    } elseif ($sample) {
        $verify_code = 'A1B2C3D4E5';
        $verify_url  = rtrim(APP_URL, '/') . '/weryfikuj/?kod=A1B2C3D4E5';
    }

    $map = [
        '{tresc_dokumentu}' => $tresc,

        '{org_nazwa}'    => $org,
        '{org_adres}'    => org_setting('org_adres')       ?: '',
        '{org_miasto}'   => org_setting('org_miejscowosc') ?: '',
        '{org_nip}'      => org_setting('org_nip')         ?: '',
        '{org_krs}'      => org_setting('org_krs')         ?: '',
        '{org_regon}'    => org_setting('org_regon')       ?: '',
        '{data_dzisiaj}' => date('d.m.Y'),

        '{imie_nazwisko}' => $imie,
        '{pesel}'         => $row['pesel']   ?? '',
        '{adres}'         => $row['adres']   ?? '',
        '{email}'         => $row['email']   ?? ($req['requester_email'] ?? ''),
        '{telefon}'       => $row['telefon'] ?? '',

        '{numer_dokumentu}' => $req['cert_number'] ?? ($sample ? 'ZAWOL/0001/' . date('Y') : ''),
        '{data_wydania}'    => !empty($req['issued_at']) ? $fmt($req['issued_at']) : date('d.m.Y'),
        '{cel}'             => $req['cel'] ?? ($sample ? 'okazania w miejscu pracy' : ''),
        '{wystawiajacy}'    => $req['issued_by_name'] ?? '',
        '{kod_weryfikacyjny}' => $verify_code,
        '{url_weryfikacji}'   => $verify_url,

        '{numer_umowy}'   => $row['numer_umowy']     ?? '',
        '{data_zawarcia}' => $fmt($row['data_zawarcia']    ?? null),
        '{data_od}'       => $fmt($row['data_rozpoczecia'] ?? ($row['data_od'] ?? null)),
        '{data_do}'       => $fmt($row['data_zakonczenia'] ?? ($row['data_do'] ?? null)),
        '{przedmiot}'     => $row['przedmiot_porozumienia'] ?? ($row['przedmiot_zlecenia'] ?? ($row['zakres_dzialan'] ?? '')),
        '{miejsce}'       => $row['miejsce_wolontariatu'] ?? ($row['miejsce_wykonania'] ?? ($row['miejsce'] ?? '')),
    ];

    return $map;
}

/** Zamienia tekst (akapity rozdzielone pustą linią) na bezpieczny HTML. */
function pt_text_to_paragraphs(string $raw): string {
    $out = '';
    foreach (preg_split('/\n{2,}/', trim($raw)) as $p) {
        $p = trim($p);
        if ($p !== '') $out .= '<p>' . nl2br(htmlspecialchars($p, ENT_QUOTES)) . "</p>\n";
    }
    return $out;
}

/**
 * Renderuje treść wzoru — podstawia zmienne.
 * {tresc_dokumentu} traktujemy jako gotowy HTML (nie escapujemy), resztę escapujemy.
 */
function pt_render(string $body, array $map): string {
    $tresc = $map['{tresc_dokumentu}'] ?? '';
    unset($map['{tresc_dokumentu}']);
    $body = strtr($body, array_map('htmlspecialchars', $map));
    return str_replace('{tresc_dokumentu}', $tresc, $body);
}

/* ── Dostęp do bazy ───────────────────────────────────────────────────────── */
function pt_get(int $id): ?array {
    pt_migrate();
    return db_one("SELECT * FROM print_templates WHERE id=?", [$id]);
}

function pt_list(string $category = '', bool $only_active = false): array {
    pt_migrate();
    $where = []; $args = [];
    if ($category !== '') { $where[] = 'category=?'; $args[] = $category; }
    if ($only_active)     { $where[] = 'is_active=1'; }
    $sql = "SELECT * FROM print_templates";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY is_default DESC, name';
    return db_all($sql, $args);
}

function pt_default_for(string $category): ?array {
    pt_migrate();
    return db_one(
        "SELECT * FROM print_templates WHERE category=? AND is_active=1 ORDER BY is_default DESC, id LIMIT 1",
        [$category]
    );
}

/** Ustawia wzór jako domyślny w kategorii (zeruje pozostałe). */
function pt_set_default(int $id): void {
    $tpl = pt_get($id);
    if (!$tpl) return;
    db()->prepare("UPDATE print_templates SET is_default=0 WHERE category=?")->execute([$tpl['category']]);
    db()->prepare("UPDATE print_templates SET is_default=1, updated_at=datetime('now','localtime') WHERE id=?")->execute([$id]);
}

/* ── Przycisk „Drukuj dokument" (dropdown aktywnych wzorów) ─────────────────── *
 * $src_params — parametry źródła danych dla print/render.php, np.
 *   'contract_id=5&type=zlecenie'  lub  'cert_id=12'.
 * Wzory pogrupowane po kategorii. Zwraca '' gdy brak aktywnych wzorów.
 */
function print_template_dropdown_html(string $src_params, array $opts = []): string {
    pt_migrate();
    $tpls = pt_list('', true);
    if (!$tpls) return '';

    $app       = defined('APP_URL') ? APP_URL : '';
    $btn_class = $opts['btn_class'] ?? 'btn btn-sm btn-outline-secondary';
    $label     = $opts['label']     ?? 'Dokumenty';
    $menu_end  = ($opts['menu_end'] ?? true) ? 'dropdown-menu-end' : '';
    $fragment  = !empty($opts['fragment']);   // zwróć tylko <li>… do wspólnego menu „Wydruki"

    // Grupuj po kategorii (zachowaj kolejność kategorii z pt_categories).
    $by_cat = [];
    foreach ($tpls as $t) $by_cat[$t['category']][] = $t;
    $cat_order = array_keys(pt_categories());

    // Wewnętrzna treść menu (pozycje <li>) — wspólna dla trybu samodzielnego i fragmentu.
    ob_start(); ?>
        <li><h6 class="dropdown-header"><i class="bi bi-printer me-1"></i>Drukuj dokument ze wzoru</h6></li>
        <?php $first = true;
        foreach ($cat_order as $cat):
          if (empty($by_cat[$cat])) continue;
          if (!$first): ?><li><hr class="dropdown-divider"></li><?php endif; $first = false; ?>
          <li><h6 class="dropdown-header py-1" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.04em"><?= h(pt_category_label($cat)) ?></h6></li>
          <?php foreach ($by_cat[$cat] as $t): ?>
          <li>
            <a class="dropdown-item d-flex align-items-center" target="_blank"
               href="<?= h($app) ?>/print/render.php?template_id=<?= (int)$t['id'] ?>&amp;<?= h($src_params) ?>">
              <i class="bi bi-printer me-2 text-danger"></i>
              <span class="flex-grow-1"><?= h($t['name']) ?><?php if (!empty($t['is_default'])): ?>
                <span class="badge bg-light text-secondary border ms-1" style="font-weight:600">domyślny</span><?php endif; ?></span>
            </a>
          </li>
          <?php endforeach;
        endforeach; ?>
        <?php if (function_exists('is_admin') && is_admin()): ?>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item small text-muted" href="<?= h($app) ?>/admin/print_templates.php">
          <i class="bi bi-gear me-2"></i>Zarządzaj wzorami…</a></li>
        <?php endif; ?>
    <?php
    $items = ob_get_clean();
    if ($fragment) return $items;

    ob_start(); ?>
    <div class="dropdown d-inline-block">
      <button class="<?= h($btn_class) ?> dropdown-toggle" type="button"
              data-bs-toggle="dropdown" aria-expanded="false" title="Drukuj dokument ze wzoru">
        <i class="bi bi-file-earmark-text"></i> <span><?= h($label) ?></span>
      </button>
      <ul class="dropdown-menu <?= $menu_end ?>" style="min-width:260px"><?= $items ?></ul>
    </div>
    <?php
    return ob_get_clean();
}

/* ── Upload tła ───────────────────────────────────────────────────────────── */
function pt_handle_bg_upload(string $field): ?string {
    if (empty($_FILES[$field]['tmp_name'])) return null;
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) return null;

    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'svg'], true)) return null;
    if ($f['size'] > 8 * 1024 * 1024) return null;

    $dir = UPLOAD_DIR . 'print_templates/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . $name)) return null;

    return 'print_templates/' . $name;
}

/** Zwraca [base64, mime] tła lub [null, null]. */
function pt_bg_data(?string $rel): array {
    if (!$rel) return [null, null];
    $path = UPLOAD_DIR . $rel;
    if (!is_file($path) || filesize($path) > 8_000_000) return [null, null];
    $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = $ext === 'svg' ? 'image/svg+xml' : ($ext === 'png' ? 'image/png' : 'image/jpeg');
    return [base64_encode(file_get_contents($path)), $mime];
}

/* ── Konwersja DOCX → HTML (import) ───────────────────────────────────────── */
function pt_docx_to_html(string $path): string {
    if (!file_exists($path)) return '';
    if (!class_exists('\PhpOffice\PhpWord\IOFactory')) {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (is_file($autoload)) require_once $autoload;
    }
    try {
        $doc = \PhpOffice\PhpWord\IOFactory::load($path, 'Word2007');
    } catch (\Throwable $e) {
        return '';
    }

    $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
    $runText = function ($container) use ($esc) {
        $out = '';
        foreach ($container->getElements() as $child) {
            $n = substr(get_class($child), strrpos(get_class($child), '\\') + 1);
            if ($n === 'Text') {
                $t = $esc($child->getText() ?? '');
                if ($t === '') continue;
                $font = $child->getFontStyle();
                if (is_string($font)) $font = \PhpOffice\PhpWord\Style::getStyle($font);
                if ($font instanceof \PhpOffice\PhpWord\Style\Font) {
                    if ($font->isBold())   $t = "<strong>$t</strong>";
                    if ($font->isItalic()) $t = "<em>$t</em>";
                    $u = $font->getUnderline();
                    if ($u && $u !== 'none') $t = "<u>$t</u>";
                }
                $out .= $t;
            } elseif ($n === 'TextBreak') {
                $out .= '<br>';
            }
        }
        return $out;
    };

    $html = ''; $li = '';
    $flush = function () use (&$li, &$html) { if ($li !== '') { $html .= "<ul>$li</ul>\n"; $li = ''; } };

    foreach ($doc->getSections() as $section) {
        foreach ($section->getElements() as $el) {
            $n = substr(get_class($el), strrpos(get_class($el), '\\') + 1);
            if ($n === 'TextBreak') { $flush(); $html .= "<p>&nbsp;</p>\n"; continue; }
            if ($n === 'ListItem')    { $li .= '<li>' . $esc($el->getText() ?? '') . "</li>\n"; continue; }
            if ($n === 'ListItemRun') { $li .= '<li>' . $runText($el) . "</li>\n"; continue; }
            $flush();
            if ($n === 'Title') {
                $d = max(1, min(3, (int)($el->getDepth() ?: 1)));
                $v = $el->getText();
                $inner = ($v instanceof \PhpOffice\PhpWord\Element\TextRun) ? $runText($v) : $esc($v);
                if ($inner) $html .= "<h$d>$inner</h$d>\n";
            } elseif ($n === 'TextRun') {
                $inner = $runText($el);
                $html .= $inner ? "<p>$inner</p>\n" : "<p>&nbsp;</p>\n";
            } elseif ($n === 'Text') {
                $t = $esc($el->getText() ?? '');
                if ($t !== '') $html .= "<p>$t</p>\n";
            }
        }
    }
    $flush();
    return trim($html);
}

/* ── Render dokumentu (wspólny dla podglądu i wydruku) ─────────────────────────
 * Zwraca HTML strony A4 (.pt-page) z tłem, nagłówkiem, tytułem, treścią, slotami.
 */
function pt_document_html(array $tpl, array $map, array $over = []): string {
    $o = array_merge(pt_options($tpl), $over);

    [$bg_b64, $bg_mime] = pt_bg_data($tpl['background_image'] ?? null);
    $accent = preg_match('/^#[0-9a-fA-F]{6}$/', $o['accent'] ?? '') ? $o['accent'] : '#1e3a5f';

    $org      = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
    $org_ad   = org_setting('org_adres')       ?: '';
    $org_nip  = org_setting('org_nip')         ?: '';
    $org_krs  = org_setting('org_krs')         ?: '';
    $org_city = org_setting('org_miejscowosc') ?: '';

    // Logo org (base64)
    $logo_b64 = ''; $logo_mime = 'image/png';
    $logo_f   = org_setting('org_logo');
    if ($logo_f) {
        $lp = dirname(__DIR__) . '/assets/logo/' . basename($logo_f);
        if (is_file($lp) && filesize($lp) < 500_000) {
            $logo_b64  = base64_encode(file_get_contents($lp));
            $logo_mime = str_ends_with(strtolower($logo_f), '.svg') ? 'image/svg+xml'
                       : (str_ends_with(strtolower($logo_f), '.jpg') ? 'image/jpeg' : 'image/png');
        }
    }

    $title = trim((string)($o['title_text'] ?? '')) ?: $tpl['name'];
    $body  = pt_render($tpl['body'] ?? '', $map);
    $date  = $map['{data_wydania}'] ?? date('d.m.Y');

    ob_start();
    ?>
    <div class="pt-page" style="<?= $bg_b64 ? 'background-image:url(data:' . h($bg_mime) . ';base64,' . $bg_b64 . ');background-size:cover;background-position:center;' : '' ?>">
      <div class="pt-inner">

        <?php if (!empty($o['show_org_header'])): ?>
        <div class="pt-org-header" style="border-color:<?= h($accent) ?>">
          <div class="pt-org-left">
            <?php if ($logo_b64): ?>
            <img src="data:<?= h($logo_mime) ?>;base64,<?= $logo_b64 ?>" alt="" class="pt-org-logo">
            <?php endif; ?>
            <div>
              <div class="pt-org-name"><?= h($org) ?></div>
              <?php if ($org_ad || $org_nip): ?>
              <div class="pt-org-meta">
                <?= h($org_ad) ?>
                <?php if ($org_nip): ?><br>NIP: <?= h($org_nip) ?><?php if ($org_krs): ?> · KRS: <?= h($org_krs) ?><?php endif; ?><?php endif; ?>
              </div>
              <?php endif; ?>
            </div>
          </div>
          <div class="pt-org-ref"><?= h($org_city ?: '') ?><?= $org_city ? ', ' : '' ?><?= h($date) ?></div>
        </div>
        <?php endif; ?>

        <?php if (!empty($o['show_title'])):
          $tstyle = $o['title_style'] ?? 'classic_line';
        ?>
          <?php if ($tstyle === 'banner'): ?>
          <div class="pt-title pt-title-banner" style="background:<?= h($accent) ?>"><?= h($title) ?></div>
          <?php elseif ($tstyle === 'minimal'): ?>
          <div class="pt-title pt-title-minimal" style="color:#000"><?= h($title) ?></div>
          <?php elseif ($tstyle === 'plain'): ?>
          <div class="pt-title pt-title-plain" style="color:#000"><?= h($title) ?></div>
          <?php else: /* classic_line — wyśrodkowany + pełna linia */ ?>
          <div class="pt-title" style="color:#000"><?= h($title) ?></div>
          <div class="pt-title-fullrule" style="background:<?= h($accent) ?>"></div>
          <?php endif; ?>
        <?php endif; ?>

        <div class="pt-body"><?= $body ?></div>

        <?php if (!empty($o['signature_slot']) || !empty($o['stamp_slot']) || !empty($o['qr_slot'])): ?>
        <div class="pt-slots">
          <?php if (!empty($o['qr_slot'])):
              $qr_data = $map['{url_weryfikacji}'] ?? '';
              if ($qr_data === '') $qr_data = ($map['{numer_dokumentu}'] ?? '') ?: $org;
              $qr_url  = 'https://api.qrserver.com/v1/create-qr-code/?size=110x110&data=' . urlencode($qr_data);
          ?>
          <div class="pt-slot pt-slot-qr">
            <img src="<?= h($qr_url) ?>" alt="QR" style="width:84px;height:84px">
            <div class="pt-slot-cap">Weryfikacja<?= !empty($map['{kod_weryfikacyjny}']) ? '<br>' . h($map['{kod_weryfikacyjny}']) : '' ?></div>
          </div>
          <?php endif; ?>
          <?php if (!empty($o['stamp_slot'])): ?>
          <div class="pt-slot pt-slot-stamp">
            <div class="pt-stamp-box">miejsce na pieczęć</div>
          </div>
          <?php endif; ?>
          <?php if (!empty($o['signature_slot'])): ?>
          <div class="pt-slot pt-slot-sign">
            <div class="pt-sign-line"></div>
            <div class="pt-slot-cap"><?= h($o['signature_label'] ?? 'Podpis') ?></div>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

      </div>
    </div>
    <?php
    return ob_get_clean();
}

/** Zwraca <style> dla strony wydruku (orientacja sterowana parametrem). */
function pt_document_css(string $orientation = 'portrait', string $font = 'montserrat'): string {
    $landscape = $orientation === 'landscape';
    $w = $landscape ? '297mm' : '210mm';
    $h = $landscape ? '210mm' : '297mm';
    $font_stack = pt_font_stack($font);
    return "
    *,*::before,*::after{box-sizing:border-box}
    html{font-size:11pt}
    body{font-family:{$font_stack};color:#111;margin:0;padding:0;background:#fff}
    @page{size:" . ($landscape ? 'A4 landscape' : 'A4') . ";margin:0}
    .pt-page{position:relative;width:{$w};min-height:{$h};background:#fff;overflow:hidden}
    .pt-inner{position:relative;z-index:2;padding:18mm 18mm 16mm;min-height:{$h};display:flex;flex-direction:column}
    .pt-org-header{display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;border-bottom:2px solid #1e3a5f;padding-bottom:.6rem;margin-bottom:.9rem}
    .pt-org-left{display:flex;align-items:center;gap:.8rem}
    .pt-org-logo{height:42px;width:auto;object-fit:contain;flex-shrink:0}
    .pt-org-name{font-size:.92rem;font-weight:900;text-transform:uppercase;letter-spacing:.04em;line-height:1.2}
    .pt-org-meta{font-size:.74rem;color:#444;margin-top:.12rem;line-height:1.4}
    .pt-org-ref{text-align:right;flex-shrink:0;font-size:.78rem;color:#555}
    .pt-title{text-align:center;font-size:1.5rem;font-weight:900;text-transform:uppercase;letter-spacing:.1em;margin:1.2rem 0 .35rem}
    .pt-title-rule{width:90px;height:3px;margin:0 auto 1.2rem;border-radius:2px}
    .pt-title-fullrule{width:100%;height:2px;margin:.1rem 0 1.2rem;border-radius:1px}
    .pt-title-plain{margin:1.2rem 0 1.2rem}
    .pt-title-minimal{text-transform:none;font-weight:600;letter-spacing:.02em;font-size:1.9rem;margin:1.6rem 0 1.4rem}
    .pt-title-banner{color:#fff;padding:.55rem 1rem;border-radius:4px;font-size:1.4rem;font-weight:800;margin:1.2rem 0 1.3rem}
    .pt-body{flex:1;font-size:1rem}
    .pt-body p{line-height:1.7;margin-bottom:.7rem;text-align:justify}
    .pt-body h1,.pt-body h2,.pt-body h3{margin:1rem 0 .4rem}
    .pt-body ul,.pt-body ol{margin-bottom:.7rem;padding-left:1.4rem;line-height:1.6}
    .pt-body table{width:100%;border-collapse:collapse;margin-bottom:.8rem;font-size:.92rem}
    .pt-body td,.pt-body th{border:1px solid #aaa;padding:.35rem .55rem}
    .pt-slots{display:flex;align-items:flex-end;gap:2.5rem;margin-top:2.5rem;justify-content:flex-end}
    .pt-slot{text-align:center}
    .pt-slot-cap{font-size:.78rem;color:#333;margin-top:.25rem}
    .pt-sign-line{width:230px;border-bottom:1px solid #000;height:42px}
    .pt-stamp-box{width:120px;height:90px;border:1px dashed #bbb;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:.7rem;color:#aaa;text-align:center}
    .pt-slot-sign{margin-left:auto}
    ";
}
