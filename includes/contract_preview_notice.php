<?php
/**
 * contract_preview_notice.php
 * Baner "w przygotowaniu" dla typów umów nie obsługiwanych aktualnie.
 *
 * Użycie: require_once + contract_preview_notice('dzielo');
 * Zwraca HTML banera. Gdy drugi arg $block=true: wyświetla i wychodzi (dla add/edit).
 */

function contract_preview_notice(string $type, bool $block = false): string {
    $labels = [
        'dzielo'   => 'Umowy o dzieło',
        'praca'    => 'Umowy o pracę',
        'zlecenie' => 'Umowy zlecenie',
    ];

    $messages = [
        'dzielo'   => 'Umowy o dzieło będą dostępne w kolejnej wersji systemu. Aktualnie obsługujemy pełny moduł porozumień wolontariackich.',
        'praca'    => 'Moduł umów o pracę jest w przygotowaniu. Planujemy pełną integrację z systemem kadrowym.',
        'zlecenie' => 'Moduł umów zlecenie jest w przygotowaniu — trwa dostosowanie do przepisów i integracja z eObiegiem DK.',
    ];

    if (!isset($labels[$type])) return '';

    $label = $labels[$type];
    $msg   = $messages[$type];

    $html = <<<HTML
<div class="alert d-flex align-items-start gap-3 mb-3 no-print"
     style="background:#FFF8F0;border:2px solid #FED7AA;border-radius:12px;padding:1rem 1.25rem">
  <div style="width:42px;height:42px;border-radius:10px;background:#FEF3C7;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0">
    🚧
  </div>
  <div style="flex:1">
    <div style="font-weight:700;font-size:.95rem;color:#92400E;margin-bottom:.2rem">
      {$label} — moduł w przygotowaniu
    </div>
    <div style="font-size:.85rem;color:#78350F;line-height:1.5">
      {$msg}
    </div>
    <div style="margin-top:.5rem;font-size:.78rem;color:#A16207">
      <strong>Istniejące rekordy są zachowane</strong> i będą dostępne po pełnym wdrożeniu modułu.
      Skontaktuj się z administratorem systemu w razie pytań.
    </div>
  </div>
</div>
HTML;

    if ($block) {
        echo $html;
        return '';
    }
    return $html;
}

/**
 * Sprawdza czy dany typ umowy jest w trybie "preview" (nie w pełni obsługiwany).
 */
function contract_is_preview(string $type): bool {
    return in_array($type, ['dzielo', 'praca', 'zlecenie'], true)
        && org_setting('contract_preview_' . $type) !== 'enabled';
}
