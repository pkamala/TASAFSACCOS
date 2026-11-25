const categoryEditor = document.querySelector('#category-edit');

if (typeof categoryEditor?.showModal === 'function') {
    categoryEditor.close();
    categoryEditor.showModal();
    categoryEditor.addEventListener('cancel', event => {
        event.preventDefault();
        window.location.assign(document.querySelector('#cancel-category-edit').href);
    });
}
