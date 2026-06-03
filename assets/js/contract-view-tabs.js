/**
 * contract-view-tabs.js
 * Logika zakładek dla widoków umów.
 * Wymaga window.CVTabsConfig = { tabsId, storageKey, defaultTab }
 */
document.addEventListener('DOMContentLoaded', function () {
  var cfg  = window.CVTabsConfig;
  if (!cfg) return;

  var tabs = document.getElementById(cfg.tabsId);
  if (!tabs) return;

  function showTab(btn) { if (btn) new bootstrap.Tab(btn).show(); }

  var hash = location.hash;

  // Hash URL ma priorytet
  if (hash && hash.startsWith('#tab-')) {
    var hashBtn = document.querySelector('[data-bs-target="' + hash + '"]');
    if (hashBtn) { showTab(hashBtn); return; }
  }

  // Anchor wiadomości
  if (hash === '#tab-messages-anchor' || location.search.includes('msg=1')) {
    var msgBtn = document.getElementById('tab-messages-btn');
    if (msgBtn) { showTab(msgBtn); localStorage.setItem(cfg.storageKey, 'tab-messages'); return; }
  }

  // Anchor zadań
  if (hash === '#tab-tasks-anchor') {
    var tasksBtn = document.getElementById('tab-tasks-btn');
    if (tasksBtn) { showTab(tasksBtn); localStorage.setItem(cfg.storageKey, 'tab-tasks'); return; }
  }

  // Przywróć z localStorage lub otwórz domyślną
  var saved  = localStorage.getItem(cfg.storageKey) || cfg.defaultTab;
  var target = document.querySelector('[data-bs-target="#' + saved + '"]');
  showTab(target || tabs.querySelector('[data-bs-toggle="tab"]'));

  // Zapisz przy zmianie
  tabs.addEventListener('shown.bs.tab', function (e) {
    localStorage.setItem(cfg.storageKey, e.target.dataset.bsTarget.replace('#', ''));
  });
});

/**
 * Dynamiczne ładowanie list zadań po wyborze workspace.
 * Wymaga window.CVTabsConfig.appUrl i window.CVTabsConfig.csrf
 */
function loadLists(wsId) {
  var sel = document.getElementById('listSelect');
  if (!sel) return;
  var cfg = window.CVTabsConfig || {};
  if (!wsId) { sel.innerHTML = '<option value="">— najpierw wybierz obszar —</option>'; return; }
  sel.innerHTML = '<option value="">Ładowanie…</option>';
  fetch(cfg.appUrl + '/tasks/api/list.php?action=lists&ws=' + wsId + '&_csrf=' + encodeURIComponent(cfg.csrf || ''))
    .then(function (r) { return r.json(); })
    .then(function (data) {
      sel.innerHTML = '<option value="">— wybierz kolumnę —</option>';
      if (data.ok && data.data) {
        data.data.forEach(function (l) {
          var o = document.createElement('option');
          o.value = l.id; o.textContent = l.name;
          sel.appendChild(o);
        });
      }
    })
    .catch(function () { sel.innerHTML = '<option value="">Błąd ładowania</option>'; });
}
