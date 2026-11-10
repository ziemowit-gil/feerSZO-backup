<?php
/**
 * modules/rich_editor — wspólny edytor treści e-maili i szablonów (CKEditor 5).
 *
 * Jedno miejsce zamiast kopii w każdym widoku: Wysyłka masowa, okno odpowiedzi
 * (crm/compose_modal.php), Komunikacja, szablony CRM. Kompilacja „classic” 39.0.1
 * z CDN — ta sama co w pismach do umów i EZD; nowsze wydania z CDN (v44+) wymagają
 * płatnego klucza licencyjnego.
 *
 * Użycie: richEditorAssets() w <head> (albo przed skryptem strony), potem w JS
 *   var ed = RichEditor.attach('#mojEdytor', { placeholder: '…', label: 'Treść e-maila' });
 *   ed.ready.then(...); ed.getEmailHtml(); ed.setHtml(html); ed.insertText('{imie}');
 * Szczegóły API: modules/rich_editor/view/richEditor.js.
 */

function richEditorAssets(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $base = rtrim((string)APP_URL, '/') . '/modules/rich_editor/view/';
    $dir  = dirname(__DIR__) . '/view/';
    $ver  = static fn(string $f): int => (int)@filemtime($dir . $f);
    echo '<script src="https://cdn.ckeditor.com/ckeditor5/39.0.1/classic/ckeditor.js"></script>' . "\n";
    echo '<script src="https://cdn.ckeditor.com/ckeditor5/39.0.1/classic/translations/pl.js"></script>' . "\n";
    echo '<link rel="stylesheet" href="' . $base . 'richEditor.css?v=' . $ver('richEditor.css') . '">' . "\n";
    echo '<script src="' . $base . 'richEditor.js?v=' . $ver('richEditor.js') . '"></script>' . "\n";
}
