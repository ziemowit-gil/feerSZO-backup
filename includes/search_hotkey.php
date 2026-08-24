<?php
/**
 * includes/search_hotkey.php — klawisz „/" ustawia kursor w wyszukiwarce strony.
 *
 * Skrót, który wszedł do kanonu (GitHub, Gmail, Slack): jeden znak zamiast
 * sięgania po mysz. Szukamy pola w kolejności od najbardziej „tej właściwej"
 * (jawnie oznaczone `data-search-input`) do ogólnej (pierwsze widoczne pole
 * wyszukiwania na stronie). Gdy strona nie ma wyszukiwarki, otwieramy paletę
 * poleceń (Ctrl+K) — bo intencja jest ta sama: „chcę czegoś poszukać".
 *
 * Nie przechwytujemy „/" podczas pisania w polu, w edytorze WYSIWYG ani przy
 * wciśniętym modyfikatorze — inaczej nie dałoby się wpisać ukośnika w tekście.
 *
 * Escape w polu wyszukiwania oddaje fokus stronie.
 */
if (defined('_SEARCH_HOTKEY_LOADED')) return;
define('_SEARCH_HOTKEY_LOADED', true);
if (!function_exists('current_user') || !current_user()) return;
?>
<script>
(function () {
  'use strict';

  // Kolejność ma znaczenie: najpierw pole wskazane przez stronę, potem typowe
  // wyszukiwarki modułów, na końcu cokolwiek, co wygląda jak szukanie.
  var SELECTORS = [
    '[data-search-input]',
    'input[type="search"]:not([disabled]):not([readonly])',
    'input[name="q"]:not([type="hidden"])',
    'input[name="search"]:not([type="hidden"])',
    'input[placeholder*="zukaj" i]',
    'input[placeholder*="iltruj" i]'
  ];

  function visible(el) {
    if (!el || el.disabled || el.readOnly) return false;
    if (el.type === 'hidden') return false;
    var r = el.getBoundingClientRect();
    if (r.width === 0 && r.height === 0) return false;
    var cs = window.getComputedStyle(el);
    return cs.visibility !== 'hidden' && cs.display !== 'none';
  }

  function findSearch() {
    for (var i = 0; i < SELECTORS.length; i++) {
      var list = document.querySelectorAll(SELECTORS[i]);
      for (var j = 0; j < list.length; j++) if (visible(list[j])) return list[j];
    }
    return null;
  }

  function typing(el) {
    if (!el) return false;
    var tag = (el.tagName || '').toLowerCase();
    return tag === 'input' || tag === 'textarea' || tag === 'select'
        || el.isContentEditable
        || !!el.closest('.ql-editor, .tox-edit-area, [contenteditable="true"]');
  }

  document.addEventListener('keydown', function (e) {
    // Escape w polu wyszukiwania — oddaj fokus stronie
    if (e.key === 'Escape' && document.activeElement
        && document.activeElement.matches && document.activeElement.matches(SELECTORS.join(','))) {
      document.activeElement.blur();
      return;
    }

    if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
    if (typing(e.target) || typing(document.activeElement)) return;

    var field = findSearch();
    if (field) {
      e.preventDefault();
      field.focus();
      if (field.select) field.select();
      field.setAttribute('aria-keyshortcuts', '/');
      return;
    }

    // Brak wyszukiwarki na stronie → paleta poleceń (ta sama intencja)
    if (window.__feerCmdk) {
      e.preventDefault();
      document.dispatchEvent(new KeyboardEvent('keydown', {
        key: 'k', ctrlKey: true, bubbles: true, cancelable: true
      }));
    }
  });
})();
</script>
