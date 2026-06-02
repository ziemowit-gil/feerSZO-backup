<?php
/**
 * Structured address helpers.
 *
 * Adds addr_street / addr_house / addr_flat / addr_postal / addr_city / addr_country
 * columns to all contract tables + shipments (idempotent, try/catch per column).
 *
 * Usage in edit.php:
 *   require_once '.../includes/address.php';
 *   // In $allowed array: array_merge($allowed, address_fields())
 *   // In HTML:           echo address_widget($row);
 *
 * Usage in view.php / list:
 *   echo address_format($row);          // "ul. Kwiatowa 5/12, 00-001 Warszawa"
 *   echo address_format($row, true);    // multiline HTML
 */

// ── Migration ────────────────────────────────────────────────────────────────

function address_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = db();

    $tables = [
        'umowy_wolontariat',
        'umowy_zlecenie',
        'umowy_dzielo',
        'umowy_praca',
        'shipments',
        'persons',
    ];

    $cols = [
        'addr_street'  => "TEXT NOT NULL DEFAULT ''",
        'addr_house'   => "TEXT NOT NULL DEFAULT ''",
        'addr_flat'    => "TEXT NOT NULL DEFAULT ''",
        'addr_postal'  => "TEXT NOT NULL DEFAULT ''",
        'addr_city'    => "TEXT NOT NULL DEFAULT ''",
        'addr_country' => "TEXT NOT NULL DEFAULT 'PL'",
    ];

    foreach ($tables as $tbl) {
        foreach ($cols as $col => $def) {
            try {
                $pdo->exec("ALTER TABLE {$tbl} ADD COLUMN {$col} {$def}");
            } catch (\Throwable $e) {
                // Column already exists — ignore
            }
        }
    }
}

// ── Field list ───────────────────────────────────────────────────────────────

/** Returns array of field names to merge into $allowed in edit forms. */
function address_fields(): array {
    return ['addr_street', 'addr_house', 'addr_flat', 'addr_postal', 'addr_city', 'addr_country'];
}

// ── Extractor ────────────────────────────────────────────────────────────────

/**
 * Extracts structured address values from a DB row array.
 * Falls back to parsing the legacy `adres` text field when structured fields are empty.
 *
 * @param  array  $row  A DB row (any contract table)
 * @return array  Keys: street, house, flat, postal, city, country, legacy_adres
 */
function address_from_row(array $row): array {
    $out = [
        'street'       => $row['addr_street']  ?? '',
        'house'        => $row['addr_house']   ?? '',
        'flat'         => $row['addr_flat']    ?? '',
        'postal'       => $row['addr_postal']  ?? '',
        'city'         => $row['addr_city']    ?? '',
        'country'      => $row['addr_country'] ?? 'PL',
        'legacy_adres' => $row['adres']        ?? '',
    ];

    // If structured fields are all empty but legacy adres exists, try to parse it.
    if (!$out['street'] && !$out['postal'] && !$out['city'] && $out['legacy_adres']) {
        // Common format: "ul. Kwiatowa 5/12, 00-001 Warszawa"
        // or: "ul. Kwiatowa 5 m. 12, 00-001 Warszawa"
        $legacy = $out['legacy_adres'];

        // Postal + city at the end: XX-XXX City
        if (preg_match('/(\d{2}-\d{3})\s+(.+)$/', $legacy, $m)) {
            $out['postal'] = $m[1];
            $out['city']   = trim($m[2]);
            $remainder     = trim(preg_replace('/,?\s*\d{2}-\d{3}\s+.+$/', '', $legacy));
        } else {
            $remainder = $legacy;
        }

        // Street + number: "ul. Kwiatowa 5" or "ul. Kwiatowa 5/12" or "ul. Kwiatowa 5 m. 12"
        if ($remainder) {
            // Flat suffix: m. X or / X
            if (preg_match('/\s+m\.?\s*(\S+)\s*$/i', $remainder, $m2)) {
                $out['flat'] = $m2[1];
                $remainder = trim(preg_replace('/\s+m\.?\s*\S+\s*$/i', '', $remainder));
            }
            // House number (last token): number possibly with /X
            if (preg_match('/^(.*?)\s+([\w\/\-]+)$/', $remainder, $m3)) {
                $maybe_house = $m3[2];
                // If it looks like a house number (starts with digit)
                if (preg_match('/^\d/', $maybe_house)) {
                    // Check for flat separator: 5/12
                    if (strpos($maybe_house, '/') !== false && !$out['flat']) {
                        [$h, $f] = explode('/', $maybe_house, 2);
                        $out['house'] = $h;
                        $out['flat']  = $f;
                    } else {
                        $out['house'] = $maybe_house;
                    }
                    $out['street'] = trim($m3[1]);
                } else {
                    $out['street'] = $remainder;
                }
            } else {
                $out['street'] = $remainder;
            }
        }
    }

    return $out;
}

// ── Formatter ────────────────────────────────────────────────────────────────

/**
 * Returns a formatted address string.
 *
 * @param  array   $row    DB row or address_from_row() result
 * @param  bool    $html   If true, returns safe HTML (escaped, with <br>)
 * @return string
 */
function address_format(array $row, bool $html = false): string {
    // Accept both raw DB rows and address_from_row() results
    $a = isset($row['street']) ? $row : address_from_row($row);

    $line1 = trim($a['street'] . ($a['house'] ? ' ' . $a['house'] : '') . ($a['flat'] ? '/' . $a['flat'] : ''));
    $line2 = trim(($a['postal'] ? $a['postal'] . ' ' : '') . $a['city']);

    // Nothing structured → fall back to legacy
    if (!$line1 && !$line2 && !empty($a['legacy_adres'])) {
        return $html ? h($a['legacy_adres']) : ($a['legacy_adres']);
    }

    $country = ($a['country'] && $a['country'] !== 'PL') ? $a['country'] : '';

    if ($html) {
        $parts = array_filter([$line1, $line2, $country]);
        return implode('<br>', array_map('h', $parts));
    }

    return implode(', ', array_filter([$line1, $line2, $country]));
}

// ── HTML Widget ──────────────────────────────────────────────────────────────

/**
 * Returns an HTML form widget for the structured address fields.
 *
 * @param  array  $row   DB row (or POST-merged data)
 * @param  array  $opts  Options:
 *                         'label'       => string  Section label (default: 'Adres zamieszkania')
 *                         'required'    => bool    Show asterisk on street (default: false)
 *                         'size'        => 'sm'|'' Input size suffix (default: '')
 *                         'show_legacy' => bool    Show read-only legacy field if no structured data (default: true)
 *                         'copy_button' => bool    Show "Kopiuj adres" clipboard button (default: false)
 *                         'widget_id'   => string  HTML id prefix for the widget div (default: auto)
 * @return string  HTML
 */
function address_widget(array $row, array $opts = []): string {
    address_migrate();

    static $_addr_widget_counter = 0;
    $_addr_widget_counter++;

    $label        = $opts['label']        ?? 'Adres zamieszkania';
    $required     = $opts['required']     ?? false;
    $sz           = !empty($opts['size']) ? ' form-control-' . $opts['size'] : '';
    $sz_sel       = !empty($opts['size']) ? ' form-select-'  . $opts['size'] : '';
    $show_legacy  = $opts['show_legacy']  ?? true;
    $copy_button  = $opts['copy_button']  ?? false;
    $wid          = $opts['widget_id']    ?? ('addrWidget' . $_addr_widget_counter);

    $a = address_from_row($row);

    // Show legacy hint only when structured fields are empty and legacy has data
    $has_structured = $a['street'] || $a['postal'] || $a['city'];
    $show_hint      = $show_legacy && !$has_structured && $a['legacy_adres'];

    $req_mark = $required ? ' <span class="text-danger">*</span>' : '';

    $countries = [
        'PL' => 'PL — Polska',
        'DE' => 'DE — Niemcy',
        'GB' => 'GB — Wielka Brytania',
        'UA' => 'UA — Ukraina',
        'FR' => 'FR — Francja',
        'US' => 'US — USA',
        'CZ' => 'CZ — Czechy',
        'SK' => 'SK — Słowacja',
        'LT' => 'LT — Litwa',
        'BY' => 'BY — Białoruś',
    ];

    $country_opts = '';
    foreach ($countries as $code => $label_c) {
        $sel = ($a['country'] === $code || ($a['country'] === '' && $code === 'PL')) ? ' selected' : '';
        $country_opts .= '<option value="' . $code . '"' . $sel . '>' . $label_c . '</option>';
    }

    $street_val  = h($a['street']);
    $house_val   = h($a['house']);
    $flat_val    = h($a['flat']);
    $postal_val  = h($a['postal']);
    $city_val    = h($a['city']);

    ob_start();
?>
<div class="addr-widget" id="<?= h($wid) ?>">
  <?php if ($show_hint): ?>
  <div class="alert alert-info py-2 px-3 mb-2 small d-flex align-items-start gap-2">
    <i class="bi bi-info-circle-fill mt-1 flex-shrink-0"></i>
    <div>
      <strong>Adres archiwalny:</strong> <?= h($a['legacy_adres']) ?><br>
      <span class="text-muted">Wpisz poniżej, aby zaktualizować do nowego formatu.</span>
    </div>
  </div>
  <?php endif; ?>
  <div class="row g-2">
    <div class="col-6 col-sm-7 fgroup">
      <label class="form-label mb-1 small fw-semibold">Ulica<?= $req_mark ?></label>
      <input name="addr_street" class="form-control<?= $sz ?>"
             placeholder="ul. Kwiatowa" value="<?= $street_val ?>"
             <?= $required ? 'required' : '' ?>>
    </div>
    <div class="col-3 fgroup">
      <label class="form-label mb-1 small fw-semibold">Nr domu</label>
      <input name="addr_house" class="form-control<?= $sz ?>"
             placeholder="5" value="<?= $house_val ?>" maxlength="10">
    </div>
    <div class="col-3 fgroup">
      <label class="form-label mb-1 small fw-semibold">Nr lok.</label>
      <input name="addr_flat" class="form-control<?= $sz ?>"
             placeholder="12" value="<?= $flat_val ?>" maxlength="10">
    </div>
    <div class="col-4 col-sm-3 fgroup">
      <label class="form-label mb-1 small fw-semibold">Kod pocztowy</label>
      <input name="addr_postal" class="form-control<?= $sz ?> font-monospace"
             placeholder="00-001" value="<?= $postal_val ?>" maxlength="10"
             pattern="\d{2}-\d{3}" title="Format: XX-XXX">
    </div>
    <div class="col-8 col-sm-5 fgroup">
      <label class="form-label mb-1 small fw-semibold">Miasto</label>
      <input name="addr_city" class="form-control<?= $sz ?>"
             placeholder="Warszawa" value="<?= $city_val ?>">
    </div>
    <div class="col-12 col-sm-4 fgroup">
      <label class="form-label mb-1 small fw-semibold">Kraj</label>
      <select name="addr_country" class="form-select<?= $sz_sel ?>">
        <?= $country_opts ?>
      </select>
    </div>
    <?php if ($copy_button): ?>
    <div class="col-12">
      <button type="button" class="btn btn-sm btn-outline-secondary"
              onclick="_addrWidgetCopy('<?= h($wid) ?>', this)"
              title="Skopiuj pełny adres do schowka">
        <i class="bi bi-clipboard me-1"></i>Kopiuj adres
      </button>
      <span class="text-success small ms-2 d-none" id="<?= h($wid) ?>_copied">
        <i class="bi bi-check-lg me-1"></i>Skopiowano!
      </span>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php if ($copy_button): ?>
<script>
if (typeof _addrWidgetCopy === 'undefined') {
  function _addrWidgetCopy(widId, btn) {
    var w      = document.getElementById(widId);
    if (!w) return;
    var street = (w.querySelector('[name="addr_street"]') || {}).value || '';
    var house  = (w.querySelector('[name="addr_house"]')  || {}).value || '';
    var flat   = (w.querySelector('[name="addr_flat"]')   || {}).value || '';
    var postal = (w.querySelector('[name="addr_postal"]') || {}).value || '';
    var city   = (w.querySelector('[name="addr_city"]')   || {}).value || '';
    var country= (w.querySelector('[name="addr_country"]')|| {}).value || '';
    var line1  = [street, house + (flat ? '/' + flat : '')].filter(Boolean).join(' ');
    var line2  = [postal, city].filter(Boolean).join(' ');
    var line3  = (country && country !== 'PL') ? country : '';
    var text   = [line1, line2, line3].filter(Boolean).join(', ');
    if (!text.trim()) { return; }
    navigator.clipboard.writeText(text).then(function () {
      var ok = document.getElementById(widId + '_copied');
      if (ok) {
        ok.classList.remove('d-none');
        setTimeout(function () { ok.classList.add('d-none'); }, 2000);
      }
    }).catch(function () {
      // Fallback dla starych przeglądarek
      var ta = document.createElement('textarea');
      ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); } catch(e) {}
      document.body.removeChild(ta);
    });
  }
}
</script>
<?php endif; ?>
<?php
    return ob_get_clean();
}
