<?php
/**
 * karty30/ti/dydaktyk/_print_page.php — uniwersalny wydruk bieżącego ekranu.
 *
 * Dołączany na stronach panelu dydaktyka (skin USOS): dodaje pływający
 * przycisk „Drukuj stronę” (window.print) oraz arkusz @media print, który
 * chowa nawigację, formularze i przyciski, a tabele i karty zostawia
 * w czystej, czarno-białej formie. Dzięki temu KAŻDA tabela/zestawienie
 * modułu daje się wydrukować bez dedykowanego widoku.
 *
 * Zmienna wejściowa (opcjonalna): $PRINT_TITLE — nagłówek dodrukowany
 * u góry strony wydruku (domyślnie tytuł dokumentu).
 */
$PRINT_TITLE = $PRINT_TITLE ?? '';
?>
<button type="button" class="btn btn-outline-secondary btn-sm kp-print-page"
        onclick="window.print()" title="Wydrukuj bieżący ekran (tabele bez nawigacji i formularzy)">
  <i class="bi bi-printer me-1" aria-hidden="true"></i>Drukuj stronę
</button>
<style>
  .kp-print-page { position: fixed; right: 1rem; bottom: 1rem; z-index: 1060;
                   box-shadow: 0 .25rem .75rem rgba(0,0,0,.2); background: var(--bs-body-bg, #fff); }
  .kp-print-head { display: none; }

  @media print {
    /* Chrom aplikacji i interakcje — poza wydrukiem */
    .kp-print-page, .navbar, .kp-topbar, .skin-sections, .skin-subnav, .skin-crumbs,
    .nav-tabs, form, .btn, .btn-close, details, .form-text, .modal, .offcanvas,
    .alert-dismissible, .dropdown-menu, nav[aria-label] { display: none !important; }

    /* Nagłówek wydruku */
    .kp-print-head { display: block; font: 700 16px/1.3 Arial, sans-serif;
                     border-bottom: 2px solid #000; padding-bottom: 6px; margin-bottom: 12px; }
    .kp-print-head small { display: block; font-weight: 400; font-size: 11px; color: #444; }

    /* Czysta, czarno-biała treść */
    body { background: #fff !important; color: #000 !important; }
    main, .dyd-wrap { padding: 0 !important; margin: 0 !important; max-width: none !important; }
    .card { border: 0 !important; box-shadow: none !important; }
    .card-body { padding: 0 0 10px !important; }
    .table-responsive { overflow: visible !important; max-height: none !important; }
    table { border-collapse: collapse !important; width: 100% !important; font-size: 11.5px !important; }
    th, td { border: 1px solid #999 !important; padding: 3px 6px !important;
             background: #fff !important; color: #000 !important; }
    thead th { background: #eee !important; }
    tr { break-inside: avoid; }
    .badge { border: 1px solid #666 !important; background: #fff !important; color: #000 !important; }
    a { color: #000 !important; text-decoration: none !important; }
  }
</style>
<div class="kp-print-head" aria-hidden="true">
  <?= h($PRINT_TITLE !== '' ? $PRINT_TITLE : ($KP_TITLE ?? 'Wydruk')) ?>
  <small><?= h(defined('ORG_NAME') ? ORG_NAME : '') ?> · wydruk: <?= date('d.m.Y H:i') ?></small>
</div>
