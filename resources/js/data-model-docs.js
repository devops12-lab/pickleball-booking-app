/**
 * CourtFlex Data Model Documentation — client-side SPA.
 *
 * Renders the schema data discovered by the backend (embedded as JSON),
 * and handles search, filtering, navigation and the command palette.
 * All server data is inserted with textContent, never as HTML.
 *
 * Hash routes: #/overview, #/domain/{slug}, #/table/{slug}
 */

import {
    chevron,
    el,
    isMac,
    slugify,
    toast,
} from './docs/shared.js';

const dataElement = document.getElementById('data-model-docs-data');
const data = dataElement ? JSON.parse(dataElement.textContent) : null;

const domains = data ? data.domains : [];

// Lookup maps
const domainBySlug = Object.create(null);
const tableBySlug = Object.create(null);
const tableToDomain = Object.create(null);

domains.forEach((d) => {
    domainBySlug[d.slug] = d;
    d.tables.forEach((t) => {
        tableBySlug[t.slug] = t;
        tableToDomain[t.slug] = d;
    });
});

/* DOM refs ------------------------------------------------------------- */

const views = {
    overview: document.querySelector('[data-view="overview"]'),
    domain: document.querySelector('[data-view="domain"]'),
    table: document.querySelector('[data-view="table"]'),
};

const sidebar = document.getElementById('sidebar');
const searchInput = document.querySelector('[data-search]');
const navSummary = document.querySelector('[data-nav-summary]');
const navEmpty = document.querySelector('[data-nav-empty]');
const expandAllBtn = document.querySelector('[data-expand-all]');

/* Theme toggle --------------------------------------------------------- */

document.querySelector('[data-theme-toggle]')?.addEventListener('click', () => {
    const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    document.documentElement.dataset.theme = next;
    localStorage.setItem('api-docs-theme', next);
});

/* Shortcut label ------------------------------------------------------- */

document.querySelectorAll('[data-shortcut-label]').forEach((kbd) => {
    kbd.textContent = isMac ? '\u2318 K' : 'Ctrl K';
});

/* Mobile drawer -------------------------------------------------------- */

function openDrawer() {
    sidebar.classList.add('is-open');
    document.querySelector('.scrim')?.removeAttribute('hidden');
}

function closeDrawer() {
    sidebar.classList.remove('is-open');
    document.querySelector('.scrim')?.setAttribute('hidden', '');
}

document.querySelectorAll('[data-drawer-open]').forEach((btn) => btn.addEventListener('click', openDrawer));
document.querySelectorAll('[data-drawer-close]').forEach((btn) => btn.addEventListener('click', closeDrawer));

/* Accordion ------------------------------------------------------------ */

document.querySelectorAll('[data-toggle]').forEach((btn) => {
    btn.addEventListener('click', () => {
        const expanded = btn.getAttribute('aria-expanded') === 'true';
        btn.setAttribute('aria-expanded', String(!expanded));
        const items = btn.nextElementSibling;
        if (items) items.hidden = expanded;
    });
});

expandAllBtn?.addEventListener('click', () => {
    const toggles = document.querySelectorAll('[data-toggle]');
    const allExpanded = [...toggles].every((t) => t.getAttribute('aria-expanded') === 'true');
    toggles.forEach((t) => {
        t.setAttribute('aria-expanded', String(!allExpanded));
        if (t.nextElementSibling) t.nextElementSibling.hidden = allExpanded;
    });
    expandAllBtn.textContent = allExpanded ? 'Expand all' : 'Collapse all';
});

/* Sidebar filter ------------------------------------------------------- */

searchInput?.addEventListener('input', () => {
    const q = searchInput.value.trim().toLowerCase();
    let visible = 0;

    document.querySelectorAll('[data-nav-domain]').forEach((group) => {
        const toggle = group.querySelector('[data-toggle]');
        const items = group.querySelector('.nav-items');
        const links = group.querySelectorAll('.nav-table-link');
        let groupVisible = 0;

        links.forEach((link) => {
            const name = link.dataset.table || '';
            const show = !q || name.includes(q);
            link.style.display = show ? '' : 'none';
            if (show) groupVisible++;
        });

        group.style.display = groupVisible > 0 || !q ? '' : 'none';
        if (q && groupVisible > 0) {
            toggle?.setAttribute('aria-expanded', 'true');
            if (items) items.hidden = false;
        }
        visible += groupVisible;
    });

    if (navEmpty) navEmpty.hidden = visible > 0 || !q;
});

document.querySelector('[data-clear-filters]')?.addEventListener('click', () => {
    if (searchInput) {
        searchInput.value = '';
        searchInput.dispatchEvent(new Event('input'));
    }
});

/* Routing -------------------------------------------------------------- */

function route() {
    const hash = location.hash || '#/overview';
    const parts = hash.slice(2).split('/');
    const page = parts[0];
    const slug = parts.slice(1).join('/');

    // Hide all views
    Object.values(views).forEach((v) => { if (v) v.hidden = true; });

    // Deactivate nav links
    document.querySelectorAll('.nav-link[aria-current="page"]').forEach((link) => link.removeAttribute('aria-current'));

    if (page === 'domain' && domainBySlug[slug]) {
        renderDomain(domainBySlug[slug]);
        activateNav('domain-' + slug);
        expandDomainAccordion(slug);
    } else if (page === 'table' && tableBySlug[slug]) {
        renderTable(tableBySlug[slug]);
        activateNav('table-' + slug);
        const domain = tableToDomain[slug];
        if (domain) expandDomainAccordion(domain.slug);
    } else {
        if (views.overview) views.overview.hidden = false;
        activateNav('overview');
    }

    closeDrawer();
    window.scrollTo(0, 0);
}

function activateNav(key) {
    const link = document.querySelector(`[data-nav="${key}"]`);
    if (link) link.setAttribute('aria-current', 'page');
}

function expandDomainAccordion(slug) {
    const group = document.querySelector(`[data-nav-domain="${slug}"]`);
    if (!group) return;
    const toggle = group.querySelector('[data-toggle]');
    const items = group.querySelector('.nav-items');
    if (toggle) toggle.setAttribute('aria-expanded', 'true');
    if (items) items.hidden = false;
}

/* Render domain -------------------------------------------------------- */

function renderDomain(domain) {
    const view = views.domain;
    if (!view) return;

    view.textContent = '';

    const header = el('header', { class: 'page-header' },
        el('p', { class: 'eyebrow', text: 'Domain' }),
        el('h1', { class: 'display', text: domain.name }),
        el('p', { class: 'lead', text: `${domain.tables.length} table${domain.tables.length !== 1 ? 's' : ''}` }),
    );

    view.append(header);
    domain.tables.forEach((table) => view.append(buildTableCard(table, true)));
    view.hidden = false;
}

/* Render single table -------------------------------------------------- */

function renderTable(table) {
    const view = views.table;
    if (!view) return;

    view.textContent = '';
    const domain = tableToDomain[table.slug];

    const header = el('header', { class: 'page-header' });
    if (domain) {
        header.append(el('p', { class: 'eyebrow' }, el('a', { href: `#/domain/${domain.slug}`, text: domain.name })));
    }
    header.append(el('h1', { class: 'display', style: 'font-family:var(--font-mono)', text: table.name }));

    const stats = [];
    stats.push(`${table.column_count} column${table.column_count !== 1 ? 's' : ''}`);
    if (table.foreign_keys.length) stats.push(`${table.foreign_keys.length} FK${table.foreign_keys.length !== 1 ? 's' : ''}`);
    if (table.indexes.length) stats.push(`${table.indexes.length} index${table.indexes.length !== 1 ? 'es' : ''}`);
    header.append(el('p', { class: 'lead', text: stats.join(' \u00B7 ') }));

    view.append(header, buildTableCard(table, false));
    view.hidden = false;
}

/* Table card builder --------------------------------------------------- */

function buildTableCard(table, showLink) {
    const fkCols = new Set(table.foreign_keys.map((fk) => fk.column));
    const card = el('div', { class: 'table-card', id: `table-${table.slug}` });

    // Header
    const headerEl = el('div', { class: 'table-card-header' },
        el('h3', { text: table.name }),
        el('span', { class: 'badge tabular', text: String(table.column_count) }),
    );
    if (showLink) {
        headerEl.append(el('a', { class: 'table-card-link', href: `#/table/${table.slug}`, text: 'View details' }));
    }
    card.append(headerEl);

    // Column table
    const colTable = el('table', { class: 'col-table' });
    const thead = el('thead', {},
        el('tr', {},
            el('th', { scope: 'col', text: 'Column' }),
            el('th', { scope: 'col', text: 'Type' }),
            el('th', { scope: 'col', text: 'Modifiers' }),
            el('th', { scope: 'col', text: 'Default' }),
            el('th', { scope: 'col', text: 'Description' }),
        ),
    );
    colTable.append(thead);

    const tbody = el('tbody');
    table.columns.forEach((col) => {
        const isPk = col.name === 'id' && (col.type === 'bigIncrements' || col.type === 'id');
        const isFk = fkCols.has(col.name);

        // Name cell
        const nameCell = el('td', { class: 'col-name', dataset: { label: 'Column' } });
        nameCell.append(document.createTextNode(col.name));
        if (isPk) nameCell.append(el('span', { class: 'pill pill--pk', text: 'PK' }));
        if (isFk) nameCell.append(el('span', { class: 'type-badge type-badge--fk', text: 'FK' }));

        // Type cell
        const typeCell = el('td', { dataset: { label: 'Type' } });
        typeCell.append(el('span', { class: `type-badge${isPk ? ' type-badge--pk' : ''}`, text: col.type }));

        // Modifiers cell
        const modCell = el('td', { dataset: { label: 'Modifiers' } });
        if (col.nullable) modCell.append(el('span', { class: 'pill pill--nullable', text: 'nullable' }));
        if (col.unique) modCell.append(el('span', { class: 'pill pill--unique', text: 'unique' }));
        if (col.indexed && !col.unique && !isPk) modCell.append(el('span', { class: 'pill pill--index', text: 'index' }));

        // Default cell
        const defCell = el('td', { dataset: { label: 'Default' } });
        if (col.default !== null && col.default !== undefined) {
            defCell.append(el('code', { text: String(col.default) }));
        } else {
            const dash = el('span', { text: '\u2014' });
            dash.style.color = 'var(--ink-subtle)';
            defCell.append(dash);
        }

        // Description cell
        const descCell = el('td', { class: 'col-desc', dataset: { label: 'Description' } });
        descCell.append(el('span', { text: col.description || '\u2014' }));

        tbody.append(el('tr', {}, nameCell, typeCell, modCell, defCell, descCell));
    });
    colTable.append(tbody);
    card.append(colTable);

    // Foreign keys
    if (table.foreign_keys.length > 0) {
        const fkSection = el('div', { class: 'fk-section' },
            el('h4', { text: 'Foreign Keys' }),
        );
        const fkList = el('ul', { class: 'fk-list' });
        table.foreign_keys.forEach((fk) => {
            const item = el('li', { class: 'fk-item' },
                el('span', { class: 'fk-col', text: fk.column }),
                el('span', { class: 'fk-arrow', text: '\u2192' }),
                el('a', { class: 'fk-ref', href: `#/table/${fk.on.replace(/_/g, '-')}`, text: `${fk.on}.${fk.references}` }),
            );
            if (fk.onDelete) item.append(el('span', { class: 'fk-action', text: `on delete ${fk.onDelete}` }));
            fkList.append(item);
        });
        fkSection.append(fkList);
        card.append(fkSection);
    }

    // Composite indexes
    const compositeIndexes = table.indexes.filter((idx) => idx.columns.length > 1);
    if (compositeIndexes.length > 0) {
        const idxSection = el('div', { class: 'idx-section' },
            el('h4', { text: 'Composite Indexes' }),
        );
        const idxList = el('ul', { class: 'idx-list' });
        compositeIndexes.forEach((idx) => {
            const item = el('li', { class: 'idx-item' },
                el('span', { class: 'idx-type', text: idx.type }),
                el('span', { class: 'idx-cols', text: `(${idx.columns.join(', ')})` }),
            );
            if (idx.name) item.append(el('span', { class: 'idx-name', text: idx.name }));
            idxList.append(item);
        });
        idxSection.append(idxList);
        card.append(idxSection);
    }

    return card;
}

/* Command palette ------------------------------------------------------ */

const palette = document.querySelector('[data-palette]');
const paletteInput = document.querySelector('[data-palette-input]');
const paletteResults = document.querySelector('[data-palette-results]');
let paletteIdx = -1;

// Build search index (domains and tables only — columns are excluded)
const searchIndex = [];
domains.forEach((d) => {
    searchIndex.push({ type: 'domain', name: d.name, slug: d.slug, href: `#/domain/${d.slug}`, hint: `${d.tables.length} tables` });
    d.tables.forEach((t) => {
        searchIndex.push({ type: 'table', name: t.name, slug: t.slug, href: `#/table/${t.slug}`, hint: d.name });
    });
});

function openPalette() {
    if (!palette) return;
    palette.showModal();
    paletteInput.value = '';
    renderPaletteResults('');
    paletteInput.focus();
}

function closePalette() {
    palette?.close();
}

function renderPaletteResults(query) {
    const q = query.toLowerCase().trim();
    paletteIdx = -1;
    paletteResults.textContent = '';

    let results = q
        ? searchIndex.filter((item) => item.name.toLowerCase().includes(q) || (item.hint && item.hint.toLowerCase().includes(q)))
        : [...searchIndex];

    results = results.slice(0, 50);

    if (results.length === 0) {
        paletteResults.append(el('li', { class: 'palette-empty', role: 'option', text: 'No results found.' }));
        return;
    }

    results.forEach((r, i) => {
        const option = el('li', {
            class: 'palette-option',
            role: 'option',
            dataset: { idx: String(i), href: r.href },
        },
            el('span', { class: 'badge', text: r.type }),
            el('span', { class: 'palette-option-title', text: r.name }),
        );

        if (r.hint) {
            const metaEl = el('span', { class: 'palette-option-meta', text: r.hint });
            // Place meta on second row of the grid
            metaEl.style.gridColumn = '2';
            option.append(metaEl);
        }

        paletteResults.append(option);
    });
}

paletteInput?.addEventListener('input', () => renderPaletteResults(paletteInput.value));

paletteInput?.addEventListener('keydown', (e) => {
    const items = paletteResults.querySelectorAll('.palette-option');
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        paletteIdx = Math.min(paletteIdx + 1, items.length - 1);
        updatePaletteActive(items);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        paletteIdx = Math.max(paletteIdx - 1, 0);
        updatePaletteActive(items);
    } else if (e.key === 'Enter') {
        e.preventDefault();
        const active = items[paletteIdx];
        if (active) {
            location.hash = active.dataset.href;
            closePalette();
        }
    }
});

paletteResults?.addEventListener('click', (e) => {
    const item = e.target.closest('.palette-option');
    if (item) {
        location.hash = item.dataset.href;
        closePalette();
    }
});

function updatePaletteActive(items) {
    items.forEach((option, i) => option.setAttribute('aria-selected', String(i === paletteIdx)));
    items[paletteIdx]?.scrollIntoView({ block: 'nearest' });
}

document.querySelector('[data-palette-open]')?.addEventListener('click', openPalette);
document.querySelector('[data-palette-close]')?.addEventListener('click', closePalette);

document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        palette?.open ? closePalette() : openPalette();
    }
    if (e.key === 'Escape' && palette?.open) {
        closePalette();
    }
});

/* Init ----------------------------------------------------------------- */

window.addEventListener('hashchange', route);
route();
