/**
 * feer_poczta — prostsza i dostępna (WCAG 2.2 AA) zakładka „Udostępnianie” w panelu bocznym Plików.
 *
 * Nie zmienia mechanizmu Nextclouda i nie przenosi jego elementów (Vue przerysowuje widok) — tylko:
 *   • na górze dwa przyciski: „Udostępnij osobie z FEER” i „Udostępnij na zewnątrz” (przenoszą fokus do właściwego pola),
 *   • proste nagłówki i widoczne podpowiedzi zamiast ikon (i), prostsze podpowiedzi w polach wyszukiwania,
 *   • „Zaawansowane” (rozwijane, aria-expanded): link wewnętrzny, „Kto jeszcze ma dostęp”, dodatkowe udostępnienia,
 *   • „Udostępnij i skopiuj link”: istniejący link publiczny albo nowy (OCS, tylko podgląd) → schowek,
 *   • szczegóły udostępnienia (uprawnienia, Zapisz/Anuluj) jako okno dialogowe: role=dialog, aria-modal, fokus w oknie,
 *     Esc = „Anuluj” (klik w przycisk Nextclouda), po zamknięciu fokus wraca,
 *   • komunikaty dla czytników ekranu (role=status), widoczny fokus, większe pola kliknięcia (css/share-a11y.css).
 * Stan „Zaawansowane” pamiętany w przeglądarce (localStorage, gdy dostępny).
 */
(function () {
	'use strict';

	var ADV_KEY = 'feer.share.advanced';
	var TEXT = {
		internalTitle: 'Osoby i zespoły w FEER',
		internalHelp: 'Osoba zobaczy plik u siebie w chmurze FEER. Wpisz imię, nazwisko albo nazwę zespołu i wybierz z listy.',
		internalPlaceholder: 'Wpisz imię, nazwisko albo zespół',
		externalTitle: 'Osoby spoza FEER',
		externalHelp: 'Dla osób bez konta w chmurze FEER. Wpisz adres e-mail, a dostaną zaproszenie, albo skopiuj link publiczny — otworzy go każdy, kto go dostanie.',
		externalPlaceholder: 'Wpisz adres e-mail',
		additionalTitle: 'Dodatkowe udostępnienia',
		btnInternal: 'Udostępnij osobie z FEER',
		btnExternal: 'Udostępnij na zewnątrz',
		advanced: 'Zaawansowane',
		advancedHint: 'link wewnętrzny, kto jeszcze ma dostęp, inne udostępnienia',
		othersWithAccess: 'Kto jeszcze ma dostęp',
		publicLink: 'Link publiczny',
		liveInternal: 'Wpisz imię, nazwisko albo zespół i wybierz osobę z listy.',
		liveExternal: 'Wpisz adres e-mail albo skopiuj link publiczny poniżej.',
		btnCopyLink: 'Udostępnij i skopiuj link',
		copyWorking: 'Tworzę link…',
		copyDone: 'Link skopiowany do schowka. Wklej go w wiadomości (Ctrl+V / ⌘V). Otworzy go każdy, kto go dostanie.',
		copyManual: 'Link gotowy. Skopiuj go z pola poniżej (Ctrl+C / ⌘C).',
		copyFail: 'Nie udało się utworzyć linku: ',
		dialogClose: 'Zamknij',
	};
	var ICON_LINK = 'M3.9 12a3.1 3.1 0 0 1 3.1-3.1h4V7H7a5 5 0 0 0 0 10h4v-1.9H7A3.1 3.1 0 0 1 3.9 12zM8 13h8v-2H8v2zm9-6h-4v1.9h4a3.1 3.1 0 0 1 0 6.2h-4V17h4a5 5 0 0 0 0-10z';
	// Teksty Nextclouda (pl i en) → prostsze; tylko w zakładce udostępniania.
	var RENAME = {
		'Udostępnianie wewnętrzne': TEXT.internalTitle, 'Internal shares': TEXT.internalTitle,
		'Udostępnienia zewnętrzne': TEXT.externalTitle, 'External shares': TEXT.externalTitle,
		'Inni z dostępem': TEXT.othersWithAccess, 'Others with access': TEXT.othersWithAccess,
	};
	var uid = 0;

	function lsGet() { try { return window.localStorage.getItem(ADV_KEY) === '1'; } catch (e) { return false; } }
	function lsSet(v) { try { window.localStorage.setItem(ADV_KEY, v ? '1' : '0'); } catch (e) { /* tryb prywatny */ } }
	function id(prefix) { uid += 1; return 'feer-share-' + prefix + '-' + uid; }

	function el(tag, attrs, text) {
		var n = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); });
		if (text) n.textContent = text;
		return n;
	}

	function svg(path) {
		var ns = 'http://www.w3.org/2000/svg';
		var s = document.createElementNS(ns, 'svg');
		s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('width', '20'); s.setAttribute('height', '20'); s.setAttribute('aria-hidden', 'true'); s.setAttribute('focusable', 'false');
		var p = document.createElementNS(ns, 'path'); p.setAttribute('d', path); p.setAttribute('fill', 'currentColor');
		s.appendChild(p);
		return s;
	}
	var ICON_PERSON = 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm0 2c-2.7 0-8 1.3-8 4v2h16v-2c0-2.7-5.3-4-8-4z';
	var ICON_GLOBE = 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm6.9 6h-2.9a15.7 15.7 0 0 0-1.4-3.6A8 8 0 0 1 18.9 8zM12 4c.8 1.2 1.5 2.5 1.9 4h-3.8c.4-1.5 1.1-2.8 1.9-4zM4.3 14a8.2 8.2 0 0 1 0-4h3.4a16.5 16.5 0 0 0 0 4H4.3zm.8 2h2.9c.3 1.3.8 2.5 1.4 3.6A8 8 0 0 1 5.1 16zM8 8H5.1a8 8 0 0 1 4.3-3.6C8.8 5.5 8.3 6.7 8 8zm4 12c-.8-1.2-1.5-2.5-1.9-4h3.8c-.4 1.5-1.1 2.8-1.9 4zm2.3-6H9.7a14.7 14.7 0 0 1 0-4h4.6a14.7 14.7 0 0 1 0 4zm.3 5.6c.6-1.1 1.1-2.3 1.4-3.6h2.9a8 8 0 0 1-4.3 3.6zM16.3 14a16.5 16.5 0 0 0 0-4h3.4a8.2 8.2 0 0 1 0 4h-3.4z';
	var ICON_CHEVRON = 'M8.6 16.6 13.2 12 8.6 7.4 10 6l6 6-6 6z';

	function setText(node, value) {
		if (node && node.textContent.trim() !== value) node.textContent = value;
	}

	function focusInput(section) {
		if (!section) return false;
		var input = section.querySelector('.sharing-search input, input[type="search"], input');
		section.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
		if (input) { input.focus(); return true; }
		return false;
	}

	function announce(root, msg) {
		var live = root.querySelector('.feer-share-live');
		if (!live) return;
		live.textContent = '';
		window.setTimeout(function () { live.textContent = msg; }, 50);
	}

	/** Sekcje zakładki: pierwsza z wyszukiwarką = wewnętrzne, druga = zewnętrzne, sekcja bez wyszukiwarki z dodatkami = dodatkowe. */
	function markSections(content) {
		var withSearch = [];
		var sections = Array.prototype.slice.call(content.children).filter(function (n) { return n.tagName === 'SECTION'; });
		sections.forEach(function (sec) {
			if (sec.querySelector('.sharing-search')) withSearch.push(sec);
			else if (sec.querySelector('.sharingTab__additionalContent') || /dodatkow|additional/i.test((sec.querySelector('h4') || {}).textContent || '')) sec.setAttribute('data-feer', 'additional');
		});
		if (withSearch[0]) withSearch[0].setAttribute('data-feer', 'internal');
		if (withSearch[1]) withSearch[1].setAttribute('data-feer', 'external');
		return {
			internal: content.querySelector('section[data-feer="internal"]'),
			external: content.querySelector('section[data-feer="external"]'),
			additional: content.querySelector('section[data-feer="additional"]'),
		};
	}

	function ensureHelp(section, text, inputPlaceholder) {
		if (!section) return;
		var header = section.querySelector('.section-header');
		var help = section.querySelector(':scope > .feer-share-help');
		if (!help) {
			help = el('p', { 'class': 'feer-share-help', id: id('help') }, text);
			if (header && header.parentNode === section) header.insertAdjacentElement('afterend', help);
			else section.insertBefore(help, section.firstChild);
		}
		var input = section.querySelector('.sharing-search input');
		if (input) {
			if (input.getAttribute('placeholder') !== inputPlaceholder) input.setAttribute('placeholder', inputPlaceholder);
			var described = (input.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
			if (described.indexOf(help.id) < 0) { described.push(help.id); input.setAttribute('aria-describedby', described.join(' ')); }
			if (!input.getAttribute('aria-label')) input.setAttribute('aria-label', inputPlaceholder);
		}
	}

	function markAdvanced(content, parts) {
		var ids = [];
		function adv(node) {
			if (!node) return;
			node.classList.add('feer-adv');
			if (!node.id) node.id = id('adv');
			ids.push(node.id);
		}
		content.querySelectorAll('.sharing-entry__internal').forEach(function (n) { adv(n.closest('li') || n); });
		var inherited = content.querySelector('#sharing-inherited-shares');
		if (inherited) adv(inherited);
		else content.querySelectorAll('.sharing-entry__inherited').forEach(function (n) { adv(n.closest('ul') || n); });
		adv(parts.additional);
		return ids;
	}

	function ensureQuick(content, parts) {
		var quick = content.querySelector(':scope > .feer-share-quick');
		if (quick) return quick;
		quick = el('div', { 'class': 'feer-share-quick', role: 'group', 'aria-label': 'Szybkie udostępnianie' });
		var bIn = el('button', { type: 'button', 'class': 'feer-share-btn feer-share-btn--primary' });
		bIn.appendChild(svg(ICON_PERSON)); bIn.appendChild(el('span', {}, TEXT.btnInternal));
		var bEx = el('button', { type: 'button', 'class': 'feer-share-btn' });
		bEx.appendChild(svg(ICON_GLOBE)); bEx.appendChild(el('span', {}, TEXT.btnExternal));
		var live = el('div', { 'class': 'feer-share-live feer-visually-hidden', role: 'status', 'aria-live': 'polite' });
		bIn.addEventListener('click', function () {
			var p = markSections(content);
			if (focusInput(p.internal)) announce(content, TEXT.liveInternal);
		});
		bEx.addEventListener('click', function () {
			var p = markSections(content);
			if (!p.external) return;
			p.external.classList.add('feer-share-highlight');
			window.setTimeout(function () { p.external.classList.remove('feer-share-highlight'); }, 2500);
			if (!focusInput(p.external)) {
				var link = p.external.querySelector('.sharing-link-list button, .sharing-link-list a');
				if (link) link.focus();
			}
			announce(content, TEXT.liveExternal);
		});
		var bLink = el('button', { type: 'button', 'class': 'feer-share-btn feer-share-btn--link' });
		bLink.appendChild(svg(ICON_LINK)); bLink.appendChild(el('span', {}, TEXT.btnCopyLink));
		bLink.addEventListener('click', function () { shareAndCopy(bLink); });
		quick.appendChild(bIn); quick.appendChild(bEx); quick.appendChild(bLink); quick.appendChild(live);
		content.insertBefore(quick, content.firstChild);
		return quick;
	}

	function ensureAdvancedToggle(tab, content, parts, ids) {
		var toggle = content.querySelector('.feer-share-adv-toggle');
		if (!ids.length) { if (toggle) toggle.hidden = true; return; }
		if (!toggle) {
			toggle = el('button', { type: 'button', 'class': 'feer-share-adv-toggle', 'aria-expanded': 'false' });
			toggle.appendChild(svg(ICON_CHEVRON));
			var lbl = el('span', { 'class': 'feer-share-adv-label' }, TEXT.advanced);
			toggle.appendChild(lbl);
			toggle.appendChild(el('span', { 'class': 'feer-share-adv-hint' }, TEXT.advancedHint));
			toggle.addEventListener('click', function () {
				var open = !tab.classList.contains('feer-adv-open');
				tab.classList.toggle('feer-adv-open', open);
				toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
				lsSet(open);
			});
		}
		toggle.hidden = false;
		toggle.setAttribute('aria-controls', ids.join(' '));
		// Za sekcją zewnętrzną (albo na końcu), żeby zaawansowane były „pod spodem”.
		var anchor = parts.external || parts.internal;
		if (anchor && anchor.nextElementSibling !== toggle) anchor.insertAdjacentElement('afterend', toggle);
		else if (!anchor && toggle.parentNode !== content) content.appendChild(toggle);
		var open = tab.classList.contains('feer-adv-open');
		toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
	}

	function renameTexts(content, parts) {
		content.querySelectorAll('h4, .sharing-entry__title, .sharing-entry__summary, .sharing-entry__desc').forEach(function (n) {
			var t = n.textContent.trim();
			if (RENAME[t] && n.children.length === 0) setText(n, RENAME[t]);
		});
		if (parts.external) {
			parts.external.querySelectorAll('.sharing-link-list .sharing-entry__title').forEach(function (n) {
				var t = n.textContent.trim();
				if ((t === 'Udostępnij link' || t === 'Share link') && n.children.length === 0) setText(n, TEXT.publicLink);
			});
		}
		// Nagłówki sekcji jako h3 w strukturze (czytniki ekranu): Nextcloud ma h4 pod h2 nazwy pliku.
		[parts.internal, parts.external, parts.additional].forEach(function (sec) {
			var h = sec && sec.querySelector('.section-header h4');
			if (h && !h.hasAttribute('aria-level')) { h.setAttribute('role', 'heading'); h.setAttribute('aria-level', '3'); }
		});
	}

	function enhance(tab) {
		var content = tab.querySelector('.sharingTab__content');
		if (!content) return;
		if (!tab.hasAttribute('data-feer-init')) {
			tab.setAttribute('data-feer-init', '1');
			if (lsGet()) tab.classList.add('feer-adv-open');
		}
		var parts = markSections(content);
		if (!parts.internal && !parts.external) return; // np. widok szczegółów udostępnienia albo błąd
		ensureQuick(content, parts);
		ensureHelp(parts.internal, TEXT.internalHelp, TEXT.internalPlaceholder);
		ensureHelp(parts.external, TEXT.externalHelp, TEXT.externalPlaceholder);
		renameTexts(content, parts);
		ensureAdvancedToggle(tab, content, parts, markAdvanced(content, parts));
		if (!parts.external) { var b = content.querySelectorAll('.feer-share-btn')[1]; if (b) b.hidden = true; }
	}

	// ── Udostępnij i skopiuj link ─────────────────────────────────────────────────────────────
	function requestToken() {
		return (document.head && document.head.dataset && document.head.dataset.requesttoken) || (window.OC && window.OC.requestToken) || '';
	}
	function ocsUrl(q) {
		var root = (window.OC && typeof window.OC.webroot === 'string') ? window.OC.webroot : '';
		return root + '/ocs/v2.php/apps/files_sharing/api/v1/shares' + (q || '');
	}
	function currentPath() {
		var sb = window.OCA && window.OCA.Files && window.OCA.Files.Sidebar;
		if (sb && typeof sb.file === 'string' && sb.file) return sb.file;
		var name = document.querySelector('.app-sidebar-header__mainname');
		var dir = new URLSearchParams(window.location.search).get('dir') || '/';
		if (!name) return '';
		return (dir.replace(/\/$/, '') + '/' + name.textContent.trim()).replace(/^\/+/, '/');
	}
	function ocs(method, url, body) {
		return fetch(url, {
			method: method, credentials: 'same-origin',
			headers: { 'OCS-APIREQUEST': 'true', 'requesttoken': requestToken(), 'Accept': 'application/json', 'Content-Type': 'application/json' },
			body: body ? JSON.stringify(body) : undefined,
		}).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (j) {
				var meta = (j.ocs && j.ocs.meta) || {};
				if (!r.ok || (meta.statuscode && meta.statuscode >= 400 && meta.statuscode !== 100)) throw new Error(meta.message || ('HTTP ' + r.status));
				return (j.ocs && j.ocs.data);
			});
		});
	}
	var toastEl = null;
	function toast(msg, url) {
		if (!toastEl) {
			toastEl = el('div', { 'class': 'feer-toast', role: 'status', 'aria-live': 'polite' });
			document.body.appendChild(toastEl);
		}
		toastEl.innerHTML = '';
		toastEl.appendChild(el('p', {}, msg));
		if (url) {
			var inp = el('input', { type: 'text', readonly: 'readonly', 'aria-label': 'Link publiczny', value: url });
			toastEl.appendChild(inp);
			window.setTimeout(function () { inp.focus(); inp.select(); }, 30);
		}
		var close = el('button', { type: 'button', 'class': 'feer-toast__close', 'aria-label': TEXT.dialogClose }, '×');
		close.addEventListener('click', function () { toastEl.hidden = true; });
		toastEl.appendChild(close);
		toastEl.hidden = false;
		window.clearTimeout(toastEl._t);
		if (!url) toastEl._t = window.setTimeout(function () { toastEl.hidden = true; }, 12000);
	}
	function copy(url) {
		if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(url).then(function () { return true; }, function () { return false; });
		return Promise.resolve(false);
	}
	function shareAndCopy(btn) {
		var path = currentPath();
		if (!path) { toast(TEXT.copyFail + 'nie wiem, który plik jest otwarty.'); return; }
		btn.disabled = true; btn.setAttribute('aria-busy', 'true');
		toast(TEXT.copyWorking);
		var created = false;
		ocs('GET', ocsUrl('?format=json&reshares=false&path=' + encodeURIComponent(path)))
			.then(function (list) {
				var link = (list || []).filter(function (sh) { return Number(sh.share_type) === 3 && sh.url; })[0];
				if (link) return link.url;
				created = true;
				return ocs('POST', ocsUrl('?format=json'), { path: path, shareType: 3, permissions: 1 }).then(function (d) { return d && d.url; });
			})
			.then(function (url) {
				if (!url) throw new Error('brak adresu linku');
				return copy(url).then(function (ok) {
					toast(ok ? TEXT.copyDone : TEXT.copyManual, ok ? null : url);
					if (created) refreshSidebar(path);
				});
			})
			.catch(function (e) { toast(TEXT.copyFail + (e && e.message ? e.message : 'błąd')); })
			.then(function () { btn.disabled = false; btn.removeAttribute('aria-busy'); });
	}
	function refreshSidebar(path) {
		var sb = window.OCA && window.OCA.Files && window.OCA.Files.Sidebar;
		if (!sb || typeof sb.open !== 'function') return;
		try { sb.close(); window.setTimeout(function () { sb.open(path); }, 120); } catch (e) { /* zostaw */ }
	}

	// ── Szczegóły udostępnienia jako okno dialogowe (WCAG: role=dialog, aria-modal, fokus w oknie, Esc = Anuluj) ──
	var lastFocus = null;
	document.addEventListener('focusin', function (e) {
		if (!e.target.closest || !e.target.closest('.feer-share-dialog')) lastFocus = e.target;
	}, true);
	function focusables(root) {
		return Array.prototype.slice.call(root.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'))
			.filter(function (n) { return n.offsetParent !== null || n === document.activeElement; });
	}
	function dialogify(tab) {
		var d = tab.querySelector('.sharingTabDetailsView');
		var open = !!d && window.getComputedStyle(d).display !== 'none';
		if (!open) {
			if (tab._feerDialogOpen) {
				tab._feerDialogOpen = false;
				document.body.classList.remove('feer-share-dialog-open');
				if (lastFocus && document.contains(lastFocus)) { try { lastFocus.focus(); } catch (e) { /* */ } }
			}
			return;
		}
		if (tab._feerDialogOpen && d.hasAttribute('data-feer-dialog')) return;
		tab._feerDialogOpen = true;
		document.body.classList.add('feer-share-dialog-open');
		d.classList.add('feer-share-dialog');
		d.setAttribute('data-feer-dialog', '1');
		d.setAttribute('role', 'dialog');
		d.setAttribute('aria-modal', 'true');
		d.setAttribute('tabindex', '-1');
		var head = d.querySelector('.sharingTabDetailsView__header');
		if (head) { if (!head.id) head.id = id('dlg-title'); d.setAttribute('aria-labelledby', head.id); }
		if (!d._feerKeys) {
			d._feerKeys = true;
			d.addEventListener('keydown', function (e) {
				if (e.key === 'Escape') {
					var cancel = d.querySelector('[data-cy-files-sharing-share-editor-action="cancel"]');
					if (cancel) { e.preventDefault(); e.stopPropagation(); cancel.click(); }
				} else if (e.key === 'Tab') {
					var f = focusables(d);
					if (!f.length) return;
					var first = f[0], last = f[f.length - 1];
					if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
					else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
				}
			});
		}
		window.requestAnimationFrame(function () {
			// Gdy przodek ma transform, „fixed” liczy się od panelu — wtedy zostaw okno jako kartę w panelu.
			var r = d.getBoundingClientRect();
			var centered = Math.abs((r.left + r.right) / 2 - window.innerWidth / 2) < 80;
			d.classList.toggle('feer-share-dialog--inpanel', !centered);
			var f = focusables(d);
			(f[0] || d).focus();
		});
	}

	// ── Wybór sposobu udostępnienia jako okno (ikona udostępniania w wierszu pliku) ───────────────────
	var chooser = null, chooserFrom = null, bypass = false;
	function rowName(btn) {
		var row = btn.closest('tr, [data-cy-files-list-row]');
		var n = row && (row.getAttribute('data-cy-files-list-row-name') || (row.querySelector('.files-list__row-name-text, .files-list__row-name') || {}).textContent || '');
		return (n || '').trim();
	}
	function openSidebarThen(btn, fn) {
		bypass = true;
		btn.click();
		bypass = false;
		var tries = 0;
		(function wait() {
			var tab = document.querySelector('.sharingTab');
			var parts = tab && tab.querySelector('.sharingTab__content') && markSections(tab.querySelector('.sharingTab__content'));
			if (parts && (parts.internal || parts.external)) { fn(tab, parts); return; }
			if (++tries < 60) window.setTimeout(wait, 100);
		})();
	}
	function closeChooser(back) {
		if (!chooser || chooser.hidden) return;
		chooser.hidden = true;
		if (back && chooserFrom && document.contains(chooserFrom)) chooserFrom.focus();
	}
	function openChooser(btn) {
		chooserFrom = btn;
		if (!chooser) {
			chooser = el('div', { 'class': 'feer-chooser-wrap', hidden: '' });
			var box = el('div', { 'class': 'feer-chooser', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'feer-chooser-title', 'aria-describedby': 'feer-chooser-file' });
			box.appendChild(el('h2', { id: 'feer-chooser-title', 'class': 'feer-chooser__title' }, 'Jak chcesz udostępnić?'));
			box.appendChild(el('p', { id: 'feer-chooser-file', 'class': 'feer-chooser__file' }));
			var opts = [
				['in', ICON_PERSON, TEXT.btnInternal, 'Osoba zobaczy plik u siebie w chmurze FEER.'],
				['ex', ICON_GLOBE, TEXT.btnExternal, 'Zaproszenie na adres e-mail osoby spoza FEER.'],
				['link', ICON_LINK, TEXT.btnCopyLink, 'Link do pliku trafi do schowka — otworzy go każdy, kto go dostanie.'],
			];
			opts.forEach(function (o) {
				var b = el('button', { type: 'button', 'class': 'feer-chooser__opt', 'data-opt': o[0] });
				b.appendChild(svg(o[1]));
				var t = el('span', { 'class': 'feer-chooser__opt-text' });
				t.appendChild(el('strong', {}, o[2])); t.appendChild(el('span', {}, o[3]));
				b.appendChild(t);
				box.appendChild(b);
			});
			var cancel = el('button', { type: 'button', 'class': 'feer-chooser__cancel' }, 'Anuluj');
			box.appendChild(cancel);
			chooser.appendChild(box);
			document.body.appendChild(chooser);
			chooser.addEventListener('click', function (e) {
				if (e.target === chooser || e.target === cancel) { closeChooser(true); return; }
				var opt = e.target.closest('.feer-chooser__opt');
				if (!opt) return;
				var from = chooserFrom; var kind = opt.getAttribute('data-opt');
				closeChooser(false);
				openSidebarThen(from, function (tab, parts) {
					var content = tab.querySelector('.sharingTab__content');
					if (kind === 'in') { focusInput(parts.internal); announce(content, TEXT.liveInternal); }
					else if (kind === 'ex') { focusInput(parts.external); announce(content, TEXT.liveExternal); }
					else { var lb = content.querySelector('.feer-share-btn--link'); shareAndCopy(lb || opt); }
				});
			});
			box.addEventListener('keydown', function (e) {
				if (e.key === 'Escape') { e.preventDefault(); closeChooser(true); return; }
				if (e.key === 'Tab') {
					var f = focusables(box); var first = f[0], last = f[f.length - 1];
					if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
					else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
				}
				if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
					var o2 = Array.prototype.slice.call(box.querySelectorAll('.feer-chooser__opt'));
					var i = o2.indexOf(document.activeElement);
					if (i >= 0) { e.preventDefault(); o2[(i + (e.key === 'ArrowDown' ? 1 : o2.length - 1)) % o2.length].focus(); }
				}
			});
		}
		var name = rowName(btn);
		chooser.querySelector('.feer-chooser__file').textContent = name ? ('Plik: ' + name) : '';
		chooser.hidden = false;
		window.requestAnimationFrame(function () { chooser.querySelector('.feer-chooser__opt').focus(); });
	}
	document.addEventListener('click', function (e) {
		if (bypass) return;
		var btn = e.target.closest && e.target.closest('[data-cy-files-list-row-action="sharing-status"], .files-list__row-action-sharing-status');
		if (!btn) return;
		e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
		openChooser(btn);
	}, true);

	/**
	 * Karty panelu bocznego: „Udostępnianie” jest domyślne, więc jego karta znika, gdy jest otwarte (wraca jako ikona,
	 * gdy otworzysz inną kartę). Pozostałe karty (np. „Aktywność”) jako małe ikony; nazwa zostaje dla czytników ekranu.
	 */
	function compactTabs() {
		document.querySelectorAll('.app-sidebar-tabs__nav').forEach(function (nav) {
			if (!nav.querySelector('[aria-controls="tab-sharing"]')) return; // tylko panel Plików z udostępnianiem
			nav.classList.add('feer-tabs-compact');
			nav.querySelectorAll('.app-sidebar-tabs__tab').forEach(function (tab) {
				var ctl = tab.matches('[aria-controls]') ? tab : tab.querySelector('[aria-controls]');
				var target = ctl ? ctl.getAttribute('aria-controls') : '';
				tab.classList.toggle('feer-tab-sharing', target === 'tab-sharing');
				var caption = tab.querySelector('.app-sidebar-tabs__tab-caption');
				var name = caption ? caption.textContent.trim() : '';
				if (name && ctl && ctl.getAttribute('title') !== name) ctl.setAttribute('title', name);
				if (name && ctl && !ctl.getAttribute('aria-label')) ctl.setAttribute('aria-label', name);
			});
		});
	}

	var scheduled = false;
	function scan() {
		scheduled = false;
		try { compactTabs(); } catch (e) { if (window.console) console.warn('feer share-a11y tabs', e); }
		document.querySelectorAll('.sharingTab').forEach(function (tab) {
			try { enhance(tab); } catch (e) { if (window.console) console.warn('feer share-a11y', e); }
			try { dialogify(tab); } catch (e) { if (window.console) console.warn('feer share-a11y dialog', e); }
		});
	}
	function schedule() {
		if (scheduled) return;
		scheduled = true;
		window.requestAnimationFrame(scan);
	}

	function start() {
		schedule();
		new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true, characterData: false });
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
	else start();
})();
