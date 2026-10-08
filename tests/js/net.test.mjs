// The request helpers: run with `node --test tests/js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../src/web/assets/cp/dist/net.js', import.meta.url), 'utf8');
const window = {};
vm.runInNewContext(source, { window, TypeError });
const transient = (error) => window.Ghostwriter.transient(error);

test('a request that never got an answer, or a busy server, is worth asking again', () => {
    assert.equal(transient({ isAxiosError: true, request: {} }), true, 'no answer (net::ERR_NETWORK_CHANGED)');
    assert.equal(transient(new TypeError('Failed to fetch')), true);
    assert.equal(transient({ isAxiosError: true, response: { status: 502 } }), true);
    assert.equal(transient({ isAxiosError: true, response: { status: 503 } }), true);
    assert.equal(transient({ response: { status: 429 } }), true);
    assert.equal(transient({ response: { status: 408 } }), true);
});

test('a refusal is not asked again', () => {
    assert.equal(transient({ isAxiosError: true, response: { status: 404 } }), false);
    assert.equal(transient({ isAxiosError: true, response: { status: 403 } }), false);
    assert.equal(transient({ isAxiosError: true, response: { status: 409 } }), false);
    assert.equal(transient({ isAxiosError: true, response: { status: 400 } }), false);
    assert.equal(transient(new Error('Something went wrong.')), false);
    assert.equal(transient(null), false);
});
