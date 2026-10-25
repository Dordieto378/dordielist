function normalizeSearch(value) {
    return String(value).normalize('NFKD').replace(/\p{M}/gu, '').toLowerCase().trim();
}

export function matchesArtistSearch(name, query) {
    const artist = normalizeSearch(name);
    return normalizeSearch(query).split(/\s+/).every(word => artist.includes(word));
}

function refreshSocialGroup(group) {
    const rows = [...group.querySelectorAll('[data-social-row]')];
    rows.forEach((row, index) => {
        row.querySelector('[data-add-social]').classList.toggle('hidden', index !== rows.length - 1);
        row.querySelector('[data-remove-social]').classList.toggle('hidden', rows.length <= 1);
    });
}

export function initDoujinArtists() {
    const root = document.getElementById('doujinArtistsSettings');
    if (!root) return;
    const search = document.getElementById('doujinArtistSearch');
    const rows = [...root.querySelectorAll('[data-artist-row]')];
    const count = document.getElementById('doujinArtistsCount');
    const empty = document.getElementById('doujinArtistsEmpty');
    const searchKey = 'settings.doujin-authors.search';
    const scrollKey = 'settings.doujin-authors.scrollY';
    const filter = () => {
        let visible = 0;
        for (const row of rows) {
            const matches = matchesArtistSearch(row.dataset.artistName, search.value);
            row.classList.toggle('hidden', !matches);
            if (matches) visible++;
        }
        count.textContent = search.value.trim() ? visible + ' of ' + rows.length + ' artists' : rows.length + ' artists';
        empty.textContent = rows.length ? 'No artists match your search.' : 'No doujin artists found.';
        empty.classList.toggle('hidden', visible > 0);
        sessionStorage.setItem(searchKey, search.value);
    };

    root.addEventListener('click', event => {
        const add = event.target.closest('[data-add-social]');
        if (add) {
            const group = add.closest('[data-social-group]');
            const list = group.querySelector('[data-social-list]');
            const row = list.lastElementChild.cloneNode(true);
            const input = row.querySelector('input');
            input.value = '';
            input.defaultValue = '';
            list.appendChild(row);
            refreshSocialGroup(group);
            input.focus();
        }
        const remove = event.target.closest('[data-remove-social]');
        if (remove) {
            const group = remove.closest('[data-social-group]');
            const row = remove.closest('[data-social-row]');
            if (group.querySelectorAll('[data-social-row]').length > 1) {
                const neighbor = row.previousElementSibling ?? row.nextElementSibling;
                row.remove();
                refreshSocialGroup(group);
                neighbor?.querySelector('input').focus();
            }
        }
    });
    root.querySelectorAll('[data-social-group]').forEach(refreshSocialGroup);
    root.querySelectorAll('form[data-artist-row]').forEach(form => {
        form.addEventListener('submit', () => sessionStorage.setItem(scrollKey, String(window.scrollY)));
    });
    search.addEventListener('input', filter);
    search.value = sessionStorage.getItem(searchKey) ?? '';
    filter();

    const failed = rows.find(row => row.dataset.artistId === root.dataset.failedArtistId);
    if (failed?.classList.contains('hidden')) {
        search.value = failed.dataset.artistName;
        filter();
    }
    const savedScroll = sessionStorage.getItem(scrollKey);
    if (savedScroll !== null) {
        sessionStorage.removeItem(scrollKey);
        requestAnimationFrame(() => window.scrollTo(0, Number(savedScroll) || 0));
    } else if (failed) {
        requestAnimationFrame(() => failed.scrollIntoView({ block: 'center' }));
    }
}
