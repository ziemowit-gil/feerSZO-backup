<?php
/**
 * includes/envelopes.php
 * Silnik wzorów kopert (osobny od wzorów dokumentów print_templates).
 *
 * Wzór koperty = format (DL/C6/C5/C4) + układ: blok nadawcy (dane organizacji,
 * automatycznie), blok adresata (z systemu/umowy lub wpisany ręcznie przy druku),
 * opcjonalne logo i miejsce na znaczek. Render współdzielony: podgląd w edytorze
 * (JS), pełny podgląd i wydruk (print/envelope.php).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

/* ── Migracja ─────────────────────────────────────────────────────────────── */
function env_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    db()->exec("CREATE TABLE IF NOT EXISTS envelope_templates (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT    NOT NULL,
        format      TEXT    NOT NULL DEFAULT 'DL',   -- DL|C6|C5|C4
        options     TEXT    NULL,                    -- JSON: układ, font, sloty
        is_active   INTEGER NOT NULL DEFAULT 1,
        is_default  INTEGER NOT NULL DEFAULT 0,
        created_by  INTEGER NULL,
        created_at  DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
        updated_at  DATETIME NOT NULL DEFAULT (datetime('now','localtime'))
    )");
}

/* ── Formaty kopert (poziome; wymiary w mm: szerokość × wysokość druku) ─────── */
function env_formats(): array {
    return [
        'DL' => ['label' => 'DL (110×220 mm)', 'w' => 220, 'h' => 110],
        'C6' => ['label' => 'C6 (114×162 mm)', 'w' => 162, 'h' => 114],
        'C5' => ['label' => 'C5 (162×229 mm)', 'w' => 229, 'h' => 162],
        'C4' => ['label' => 'C4 (229×324 mm)', 'w' => 324, 'h' => 229],
    ];
}

function env_format(string $code): array {
    $f = env_formats();
    return $f[$code] ?? $f['DL'];
}

/* ── Domyślne opcje układu ────────────────────────────────────────────────── */
function env_default_options(): array {
    return [
        'font'            => 'montserrat',  // montserrat | arial
        'font_size'       => 'normal',      // small | normal | large
        'show_sender'     => true,          // blok nadawcy (organizacja)
        'show_logo'       => false,         // logo organizacji w bloku nadawcy
        'show_stamp_hint' => false,         // ramka „miejsce na znaczek" (góra-prawo)
        'recipient_pos'   => 'standard',    // standard (dół-prawo) | center
        'sender_text'     => '',            // nadpisanie nadawcy; pusty = z danych organizacji
    ];
}

function env_options(array $tpl): array {
    $o = env_default_options();
    if (!empty($tpl['options'])) {
        $dec = json_decode($tpl['options'], true);
        if (is_array($dec)) $o = array_merge($o, $dec);
    }
    return $o;
}

/** Stos czcionek CSS (spójny z dokumentami). */
function env_font_stack(string $font): string {
    return $font === 'arial'
        ? "Arial,'Helvetica Neue',Helvetica,sans-serif"
        : "'Montserrat','Segoe UI',Arial,sans-serif";
}

/** Rozmiar czcionki adresata w pt wg opcji. */
function env_recipient_pt(string $size): float {
    return ['small' => 12.0, 'large' => 16.0][$size] ?? 14.0;
}

/* ── Dane nadawcy (organizacja) ───────────────────────────────────────────── */
function env_sender_text(array $o): string {
    $custom = trim((string)($o['sender_text'] ?? ''));
    if ($custom !== '') return $custom;

    $org  = org_setting('org_name')        ?: (defined('ORG_NAME') ? ORG_NAME : '');
    $adr  = org_setting('org_adres')       ?: '';
    $city = org_setting('org_miejscowosc') ?: (org_setting('org_miasto') ?: '');
    return implode("\n", array_filter([$org, $adr, $city], fn($x) => trim((string)$x) !== ''));
}

/** Logo organizacji jako [base64, mime] lub [null,null]. */
function env_logo_data(): array {
    $rel = org_setting('org_logo') ?: '';
    if (!$rel) return [null, null];
    $path = (defined('UPLOAD_DIR') ? UPLOAD_DIR : (dirname(__DIR__) . '/uploads/')) . $rel;
    if (!is_file($path) || filesize($path) > 4_000_000) return [null, null];
    $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = $ext === 'svg' ? 'image/svg+xml' : ($ext === 'png' ? 'image/png' : 'image/jpeg');
    return [base64_encode(file_get_contents($path)), $mime];
}

/* ── Adresat ──────────────────────────────────────────────────────────────── *
 * $ctx['recipient'] = ['name'=>..., 'addr'=>... (wielolinijkowy)] (ręcznie)
 * lub z $ctx['row'] (umowa/osoba: imie_nazwisko + addr_* / adres). 'sample' → przykładowe dane.
 */

/** Wielolinijkowy adres z wiersza: preferuje pola strukturalne (addr_*), fallback: legacy 'adres'. */
function env_addr_from_row(array $row): string {
    $street = trim((string)($row['addr_street']  ?? ''));
    $house  = trim((string)($row['addr_house']   ?? ''));
    $flat   = trim((string)($row['addr_flat']    ?? ''));
    $postal = trim((string)($row['addr_postal']  ?? ''));
    $city   = trim((string)($row['addr_city']    ?? ''));
    $country= trim((string)($row['addr_country'] ?? ''));

    $line1 = trim($street . ($house !== '' ? ' ' . $house : '') . ($flat !== '' ? '/' . $flat : ''));
    $line2 = trim(($postal !== '' ? $postal . ' ' : '') . $city);

    $lines = array_filter([$line1, $line2], fn($x) => $x !== '');
    if ($country !== '' && strtoupper($country) !== 'PL') $lines[] = $country;

    if (!$lines) return trim((string)($row['adres'] ?? ''));   // legacy
    return implode("\n", $lines);
}

function env_recipient_lines(array $ctx): array {
    $r    = $ctx['recipient'] ?? [];
    $row  = $ctx['row'] ?? [];
    $name = trim((string)($r['name'] ?? ($row['imie_nazwisko'] ?? '')));
    $addr = (string)($r['addr'] ?? '');
    if ($addr === '' && $row) $addr = env_addr_from_row($row);

    if ($name === '' && $addr === '' && !empty($ctx['sample'])) {
        $name = 'Jan Kowalski';
        $addr = "ul. Przykładowa 12/3\n00-001 Warszawa";
    }

    $lines = [];
    if ($name !== '') $lines[] = $name;
    foreach (preg_split('/\r\n|\r|\n/', $addr) as $l) {
        $l = trim($l);
        if ($l !== '') $lines[] = $l;
    }
    return $lines;
}

/* ── CSS koperty (mm; wspólne dla podglądu i wydruku) ──────────────────────── */
function env_css(string $format, string $font, string $font_size): string {
    $f  = env_format($format);
    $w  = $f['w']; $h = $f['h'];
    $fs = env_font_stack($font);
    $rp = env_recipient_pt($font_size);
    return "
    *,*::before,*::after{box-sizing:border-box}
    body{font-family:{$fs};color:#000;margin:0;padding:0;background:#fff}
    @page{size:{$w}mm {$h}mm;margin:0}
    .env{position:relative;width:{$w}mm;height:{$h}mm;background:#fff;overflow:hidden}
    .env-sender{position:absolute;top:10mm;left:12mm;max-width:" . max(70, (int)($w * 0.45)) . "mm;font-size:9.5pt;line-height:1.4;white-space:pre-line}
    .env-sender-logo{height:12mm;width:auto;object-fit:contain;margin-bottom:2mm;display:block}
    .env-stamp{position:absolute;top:8mm;right:10mm;width:24mm;height:24mm;border:1px dashed #c0c0c0;border-radius:1.5mm;display:flex;align-items:center;justify-content:center;text-align:center;font-size:7pt;color:#aaa}
    .env-recipient{position:absolute;font-size:{$rp}pt;line-height:1.5;white-space:pre-line}
    .env-recipient.pos-standard{left:" . (int)($w * 0.46) . "mm;top:" . (int)($h * 0.52) . "mm;right:12mm}
    .env-recipient.pos-center{left:12mm;right:12mm;top:" . (int)($h * 0.46) . "mm;text-align:center}
    .env-recipient .r-name{font-weight:700}
    ";
}

/* ── Render HTML koperty (.env) ───────────────────────────────────────────── */
function env_render_html(array $tpl, array $ctx = [], array $over = []): string {
    $o = array_merge(env_options($tpl), $over);

    $sender = env_sender_text($o);
    $rec    = env_recipient_lines($ctx);
    [$logo_b64, $logo_mime] = !empty($o['show_logo']) ? env_logo_data() : [null, null];

    ob_start(); ?>
    <div class="env">
      <?php if (!empty($o['show_sender']) && ($sender !== '' || $logo_b64)): ?>
      <div class="env-sender"><?php
        if ($logo_b64): ?><img class="env-sender-logo" src="data:<?= h($logo_mime) ?>;base64,<?= $logo_b64 ?>" alt=""><?php endif;
        echo h($sender);
      ?></div>
      <?php endif; ?>

      <?php if (!empty($o['show_stamp_hint'])): ?>
      <div class="env-stamp">miejsce<br>na<br>znaczek</div>
      <?php endif; ?>

      <div class="env-recipient pos-<?= ($o['recipient_pos'] ?? 'standard') === 'center' ? 'center' : 'standard' ?>">
        <?php foreach ($rec as $i => $line): ?>
        <div class="<?= $i === 0 ? 'r-name' : '' ?>"><?= h($line) ?></div>
        <?php endforeach; ?>
        <?php if (!$rec): ?><div class="text-muted">(brak danych adresata)</div><?php endif; ?>
      </div>
    </div>
    <?php
    return ob_get_clean();
}

/* ── Dostęp do bazy ───────────────────────────────────────────────────────── */
function env_get(int $id): ?array {
    env_migrate();
    return db_one("SELECT * FROM envelope_templates WHERE id=?", [$id]);
}

function env_list(bool $only_active = false): array {
    env_migrate();
    $sql = "SELECT * FROM envelope_templates";
    if ($only_active) $sql .= " WHERE is_active=1";
    $sql .= " ORDER BY is_default DESC, name";
    return db_all($sql);
}

function env_default(): ?array {
    env_migrate();
    return db_one("SELECT * FROM envelope_templates WHERE is_active=1 ORDER BY is_default DESC, id LIMIT 1");
}

/** Ustawia wzór jako domyślny (zeruje pozostałe). */
function env_set_default(int $id): void {
    if (!env_get($id)) return;
    db()->exec("UPDATE envelope_templates SET is_default=0");
    db()->prepare("UPDATE envelope_templates SET is_default=1, updated_at=datetime('now','localtime') WHERE id=?")->execute([$id]);
}

/* ── Przycisk „Generuj kopertę" (dropdown aktywnych wzorów) ─────────────────── *
 * $src_params — parametry źródła adresata dla print/envelope.php, np.
 *   'person_id=5'  lub  'contract_id=5&type=zlecenie'.
 * Zwraca '' gdy brak aktywnych wzorów (przycisk się nie renderuje).
 */
function envelope_dropdown_html(string $src_params, array $opts = []): string {
    $tpls = env_list(true);
    if (!$tpls) return '';

    $app       = defined('APP_URL') ? APP_URL : '';
    $btn_class = $opts['btn_class'] ?? 'btn btn-sm btn-outline-secondary';
    $label     = $opts['label']     ?? 'Koperta';
    $menu_end  = ($opts['menu_end'] ?? true) ? 'dropdown-menu-end' : '';
    $fragment  = !empty($opts['fragment']);   // zwróć tylko <li>… do wspólnego menu „Wydruki"

    // Wewnętrzna treść menu (pozycje <li>) — wspólna dla trybu samodzielnego i fragmentu.
    ob_start(); ?>
        <li><h6 class="dropdown-header"><i class="bi bi-envelope me-1"></i>Generuj kopertę ze wzoru</h6></li>
        <?php foreach ($tpls as $t): ?>
        <li>
          <a class="dropdown-item d-flex align-items-center" target="_blank"
             href="<?= h($app) ?>/print/envelope.php?template_id=<?= (int)$t['id'] ?>&amp;<?= h($src_params) ?>">
            <i class="bi bi-printer me-2 text-danger"></i>
            <span class="flex-grow-1"><?= h($t['name']) ?><?php if (!empty($t['is_default'])): ?>
              <span class="badge bg-light text-secondary border ms-1" style="font-weight:600">domyślny</span><?php endif; ?></span>
            <span class="text-muted small ms-2"><?= h($t['format']) ?></span>
          </a>
        </li>
        <?php endforeach; ?>
        <?php if (function_exists('is_admin') && is_admin()): ?>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item small text-muted" href="<?= h($app) ?>/admin/envelope_templates.php">
          <i class="bi bi-gear me-2"></i>Zarządzaj wzorami…</a></li>
        <?php endif; ?>
    <?php
    $items = ob_get_clean();
    if ($fragment) return $items;

    ob_start(); ?>
    <div class="dropdown d-inline-block">
      <button class="<?= h($btn_class) ?> dropdown-toggle" type="button"
              data-bs-toggle="dropdown" aria-expanded="false" title="Generuj kopertę ze wzoru">
        <i class="bi bi-envelope"></i> <span class="d-none d-sm-inline"><?= h($label) ?></span>
      </button>
      <ul class="dropdown-menu <?= $menu_end ?>" style="min-width:248px"><?= $items ?></ul>
    </div>
    <?php
    return ob_get_clean();
}

/* ── Wspólne menu „Wydruki" (Koperty + Pisma/Dokumenty) ────────────────────── *
 * Scala dropdown kopert i dropdown wzorów dokumentów w jeden przycisk.
 * $src_params — parametry źródła, np. 'contract_id=5&type=zlecenie'.
 * Zwraca '' gdy nie ma żadnych wzorów (ani kopert, ani dokumentów).
 */
function wydruki_dropdown_html(string $src_params, array $opts = []): string {
    $app       = defined('APP_URL') ? APP_URL : '';
    $btn_class = $opts['btn_class'] ?? 'btn btn-sm btn-outline-secondary';
    $label     = $opts['label']     ?? 'Wydruki';
    $menu_end  = ($opts['menu_end'] ?? true) ? 'dropdown-menu-end' : '';

    // Najpierw koperty, potem pisma/dokumenty (zgodnie z układem nagłówka).
    $env = function_exists('envelope_dropdown_html')
         ? envelope_dropdown_html($src_params, ['fragment' => true]) : '';
    $doc = function_exists('print_template_dropdown_html')
         ? print_template_dropdown_html($src_params, ['fragment' => true]) : '';

    if ($env === '' && $doc === '') return '';

    ob_start(); ?>
    <div class="dropdown d-inline-block">
      <button class="<?= h($btn_class) ?> dropdown-toggle" type="button"
              data-bs-toggle="dropdown" aria-expanded="false" title="Wydruki: koperty i dokumenty">
        <i class="bi bi-printer"></i> <span class="d-none d-sm-inline"><?= h($label) ?></span>
      </button>
      <ul class="dropdown-menu <?= $menu_end ?>" style="min-width:260px">
        <?php if ($env !== ''): ?>
          <?= $env ?>
        <?php endif; ?>
        <?php if ($env !== '' && $doc !== ''): ?>
          <li><hr class="dropdown-divider"></li>
        <?php endif; ?>
        <?php if ($doc !== ''): ?>
          <?= $doc ?>
        <?php endif; ?>
      </ul>
    </div>
    <?php
    return ob_get_clean();
}
