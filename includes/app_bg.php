<?php
/**
 * includes/app_bg.php — wspólne tło aplikacji: geometria jak na ekranie
 * logowania, tylko przyciemniona i wyciszona, żeby nie przeszkadzała treści.
 *
 * Używane przez powłoki: SZO (includes/header.php), panel wolontariusza
 * (panel/includes/header_panel.php) i CRM (crm/includes/header_crm.php).
 * Wywoływać w <head> PO własnym bloku <style> powłoki — reguła body musi wygrać.
 *
 *   require_once __DIR__ . '/app_bg.php';
 *   app_bg_css();                       // domyślna kanwa
 *   app_bg_css('var(--pvl-body)');      // gdy powłoka trzyma kolor w zmiennej
 */

/** Zwraca data-URI kafla z geometrią (te same kształty co ekran logowania). */
function app_bg_tile(string $fill, string $opacity): string {
    $svg = "<svg xmlns='http://www.w3.org/2000/svg' width='420' height='420' viewBox='0 0 420 420'>"
         . "<g fill='{$fill}' fill-opacity='{$opacity}'>"
         . "<rect x='24' y='40' width='120' height='120' rx='8'/>"
         . "<circle cx='330' cy='96' r='58'/>"
         . "<rect x='210' y='250' width='150' height='150' rx='8'/>"
         . "<path d='M0 210l70-70v46l-24 24zm52 132l96-96v46l-50 50z'/>"
         . "<path d='M300 0l60 60-24 24-60-60z'/>"
         . "</g></svg>";
    return 'url("data:image/svg+xml,' . str_replace(['#', '<', '>', '"', ' '], ['%23', '%3C', '%3E', "'", '%20'], $svg) . '")';
}

/**
 * Emituje <style> z tłem strony.
 *
 * @param string $color Kolor kanwy (literał lub var(--…)). Domyślnie kanwa
 *                      przyciemniona względem dotychczasowej (#F0F2F5/#f8fafc).
 */
function app_bg_css(string $color = '#E8EBF0'): void {
    $light = app_bg_tile('#000', '.045');
    $dark  = app_bg_tile('#fff', '.035');
    ?>
<style>
/* Tło aplikacji — geometria jak na ekranie logowania, wyciszona */
body{
  background-color:<?= $color ?>;
  background-image:<?= $light ?>,repeating-linear-gradient(135deg,rgba(0,0,0,.028) 0 3px,transparent 3px 26px);
  background-size:420px 420px,auto;
  background-attachment:fixed;
  background-position:center top;
}
:root[data-theme="dark"] body{
  background-image:<?= $dark ?>,repeating-linear-gradient(135deg,rgba(255,255,255,.02) 0 3px,transparent 3px 26px);
}
@media(prefers-color-scheme:dark){
  :root:not([data-theme="light"]):not([data-theme="hc"]) body{
    background-image:<?= $dark ?>,repeating-linear-gradient(135deg,rgba(255,255,255,.02) 0 3px,transparent 3px 26px);
  }
}
/* Wysoki kontrast — czysta biel, żadnej tekstury */
:root[data-theme="hc"] body{background-image:none;background-color:#fff}
@media print{body{background-image:none!important;background-color:#fff!important}}
</style>
<?php
}
