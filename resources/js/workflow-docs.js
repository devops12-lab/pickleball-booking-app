/**
 * CourtFlex backend workflow documentation portal.
 *
 * Navigation and search use the index embedded by the server. Documents are
 * loaded on demand from /docs-work-flow/documents/{slug} and cached. Their
 * HTML is rendered and sanitized on the server by league/commonmark (raw
 * HTML escaped, unsafe links removed); everything else uses textContent.
 */

import {
    CODE_WRAP_KEY,
    chevron,
    copyButton,
    el,
    highlightJson,
    isMac,
    prefersReducedMotion,
    setCodeWrap,
    slugify,
    toast,
    wrapToggle,
} from './docs/shared.js';

const dataElement = document.getElementById('workflow-docs-data');
const data = dataElement ? JSON.parse(dataElement.textContent) : null;

const state = {
    query: '',
    statuses: new Set(),
    expanded: new Set(),
    route: { slug: null, section: null },
    rendered: null,
};

const cache = new Map();
let tocObserver = null;
let loadToken = 0;

/* Helpers -------------------------------------------------------------- */

function statusKey(status) {
    return slugify(status);
}

function statusBadge(status) {
    return el('span', { class: 'status-badge', dataset: { status: statusKey(status) }, text: status });
}

function documentBySlug(slug) {
    return data.documents.find((document) => document.slug === slug) ?? null;
}

function documentHash(slug, section = null) {
    return `#/doc/${slug}${section ? `?section=${encodeURIComponent(section)}` : ''}`;
}

/* Code highlighting ------------------------------------------------------ */

const KEYWORDS = {
    php: 'abstract array as break case catch class const continue declare default do echo else elseif enum extends false final finally fn for foreach function if implements instanceof interface match namespace new null private protected public readonly return self static switch throw trait true try use while yield',
    js: 'async await break case catch class const continue default delete do else export extends false finally for from function if import in instanceof let new null of return static super switch this throw true try typeof undefined var void while yield',
    bash: 'cd cp curl echo export fi for if in mkdir npm php composer then vendor artisan',
    sql: 'select from where insert into update delete join left right inner on and or not null order by group limit values set create table index primary key foreign references',
};

const LANGUAGE_ALIASES = { javascript: 'js', jsx: 'js', ts: 'js', typescript: 'js', sh: 'bash', shell: 'bash', zsh: 'bash', console: 'bash', env: 'bash', dotenv: 'bash', mysql: 'sql' };

/**
 * Light, safe syntax colouring: comments, strings, numbers and keywords.
 */
function highlightCode(source, language) {
    const lang = LANGUAGE_ALIASES[language] ?? language;

    if (lang === 'json') {
        return highlightJson(source);
    }

    const keywords = new Set((KEYWORDS[lang] ?? '').split(' ').filter(Boolean));
    const hashComments = ['bash', 'yaml', 'yml', 'python', 'ini', 'toml'].includes(lang);
    const fragment = document.createDocumentFragment();

    if (keywords.size === 0 && !hashComments) {
        fragment.append(source);

        return fragment;
    }

    const commentPattern = hashComments ? '#[^\\n]*' : '\\/\\/[^\\n]*|\\/\\*[\\s\\S]*?\\*\\/';
    const pattern = new RegExp(`(${commentPattern})|("(?:\\\\.|[^"\\\\\\n])*"|'(?:\\\\.|[^'\\\\\\n])*'|\`(?:\\\\.|[^\`\\\\])*\`)|(\\$\\w+)|\\b(\\d+(?:\\.\\d+)?)\\b|\\b([A-Za-z_]\\w*)\\b`, 'g');
    let lastIndex = 0;
    let match;

    while ((match = pattern.exec(source)) !== null) {
        if (match.index > lastIndex) {
            fragment.append(source.slice(lastIndex, match.index));
        }

        const [token, comment, string, variable, number, word] = match;

        if (comment !== undefined) {
            fragment.append(el('span', { class: 'tok-comment', text: token }));
        } else if (string !== undefined) {
            fragment.append(el('span', { class: 'tok-string', text: token }));
        } else if (variable !== undefined) {
            fragment.append(el('span', { class: 'tok-variable', text: token }));
        } else if (number !== undefined) {
            fragment.append(el('span', { class: 'tok-number', text: token }));
        } else if (word !== undefined && keywords.has(lang === 'sql' ? word.toLowerCase() : word)) {
            fragment.append(el('span', { class: 'tok-keyword', text: token }));
        } else {
            fragment.append(token);
        }

        lastIndex = pattern.lastIndex;
    }

    fragment.append(source.slice(lastIndex));

    return fragment;
}

/**
 * Wrap rendered <pre><code> blocks in the shared code-block card.
 */
function enhanceCodeBlocks(container) {
    container.querySelectorAll('pre > code').forEach((code) => {
        const pre = code.parentElement;
        const language = ([...code.classList].find((name) => name.startsWith('language-')) ?? '').replace('language-', '').toLowerCase();
        const source = code.textContent.replace(/\n$/, '');
        const isDiagram = language === 'mermaid';
        const label = isDiagram ? 'Diagram source (Mermaid)' : (language || 'Code');

        code.replaceChildren(isDiagram ? source : highlightCode(source, language));

        const figure = el('figure', { class: 'code-block' },
            el('figcaption', { class: 'code-block-head' },
                el('span', { class: 'caption language', text: label }),
                el('span', { class: 'sample-actions' },
                    wrapToggle(),
                    copyButton(isDiagram ? 'Copy diagram' : 'Copy', source, `${isDiagram ? 'Diagram' : 'Code'} copied`))),
            isDiagram
                ? el('p', { class: 'diagram-note' }, 'Shown as source because no Mermaid renderer is installed. Paste it into ', el('a', { href: 'https://mermaid.live', target: '_blank', rel: 'noopener noreferrer', text: 'mermaid.live' }), ' to view the diagram.')
                : null);

        pre.replaceWith(figure);
        figure.append(pre);
    });
}

/* Sidebar ---------------------------------------------------------------- */

const nav = document.querySelector('[data-nav]');
const navEmpty = document.querySelector('[data-nav-empty]');
const navSummary = document.querySelector('[data-nav-summary]');
const expandAllButton = document.querySelector('[data-expand-all]');

function matchesFilters(document) {
    if (state.statuses.size > 0 && !state.statuses.has(document.status)) {
        return false;
    }

    const terms = state.query.toLowerCase().split(/\s+/).filter(Boolean);

    if (terms.length === 0) {
        return true;
    }

    const text = `${document.title} ${document.category} ${document.status} ${document.source} ${document.headings.map((heading) => heading.text).join(' ')}`.toLowerCase();

    return terms.every((term) => text.includes(term));
}

function isFiltering() {
    return state.query.trim() !== '' || state.statuses.size > 0;
}

function renderSidebar() {
    const filtering = isFiltering();
    const visible = data.documents.filter(matchesFilters);
    const allExpanded = data.categories.every((category) => state.expanded.has(category.name));

    nav.replaceChildren();
    navEmpty.hidden = visible.length > 0;
    navSummary.textContent = filtering ? `${visible.length} of ${data.documents.length} documents` : `${data.documents.length} documents`;
    expandAllButton.hidden = filtering;
    expandAllButton.textContent = allExpanded ? 'Collapse all' : 'Expand all';

    for (const category of data.categories) {
        const documents = visible.filter((document) => document.category === category.name);

        if (documents.length === 0) {
            continue;
        }

        const expanded = filtering || state.expanded.has(category.name);
        const listId = `nav-${slugify(category.name)}`;

        nav.append(el('div', { class: 'nav-module' },
            el('button', {
                type: 'button',
                class: 'nav-module-toggle',
                'aria-expanded': String(expanded),
                'aria-controls': listId,
                onClick: () => {
                    state.expanded.has(category.name) ? state.expanded.delete(category.name) : state.expanded.add(category.name);
                    renderSidebar();
                },
            }, chevron(), el('span', { text: category.name }), el('span', { class: 'badge tabular', text: filtering ? `${documents.length}/${category.count}` : String(category.count) })),
            el('div', { id: listId, hidden: !expanded },
                documents.map((document) => el('a', {
                    class: 'nav-doc',
                    href: documentHash(document.slug),
                    'aria-current': state.route.slug === document.slug ? 'page' : null,
                    title: `${document.status} · ${document.source}`,
                },
                el('span', { class: 'status-dot', dataset: { status: statusKey(document.status) }, 'aria-label': document.status }),
                el('span', { text: document.title }))))));
    }
}

/* Document view -------------------------------------------------------------- */

const article = document.querySelector('[data-doc]');
const tocAside = document.querySelector('[data-toc]');

function renderSkeleton() {
    article.replaceChildren(el('div', { class: 'skeleton', 'aria-label': 'Loading document' }, el('span'), el('span'), el('span'), el('span')));
    tocAside.replaceChildren();
}

function renderError(slug, message) {
    article.replaceChildren(el('div', { class: 'empty-state' },
        el('h1', { class: 'display', text: 'Document unavailable' }),
        el('p', { text: message }),
        el('div', { class: 'badges' },
            el('button', { type: 'button', class: 'button button-secondary', text: 'Try again', onClick: () => showDocument(slug, state.route.section, true) }),
            el('a', { class: 'button button-secondary', href: documentHash(data.documents[0]?.slug ?? ''), text: 'Open the first document' }))));
    tocAside.replaceChildren();
}

function tocList(headings, onNavigate) {
    return el('ul', { class: 'toc-list' }, headings.map((heading) => el('li', {},
        el('a', {
            href: documentHash(state.route.slug, heading.id),
            class: `toc-level-${heading.level}`,
            dataset: { target: heading.id },
            text: heading.text,
            onClick: (event) => {
                event.preventDefault();
                onNavigate(heading.id);
            },
        }))));
}

function scrollToSection(id, smooth = true) {
    const target = id ? document.getElementById(id) : null;

    if (target) {
        target.scrollIntoView({ behavior: smooth && !prefersReducedMotion() ? 'smooth' : 'auto', block: 'start' });
        history.replaceState(null, '', documentHash(state.route.slug, id));
    } else {
        window.scrollTo({ top: 0 });
    }
}

function renderDocument(document) {
    const notes = document.notes ?? [];
    const sources = document.sources ?? [];
    const unresolved = (document.references ?? []).filter((reference) => !reference.verified);

    const header = el('header', { class: 'doc-header' },
        el('p', { class: 'eyebrow', text: document.category }),
        el('h1', { class: 'display', text: document.title }),
        document.summary ? el('p', { class: 'lead', text: document.summary }) : null,
        el('div', { class: 'doc-meta' },
            statusBadge(document.status),
            el('span', { class: 'badge', title: 'Markdown source file', text: document.source }),
            copyButton('Copy path', document.source, 'File path copied'),
            el('span', { class: 'caption', text: `Updated ${document.updated_at}` })));

    const blocks = [header];

    if (notes.length > 0) {
        blocks.push(el('div', { class: 'doc-notice', role: 'note' },
            el('strong', { text: `Checked against the code: ${notes.length} ${notes.length === 1 ? 'conflict' : 'conflicts'} found` }),
            el('ul', {}, notes.map((note) => el('li', { text: note }))),
            el('span', {}, 'Where this page and the code disagree, the code is current. ', el('a', { href: documentHash('workflows/documentation-discrepancies'), text: 'See all discrepancies' }))));
    }

    if (sources.length > 0 || unresolved.length > 0) {
        const items = [
            ...sources.map((source) => el('li', {}, el('code', {
                class: `source-ref ${source.verified ? 'is-verified' : 'is-unresolved'}`,
                title: source.verified ? `Source: ${source.path}` : `Unresolved: ${source.path} was not found`,
                text: source.path,
            }))),
        ];

        blocks.push(el('details', { class: 'disclosure doc-sources' },
            el('summary', {},
                el('span', { text: `Source files (${sources.length})` }),
                unresolved.length > 0 ? el('span', { class: 'summary-message', text: `${unresolved.length} unresolved reference${unresolved.length === 1 ? '' : 's'} in text` }) : null,
                chevron()),
            el('div', { class: 'disclosure-body doc-prose' }, el('ul', { class: 'source-list' }, items))));
    }

    if (document.headings.length > 2) {
        blocks.push(el('details', { class: 'disclosure doc-toc-inline' },
            el('summary', {}, el('span', { text: 'On this page' }), chevron()),
            el('div', { class: 'disclosure-body' }, tocList(document.headings, (id) => scrollToSection(id)))));
    }

    const prose = el('div', { class: 'doc-prose' });
    // Server-rendered by league/commonmark with raw HTML escaped and unsafe links removed.
    prose.innerHTML = document.html;
    enhanceCodeBlocks(prose);
    blocks.push(prose, pager(document));

    article.replaceChildren(...blocks);

    tocAside.replaceChildren(...(document.headings.length > 0
        ? [el('p', { class: 'toc-title', text: 'On this page' }), tocList(document.headings, (id) => scrollToSection(id))]
        : []));

    observeHeadings(document.headings);
}

function pager(document) {
    const index = data.documents.findIndex((item) => item.slug === document.slug);
    const previous = data.documents[index - 1];
    const next = data.documents[index + 1];

    const link = (item, label, className) => el('a', { href: documentHash(item.slug), class: className },
        el('span', { class: 'caption', text: label }),
        el('span', { class: 'pager-title' }, el('span', { class: 'status-dot', dataset: { status: statusKey(item.status) } }), el('span', { text: item.title })));

    return el('nav', { class: 'pager', 'aria-label': 'Previous and next documents' },
        previous ? link(previous, 'Previous', 'pager-previous') : null,
        next ? link(next, 'Next', 'pager-next') : null);
}

/**
 * Highlight the heading currently in view in the table of contents.
 */
function observeHeadings(headings) {
    tocObserver?.disconnect();

    const links = [...document.querySelectorAll('.toc-list a')];
    const setActive = (id) => links.forEach((link) => link.setAttribute('aria-current', String(link.dataset.target === id)));

    tocObserver = new IntersectionObserver((entries) => {
        const visible = entries.filter((entry) => entry.isIntersecting);

        if (visible.length > 0) {
            setActive(visible[0].target.id);
        }
    }, { rootMargin: '-80px 0px -70% 0px' });

    headings.forEach((heading) => {
        const target = document.getElementById(heading.id);

        if (target) {
            tocObserver.observe(target);
        }
    });
}

async function fetchDocument(slug) {
    if (cache.has(slug)) {
        return cache.get(slug);
    }

    const response = await fetch(`${data.meta.document_url}/${slug.split('/').map(encodeURIComponent).join('/')}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });

    if (response.status === 404) {
        throw new Error('This document does not exist or was removed from the docs folder.');
    }

    if (!response.ok) {
        throw new Error(`The document couldn't be loaded (status ${response.status}). Try again.`);
    }

    const payload = await response.json();
    cache.set(slug, payload.data);

    return payload.data;
}

async function showDocument(slug, section, force = false) {
    if (!force && state.rendered === slug) {
        scrollToSection(section);

        return;
    }

    const token = ++loadToken;
    const summary = documentBySlug(slug);

    if (!summary) {
        state.rendered = null;
        renderError(slug, 'This document does not exist or was removed from the docs folder.');

        return;
    }

    document.title = `${summary.title} · ${data.meta.title}`;

    if (!cache.has(slug)) {
        renderSkeleton();
    }

    try {
        const loaded = await fetchDocument(slug);

        if (token !== loadToken) {
            return;
        }

        renderDocument(loaded);
        state.rendered = slug;
        scrollToSection(section, false);
    } catch (error) {
        if (token === loadToken) {
            state.rendered = null;
            renderError(slug, error.message || "The document couldn't be loaded. Check your connection and try again.");
        }
    }
}

/* Routing ---------------------------------------------------------------- */

function parseHash() {
    const raw = window.location.hash.replace(/^#\/?/, '');
    const match = raw.match(/^doc\/([^?]+)(?:\?section=(.+))?$/);

    if (match) {
        return { slug: decodeURIComponent(match[1]), section: match[2] ? decodeURIComponent(match[2]) : null };
    }

    return { slug: data.documents[0]?.slug ?? null, section: null };
}

function route() {
    state.route = parseHash();

    const current = documentBySlug(state.route.slug);

    if (current) {
        state.expanded.add(current.category);
    }

    renderSidebar();
    closeDrawer();
    document.querySelector('.nav-doc[aria-current="page"]')?.scrollIntoView({ block: 'nearest' });

    if (state.route.slug) {
        showDocument(state.route.slug, state.route.section);
    }
}

/* Search palette -------------------------------------------------------- */

const palette = document.querySelector('[data-palette]');
const paletteInput = document.querySelector('[data-palette-input]');
const paletteResults = document.querySelector('[data-palette-results]');
let paletteMatches = [];
let paletteIndex = 0;

function searchDocuments(query) {
    const terms = query.toLowerCase().split(/\s+/).filter(Boolean);
    const results = [];

    if (terms.length === 0) {
        return data.documents.slice(0, 40).map((document) => ({ slug: document.slug, section: null, title: document.title, meta: `${document.category} · ${document.source}`, status: document.status, score: 0 }));
    }

    const includesAll = (text) => terms.every((term) => text.includes(term));

    for (const document of data.documents) {
        const titleText = `${document.title} ${document.source} ${document.category}`.toLowerCase();

        if (includesAll(titleText)) {
            results.push({ slug: document.slug, section: null, title: document.title, meta: `${document.category} · ${document.source}`, status: document.status, score: 3 });
        }

        for (const section of document.sections ?? []) {
            if (!section.heading) {
                continue;
            }

            const headingText = section.heading.toLowerCase();
            const bodyText = `${headingText} ${section.text}`.toLowerCase();

            if (includesAll(headingText) || (!includesAll(titleText) && includesAll(bodyText))) {
                const excerptIndex = section.text.toLowerCase().indexOf(terms[0]);
                const excerpt = excerptIndex >= 0 ? section.text.slice(Math.max(0, excerptIndex - 40), excerptIndex + 80).trim() : '';

                results.push({
                    slug: document.slug,
                    section: section.id,
                    title: section.heading,
                    meta: excerpt ? `${document.title} · …${excerpt}…` : document.title,
                    status: document.status,
                    score: includesAll(headingText) ? 2 : 1,
                });
            }
        }
    }

    return results.sort((a, b) => b.score - a.score).slice(0, 60);
}

function renderPalette() {
    const query = paletteInput.value.trim();
    paletteMatches = searchDocuments(query);
    paletteIndex = 0;
    paletteResults.replaceChildren();

    if (paletteMatches.length === 0) {
        paletteResults.append(el('li', { class: 'palette-empty', role: 'presentation', text: `Nothing matches "${query}". Try a module, table or class name.` }));
        paletteInput.removeAttribute('aria-activedescendant');

        return;
    }

    paletteMatches.forEach((item, index) => {
        paletteResults.append(el('li', {
            id: `palette-option-${index}`,
            class: 'palette-option',
            role: 'option',
            'aria-selected': String(index === 0),
            onClick: () => choosePalette(index),
            onMousemove: () => movePalette(index),
        },
        el('span', { class: 'method', text: item.section ? '#' : 'DOC' }),
        el('span', { class: 'palette-option-title', text: item.title }),
        el('span', { class: 'palette-option-meta', text: item.meta })));
    });

    paletteInput.setAttribute('aria-activedescendant', 'palette-option-0');
}

function movePalette(index) {
    if (paletteMatches.length === 0) {
        return;
    }

    paletteIndex = (index + paletteMatches.length) % paletteMatches.length;
    paletteResults.querySelectorAll('[role="option"]').forEach((option, optionIndex) => option.setAttribute('aria-selected', String(optionIndex === paletteIndex)));
    document.getElementById(`palette-option-${paletteIndex}`)?.scrollIntoView({ block: 'nearest' });
    paletteInput.setAttribute('aria-activedescendant', `palette-option-${paletteIndex}`);
}

function choosePalette(index = paletteIndex) {
    const item = paletteMatches[index];

    if (!item) {
        return;
    }

    palette.close();

    const hash = documentHash(item.slug, item.section);

    if (window.location.hash === hash) {
        showDocument(item.slug, item.section);
    } else {
        window.location.hash = hash;
    }
}

function openPalette() {
    if (palette.open) {
        return;
    }

    closeDrawer();
    paletteInput.value = '';
    renderPalette();
    palette.showModal();
    paletteInput.focus();
}

/* Drawer & theme --------------------------------------------------------- */

const sidebar = document.getElementById('sidebar');
const scrim = document.querySelector('.scrim');
const openButton = document.querySelector('[data-drawer-open]');

function openDrawer() {
    sidebar.classList.add('is-open');
    scrim.hidden = false;
    openButton.setAttribute('aria-expanded', 'true');
    document.body.style.overflow = 'hidden';
}

function closeDrawer() {
    if (!sidebar.classList.contains('is-open')) {
        return;
    }

    sidebar.classList.remove('is-open');
    scrim.hidden = true;
    openButton.setAttribute('aria-expanded', 'false');
    document.body.style.overflow = '';
}

function updateThemeButton() {
    const isDark = document.documentElement.dataset.theme === 'dark';
    document.querySelector('[data-theme-toggle]').setAttribute('aria-label', isDark ? 'Switch to light theme' : 'Switch to dark theme');
}

/* Boot ------------------------------------------------------------------------ */

function boot() {
    if (!data) {
        return;
    }

    if (data.initial) {
        cache.set(data.initial.slug, data.initial);
    }

    setCodeWrap(localStorage.getItem(CODE_WRAP_KEY) !== 'false');

    const shortcutLabel = document.querySelector('[data-shortcut-label]');

    if (shortcutLabel) {
        shortcutLabel.textContent = isMac ? '⌘ K' : 'Ctrl K';
    }

    const filterInput = document.querySelector('[data-filter]');

    filterInput.addEventListener('input', () => {
        state.query = filterInput.value;
        renderSidebar();
    });

    filterInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            const first = data.documents.find(matchesFilters);

            if (first) {
                window.location.hash = documentHash(first.slug);
            }
        }
    });

    document.querySelectorAll('[data-status-filter]').forEach((button) => {
        button.addEventListener('click', () => {
            const status = button.dataset.statusFilter;
            state.statuses.has(status) ? state.statuses.delete(status) : state.statuses.add(status);
            button.setAttribute('aria-pressed', String(state.statuses.has(status)));
            renderSidebar();
        });
    });

    document.querySelector('[data-clear-filters]').addEventListener('click', () => {
        state.query = '';
        state.statuses.clear();
        filterInput.value = '';
        document.querySelectorAll('[data-status-filter]').forEach((button) => button.setAttribute('aria-pressed', 'false'));
        renderSidebar();
    });

    expandAllButton.addEventListener('click', () => {
        const allExpanded = data.categories.every((category) => state.expanded.has(category.name));
        data.categories.forEach((category) => (allExpanded ? state.expanded.delete(category.name) : state.expanded.add(category.name)));
        renderSidebar();
    });

    document.querySelector('[data-palette-open]').addEventListener('click', openPalette);
    document.querySelector('[data-palette-close]').addEventListener('click', () => palette.close());
    paletteInput.addEventListener('input', renderPalette);
    paletteInput.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            movePalette(paletteIndex + 1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            movePalette(paletteIndex - 1);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            choosePalette();
        }
    });
    palette.addEventListener('click', (event) => {
        if (event.target === palette) {
            palette.close();
        }
    });

    openButton.addEventListener('click', () => {
        openDrawer();
        filterInput.focus();
    });
    document.querySelectorAll('[data-drawer-close]').forEach((button) => button.addEventListener('click', closeDrawer));
    window.matchMedia('(min-width: 1024px)').addEventListener('change', closeDrawer);

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !palette.open) {
            closeDrawer();
        }

        const target = event.target;
        const typing = target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target.isContentEditable;

        if ((event.key === 'k' || event.key === 'K') && (event.ctrlKey || event.metaKey)) {
            event.preventDefault();
            palette.open ? palette.close() : openPalette();
        } else if (event.key === '/' && !typing) {
            event.preventDefault();
            openPalette();
        }
    });

    // Broken links point nowhere; keep them from changing the page.
    article.addEventListener('click', (event) => {
        if (event.target.closest('a.doc-link-broken')) {
            event.preventDefault();
            toast('This link points to a document that does not exist.', 'error');
        }
    });

    document.querySelector('[data-theme-toggle]').addEventListener('click', () => {
        const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.theme = next;
        localStorage.setItem('api-docs-theme', next);
        updateThemeButton();
    });

    updateThemeButton();
    window.addEventListener('hashchange', route);
    route();
}

boot();
