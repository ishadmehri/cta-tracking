/**
 * CTA Tracking: counts clicks on the elements the admin defined by CSS selector.
 *
 * One click listener on the document, in the capture phase (themes that stopPropagation() cannot hide a click).
 * From the clicked element upwards, the first element that matches any CTA selector wins (nearest match); priority
 * decides only between CTAs matching that same element. One click is one CTA at most. The zone is the nearest
 * ancestor matching a zone selector. Sent with sendBeacon as a form body (no CORS preflight), no cookie, no IP.
 * Not counted: clicks inside iframes and closed shadow roots.
 */
(function (root, factory) {
	'use strict';
	var api = factory();
	if (typeof module === 'object' && module.exports) {
		module.exports = api;
	} else if (root && root.document && root.ctaTrackingConfig) {
		api.run(root, root.document, root.ctaTrackingConfig);
	}
})(typeof window !== 'undefined' ? window : this, function () {
	'use strict';

	var DEDUPE_MS = 1500;
	var UNTRUSTED = 1;
	var WEBDRIVER = 2;

	/** Drops definitions whose selector or regex does not parse, so one typo cannot break the rest. */
	function compile(cfg, doc) {
		var ok = function (sel) {
			try {
				doc.querySelector(sel);
				return true;
			} catch (e) {
				return false;
			}
		};
		var ctas = [];
		(cfg.ctas || []).forEach(function (c) {
			if (!ok(c[1])) {
				return;
			}
			var re = null;
			if (c[3]) {
				try {
					re = new RegExp(c[3]);
				} catch (e) {
					re = null;
				}
			}
			ctas.push({ key: c[0], sel: c[1], pri: +c[2] || 0, re: re });
		});
		var zones = [];
		(cfg.zones || []).forEach(function (z) {
			if (ok(z[1])) {
				zones.push({ key: z[0], sel: z[1] });
			}
		});
		return { ctas: ctas, zones: zones };
	}

	/** The nearest element on the path that matches a CTA, with that CTA; null when none does. */
	function findCta(path, ctas) {
		for (var i = 0; i < path.length; i++) {
			var el = path[i];
			if (!el || el.nodeType !== 1) {
				continue;
			}
			var best = null;
			for (var j = 0; j < ctas.length; j++) {
				if ((!best || ctas[j].pri > best.pri) && el.matches(ctas[j].sel)) {
					best = ctas[j];
				}
			}
			if (best) {
				return { el: el, cta: best };
			}
		}
		return null;
	}

	/** Key of the nearest zone around an element; on one element the first zone in the list wins. */
	function findZone(el, zones) {
		for (; el && el.nodeType === 1; el = el.parentElement) {
			for (var j = 0; j < zones.length; j++) {
				if (el.matches(zones[j].sel)) {
					return zones[j].key;
				}
			}
		}
		return '';
	}

	/** The CTA's identity from the link (first regex group, else the whole match), e.g. the phone number. */
	function contextOf(el, re) {
		if (!re) {
			return '';
		}
		var m = re.exec(el.getAttribute('href') || '');
		return m ? String(m[1] !== undefined ? m[1] : m[0]).slice(0, 64) : '';
	}

	/** Elements from the click target up: composedPath() reaches into open shadow roots. */
	function pathOf(event) {
		if (event.composedPath) {
			return event.composedPath();
		}
		var out = [];
		for (var el = event.target; el; el = el.parentNode) {
			out.push(el);
		}
		return out;
	}

	function run(win, doc, cfg) {
		var defs = compile(cfg, doc);
		if (!defs.ctas.length || !cfg.url) {
			return;
		}
		var last = { key: '', at: 0 };
		var nav = win.navigator || {};

		function send(fields) {
			var body = new URLSearchParams(fields);
			if (nav.sendBeacon && nav.sendBeacon(cfg.url, body)) {
				return;
			}
			if (win.fetch) {
				win.fetch(cfg.url, { method: 'POST', body: body, keepalive: true, credentials: 'same-origin' }).catch(function () {});
			}
		}

		function onClick(event) {
			if (win.ctaTrackingConsent === false) {
				return;
			}
			var hit = findCta(pathOf(event), defs.ctas);
			if (!hit) {
				return;
			}
			var context = contextOf(hit.el, hit.cta.re);
			var key = hit.cta.key + '|' + context;
			var now = Date.now();
			if (last.key === key && now - last.at < DEDUPE_MS) {
				return; // a double click is one wish
			}
			last = { key: key, at: now };
			send({
				c: hit.cta.key,
				z: findZone(hit.el, defs.zones),
				x: context,
				p: cfg.post || 0,
				w: win.innerWidth || 0,
				t: nav.maxTouchPoints || 0,
				f: (event.isTrusted === false ? UNTRUSTED : 0) | (nav.webdriver ? WEBDRIVER : 0)
			});
		}

		doc.addEventListener('click', onClick, true);
		doc.addEventListener('auxclick', function (event) {
			if (event.button === 1) {
				onClick(event); // middle click: opened in a new tab
			}
		}, true);

		if (/[?&]cta_debug=1/.test(win.location.search)) {
			debug(doc, defs);
		}
	}

	/** ?cta_debug=1: outlines every element a CTA matches and shows which CTA and zone it would count as. */
	function debug(doc, defs) {
		defs.ctas.forEach(function (c) {
			Array.prototype.forEach.call(doc.querySelectorAll(c.sel), function (el) {
				var hit = findCta([el], defs.ctas);
				var label = hit.cta.key + ' / ' + (findZone(el, defs.zones) || '-') + (contextOf(el, hit.cta.re) ? ' / ' + contextOf(el, hit.cta.re) : '');
				el.style.outline = '2px dashed #e11d48';
				el.setAttribute('title', 'CTA: ' + label);
			});
		});
	}

	return { compile: compile, findCta: findCta, findZone: findZone, contextOf: contextOf, run: run };
});
