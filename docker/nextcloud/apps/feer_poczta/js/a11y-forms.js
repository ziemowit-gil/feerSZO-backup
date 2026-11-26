/**
 * feer_poczta — poprawki dostępności formularzy Nextclouda (WCAG 2.2 AA), ładowane na każdej stronie.
 *   • Kalendarz › „Utwórz harmonogram spotkań”: pola opisane tylko tekstem zastępczym (placeholder) dostają widoczną
 *     etykietę <label for> (1.3.1, 3.3.2) — placeholder znika przy pisaniu.
 *   • Strona rezerwacji: zepsuty obrazek awatara (brak zdjęcia) zastąpiony inicjałami.
 * Bez przenoszenia elementów Vue: etykieta wstawiana tuż przed polem, idempotentnie (MutationObserver).
 */
(function () {
	'use strict';
	var n = 0;
	function labelize(root) {
		root.querySelectorAll('input[placeholder]:not([type="hidden"]):not([type="search"]), textarea[placeholder]').forEach(function (inp) {
			if (inp.closest('.feer-apps, .sharingTab')) return;
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
	var queued = false;
	function run() {
		queued = false;
		document.querySelectorAll('.appointment-config-modal, .booking-details, .appointment-details, .modal-container').forEach(labelize);
		avatars(document);
	}
	function schedule() { if (!queued) { queued = true; window.requestAnimationFrame(run); } }
	function start() { schedule(); new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true }); }
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
