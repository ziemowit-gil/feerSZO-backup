/**
 * feer_poczta — poprawki dostępności formularzy Nextclouda (WCAG 2.2 AA), ładowane na każdej stronie.
 *   • Kalendarz › „Utwórz harmonogram spotkań”: pola opisane tylko tekstem zastępczym (placeholder) dostają widoczną
 *     etykietę <label for> (1.3.1, 3.3.2) — placeholder znika przy pisaniu.
 *   • Strona rezerwacji: zepsuty obrazek awatara (brak zdjęcia) zastąpiony inicjałami.
 *   • Hasła: stan bezpieczeństwa hasła nie tylko kolorem tarczy (1.4.1) — napis „Bezpieczne / Słabe / Ujawnione /
 *     Niesprawdzone” obok ikony i jako nazwa dla czytników (kolor z atrybutu fill: --color-element-success/warning/error).
 *   • Kalendarz: brakujące polskie tłumaczenia (np. „Start time”, „End date”, „When shared”) w edytorze i dymku wydarzenia,
 *     w etykietach, podpowiedziach pól i aria-label.
 * Bez przenoszenia elementów Vue: etykieta wstawiana tuż przed polem, idempotentnie (MutationObserver).
 */
(function () {
	'use strict';
	var n = 0;
	var PL = {
		'Start time': 'Godzina od', 'End time': 'Godzina do', 'Start date': 'Data od', 'End date': 'Data do',
		'When shared': 'Gdy udostępnione', 'Filter events …': 'Szukaj wydarzeń…', 'Filter events ...': 'Szukaj wydarzeń…',
		'Filter events': 'Szukaj wydarzeń', 'Add a location': 'Dodaj lokalizację', 'All day': 'Cały dzień',
	};
	function translate(root) {
		var w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
		var t;
		while ((t = w.nextNode())) {
			var v = t.nodeValue.trim();
			if (v && PL[v]) t.nodeValue = t.nodeValue.replace(v, PL[v]);
		}
		root.querySelectorAll('[placeholder], [aria-label], [title]').forEach(function (el) {
			['placeholder', 'aria-label', 'title'].forEach(function (a) {
				var v = (el.getAttribute(a) || '').trim();
				if (v && PL[v]) el.setAttribute(a, PL[v]);
			});
		});
	}
	function labelize(root) {
		root.querySelectorAll('input[placeholder]:not([type="hidden"]):not([type="search"]), textarea[placeholder]').forEach(function (inp) {
			if (inp.closest('.feer-apps, .sharingTab, .mx-datepicker, .native-datetime-picker, .vs__dropdown-toggle')) return;
			var prev = inp.previousElementSibling;
			if (prev && (prev.tagName === 'LABEL' || prev.classList.contains('feer-field-label'))) { inp.setAttribute('data-feer-label', '1'); return; }
			if (inp.hasAttribute('data-feer-label')) return;
			if (inp.id && root.querySelector('label[for="' + CSS.escape(inp.id) + '"]')) { inp.setAttribute('data-feer-label', '1'); return; }
			if (inp.closest('label') || inp.getAttribute('aria-labelledby')) { inp.setAttribute('data-feer-label', '1'); return; }
			var text = (inp.getAttribute('aria-label') || inp.getAttribute('placeholder') || '').trim();
			if (!text) return;
			if (!inp.id) { n += 1; inp.id = 'feer-field-' + n; }
			var lab = document.createElement('label');
			lab.className = 'feer-field-label';
			lab.setAttribute('for', inp.id);
			lab.textContent = text;
			inp.parentNode.insertBefore(lab, inp);
			inp.setAttribute('data-feer-label', '1');
		});
	}
	function avatars(root) {
		root.querySelectorAll('.booking__config-user-info img:not([data-feer-av])').forEach(function (img) {
			img.setAttribute('data-feer-av', '1');
			function fallback() {
				var name = ((root.querySelector('.booking__config-user-info') || {}).textContent || '').trim();
				var ini = name.split(/\s+/).filter(Boolean).slice(0, 2).map(function (w) { return w[0]; }).join('').toUpperCase() || '?';
				var span = document.createElement('span');
				span.className = 'feer-avatar-fallback'; span.setAttribute('aria-hidden', 'true'); span.textContent = ini;
				img.replaceWith(span);
			}
			if (img.complete && img.naturalWidth === 0) fallback();
			else img.addEventListener('error', fallback, { once: true });
		});
	}
	var SEC = [
		['--color-element-success', 'Bezpieczne', 'ok'],
		['--color-element-warning', 'Słabe', 'weak'],
		['--color-element-error', 'Ujawnione', 'breached'],
		['--color-main-text', 'Niesprawdzone', 'unknown'],
	];
	function passwordSecurity(root) {
		root.querySelectorAll('.shield-half-full-icon').forEach(function (icon) {
			var path = icon.querySelector('svg path, svg');
			var fill = ((path && (path.getAttribute('fill') || path.style.fill)) || icon.getAttribute('style') || '').toString();
			var hit = null;
			SEC.forEach(function (x) { if (!hit && fill.indexOf(x[0]) >= 0) hit = x; });
			if (!hit) return;
			icon.setAttribute('role', 'img');
			icon.setAttribute('aria-label', 'Bezpieczeństwo hasła: ' + hit[1]);
			var next = icon.nextElementSibling;
			if (next && next.classList.contains('feer-sec-label')) {
				if (next.textContent !== hit[1]) { next.textContent = hit[1]; next.className = 'feer-sec-label feer-sec--' + hit[2]; }
				return;
			}
			var lab = document.createElement('span');
			lab.className = 'feer-sec-label feer-sec--' + hit[2];
			lab.setAttribute('aria-hidden', 'true');
			lab.textContent = hit[1];
			icon.insertAdjacentElement('afterend', lab);
		});
	}

	var queued = false;
	function run() {
		queued = false;
		// Tylko formularze, w których pola NIE mają etykiet (sprawdzone): harmonogram spotkań i dane rezerwującego.
		// Nie w każdym oknie — np. edytor wydarzenia ma własne etykiety (dublowały się i nachodziły na pola).
		document.querySelectorAll('.appointment-config-modal, .booking-details, .appointment-details').forEach(labelize);
		avatars(document);
		if (document.querySelector('.passwordsapp, #app-content .shield-half-full-icon')) passwordSecurity(document);
		// Strona rezerwacji (publiczna): bez stopki Nextclouda („Chmura FEER · Załóż własne bezpłatne konto”).
		if (document.querySelector('.booking, .booking__container, .appointment-booking-confirmation')) {
			document.querySelectorAll('footer, #footer, .footer, a[href*="nextcloud.com/signup"]').forEach(function (n) {
				var box = n.tagName === 'A' ? (n.closest('footer, .guest-box, div') || n) : n;
				if (!box.contains(document.querySelector('.booking'))) box.style.display = 'none';
			});
		}
		document.querySelectorAll('.app-full, .event-popover, .app-calendar .app-navigation, #app-navigation-vue').forEach(translate);
		// Szybkie okienko wydarzenia: pola z samą podpowiedzią dostają dostępną nazwę (bez zmiany wyglądu).
		document.querySelectorAll('.event-popover input[placeholder]:not([aria-label]), .event-popover textarea[placeholder]:not([aria-label])').forEach(function (f) {
			if (f.id && document.querySelector('label[for="' + CSS.escape(f.id) + '"]')) return;
			var ph = (f.getAttribute('placeholder') || '').split('\n')[0].trim();
			if (ph) f.setAttribute('aria-label', ph);
		});
	}
	function schedule() { if (!queued) { queued = true; window.requestAnimationFrame(run); } }
	function start() { schedule(); new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true }); }
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
