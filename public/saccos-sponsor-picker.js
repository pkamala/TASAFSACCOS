document.querySelectorAll('[data-sponsor-picker]').forEach(picker => {
    const dialog = picker.querySelector('dialog');
    if (typeof dialog.showModal !== 'function') return;

    const search = dialog.querySelector('input');
    const status = dialog.querySelector('[data-sponsor-search-status]');
    const results = dialog.querySelector('[data-sponsor-results]');
    const heading = dialog.querySelector('h2');
    const inputs = [...picker.querySelectorAll('input[name="sponsors[]"]')];
    const names = picker.querySelectorAll('[data-sponsor-name]');
    const buttons = picker.querySelectorAll('[data-open-sponsors]');
    let selectedSlot;
    let pending;
    let timer;

    function cancelSearch() {
        clearTimeout(timer);
        pending?.abort();
    }

    async function loadResults() {
        const controller = new AbortController();
        pending = controller;
        const url = new URL(picker.dataset.searchUrl, location.origin);
        url.searchParams.set('q', search.value.trim());

        try {
            const response = await fetch(url, {
                headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                signal: controller.signal,
            });
            if (!response.ok) throw new Error('Search failed');
            const members = await response.json();
            if (controller.signal.aborted) return;

            const otherNumber = inputs.find((_, index) => index !== selectedSlot).value.trim().toUpperCase();
            const available = members.filter(member => member.registration_no.toUpperCase() !== otherNumber);
            available.forEach(member => {
                const name = [member.first_name, member.middle_name, member.last_name].filter(Boolean).join(' ');
                const item = document.createElement('li');
                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = `${name} · ${member.registration_no}`;
                button.addEventListener('click', () => {
                    inputs[selectedSlot].value = member.registration_no;
                    names[selectedSlot].textContent = name;
                    dialog.close();
                });
                item.append(button);
                results.append(item);
            });
            status.textContent = available.length
                ? (members.length === 20 ? 'Showing up to 20 matches. Refine your search for more specific results.' : `${available.length} eligible members found.`)
                : 'No eligible members found.';
        } catch (error) {
            if (controller.signal.aborted) return;
            status.textContent = 'Could not load members. Search again or enter the registration number directly.';
        }
    }

    function searchMembers(delay = 0) {
        cancelSearch();
        status.textContent = 'Searching…';
        results.replaceChildren();
        timer = setTimeout(loadResults, delay);
    }

    buttons.forEach((button, index) => {
        button.hidden = false;
        button.addEventListener('click', () => {
            selectedSlot = index;
            heading.textContent = `Choose sponsor ${index + 1}`;
            search.value = '';
            dialog.showModal();
            searchMembers();
        });
        inputs[index].addEventListener('input', () => {
            names[index].textContent = '';
        });
    });

    search.addEventListener('input', () => searchMembers(200));
    search.addEventListener('keydown', event => {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        searchMembers();
    });
    dialog.querySelector('[data-close-sponsors]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', cancelSearch);
});
