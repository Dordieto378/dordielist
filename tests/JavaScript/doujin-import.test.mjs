import assert from 'node:assert/strict';
import test from 'node:test';
import { buildDoujinQueue, importDoujinQueue } from '../../resources/js/doujin-import.js';

function file(path, size = 16) {
    const upload = new File([new Uint8Array(size)], path.split('/').at(-1));
    Object.defineProperty(upload, 'webkitRelativePath', { value: path });
    return upload;
}

const ok = (body = {}) => new Response(JSON.stringify(body), { status: 200 });
const failed = (message, status = 422) => new Response(JSON.stringify({ message }), { status });

test('folder selection derives one author from each complete folder name', () => {
    const selection = buildDoujinQueue([
        file('Collection/author 1/First.zip'),
        file('Collection/author 1 and author 2/Shared.ZIP'),
        file('Collection/author 2/Another.zip'),
        file('Collection/author 2/notes.txt'),
    ]);
    assert.equal(selection.ignored, 1);
    assert.equal(selection.entries.length, 3);
    assert.equal(selection.entries.find(entry => entry.title === 'Shared').author, 'author 1 and author 2');
    assert.equal(new Set(selection.entries.map(entry => entry.author)).size, 3);
});

test('and and commas remain part of a single author name', () => {
    const { entries } = buildDoujinQueue([file('Collection/Anderson, Candy and Friends/Book.zip')]);
    assert.equal(entries[0].author, 'Anderson, Candy and Friends');
});

test('invalid folder layouts and paths cannot be submitted', () => {
    for (const path of ['Collection/Book.zip', 'Collection/Artist/Extra/Book.zip', 'Collection/../Book.zip']) {
        assert.throws(() => buildDoujinQueue([file(path)]), /outer folder/);
    }
    assert.throws(() => buildDoujinQueue([file('Collection/Artist/readme.txt')]), /No ZIP files/);
});

test('a batch continues after a bad ZIP and retries only that ZIP with its original metadata', async () => {
    const { entries } = buildDoujinQueue([
        file('Collection/author 1/A.zip'),
        file('Collection/author 1/B.zip'),
    ]);
    let rejectFirst = true;
    const completions = [];
    const options = {
        chunkUrl: '/chunks', completeUrl: '/complete', token: 'csrf-token',
        request: async (url, { body, headers }) => {
            assert.equal(headers['X-CSRF-TOKEN'], 'csrf-token');
            if (url === '/chunks') return ok();
            completions.push(body.get('relative_path'));
            assert.equal(body.get('original_name'), body.get('relative_path').split('/').at(-1));
            if (body.get('original_name') === 'A.zip' && rejectFirst) return failed('Bad ZIP');
            return ok({ skipped: body.get('original_name') === 'B.zip' });
        },
    };
    await importDoujinQueue(entries, options);
    assert.deepEqual(entries.map(entry => entry.status), ['failed', 'skipped']);
    assert.equal(entries[0].message, 'Bad ZIP');
    rejectFirst = false;
    await importDoujinQueue(entries, options);
    assert.deepEqual(entries.map(entry => entry.status), ['added', 'skipped']);
    assert.deepEqual(completions, [entries[0].relativePath, entries[1].relativePath, entries[0].relativePath]);
});

test('retrying a large ZIP resumes at the failed chunk', async () => {
    const { entries } = buildDoujinQueue([file('Collection/Artist/Large.zip', 8 * 1024 * 1024 + 1)]);
    const chunks = [];
    let failOnce = true;
    const uploadIds = new Set();
    const options = {
        chunkUrl: '/chunks', completeUrl: '/complete',
        request: async (url, { body }) => {
            uploadIds.add(body.get('upload_id'));
            if (url === '/complete') return ok();
            const index = body.get('chunk_index');
            chunks.push(index);
            if (index === '1' && failOnce) {
                failOnce = false;
                return failed('Connection failed', 503);
            }
            return ok();
        },
    };
    await importDoujinQueue(entries, options);
    assert.equal(entries[0].status, 'failed');
    await importDoujinQueue(entries, options);
    assert.equal(entries[0].status, 'added');
    assert.deepEqual(chunks, ['0', '1', '1']);
    assert.equal(uploadIds.size, 1);
});

test('an expired login stops a batch before uploading further ZIPs', async () => {
    const { entries } = buildDoujinQueue([file('Collection/Artist/A.zip'), file('Collection/Artist/B.zip')]);
    let requests = 0;
    await importDoujinQueue(entries, {
        chunkUrl: '/chunks', completeUrl: '/complete',
        request: async () => { requests++; return failed('Session expired', 419); },
    });
    assert.equal(requests, 1);
    assert.deepEqual(entries.map(entry => entry.status), ['failed', 'pending']);
});

test('a lost completion response can be retried on an HTTP site without crypto.randomUUID', async () => {
    const cryptoDescriptor = Object.getOwnPropertyDescriptor(globalThis, 'crypto');
    Object.defineProperty(globalThis, 'crypto', { configurable: true, value: {} });
    try {
        const { entries } = buildDoujinQueue([file('Collection/Artist/Book.zip')]);
        let chunks = 0;
        let completions = 0;
        const options = {
            chunkUrl: '/chunks', completeUrl: '/complete',
            request: async (url, { body }) => {
                assert.match(body.get('upload_id'), /^[a-zA-Z0-9_-]{1,80}$/);
                if (url === '/chunks') { chunks++; return ok(); }
                if (++completions === 1) throw new TypeError('Connection lost');
                return ok({ skipped: true });
            },
        };
        await importDoujinQueue(entries, options);
        assert.equal(entries[0].status, 'failed');
        assert.equal(entries[0].session, null);
        await importDoujinQueue(entries, options);
        assert.equal(entries[0].status, 'skipped');
        assert.equal(chunks, 2);
    } finally {
        Object.defineProperty(globalThis, 'crypto', cryptoDescriptor);
    }
});
