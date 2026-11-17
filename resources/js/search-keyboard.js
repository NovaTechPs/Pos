/**
 * Arrow-key navigation for search boxes and their results.
 *
 * Mark the element that wraps a search input and its results with [data-search-nav]
 * and every result with [data-search-option]:
 *  - ArrowDown / ArrowUp in the search box move the highlight through the results
 *    while the cursor stays in the box, so typing can continue.
 *  - Enter adds or opens the highlighted result (its own click action, or the
 *    [data-search-open] control inside it). Without a highlight, Enter keeps the
 *    search box's own behaviour (for example a barcode lookup).
 *  - Home / End jump to the first / last result and Escape clears the highlight.
 *  - The highlighted result gets the shared dark-blue `search-result-active` style.
 * A focused result reacts to the same keys, and ArrowUp from the first result
 * returns to the search box.
 * When the wrapper also holds other fields (a page with forms or modals), mark the
 * search box (or its wrapper) with [data-search-input] so only it drives the list.
 * Results that are not clickable themselves open through their [data-search-open]
 * control; without one, Enter leaves them alone.
 */
const CONTAINER = '[data-search-nav]';
const OPTION = '[data-search-option]';
const ACTIVE_CLASS = 'search-result-active';
const SEARCH_INPUT = 'input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not([type="button"]):not([type="submit"])';

const activeIndexes = new WeakMap();

function isUsable(option) {
    return option.getClientRects().length > 0 && !option.matches(':disabled, [aria-disabled="true"]');
}

export function searchOptions(container) {
    return [...container.querySelectorAll(OPTION)].filter((option) => option.closest(CONTAINER) === container && isUsable(option));
}

function isSearchBox(input, container) {
    if (!input.matches(SEARCH_INPUT) || input.closest(OPTION) || input.closest(CONTAINER) !== container) {
        return false;
    }

    return !container.querySelector('[data-search-input]') || Boolean(input.closest('[data-search-input]'));
}

function searchInput(container) {
    return [...container.querySelectorAll(SEARCH_INPUT)].find((input) => isSearchBox(input, container) && isUsable(input));
}

function paint(container) {
    const options = searchOptions(container);
    const index = activeIndexes.get(container) ?? -1;

    container.querySelectorAll(OPTION).forEach((option) => {
        const isActive = option === options[index];
        if (option.classList.contains(ACTIVE_CLASS) !== isActive) {
            option.classList.toggle(ACTIVE_CLASS, isActive);
        }
        if (isActive) {
            option.setAttribute('aria-selected', 'true');
        } else if (option.hasAttribute('aria-selected')) {
            option.removeAttribute('aria-selected');
        }
    });

    return options[index] ?? null;
}

export function setActiveOption(container, index) {
    const options = searchOptions(container);
    const clamped = options.length === 0 ? -1 : Math.max(-1, Math.min(options.length - 1, index));
    activeIndexes.set(container, clamped);
    const active = paint(container);
    active?.scrollIntoView({ block: 'nearest' });

    return active;
}

export function activeOption(container) {
    return searchOptions(container)[activeIndexes.get(container) ?? -1] ?? null;
}

export function chooseOption(option) {
    const target = option.matches('a, button, [wire\\:click], [x-on\\:click], [onclick]')
        ? option
        : option.querySelector('[data-search-open]');
    target?.click();
}

function handleKey(event, container, fromOption) {
    const options = searchOptions(container);
    if (options.length === 0) {
        return;
    }

    const current = fromOption ? options.indexOf(fromOption) : activeIndexes.get(container) ?? -1;
    const targets = {
        ArrowDown: Math.min(options.length - 1, current + 1),
        ArrowUp: current - 1,
        Home: 0,
        End: options.length - 1,
    };

    if (event.key in targets) {
        if (!fromOption && (event.key === 'Home' || event.key === 'End') && current === -1) {
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        const next = setActiveOption(container, targets[event.key]);
        if (fromOption) {
            (next ?? searchInput(container))?.focus({ preventScroll: true });
        }

        return;
    }

    if (event.key === 'Enter') {
        const option = fromOption ?? options[current];
        if (!option) {
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        chooseOption(option);

        return;
    }

    if (event.key === 'Escape' && current !== -1 && !fromOption) {
        event.preventDefault();
        event.stopPropagation();
        setActiveOption(container, -1);
    }
}

document.addEventListener('keydown', (event) => {
    if (!['ArrowDown', 'ArrowUp', 'Home', 'End', 'Enter', 'Escape'].includes(event.key)) return;
    if (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey || event.isComposing) return;
    if (!(event.target instanceof HTMLElement)) return;

    const option = event.target.closest(OPTION);
    const container = event.target.closest(CONTAINER);
    if (!container) return;

    if (option && option === event.target && option.closest(CONTAINER) === container) {
        handleKey(event, container, option);
    } else if (!option && isSearchBox(event.target, container)) {
        handleKey(event, container, null);
    }
}, true);

document.addEventListener('input', (event) => {
    const container = event.target instanceof HTMLElement ? event.target.closest(CONTAINER) : null;
    if (container && (activeIndexes.get(container) ?? -1) !== -1 && isSearchBox(event.target, container)) {
        setActiveOption(container, -1);
    }
});

document.addEventListener('mousemove', (event) => {
    const option = event.target instanceof Element ? event.target.closest(OPTION) : null;
    const container = option?.closest(CONTAINER);
    if (!container) return;

    const index = searchOptions(container).indexOf(option);
    if (index !== -1 && index !== activeIndexes.get(container)) {
        activeIndexes.set(container, index);
        paint(container);
    }
});

document.addEventListener('focusin', (event) => {
    const option = event.target instanceof Element ? event.target.closest(OPTION) : null;
    const container = option?.closest(CONTAINER);
    if (container && option === event.target) {
        activeIndexes.set(container, searchOptions(container).indexOf(option));
        paint(container);
    }
});

/** Livewire re-renders drop the highlight class, so paint it back after every DOM change. */
new MutationObserver(() => {
    document.querySelectorAll(CONTAINER).forEach((container) => {
        if ((activeIndexes.get(container) ?? -1) !== -1) {
            paint(container);
        }
    });
}).observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });
