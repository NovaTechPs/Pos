/**
 * WaslaPOS always shows Western digits (0-9), whatever the interface language or the
 * browser/device locale. This module:
 *  - makes every Intl / toLocale* call use the Latin numbering system;
 *  - rewrites Arabic-Indic and Persian digits found in page text, placeholders and titles;
 *  - converts digits typed in Arabic into Western digits while the user types.
 */
const EASTERN_DIGITS = /[٠-٩۰-۹]/g;

export function toLatinDigits(value) {
    if (typeof value !== 'string') {
        return value;
    }

    return value.replace(EASTERN_DIGITS, digit => {
        const code = digit.charCodeAt(0);

        return String(code >= 0x06F0 ? code - 0x06F0 : code - 0x0660);
    });
}

export function withLatinNumbers(locales) {
    const list = locales === undefined ? [navigator.language || 'ar'] : [].concat(locales);

    return list.map(locale => {
        const tag = String(locale);
        if (/-u-(?:.+-)?nu-/i.test(tag)) {
            return tag.replace(/(-u-(?:.+-)?nu-)[a-z0-9]+/i, '$1latn');
        }

        return tag.includes('-u-') ? `${tag}-nu-latn` : `${tag}-u-nu-latn`;
    });
}

function patchIntl() {
    if (typeof Intl === 'undefined' || Intl.__waslaLatinDigits) {
        return;
    }

    ['NumberFormat', 'DateTimeFormat', 'RelativeTimeFormat', 'ListFormat'].forEach(name => {
        const Original = Intl[name];
        if (typeof Original !== 'function') {
            return;
        }

        const Patched = function (locales, options) {
            const safeLocales = withLatinNumbers(locales);

            return new.target ? new Original(safeLocales, options) : Original(safeLocales, options);
        };
        Patched.prototype = Original.prototype;
        Patched.supportedLocalesOf = Original.supportedLocalesOf.bind(Original);
        Intl[name] = Patched;
    });

    const numberToLocale = Number.prototype.toLocaleString;
    Number.prototype.toLocaleString = function (locales, options) {
        return numberToLocale.call(this, withLatinNumbers(locales), options);
    };

    if (typeof BigInt !== 'undefined') {
        const bigintToLocale = BigInt.prototype.toLocaleString;
        BigInt.prototype.toLocaleString = function (locales, options) {
            return bigintToLocale.call(this, withLatinNumbers(locales), options);
        };
    }

    ['toLocaleString', 'toLocaleDateString', 'toLocaleTimeString'].forEach(method => {
        const original = Date.prototype[method];
        Date.prototype[method] = function (locales, options) {
            return original.call(this, withLatinNumbers(locales), options);
        };
    });

    Intl.__waslaLatinDigits = true;
}

const TEXT_ATTRIBUTES = ['placeholder', 'title', 'aria-label', 'data-label'];

function fixElementAttributes(element) {
    TEXT_ATTRIBUTES.forEach(attribute => {
        const value = element.getAttribute?.(attribute);
        const fixed = toLatinDigits(value);
        if (value && fixed !== value) {
            element.setAttribute(attribute, fixed);
        }
    });
}

function fixTree(root) {
    if (!root) {
        return;
    }

    if (root.nodeType === Node.TEXT_NODE) {
        const fixed = toLatinDigits(root.nodeValue);
        if (fixed !== root.nodeValue) {
            root.nodeValue = fixed;
        }

        return;
    }

    if (root.nodeType !== Node.ELEMENT_NODE && root.nodeType !== Node.DOCUMENT_NODE) {
        return;
    }

    if (root.nodeType === Node.ELEMENT_NODE) {
        fixElementAttributes(root);
    }

    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT);
    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
        if (node.nodeType === Node.TEXT_NODE) {
            const fixed = toLatinDigits(node.nodeValue);
            if (fixed !== node.nodeValue) {
                node.nodeValue = fixed;
            }
        } else {
            fixElementAttributes(node);
        }
    }
}

function convertTypedDigits(event) {
    const field = event.target;
    if (!(field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement)) {
        return;
    }

    const fixed = toLatinDigits(field.value);
    if (fixed === field.value) {
        return;
    }

    // Each digit is replaced by exactly one character, so the caret stays where it was.
    let start = null;
    let end = null;
    try {
        start = field.selectionStart;
        end = field.selectionEnd;
    } catch (_) {}
    field.value = fixed;
    try {
        if (start !== null) {
            field.setSelectionRange(start, end);
        }
    } catch (_) {}
}

function start() {
    patchIntl();

    if (typeof document === 'undefined') {
        return;
    }

    // Capture phase so Livewire/Alpine listeners already see Western digits.
    document.addEventListener('input', convertTypedDigits, true);

    const observe = () => {
        fixTree(document.body);
        new MutationObserver(mutations => {
            mutations.forEach(mutation => {
                if (mutation.type === 'characterData') {
                    fixTree(mutation.target);
                } else if (mutation.type === 'attributes') {
                    fixElementAttributes(mutation.target);
                } else {
                    mutation.addedNodes.forEach(fixTree);
                }
            });
        }).observe(document.body, {
            childList: true,
            subtree: true,
            characterData: true,
            attributes: true,
            attributeFilter: TEXT_ATTRIBUTES,
        });
    };

    if (document.body) {
        observe();
    } else {
        document.addEventListener('DOMContentLoaded', observe, { once: true });
    }
}

start();
