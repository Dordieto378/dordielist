import assert from 'node:assert/strict';
import test from 'node:test';
import { scrollPosition, readerModeUrl } from '../../resources/js/reader-progress.js';

const frame = (number, top, height) => ({
    dataset: { readerPageNumber: String(number) },
    getBoundingClientRect: () => ({ top, bottom: top + height, height }),
});

test('scroll progress records the page at the viewport top and its relative offset', () => {
    assert.deepEqual(scrollPosition([frame(49, -1400, 1000), frame(50, -400, 1000), frame(51, 600, 1000)]), {
        page_number: 50, scroll_offset: 0.4,
    });
});

test('a page boundary moves progress to the following page', () => {
    assert.deepEqual(scrollPosition([frame(50, -1000, 1000), frame(51, 0, 2000)]), {
        page_number: 51, scroll_offset: 0,
    });
});

test('scroll positions remain bounded for short or empty chapters', () => {
    assert.equal(scrollPosition([]), null);
    assert.deepEqual(scrollPosition([frame(1, 20, 100)]), { page_number: 1, scroll_offset: 0 });
    assert.deepEqual(scrollPosition([frame(1, -200, 100)]), { page_number: 1, scroll_offset: 1 });
});

test('all reading modes use the current page and double mode anchors its spread there', () => {
    for (const view of ['one', 'double', 'scroll']) {
        const url = new URL(readerModeUrl('/media/7/chapters/2', view, 50, 'https://example.test'));
        assert.equal(url.pathname, '/media/7/chapters/2/50');
        assert.equal(url.searchParams.get('view'), view);
        assert.equal(url.searchParams.get('spread_start'), view === 'double' ? '50' : null);
    }
});
