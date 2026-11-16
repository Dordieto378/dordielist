// Progress uses a page number in every view; scroll mode adds a fraction of that page.
export function scrollPosition(frames, viewportTop = 0) {
    const page = frames.find(frame => frame.getBoundingClientRect().bottom > viewportTop) || frames.at(-1);
    if (!page) return null;
    const bounds = page.getBoundingClientRect();
    return {
        page_number: Number(page.dataset.readerPageNumber),
        scroll_offset: Math.max(0, Math.min(1, (viewportTop - bounds.top) / Math.max(1, bounds.height))),
    };
}

export function readerModeUrl(baseUrl, view, pageNumber, origin) {
    const url = new URL(baseUrl, origin);
    url.pathname = `${url.pathname.replace(/\/$/, '')}/${pageNumber}`;
    url.searchParams.set('view', view);
    if (view === 'double') url.searchParams.set('spread_start', pageNumber);
    return url.toString();
}

let currentReader = null;

export function initReaderProgress(root = document) {
    currentReader?.destroy();
    currentReader = null;
    const reader = root.querySelector('[data-reader-progress-url]');
    if (!reader) return null;

    const view = reader.dataset.readerView;
    const frames = Array.from(reader.querySelectorAll('[data-reader-page-number]'));
    const state = {
        page_number: Number(reader.dataset.readerPageNumber),
        scroll_offset: Number(reader.dataset.readerScrollOffset) || 0,
        view,
        spread_start: view === 'double' ? Number(reader.dataset.readerSpreadStart) : null,
    };
    let restoring = view === 'scroll';
    let destroyed = false;
    let timer;
    let animationFrame;
    let lastSaved = '';
    let saving;
    let recordedAt = Number(reader.dataset.readerRecordedAt) || 0;
    const listeners = new AbortController();
    const signal = listeners.signal;
    const csrf = root.querySelector('meta[name="csrf-token"]')?.content;

    const updateLinks = () => {
        root.querySelectorAll('[data-reader-view-switch]').forEach(link => {
            link.href = readerModeUrl(reader.dataset.readerBaseUrl, link.dataset.readerViewSwitch, state.page_number, location.origin);
        });
    };
    const sample = () => {
        if (view === 'scroll' && !restoring) Object.assign(state, scrollPosition(frames));
        if (view === 'scroll') {
            const base = new URL(reader.dataset.readerBaseUrl, location.origin);
            const url = new URL(location.href);
            if (url.origin === base.origin) {
                url.pathname = `${base.pathname}/${state.page_number}`;
                url.searchParams.set('view', 'scroll');
                if (url.href !== location.href) history.replaceState(history.state, '', url);
            }
        }
        updateLinks();
    };
    const flush = () => {
        clearTimeout(timer);
        if (destroyed) return;
        sample();
        const snapshot = JSON.stringify(state);
        if (snapshot === lastSaved) return saving;
        lastSaved = snapshot;
        recordedAt = Math.max(Date.now(), recordedAt + 1);
        // Keep the final save alive when a link closes/replaces the current page.
        saving = fetch(reader.dataset.readerProgressUrl, {
            method: 'POST', credentials: 'same-origin', keepalive: true,
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ ...state, recorded_at: recordedAt }),
        }).then(response => {
            if (!response.ok && lastSaved === snapshot) lastSaved = '';
        }).catch(() => {
            if (lastSaved === snapshot) lastSaved = '';
        });
        return saving;
    };
    const changed = () => {
        if (restoring || destroyed) return;
        cancelAnimationFrame(animationFrame);
        animationFrame = requestAnimationFrame(() => {
            sample();
            clearTimeout(timer);
            timer = setTimeout(flush, 500);
        });
    };

    // Keep the saved page in place while lazy images above it finish sizing.
    const restore = () => {
        if (!restoring || destroyed) return;
        const frame = frames.find(page => Number(page.dataset.readerPageNumber) === state.page_number);
        if (!frame) { restoring = false; return; }
        const bounds = frame.getBoundingClientRect();
        window.scrollTo(0, window.scrollY + bounds.top + bounds.height * state.scroll_offset);
    };
    const stopRestoring = event => {
        if (event.type === 'keydown' && !['ArrowDown', 'ArrowUp', 'PageDown', 'PageUp', 'Home', 'End', ' '].includes(event.key)) return;
        restoring = false;
        resizeObserver?.disconnect();
    };
    const resizeObserver = view === 'scroll' ? new ResizeObserver(restore) : null;
    if (view === 'scroll') {
        restore();
        resizeObserver.observe(reader);
        document.fonts.ready.then(restore);
        ['wheel', 'touchstart', 'pointerdown', 'keydown'].forEach(type => {
            window.addEventListener(type, stopRestoring, { passive: true, signal });
        });
        window.addEventListener('scroll', changed, { passive: true, signal });
    }
    window.addEventListener('pagehide', flush, { signal });
    root.addEventListener('visibilitychange', () => { if (root.hidden) flush(); }, { signal });
    root.addEventListener('click', flush, { capture: true, signal });
    updateLinks();
    flush();

    currentReader = {
        flush,
        navigationUrl(target) {
            sample();
            const url = new URL(target, location.origin);
            const base = new URL(reader.dataset.readerBaseUrl, location.origin);
            const mode = url.searchParams.get('view');
            if (url.origin === base.origin && url.pathname.replace(/\/\d+\/?$/, '') === base.pathname
                && ['one', 'double', 'scroll'].includes(mode) && mode !== view) {
                return readerModeUrl(base, mode, state.page_number, location.origin);
            }
            return target;
        },
        destroy() {
            if (destroyed) return;
            flush();
            destroyed = true;
            clearTimeout(timer);
            cancelAnimationFrame(animationFrame);
            resizeObserver?.disconnect();
            listeners.abort();
        },
    };
    return currentReader;
}
