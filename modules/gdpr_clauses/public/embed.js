/*
 * modules/gdpr_clauses/public/embed.js — osadzanie klauzuli RODO na zewnętrznej stronie.
 *
 *   <div data-gdpr-clause="rekrutacja"></div>
 *   <script src="https://…/modules/gdpr_clauses/public/embed.js" async></script>
 *
 * Każdy element z data-gdpr-clause dostaje iframe z /klauzula/{slug}?embed=1,
 * którego wysokość dopasowuje się do treści (postMessage z clause.php).
 * data-lang="en" — wersja językowa (brak → polska).
 * Iframe zamiast wstrzykiwania HTML — style strony-gospodarza nie psują
 * klauzuli i odwrotnie. Opcjonalnie data-height="400" = stała wysokość.
 */
(function () {
  var script = document.currentScript;
  if (!script) {
    var all = document.querySelectorAll('script[src*="gdpr_clauses/public/embed.js"]');
    script = all[all.length - 1];
  }
  var base = script.src.replace(/\/modules\/gdpr_clauses\/public\/embed\.js.*$/, '');
  var origin = new URL(base, location.href).origin;
  var frames = [];

  function mount() {
    document.querySelectorAll('[data-gdpr-clause]:not([data-gdpr-mounted])').forEach(function (el) {
      var slug = el.getAttribute('data-gdpr-clause');
      if (!/^[a-z0-9-]{1,64}$/.test(slug)) return;
      el.setAttribute('data-gdpr-mounted', '1');
      var f = document.createElement('iframe');
      var lang = (el.getAttribute('data-lang') || '').toLowerCase();
      f.src = base + '/klauzula/' + slug + (/^[a-z]{2}$/.test(lang) && lang !== 'pl' ? '/' + lang : '') + '?embed=1';
      f.title = 'Klauzula informacyjna RODO';
      f.loading = 'lazy';
      f.style.cssText = 'width:100%;border:0;display:block;height:' + (parseInt(el.getAttribute('data-height'), 10) || 400) + 'px';
      if (el.hasAttribute('data-height')) f.setAttribute('data-fixed', '1');
      el.appendChild(f);
      frames.push(f);
    });
  }

  window.addEventListener('message', function (e) {
    if (e.origin !== origin || !e.data || e.data.type !== 'gdpr-clause-height') return;
    frames.forEach(function (f) {
      if (f.contentWindow !== e.source) return;
      // Tytuł ramki = tytuł klauzuli (czytniki ekranu ogłaszają go przy wejściu w iframe).
      if (typeof e.data.title === 'string' && e.data.title) f.title = e.data.title;
      if (!f.hasAttribute('data-fixed')) f.style.height = (e.data.height + 4) + 'px';
    });
  });

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount); else mount();
})();
