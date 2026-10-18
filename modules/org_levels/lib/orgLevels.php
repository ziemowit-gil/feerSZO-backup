<?php
/**
 * modules/org_levels/lib/orgLevels.php — poziomy struktury wewnętrznej: Jednostka → Dział.
 *
 * Kolumna org_units.level: 'jednostka' (poziom najwyższy, grupuje działy) albo 'dzial'.
 * Wszystkie dotychczasowe jednostki wewnętrzne stają się Działami (DEFAULT 'dzial');
 * administrator tworzy nad nimi Jednostki i przypina do nich działy jako jednostkę
 * nadrzędną (parent_id). Jednostki zewnętrzne (kind='external') poziomu nie używają.
 *
 * Funkcjonalność współdzielona: org/units/add.php, edit.php, view.php, org/index.php
 * (includes/org.php: org_render_tree) i Katalog (/katalog, modules/katalog_metro).
 * Zapis poziomu osobnym UPDATE po org_unit_create/org_unit_update — bez zmian w ich SQL.
 */

const ORG_LEVELS = ['jednostka' => 'Jednostka', 'dzial' => 'Dział'];
const ORG_LEVEL_PLURAL = ['jednostka' => 'Jednostki', 'dzial' => 'Działy'];
const ORG_LEVEL_ICONS = ['jednostka' => 'bi-building', 'dzial' => 'bi-diagram-3'];

function orgLevelsMigrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try { db()->exec("ALTER TABLE org_units ADD COLUMN level TEXT NOT NULL DEFAULT 'dzial'"); } catch (\Throwable $e) {}
}

function orgLevelOf(array $unit): string {
    if (($unit['kind'] ?? 'internal') === 'external') return '';
    $l = (string)($unit['level'] ?? 'dzial');
    return isset(ORG_LEVELS[$l]) ? $l : 'dzial';
}

function orgLevelLabel(array $unit): string {
    $l = orgLevelOf($unit);
    return $l === '' ? '' : ORG_LEVELS[$l];
}

function orgLevelSet(int $unitId, string $level): void {
    orgLevelsMigrate();
    if (!isset(ORG_LEVELS[$level])) $level = 'dzial';
    db()->prepare("UPDATE org_units SET level=? WHERE id=?")->execute([$level, $unitId]);
}

/** Pole wyboru poziomu do formularza jednostki wewnętrznej. */
function orgLevelField(string $current): string {
    $current = isset(ORG_LEVELS[$current]) ? $current : 'dzial';
    $html = '<fieldset class="mb-3"><legend class="form-label fw-semibold" style="font-size:1rem">Poziom w strukturze</legend><div class="d-flex flex-wrap gap-3">';
    foreach (ORG_LEVELS as $k => $label) {
        $hint = $k === 'jednostka' ? 'najwyższy poziom, grupuje działy' : 'część jednostki (wskaż ją jako nadrzędną)';
        $html .= '<div class="form-check"><input class="form-check-input" type="radio" name="org_level" id="org_level_' . $k . '" value="' . $k . '"'
               . ($current === $k ? ' checked' : '') . '><label class="form-check-label" for="org_level_' . $k . '"><i class="bi ' . ORG_LEVEL_ICONS[$k] . ' me-1" aria-hidden="true"></i>'
               . h($label) . ' <span class="text-muted small">— ' . h($hint) . '</span></label></div>';
    }
    return $html . '</div></fieldset>';
}

/** Znacznik poziomu (drzewo w org/index.php, karta jednostki). */
function orgLevelBadge(array $unit): string {
    $l = orgLevelOf($unit);
    if ($l === '') return '';
    $cls = $l === 'jednostka' ? 'bg-primary text-white' : 'bg-light text-secondary border';
    return '<span class="badge ' . $cls . ' ms-1" style="font-size:.62rem"><i class="bi ' . ORG_LEVEL_ICONS[$l] . ' me-1" aria-hidden="true"></i>' . h(ORG_LEVELS[$l]) . '</span>';
}

/** Najbliższa Jednostka nad działem (po parent_id) albo null. $byId: [id => unit]. */
function orgLevelParentUnit(array $unit, array $byId): ?array {
    $seen = [];
    $p = (int)($unit['parent_id'] ?? 0);
    while ($p && isset($byId[$p]) && !isset($seen[$p])) {
        $seen[$p] = true;
        if (orgLevelOf($byId[$p]) === 'jednostka') return $byId[$p];
        $p = (int)($byId[$p]['parent_id'] ?? 0);
    }
    return null;
}
