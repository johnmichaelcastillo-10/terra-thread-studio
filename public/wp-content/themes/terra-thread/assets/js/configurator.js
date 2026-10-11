/**
 * Product configurator: live price, engraving preview and add-to-bag.
 *
 * TT.quote() (studio.js) mirrors TerraThread\Core\Catalog\quote() so feedback
 * is instant; on add-to-bag the server re-prices the configuration and its
 * number is the one stored in the bag.
 */
(() => {
	'use strict';

	const { money, api, el, bag, openBag, quote, normaliseText } = window.TT;

	const plus = (n) => (n > 0 ? `+${money(n)}` : '');

	function init(root) {
		const product = JSON.parse(root.querySelector('[data-tt-config]').textContent);
		window.TT.products[product.id] = product; // lets the static-site adapter price it
		const form = root.querySelector('[data-tt-form]');
		const art = root.querySelector('.tt-art');
		const preview = root.querySelector('[data-tt-engraving-preview]');
		const proof = root.querySelector('[data-tt-proof]');
		const finishDot = root.querySelector('[data-tt-finish-dot]');
		const finishName = root.querySelector('[data-tt-finish-name]');
		const engraveInput = root.querySelector('[data-tt-engrave]');
		const qtyInput = form.elements.quantity;
		const submit = root.querySelector('[data-tt-add]');
		const status = root.querySelector('[data-tt-status]');
		const tintOption = product.options.find((o) => o.type === 'swatch');
		const $ = (sel) => root.querySelector(sel);

		const quantity = () => Math.max(1, Math.min(product.maxQty, parseInt(qtyInput.value, 10) || 1));

		function selection() {
			const out = {};
			for (const opt of product.options) {
				const field = form.elements[opt.key];
				if (!field) continue;
				if (opt.type === 'checkbox') out[opt.key] = field.checked;
				else out[opt.key] = opt.type === 'text' ? normaliseText(field.value) : field.value;
			}
			return out;
		}

		function bump(node) {
			node.classList.remove('is-bumped');
			void node.offsetWidth; // restart the animation
			node.classList.add('is-bumped');
		}

		let lastUnit = null;

		function update() {
			const sel = selection();
			const qty = quantity();
			const q = quote(product, sel, qty);

			// Prices.
			const unitNode = $('[data-tt-unit]');
			unitNode.textContent = money(q.unit);
			if (lastUnit !== null && lastUnit !== q.unit) bump(unitNode);
			lastUnit = q.unit;

			const note = $('[data-tt-unit-note]');
			note.hidden = qty < 1 || qty === 1;
			note.textContent = `${qty} × ${money(q.unit)} = ${money(q.total)}`;
			root.querySelectorAll('[data-tt-total]').forEach((n) => (n.textContent = money(q.total)));

			// Breakdown.
			$('[data-tt-lines]').replaceChildren(
				...q.lines.map((l) => el('li', {}, el('span', { text: l.label }), el('span', { text: money(l.amount) }))),
				...(qty > 1 ? [el('li', { class: 'is-total' }, el('span', { text: `× ${qty}` }), el('span', { text: money(q.total) }))] : [])
			);

			for (const opt of product.options) {
				if (opt.type === 'swatch') {
					const choice = opt.choices.find((c) => c.value === sel[opt.key]) || opt.choices[0];
					const legend = $(`[data-tt-value-for="${opt.key}"]`);
					if (legend) legend.textContent = choice.label + (choice.price ? ` (${plus(choice.price)})` : '');
					if (opt === tintOption) {
						if (choice.color && art) art.style.setProperty('--art-tint', choice.color); // SVG fallback
						if (finishDot && choice.color) finishDot.style.setProperty('--swatch', choice.color);
						if (finishName) finishName.textContent = choice.label;
					}
				}

				if (opt.type === 'text') {
					const text = sel[opt.key] || '';
					const chars = text.replace(/ /g, '').length;
					const counter = $(`[data-tt-counter-for="${opt.key}"]`);
					if (counter) counter.textContent = `${form.elements[opt.key].value.length} / ${opt.maxlength}`;
					const priceTag = $(`[data-tt-price-for="${opt.key}"]`);
					if (priceTag) priceTag.textContent = text ? plus(opt.price + chars * opt.price_per_char) : plus(opt.price);

					const error = $(`[data-tt-error-for="${opt.key}"]`);
					const msg = q.errors[opt.key] || '';
					form.elements[opt.key].setAttribute('aria-invalid', msg ? 'true' : 'false');
					if (error) {
						error.hidden = !msg;
						error.textContent = msg;
					}
				}
			}

			// Engraving proof bound to the first text option.
			if (preview && engraveInput) {
				const text = normaliseText(engraveInput.value);
				preview.textContent = text;
				if (proof) proof.hidden = !text;
			}

			submit.disabled = product.stock < 1 || Object.keys(q.errors).length > 0;
			return q;
		}

		form.addEventListener('input', update);
		form.addEventListener('change', update);

		root.querySelectorAll('[data-tt-step]').forEach((btn) =>
			btn.addEventListener('click', () => {
				qtyInput.value = String(Math.max(1, Math.min(product.maxQty, quantity() + Number(btn.dataset.ttStep))));
				update();
			})
		);
		qtyInput.addEventListener('blur', () => {
			qtyInput.value = String(quantity());
			update();
		});

		form.addEventListener('submit', async (e) => {
			e.preventDefault();
			const local = update();
			if (Object.keys(local.errors).length) return;

			const label = submit.innerHTML;
			submit.disabled = true;
			submit.classList.add('is-busy');
			submit.textContent = 'Adding…';
			status.textContent = '';
			status.classList.remove('is-error');

			try {
				const sel = selection();
				const server = await api(`products/${product.id}/quote`, {
					method: 'POST',
					body: { options: sel, quantity: quantity() },
				});

				bag.add({
					key: `${product.id}:${JSON.stringify(server.selection)}`,
					id: product.id,
					title: product.title,
					url: product.url,
					image: product.image,
					tint: finishDot ? finishDot.style.getPropertyValue('--swatch').trim() : art ? getComputedStyle(art).getPropertyValue('--art-tint').trim() : '',
					summary: local.summary,
					selection: server.selection,
					unit: server.unit,
					quantity: server.quantity,
					maxQty: product.maxQty,
				});

				status.textContent = `Added to your bag — ${money(server.total)}.`;
				openBag();
			} catch (err) {
				status.textContent = err.message;
				status.classList.add('is-error');
				if (err.data && err.data.option && form.elements[err.data.option]) form.elements[err.data.option].focus();
			} finally {
				submit.innerHTML = label;
				submit.classList.remove('is-busy');
				update();
			}
		});

		update();
	}

	document.querySelectorAll('[data-tt-configurator]').forEach(init);
})();
