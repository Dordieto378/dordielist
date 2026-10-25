const chunkSize = 8 * 1024 * 1024;

export function buildDoujinQueue(files) {
    const entries = [];
    let ignored = 0;

    for (const file of files) {
        if (!file.name.toLowerCase().endsWith('.zip')) {
            ignored++;
            continue;
        }

        const relativePath = (file.webkitRelativePath || '').replace(/\\/g, '/');
        const parts = relativePath.split('/');
        if (parts.length !== 3 || parts.some(part => !part.trim() || ['.', '..'].includes(part) || /[\x00-\x1f:]/.test(part))) {
            throw new Error('Choose the outer folder containing author folders, with ZIP files directly inside each author folder.');
        }

        const title = file.name.slice(0, -4).trim();
        const author = parts[1].trim();
        if (parts[2] !== file.name || !title || Array.from(title).length > 255 || Array.from(author).length > 255) {
            throw new Error('Each ZIP needs a title and author name of up to 255 characters.');
        }

        entries.push({ file, relativePath, title, author, status: 'pending', message: 'Ready', progress: 0 });
    }

    if (!entries.length) {
        throw new Error('No ZIP files found. Select a folder containing author folders with doujin ZIP files.');
    }

    entries.sort((left, right) => left.relativePath.localeCompare(right.relativePath, undefined, { numeric: true, sensitivity: 'base' }));
    return { entries, ignored };
}

async function readResponse(response) {
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
        const error = new Error(payload.message || 'Upload failed. Please retry this ZIP.');
        error.status = response.status;
        throw error;
    }
    return payload;
}

export async function importDoujinQueue(entries, { chunkUrl, completeUrl, token, onUpdate = () => {}, request = fetch }) {
    const headers = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(token ? { 'X-CSRF-TOKEN': token } : {}),
    };

    for (const entry of entries) {
        if (entry.status === 'added' || entry.status === 'skipped') continue;
        entry.status = 'uploading';
        entry.message = 'Uploading...';
        entry.progress = 0;
        onUpdate(entry);

        try {
            entry.session ??= {
                uploadId: globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`,
                totalChunks: Math.max(1, Math.ceil(entry.file.size / chunkSize)),
                uploadedChunks: 0,
            };
            const session = entry.session;
            for (let index = session.uploadedChunks; index < session.totalChunks; index++) {
                const body = new FormData();
                body.append('upload_id', session.uploadId);
                body.append('chunk_index', String(index));
                body.append('total_chunks', String(session.totalChunks));
                body.append('archive_chunk', entry.file.slice(index * chunkSize, (index + 1) * chunkSize), `${entry.file.name}.part${index}`);
                await readResponse(await request(chunkUrl, { method: 'POST', headers, body }));
                session.uploadedChunks = index + 1;
                entry.progress = 0.9 * session.uploadedChunks / session.totalChunks;
                entry.message = `Uploading ${Math.round(session.uploadedChunks / session.totalChunks * 100)}%`;
                onUpdate(entry);
            }

            entry.status = 'importing';
            entry.message = 'Importing...';
            entry.progress = 0.95;
            onUpdate(entry);
            const body = new FormData();
            body.append('upload_id', session.uploadId);
            body.append('total_chunks', String(session.totalChunks));
            body.append('original_name', entry.file.name);
            body.append('relative_path', entry.relativePath);
            const result = await readResponse(await request(completeUrl, { method: 'POST', headers, body }));
            entry.status = result.skipped ? 'skipped' : 'added';
            entry.message = result.skipped ? 'Already added' : 'Added';
            entry.session = null;
            entry.progress = 1;
        } catch (error) {
            if (entry.status === 'importing') entry.session = null;
            entry.status = 'failed';
            entry.message = error instanceof Error ? error.message : 'Upload failed. Please retry this ZIP.';
            entry.progress = 1;
            if ([401, 403, 419].includes(error.status)) {
                onUpdate(entry);
                break;
            }
        }
        onUpdate(entry);
    }
}

export function initDoujinImport() {
    const modal = document.getElementById('addDoujinModal');
    if (!modal) return;
    const form = document.getElementById('addDoujinForm');
    const folderInput = document.getElementById('doujinFolderInput');
    const submit = document.getElementById('submitAddDoujinBtn');
    const cancel = document.getElementById('cancelAddDoujinModal');
    const close = document.getElementById('closeAddDoujinModal');
    const errorBox = document.getElementById('addDoujinInlineError');
    const progress = document.getElementById('doujinImportProgress');
    const progressBar = document.getElementById('doujinImportProgressBar');
    const status = document.getElementById('doujinImportStatus');
    let entries = [];
    let busy = false;
    let hasChanges = false;
    let previousFocus;

    const showError = message => {
        errorBox.textContent = message;
        errorBox.classList.toggle('hidden', !message);
    };
    const updateControls = () => {
        const remaining = entries.filter(entry => !['added', 'skipped'].includes(entry.status));
        const retry = remaining.some(entry => entry.status === 'failed');
        submit.disabled = busy || !remaining.length;
        submit.textContent = busy ? 'Importing...' : remaining.length
            ? `${retry ? 'Retry' : 'Add'} ${remaining.length} ${retry ? (remaining.length === 1 ? 'ZIP' : 'ZIPs') : (remaining.length === 1 ? 'Doujin' : 'Doujins')}` : 'Add Doujins';
        folderInput.disabled = busy;
        close.disabled = busy;
        cancel.disabled = busy;
        cancel.textContent = hasChanges ? 'Done' : 'Cancel';
    };
    const setOpen = open => {
        if (busy) return;
        if (!open && hasChanges) {
            window.location.reload();
            return;
        }
        modal.classList.toggle('hidden', !open);
        document.body.classList.toggle('overflow-hidden', open);
        if (open) {
            previousFocus = document.activeElement;
            folderInput.focus();
        } else {
            previousFocus?.focus();
        }
    };
    const updateEntry = entry => {
        entry.statusCell.textContent = entry.message;
        entry.statusCell.classList.toggle('text-red-600', entry.status === 'failed');
        entry.statusCell.classList.toggle('text-emerald-700', ['added', 'skipped'].includes(entry.status));
        if (['added', 'skipped'].includes(entry.status)) hasChanges = true;
        const done = entries.filter(item => ['added', 'skipped', 'failed'].includes(item.status)).length;
        status.textContent = `${done} of ${entries.length} processed. ${entry.title}: ${entry.message}`;
        progressBar.value = Math.round(entries.reduce((sum, item) => sum + item.progress, 0) / entries.length * 100);
    };

    document.getElementById('openAddDoujinModal')?.addEventListener('click', () => setOpen(true));
    close.addEventListener('click', () => setOpen(false));
    cancel.addEventListener('click', () => setOpen(false));
    modal.addEventListener('click', event => {
        if (event.target === modal) setOpen(false);
    });
    modal.addEventListener('keydown', event => {
        if (event.key === 'Escape') setOpen(false);
        if (event.key === 'Tab') {
            const controls = [...modal.querySelectorAll('button:not([disabled]), input:not([disabled]):not([type="hidden"])')];
            const first = controls[0];
            const last = controls[controls.length - 1];
            if ((event.shiftKey && document.activeElement === first) || (!event.shiftKey && document.activeElement === last)) {
                event.preventDefault();
                (event.shiftKey ? last : first)?.focus();
            }
        }
    });
    folderInput.addEventListener('change', () => {
        entries = [];
        showError('');
        progress.classList.add('hidden');
        const preview = document.getElementById('doujinImportPreview');
        const rows = document.getElementById('doujinImportFiles');
        rows.replaceChildren();
        preview.classList.add('hidden');
        try {
            const selection = buildDoujinQueue(folderInput.files);
            entries = selection.entries;
            const authors = new Set(entries.map(entry => entry.author.toLowerCase()));
            document.getElementById('doujinFolderSummary').textContent = `${entries.length} ZIPs, ${authors.size} authors${selection.ignored ? `, ${selection.ignored} other files ignored` : ''}`;
            for (const entry of entries) {
                const row = document.createElement('tr');
                for (const value of [entry.title, entry.author, entry.message]) {
                    const cell = document.createElement('td');
                    cell.className = 'px-3 py-2 align-top break-words';
                    cell.textContent = value;
                    row.appendChild(cell);
                }
                entry.statusCell = row.lastElementChild;
                rows.appendChild(row);
            }
            preview.classList.remove('hidden');
        } catch (error) {
            showError(error.message);
        }
        updateControls();
    });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy || !entries.length) return;
        busy = true;
        showError('');
        entries.filter(entry => entry.status === 'failed').forEach(entry => { entry.progress = 0; });
        updateControls();
        progress.classList.remove('hidden');
        try {
            await importDoujinQueue(entries, {
                chunkUrl: form.dataset.chunkUrl,
                completeUrl: form.dataset.completeUrl,
                token: form.querySelector('input[name="_token"]').value,
                onUpdate: updateEntry,
            });
            const counts = state => entries.filter(entry => entry.status === state).length;
            const pending = counts('pending');
            status.textContent = `${counts('added')} added, ${counts('skipped')} already added, ${counts('failed')} failed${pending ? `, ${pending} waiting` : ''}.`;
        } finally {
            busy = false;
            updateControls();
        }
    });
    if (!errorBox.classList.contains('hidden')) setOpen(true);
}
