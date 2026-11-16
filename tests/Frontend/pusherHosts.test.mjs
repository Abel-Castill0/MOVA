import test from 'node:test';
import assert from 'node:assert/strict';
import { resolvePusherWsHost } from '../../resources/js/utils/pusherHosts.js';

test('default Pusher HTTP API host becomes the WebSocket endpoint', () => {
    assert.equal(resolvePusherWsHost({ cluster: 'sa1', host: 'api-sa1.pusher.com' }), 'ws-sa1.pusher.com');
    assert.equal(resolvePusherWsHost({ cluster: 'mt1', host: null }), 'ws-mt1.pusher.com');
    assert.equal(resolvePusherWsHost({}), 'ws-mt1.pusher.com');
});

test('custom self-hosted WebSocket endpoints are preserved', () => {
    assert.equal(resolvePusherWsHost({ host: 'ws.example.org' }), 'ws.example.org');
    assert.equal(resolvePusherWsHost({ host: 'https://ws.internal.example:6001/socket' }), 'ws.internal.example');
});
