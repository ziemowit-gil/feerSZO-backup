<?php
/**
 * Wybór statusu kartoteki CRM — jeden komponent dla formularzy dodawania/edycji.
 *
 * Natywne radio (dostępne z klawiatury, strzałki działają jak w każdej grupie),
 * zaznaczenie pokazuje CSS (:has / :checked) — bez JS i bez styli inline, więc nie ma
 * rozjazdu stanu po zmianie. Zaznaczenie to ramka w kolorze statusu + znacznik ✓,
 * nie sam kolor (WCAG 1.4.1). Statusy spoza katalogu (osierocone) zostają jako opcja,
 * żeby zapis kartoteki nie przestawił ich po cichu na „prospect”.
 */
function crmStatusPicker(string $current, string $name = 'status', string $labelId = ''): string
{
    static $cssDone = false;
    $out = '';
    if (!$cssDone) {
        $cssDone = true;
        $out .= <<<'CSS'
<style>
.sp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(8.5rem,1fr));gap:.5rem}
.sp-opt{position:relative;display:flex;align-items:center;gap:.5rem;margin:0;padding:.55rem .65rem;
  min-height:2.75rem;border:1px solid #c3c8cf;border-radius:.4rem;background:#fff;color:#1f2937;
  font-size:.84rem;line-height:1.2;cursor:pointer;transition:background .12s,border-color .12s}
.sp-opt:hover{background:#f3f6f9;border-color:#8a94a1}
.sp-opt input{position:absolute;opacity:0;inset:0;width:100%;height:100%;margin:0;cursor:pointer}
.sp-dot{flex:0 0 auto;width:.8rem;height:.8rem;border-radius:50%;background:var(--sp-c,#939393);
  box-shadow:0 0 0 1px rgba(0,0,0,.25)}
.sp-txt{flex:1 1 auto;min-width:0;overflow-wrap:anywhere}
.sp-tick{flex:0 0 auto;visibility:hidden;font-size:.95rem;color:#111827}
.sp-opt:has(input:checked){border-color:var(--sp-c,#00807e);box-shadow:inset 0 0 0 1px var(--sp-c,#00807e);
  background:var(--crm-primary-bg,#e5f2f2);font-weight:600}
.sp-opt:has(input:checked) .sp-tick{visibility:visible}
.sp-opt:has(input:focus-visible){outline:3px solid #d90429;outline-offset:2px}
@media (forced-colors:active){.sp-opt:has(input:checked){outline:2px solid Highlight}}
</style>
CSS;
    }
    $statuses = crm_statuses_with($current);
    $out .= '<div class="sp-grid" role="radiogroup"'
          . ($labelId !== '' ? ' aria-labelledby="' . h($labelId) . '"' : ' aria-label="Status kontaktu"') . '>';
    foreach ($statuses as $sk => $sv) {
        $label = trim((string)$sv['label']);
        if ($label === '' && $current !== $sk) continue;   // puste wpisy słownika nie są opcjami
        $color = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)($sv['color'] ?? '')) ? $sv['color'] : '#939393';
        $out .= '<label class="sp-opt" style="--sp-c:' . h($color) . '">'
              . '<input type="radio" name="' . h($name) . '" value="' . h($sk) . '"' . ($current === $sk ? ' checked' : '') . '>'
              . '<span class="sp-dot" aria-hidden="true"></span>'
              . '<span class="sp-txt">' . h($label !== '' ? $label : $sk) . (!empty($sv['orphan']) ? ' (spoza katalogu)' : '') . '</span>'
              . '<i class="bi bi-check-lg sp-tick" aria-hidden="true"></i>'
              . '</label>';
    }
    return $out . '</div>';
}
