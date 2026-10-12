/**
 * Terra & Thread — shared runtime: money formatting, pricing, REST helper, the
 * bag store (localStorage, synced across tabs) and the bag drawer UI.
 * Exposes window.TT for configurator.js and booking.js.
 *
 * On the static export (ttStudio.static, set by bin/export-static.php) there is
 * no PHP, so api() is served by staticApi(): availability comes from exported
 * JSON, prices from quote(), and reservations are kept in this browser.
 */
(() => {
	'use strict';

	const cfg = window.ttStudio || {};
	const locale = cfg.locale || 'en-US';
	const currency = cfg.currency || 'USD';
	const whole = new Intl.NumberFormat(locale, { style: 'currency', currency, maximumFractionDigits: 0 });
	const cents = new Intl.NumberFormat(locale, { style: 'currency', currency, minimumFractionDigits: 2 });

	/** Mirrors Catalog\format_money(): no decimals for whole amounts. */
	const money = (n) => {
		const v = Math.round(n * 100) / 100;
		return Number.isInteger(v) ? whole.format(v) : cents.format(v);
	};

	/* ── Pricing (mirrors TerraThread\Core\Catalog\quote()) ─────────────── */

	const ENGRAVING = /^[\p{L}\p{N} .,'&!?\-]*$/u;
	const normaliseText = (v) => String(v || '').replace(/\s+/gu, ' ').trim();

	function quote(product, selection, quantity) {
		let unit = product.price;
		const lines = [{ label: 'Base price', amount: product.price }];
		const errors = {};
		const summary = [];
		const clean = {};

		for (const opt of product.options) {
			const value = selection[opt.key];

			if (opt.type === 'swatch' || opt.type === 'select') {
				const choice = opt.choices.find((c) => c.value === value) || opt.choices[0];
				if (value != null && choice.value !== value) errors[opt.key] = `Please check “${opt.label}”.`;
				clean[opt.key] = choice.value;
				summary.push(choice.label.split(' — ')[0]);
				if (choice.price) {
					lines.push({ label: `${opt.label}: ${choice.label}`, amount: choice.price });
					unit += choice.price;
				}
			} else if (opt.type === 'text') {
				const text = normaliseText(value);
				if (!text) continue;
				if (text.length > opt.maxlength) errors[opt.key] = `Keep it to ${opt.maxlength} characters.`;
				else if (!ENGRAVING.test(text)) errors[opt.key] = 'Letters, numbers, spaces and . , \' & ! ? - only.';
				const chars = text.replace(/ /g, '').length;
				const amount = Math.round((opt.price + chars * opt.price_per_char) * 100) / 100;
				clean[opt.key] = text;
				lines.push({ label: `${opt.label} “${text}”`, amount });
				summary.push(`“${text}”`);
				unit += amount;
			} else if (opt.type === 'checkbox' && value) {
				clean[opt.key] = true;
				lines.push({ label: opt.label, amount: opt.price });
				summary.push(opt.label);
				unit += opt.price;
			}
		}

		unit = Math.round(unit * 100) / 100;
		return { unit, total: Math.round(unit * quantity * 100) / 100, quantity, lines, errors, selection: clean, summary: summary.join(' · ') };
	}

	/* ── API: WordPress REST, or the static-site adapter ──────────────────── */

	function apiError(message, status, data = {}) {
		const err = new Error(message);
		err.status = status;
		err.data = data;
		return err;
	}

	const DEMO_KEY = 'tt-demo-bookings-v1';
	const products = {};
	const sessionCache = new Map(); // session id → session, filled by every availability fetch

	function demoBookings() {
		try {
			const v = JSON.parse(localStorage.getItem(DEMO_KEY) || '[]');
			return Array.isArray(v) ? v : [];
		} catch {
			return [];
		}
	}

	async function staticApi(path, { method = 'GET', body } = {}) {
		let m;

		if (method === 'GET' && (m = path.match(/^workshops\/(\d+)\/sessions$/))) {
			const res = await fetch(`${cfg.rest}workshops/${m[1]}/sessions.json`, { headers: { Accept: 'application/json' } });
			if (!res.ok) throw apiError('Workshop not found.', 404);
			const data = await res.json();
			const now = Date.now();
			const mine = demoBookings();
			data.sessions = data.sessions
				.filter((s) => new Date(s.iso).getTime() > now)
				.map((s) => {
					const taken = mine.filter((b) => b.session === s.id).reduce((n, b) => n + b.seats, 0);
					const seats_left = Math.max(0, s.seats_left - taken);
					const session = { ...s, seats_left, sold_out: seats_left === 0 };
					sessionCache.set(s.id, session);
					return session;
				});
			return data;
		}

		if (method === 'POST' && path === 'bookings') {
			const session = sessionCache.get(body.session_id);
			if (!session) throw apiError('That session is no longer available.', 404);
			if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(body.email || '')) throw apiError('Please enter a valid email address.', 400);
			if (normaliseText(body.name).length < 2) throw apiError('Please tell us your name.', 400);
			if (body.seats < 1 || body.seats > 4) throw apiError('You can reserve 1–4 seats at a time.', 400);
			if (body.seats > session.seats_left) {
				throw apiError(session.seats_left ? `Only ${session.seats_left} seats are left in this session.` : 'This session just sold out.', 409);
			}
			const reference = 'TT-' + Array.from(crypto.getRandomValues(new Uint8Array(6)), (b) => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'[b % 32]).join('');
			try {
				localStorage.setItem(DEMO_KEY, JSON.stringify([...demoBookings(), { session: session.id, seats: body.seats, reference }]));
			} catch {
				/* private mode: the confirmation still shows */
			}
			const seats_left = session.seats_left - body.seats;
			return { reference, seats: body.seats, session: { ...session, seats_left, sold_out: seats_left === 0 }, demo: true };
		}

		if (method === 'POST' && (m = path.match(/^products\/(\d+)\/quote$/))) {
			const product = products[m[1]];
			if (!product) throw apiError('Product not found.', 404);
			if (product.stock < 1) throw apiError('This piece is sold out.', 409);
			const max = Math.min(10, product.stock);
			if (body.quantity < 1 || body.quantity > max) throw apiError(`Choose between 1 and ${max}.`, 400);
			const q = quote(product, body.options || {}, body.quantity);
			const bad = Object.keys(q.errors)[0];
			if (bad) throw apiError(q.errors[bad], 400, { option: bad });
			return q;
		}

		throw apiError('This feature needs the full WordPress site.', 501);
	}

	async function api(path, opts = {}) {
		if (cfg.static) return staticApi(path, opts);
		const { method = 'GET', body } = opts;
		const res = await fetch(cfg.rest + path, {
			method,
			credentials: 'same-origin',
			headers: body ? { 'Content-Type': 'application/json', Accept: 'application/json' } : { Accept: 'application/json' },
			body: body ? JSON.stringify(body) : undefined,
		});
		const data = await res.json().catch(() => ({}));
		if (!res.ok) {
			const err = new Error(data.message || 'Something went wrong. Please try again.');
			err.status = res.status;
			err.data = data.data || {};
			err.code = data.code;
			throw err;
		}
		return data;
	}

	const el = (tag, attrs = {}, ...children) => {
		const node = document.createElement(tag);
		for (const [k, v] of Object.entries(attrs)) {
			if (v === false || v == null) continue;
			if (k === 'class') node.className = v;
			else if (k === 'text') node.textContent = v;
			else if (k.startsWith('on')) node.addEventListener(k.slice(2), v);
			else node.setAttribute(k, v === true ? '' : v);
		}
		node.append(...children.filter((c) => c != null && c !== false));
		return node;
	};

	/* ── Bag store ─────────────────────────────────────────────────────── */

	const KEY = 'tt-bag-v1';
	let memory = [];

	const bag = {
		read() {
			try {
				const v = JSON.parse(localStorage.getItem(KEY) || '[]');
				return Array.isArray(v) ? v : [];
			} catch {
				return memory;
			}
		},
		write(items) {
			memory = items;
			try {
				localStorage.setItem(KEY, JSON.stringify(items));
			} catch {
				/* private mode: keep in memory */
			}
			document.dispatchEvent(new CustomEvent('tt:bag', { detail: items }));
		},
		add(item) {
			const items = bag.read();
			const existing = items.find((i) => i.key === item.key);
			if (existing) {
				existing.quantity = Math.min(existing.quantity + item.quantity, item.maxQty || 10);
				existing.unit = item.unit; // latest server quote wins
			} else {
				items.push(item);
			}
			bag.write(items);
		},
		setQuantity(key, quantity) {
			const items = bag.read();
			const item = items.find((i) => i.key === key);
			if (!item) return;
			item.quantity = Math.max(1, Math.min(quantity, item.maxQty || 10));
			bag.write(items);
		},
		remove(key) {
			bag.write(bag.read().filter((i) => i.key !== key));
		},
		count: () => bag.read().reduce((n, i) => n + i.quantity, 0),
		subtotal: () => bag.read().reduce((n, i) => n + i.unit * i.quantity, 0),
	};

	/* ── Drawer UI ─────────────────────────────────────────────────────── */

	const drawer = document.querySelector('[data-tt-bag-drawer]');

	function renderCount() {
		const n = bag.count();
		document.querySelectorAll('[data-tt-bag-count]').forEach((c) => {
			c.textContent = String(n);
			c.classList.toggle('is-empty', n === 0);
		});
		document.querySelectorAll('[data-tt-bag-sr]').forEach((c) => {
			c.textContent = `${n} ${n === 1 ? 'item' : 'items'}`;
		});
	}

	function renderDrawer() {
		if (!drawer) return;
		const list = drawer.querySelector('[data-tt-bag-items]');
		const foot = drawer.querySelector('[data-tt-bag-foot]');
		const items = bag.read();
		list.replaceChildren();
		foot.hidden = items.length === 0;

		if (!items.length) {
			list.append(
				el('div', { class: 'tt-drawer__empty' },
					el('p', { class: 'tt-drawer__empty-title', text: 'Your bag is empty.' }),
					el('p', { class: 'tt-help', text: 'Every piece is made by hand in small batches.' }),
					el('a', { class: 'tt-button tt-button--ghost', href: cfg.shopUrl || '/shop/', text: 'Browse the shop' })
				)
			);
			return;
		}

		for (const item of items) {
			list.append(
				el('article', { class: 'tt-line' },
					item.image
						? el('span', { class: 'tt-line__thumb tt-line__thumb--photo', 'aria-hidden': 'true' },
							el('img', { src: item.image, alt: '', loading: 'lazy', width: '64', height: '80' }),
							item.tint ? el('i', { style: `--swatch:${item.tint}` }) : null)
						: el('span', { class: 'tt-line__thumb', style: `--art-tint:${item.tint || '#B5583A'}`, 'aria-hidden': 'true', text: item.title.charAt(0) }),
					el('div', { class: 'tt-line__body' },
						el('a', { class: 'tt-line__title', href: item.url, text: item.title }),
						item.summary ? el('p', { class: 'tt-line__summary', text: item.summary }) : null,
						el('div', { class: 'tt-line__row' },
							el('div', { class: 'tt-qty tt-qty--sm', role: 'group', 'aria-label': `Quantity for ${item.title}` },
								el('button', { type: 'button', class: 'tt-qty__btn', 'aria-label': 'Decrease quantity', onclick: () => bag.setQuantity(item.key, item.quantity - 1), disabled: item.quantity <= 1 }, '−'),
								el('span', { class: 'tt-qty__value', text: String(item.quantity) }),
								el('button', { type: 'button', class: 'tt-qty__btn', 'aria-label': 'Increase quantity', onclick: () => bag.setQuantity(item.key, item.quantity + 1), disabled: item.quantity >= (item.maxQty || 10) }, '+')
							),
							el('button', { type: 'button', class: 'tt-link', onclick: () => bag.remove(item.key), text: 'Remove' })
						)
					),
					el('p', { class: 'tt-line__price', text: money(item.unit * item.quantity) })
				)
			);
		}

		drawer.querySelector('[data-tt-bag-subtotal]').textContent = money(bag.subtotal());
	}

	function render() {
		renderCount();
		renderDrawer();
	}

	function openBag() {
		if (!drawer) return;
		renderDrawer();
		drawer.querySelector('[data-tt-checkout-note]').hidden = true;
		if (!drawer.open) drawer.showModal();
	}

	/** Close a <dialog> on backdrop click and via [data-tt-close]. */
	function wireDialog(dialog) {
		dialog.addEventListener('click', (e) => {
			if (e.target === dialog || e.target.closest('[data-tt-close]')) dialog.close();
		});
	}

	if (drawer) {
		wireDialog(drawer);
		drawer.querySelector('[data-tt-checkout]').addEventListener('click', () => {
			drawer.querySelector('[data-tt-checkout-note]').hidden = false;
		});
	}

	document.addEventListener('click', (e) => {
		if (e.target.closest('[data-tt-bag-open]')) openBag();
	});
	document.addEventListener('tt:bag', render);
	window.addEventListener('storage', (e) => e.key === KEY && render());
	render();

	/* ── Light/dark toggle ─────────────────────────────────────────────── */

	// With no choice saved the site follows the system; a choice is stored as "tt-theme" and
	// applied as data-theme on <html> (before paint, by the inline script in functions.php).
	// Flipping back to what the system already shows forgets the choice.
	const THEME_KEY = 'tt-theme';
	const systemDark = window.matchMedia('(prefers-color-scheme: dark)');
	const themeToggle = document.querySelector('[data-tt-theme-toggle]');
	const root = document.documentElement;
	const currentTheme = () =>
		root.dataset.theme === 'light' || root.dataset.theme === 'dark' ? root.dataset.theme : systemDark.matches ? 'dark' : 'light';
	const applyTheme = (theme) => {
		if (theme === 'light' || theme === 'dark') root.dataset.theme = theme;
		else delete root.dataset.theme;
		if (themeToggle) {
			themeToggle.setAttribute('aria-label', currentTheme() === 'dark' ? themeToggle.dataset.labelLight : themeToggle.dataset.labelDark);
		}
	};

	if (themeToggle) {
		themeToggle.addEventListener('click', () => {
			const next = currentTheme() === 'dark' ? 'light' : 'dark';
			const followSystem = next === (systemDark.matches ? 'dark' : 'light');
			try {
				if (followSystem) localStorage.removeItem(THEME_KEY);
				else localStorage.setItem(THEME_KEY, next);
			} catch {
				/* Not remembered; this page still switches. */
			}
			applyTheme(followSystem ? null : next);
		});
		systemDark.addEventListener('change', () => applyTheme(root.dataset.theme));
		window.addEventListener('storage', (e) => e.key === THEME_KEY && applyTheme(e.newValue));
		applyTheme(root.dataset.theme);
		themeToggle.hidden = false;
	}

	window.TT = { money, api, el, bag, openBag, wireDialog, quote, normaliseText, products, isStatic: Boolean(cfg.static) };
})();
