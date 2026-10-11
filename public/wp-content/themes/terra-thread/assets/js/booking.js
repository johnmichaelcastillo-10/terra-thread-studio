/**
 * Workshop booking modal: month calendar → time slot → seats & details →
 * confirmation (with a downloadable .ics). Availability is fetched fresh each
 * time the modal opens; the server re-checks capacity on submit.
 */
(() => {
	'use strict';

	const { money, api, el, wireDialog, isStatic } = window.TT;
	const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
	const monthFmt = new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric' });
	const dayFmt = new Intl.DateTimeFormat(undefined, { weekday: 'long', month: 'long', day: 'numeric' });

	const pad = (n) => String(n).padStart(2, '0');
	const ymd = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
	const parseYmd = (s) => {
		const [y, m, d] = s.split('-').map(Number);
		return new Date(y, m - 1, d);
	};

	const state = {
		workshop: null,
		sessions: [],
		maxSeats: 4,
		month: null, // Date at the 1st of the visible month
		date: null, // 'Y-m-d'
		session: null,
		seats: 1,
		booked: false,
	};

	let dialog;
	const refs = {};

	/* ── Markup ─────────────────────────────────────────────────────────── */

	function build() {
		dialog = el('dialog', { class: 'tt-modal', 'aria-labelledby': 'tt-book-title' });
		dialog.innerHTML = `
			<div class="tt-modal__inner">
				<header class="tt-modal__head">
					<div>
						<p class="tt-eyebrow">Reserve a seat</p>
						<h2 id="tt-book-title" class="tt-modal__title" data-ref="title">Loading…</h2>
						<p class="tt-modal__meta" data-ref="meta"></p>
					</div>
					<button type="button" class="tt-icon-btn" data-tt-close aria-label="Close booking">×</button>
				</header>

				<div class="tt-modal__body" data-ref="pick">
					<section class="tt-cal" aria-label="Choose a date">
						<div class="tt-cal__head">
							<button type="button" class="tt-icon-btn" data-ref="prev" aria-label="Previous month">‹</button>
							<h3 class="tt-cal__month" data-ref="month" aria-live="polite"></h3>
							<button type="button" class="tt-icon-btn" data-ref="next" aria-label="Next month">›</button>
						</div>
						<div class="tt-cal__grid" data-ref="grid"></div>
						<ul class="tt-cal__legend" aria-hidden="true">
							<li><i class="is-open"></i>Available</li>
							<li><i class="is-low"></i>Last seats</li>
							<li><i class="is-full"></i>Full</li>
						</ul>
					</section>

					<section class="tt-book" aria-live="polite">
						<h3 class="tt-section-label" data-ref="dayLabel">Choose a date</h3>
						<div class="tt-slots" data-ref="slots" role="radiogroup" aria-label="Available times"></div>

						<form class="tt-book__form" data-ref="form" novalidate hidden>
							<div class="tt-field tt-field--inline">
								<span class="tt-field__label" id="tt-seats-label">Seats</span>
								<div class="tt-qty" role="group" aria-labelledby="tt-seats-label">
									<button type="button" class="tt-qty__btn" data-ref="minus" aria-label="Fewer seats">−</button>
									<output class="tt-qty__value" data-ref="seats">1</output>
									<button type="button" class="tt-qty__btn" data-ref="plus" aria-label="More seats">+</button>
								</div>
							</div>
							<div class="tt-field">
								<label class="tt-field__label" for="tt-book-name">Your name</label>
								<input id="tt-book-name" name="name" type="text" autocomplete="name" required minlength="2" maxlength="120">
							</div>
							<div class="tt-field">
								<label class="tt-field__label" for="tt-book-email">Email</label>
								<input id="tt-book-email" name="email" type="email" autocomplete="email" required>
							</div>
							<div class="tt-hp" aria-hidden="true">
								<label for="tt-book-website">Website</label>
								<input id="tt-book-website" name="website" type="text" tabindex="-1" autocomplete="off">
							</div>
							<p class="tt-book__summary"><span data-ref="summary"></span><strong data-ref="total"></strong></p>
							<button type="submit" class="tt-button tt-button--primary tt-button--block" data-ref="submit">Reserve</button>
							<p class="tt-form-status" role="status" data-ref="status"></p>
						</form>
					</section>
				</div>

				<div class="tt-modal__body tt-confirm" data-ref="done" hidden>
					<div class="tt-confirm__mark" aria-hidden="true">✓</div>
					<h3 class="tt-confirm__title">You’re booked.</h3>
					<p data-ref="doneText"></p>
					<p class="tt-confirm__ref">Reference <code data-ref="reference"></code></p>
					<p class="tt-confirm__demo" data-ref="demoNote" hidden>This is a portfolio demo: your reservation is saved in this browser only, and no email is sent.</p>
					<div class="tt-confirm__actions">
						<a class="tt-button tt-button--ghost" data-ref="ics" download="terra-thread-workshop.ics">Add to calendar</a>
						<button type="button" class="tt-button tt-button--primary" data-tt-close>Done</button>
					</div>
				</div>
			</div>`;

		dialog.querySelectorAll('[data-ref]').forEach((n) => (refs[n.dataset.ref] = n));
		document.body.append(dialog);
		wireDialog(dialog);

		refs.prev.addEventListener('click', () => shiftMonth(-1));
		refs.next.addEventListener('click', () => shiftMonth(1));
		refs.minus.addEventListener('click', () => setSeats(state.seats - 1));
		refs.plus.addEventListener('click', () => setSeats(state.seats + 1));
		refs.form.addEventListener('submit', submit);
		refs.grid.addEventListener('keydown', gridKeys);
		dialog.addEventListener('close', () => {
			// Refresh seat counts on the page (the static export's counts are baked into the HTML).
			if (state.booked && !isStatic) window.location.reload();
		});
	}

	/* ── Calendar ───────────────────────────────────────────────────────── */

	const sessionsOn = (date) => state.sessions.filter((s) => s.date === date);

	function dayStatus(date) {
		const list = sessionsOn(date);
		if (!list.length) return null;
		if (list.every((s) => s.sold_out)) return 'full';
		return list.some((s) => !s.sold_out && s.seats_left > 2) ? 'open' : 'low';
	}

	function monthBounds() {
		const first = state.sessions.length ? parseYmd(state.sessions[0].date) : new Date();
		const last = state.sessions.length ? parseYmd(state.sessions[state.sessions.length - 1].date) : new Date();
		return [new Date(first.getFullYear(), first.getMonth(), 1), new Date(last.getFullYear(), last.getMonth(), 1)];
	}

	function shiftMonth(delta) {
		const [min, max] = monthBounds();
		const next = new Date(state.month.getFullYear(), state.month.getMonth() + delta, 1);
		if (next < min || next > max) return;
		state.month = next;
		renderCalendar();
	}

	function renderCalendar() {
		const [min, max] = monthBounds();
		const y = state.month.getFullYear();
		const m = state.month.getMonth();
		const today = ymd(new Date());

		refs.month.textContent = monthFmt.format(state.month);
		refs.prev.disabled = state.month <= min;
		refs.next.disabled = state.month >= max;

		const cells = WEEKDAYS.map((d) => el('span', { class: 'tt-cal__dow', 'aria-hidden': 'true', text: d }));
		const offset = (new Date(y, m, 1).getDay() + 6) % 7; // Monday first
		for (let i = 0; i < offset; i++) cells.push(el('span', { class: 'tt-cal__pad' }));

		const daysInMonth = new Date(y, m + 1, 0).getDate();
		for (let d = 1; d <= daysInMonth; d++) {
			const date = ymd(new Date(y, m, d));
			const status = dayStatus(date);

			if (!status) {
				cells.push(el('span', { class: `tt-cal__day${date < today ? ' is-past' : ''}${date === today ? ' is-today' : ''}`, text: String(d) }));
				continue;
			}

			const label = `${dayFmt.format(new Date(y, m, d))}: ${status === 'full' ? 'fully booked' : status === 'low' ? 'last seats' : 'seats available'}`;
			cells.push(
				el('button', {
					type: 'button',
					class: `tt-cal__day has-session is-${status}${date === state.date ? ' is-selected' : ''}`,
					'data-date': date,
					'aria-pressed': date === state.date ? 'true' : 'false',
					'aria-label': label,
					disabled: status === 'full',
					onclick: () => selectDate(date),
				}, el('span', { text: String(d) }), el('i', { 'aria-hidden': 'true' }))
			);
		}

		refs.grid.replaceChildren(...cells);
	}

	/** Arrow keys move between session days in the grid. */
	function gridKeys(e) {
		if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(e.key)) return;
		const days = [...refs.grid.querySelectorAll('button:not([disabled])')];
		const i = days.indexOf(document.activeElement);
		if (i < 0) return;
		e.preventDefault();
		const next = days[Math.max(0, Math.min(days.length - 1, i + (e.key === 'ArrowLeft' || e.key === 'ArrowUp' ? -1 : 1)))];
		next.focus();
	}

	function selectDate(date) {
		state.date = date;
		const open = sessionsOn(date).filter((s) => !s.sold_out);
		state.session = open.length === 1 ? open[0] : open.find((s) => s.id === state.session?.id) || null;
		renderCalendar();
		renderSlots();
	}

	/* ── Slots & form ───────────────────────────────────────────────────── */

	function renderSlots() {
		const list = state.date ? sessionsOn(state.date) : [];
		refs.dayLabel.textContent = state.date ? dayFmt.format(parseYmd(state.date)) : 'Choose a date';

		refs.slots.replaceChildren(
			...list.map((s) =>
				el('button', {
					type: 'button',
					role: 'radio',
					class: `tt-slot${state.session?.id === s.id ? ' is-selected' : ''}`,
					'aria-checked': state.session?.id === s.id ? 'true' : 'false',
					disabled: s.sold_out,
					onclick: () => {
						state.session = s;
						renderSlots();
					},
				},
				el('span', { class: 'tt-slot__time', text: s.label_time }),
				el('span', { class: `tt-slot__seats${s.seats_left <= 2 ? ' is-low' : ''}`, text: s.sold_out ? 'Full' : `${s.seats_left} of ${s.capacity} seats left` }))
			)
		);

		if (!list.length) {
			refs.slots.append(el('p', { class: 'tt-help', text: 'Highlighted days have sessions. Pick one to see times.' }));
		}

		refs.form.hidden = !state.session;
		if (state.session) setSeats(state.seats);
	}

	function maxSeats() {
		return state.session ? Math.min(state.maxSeats, state.session.seats_left) : 1;
	}

	function setSeats(n) {
		state.seats = Math.max(1, Math.min(maxSeats(), n));
		refs.seats.textContent = String(state.seats);
		refs.minus.disabled = state.seats <= 1;
		refs.plus.disabled = state.seats >= maxSeats();
		refs.summary.textContent = `${state.seats} × ${money(state.workshop.price)}`;
		refs.total.textContent = money(state.seats * state.workshop.price);
		refs.submit.textContent = `Reserve ${state.seats} ${state.seats === 1 ? 'seat' : 'seats'}`;
	}

	async function submit(e) {
		e.preventDefault();
		const form = refs.form;
		if (!form.reportValidity()) return;

		refs.submit.disabled = true;
		refs.submit.classList.add('is-busy');
		refs.status.textContent = '';
		refs.status.classList.remove('is-error');

		try {
			const result = await api('bookings', {
				method: 'POST',
				body: {
					session_id: state.session.id,
					seats: state.seats,
					name: form.elements.name.value.trim(),
					email: form.elements.email.value.trim(),
					website: form.elements.website.value,
				},
			});
			state.booked = true;
			showConfirmation(result, form.elements.name.value.trim());
		} catch (err) {
			refs.status.textContent = err.message;
			refs.status.classList.add('is-error');
			if (err.status === 409 || err.status === 404) {
				await load(state.workshop.id, state.session?.id, true); // someone else got there first
			}
		} finally {
			refs.submit.disabled = false;
			refs.submit.classList.remove('is-busy');
		}
	}

	function showConfirmation(result, name) {
		refs.pick.hidden = true;
		refs.done.hidden = false;
		const s = result.session;
		refs.doneText.textContent = `${name.split(' ')[0]}, we’ve saved ${result.seats} ${result.seats === 1 ? 'seat' : 'seats'} for you at ${state.workshop.title} on ${s.label_date}, ${s.label_time}. A confirmation is on its way to your inbox.`;
		refs.reference.textContent = result.reference;
		refs.demoNote.hidden = !result.demo;
		if (result.demo) refs.doneText.textContent = refs.doneText.textContent.replace(' A confirmation is on its way to your inbox.', '');
		refs.ics.href = icsUrl(s, result.reference);
		refs.done.querySelector('[data-tt-close].tt-button').focus();
	}

	function icsUrl(session, reference) {
		const start = new Date(session.iso);
		const end = new Date(start.getTime() + state.workshop.duration * 60000);
		const stamp = (d) => d.toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, '');
		const ics = [
			'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Terra & Thread//Workshops//EN', 'BEGIN:VEVENT',
			`UID:${reference}@terrathread`, `DTSTAMP:${stamp(new Date())}`, `DTSTART:${stamp(start)}`, `DTEND:${stamp(end)}`,
			`SUMMARY:${state.workshop.title} — Terra & Thread`,
			`LOCATION:${state.workshop.location}`,
			`DESCRIPTION:Instructor: ${state.workshop.instructor}\\nReference: ${reference}`,
			'END:VEVENT', 'END:VCALENDAR',
		].join('\r\n');
		return URL.createObjectURL(new Blob([ics], { type: 'text/calendar' }));
	}

	/* ── Lifecycle ──────────────────────────────────────────────────────── */

	async function load(workshopId, sessionId, keepStatus = false) {
		const data = await api(`workshops/${workshopId}/sessions`);
		state.workshop = data.workshop;
		state.sessions = data.sessions;
		state.maxSeats = data.max_seats_per_booking;

		refs.title.textContent = data.workshop.title;
		refs.meta.textContent = `with ${data.workshop.instructor} · ${data.workshop.location} · ${money(data.workshop.price)} per seat`;

		const wanted = state.sessions.find((s) => s.id === sessionId && !s.sold_out) || state.sessions.find((s) => !s.sold_out);
		state.session = wanted || null;
		state.date = wanted ? wanted.date : null;
		const anchor = wanted ? parseYmd(wanted.date) : new Date();
		state.month = new Date(anchor.getFullYear(), anchor.getMonth(), 1);

		if (!keepStatus) refs.status.textContent = '';
		renderCalendar();
		renderSlots();
	}

	async function open(workshopId, sessionId) {
		if (!dialog) build();
		Object.assign(state, { seats: 1, booked: false });
		refs.pick.hidden = false;
		refs.done.hidden = true;
		refs.title.textContent = 'Loading…';
		refs.meta.textContent = '';
		refs.grid.replaceChildren();
		refs.slots.replaceChildren();
		refs.form.hidden = true;
		dialog.showModal();

		try {
			await load(workshopId, sessionId);
			const focusTarget = refs.grid.querySelector('.is-selected') || refs.grid.querySelector('button:not([disabled])');
			focusTarget?.focus();
		} catch (err) {
			refs.title.textContent = 'Booking is unavailable';
			refs.meta.textContent = err.message;
		}
	}

	document.addEventListener('click', (e) => {
		const trigger = e.target.closest('[data-tt-book]');
		if (!trigger || trigger.disabled) return;
		open(Number(trigger.dataset.ttBook), trigger.dataset.ttSession ? Number(trigger.dataset.ttSession) : null);
	});
})();
