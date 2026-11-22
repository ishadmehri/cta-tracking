// The click matcher of assets/tracker.js, with fake elements (no DOM needed): node --test tests/
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { compile, findCta, findZone, contextOf } = require('../assets/tracker.js');

/** A fake element answering matches() from a list of selectors; parent links it upwards. */
const node = (sels, attrs = {}, parent = null) => ({
  nodeType: 1,
  parentElement: parent,
  matches: (s) => sels.includes(s),
  getAttribute: (n) => (n in attrs ? attrs[n] : null)
});
const doc = { querySelector: (s) => { if (s.includes('[[')) throw new SyntaxError('bad'); return null; } };

const defs = compile({
  ctas: [
    ['whatsapp', 'a.wa', 10, '(?:wa\\.me\\/|phone=)\\+?(\\d+)'],
    ['card', '.card', 10, ''],
    ['broken', 'a[[x', 10, ''],
    ['tel', 'a.tel', 10, 'tel:\\+?(\\d+)'],
    ['special', 'a.tel', 20, '']
  ],
  zones: [['popup', '.popup'], ['footer', 'footer'], ['bad', '[[']]
}, doc);

test('invalid selectors are dropped instead of breaking every click', () => {
  assert.deepEqual(defs.ctas.map((c) => c.key), ['whatsapp', 'card', 'tel', 'special']);
  assert.deepEqual(defs.zones.map((z) => z.key), ['popup', 'footer']);
});

test('the nearest matching element wins, not the first CTA in the list', () => {
  const footer = node(['footer']);
  const card = node(['.card'], {}, footer);
  const link = node(['a.wa'], { href: 'https://api.whatsapp.com/send?phone=989120000001' }, card);
  const icon = node([], {}, link);
  const hit = findCta([icon, link, card, footer, { nodeType: 9 }], defs.ctas);
  assert.equal(hit.cta.key, 'whatsapp');
  assert.equal(hit.el, link);
});

test('priority decides only between CTAs on the same element', () => {
  const link = node(['a.tel'], { href: 'tel:+989120000001' });
  assert.equal(findCta([link], defs.ctas).cta.key, 'special');
});

test('no CTA on the path → nothing is sent', () => {
  assert.equal(findCta([node(['p']), { nodeType: 9 }], defs.ctas), null);
});

test('zone is the nearest ancestor; the first listed wins on one element', () => {
  const both = node(['.popup', 'footer']);
  const inner = node([], {}, node([], {}, both));
  assert.equal(findZone(inner, defs.zones), 'popup');
  assert.equal(findZone(node([]), defs.zones), '');
});

test('context is the first regex group of the href', () => {
  const wa = defs.ctas[0].re;
  assert.equal(contextOf(node([], { href: 'https://api.whatsapp.com/send?phone=989120000001' }), wa), '989120000001');
  assert.equal(contextOf(node([], { href: 'https://wa.me/989120000002?text=hi' }), wa), '989120000002');
  assert.equal(contextOf(node([], { href: 'tel:+989120000001' }), defs.ctas[2].re), '989120000001');
  assert.equal(contextOf(node([], {}), wa), '');
  assert.equal(contextOf(node([], { href: 'x' }), null), '');
});
