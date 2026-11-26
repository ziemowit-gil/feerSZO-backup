/**
 * feer_poczta — selektor aplikacji FEER zamiast menu „kafelki” Nextclouda (przycisk .app-menu__waffle w nagłówku).
 *
 * Lista aplikacji: stan początkowy core/apps (#initial-state-core-apps) — te same uprawnienia co w menu Nextclouda.
 * Układ: wyszukiwarka, grupy (Utwórz · Na co dzień · Dokumenty · Organizacja · FEER · Inne), duże pola z podpisem po polsku.
 * „Utwórz” = szybkie tworzenie Nextclouda (stan core/navigationActions): akcja z href → link, bez href → zdarzenie
 * core:navigation:action na window._nc_event_bus (tak jak robi menu Nextclouda — obsługuje je właściwa aplikacja).
 * Ukryte: „Zespoły” Nextclouda (circles — zespoły są w Strukturze SZO); „Desktop” (desktop_workspace) = „Widok okien”.
 * WCAG 2.2 AA: role=dialog + aria-modal=false (okienko nie blokuje strony), nagłówki grup, aria-current dla bieżącej,
 * strzałki/Home/End w siatce, Esc i klik poza zamykają, fokus wraca na przycisk; linki zewnętrzne z opisem.
 * Najechanie myszą nie otwiera menu (Nextcloud otwiera je na hover — przechwytujemy).
 */
(function () {
	'use strict';

	var GROUPS = [
		{ id: 'daily', name: 'Na co dzień', apps: ['dashboard', 'files', 'calendar', 'contacts', 'mail', 'spreed', 'deck', 'tasks'] },
		{ id: 'docs', name: 'Dokumenty i zdjęcia', apps: ['richdocuments', 'office', 'onlyoffice', 'photos', 'memories', 'text', 'notes', 'files_pdfviewer'] },
		{ id: 'org', name: 'Organizacja', apps: ['activity', 'passwords', 'forms', 'polls', 'groupfolders'] },
	];
	var HIDDEN = ['circles'];
	var RENAME = { desktop_workspace: 'Widok okien', richdocuments: 'Biuro', office: 'Biuro' };
	var FEER = [
		{ id: 'feer-szo', name: 'SZO', href: 'https://szo.feer.org.pl/', icon: 'M3 3h8v8H3V3zm10 0h8v8h-8V3zM3 13h8v8H3v-8zm10 0h8v8h-8v-8z' },
		{ id: 'feer-poczta', name: 'Poczta', href: 'https://poczta.feer.org.pl/', icon: 'M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4-8 5-8-5V6l8 5 8-5v2z' },
		{ id: 'feer-zespoly', name: 'Zespoły', href: 'https://szo.feer.org.pl/org/teams.php', icon: 'M16 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm-8 0a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm0 2c-2.3 0-7 1.2-7 3.5V19h14v-2.5C15 14.2 10.3 13 8 13zm8 0c-.3 0-.6 0-1 .1 1.2.8 2 2 2 3.4V19h6v-2.5c0-2.3-4.7-3.5-7-3.5z' },
		{ id: 'feer-pomoc', name: 'Pomoc IT', href: 'https://szo.feer.org.pl/uslugi-it/', icon: 'M11 18h2v-2h-2v2zm1-16a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm0 18a8 8 0 1 1 0-16 8 8 0 0 1 0 16zm0-14a4 4 0 0 0-4 4h2a2 2 0 1 1 4 0c0 2-3 1.8-3 5h2c0-2.2 3-2.5 3-5a4 4 0 0 0-4-4z' },
	];

	var ACTION_NAMES = {
		'Text document': 'Dokument tekstowy', 'New text document': 'Dokument tekstowy', 'Document': 'Dokument',
		'Spreadsheet': 'Arkusz', 'Presentation': 'Prezentacja', 'Diagram': 'Diagram', 'Event': 'Wydarzenie',
		'Task': 'Zadanie', 'Contact': 'Kontakt', 'Note': 'Notatka', 'Folder': 'Folder', 'New folder': 'Folder',
		'Prezentacje': 'Prezentacja', 'Wydarzenia': 'Wydarzenie',
	};
	var ICON_PLUS = 'M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z';

	function readState(key) {
		var inp = document.getElementById('initial-state-' + key);
		if (!inp) return null;
		try { return JSON.parse(decodeURIComponent(escape(window.atob(inp.value)))); } catch (e) { return null; }
	}

	function readActions() {
		var raw = readState('core-navigationActions');
		var list = Array.isArray(raw) ? raw : (raw ? Object.keys(raw).map(function (k) { return raw[k]; }) : []);
		return list.filter(function (a) { return a && (a.id || a.name); }).sort(function (a, b) { return (a.order || 0) - (b.order || 0); });
	}

	function runAction(action) {
		hide(false);
		var bus = window._nc_event_bus;
		if (bus && typeof bus.emit === 'function') { bus.emit('core:navigation:action', action); return; }
		if (window.console) console.warn('feer app-menu: brak szyny zdarzeń dla', action && action.id);
	}

	function readApps() {
		var inp = document.getElementById('initial-state-core-apps');
		if (!inp) return [];
		try {
			var raw = JSON.parse(decodeURIComponent(escape(window.atob(inp.value))));
			var list = Array.isArray(raw) ? raw : Object.keys(raw).map(function (k) { return raw[k]; });
			return list.filter(function (a) { return a && a.id && a.href && HIDDEN.indexOf(a.id) < 0; })
				.sort(function (a, b) { return (a.order || 0) - (b.order || 0); });
		} catch (e) { return []; }
	}

	function el(tag, attrs, text) {
		var n = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) { if (attrs[k] !== null && attrs[k] !== undefined) n.setAttribute(k, attrs[k]); });
		if (text) n.textContent = text;
		return n;
	}
	function svgIcon(path) {
		var ns = 'http://www.w3.org/2000/svg';
		var s = document.createElementNS(ns, 'svg');
		s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('aria-hidden', 'true'); s.setAttribute('focusable', 'false');
		var p = document.createElementNS(ns, 'path'); p.setAttribute('d', path); p.setAttribute('fill', 'currentColor');
		s.appendChild(p);
		return s;
	}

	var panel = null, waffle = null, lastOpen = 0;

	function tile(app, external) {
		var name = RENAME[app.id] || app.name;
		var isAction = !!app._action && !(app.href && app.href !== '#');
		var a = isAction
			? el('button', { type: 'button', 'class': 'feer-apps__tile', 'data-name': name.toLowerCase() })
			: el('a', { href: app.href, 'class': 'feer-apps__tile', 'data-name': name.toLowerCase() });
		if (isAction) a.addEventListener('click', function () { runAction(app._action); });
		if (app.active) a.setAttribute('aria-current', 'page');
		var ico = el('span', { 'class': 'feer-apps__icon', 'aria-hidden': 'true' });
		if (app.color) ico.style.setProperty('--feer-icon-color', app.color);
		if (app.svg) ico.appendChild(svgIcon(app.svg));
		else if (app.icon) { ico.classList.add('feer-apps__icon--mask'); ico.style.setProperty('--feer-icon', 'url("' + app.icon + '")'); }
		a.appendChild(ico);
		a.appendChild(el('span', { 'class': 'feer-apps__label' }, name));
		if (external) {
			a.setAttribute('target', '_blank'); a.setAttribute('rel', 'noopener');
			a.appendChild(el('span', { 'class': 'feer-visually-hidden' }, ' (otwiera się w nowej karcie)'));
		}
		if (app.unread) a.appendChild(el('span', { 'class': 'feer-apps__badge' }, String(app.unread)));
		return a;
	}

	function build() {
		var apps = readApps();
		var byId = {};
		apps.forEach(function (a) { byId[a.id] = a; });
		var used = {};
		panel = el('div', { id: 'feer-apps', 'class': 'feer-apps', role: 'dialog', 'aria-modal': 'false', 'aria-labelledby': 'feer-apps-title', hidden: '' });
		var head = el('div', { 'class': 'feer-apps__head' });
		head.appendChild(el('h2', { id: 'feer-apps-title', 'class': 'feer-apps__title' }, 'Aplikacje'));
		var close = el('button', { type: 'button', 'class': 'feer-apps__close', 'aria-label': 'Zamknij' }, '×');
		close.addEventListener('click', function () { hide(true); });
		head.appendChild(close);
		panel.appendChild(head);
		var searchId = 'feer-apps-search';
		panel.appendChild(el('label', { 'for': searchId, 'class': 'feer-visually-hidden' }, 'Szukaj aplikacji'));
		var search = el('input', { id: searchId, type: 'search', 'class': 'feer-apps__search', placeholder: 'Szukaj aplikacji…', autocomplete: 'off' });
		panel.appendChild(search);
		var status = el('p', { 'class': 'feer-visually-hidden', role: 'status', 'aria-live': 'polite' });
		panel.appendChild(status);

		function section(title, items, external) {
			if (!items.length) return;
			var sec = el('section', { 'class': 'feer-apps__group' });
			var hid = 'feer-apps-g-' + title.replace(/\W+/g, '').toLowerCase();
			sec.appendChild(el('h3', { id: hid, 'class': 'feer-apps__group-title' }, title));
			var ul = el('ul', { 'class': 'feer-apps__grid', 'aria-labelledby': hid });
			items.forEach(function (it) { var li = el('li'); li.appendChild(tile(it, external)); ul.appendChild(li); });
			sec.appendChild(ul);
			panel.appendChild(sec);
		}
		var actions = readActions().map(function (a) {
			var nm = ACTION_NAMES[a.name] || a.name || a.id;
			return { id: 'action-' + (a.id || nm), name: nm, href: a.href || '', icon: a.icon || '', svg: a.icon ? null : ICON_PLUS, color: a.color || '', _action: a };
		});
		section('Utwórz', actions, false);
		GROUPS.forEach(function (g) {
			var items = g.apps.filter(function (id) { return byId[id]; }).map(function (id) { used[id] = 1; return byId[id]; });
			section(g.name, items, false);
		});
		section('FEER', FEER.map(function (f) { return { id: f.id, name: f.name, href: f.href, svg: f.icon }; }), true);
		var rest = apps.filter(function (a) { return !used[a.id]; });
		section('Inne', rest, false);

		search.addEventListener('input', function () {
			var q = search.value.trim().toLowerCase();
			var n = 0;
			panel.querySelectorAll('.feer-apps__group').forEach(function (sec) {
				var vis = 0;
				sec.querySelectorAll('li').forEach(function (li) {
					var ok = !q || li.firstChild.getAttribute('data-name').indexOf(q) >= 0;
					li.hidden = !ok; if (ok) vis++;
				});
				sec.hidden = vis === 0; n += vis;
			});
			status.textContent = q ? ('Znaleziono aplikacji: ' + n) : '';
		});
		search.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowDown' || (e.key === 'Enter' && search.value)) {
				var first = visibleTiles()[0];
				if (first) { e.preventDefault(); if (e.key === 'Enter') first.click(); else first.focus(); }
			}
		});
		panel.addEventListener('keydown', onKey);
		document.body.appendChild(panel);
	}

	function visibleTiles() {
		return Array.prototype.slice.call(panel.querySelectorAll('.feer-apps__tile')).filter(function (t) { return !t.closest('[hidden]'); });
	}

	function onKey(e) {
		if (e.key === 'Escape') { e.preventDefault(); hide(true); return; }
		var t = e.target.closest && e.target.closest('.feer-apps__tile');
		if (!t) return;
		var tiles = visibleTiles(); var i = tiles.indexOf(t); if (i < 0) return;
		var cols = Math.max(1, Math.round(t.closest('ul').clientWidth / t.offsetWidth));
		var j = null;
		if (e.key === 'ArrowRight') j = i + 1; else if (e.key === 'ArrowLeft') j = i - 1;
		else if (e.key === 'ArrowDown') j = i + cols; else if (e.key === 'ArrowUp') j = i - cols;
		else if (e.key === 'Home') j = 0; else if (e.key === 'End') j = tiles.length - 1;
		if (j === null) return;
		e.preventDefault();
		if (j < 0) { panel.querySelector('.feer-apps__search').focus(); return; }
		tiles[Math.min(j, tiles.length - 1)].focus();
	}

	function position() {
		var r = (waffle || document.querySelector('.app-menu__waffle') || document.body).getBoundingClientRect();
		panel.style.top = Math.round(r.bottom + 8) + 'px';
		panel.style.insetInlineStart = Math.max(8, Math.round(r.left)) + 'px';
	}

	function show() {
		if (!panel) build();
		position();
		panel.hidden = false;
		lastOpen = Date.now();
		if (waffle) waffle.setAttribute('aria-expanded', 'true');
		var s = panel.querySelector('.feer-apps__search'); s.value = ''; s.dispatchEvent(new Event('input'));
		var cur = panel.querySelector('[aria-current="page"]');
		(cur || s).focus();
	}
	function hide(returnFocus) {
		if (!panel || panel.hidden) return;
		panel.hidden = true;
		if (waffle) { waffle.setAttribute('aria-expanded', 'false'); if (returnFocus) waffle.focus(); }
	}

	// Przechwycenie przycisku Nextclouda (faza capture — zanim zareaguje jego Vue).
	document.addEventListener('click', function (e) {
		var w = e.target.closest && e.target.closest('.app-menu__waffle, .app-menu__trigger');
		if (w) {
			waffle = document.querySelector('.app-menu__waffle button, button.app-menu__waffle') || document.querySelector('.app-menu__waffle');
			if (waffle) { waffle.setAttribute('aria-haspopup', 'dialog'); waffle.setAttribute('aria-controls', 'feer-apps'); }
			e.preventDefault(); e.stopPropagation();
			if (panel && !panel.hidden) hide(true); else show();
			return;
		}
		if (panel && !panel.hidden && !panel.contains(e.target) && Date.now() - lastOpen > 50) hide(false);
	}, true);
	['mouseenter', 'mouseover', 'pointerenter'].forEach(function (ev) {
		document.addEventListener(ev, function (e) {
			if (e.target.closest && e.target.closest('.app-menu__trigger')) e.stopPropagation();
		}, true);
	});
	document.addEventListener('keydown', function (e) {
		if ((e.key === 'Enter' || e.key === ' ') && e.target.closest && e.target.closest('.app-menu__waffle')) {
			e.preventDefault(); e.stopPropagation();
			waffle = e.target.closest('button') || e.target;
			if (panel && !panel.hidden) hide(true); else show();
		}
	}, true);
	window.addEventListener('resize', function () { if (panel && !panel.hidden) position(); });
})();
