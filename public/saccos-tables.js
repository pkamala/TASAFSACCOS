const tableNumber = new Intl.NumberFormat(document.documentElement.lang || 'en');

function recordsLabel(total) {
    return `${tableNumber.format(total)} ${total === 1 ? 'record' : 'records'}`;
}

document.querySelectorAll('main table').forEach(table => {
    const wrapper = table.closest('.table-wrap') ?? table;
    const serverControls = wrapper.nextElementSibling;
    if (serverControls?.matches('[data-server-table-pagination]')) {
        const form = serverControls.querySelector('[data-table-search-form]');
        if (form) wrapper.before(form);
        return;
    }

    const rows = Array.from(table.tBodies).flatMap(body => Array.from(body.rows))
        .filter(row => !(row.cells.length === 1 && row.cells[0].matches('td[colspan]')));
    const controls = document.createElement('div');
    controls.className = 'table-pagination print-hide';
    wrapper.after(controls);

    if (!rows.length) {
        controls.textContent = recordsLabel(0);
        return;
    }

    const label = document.createElement('label');
    label.className = 'page-size print-hide';
    label.textContent = 'Search table';
    const search = document.createElement('input');
    search.type = 'search';
    search.maxLength = 100;
    label.append(search);
    wrapper.before(label);
    search.addEventListener('keydown', event => {
        if (event.key === 'Enter') event.preventDefault();
    });

    const paged = table.hasAttribute('data-table-pages');
    if (paged) {
        controls.innerHTML = `
            <label class="page-size">Rows per page
                <select>
                    <option selected>10</option><option>25</option><option>50</option><option>100</option>
                </select>
            </label>
            <button type="button">Previous</button>
            <span role="status"></span>
            <button type="button">Next</button>
        `;
    } else {
        controls.innerHTML = '<span role="status"></span>';
    }

    const [previous, next] = controls.querySelectorAll('button');
    const size = controls.querySelector('select');
    const status = controls.querySelector('[role="status"]');
    const rowText = rows.map(row => Array.from(row.cells, cell => cell.textContent).join(' ').toLocaleLowerCase());
    let page = 0;

    function render() {
        const terms = search.value.toLocaleLowerCase().trim().split(/\s+/).filter(Boolean);
        const pageSize = size ? Number(size.value) : rows.length;
        const first = page * pageSize;
        let total = 0;

        rows.forEach((row, index) => {
            const matches = terms.every(term => rowText[index].includes(term));
            row.hidden = !matches;
            row.classList.toggle('outside-table-page', matches && (total < first || total >= first + pageSize));
            if (matches) total += 1;
        });

        if (paged) {
            previous.disabled = first === 0;
            next.disabled = first + pageSize >= total;
        }
        status.textContent = paged && total
            ? `Showing ${tableNumber.format(first + 1)}–${tableNumber.format(Math.min(first + pageSize, total))} of ${recordsLabel(total)}`
            : recordsLabel(total);
    }

    function resetPage() {
        page = 0;
        render();
    }

    search.addEventListener('input', resetPage);
    size?.addEventListener('change', resetPage);
    previous?.addEventListener('click', () => { page -= 1; render(); });
    next?.addEventListener('click', () => { page += 1; render(); });
    render();
});

document.querySelectorAll('[data-live-table-search]').forEach(form => {
    const status = form.parentElement.querySelector('[data-live-table-status]');
    let results = form.parentElement.querySelector('[data-live-table-results]');
    let pending;
    let timer;

    function searchUrl() {
        const url = new URL(form.action, location.href);
        const data = new FormData(form);
        const pageSize = results.querySelector('.page-size select');
        if (pageSize) data.set(pageSize.name, pageSize.value);
        url.search = new URLSearchParams(data);
        return url;
    }

    async function refresh(url) {
        clearTimeout(timer);
        pending?.abort();
        const controller = new AbortController();
        pending = controller;
        results.setAttribute('aria-busy', 'true');
        status.textContent = 'Searching…';
        try {
            const response = await fetch(url, {
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                signal: controller.signal,
            });
            if (controller.signal.aborted) return;
            if (response.status === 401) {
                location.assign(url);
                return;
            }
            if (response.redirected) {
                location.assign(response.url);
                return;
            }
            if (!response.ok) throw new Error('Search failed');
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            if (controller.signal.aborted) return;
            const replacement = page.querySelector('[data-live-table-results]');
            if (!replacement) throw new Error('Missing search results');
            const focused = results.contains(document.activeElement);
            results.replaceWith(replacement);
            results = replacement;
            history.replaceState(history.state, '', url);
            if (focused) {
                results.setAttribute('tabindex', '-1');
                results.focus({preventScroll: true});
            }
            form.querySelector('[data-search-fallback]').hidden = true;
            status.textContent = 'Results updated.';
        } catch (error) {
            if (!controller.signal.aborted) {
                status.textContent = 'Search could not update. Press Enter to reload results.';
                form.querySelector('[data-search-fallback]').hidden = false;
            }
        } finally {
            if (pending === controller) results.removeAttribute('aria-busy');
        }
    }

    form.querySelector('[data-search-fallback]').hidden = true;
    form.addEventListener('input', event => {
        if (event.target.name !== 'q' || event.isComposing) return;
        clearTimeout(timer);
        pending?.abort();
        timer = setTimeout(() => refresh(searchUrl()), 300);
    });
    form.addEventListener('change', event => {
        if (event.target.matches('select')) refresh(searchUrl());
    });
    form.addEventListener('submit', event => {
        if (!form.querySelector('[data-search-fallback]').hidden) return;
        event.preventDefault();
        refresh(searchUrl());
    });

    form.parentElement.addEventListener('click', event => {
        const link = event.target.closest('[data-live-table-results] .pagination a');
        if (!link || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        refresh(link.href);
    });
    form.parentElement.addEventListener('submit', event => {
        if (!event.target.matches('[data-live-table-results] form.page-size')) return;
        event.preventDefault();
        refresh(searchUrl());
    });
});
