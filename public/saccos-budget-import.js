const budgetImport = document.querySelector('#budget-import');

if (typeof budgetImport?.showModal === 'function') {
    if (budgetImport.open) {
        budgetImport.close();
        budgetImport.showModal();
    }

    document.querySelector('[data-open-budget-import]').addEventListener('click', event => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        if (!budgetImport.open) budgetImport.showModal();
    });

    budgetImport.querySelector('[data-close-budget-import]').addEventListener('click', event => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        if (!new URL(location.href).searchParams.has('import')) {
            event.preventDefault();
            budgetImport.close();
        }
    });
}
