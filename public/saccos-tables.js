document.querySelectorAll('table[data-table-pages]').forEach(table => {
    const rows = Array.from(table.tBodies).flatMap(body => Array.from(body.rows));
    if (!rows.length) return;

    const controls = document.createElement('div');
    controls.className = 'table-pagination print-hide';
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
    const [previous, next] = controls.querySelectorAll('button');
    const size = controls.querySelector('select');
    const status = controls.querySelector('[role="status"]');
    let page = 0;

    function render() {
        const count = Number(size.value);
        const first = page * count;
        rows.forEach((row, index) => row.classList.toggle('outside-table-page', index < first || index >= first + count));
        previous.disabled = first === 0;
        next.disabled = first + count >= rows.length;
        status.textContent = `${first + 1}–${Math.min(first + count, rows.length)} of ${rows.length}`;
    }

    size.addEventListener('change', () => { page = 0; render(); });
    previous.addEventListener('click', () => { page -= 1; render(); });
    next.addEventListener('click', () => { page += 1; render(); });
    (table.closest('.table-wrap') ?? table).after(controls);
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
