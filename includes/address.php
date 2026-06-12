<?php
/**
 * Structured address helpers.
 *
 * Adds addr_street / addr_house / addr_flat / addr_postal / addr_city / addr_country
 * columns to all contract tables + shipments (idempotent, try/catch per column).
 *
 * Also manages the postal_codes table (import via admin/postal_import.php).
 *
 * Usage in edit.php:
 *   require_once '.../includes/address.php';
 *   // In $allowed array: array_merge($allowed, address_fields())
 *   // In HTML:           echo address_widget($row);
 *
 * Usage with TERYT/postal autocomplete:
 *   echo address_widget($row, ['autocomplete' => true]);
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

    // ── Tabela kodów pocztowych (słownik poczty) ─────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS postal_codes (
        id      INTEGER PRIMARY KEY AUTOINCREMENT,
        code    TEXT NOT NULL,
        city    TEXT NOT NULL,
        gmina   TEXT NOT NULL DEFAULT '',
        powiat  TEXT NOT NULL DEFAULT '',
        woj     TEXT NOT NULL DEFAULT '',
        UNIQUE(code, city)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_postal_code ON postal_codes(code)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_postal_city ON postal_codes(city)");
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
 *   'label'        => string   Section label (default: 'Adres zamieszkania')
 *   'required'     => bool     Show asterisk on street (default: false)
 *   'size'         => 'sm'|'' Input size suffix (default: '')
 *   'show_legacy'  => bool     Show read-only legacy field if no structured data (default: true)
 *   'copy_button'  => bool     Show "Kopiuj adres" clipboard button (default: false)
 *   'widget_id'    => string   HTML id prefix for the widget div (default: auto)
 *   'autocomplete' => bool     Enable TERYT/postal autocomplete (default: false)
 *   'api_url'      => string   Base URL for addr API (default: APP_URL + /crm/api/addr.php)
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
    $autocomplete = $opts['autocomplete'] ?? false;
    $api_url      = $opts['api_url']      ?? (defined('APP_URL') ? APP_URL . '/crm/api/addr.php' : '/crm/api/addr.php');
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

    $ac_attr = $autocomplete ? ' data-addr-ac="1" data-api-url="' . h($api_url) . '"' : '';

    ob_start();
?>
<div class="addr-widget" id="<?= h($wid) ?>"<?= $ac_attr ?>>
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
    <div class="col-6 col-sm-7 fgroup" style="position:relative">
      <label class="form-label mb-1 small fw-semibold">Ulica<?= $req_mark ?></label>
      <input name="addr_street" class="form-control<?= $sz ?> addr-street"
             placeholder="ul. Kwiatowa" value="<?= $street_val ?>"
             <?= $required ? 'required' : '' ?> autocomplete="address-line1">
    </div>
    <div class="col-3 fgroup">
      <label class="form-label mb-1 small fw-semibold">Nr domu</label>
      <input name="addr_house" class="form-control<?= $sz ?> addr-house"
             placeholder="5" value="<?= $house_val ?>" maxlength="10" autocomplete="off">
    </div>
    <div class="col-3 fgroup">
      <label class="form-label mb-1 small fw-semibold">Nr lok.</label>
      <input name="addr_flat" class="form-control<?= $sz ?> addr-flat"
             placeholder="12" value="<?= $flat_val ?>" maxlength="10" autocomplete="off">
    </div>
    <div class="col-4 col-sm-3 fgroup" style="position:relative">
      <label class="form-label mb-1 small fw-semibold">
        Kod pocztowy
        <?php if ($autocomplete): ?>
        <span class="addr-postal-spinner text-muted ms-1" style="display:none;font-size:.7rem">
          <i class="bi bi-arrow-repeat"></i>
        </span>
        <?php endif; ?>
      </label>
      <input name="addr_postal" class="form-control<?= $sz ?> font-monospace addr-postal"
             placeholder="00-001" value="<?= $postal_val ?>" maxlength="10"
             pattern="\d{2}-\d{3}" title="Format: XX-XXX" autocomplete="postal-code">
    </div>
    <div class="col-8 col-sm-5 fgroup" style="position:relative">
      <label class="form-label mb-1 small fw-semibold">Miasto
        <?php if ($autocomplete): ?>
        <span class="addr-city-spinner text-muted ms-1" style="display:none;font-size:.7rem">
          <i class="bi bi-arrow-repeat"></i>
        </span>
        <?php endif; ?>
      </label>
      <input name="addr_city" class="form-control<?= $sz ?> addr-city"
             placeholder="Warszawa" value="<?= $city_val ?>" autocomplete="address-level2">
    </div>
    <div class="col-12 col-sm-4 fgroup">
      <label class="form-label mb-1 small fw-semibold">Kraj</label>
      <select name="addr_country" class="form-select<?= $sz_sel ?> addr-country">
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
<?php
    $html_out = ob_get_clean();

    // ── JS (emitowane tylko raz dla copy_button) ─────────────────────────────
    static $copy_js_done = false;
    if ($copy_button && !$copy_js_done) {
        $copy_js_done = true;
        $html_out .= <<<'JS'
<script>
if (typeof _addrWidgetCopy === 'undefined') {
  function _addrWidgetCopy(widId, btn) {
    var w = document.getElementById(widId); if (!w) return;
    var street = (w.querySelector('[name="addr_street"]')||{}).value||'';
    var house  = (w.querySelector('[name="addr_house"]') ||{}).value||'';
    var flat   = (w.querySelector('[name="addr_flat"]')  ||{}).value||'';
    var postal = (w.querySelector('[name="addr_postal"]')||{}).value||'';
    var city   = (w.querySelector('[name="addr_city"]')  ||{}).value||'';
    var country= (w.querySelector('[name="addr_country"]')||{}).value||'';
    var line1  = [street, house+(flat?'/'+flat:'')].filter(Boolean).join(' ');
    var line2  = [postal, city].filter(Boolean).join(' ');
    var line3  = (country && country!=='PL') ? country : '';
    var text   = [line1, line2, line3].filter(Boolean).join(', ');
    if (!text.trim()) return;
    navigator.clipboard.writeText(text).then(function() {
      var ok = document.getElementById(widId+'_copied');
      if (ok) { ok.classList.remove('d-none'); setTimeout(function(){ ok.classList.add('d-none'); }, 2000); }
    }).catch(function() {
      var ta=document.createElement('textarea'); ta.value=text;
      ta.style.cssText='position:fixed;opacity:0'; document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); } catch(e) {}
      document.body.removeChild(ta);
    });
  }
}
</script>
JS;
    }

    // ── Autocomplete JS (emitowane tylko raz) ────────────────────────────────
    static $ac_js_done = false;
    if ($autocomplete && !$ac_js_done) {
        $ac_js_done = true;
        $html_out .= <<<'JS'
<script>
(function() {
'use strict';

/* ─── Dropdown helper ───────────────────────────────────────────────────── */
function addrDropdown(inp, items, onSelect) {
  var old = document.getElementById('_addr_dd_' + inp.id);
  if (old) old.remove();
  if (!items.length) return;

  var dd = document.createElement('div');
  dd.id  = '_addr_dd_' + inp.id;
  dd.style.cssText = [
    'position:absolute','z-index:1060','background:#fff',
    'border:1px solid #dee2e6','border-radius:.5rem',
    'box-shadow:0 4px 16px rgba(0,0,0,.12)',
    'min-width:' + inp.offsetWidth + 'px',
    'max-height:220px','overflow-y:auto','font-size:.83rem',
    'top:' + (inp.offsetTop + inp.offsetHeight + 2) + 'px',
    'left:' + inp.offsetLeft + 'px',
  ].join(';');

  items.forEach(function(it) {
    var d = document.createElement('div');
    d.style.cssText = 'padding:.38rem .75rem;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis';
    d.textContent   = it.label || it;
    d.addEventListener('mousedown', function(e) {
      e.preventDefault();
      onSelect(it);
      dd.remove();
    });
    d.addEventListener('mouseover',  function() { d.style.background = '#f0f4ff'; });
    d.addEventListener('mouseout',   function() { d.style.background = ''; });
    dd.appendChild(d);
  });

  inp.parentElement.style.position = 'relative';
  inp.parentElement.appendChild(dd);

  function closeOnOutside(e) {
    if (!dd.contains(e.target) && e.target !== inp) {
      dd.remove(); document.removeEventListener('mousedown', closeOnOutside);
    }
  }
  document.addEventListener('mousedown', closeOnOutside);
}

function addrDropdownHide(inp) {
  var old = document.getElementById('_addr_dd_' + inp.id);
  if (old) old.remove();
}

/* ─── Debounce ──────────────────────────────────────────────────────────── */
function debounce(fn, ms) {
  var t; return function() { var a=arguments, ctx=this; clearTimeout(t); t=setTimeout(function(){ fn.apply(ctx,a); }, ms); };
}

/* ─── Format postal XX-XXX ─────────────────────────────────────────────── */
function fmtPostal(v) {
  var d = v.replace(/\D/g, '').slice(0, 5);
  return d.length > 2 ? d.slice(0,2) + '-' + d.slice(2) : d;
}

/* ─── Init widget ───────────────────────────────────────────────────────── */
function initAddrWidget(wid) {
  var w = document.getElementById(wid);
  if (!w) return;
  var api     = w.dataset.apiUrl || '/crm/api/addr.php';
  var postalEl = w.querySelector('.addr-postal');
  var cityEl   = w.querySelector('.addr-city');
  if (!postalEl && !cityEl) return;

  /* ── Kod pocztowy ─────────────────────────────────────────────────────── */
  if (postalEl) {
    // Auto-format XX-XXX podczas wpisywania
    postalEl.addEventListener('input', function() {
      var raw = this.value;
      var fmt = fmtPostal(raw);
      if (fmt !== raw) { var p = this.selectionStart; this.value = fmt; try { this.setSelectionRange(p,p); } catch(e){} }
    });

    var fetchPostal = debounce(function() {
      var q = postalEl.value.trim();
      if (q.length < 2) { addrDropdownHide(postalEl); return; }
      fetch(api + '?action=postal&q=' + encodeURIComponent(q))
        .then(function(r) { return r.json(); })
        .then(function(rows) {
          if (!rows.length) { addrDropdownHide(postalEl); return; }
          addrDropdown(postalEl, rows.map(function(r) {
            return { label: r.code + ' — ' + r.city, code: r.code, city: r.city };
          }), function(it) {
            postalEl.value = it.code;
            if (cityEl && !cityEl.value.trim()) cityEl.value = it.city;
            postalEl.dispatchEvent(new Event('change'));
          });
        }).catch(function() {});
    }, 250);

    postalEl.addEventListener('input', fetchPostal);

    // Po wpisaniu pełnego kodu XX-XXX → zapytaj o miasto
    postalEl.addEventListener('change', function() {
      var code = this.value.trim();
      if (!/^\d{2}-\d{3}$/.test(code)) return;
      if (cityEl && cityEl.value.trim()) return; // miasto już wypełnione
      fetch(api + '?action=postal_city&code=' + encodeURIComponent(code))
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (data.cities && data.cities.length === 1 && cityEl && !cityEl.value.trim()) {
            cityEl.value = data.cities[0];
          } else if (data.cities && data.cities.length > 1 && cityEl && !cityEl.value.trim()) {
            // Pokaż dropdown z możliwymi miastami
            addrDropdown(cityEl, data.cities.map(function(c) {
              return { label: c, city: c };
            }), function(it) {
              if (cityEl) cityEl.value = it.city;
            });
          }
        }).catch(function() {});
    });
  }

  /* ── Miasto — autocomplete z TERYT ───────────────────────────────────── */
  if (cityEl) {
    var fetchCity = debounce(function() {
      var q = cityEl.value.trim();
      if (q.length < 2) { addrDropdownHide(cityEl); return; }
      fetch(api + '?action=city&q=' + encodeURIComponent(q))
        .then(function(r) { return r.json(); })
        .then(function(rows) {
          if (!rows.length) { addrDropdownHide(cityEl); return; }
          addrDropdown(cityEl, rows.map(function(name) {
            return { label: name, city: name };
          }), function(it) {
            cityEl.value = it.city;
          });
        }).catch(function() {});
    }, 250);

    cityEl.addEventListener('input', fetchCity);
    cityEl.addEventListener('blur',  function() { setTimeout(function(){ addrDropdownHide(cityEl); }, 150); });
  }

  if (postalEl) {
    postalEl.addEventListener('blur', function() { setTimeout(function(){ addrDropdownHide(postalEl); }, 150); });
  }
}

/* ─── Auto-init wszystkich widgetów ─────────────────────────────────────── */
function initAllAddrWidgets() {
  document.querySelectorAll('.addr-widget[data-addr-ac]').forEach(function(w) {
    if (!w.dataset.addrAcInit) {
      w.dataset.addrAcInit = '1';
      initAddrWidget(w.id);
    }
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initAllAddrWidgets);
} else {
  initAllAddrWidgets();
}

/* Eksportuj globalnie żeby można było wywołać ręcznie po AJAX */
window.AddrWidget = { init: initAddrWidget, initAll: initAllAddrWidgets };

})();
</script>
JS;
    }

    return $html_out;
}
