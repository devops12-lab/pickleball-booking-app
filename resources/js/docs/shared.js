/**
 * Helpers shared by the CourtFlex documentation portals
 * (/docs-api-endpoints and /docs-work-flow).
 * All text is inserted with textContent, never as HTML.
 */

const toastElement = document.querySelector('[data-toast]');
let toastTimer = null;

export const CODE_WRAP_KEY = 'docs-code-wrap';
export const isMac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);

export function el(tag, props = {}, ...children) {
    const node = document.createElement(tag);

    for (const [key, value] of Object.entries(props)) {
        if (value === null || value === undefined || value === false) {
            continue;
        }

        if (key === 'class') {
            node.className = value;
        } else if (key === 'text') {
            node.textContent = value;
        } else if (key.startsWith('on')) {
            node.addEventListener(key.slice(2).toLowerCase(), value);
        } else if (key === 'dataset') {
            Object.assign(node.dataset, value);
        } else {
            node.setAttribute(key, value === true ? '' : value);
        }
    }

    for (const child of children.flat()) {
        if (child === null || child === undefined || child === false) {
            continue;
        }

        node.append(child instanceof Node ? child : document.createTextNode(String(child)));
    }

    return node;
}

export function svg(path) {
    const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    icon.setAttribute('viewBox', '0 0 24 24');
    icon.setAttribute('aria-hidden', 'true');
    icon.innerHTML = path;

    return icon;
}

export const chevron = () => svg('<path d="m9 6 6 6-6 6" />');

export function slugify(value) {
    return String(value).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
}

export function prettyJson(value) {
    return JSON.stringify(value, null, 2);
}

/**
 * Wrap JSON tokens in spans for light syntax colouring.
 */
export function highlightJson(source) {
    const fragment = document.createDocumentFragment();
    const pattern = /("(?:\\.|[^"\\])*")(\s*:)?|\b(true|false|null)\b|(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)|([{}[\],])/g;
    let lastIndex = 0;
    let match;

    while ((match = pattern.exec(source)) !== null) {
        if (match.index > lastIndex) {
            fragment.append(source.slice(lastIndex, match.index));
        }

        if (match[1] !== undefined) {
            fragment.append(el('span', { class: match[2] ? 'tok-key' : 'tok-string', text: match[1] }));

            if (match[2]) {
                fragment.append(el('span', { class: 'tok-punct', text: match[2] }));
            }
        } else if (match[3] !== undefined) {
            fragment.append(el('span', { class: 'tok-literal', text: match[3] }));
        } else if (match[4] !== undefined) {
            fragment.append(el('span', { class: 'tok-number', text: match[4] }));
        } else {
            fragment.append(el('span', { class: 'tok-punct', text: match[5] }));
        }

        lastIndex = pattern.lastIndex;
    }

    fragment.append(source.slice(lastIndex));

    return fragment;
}

export function toast(message, type = 'success') {
    if (!toastElement) {
        return;
    }

    toastElement.textContent = message;
    toastElement.classList.toggle('toast-error', type === 'error');
    toastElement.hidden = false;

    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        toastElement.hidden = true;
    }, type === 'error' ? 4000 : 2200);
}

export function fallbackCopy(text) {
    const textarea = el('textarea', { readonly: true, 'aria-hidden': 'true' });
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.top = '-1000px';
    textarea.style.opacity = '0';
    document.body.append(textarea);
    textarea.focus();
    textarea.select();
    textarea.setSelectionRange(0, text.length);

    let copied = false;

    try {
        copied = document.execCommand('copy');
    } catch {
        copied = false;
    }

    textarea.remove();

    return copied;
}

/**
 * Show "Copied" on the button itself for a moment.
 */
export function flashButton(button) {
    if (!button || button.dataset.copying === 'true') {
        return;
    }

    const original = button.textContent;
    button.dataset.copying = 'true';
    button.classList.add('is-copied');
    button.textContent = 'Copied';

    setTimeout(() => {
        button.textContent = original;
        button.classList.remove('is-copied');
        delete button.dataset.copying;
    }, 1500);
}

export async function copyText(text, message = 'Copied', button = null) {
    if (typeof text !== 'string' || text.trim() === '') {
        toast('Nothing to copy.', 'error');

        return false;
    }

    let copied = false;

    if (navigator.clipboard && window.isSecureContext) {
        try {
            await navigator.clipboard.writeText(text);
            copied = true;
        } catch {
            copied = false;
        }
    }

    if (!copied) {
        copied = fallbackCopy(text);
    }

    if (copied) {
        flashButton(button);
    }

    toast(copied ? message : "Couldn't copy. Select the text and copy it manually.", copied ? 'success' : 'error');

    return copied;
}

export function copyButton(label, getText, message, extraClass = '') {
    const button = el('button', { type: 'button', class: `copy-button ${extraClass}`.trim(), text: label });
    button.addEventListener('click', () => copyText(typeof getText === 'function' ? getText() : getText, message, button));

    return button;
}

export function codeBlock(label, code, { json = false, copyLabel = 'Copy', copyMessage = null, className = '' } = {}) {
    const codeElement = el('code');

    if (json) {
        codeElement.append(highlightJson(code));
    } else {
        codeElement.textContent = code;
    }

    return el('figure', { class: `code-block ${className}`.trim() },
        el('figcaption', { class: 'code-block-head' },
            el('span', { class: 'caption', text: label }),
            copyButton(copyLabel, code, copyMessage ?? `${label} copied`)),
        el('pre', {}, codeElement));
}

/**
 * Toggle soft-wrapping of long lines in every code block on the page.
 */
export function setCodeWrap(enabled) {
    document.documentElement.dataset.codeWrap = String(enabled);
    localStorage.setItem(CODE_WRAP_KEY, String(enabled));
    document.querySelectorAll('[data-wrap-toggle]').forEach((button) => button.setAttribute('aria-pressed', String(enabled)));
}

export function wrapToggle() {
    return el('button', {
        type: 'button',
        class: 'copy-button',
        text: 'Wrap',
        title: 'Wrap long lines',
        'aria-pressed': String(document.documentElement.dataset.codeWrap === 'true'),
        dataset: { wrapToggle: 'true' },
        onClick: () => setCodeWrap(document.documentElement.dataset.codeWrap !== 'true'),
    });
}

export function prefersReducedMotion() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

