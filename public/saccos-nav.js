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
