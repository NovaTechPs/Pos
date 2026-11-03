document.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter' || !(event.target instanceof HTMLElement)) return;
    if (event.target.closest('textarea, [contenteditable="true"]')) return;

    const form = event.target.closest('form');
    if (!form) return;

    const submitAction = form.getAttribute('wire:submit') || form.getAttribute('wire:submit.prevent') || '';
    if (/^(search|applyFilters|filter|preview|calculate|lookup)/i.test(submitAction.trim())) return;

    event.preventDefault();
}, true);
