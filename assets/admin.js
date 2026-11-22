/**
 * CTA Tracking admin: the chart dashboard (bar, line, heat map drawn as SVG, no library) and the settings form helpers.
 * Each chart is a saved {title, type, x, s, days, ctas, zones, devices}; its data comes from /report as
 * {labels, series:[{name, data}], total}.
 */
(function () {
	'use strict';

	var A = window.ctaTrackingAdmin || {};
	var NS = 'http://www.w3.org/2000/svg';
	// Categorical slots in fixed order (validated palette); a colour follows the entity, never its rank.
	var PALETTE = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'];
	var TYPES = { bar: 'میله‌ای', line: 'خطی', heatmap: 'حرارتی' };
	var DAYS = { 1: 'امروز', 7: '۷ روز', 30: '۳۰ روز', 90: '۹۰ روز', 180: '۶ ماه', 365: 'یک سال' };
	var W = 720;
	var H = 340;

	settingsForm();
	var root = document.getElementById('cta-dashboard');
	if (!root) {
		return;
	}

	var charts = (A.charts || []).slice();
	var colors = {}; // entity name → slot, shared by every chart on the page
	Object.keys(A.ctas || {}).forEach(function (k, i) {
		colors[A.ctas[k] || k] = i % PALETTE.length;
	});
	var nextSlot = Object.keys(colors).length;
	function colorOf(name) {
		if (!(name in colors)) {
			colors[name] = nextSlot++ % PALETTE.length;
		}
		return name === 'سایر' ? '#9b9a95' : PALETTE[colors[name]];
	}

	var tip = el('div', { class: 'cta-tip', hidden: '' });
	document.body.appendChild(tip);
	document.addEventListener('mousemove', function (e) {
		var t = e.target.closest && e.target.closest('[data-tip]');
		if (!t) {
			tip.hidden = true;
			return;
		}
		tip.innerHTML = t.getAttribute('data-tip');
		tip.hidden = false;
		tip.style.left = Math.min(e.clientX + 14, window.innerWidth - tip.offsetWidth - 8) + 'px';
		tip.style.top = e.clientY + 14 + 'px';
	});

	render();

	// ---- dashboard -------------------------------------------------------------------------------------------------

	function render() {
		root.innerHTML = '';
		var bar = el('div', { class: 'cta-toolbar' });
		bar.appendChild(button('+ نمودار جدید', 'button button-primary', function () {
			edit(null);
		}));
		root.appendChild(bar);
		var grid = el('div', { class: 'cta-grid' });
		root.appendChild(grid);
		charts.forEach(function (c) {
			grid.appendChild(card(c));
		});
	}

	function card(c) {
		var box = el('section', { class: 'cta-card' });
		var head = el('header', { class: 'cta-card-head' });
		var titles = el('div');
		titles.appendChild(el('h2', {}, c.title || A.dims[c.x]));
		titles.appendChild(el('p', { class: 'cta-sub' }, subtitle(c)));
		head.appendChild(titles);
		var tools = el('div', { class: 'cta-tools' });
		Object.keys(TYPES).forEach(function (t) {
			var b = button(TYPES[t], 'cta-type' + (c.type === t ? ' is-on' : ''), function () {
				c.type = t;
				save();
			});
			b.setAttribute('aria-pressed', c.type === t ? 'true' : 'false');
			tools.appendChild(b);
		});
		tools.appendChild(button('ویرایش', 'button-link', function () {
			edit(c);
		}));
		tools.appendChild(button('حذف', 'button-link cta-del', function () {
			if (window.confirm('این نمودار حذف شود؟')) {
				charts = charts.filter(function (x) {
					return x !== c;
				});
				save();
			}
		}));
		head.appendChild(tools);
		box.appendChild(head);
		var body = el('div', { class: 'cta-body' }, 'در حال بارگذاری…');
		box.appendChild(body);
		load(c).then(function (data) {
			body.innerHTML = '';
			draw(body, c, data);
		}).catch(function (e) {
			body.textContent = 'خطا در دریافت داده: ' + e.message;
		});
		return box;
	}

	function subtitle(c) {
		var parts = [A.dims[c.x] + (c.s ? ' به تفکیک ' + A.dims[c.s] : ''), DAYS[c.days] || c.days + ' روز'];
		var f = [].concat(names(c.ctas, A.ctas), names(c.zones, A.zones), names(c.devices, A.devices));
		if (f.length) {
			parts.push('فقط: ' + f.join('، '));
		}
		return parts.join(' · ');
	}

	function names(keys, map) {
		return (keys || []).map(function (k) {
			return (map && map[k]) || k;
		});
	}

	function load(c) {
		var q = new URLSearchParams({ x: c.x, s: c.s || '', days: c.days, ctas: (c.ctas || []).join(','), zones: (c.zones || []).join(','), devices: (c.devices || []).join(',') });
		return api('report?' + q.toString());
	}

	function api(path, body) {
		return fetch(A.root + path, {
			method: body ? 'POST' : 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': A.nonce, 'Content-Type': 'application/json' },
			body: body ? JSON.stringify(body) : undefined
		}).then(function (r) {
			if (!r.ok) {
				throw new Error('HTTP ' + r.status);
			}
			return r.json();
		});
	}

	function save() {
		api('charts', { charts: charts }).then(function (saved) {
			charts = saved;
			render();
		}).catch(function (e) {
			window.alert('ذخیره نشد: ' + e.message);
		});
	}

	// ---- editor ----------------------------------------------------------------------------------------------------

	function edit(c) {
		var isNew = !c;
		c = c || { id: '', title: '', type: 'bar', x: 'cta', s: '', days: 30, ctas: [], zones: [], devices: [] };
		var form = el('form', { class: 'cta-card cta-editor' });
		form.appendChild(el('h2', {}, isNew ? 'نمودار جدید' : 'ویرایش نمودار'));
		form.appendChild(field('عنوان', input('title', c.title)));
		form.appendChild(field('نوع نمودار', select('type', TYPES, c.type)));
		form.appendChild(field('محور افقی (چه چیزی شمرده شود)', select('x', A.dims, c.x)));
		var sOpts = { '': '— بدون تفکیک —' };
		Object.keys(A.dims).forEach(function (k) {
			sOpts[k] = A.dims[k];
		});
		form.appendChild(field('تفکیک سری‌ها (رنگ‌ها)', select('s', sOpts, c.s || '')));
		form.appendChild(field('بازهٔ زمانی', select('days', DAYS, String(c.days))));
		form.appendChild(field('فقط این دکمه‌ها (خالی = همه)', checks('ctas', A.ctas, c.ctas)));
		form.appendChild(field('فقط این ناحیه‌ها', checks('zones', A.zones, c.zones)));
		form.appendChild(field('فقط این دستگاه‌ها', checks('devices', A.devices, c.devices)));
		var actions = el('p');
		actions.appendChild(el('button', { type: 'submit', class: 'button button-primary' }, 'ذخیره'));
		actions.appendChild(button('انصراف', 'button', render));
		form.appendChild(actions);
		// A heat map reads best as hours across, weekdays down; preset that, the user can still change it.
		form.type.addEventListener('change', function () {
			if (form.type.value === 'heatmap') {
				form.x.value = 'hour';
				form.s.value = 'dow';
			}
		});
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var d = new FormData(form);
			var out = {
				id: c.id,
				title: d.get('title'),
				type: d.get('type'),
				x: d.get('x'),
				s: d.get('s'),
				days: +d.get('days'),
				ctas: d.getAll('ctas'),
				zones: d.getAll('zones'),
				devices: d.getAll('devices')
			};
			if (isNew) {
				charts.push(out);
			} else {
				charts[charts.indexOf(c)] = out;
			}
			save();
		});
		root.innerHTML = '';
		root.appendChild(form);
	}

	function field(label, control) {
		var p = el('div', { class: 'cta-field' });
		p.appendChild(el('label', {}, label));
		p.appendChild(control);
		return p;
	}

	function input(name, value) {
		return el('input', { name: name, value: value || '', class: 'regular-text' });
	}

	function select(name, opts, value) {
		var s = el('select', { name: name });
		Object.keys(opts).forEach(function (k) {
			var o = el('option', { value: k }, opts[k]);
			o.selected = k === value;
			s.appendChild(o);
		});
		return s;
	}

	function checks(name, opts, values) {
		var box = el('div', { class: 'cta-checks' });
		Object.keys(opts || {}).forEach(function (k) {
			var l = el('label');
			var i = el('input', { type: 'checkbox', name: name, value: k });
			i.checked = (values || []).indexOf(k) !== -1;
			l.appendChild(i);
			l.appendChild(document.createTextNode(' ' + (opts[k] || k)));
			box.appendChild(l);
		});
		return box;
	}

	// ---- charts ----------------------------------------------------------------------------------------------------

	function draw(body, c, data) {
		if (!data.total) {
			body.appendChild(el('p', { class: 'cta-empty' }, 'در این بازه کلیکی ثبت نشده است.'));
			return;
		}
		if (c.type === 'heatmap') {
			heatmap(body, data);
		} else {
			if (data.series.length > 1) {
				var legend = el('ul', { class: 'cta-legend' });
				data.series.forEach(function (s) {
					var li = el('li');
					li.appendChild(el('span', { class: 'cta-dot', style: 'background:' + colorOf(s.name) }));
					li.appendChild(document.createTextNode(s.name));
					legend.appendChild(li);
				});
				body.appendChild(legend);
			}
			var svg = svgEl('svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'cta-svg', role: 'img', 'aria-label': c.title || '' });
			(c.type === 'line' ? lineChart : barChart)(svg, data, c);
			body.appendChild(svg);
		}
		body.appendChild(el('p', { class: 'cta-total' }, 'مجموع: ' + fmt(data.total) + ' کلیک'));
		body.appendChild(table(data));
	}

	/** The same numbers as a table, for reading exact values and for screen readers. */
	function table(data) {
		var d = el('details', { class: 'cta-table' });
		d.appendChild(el('summary', {}, 'جدول داده'));
		var t = el('table', { class: 'widefat striped' });
		var tr = el('tr');
		tr.appendChild(el('th'));
		data.series.forEach(function (s) {
			tr.appendChild(el('th', {}, s.name));
		});
		t.appendChild(tr);
		data.labels.forEach(function (label, i) {
			var row = el('tr');
			row.appendChild(el('th', {}, label));
			data.series.forEach(function (s) {
				row.appendChild(el('td', {}, fmt(s.data[i])));
			});
			t.appendChild(row);
		});
		d.appendChild(t);
		return d;
	}

	var M = { top: 24, right: 12, bottom: 78, left: 44 };

	function yAxis(svg, max) {
		var ticks = niceTicks(max);
		var top = ticks[ticks.length - 1];
		var y = function (v) {
			return M.top + (H - M.top - M.bottom) * (1 - v / top);
		};
		ticks.forEach(function (t) {
			svg.appendChild(svgEl('line', { x1: M.left, x2: W - M.right, y1: y(t), y2: y(t), class: t ? 'cta-grid' : 'cta-base' }));
			svg.appendChild(svgEl('text', { x: M.left - 8, y: y(t) + 4, class: 'cta-tick', 'text-anchor': 'end' }, fmt(t)));
		});
		return y;
	}

	/** Persian labels run right-to-left so mixed text and brackets keep their order; «start» is then the right edge. */
	function xLabel(svg, x, text, every, i) {
		if (i % every) {
			return;
		}
		var y = H - M.bottom + 14;
		svg.appendChild(svgEl('text', { x: x, y: y, class: 'cta-tick', direction: 'rtl', 'text-anchor': 'start', transform: 'rotate(-40 ' + x + ' ' + y + ')' }, short(text, 18)));
	}

	function barChart(svg, data, c) {
		var n = data.labels.length;
		var stacks = data.labels.map(function (_, i) {
			return data.series.reduce(function (a, s) {
				return a + s.data[i];
			}, 0);
		});
		var y = yAxis(svg, Math.max.apply(null, stacks));
		var slot = (W - M.left - M.right) / n;
		var bw = Math.max(4, Math.min(48, slot * 0.72));
		var every = Math.ceil(n / 31);
		// One series of entities (CTAs, groups): each bar wears its entity's colour, as on artificialanalysis.ai.
		var perBar = data.series.length === 1 && (c.x === 'cta' || c.x === 'group' || c.x === 'context');
		data.labels.forEach(function (label, i) {
			var x0 = M.left + slot * i + (slot - bw) / 2;
			var base = 0;
			data.series.forEach(function (s, si) {
				var v = s.data[i];
				if (!v) {
					return;
				}
				var h = y(base) - y(base + v);
				var top = si === data.series.length - 1 || !data.series.slice(si + 1).some(function (r) {
					return r.data[i];
				});
				svg.appendChild(svgEl('path', {
					d: barPath(x0, y(base + v), bw, Math.max(0, h - (base ? 2 : 0)), top),
					fill: perBar ? colorOf(label) : colorOf(s.name),
					'data-tip': esc(label) + (data.series.length > 1 ? ' · ' + esc(s.name) : '') + ': <b>' + fmt(v) + '</b>'
				}));
				base += v;
			});
			if (stacks[i] && bw >= 18) {
				svg.appendChild(svgEl('text', { x: x0 + bw / 2, y: y(stacks[i]) - 6, class: 'cta-val', 'text-anchor': 'middle' }, fmt(stacks[i])));
			}
			xLabel(svg, x0 + bw / 2 + 4, label, every, i);
		});
	}

	/** A bar with 4px rounded top corners (only on the top segment of a stack). */
	function barPath(x, y, w, h, round) {
		var r = round ? Math.min(4, w / 2, h) : 0;
		return 'M' + x + ',' + (y + h) + 'V' + (y + r) + 'Q' + x + ',' + y + ' ' + (x + r) + ',' + y + 'H' + (x + w - r) + 'Q' + (x + w) + ',' + y + ' ' + (x + w) + ',' + (y + r) + 'V' + (y + h) + 'Z';
	}

	function lineChart(svg, data) {
		var n = data.labels.length;
		var max = Math.max.apply(null, data.series.map(function (s) {
			return Math.max.apply(null, s.data);
		}));
		var y = yAxis(svg, max);
		var step = n > 1 ? (W - M.left - M.right - 20) / (n - 1) : 0;
		var x = function (i) {
			return M.left + 10 + step * i;
		};
		var every = Math.ceil(n / 16);
		data.series.forEach(function (s) {
			svg.appendChild(svgEl('polyline', {
				points: s.data.map(function (v, i) {
					return x(i) + ',' + y(v);
				}).join(' '),
				fill: 'none',
				stroke: colorOf(s.name),
				'stroke-width': 2,
				'stroke-linejoin': 'round'
			}));
			if (n <= 31) {
				s.data.forEach(function (v, i) {
					svg.appendChild(svgEl('circle', { cx: x(i), cy: y(v), r: 4, fill: colorOf(s.name), class: 'cta-point' }));
				});
			}
		});
		// Hover columns: one tooltip with every series at that point.
		data.labels.forEach(function (label, i) {
			xLabel(svg, x(i) + 4, label, every, i);
			var rows = data.series.map(function (s) {
				return '<span style="color:' + colorOf(s.name) + '">●</span> ' + esc(s.name) + ': <b>' + fmt(s.data[i]) + '</b>';
			});
			svg.appendChild(svgEl('rect', {
				x: x(i) - Math.max(step, 8) / 2, y: M.top, width: Math.max(step, 8), height: H - M.top - M.bottom,
				class: 'cta-hit', 'data-tip': '<b>' + esc(label) + '</b><br>' + rows.join('<br>')
			}));
		});
	}

	/**
	 * Heat map: one row per series (weekdays from Saturday when split by weekday), one column per label (hours).
	 * One hue from light to dark, so the darkest cell is the busiest hour; exact numbers are in the tooltip and table.
	 */
	function heatmap(body, data) {
		var rows = data.series.length;
		var cols = data.labels.length;
		var left = 72;
		var top = 6;
		var cw = (W - left - 4) / cols;
		var ch = Math.min(34, Math.max(20, cw));
		var h = top + rows * ch + 24;
		var max = Math.max.apply(null, data.series.map(function (s) {
			return Math.max.apply(null, s.data);
		}));
		var svg = svgEl('svg', { viewBox: '0 0 ' + W + ' ' + h, class: 'cta-svg', role: 'img' });
		data.series.forEach(function (s, r) {
			var y = top + r * ch;
			svg.appendChild(svgEl('text', { x: left - 8, y: y + ch / 2 + 4, class: 'cta-tick', direction: 'rtl', 'text-anchor': 'start' }, short(s.name, 12)));
			s.data.forEach(function (v, i) {
				svg.appendChild(svgEl('rect', {
					x: left + i * cw + 1, y: y + 1, width: Math.max(1, cw - 2), height: ch - 2, rx: 3,
					fill: heat(max ? v / max : 0),
					'data-tip': esc(s.name) + ' · ' + esc(data.labels[i]) + ': <b>' + fmt(v) + '</b>'
				}));
			});
		});
		var every = Math.ceil(cols / 24);
		data.labels.forEach(function (label, i) {
			if (!(i % every)) {
				svg.appendChild(svgEl('text', { x: left + i * cw + cw / 2, y: top + rows * ch + 16, class: 'cta-tick', 'text-anchor': 'middle' }, short(label, 8)));
			}
		});
		body.appendChild(svg);
		var scale = el('div', { class: 'cta-scale' });
		scale.appendChild(el('span', {}, fmt(0)));
		scale.appendChild(el('i', { style: 'background:linear-gradient(to right,' + heat(0.02) + ',' + heat(1) + ')' }));
		scale.appendChild(el('span', {}, fmt(max) + ' کلیک'));
		body.appendChild(scale);
	}

	/** Sequential blue, light to dark; zero is a neutral grey so "none" reads apart from "few". */
	function heat(t) {
		if (!t) {
			return '#efeeea';
		}
		var a = [227, 238, 251];
		var b = [13, 59, 120];
		return 'rgb(' + a.map(function (c, i) {
			return Math.round(c + (b[i] - c) * t);
		}).join(',') + ')';
	}

	/** 0 … a round maximum in 4-5 steps. */
	function niceTicks(max) {
		if (!max) {
			return [0, 1];
		}
		var raw = max / 4;
		var pow = Math.pow(10, Math.floor(Math.log10(raw)));
		var step = [1, 2, 2.5, 5, 10].map(function (m) {
			return m * pow;
		}).filter(function (s) {
			return s >= raw;
		})[0];
		step = Math.max(1, step);
		var out = [];
		for (var v = 0; v < max + step; v += step) {
			out.push(Math.round(v * 100) / 100);
			if (v >= max) {
				break;
			}
		}
		return out;
	}

	// ---- settings form ---------------------------------------------------------------------------------------------

	/** Adds and removes rows, and refuses to save a selector or regex the browser cannot parse. */
	function settingsForm() {
		var form = document.getElementById('cta-settings');
		if (!form) {
			return;
		}
		form.addEventListener('click', function (e) {
			if (e.target.classList.contains('cta-remove')) {
				e.target.closest('tr').remove();
			}
			if (e.target.classList.contains('cta-add')) {
				var body = form.querySelector('table[data-list="' + e.target.getAttribute('data-list') + '"] tbody');
				var row = body.lastElementChild.cloneNode(true);
				var i = Date.now();
				row.querySelectorAll('input, textarea').forEach(function (f) {
					f.name = f.name.replace(/\[\d+\]/, '[' + i + ']');
					f.value = f.type === 'number' ? '10' : '';
				});
				body.appendChild(row);
			}
		});
		form.addEventListener('submit', function (e) {
			var bad = null;
			form.querySelectorAll('.cta-selector, .cta-regex').forEach(function (f) {
				f.setCustomValidity('');
				if (!f.value) {
					return;
				}
				try {
					if (f.classList.contains('cta-regex')) {
						new RegExp(f.value);
					} else {
						document.querySelector(f.value);
					}
				} catch (err) {
					f.setCustomValidity('نامعتبر است: ' + err.message);
					bad = bad || f;
				}
			});
			if (bad) {
				e.preventDefault();
				bad.reportValidity();
			}
		});
	}

	// ---- helpers ---------------------------------------------------------------------------------------------------

	function el(tag, attrs, text) {
		var n = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			n.setAttribute(k, attrs[k]);
		});
		if (text !== undefined) {
			n.textContent = text;
		}
		return n;
	}

	function svgEl(tag, attrs, text) {
		var n = document.createElementNS(NS, tag);
		Object.keys(attrs || {}).forEach(function (k) {
			n.setAttribute(k, attrs[k]);
		});
		if (text !== undefined) {
			n.textContent = text;
		}
		return n;
	}

	function button(text, cls, onClick) {
		var b = el('button', { type: 'button', class: cls }, text);
		b.addEventListener('click', onClick);
		return b;
	}

	function fmt(n) {
		return Number(n || 0).toLocaleString('fa-IR');
	}

	function short(s, max) {
		s = String(s);
		return s.length > max ? s.slice(0, max - 1) + '…' : s;
	}

	function esc(s) {
		return String(s).replace(/[&<>"]/g, function (ch) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch];
		});
	}
})();
