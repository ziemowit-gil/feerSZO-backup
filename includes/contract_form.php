<?php
/**
 * Reużywalne fragmenty formularza umowy.
 * Wymaga: $row (tablica danych lub pusta), $readonly (bool)
 */
$readonly = $readonly ?? false;
$row = $row ?? [];
$ro  = $readonly ? 'readonly' : '';
$dis = $readonly ? 'disabled' : '';

function fval(array $row, string $key, mixed $default = ''): mixed {
    return $row[$key] ?? $default;
}

function field_text(array $row, string $name, string $label, bool $readonly = false, string $type = 'text', string $cls = 'col-md-6'): void {
    $val = htmlspecialchars((string)($row[$name] ?? ''), ENT_QUOTES);
    $ro  = $readonly ? 'readonly' : '';
    echo "<div class=\"{$cls} mb-3\"><label class=\"form-label\">{$label}</label>";
    echo "<input type=\"{$type}\" name=\"{$name}\" class=\"form-control\" value=\"{$val}\" {$ro}></div>";
}

function field_textarea(array $row, string $name, string $label, bool $readonly = false, string $cls = 'col-12'): void {
    $val = htmlspecialchars((string)($row[$name] ?? ''), ENT_QUOTES);
    $ro  = $readonly ? 'readonly' : '';
    echo "<div class=\"{$cls} mb-3\"><label class=\"form-label\">{$label}</label>";
    echo "<textarea name=\"{$name}\" class=\"form-control\" rows=\"3\" {$ro}>{$val}</textarea></div>";
}

function field_select(array $row, string $name, string $label, array $options, bool $readonly = false, string $cls = 'col-md-6'): void {
    $val = (string)($row[$name] ?? '');
    $dis = $readonly ? 'disabled' : '';
    echo "<div class=\"{$cls} mb-3\"><label class=\"form-label\">{$label}</label><select name=\"{$name}\" class=\"form-select\" {$dis}>";
    echo '<option value="">— wybierz —</option>';
    foreach ($options as $k => $v) {
        $sel = $val === (string)$k ? 'selected' : '';
        echo "<option value=\"" . htmlspecialchars($k) . "\" {$sel}>" . htmlspecialchars($v) . "</option>";
    }
    echo "</select></div>";
}

function field_check(array $row, string $name, string $label, bool $readonly = false, string $cls = 'col-md-6'): void {
    $checked = !empty($row[$name]) ? 'checked' : '';
    $dis = $readonly ? 'disabled' : '';
    echo "<div class=\"{$cls} mb-3 d-flex align-items-center gap-2 pt-4\">";
    echo "<input type=\"checkbox\" name=\"{$name}\" id=\"{$name}\" class=\"form-check-input\" value=\"1\" {$checked} {$dis}>";
    echo "<label class=\"form-check-label\" for=\"{$name}\">{$label}</label></div>";
}
?>
