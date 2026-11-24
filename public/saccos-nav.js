const navigation = document.querySelector('.main-nav');
const menus = Array.from(navigation?.querySelectorAll('.nav-menu') ?? []);

menus.forEach(menu => {
    menu.addEventListener('toggle', () => {
        if (menu.open) {
            menus.filter(other => other !== menu).forEach(other => { other.open = false; });
        }
    });
});

document.addEventListener('click', event => {
    menus.filter(menu => !menu.contains(event.target)).forEach(menu => { menu.open = false; });
});

document.addEventListener('keydown', event => {
    const open = menus.find(menu => menu.open);
    if (event.key === 'Escape' && open) {
        open.open = false;
        if (open.contains(document.activeElement)) {
            open.querySelector('summary').focus();
        }
    }
});

const taskLink = navigation?.querySelector('[data-task-count-url]');

if (taskLink) {
    const badge = taskLink.querySelector('[data-task-count]');
    let loading = false;

    const refresh = async () => {
        if (loading || document.hidden) return;

        loading = true;
        try {
            const response = await fetch(taskLink.dataset.taskCountUrl, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            const count = response.ok ? (await response.json()).count : 0;
            badge.hidden = !(Number.isInteger(count) && count > 0);
            badge.textContent = badge.hidden ? '' : String(count);
        } catch {
            badge.hidden = true;
            badge.textContent = '';
        } finally {
            loading = false;
        }
    };

    refresh();
    document.addEventListener('visibilitychange', refresh);
    window.setInterval(refresh, 60000);
}
