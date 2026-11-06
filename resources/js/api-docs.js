/**
 * CourtFlex API documentation portal.
 *
 * Renders the endpoint data discovered by the backend (embedded as JSON),
 * and handles search, filtering, navigation, clipboard and "Try it".
 * All server data is inserted with textContent, never as HTML.
 */

import {
    CODE_WRAP_KEY,
    chevron,
    codeBlock,
    copyButton,
    copyText,
    el,
    highlightJson,
    isMac,
    prefersReducedMotion,
    prettyJson,
    setCodeWrap,
    slugify,
    toast,
    wrapToggle,
} from './docs/shared.js';

const dataElement = document.getElementById('api-docs-data');
const docs = dataElement ? JSON.parse(dataElement.textContent) : null;

const TOKEN_PLACEHOLDER = 'YOUR_ACCESS_TOKEN';
const CODE_TAB_KEY = 'api-docs-code-tab';

const state = {
    query: '',
    methods: new Set(),
    expanded: new Set(),
    route: { view: 'overview', id: null },
    codeTab: localStorage.getItem(CODE_TAB_KEY) || 'curl',
    // Kept in memory only: cleared when the page is closed or reloaded.
    token: '',
    tenant: '',
};

/* Helpers ------------------------------------------------------------- */

function methodBadge(method) {
    return el('span', { class: `method method-${method.toLowerCase()}`, text: method });
}

/**
 * Copy a complete endpoint URL. The URL is rebuilt from the base URL and
 * validated first, so a partial or relative URL is never copied.
 */
function copyEndpointUrl(endpoint, button) {
    const url = fullUrl(endpoint.uri);

    if (url === null) {
        toast("Couldn't build a complete URL for this endpoint.", 'error');

        return;
    }

    const placeholders = endpoint.path_parameters.map((parameter) => `{${parameter.name}}`);

    copyText(url, placeholders.length > 0 ? `URL copied. Replace ${placeholders.join(', ')} before sending.` : 'Full URL copied', button);
}

/* Token -------------------------------------------------------------------- */

const tokenChip = document.querySelector('[data-token-chip]');

function setToken(value) {
    state.token = value.trim();
    tokenChip.hidden = state.token === '';
    document.querySelectorAll('[data-token-input]').forEach((input) => {
        if (input.value !== state.token) {
            input.value = state.token;
        }
    });
}

/* URLs & snippets ------------------------------------------------------ */

function fullUrl(path) {
    try {
        const url = new URL(docs.meta.root_url + path);

        return url.protocol.startsWith('http') ? decodeURI(url.href) : null;
    } catch {
        return null;
    }
}

function requiredQuery(endpoint) {
    return endpoint.query_parameters
        .filter((parameter) => parameter.required === 'required')
        .map((parameter) => [parameter.name, String(parameter.example ?? '')]);
}

function requiredQueryString(endpoint) {
    const parameters = requiredQuery(endpoint);

    return parameters.length === 0 ? '' : '?' + parameters.map(([name, value]) => `${encodeURIComponent(name)}=${encodeURIComponent(value)}`).join('&');
}

function snippetHeaders(endpoint) {
    const headers = {};

    for (const header of endpoint.headers) {
        if (!header.required) {
            continue;
        }

        if (header.name === 'Content-Type' && isMultipart(endpoint)) {
            continue;
        }

        headers[header.name] = header.value;
    }

    return headers;
}

function headerLines(endpoint) {
    return endpoint.headers.filter((header) => header.required).map((header) => `${header.name}: ${header.value}`).join('\n');
}

function isMultipart(endpoint) {
    return endpoint.body.content_type === 'multipart/form-data';
}

function shellQuote(value) {
    return `'${value.replace(/'/g, "'\\''")}'`;
}

function curlSnippet(endpoint) {
    const url = (fullUrl(endpoint.uri) ?? endpoint.uri) + requiredQueryString(endpoint);
    const lines = [`curl -X ${endpoint.method} "${url}"`];

    for (const [name, value] of Object.entries(snippetHeaders(endpoint))) {
        lines.push(`  -H "${name}: ${value}"`);
    }

    if (isMultipart(endpoint)) {
        for (const field of endpoint.body.fields) {
            lines.push(field.type === 'file' ? `  -F "${field.name}=@/path/to/file"` : `  -F "${field.name}=${field.example ?? ''}"`);
        }
    } else if (endpoint.body.example) {
        lines.push(`  -d ${shellQuote(prettyJson(endpoint.body.example))}`);
    }

    return lines.join(' \\\n');
}

function indent(text, spaces) {
    const padding = ' '.repeat(spaces);

    return text.split('\n').map((line, index) => (index === 0 ? line : padding + line)).join('\n');
}

function fetchSnippet(endpoint) {
    const url = (fullUrl(endpoint.uri) ?? endpoint.uri) + requiredQueryString(endpoint);
    const headerText = Object.entries(snippetHeaders(endpoint)).map(([name, value]) => `      "${name}": "${value}"`).join(',\n');
    const lines = [];

    if (isMultipart(endpoint)) {
        lines.push('const formData = new FormData();');

        for (const field of endpoint.body.fields) {
            lines.push(field.type === 'file'
                ? `formData.append("${field.name}", fileInput.files[0]);`
                : `formData.append("${field.name}", ${JSON.stringify(String(field.example ?? ''))});`);
        }

        lines.push('');
    }

    lines.push('const response = await fetch(');
    lines.push(`  "${url}",`);
    lines.push('  {');
    lines.push(`    method: "${endpoint.method}",`);
    lines.push('    headers: {');
    lines.push(headerText);
    lines.push(endpoint.body.example || isMultipart(endpoint) ? '    },' : '    }');

    if (isMultipart(endpoint)) {
        lines.push('    body: formData');
    } else if (endpoint.body.example) {
        lines.push(`    body: JSON.stringify(${indent(prettyJson(endpoint.body.example), 4)})`);
    }

    lines.push('  }');
    lines.push(');');
    lines.push('');
    lines.push('const data = await response.json();');
    lines.push('console.log(data);');

    return lines.join('\n');
}

function phpValue(value, depth = 0) {
    const pad = '    '.repeat(depth + 1);
    const closePad = '    '.repeat(depth);

    if (value === null || value === undefined) {
        return 'null';
    }

    if (typeof value === 'string') {
        return `'${value.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
    }

    if (typeof value === 'number' || typeof value === 'boolean') {
        return String(value);
    }

    const isList = Array.isArray(value);
    const entries = isList ? value.map((item) => [null, item]) : Object.entries(value);

    if (entries.length === 0) {
        return '[]';
    }

    const lines = entries.map(([key, item]) => `${pad}${key === null ? '' : `${phpValue(key)} => `}${phpValue(item, depth + 1)},`);

    return `[\n${lines.join('\n')}\n${closePad}]`;
}

function phpSnippet(endpoint) {
    const url = fullUrl(endpoint.uri) ?? endpoint.uri;
    const verb = endpoint.method.toLowerCase();
    const extraHeaders = Object.fromEntries(Object.entries(snippetHeaders(endpoint))
        .filter(([name]) => !['Accept', 'Content-Type', 'Authorization'].includes(name)));
    const lines = ['use Illuminate\\Support\\Facades\\Http;', '', '$response = Http::acceptJson()'];

    if (endpoint.authentication.required) {
        lines.push(`    ->withToken('${TOKEN_PLACEHOLDER}')`);
    }

    if (Object.keys(extraHeaders).length > 0) {
        lines.push(`    ->withHeaders(${indent(phpValue(extraHeaders, 1), 0)})`);
    }

    if (isMultipart(endpoint)) {
        for (const field of endpoint.body.fields.filter((item) => item.type === 'file')) {
            lines.push(`    ->attach('${field.name}', fopen('/path/to/file', 'r'), 'file')`);
        }
    }

    const query = Object.fromEntries(requiredQuery(endpoint));
    let data = null;

    if (['get', 'delete'].includes(verb) && Object.keys(query).length > 0) {
        data = query;
    } else if (isMultipart(endpoint)) {
        const fields = Object.fromEntries(endpoint.body.fields.filter((item) => item.type !== 'file').map((item) => [item.name, item.example]));
        data = Object.keys(fields).length > 0 ? fields : null;
    } else if (endpoint.body.example) {
        data = endpoint.body.example;
    }

    lines.push(data === null ? `    ->${verb}('${url}');` : `    ->${verb}('${url}', ${phpValue(data, 1)});`);
    lines.push('', '$data = $response->json();');

    return lines.join('\n');
}

/* Search & filtering ---------------------------------------------------- */

function haystack(endpoint) {
    return [
        endpoint.title,
        endpoint.uri,
        endpoint.method,
        endpoint.module,
        endpoint.area,
        endpoint.resource,
        endpoint.description,
        endpoint.route_name,
        endpoint.implementation?.controller,
    ].filter(Boolean).join(' ').toLowerCase();
}

function matchesQuery(endpoint, query) {
    const terms = query.toLowerCase().split(/\s+/).filter(Boolean);

    return terms.every((term) => endpoint._haystack.includes(term));
}

/**
 * Rank search results: method and path matches first, then titles.
 */
function searchScore(endpoint, query) {
    const text = query.trim().toLowerCase();

    if (text === '') {
        return 0;
    }

    let score = 0;
    const [first, ...rest] = text.split(/\s+/);

    if (endpoint.method.toLowerCase() === first) {
        score += 4;
    }

    if (endpoint.uri.toLowerCase().includes(rest.length ? rest.join(' ') : text)) {
        score += 3;
    }

    if (endpoint.title.toLowerCase().startsWith(text)) {
        score += 2;
    } else if (endpoint.title.toLowerCase().includes(text)) {
        score += 1;
    }

    return score;
}

function isFiltering() {
    return state.query.trim() !== '' || state.methods.size > 0;
}

function matches(endpoint) {
    if (state.methods.size > 0 && !state.methods.has(endpoint.method)) {
        return false;
    }

    return matchesQuery(endpoint, state.query);
}

function filteredEndpoints() {
    return docs.endpoints.filter(matches);
}

/* Sidebar ------------------------------------------------------------------- */

const navModules = document.querySelector('[data-nav-modules]');
const navEmpty = document.querySelector('[data-nav-empty]');
const navSummary = document.querySelector('[data-nav-summary]');
const expandAllButton = document.querySelector('[data-expand-all]');

function subgroupKey(endpoints) {
    return new Set(endpoints.map((endpoint) => endpoint.area)).size > 1 ? 'area' : 'resource';
}

function renderSidebar() {
    const visible = filteredEndpoints();
    const filtering = isFiltering();
    const allExpanded = docs.modules.every((module) => state.expanded.has(module.name));

    navModules.replaceChildren();
    navEmpty.hidden = visible.length > 0;
    navSummary.textContent = filtering ? `${visible.length} of ${docs.endpoints.length} endpoints` : `${docs.endpoints.length} endpoints`;
    expandAllButton.hidden = filtering;
    expandAllButton.textContent = allExpanded ? 'Collapse all' : 'Expand all';

    for (const module of docs.modules) {
        const items = visible.filter((endpoint) => endpoint.module === module.name);

        if (items.length === 0) {
            continue;
        }

        const expanded = filtering || state.expanded.has(module.name);
        const listId = `nav-${slugify(module.name)}`;

        const toggle = el('button', {
            type: 'button',
            class: 'nav-module-toggle',
            'aria-expanded': String(expanded),
            'aria-controls': listId,
            onClick: () => {
                state.expanded.has(module.name) ? state.expanded.delete(module.name) : state.expanded.add(module.name);
                renderSidebar();
            },
        }, chevron(), el('span', { text: module.name }), el('span', {
            class: 'badge tabular',
            text: filtering ? `${items.length}/${module.count}` : String(module.count),
        }));

        const list = el('div', { id: listId, hidden: !expanded });
        const key = subgroupKey(docs.endpoints.filter((endpoint) => endpoint.module === module.name));
        let currentGroup = null;

        for (const endpoint of items) {
            if (endpoint[key] !== currentGroup) {
                currentGroup = endpoint[key];
                list.append(el('p', { class: 'nav-resource', text: currentGroup }));
            }

            const isCurrent = state.route.view === 'endpoint' && state.route.id === endpoint.id;

            list.append(el('a', {
                class: 'nav-endpoint',
                href: `#/e/${endpoint.id}`,
                'aria-current': isCurrent ? 'page' : null,
                title: `${endpoint.method} ${endpoint.uri}`,
            }, methodBadge(endpoint.method), el('span', { class: 'nav-endpoint-text' }, endpoint.title)));
        }

        navModules.append(el('div', { class: 'nav-module' }, toggle, list));
    }

    document.querySelectorAll('[data-nav]').forEach((link) => {
        if (state.route.view === link.dataset.nav) {
            link.setAttribute('aria-current', 'page');
        } else {
            link.removeAttribute('aria-current');
        }
    });
}

/* Views --------------------------------------------------------------------- */

const views = {
    overview: document.querySelector('[data-view="overview"]'),
    authentication: document.querySelector('[data-view="authentication"]'),
    module: document.querySelector('[data-view="module"]'),
    endpoint: document.querySelector('[data-view="endpoint"]'),
};

let sectionObserver = null;

function section(id, title, ...children) {
    return el('section', { class: 'endpoint-section', id: `section-${id}`, 'aria-labelledby': `heading-${id}`, dataset: { section: id } },
        el('h2', { id: `heading-${id}`, text: title }),
        ...children);
}

function table(headings, rows) {
    return el('div', { class: 'table-wrap table-stack' },
        el('table', {},
            el('thead', {}, el('tr', {}, headings.map((heading) => el('th', { scope: 'col', text: heading })))),
            el('tbody', {}, rows)));
}

function cell(label, ...children) {
    return el('td', { 'data-label': label }, ...children);
}

function fieldCell(name, type, nullable = false) {
    return cell('Field',
        el('span', { class: 'field-name', text: name }),
        el('span', { class: 'field-type', text: nullable ? `${type}, nullable` : type }));
}

function requirementBadge(required) {
    if (required === 'required' || required === true) {
        return el('span', { class: 'badge badge-active', text: 'Required' });
    }

    return el('span', { class: 'badge', text: required === 'conditional' ? 'Conditional' : 'Optional' });
}

function rulesCell(rules, example) {
    return cell('Rules',
        rules.length > 0 ? el('div', { class: 'rules' }, rules.map((rule) => el('code', { text: rule }))) : null,
        example !== undefined && example !== null && example !== ''
            ? el('span', { class: 'field-type', text: `Example: ${typeof example === 'string' ? example : JSON.stringify(example)}` })
            : (rules.length > 0 ? null : '—'));
}

function parameterRows(parameters) {
    return parameters.map((parameter) => el('tr', {},
        fieldCell(parameter.name, parameter.type, parameter.nullable),
        cell('Required', requirementBadge(parameter.required)),
        rulesCell(parameter.rules ?? [], parameter.type === 'file' ? 'binary file' : parameter.example)));
}

function routeCode(uri) {
    const code = el('code');

    for (const part of uri.split(/(\{[^}]+\})/)) {
        code.append(part.startsWith('{') ? el('span', { class: 'param', text: part }) : part);
    }

    return code;
}

function statusClass(status) {
    return status < 400 ? 'status status-success' : 'status status-error';
}

/**
 * Flatten a response example into documented fields (path, type, example).
 */
function responseFields(value, path = '', fields = []) {
    if (Array.isArray(value)) {
        fields.push({ path: path || '(root)', type: 'array', example: value.length === 0 ? '[]' : null });

        if (value.length > 0 && value[0] !== null && typeof value[0] === 'object') {
            responseFields(value[0], `${path}[]`, fields);
        }

        return fields;
    }

    if (value !== null && typeof value === 'object') {
        if (path !== '') {
            fields.push({ path, type: 'object', example: null });
        }

        for (const [key, item] of Object.entries(value)) {
            responseFields(item, path === '' ? key : `${path}.${key}`, fields);
        }

        return fields;
    }

    const type = value === null ? 'null' : (typeof value === 'number' ? (Number.isInteger(value) ? 'integer' : 'number') : typeof value);
    fields.push({ path, type, example: value === null ? 'null' : String(value) });

    return fields;
}

function renderEndpoint(endpoint) {
    const view = views.endpoint;
    const head = el('div', { class: 'endpoint-head' });
    const details = el('div', { class: 'endpoint-details' });
    const aside = el('aside', { class: 'endpoint-aside', 'aria-label': 'Code samples' });
    const url = fullUrl(endpoint.uri);

    const badges = el('div', { class: 'badges' },
        endpoint.authentication.required
            ? el('span', { class: 'badge', text: 'Requires token' })
            : el('span', { class: 'badge badge-active', text: 'Public' }),
        endpoint.roles.length ? el('span', { class: 'badge', text: `Role: ${endpoint.roles.join(', ')}` }) : null,
        endpoint.tenant?.required ? el('span', { class: 'badge', text: 'Tenant header' }) : null,
        endpoint.internal ? el('span', { class: 'badge badge-warning', text: 'Internal' }) : null,
        endpoint.destructive ? el('span', { class: 'badge badge-danger', text: endpoint.method === 'DELETE' ? 'Deletes data' : 'Changes state' }) : null,
        endpoint.response.paginated ? el('span', { class: 'badge', text: 'Paginated' }) : null);

    head.append(el('header', { class: 'endpoint-title' },
        el('p', { class: 'eyebrow' },
            el('a', { href: `#/module/${slugify(endpoint.module)}`, text: endpoint.module }),
            ` · ${endpoint.area}`),
        el('h1', { class: 'display', text: endpoint.title }),
        badges));

    const copyUrlButton = el('button', { type: 'button', class: 'copy-button', text: 'Copy URL' });
    copyUrlButton.addEventListener('click', () => copyEndpointUrl(endpoint, copyUrlButton));

    head.append(el('div', { class: 'endpoint-route' },
        methodBadge(endpoint.method),
        routeCode(endpoint.uri),
        el('div', { class: 'endpoint-route-actions' },
            copyUrlButton,
            copyButton('Copy path', endpoint.uri, 'Relative path copied'))));

    if (endpoint.description) {
        head.append(el('p', { class: 'endpoint-description', text: endpoint.description }));
    }

    const metaRows = [
        ['Full URL', el('span', { class: 'muted', text: url ?? endpoint.uri })],
        ['Base URL', el('span', { class: 'fact-copy' }, el('code', { text: docs.meta.base_url }), copyButton('Copy', docs.meta.base_url, 'Base URL copied'))],
        ['Authentication', endpoint.authentication.required ? endpoint.authentication.scheme : 'None. This endpoint is public.'],
        endpoint.roles.length ? ['Required role', endpoint.roles.join(', ')] : null,
        endpoint.permissions.length ? ['Required permission', endpoint.permissions.join(', ')] : null,
        endpoint.requires_active_account ? ['Account', 'Must be active'] : null,
        endpoint.tenant ? ['Tenant', endpoint.tenant.required ? 'Required. Send the X-Tenant-ID header.' : 'Optional X-Tenant-ID header'] : null,
        endpoint.rate_limits.length ? ['Rate limit', endpoint.rate_limits.map((limit) => limit.summary).join('; ')] : null,
        ['Success status', String(endpoint.response.status)],
        endpoint.route_name ? ['Route name', el('code', { text: endpoint.route_name })] : null,
    ].filter(Boolean);

    head.append(el('dl', { class: 'meta-list' },
        metaRows.map(([term, value]) => el('div', {}, el('dt', { text: term }), el('dd', {}, value)))));

    // Sections -----------------------------------------------------------
    const sections = [];

    sections.push(section('headers', 'Headers',
        table(['Header', 'Value', 'Description'], endpoint.headers.map((header) => el('tr', {},
            cell('Header', el('span', { class: 'field-name', text: header.name }), el('span', { class: 'field-type', text: header.required ? 'Required' : 'Optional' })),
            cell('Value', el('code', { text: header.value })),
            cell('Description', header.description)))),
        el('div', { class: 'badges' },
            copyButton('Copy headers', headerLines(endpoint), 'Request headers copied'),
            endpoint.authentication.required ? copyButton('Copy Authorization header', `Authorization: Bearer ${TOKEN_PLACEHOLDER}`, 'Authorization header copied') : null)));

    if (endpoint.path_parameters.length > 0 || endpoint.query_parameters.length > 0) {
        const children = [];

        if (endpoint.path_parameters.length > 0) {
            children.push(el('h3', { text: 'Path' }), table(['Parameter', 'Required', 'Description'], endpoint.path_parameters.map((parameter) => el('tr', {},
                fieldCell(`{${parameter.name}}`, parameter.type),
                cell('Required', requirementBadge(parameter.required)),
                cell('Description', parameter.description)))));
        }

        if (endpoint.query_parameters.length > 0) {
            children.push(el('h3', { text: 'Query' }), table(['Parameter', 'Required', 'Rules'], parameterRows(endpoint.query_parameters)));
        }

        sections.push(section('parameters', 'Parameters', ...children));
    }

    if (endpoint.body.fields.length > 0 || !endpoint.body.rules_inferred) {
        const children = [el('p', {}, 'Content type: ', el('code', { text: endpoint.body.content_type }))];

        if (!endpoint.body.rules_inferred) {
            children.push(el('p', { class: 'notice notice-warning', text: 'The validation rules for this request could not be read automatically and need manual documentation.' }));
        }

        if (endpoint.body.fields.length > 0) {
            children.push(table(['Field', 'Required', 'Rules'], parameterRows(endpoint.body.fields)));
        }

        if (endpoint.body.example) {
            children.push(codeBlock('Example payload', prettyJson(endpoint.body.example), { json: true, copyLabel: 'Copy JSON', copyMessage: 'Example payload copied' }));
        }

        sections.push(section('body', 'Request body', ...children));
    }

    const responses = el('div', { class: 'response-list' });

    if (endpoint.response.example) {
        responses.append(el('p', {}, el('span', { class: statusClass(endpoint.response.status) }, el('span', { class: 'status-dot' }), `${endpoint.response.status} Success`)));

        if (!endpoint.response.data_documented) {
            responses.append(el('p', { class: 'notice notice-warning', text: 'The shape of "data" could not be inferred from the code. This response example needs manual documentation.' }));
        }

        responses.append(codeBlock(`${endpoint.response.status} response`, prettyJson(endpoint.response.example), { json: true, copyLabel: 'Copy JSON' }));

        const fields = endpoint.response.data_documented ? responseFields(endpoint.response.example) : [];

        if (fields.length > 0) {
            responses.append(el('details', { class: 'disclosure' },
                el('summary', {}, el('span', { text: `Response fields (${fields.length})` }), chevron()),
                el('div', { class: 'disclosure-body' }, table(['Field', 'Type', 'Example'], fields.map((field) => el('tr', {},
                    cell('Field', el('span', { class: 'field-name', text: field.path })),
                    cell('Type', field.type),
                    cell('Example', field.example === null ? '—' : el('code', { text: field.example }))))))));
        }

        if (endpoint.response.paginated) {
            responses.append(el('p', { text: 'This endpoint is paginated. Send page and per_page as query parameters and read the totals from meta.' }));
        }
    } else {
        responses.append(el('p', { class: 'notice notice-warning', text: 'The success response could not be inferred from the code and needs manual documentation.' }));
    }

    if (endpoint.response.errors.length > 0) {
        responses.append(el('h3', { text: 'Errors' }));

        for (const error of endpoint.response.errors) {
            responses.append(el('details', { class: 'disclosure' },
                el('summary', {},
                    el('span', { class: statusClass(error.status) }, el('span', { class: 'status-dot' }), String(error.status)),
                    el('span', { class: 'summary-message', text: error.body.message }),
                    chevron()),
                el('div', { class: 'disclosure-body' }, codeBlock(`${error.status} response`, prettyJson(error.body), { json: true, copyLabel: 'Copy JSON' }))));
        }
    }

    sections.push(section('responses', 'Responses', responses));

    if (endpoint.try_it) {
        sections.push(section('try-it', 'Try it', tryItPanel(endpoint)));
    }

    if (endpoint.implementation) {
        const implementation = endpoint.implementation;
        const rows = [
            implementation.controller ? ['Controller', el('code', { text: implementation.controller })] : null,
            implementation.form_request ? ['Form request', el('code', { text: implementation.form_request })] : null,
            implementation.resource ? ['API resource', el('code', { text: implementation.resource })] : null,
            ['Middleware', el('div', { class: 'chips' }, endpoint.middleware.map((item) => el('code', { text: item })))],
        ].filter(Boolean);

        sections.push(section('implementation', 'Implementation',
            el('dl', { class: 'meta-list' }, rows.map(([term, value]) => el('div', {}, el('dt', { text: term }), el('dd', {}, value))))));
    }

    // Sticky jump bar for the sections on this page.
    const sectionNav = el('nav', { class: 'section-nav', 'aria-label': 'On this page' },
        sections.map((node) => el('a', {
            href: `#${node.id}`,
            dataset: { target: node.id },
            text: node.querySelector('h2').textContent,
            onClick: (event) => {
                event.preventDefault();
                document.getElementById(node.id)?.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'start' });
            },
        })));

    details.append(sectionNav, ...sections, pager(endpoint));

    // Code samples -------------------------------------------------------
    const tabs = [
        { id: 'curl', label: 'cURL', code: curlSnippet(endpoint), copy: 'Copy cURL', message: 'cURL command copied' },
        { id: 'fetch', label: 'JavaScript', code: fetchSnippet(endpoint), copy: 'Copy code', message: 'Fetch example copied' },
        { id: 'php', label: 'PHP', code: phpSnippet(endpoint), copy: 'Copy code', message: 'PHP example copied' },
    ];
    const sampleCode = el('code');
    const samplePanel = el('pre', { id: `sample-${slugify(endpoint.id)}`, role: 'tabpanel', tabindex: '0' }, sampleCode);
    const tabList = el('div', { class: 'tabs', role: 'tablist', 'aria-label': 'Code sample language' });
    const sampleCopy = el('button', { type: 'button', class: 'copy-button' });
    let activeTab = tabs[0];

    sampleCopy.addEventListener('click', () => copyText(activeTab.code, activeTab.message, sampleCopy));

    const showTab = (id, focus = false) => {
        const tab = tabs.find((item) => item.id === id) ?? tabs[0];
        activeTab = tab;
        state.codeTab = tab.id;
        localStorage.setItem(CODE_TAB_KEY, tab.id);

        tabList.querySelectorAll('[role="tab"]').forEach((button) => {
            const selected = button.dataset.tab === tab.id;
            button.setAttribute('aria-selected', String(selected));
            button.tabIndex = selected ? 0 : -1;

            if (selected && focus) {
                button.focus();
            }
        });

        sampleCode.textContent = tab.code;
        samplePanel.setAttribute('aria-label', `${tab.label} example`);

        if (sampleCopy.dataset.copying !== 'true') {
            sampleCopy.textContent = tab.copy;
        }
    };

    for (const tab of tabs) {
        tabList.append(el('button', {
            type: 'button',
            class: 'tab',
            role: 'tab',
            'aria-controls': samplePanel.id,
            dataset: { tab: tab.id },
            text: tab.label,
            onClick: () => showTab(tab.id),
        }));
    }

    tabList.addEventListener('keydown', (event) => {
        if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) {
            return;
        }

        const index = tabs.findIndex((tab) => tab.id === state.codeTab);
        const next = (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
        showTab(tabs[next].id, true);
    });

    aside.append(
        el('h2', { class: 'label', text: 'Request example' }),
        el('figure', { class: 'code-block sample-card' },
            el('div', { class: 'code-block-head sample-head' },
                tabList,
                el('div', { class: 'sample-actions' }, wrapToggle(), sampleCopy)),
            samplePanel));
    showTab(state.codeTab);

    if (endpoint.response.example) {
        aside.append(codeBlock(`Response · ${endpoint.response.status}`, prettyJson(endpoint.response.example), { json: true, copyLabel: 'Copy JSON', className: 'aside-response' }));
    }

    view.replaceChildren(el('div', { class: 'endpoint' }, head, aside, details));
    observeSections(sectionNav);
}

function pager(endpoint) {
    const index = docs.endpoints.findIndex((item) => item.id === endpoint.id);
    const previous = docs.endpoints[index - 1];
    const next = docs.endpoints[index + 1];

    const link = (item, label, className) => el('a', { href: `#/e/${item.id}`, class: className },
        el('span', { class: 'caption', text: label }),
        el('span', { class: 'pager-title' }, methodBadge(item.method), el('span', { text: item.title })));

    return el('nav', { class: 'pager', 'aria-label': 'Previous and next endpoints' },
        previous ? link(previous, 'Previous', 'pager-previous') : null,
        next ? link(next, 'Next', 'pager-next') : null);
}

/**
 * Highlight the section currently in view in the jump bar.
 */
function observeSections(sectionNav) {
    sectionObserver?.disconnect();

    const links = new Map([...sectionNav.querySelectorAll('a')].map((link) => [link.dataset.target, link]));

    sectionObserver = new IntersectionObserver((entries) => {
        for (const entry of entries) {
            if (!entry.isIntersecting) {
                continue;
            }

            links.forEach((link, id) => link.setAttribute('aria-current', String(id === entry.target.id)));

            // Scroll only the bar sideways; scrollIntoView would cancel the page's smooth scroll.
            const active = links.get(entry.target.id);

            if (active) {
                sectionNav.scrollTo({ left: active.offsetLeft - sectionNav.offsetLeft - 16 });
            }
        }
    }, { rootMargin: '-120px 0px -65% 0px' });

    document.querySelectorAll('.endpoint-section').forEach((node) => sectionObserver.observe(node));
}

function renderModule(name) {
    const module = docs.modules.find((item) => item.name === name);
    const items = docs.endpoints.filter((endpoint) => endpoint.module === name);

    views.module.replaceChildren(el('div', { class: 'prose' },
        el('header', { class: 'page-header' },
            el('p', { class: 'eyebrow', text: 'Module' }),
            el('h1', { class: 'display', text: name }),
            el('p', { class: 'lead', text: `${module?.count ?? items.length} endpoints` })),
        el('ul', { class: 'endpoint-links' }, items.map((endpoint) => el('li', {},
            el('a', { href: `#/e/${endpoint.id}` },
                methodBadge(endpoint.method),
                el('span', { class: 'endpoint-link-label', text: endpoint.title }),
                el('code', { text: endpoint.uri })))))));
}

/* Try it -------------------------------------------------------------------- */

function inputField({ id, label, hint = null, value = '', type = 'text', wide = false, placeholder = '' }) {
    const input = el('input', { id, type, placeholder, autocomplete: 'off', spellcheck: 'false' });
    input.value = value ?? '';

    return {
        input,
        node: el('div', { class: wide ? 'field field-wide' : 'field' },
            el('label', { for: id }, label, hint ? el('span', { class: 'caption', text: hint }) : null),
            input),
    };
}

function findToken(payload) {
    const candidates = [payload?.data?.access_token, payload?.data?.token, payload?.access_token, payload?.token];

    return candidates.find((value) => typeof value === 'string' && value !== '' && value !== TOKEN_PLACEHOLDER) ?? null;
}

function tryItPanel(endpoint) {
    const prefix = `try-${slugify(endpoint.id)}`;
    const form = el('form', { class: 'try-it', novalidate: true });
    const grid = el('div', { class: 'form-grid' });
    const pathInputs = [];
    const queryInputs = [];
    let tokenInput = null;
    let tenantInput = null;
    let bodyInput = null;
    let bodyField = null;
    let bodyError = null;
    let confirmInput = null;
    let lastRequest = null;

    form.append(el('p', { class: 'notice', text: `Requests are sent from your browser to ${docs.meta.base_url} only when you select Send request.` }));

    if (endpoint.method !== 'GET') {
        form.append(el('p', {
            class: endpoint.destructive ? 'notice notice-danger' : 'notice notice-warning',
            text: endpoint.destructive
                ? `This ${endpoint.method} request changes or removes data. Use test records only.`
                : `This ${endpoint.method} request can create or change data. Use test records only.`,
        }));
    }

    for (const parameter of endpoint.path_parameters) {
        const field = inputField({ id: `${prefix}-path-${parameter.name}`, label: `{${parameter.name}}`, hint: 'Path', value: parameter.example });
        pathInputs.push({ parameter, input: field.input });
        grid.append(field.node);
    }

    for (const parameter of endpoint.query_parameters) {
        const field = inputField({
            id: `${prefix}-query-${slugify(parameter.name)}`,
            label: parameter.name,
            hint: parameter.required === 'required' ? 'Query, required' : 'Query',
            value: parameter.required === 'required' ? parameter.example : '',
        });
        queryInputs.push({ parameter, input: field.input });
        grid.append(field.node);
    }

    if (endpoint.authentication.required) {
        const field = inputField({ id: `${prefix}-token`, label: 'Access token', hint: 'Bearer', type: 'password', wide: true, placeholder: 'Paste a token from a login response', value: state.token });
        tokenInput = field.input;
        tokenInput.dataset.tokenInput = 'true';
        tokenInput.addEventListener('input', () => setToken(tokenInput.value));
        field.node.append(el('p', { class: 'helper', text: 'Shared by every endpoint on this page and forgotten when you reload. It is never stored or sent anywhere else.' }));
        grid.append(field.node);
    }

    if (endpoint.tenant) {
        const field = inputField({ id: `${prefix}-tenant`, label: 'X-Tenant-ID', hint: endpoint.tenant.required ? 'Required' : 'Optional', value: state.tenant });
        tenantInput = field.input;
        tenantInput.addEventListener('input', () => {
            state.tenant = tenantInput.value.trim();
        });
        grid.append(field.node);
    }

    if (isMultipart(endpoint)) {
        grid.append(el('p', { class: 'notice field-wide', text: 'This endpoint uploads a file. Use the cURL, JavaScript or PHP example to send it.' }));
    } else if (endpoint.body.example) {
        bodyInput = el('textarea', { id: `${prefix}-body`, spellcheck: 'false' });
        bodyInput.value = prettyJson(endpoint.body.example);
        bodyError = el('p', { class: 'error', hidden: true });
        bodyField = el('div', { class: 'field field-wide' },
            el('label', { for: `${prefix}-body` }, 'Request body', el('span', { class: 'caption', text: 'JSON' })),
            bodyInput,
            el('div', { class: 'badges' },
                el('button', {
                    type: 'button',
                    class: 'link-button',
                    text: 'Format JSON',
                    onClick: () => {
                        try {
                            bodyInput.value = prettyJson(JSON.parse(bodyInput.value));
                            bodyError.hidden = true;
                            bodyField.classList.remove('field-invalid');
                        } catch {
                            showBodyError();
                        }
                    },
                }),
                el('button', {
                    type: 'button',
                    class: 'link-button',
                    text: 'Reset example',
                    onClick: () => {
                        bodyInput.value = prettyJson(endpoint.body.example);
                    },
                })),
            bodyError);
        grid.append(bodyField);
    }

    function showBodyError() {
        bodyField.classList.add('field-invalid');
        bodyError.textContent = 'The body is not valid JSON. Fix it and send again.';
        bodyError.hidden = false;
        bodyInput.focus();
    }

    form.append(grid);

    if (endpoint.destructive) {
        confirmInput = el('input', { type: 'checkbox', id: `${prefix}-confirm` });
        form.append(el('label', { class: 'checkbox', for: `${prefix}-confirm` }, confirmInput, 'I understand this request changes data'));
    }

    const sendButton = el('button', { type: 'submit', class: 'button button-primary', text: 'Send request', disabled: isMultipart(endpoint) });
    const curlButton = el('button', { type: 'button', class: 'button button-secondary', text: 'Copy as cURL', disabled: isMultipart(endpoint) });
    const result = el('div', { class: 'try-result', 'aria-live': 'polite' });

    if (confirmInput) {
        sendButton.disabled = true;
        confirmInput.addEventListener('change', () => {
            sendButton.disabled = !confirmInput.checked;
        });
    }

    form.append(el('div', { class: 'try-actions' }, sendButton, curlButton), el('p', { class: 'caption', text: isMac ? 'Tip: press ⌘ Enter to send.' : 'Tip: press Ctrl Enter to send.' }), result);

    /**
     * Build the request from the form. Returns null when input is invalid.
     */
    function buildRequest() {
        let path = endpoint.uri;

        for (const { parameter, input } of pathInputs) {
            const value = input.value.trim();

            if (value === '') {
                input.focus();
                toast(`Enter a value for {${parameter.name}}.`, 'error');

                return null;
            }

            path = path.replace(new RegExp(`\\{${parameter.name}\\??\\}`), encodeURIComponent(value));
        }

        const url = fullUrl(path);

        if (url === null) {
            toast("Couldn't build a complete URL for this request.", 'error');

            return null;
        }

        const requestUrl = new URL(url);

        for (const { parameter, input } of queryInputs) {
            if (input.value.trim() !== '') {
                requestUrl.searchParams.set(parameter.name, input.value.trim());
            }
        }

        const headers = { Accept: 'application/json' };
        let body;

        if (bodyInput) {
            bodyField.classList.remove('field-invalid');
            bodyError.hidden = true;

            try {
                body = JSON.stringify(JSON.parse(bodyInput.value || '{}'));
                headers['Content-Type'] = 'application/json';
            } catch {
                showBodyError();

                return null;
            }
        }

        if (tokenInput && tokenInput.value.trim() !== '') {
            headers.Authorization = `Bearer ${tokenInput.value.trim()}`;
        }

        if (tenantInput && tenantInput.value.trim() !== '') {
            headers['X-Tenant-ID'] = tenantInput.value.trim();
        }

        return { url: requestUrl.href, headers, body };
    }

    curlButton.addEventListener('click', () => {
        const request = buildRequest();

        if (!request) {
            return;
        }

        const lines = [`curl -X ${endpoint.method} "${decodeURI(request.url)}"`];

        for (const [name, value] of Object.entries(request.headers)) {
            lines.push(`  -H "${name}: ${name === 'Authorization' ? `Bearer ${TOKEN_PLACEHOLDER}` : value}"`);
        }

        if (request.body) {
            lines.push(`  -d ${shellQuote(request.body)}`);
        }

        copyText(lines.join(' \\\n'), 'cURL copied. The token is replaced with a placeholder.', curlButton);
    });

    form.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
            event.preventDefault();
            form.requestSubmit();
        }
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (sendButton.disabled) {
            return;
        }

        const request = buildRequest();

        if (!request) {
            return;
        }

        lastRequest = request;
        sendButton.disabled = true;
        sendButton.replaceChildren(el('span', { class: 'spinner', 'aria-hidden': 'true' }), 'Sending');

        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 30000);
        const startedAt = performance.now();

        try {
            const response = await fetch(lastRequest.url, {
                method: endpoint.method,
                headers: lastRequest.headers,
                body: lastRequest.body,
                credentials: 'omit',
                signal: controller.signal,
            });

            const elapsed = Math.round(performance.now() - startedAt);
            const text = await response.text();
            let formatted = text;
            let payload = null;

            try {
                payload = JSON.parse(text);
                formatted = prettyJson(payload);
            } catch {
                payload = null;
            }

            const headerText = [...response.headers.entries()].map(([name, value]) => `${name}: ${value}`).join('\n');
            const receivedToken = response.ok ? findToken(payload) : null;

            result.replaceChildren(
                el('div', { class: 'try-result-head' },
                    el('span', { class: statusClass(response.status) }, el('span', { class: 'status-dot' }), `${response.status} ${response.statusText}`),
                    el('span', { class: 'caption tabular', text: `${elapsed} ms` }),
                    el('button', { type: 'button', class: 'link-button', text: 'Clear', onClick: () => result.replaceChildren() })),
                receivedToken
                    ? el('div', { class: 'notice' },
                        'This response contains an access token. ',
                        el('button', {
                            type: 'button',
                            class: 'link-button',
                            text: 'Use it for other requests',
                            onClick: (clickEvent) => {
                                setToken(receivedToken);
                                clickEvent.currentTarget.replaceWith('Token saved for this page.');
                                toast('Token saved for this page');
                            },
                        }))
                    : null,
                el('details', { class: 'disclosure' },
                    el('summary', {}, el('span', { text: 'Response headers' }), chevron()),
                    el('div', { class: 'disclosure-body' }, codeBlock('Headers', headerText || '(none)'))),
                codeBlock('Response body', formatted || '(empty)', { json: payload !== null, copyLabel: 'Copy body' }));
        } catch (error) {
            result.replaceChildren(el('p', {
                class: 'notice notice-danger',
                text: error.name === 'AbortError'
                    ? 'The request timed out after 30 seconds. Try again.'
                    : "The request couldn't be sent. Check your connection and the base URL, then try again.",
            }));
        } finally {
            clearTimeout(timeout);
            sendButton.disabled = confirmInput ? !confirmInput.checked : false;
            sendButton.replaceChildren('Send request');
            result.scrollIntoView({ block: 'nearest', behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
        }
    });

    return form;
}

/* Search palette --------------------------------------------------------------- */

const palette = document.querySelector('[data-palette]');
const paletteInput = document.querySelector('[data-palette-input]');
const paletteResults = document.querySelector('[data-palette-results]');
let paletteMatches = [];
let paletteIndex = 0;

function openPalette(initial = '') {
    if (!palette || palette.open) {
        return;
    }

    closeDrawer();
    paletteInput.value = initial;
    renderPalette();
    palette.showModal();
    paletteInput.focus();
}

function closePalette() {
    if (palette?.open) {
        palette.close();
    }
}

/**
 * Guide pages and modules that the search palette can open besides endpoints.
 *
 * @return {Array<{title: string, meta: string, hash: string, keywords: string}>}
 */
function documentationPages() {
    return [
        {
            title: 'Overview',
            meta: 'Guide',
            hash: '#/overview',
            keywords: 'getting started overview quickstart base url version making requests headers response format envelope status codes errors tenant context x-tenant-id rate limits pagination',
        },
        {
            title: 'Authentication',
            meta: 'Guide',
            hash: '#/authentication',
            keywords: 'authentication auth login register registration logout token bearer sanctum authorization header expiration lifetime revoke revocation google errors 401 403',
        },
        ...docs.modules.map((module) => ({
            title: module.name,
            meta: `Module · ${module.count} endpoints`,
            hash: `#/module/${slugify(module.name)}`,
            keywords: `module ${module.name}`,
        })),
    ];
}

function renderPalette() {
    const query = paletteInput.value.trim();
    const terms = query.toLowerCase().split(/\s+/).filter(Boolean);

    const pages = terms.length === 0 ? [] : documentationPages()
        .filter((page) => terms.every((term) => `${page.title} ${page.keywords}`.toLowerCase().includes(term)))
        .map((page) => ({ kind: 'page', hash: page.hash, title: page.title, meta: page.meta }));

    const endpoints = docs.endpoints
        .filter((endpoint) => matchesQuery(endpoint, query))
        .map((endpoint, order) => ({ endpoint, score: searchScore(endpoint, query), order }))
        .sort((a, b) => b.score - a.score || a.order - b.order)
        .slice(0, 60)
        .map(({ endpoint }) => ({
            kind: 'endpoint',
            hash: `#/e/${endpoint.id}`,
            title: endpoint.title,
            meta: `${endpoint.uri} · ${endpoint.area}`,
            method: endpoint.method,
        }));

    paletteMatches = [...pages, ...endpoints];
    paletteIndex = 0;
    paletteResults.replaceChildren();

    if (paletteMatches.length === 0) {
        paletteResults.append(el('li', { class: 'palette-empty', role: 'presentation', text: `Nothing matches "${query}". Try a path, a method or a module name.` }));
        paletteInput.removeAttribute('aria-activedescendant');

        return;
    }

    paletteMatches.forEach((item, index) => {
        if (index === 0 && item.kind === 'page') {
            paletteResults.append(el('li', { class: 'palette-group', role: 'presentation', text: 'Documentation' }));
        }

        if (item.kind === 'endpoint' && (index === 0 || paletteMatches[index - 1].kind === 'page')) {
            paletteResults.append(el('li', { class: 'palette-group', role: 'presentation', text: query === '' ? 'All endpoints' : 'Endpoints' }));
        }

        paletteResults.append(el('li', {
            id: `palette-option-${index}`,
            class: 'palette-option',
            role: 'option',
            'aria-selected': String(index === 0),
            onClick: () => choosePalette(index),
            onMousemove: () => movePalette(index),
        },
        item.kind === 'endpoint' ? methodBadge(item.method) : el('span', { class: 'method', text: 'DOC' }),
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

    paletteResults.querySelectorAll('[role="option"]').forEach((option, optionIndex) => {
        option.setAttribute('aria-selected', String(optionIndex === paletteIndex));
    });

    const active = document.getElementById(`palette-option-${paletteIndex}`);
    active?.scrollIntoView({ block: 'nearest' });
    paletteInput.setAttribute('aria-activedescendant', `palette-option-${paletteIndex}`);
}

function choosePalette(index = paletteIndex) {
    const item = paletteMatches[index];

    if (!item) {
        return;
    }

    closePalette();
    window.location.hash = item.hash;
}

/* Routing ------------------------------------------------------------------- */

function parseHash() {
    const hash = decodeURIComponent(window.location.hash.replace(/^#\/?/, ''));

    if (hash.startsWith('e/')) {
        return { view: 'endpoint', id: hash.slice(2) };
    }

    if (hash.startsWith('module/')) {
        const module = docs.modules.find((item) => slugify(item.name) === hash.slice(7));

        return module ? { view: 'module', id: module.name } : { view: 'overview', id: null };
    }

    return { view: hash === 'authentication' ? 'authentication' : 'overview', id: null };
}

function showView(name) {
    for (const [key, view] of Object.entries(views)) {
        view.hidden = key !== name;
    }
}

function render() {
    // In-page section links ("#section-...") are handled by the jump bar.
    if (window.location.hash.startsWith('#section-')) {
        return;
    }

    state.route = parseHash();
    sectionObserver?.disconnect();
    let title = docs.meta.title;

    if (state.route.view === 'endpoint') {
        const endpoint = docs.endpoints.find((item) => item.id === state.route.id);

        if (!endpoint) {
            views.endpoint.replaceChildren(el('div', { class: 'empty-state' },
                el('h1', { class: 'display', text: 'Endpoint not found' }),
                el('p', { text: 'This endpoint does not exist or is not documented.' }),
                el('a', { class: 'button button-secondary', href: '#/overview', text: 'Back to overview' })));
        } else {
            state.expanded.add(endpoint.module);
            renderEndpoint(endpoint);
            title = `${endpoint.method} ${endpoint.uri} · ${docs.meta.title}`;
        }

        showView('endpoint');
    } else if (state.route.view === 'module') {
        state.expanded.add(state.route.id);
        renderModule(state.route.id);
        showView('module');
        title = `${state.route.id} · ${docs.meta.title}`;
    } else {
        showView(state.route.view);

        if (state.route.view === 'authentication') {
            title = `Authentication · ${docs.meta.title}`;
        }
    }

    document.title = title;
    renderSidebar();
    closeDrawer();
    window.scrollTo({ top: 0 });

    document.querySelector('.nav-endpoint[aria-current="page"]')?.scrollIntoView({ block: 'nearest' });
}

/* Drawer & theme -------------------------------------------------------------- */

const sidebar = document.getElementById('sidebar');
const scrim = document.querySelector('.scrim');
const openButton = document.querySelector('[data-drawer-open]');

function isDesktop() {
    return window.matchMedia('(min-width: 1024px)').matches;
}

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
    const button = document.querySelector('[data-theme-toggle]');
    const isDark = document.documentElement.dataset.theme === 'dark';

    button.setAttribute('aria-label', isDark ? 'Switch to light theme' : 'Switch to dark theme');
}

/* Boot ------------------------------------------------------------------------ */

function boot() {
    if (!docs) {
        return;
    }

    for (const endpoint of docs.endpoints) {
        endpoint._haystack = haystack(endpoint);
    }

    // Long lines wrap by default so samples read well on any screen.
    setCodeWrap(localStorage.getItem(CODE_WRAP_KEY) !== 'false');

    document.querySelectorAll('code[data-highlight="json"]').forEach((code) => {
        code.replaceChildren(highlightJson(code.textContent));
    });

    const shortcutLabel = document.querySelector('[data-shortcut-label]');

    if (shortcutLabel) {
        shortcutLabel.textContent = isMac ? '⌘ K' : 'Ctrl K';
    }

    // Sidebar filter.
    const searchInput = document.querySelector('[data-search]');

    searchInput.addEventListener('input', () => {
        state.query = searchInput.value;
        renderSidebar();
    });

    searchInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            const first = filteredEndpoints()[0];

            if (first) {
                window.location.hash = `#/e/${first.id}`;
            }
        }

        if (event.key === 'Escape' && searchInput.value !== '') {
            event.stopPropagation();
            searchInput.value = '';
            searchInput.dispatchEvent(new Event('input'));
        }
    });

    document.querySelectorAll('[data-method-filter]').forEach((button) => {
        button.addEventListener('click', () => {
            const method = button.dataset.methodFilter;
            state.methods.has(method) ? state.methods.delete(method) : state.methods.add(method);
            button.setAttribute('aria-pressed', String(state.methods.has(method)));
            renderSidebar();
        });
    });

    document.querySelector('[data-clear-filters]')?.addEventListener('click', () => {
        state.query = '';
        state.methods.clear();
        searchInput.value = '';
        document.querySelectorAll('[data-method-filter]').forEach((button) => button.setAttribute('aria-pressed', 'false'));
        renderSidebar();
    });

    expandAllButton.addEventListener('click', () => {
        const allExpanded = docs.modules.every((module) => state.expanded.has(module.name));
        docs.modules.forEach((module) => (allExpanded ? state.expanded.delete(module.name) : state.expanded.add(module.name)));
        renderSidebar();
    });

    // Copy buttons rendered by Blade.
    document.addEventListener('click', (event) => {
        const baseButton = event.target.closest('[data-copy="base"]');

        if (baseButton) {
            copyText(docs.meta.base_url, 'Base URL copied', baseButton);

            return;
        }

        const textButton = event.target.closest('[data-copy-text]');

        if (textButton) {
            copyText(textButton.dataset.copyText, textButton.dataset.copyMessage || 'Copied', textButton);
        }
    });

    // Token chip.
    document.querySelector('[data-token-clear]').addEventListener('click', () => {
        setToken('');
        toast('Token cleared');
    });

    // Palette.
    document.querySelector('[data-palette-open]').addEventListener('click', () => openPalette());
    document.querySelector('[data-palette-close]').addEventListener('click', closePalette);
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
            closePalette();
        }
    });

    // Drawer.
    openButton.addEventListener('click', () => {
        openDrawer();
        searchInput.focus();
    });

    document.querySelectorAll('[data-drawer-close]').forEach((button) => button.addEventListener('click', closeDrawer));
    window.matchMedia('(min-width: 1024px)').addEventListener('change', closeDrawer);

    // Keyboard shortcuts.
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !palette.open) {
            closeDrawer();
        }

        const target = event.target;
        const typing = target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target.isContentEditable;

        if ((event.key === 'k' || event.key === 'K') && (event.ctrlKey || event.metaKey)) {
            event.preventDefault();
            palette.open ? closePalette() : openPalette();

            return;
        }

        if (event.key === '/' && !typing) {
            event.preventDefault();
            openPalette();
        }
    });

    document.querySelector('[data-theme-toggle]').addEventListener('click', () => {
        const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.theme = next;
        localStorage.setItem('api-docs-theme', next);
        updateThemeButton();
    });

    updateThemeButton();
    window.addEventListener('hashchange', render);
    render();
}

boot();
