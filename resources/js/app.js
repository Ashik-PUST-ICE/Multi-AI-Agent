import './bootstrap';

const API_BASE = '/api/v1';
const TOKEN_STORAGE_KEY = 'multi-ai-agent.api-token';
const STATUS_LABELS = {
    0: 'অপেক্ষমাণ',
    1: 'চলছে',
    2: 'সম্পন্ন',
    3: 'ব্যর্থ',
};
const AGENT_SEQUENCE = ['Orchestrator', 'Research', 'Writer', 'Reviewer'];

const elements = {
    approvalCount: document.querySelector('#approval-count'),
    approvalList: document.querySelector('#approval-list'),
    application: document.querySelector('#application'),
    authError: document.querySelector('#auth-error'),
    authDescription: document.querySelector('#auth-description'),
    authEmail: document.querySelector('#auth-email'),
    authForm: document.querySelector('#auth-form'),
    authHeading: document.querySelector('#auth-heading'),
    authModeToggle: document.querySelector('#auth-mode-toggle'),
    authNameField: document.querySelector('#auth-name-field'),
    authName: document.querySelector('#auth-name'),
    authPassword: document.querySelector('#auth-password'),
    authPasswordConfirmation: document.querySelector('#auth-password-confirmation'),
    authPasswordConfirmationInput: document.querySelector('#auth-password-confirmation-input'),
    authSubmit: document.querySelector('#auth-submit'),
    accountControls: document.querySelector('#account-controls'),
    accountName: document.querySelector('#account-name'),
    agentDirectory: document.querySelector('#agent-directory'),
    agentBrainGrid: document.querySelector('#agent-brain-grid'),
    brainSettingsForm: document.querySelector('#brain-settings-form'),
    defaultProvider: document.querySelector('#default-provider'),
    defaultModel: document.querySelector('#default-model'),
    fallbackProvider: document.querySelector('#fallback-provider'),
    fallbackModel: document.querySelector('#fallback-model'),
    routingPolicy: document.querySelector('#routing-policy'),
    modelCatalog: document.querySelector('#model-catalog'),
    providerKeyList: document.querySelector('#provider-key-list'),
    connectionForm: document.querySelector('#connection-form'),
    connectionIndicator: document.querySelector('#connection-indicator'),
    connectionLabel: document.querySelector('#connection-label'),
    disconnectButton: document.querySelector('#disconnect-button'),
    error: document.querySelector('#global-error'),
    mobileApprovalCount: document.querySelector('#mobile-approval-count'),
    pageTitle: document.querySelector('#page-title'),
    success: document.querySelector('#global-success'),
    taskEstimate: document.querySelector('#task-estimate'),
    taskDetail: document.querySelector('#task-detail'),
    taskForm: document.querySelector('#task-form'),
    taskList: document.querySelector('#task-list'),
    tokenInput: document.querySelector('#api-token'),
    views: document.querySelectorAll('.view-panel'),
    navigationButtons: document.querySelectorAll('[data-view]'),
    mobileMoreButton: document.querySelector('#mobile-more-button'),
    mobileMoreMenu: document.querySelector('#mobile-more-menu'),
};

let apiToken = sessionStorage.getItem(TOKEN_STORAGE_KEY) || '';
let selectedTaskId = null;
let refreshTimer = null;
let pendingTaskConfirmation = null;
let brainSettings = null;
let brainCatalog = {};
let brainKeys = {};
let authMode = 'login';
const AGENT_LABELS = {
    Orchestrator: 'পরিকল্পনাকারী',
    Research: 'গবেষক',
    Writer: 'লেখক',
    Reviewer: 'পর্যালোচক',
};

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    })[character]);
}

function showNotice(element, message) {
    elements.error.hidden = true;
    elements.success.hidden = true;
    elements.authError.hidden = true;
    const noticeElement = elements.application.classList.contains('is-auth-required')
        ? elements.authError
        : element;
    noticeElement.textContent = message;
    noticeElement.hidden = false;
}

function clearNotices() {
    elements.error.hidden = true;
    elements.success.hidden = true;
    elements.authError.hidden = true;
}

function setConnected(isConnected) {
    elements.application.classList.toggle('is-auth-required', !isConnected);
    elements.accountControls.hidden = !isConnected;
    elements.connectionIndicator.classList.toggle('is-connected', isConnected);
    elements.connectionLabel.textContent = isConnected ? 'নিরাপদ workspace সংযুক্ত' : 'API সংযুক্ত নয়';
}

function setAuthMode(mode) {
    authMode = mode;
    const isRegistering = mode === 'register';
    elements.authForm.dataset.mode = mode;
    elements.authHeading.textContent = isRegistering ? 'নতুন workspace তৈরি করুন' : 'আপনার অ্যাকাউন্টে প্রবেশ করুন';
    elements.authDescription.textContent = isRegistering
        ? 'নতুন account তৈরি করলে আলাদা private workspace তৈরি হবে।'
        : 'নিজের AI workspace-এ নিরাপদে প্রবেশ করুন।';
    elements.authNameField.hidden = !isRegistering;
    elements.authName.required = isRegistering;
    elements.authPasswordConfirmation.hidden = !isRegistering;
    elements.authPasswordConfirmationInput.required = isRegistering;
    elements.authPassword.minLength = isRegistering ? 12 : 1;
    elements.authPassword.autocomplete = isRegistering ? 'new-password' : 'current-password';
    elements.authSubmit.textContent = isRegistering ? 'Workspace তৈরি করুন' : 'প্রবেশ করুন';
    elements.authModeToggle.textContent = isRegistering
        ? 'আগে থেকে account আছে? প্রবেশ করুন'
        : 'নতুন account তৈরি করুন';
}

function formatDate(value) {
    if (!value) {
        return 'এখন';
    }

    return new Intl.DateTimeFormat('bn-BD', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function statusLabel(status) {
    return STATUS_LABELS[status] || 'অজানা';
}

async function apiRequest(path, options = {}) {
    if (!apiToken) {
        throw new Error('আগে আপনার Sanctum API token দিয়ে সংযুক্ত করুন।');
    }

    const requestHeaders = {
        Accept: 'application/json',
        Authorization: `Bearer ${apiToken}`,
        ...options.headers,
    };

    if (options.body && !(options.body instanceof FormData)) {
        requestHeaders['Content-Type'] = 'application/json';
    }

    const response = await fetch(`${API_BASE}${path}`, {
        ...options,
        headers: requestHeaders,
    });
    const responseData = await response.json().catch(() => ({}));

    if (!response.ok) {
        const validationMessage = Object.values(responseData.errors || {}).flat()[0];
        throw new Error(validationMessage || responseData.message || `Request failed (${response.status}).`);
    }

    return responseData;
}

async function publicApiRequest(path, options = {}) {
    const requestHeaders = {
        Accept: 'application/json',
        ...options.headers,
    };

    if (options.body && !(options.body instanceof FormData)) {
        requestHeaders['Content-Type'] = 'application/json';
    }

    const response = await fetch(`${API_BASE}${path}`, {
        ...options,
        headers: requestHeaders,
    });
    const responseData = await response.json().catch(() => ({}));

    if (!response.ok) {
        const validationMessage = Object.values(responseData.errors || {}).flat()[0];
        throw new Error(validationMessage || responseData.message || `Request failed (${response.status}).`);
    }

    return responseData;
}

async function activateToken(token, displayName = '') {
    apiToken = token;
    const userResponse = await apiRequest('/user');
    sessionStorage.setItem(TOKEN_STORAGE_KEY, apiToken);
    elements.accountName.textContent = displayName || userResponse.user.name;
    setConnected(true);
    await Promise.all([loadTasks(), loadApprovals()]);
}

function switchView(viewName) {
    const viewAliases = { agents: 'agents', brain: 'brain' };
    const selectedView = viewAliases[viewName] || viewName;
    const titleByView = {
        tasks: 'আমার কাজ',
        approvals: 'অনুমোদন',
        agents: 'এজেন্ট',
        brain: 'Brain ও routing',
    };

    elements.views.forEach((view) => {
        view.classList.toggle('is-visible', view.id === `${selectedView}-view`);
    });
    elements.navigationButtons.forEach((button) => {
        button.classList.toggle('is-active', button.dataset.view === selectedView);
    });
    elements.pageTitle.textContent = titleByView[selectedView] || titleByView.tasks;

    if (selectedView === 'approvals' && apiToken) {
        loadApprovals();
    }

    if ((selectedView === 'brain' || selectedView === 'agents') && apiToken) {
        loadBrainSettings().catch((error) => showNotice(elements.error, error.message));
    }
}

function renderTaskList(tasks) {
    if (tasks.length === 0) {
        elements.taskList.innerHTML = '<div class="empty-state">এখনো কোনো কাজ জমা হয়নি।</div>';
        return;
    }

    elements.taskList.innerHTML = tasks.map((task) => `
        <button class="task-row ${task.id === selectedTaskId ? 'is-selected' : ''}" type="button" data-task-id="${escapeHtml(task.id)}">
            <span class="task-row-title">${escapeHtml(task.title)}</span>
            <span class="task-row-meta">
                <span>${escapeHtml(formatDate(task.created_at))}</span>
                <span class="status-chip status-${escapeHtml(task.status)}">${escapeHtml(statusLabel(task.status))}</span>
            </span>
        </button>
    `).join('');
}

function renderAgentRuns(agentRuns = [], routing = null) {
    const agentRunByName = new Map(agentRuns.map((run) => [run.agent_name, run]));
    const agentSequence = routing?.agent_sequence?.length
        ? routing.agent_sequence
        : (agentRuns.length > 0 ? agentRuns.map((run) => run.agent_name) : AGENT_SEQUENCE);

    return agentSequence.map((agentName, index) => {
        const agentRun = agentRunByName.get(agentName);
        const status = agentRun?.status ?? 0;
        const outputText = agentRun?.output?.text;
        const errorMessage = agentRun?.error_message;
        const extra = outputText || errorMessage
            ? `<p class="stage-output">${escapeHtml(outputText || errorMessage)}</p>`
            : '';

        return `
            <div class="agent-stage status-${escapeHtml(status)}">
                <span class="stage-icon" aria-hidden="true">${agentRun?.status === 2 ? '✓' : index + 1}</span>
                <span class="stage-name">${escapeHtml(agentName)}</span>
                <span class="status-chip status-${escapeHtml(status)}">${escapeHtml(statusLabel(status))}</span>
                ${extra}
            </div>
        `;
    }).join('');
}

function renderTaskDetail(task) {
    const answer = task.result?.answer;
    const failure = task.error_message;
    const finalResult = answer
        ? `<div class="result-card"><strong>চূড়ান্ত উত্তর</strong><p>${escapeHtml(answer)}</p></div>`
        : '';
    const failureMessage = failure
        ? `<div class="notice notice-error">${escapeHtml(failure)}</div>`
        : '';
    const tokenUsage = (task.agent_runs || []).reduce(
        (total, run) => total + Number(run.token_usage?.total_tokens || 0),
        0,
    );
    const usageSummary = tokenUsage > 0
        ? `<p class="usage-summary">Token usage: ${escapeHtml(tokenUsage.toLocaleString())}</p>`
        : '';
    const routingDetails = (task.routing_events || []).map((routingEvent) => {
        const routing = routingEvent.payload?.routing;
        if (routingEvent.event_type === 'agent.model_fallback') {
            return `<div class="replay-event"><strong>Fallback:</strong> ${escapeHtml(routingEvent.payload.from_model)} → ${escapeHtml(routingEvent.payload.to_model)} · ${escapeHtml(routingEvent.payload.reason)}</div>`;
        }
        return `<div class="replay-event"><strong>${escapeHtml(routingEvent.payload.agent)} · ${escapeHtml(routingEvent.payload.model || 'model')}</strong> · ${escapeHtml(routing?.brain_mode || 'Auto')} / ${escapeHtml(routing?.policy || 'policy')} · ${escapeHtml(routing?.reason || 'Routing reason unavailable')} · ${escapeHtml(routingEvent.mode)}</div>`;
    }).join('');
    const taskMode = task.mode === 'live' ? 'live' : 'sandbox';
    const modeActions = [
        `<button class="button button-quiet button-small" type="button" data-replay-task-id="${escapeHtml(task.id)}">Sandbox replay (read only)</button>`,
    ];

    if (taskMode === 'sandbox' && task.status === 2) {
        modeActions.unshift(
            `<button class="button button-small" type="button" data-live-transition-task-id="${escapeHtml(task.id)}">Live mode-এর safety gate যাচাই</button>`,
        );
    }

    elements.taskDetail.innerHTML = `
        <div class="detail-header">
            <div>
                <h3>${escapeHtml(task.title)}</h3>
                <p>শুরু: ${escapeHtml(formatDate(task.started_at || task.created_at))}</p>
                <span class="mode-badge ${taskMode === 'live' ? 'mode-badge-live' : ''}">${taskMode === 'live' ? 'LIVE' : 'SANDBOX'}</span>
            </div>
            <span class="status-chip status-${escapeHtml(task.status)}">${escapeHtml(statusLabel(task.status))}</span>
        </div>
        <div class="task-mode-actions">${modeActions.join('')}</div>
        <div class="agent-timeline">${renderAgentRuns(task.agent_runs || [], task.routing)}</div>
        ${routingDetails ? `<section class="routing-reasons"><strong>কেন এই model</strong>${routingDetails}</section>` : ''}
        ${usageSummary}
        ${finalResult}
        ${failureMessage}
    `;
}

async function loadSandboxReplay(taskId) {
    const responseData = await apiRequest(`/tasks/${encodeURIComponent(taskId)}/replay`);
    const eventRows = responseData.events.length > 0
        ? responseData.events.map((event) => `
            <div class="replay-event">
                <strong>${escapeHtml(event.event_type)}</strong>
                · ${escapeHtml(event.source_mode)} → ${escapeHtml(event.mode)}
                · ${escapeHtml(formatDate(event.occurred_at))}
            </div>
        `).join('')
        : '<div class="empty-state">এই কাজের জন্য কোনো replay event নেই।</div>';

    const existingReplay = elements.taskDetail.querySelector('[data-replay-events]');
    if (existingReplay) {
        existingReplay.remove();
    }

    const replayPanel = document.createElement('section');
    replayPanel.className = 'panel';
    replayPanel.dataset.replayEvents = 'true';
    replayPanel.innerHTML = `<strong>Sandbox replay · read only</strong>${eventRows}`;
    elements.taskDetail.append(replayPanel);
}

async function loadTask(taskId) {
    const responseData = await apiRequest(`/tasks/${encodeURIComponent(taskId)}`);
    selectedTaskId = responseData.task.id;
    renderTaskDetail(responseData.task);
    await loadTasks();
}

async function loadTasks() {
    const responseData = await apiRequest('/tasks');
    const tasks = responseData.tasks || [];

    renderTaskList(tasks);

    const selectedTask = tasks.find((task) => task.id === selectedTaskId);
    if (selectedTask) {
        const detailResponse = await apiRequest(`/tasks/${encodeURIComponent(selectedTaskId)}`);
        renderTaskDetail(detailResponse.task);
    } else if (tasks.length > 0) {
        await loadTask(tasks[0].id);
    }

    const hasActiveTask = tasks.some((task) => task.status === 0 || task.status === 1);
    if (hasActiveTask) {
        scheduleTaskRefresh();
    } else {
        window.clearTimeout(refreshTimer);
    }
}

function scheduleTaskRefresh() {
    window.clearTimeout(refreshTimer);
    refreshTimer = window.setTimeout(async () => {
        try {
            await loadTasks();
        } catch (error) {
            showNotice(elements.error, error.message);
        }
    }, 3000);
}

function setApprovalCount(count) {
    [elements.approvalCount, elements.mobileApprovalCount].forEach((badge) => {
        badge.textContent = String(count);
        badge.hidden = count === 0;
    });
}

function renderApprovals(approvals) {
    setApprovalCount(approvals.length);

    if (approvals.length === 0) {
        elements.approvalList.innerHTML = '<div class="panel empty-state"><strong>কোনো অনুমোদন অপেক্ষায় নেই</strong><span>নতুন approval request এলে এখানে দেখা যাবে।</span></div>';
        return;
    }

    elements.approvalList.innerHTML = approvals.map((approval) => `
        <article class="panel approval-card">
            <div class="approval-card-header">
                <div>
                    <h3>${escapeHtml(approval.action)}</h3>
                    <p>কাজ #${escapeHtml(approval.task_id)} · মেয়াদ: ${escapeHtml(formatDate(approval.expires_at))}</p>
                </div>
                <span class="status-chip status-1">অনুমোদন প্রয়োজন</span>
            </div>
            <pre class="approval-data">${escapeHtml(JSON.stringify(approval.payload?.tool_input || {}, null, 2))}</pre>
            <div class="approval-actions">
                <button class="button button-danger button-small" type="button" data-approval-id="${escapeHtml(approval.id)}" data-decision="reject">প্রত্যাখ্যান</button>
                <button class="button button-approve button-small" type="button" data-approval-id="${escapeHtml(approval.id)}" data-decision="approve">অনুমোদন দিন</button>
            </div>
        </article>
    `).join('');
}

async function loadApprovals() {
    const responseData = await apiRequest('/approvals');
    renderApprovals(responseData.approvals || []);
}

function modelKey(providerId, modelId) {
    return `${providerId}:${modelId}`;
}

function approvedModelChoices() {
    return Array.from(elements.modelCatalog.querySelectorAll('[data-approved-model]:checked'))
        .map((checkbox) => ({
            provider: checkbox.dataset.provider,
            model: checkbox.dataset.model,
        }));
}

function selectedModelLabel(providerId, modelId) {
    const provider = brainCatalog[providerId];
    return `${provider?.label || providerId} · ${modelId}`;
}

function renderModelCatalog() {
    const approvedKeys = new Set((brainSettings?.approved_models || []).map((model) => modelKey(model.provider, model.model)));
    const prices = new Map((brainSettings?.model_pricing || []).map((price) => [
        modelKey(price.provider, price.model),
        price,
    ]));
    const providerGroups = Object.values(brainCatalog).map((provider) => {
        const modelRows = Object.entries(provider.models || {}).map(([modelId, capabilities]) => {
            const key = modelKey(provider.id, modelId);
            const price = prices.get(key);
            const capabilitiesText = [
                capabilities.tools ? 'Tools' : null,
                capabilities.vision ? 'Vision' : null,
                capabilities.streaming ? 'Streaming' : null,
                capabilities.json_mode ? 'JSON' : null,
                capabilities.context_window ? `${Number(capabilities.context_window).toLocaleString()} context` : 'Context unknown',
                capabilities.capabilities_unverified ? 'Unverified' : null,
            ].filter(Boolean).join(' · ');

            return `
                <div class="model-catalog-row">
                    <label class="model-approval">
                        <input type="checkbox" data-approved-model data-provider="${escapeHtml(provider.id)}" data-model="${escapeHtml(modelId)}" ${approvedKeys.has(key) ? 'checked' : ''}>
                        <span>${escapeHtml(modelId)}</span>
                    </label>
                    <span class="model-capabilities">${escapeHtml(capabilitiesText || 'No capability metadata')}</span>
                    <input class="model-price-input" type="number" min="0" step="any" inputmode="decimal" data-price="input" data-provider="${escapeHtml(provider.id)}" data-model="${escapeHtml(modelId)}" value="${escapeHtml(price?.input_cost_per_million ?? '')}" placeholder="Input / 1M">
                    <input class="model-price-input" type="number" min="0" step="any" inputmode="decimal" data-price="output" data-provider="${escapeHtml(provider.id)}" data-model="${escapeHtml(modelId)}" value="${escapeHtml(price?.output_cost_per_million ?? '')}" placeholder="Output / 1M">
                    <input class="model-price-input" type="text" maxlength="3" data-price-currency data-provider="${escapeHtml(provider.id)}" data-model="${escapeHtml(modelId)}" value="${escapeHtml(price?.currency || 'USD')}" placeholder="Currency" aria-label="${escapeHtml(modelId)} currency">
                </div>
            `;
        }).join('');

        return `
            <section class="model-provider-group">
                <h4>${escapeHtml(provider.label)}</h4>
                ${modelRows || (provider.custom_base_url ? `
                    <p class="model-capabilities">Custom endpoint-এর model ID যোগ করুন। Capability যাচাই করা হবে না; tools/vision সমর্থন ধরে নেওয়া হবে না।</p>
                    <div class="provider-key-actions">
                        <input class="model-price-input" type="text" maxlength="128" data-new-model-id="${escapeHtml(provider.id)}" placeholder="Custom model ID">
                        <button class="button button-quiet button-small" type="button" data-add-custom-model="${escapeHtml(provider.id)}">Model যোগ করুন</button>
                    </div>
                ` : '<p class="model-capabilities">এই provider-এর catalog model নেই।</p>')}
            </section>
        `;
    }).join('');

    elements.modelCatalog.innerHTML = providerGroups || '<div class="empty-state">Provider catalog পাওয়া যায়নি।</div>';
}

function approvedOptions(selectedKey = '') {
    return approvedModelChoices().map((model) => {
        const key = modelKey(model.provider, model.model);
        return `<option value="${escapeHtml(key)}" ${key === selectedKey ? 'selected' : ''}>${escapeHtml(selectedModelLabel(model.provider, model.model))}</option>`;
    }).join('');
}

function renderBrainSelectors(overrides = {}) {
    const approvedModels = approvedModelChoices();
    const approvedProviderIds = [...new Set(approvedModels.map((model) => model.provider))];
    const previousProvider = overrides.provider || elements.defaultProvider.value || brainSettings?.provider || '';
    const previousModel = overrides.model || elements.defaultModel.value || brainSettings?.model || '';
    const savedFallback = brainSettings?.fallback_provider && brainSettings?.fallback_model
        ? modelKey(brainSettings.fallback_provider, brainSettings.fallback_model)
        : '';
    const previousFallback = Object.hasOwn(overrides, 'fallback')
        ? overrides.fallback
        : (elements.fallbackProvider.value || savedFallback);
    const previousPolicy = overrides.policy
        ?? brainSettings?.routing_policy
        ?? elements.routingPolicy.value;
    const providerOptions = approvedProviderIds.map((providerId) => `
        <option value="${escapeHtml(providerId)}" ${providerId === previousProvider ? 'selected' : ''}>
            ${escapeHtml(brainCatalog[providerId]?.label || providerId)}
        </option>
    `).join('');
    elements.defaultProvider.innerHTML = providerOptions || '<option value="">আগে model approve করুন</option>';

    const selectedProvider = elements.defaultProvider.value;
    const defaultModelOptions = approvedModels
        .filter((model) => model.provider === selectedProvider)
        .map((model) => `<option value="${escapeHtml(model.model)}">${escapeHtml(model.model)}</option>`)
        .join('');
    elements.defaultModel.innerHTML = defaultModelOptions || '<option value="">Approved model নেই</option>';
    if (approvedModels.some((model) => model.provider === selectedProvider && model.model === previousModel)) {
        elements.defaultModel.value = previousModel;
    }

    const fallbackOptions = `<option value="">কোনো fallback নয়</option>${approvedOptions(previousFallback)}`;
    elements.fallbackProvider.innerHTML = fallbackOptions;
    elements.fallbackModel.innerHTML = fallbackOptions;
    elements.fallbackProvider.value = previousFallback;
    elements.fallbackModel.value = previousFallback;

    const selectedBrains = brainSettings?.agent_brains || {};
    const agentModelSettings = brainSettings?.agent_models || {};
    elements.agentBrainGrid.innerHTML = Object.entries(AGENT_LABELS).map(([agentName, label]) => {
        const brainMode = overrides.agentModes?.[agentName] || selectedBrains[agentName] || 'auto';
        const configuredModel = agentModelSettings[agentName];
        const currentModelKey = overrides.agentModels?.[agentName] || (configuredModel
            ? modelKey(configuredModel.provider, configuredModel.model)
            : '');

        return `
            <article class="agent-brain-card" data-agent-card="${escapeHtml(agentName)}">
                <h4>${escapeHtml(label)} · ${escapeHtml(agentName)}</h4>
                <label class="brain-field">
                    <span>Brain mode</span>
                    <select data-agent-mode="${escapeHtml(agentName)}">
                        <option value="auto" ${brainMode === 'auto' ? 'selected' : ''}>Auto</option>
                        <option value="fixed" ${brainMode === 'fixed' ? 'selected' : ''}>Fixed</option>
                        <option value="custom" ${brainMode === 'custom' ? 'selected' : ''}>Custom</option>
                    </select>
                </label>
                <label class="brain-field" data-agent-model-label="${escapeHtml(agentName)}" ${brainMode === 'custom' ? '' : 'hidden'}>
                    <span>Custom approved model</span>
                    <select data-agent-model="${escapeHtml(agentName)}">
                        <option value="">Model বেছে নিন</option>${approvedOptions(currentModelKey)}
                    </select>
                </label>
            </article>
        `;
    }).join('');

    elements.routingPolicy.value = previousPolicy || 'cheapest';
}

function renderProviderKeys() {
    elements.providerKeyList.innerHTML = Object.values(brainCatalog).map((provider) => {
        const keyStatus = brainKeys[provider.id] || {};
        const baseUrlField = provider.custom_base_url
            ? `<input class="brain-key-input" type="url" data-base-url="${escapeHtml(provider.id)}" value="${escapeHtml(keyStatus.custom_base_url || '')}" placeholder="https://your-provider.example/v1" aria-label="${escapeHtml(provider.label)} base URL">`
            : '';

        return `
            <article class="provider-key-card">
                <strong>${escapeHtml(provider.label)}</strong>
                <small>${provider.requires_api_key
                    ? (keyStatus.configured ? `Key configured: ${escapeHtml(keyStatus.masked || '********')}` : 'API key configured নয়')
                    : (keyStatus.custom_base_url ? `Base URL configured: ${escapeHtml(keyStatus.custom_base_url)}` : 'Base URL configure করুন')}</small>
                ${baseUrlField}
                ${provider.requires_api_key
                    ? `<input class="brain-key-input" type="password" autocomplete="new-password" data-api-key="${escapeHtml(provider.id)}" placeholder="${keyStatus.configured ? 'নতুন key দিলে rotate হবে' : 'আপনার API key'}" aria-label="${escapeHtml(provider.label)} API key">`
                    : ''}
                <div class="provider-key-actions">
                    <button class="button button-quiet button-small" type="button" data-save-provider-key="${escapeHtml(provider.id)}">Credential সংরক্ষণ</button>
                    ${keyStatus.configured ? `<button class="button button-quiet button-small" type="button" data-delete-provider-key="${escapeHtml(provider.id)}">মুছুন</button>` : ''}
                </div>
            </article>
        `;
    }).join('');
}

function renderAgentDirectory() {
    const brains = brainSettings?.agent_brains || {};
    const models = brainSettings?.agent_models || {};
    elements.agentDirectory.innerHTML = Object.entries(AGENT_LABELS).map(([agentName, label]) => {
        const brainMode = brains[agentName] || 'auto';
        const model = models[agentName];
        const modelText = model
            ? selectedModelLabel(model.provider, model.model)
            : `${brainMode === 'fixed' ? 'Fixed model' : 'Auto policy'}`;

        return `
            <article class="panel agent-directory-card">
                <h3>${escapeHtml(label)} · ${escapeHtml(agentName)}</h3>
                <p>Brain: ${escapeHtml(brainMode)} · ${escapeHtml(modelText)}</p>
                <button class="button button-quiet button-small" type="button" data-open-brain>Brain configure করুন</button>
            </article>
        `;
    }).join('');
}

async function loadBrainSettings() {
    if (!apiToken) {
        return;
    }

    const responseData = await apiRequest('/llm/settings');
    brainSettings = responseData.settings || {};
    brainCatalog = responseData.catalog || {};
    brainKeys = responseData.keys || {};
    for (const model of brainSettings?.approved_models || []) {
        const provider = brainCatalog[model.provider];
        if (provider && !provider.models[model.model]) {
            provider.models[model.model] = {
                tools: false,
                vision: false,
                context_window: null,
                streaming: false,
                json_mode: false,
                capabilities_unverified: true,
            };
        }
    }
    renderModelCatalog();
    renderBrainSelectors();
    renderProviderKeys();
    renderAgentDirectory();
}

function collectBrainSettings() {
    const approvedModels = approvedModelChoices();
    const agentBrains = {};
    const agentModels = {};

    elements.agentBrainGrid.querySelectorAll('[data-agent-mode]').forEach((select) => {
        const agentName = select.dataset.agentMode;
        const brainMode = select.value;
        agentBrains[agentName] = brainMode;
        if (brainMode === 'custom') {
            const selectedKey = elements.agentBrainGrid.querySelector(`[data-agent-model="${CSS.escape(agentName)}"]`)?.value || '';
            const [provider, ...modelSegments] = selectedKey.split(':');
            const model = modelSegments.join(':');
            if (provider && model) {
                agentModels[agentName] = { provider, model };
            }
        }
    });

    const modelPricing = [];
    for (const model of approvedModels) {
        const input = elements.modelCatalog.querySelector(`[data-price="input"][data-provider="${CSS.escape(model.provider)}"][data-model="${CSS.escape(model.model)}"]`);
        const output = elements.modelCatalog.querySelector(`[data-price="output"][data-provider="${CSS.escape(model.provider)}"][data-model="${CSS.escape(model.model)}"]`);
        const inputRate = input?.value.trim() || '';
        const outputRate = output?.value.trim() || '';
        const currencyInput = elements.modelCatalog.querySelector(`[data-price-currency][data-provider="${CSS.escape(model.provider)}"][data-model="${CSS.escape(model.model)}"]`);
        const currency = currencyInput?.value.trim().toUpperCase() || 'USD';
        if (inputRate === '' && outputRate === '') {
            continue;
        }
        if (inputRate === '' || outputRate === '') {
            throw new Error(`${model.model} model-এর input ও output rate—দুটিই দিন, অথবা দুটোই ফাঁকা রাখুন।`);
        }
        modelPricing.push({
            ...model,
            currency,
            input_cost_per_million: Number(inputRate),
            output_cost_per_million: Number(outputRate),
        });
    }

    const [fallbackProvider = '', ...fallbackModelSegments] = elements.fallbackProvider.value.split(':');
    const fallbackModel = fallbackModelSegments.join(':');

    return {
        provider: elements.defaultProvider.value,
        model: elements.defaultModel.value,
        approved_models: approvedModels,
        agent_brains: agentBrains,
        agent_models: agentModels,
        routing_policy: elements.routingPolicy.value,
        fallback_provider: fallbackProvider || null,
        fallback_model: fallbackModel || null,
        model_pricing: modelPricing,
    };
}

elements.modelCatalog.addEventListener('change', (event) => {
    if (event.target.matches('[data-approved-model]')) {
        const agentModes = Object.fromEntries(
            [...elements.agentBrainGrid.querySelectorAll('[data-agent-mode]')].map((select) => [select.dataset.agentMode, select.value]),
        );
        const agentModels = Object.fromEntries(
            [...elements.agentBrainGrid.querySelectorAll('[data-agent-model]')].map((select) => [select.dataset.agentModel, select.value]),
        );
        renderBrainSelectors({
            provider: elements.defaultProvider.value,
            model: elements.defaultModel.value,
            fallback: elements.fallbackProvider.value,
            policy: elements.routingPolicy.value,
            agentModes,
            agentModels,
        });
    }
});

elements.modelCatalog.addEventListener('click', (event) => {
    const addButton = event.target.closest('[data-add-custom-model]');
    if (!addButton) {
        return;
    }

    const providerId = addButton.dataset.addCustomModel;
    const modelInput = elements.modelCatalog.querySelector(`[data-new-model-id="${CSS.escape(providerId)}"]`);
    const modelId = modelInput?.value.trim() || '';
    if (!/^[a-zA-Z0-9._:-]{1,128}$/.test(modelId)) {
        showNotice(elements.error, 'Custom model ID-তে কেবল অক্ষর, সংখ্যা, dot, underscore, colon বা hyphen দিন।');
        return;
    }
    if (brainCatalog[providerId].models[modelId]) {
        showNotice(elements.error, 'এই model ID catalog-এ আগে থেকেই আছে।');
        return;
    }

    brainCatalog[providerId].models[modelId] = {
        tools: false,
        vision: false,
        context_window: null,
        streaming: false,
        json_mode: false,
        capabilities_unverified: true,
    };
    const approvedKeys = approvedModelChoices();
    renderModelCatalog();
    for (const approvedModel of approvedKeys) {
        const checkbox = elements.modelCatalog.querySelector(`[data-approved-model][data-provider="${CSS.escape(approvedModel.provider)}"][data-model="${CSS.escape(approvedModel.model)}"]`);
        if (checkbox) {
            checkbox.checked = true;
        }
    }
    renderBrainSelectors();
    showNotice(elements.success, 'Custom model তালিকায় যোগ হয়েছে। Capability unverified থাকবে।');
});

elements.agentBrainGrid.addEventListener('change', (event) => {
    if (event.target.matches('[data-agent-mode]')) {
        const agentName = event.target.dataset.agentMode;
        const modelLabel = elements.agentBrainGrid.querySelector(`[data-agent-model-label="${CSS.escape(agentName)}"]`);
        modelLabel.hidden = event.target.value !== 'custom';
    }
});

elements.defaultProvider.addEventListener('change', () => {
    const previousProvider = brainSettings?.provider;
    const previousModel = brainSettings?.model;
    const approvedModels = approvedModelChoices().filter((model) => model.provider === elements.defaultProvider.value);
    elements.defaultModel.innerHTML = approvedModels.map((model) => `
        <option value="${escapeHtml(model.model)}">${escapeHtml(model.model)}</option>
    `).join('') || '<option value="">Approved model নেই</option>';
    if (elements.defaultProvider.value === previousProvider
        && approvedModels.some((model) => model.model === previousModel)) {
        elements.defaultModel.value = previousModel;
    }
});

elements.fallbackProvider.addEventListener('change', () => {
    elements.fallbackModel.value = elements.fallbackProvider.value;
});

elements.fallbackModel.addEventListener('change', () => {
    elements.fallbackProvider.value = elements.fallbackModel.value;
});

elements.brainSettingsForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearNotices();

    try {
        const settingsData = collectBrainSettings();
        if (!settingsData.provider || !settingsData.model || settingsData.approved_models.length === 0) {
            throw new Error('সংরক্ষণের আগে অন্তত একটি model approve করে Fixed provider/model বেছে নিন।');
        }
        if (Object.entries(settingsData.agent_brains).some(([agent, mode]) => mode === 'custom' && !settingsData.agent_models[agent])) {
            throw new Error('প্রতিটি Custom mode agent-এর জন্য approved model বেছে নিন।');
        }
        const responseData = await apiRequest('/llm/settings', {
            method: 'PUT',
            body: JSON.stringify(settingsData),
        });
        brainSettings = responseData.settings;
        renderBrainSelectors();
        renderAgentDirectory();
        showNotice(elements.success, 'Brain settings সংরক্ষিত হয়েছে।');
    } catch (error) {
        showNotice(elements.error, error.message);
    }
});

elements.providerKeyList.addEventListener('click', async (event) => {
    const saveButton = event.target.closest('[data-save-provider-key]');
    const deleteButton = event.target.closest('[data-delete-provider-key]');
    if (!saveButton && !deleteButton) {
        return;
    }

    const providerId = saveButton?.dataset.saveProviderKey || deleteButton.dataset.deleteProviderKey;
    try {
        if (deleteButton) {
            if (!window.confirm(`${brainCatalog[providerId]?.label || providerId} credential মুছে দেবেন?`)) {
                return;
            }
            await apiRequest(`/llm/keys/${encodeURIComponent(providerId)}`, { method: 'DELETE' });
        } else {
            const apiKey = elements.providerKeyList.querySelector(`[data-api-key="${CSS.escape(providerId)}"]`)?.value.trim() || '';
            const baseUrl = elements.providerKeyList.querySelector(`[data-base-url="${CSS.escape(providerId)}"]`)?.value.trim() || '';
            if (!apiKey && !baseUrl) {
                throw new Error('Credential সংরক্ষণ করতে API key অথবা custom provider-এর base URL দিন।');
            }
            await apiRequest(`/llm/keys/${encodeURIComponent(providerId)}`, {
                method: 'PUT',
                body: JSON.stringify({
                    ...(apiKey ? { api_key: apiKey } : {}),
                    ...(baseUrl ? { base_url: baseUrl } : {}),
                }),
            });
        }
        await loadBrainSettings();
        showNotice(elements.success, 'Provider credential settings আপডেট হয়েছে।');
    } catch (error) {
        showNotice(elements.error, error.message);
    }
});

elements.agentDirectory.addEventListener('click', (event) => {
    if (event.target.closest('[data-open-brain]')) {
        switchView('brain');
    }
});

elements.mobileMoreButton.addEventListener('click', () => {
    const isExpanded = elements.mobileMoreButton.getAttribute('aria-expanded') === 'true';
    elements.mobileMoreButton.setAttribute('aria-expanded', String(!isExpanded));
    elements.mobileMoreMenu.hidden = isExpanded;
});

elements.connectionForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearNotices();

    const suppliedToken = elements.tokenInput.value.trim();
    if (!suppliedToken) {
        showNotice(elements.error, 'Sanctum API token দিন।');
        return;
    }

    try {
        await activateToken(suppliedToken);
        elements.tokenInput.value = '';
        showNotice(elements.success, 'API সংযোগ সফল হয়েছে।');
    } catch (error) {
        apiToken = '';
        sessionStorage.removeItem(TOKEN_STORAGE_KEY);
        setConnected(false);
        showNotice(elements.error, error.message);
    }
});

elements.authModeToggle.addEventListener('click', () => {
    setAuthMode(authMode === 'login' ? 'register' : 'login');
    clearNotices();
});

elements.authForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearNotices();

    const formData = new FormData(elements.authForm);
    const credentials = {
        email: String(formData.get('email') || '').trim(),
        password: String(formData.get('password') || ''),
    };
    const isRegistering = authMode === 'register';
    const requestData = isRegistering
        ? {
            ...credentials,
            name: String(formData.get('name') || '').trim(),
            password_confirmation: String(formData.get('password_confirmation') || ''),
        }
        : credentials;

    elements.authSubmit.disabled = true;
    try {
        const responseData = await publicApiRequest(`/auth/${authMode}`, {
            method: 'POST',
            body: JSON.stringify(requestData),
        });
        await activateToken(responseData.token, responseData.user.name);
        elements.authForm.reset();
        showNotice(elements.success, isRegistering ? 'নতুন private workspace তৈরি হয়েছে।' : 'নিরাপদে প্রবেশ করা হয়েছে।');
    } catch (error) {
        apiToken = '';
        sessionStorage.removeItem(TOKEN_STORAGE_KEY);
        setConnected(false);
        showNotice(elements.error, error.message);
    } finally {
        elements.authSubmit.disabled = false;
    }
});

async function clearSession() {
    apiToken = '';
    selectedTaskId = null;
    sessionStorage.removeItem(TOKEN_STORAGE_KEY);
    window.clearTimeout(refreshTimer);
    setConnected(false);
    renderTaskList([]);
    elements.taskList.innerHTML = '<div class="empty-state">কাজ দেখতে API token দিয়ে সংযুক্ত করুন।</div>';
    elements.taskDetail.innerHTML = '<div class="empty-state"><strong>একটি কাজ নির্বাচন করুন</strong><span>কাজের ধাপ ও agent-এর ফলাফল এখানে দেখা যাবে।</span></div>';
    elements.approvalList.innerHTML = '<div class="panel empty-state">অনুমোদন দেখতে API token দিয়ে সংযুক্ত করুন।</div>';
    setApprovalCount(0);
    clearNotices();
}

elements.disconnectButton.addEventListener('click', async () => {
    elements.disconnectButton.disabled = true;
    try {
        await apiRequest('/auth/logout', { method: 'POST' });
        await clearSession();
    } catch (error) {
        showNotice(elements.error, error.message);
    } finally {
        elements.disconnectButton.disabled = false;
    }
});

elements.taskForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearNotices();
    const submitButton = elements.taskForm.querySelector('button[type="submit"]');
    const formData = new FormData(elements.taskForm);
    const taskData = {
        title: String(formData.get('title') || '').trim(),
        prompt: String(formData.get('prompt') || '').trim(),
    };
    submitButton.disabled = true;

    try {
        const isCurrentEstimateConfirmed = pendingTaskConfirmation
            && pendingTaskConfirmation.title === taskData.title
            && pendingTaskConfirmation.prompt === taskData.prompt;
        if (!isCurrentEstimateConfirmed) {
            const estimateResponse = await apiRequest('/tasks/estimate', {
                method: 'POST',
                body: JSON.stringify({ prompt: taskData.prompt }),
            });
            renderTaskEstimate(estimateResponse);
            pendingTaskConfirmation = estimateResponse.cost_estimate.can_complete === false
                ? null
                : taskData;
            submitButton.innerHTML = estimateResponse.cost_estimate.can_complete === false
                ? 'সীমার বাইরে — prompt ছোট করুন'
                : 'অনুমান দেখে শুরু করুন <span aria-hidden="true">→</span>';
            return;
        }

        const responseData = await apiRequest('/tasks', {
            method: 'POST',
            body: JSON.stringify(taskData),
        });
        pendingTaskConfirmation = null;
        elements.taskEstimate.hidden = true;
        submitButton.innerHTML = 'কাজ জমা দিন <span aria-hidden="true">→</span>';
        elements.taskForm.reset();
        selectedTaskId = responseData.task.id;
        await loadTasks();
        showNotice(elements.success, 'কাজ জমা হয়েছে; agent-দের অগ্রগতি নিচে দেখা যাবে।');
    } catch (error) {
        showNotice(elements.error, error.message);
    } finally {
        submitButton.disabled = false;
    }
});

function renderTaskEstimate(responseData) {
    const { routing, cost_estimate: costEstimate } = responseData;
    const routingComparison = responseData.routing_cost_comparison || {};
    const costLine = costEstimate.status === 'available'
        ? `সর্বোচ্চ আনুমানিক খরচ: ${costEstimate.maximum_cost} ${costEstimate.currency}`
        : `টাকার হিসাব নেই: ${costEstimate.reason}`;
    const comparisonLine = ['auto', 'fixed'].map((strategy) => {
        const estimate = routingComparison[strategy];
        if (!estimate) {
            return '';
        }
        const price = estimate.status === 'available'
            ? `${estimate.maximum_cost} ${estimate.currency}`
            : `অজানা (${estimate.reason})`;
        return `${strategy === 'auto' ? 'Auto' : 'Fixed'} forecast: ${price}`;
    }).filter(Boolean);
    const selectedModelReasons = (costEstimate.agents || []).map((agentEstimate) => {
        const fallbackModels = (agentEstimate.fallback_candidates || [])
            .map((fallback) => `${fallback.provider}/${fallback.model}`)
            .join(', ');
        const fallbackLine = fallbackModels
            ? `; fallback: ${fallbackModels} (${agentEstimate.fallback_maximum_cost ?? 'price unavailable'} ${agentEstimate.fallback_currency || ''})`
            : '';

        return `${agentEstimate.agent}: ${agentEstimate.provider}/${agentEstimate.model} — ${agentEstimate.routing_reason}${fallbackLine}`;
    });

    elements.taskEstimate.textContent = [
        `পথ: ${routing.strategy === 'multi_agent' ? 'একাধিক agent' : 'একটি agent'} (${routing.agent_sequence.join(' → ')})`,
        `কেন: ${routing.reason}`,
        `সর্বোচ্চ token: ${costEstimate.estimated_tokens ?? 'হিসাব করা যায়নি'} / ${costEstimate.maximum_tokens}`,
        costEstimate.can_complete === false
            ? 'এই prompt-এর জন্য token সীমায় উত্তর দেওয়ার জায়গা নেই; prompt ছোট করে আবার অনুমান করুন।'
            : '',
        costLine,
        ...comparisonLine,
        ...selectedModelReasons,
        `Routing evaluation: ${routingComparison.evaluation?.reason || 'উপলব্ধ নয়'}`,
        'হিসাবটি configured fallback-সহ সর্বোচ্চ token budget ও আনুমানিক input token-এর উপর ভিত্তি করে; failed request-এর খরচসহ provider-এর প্রকৃত bill ভিন্ন হতে পারে।',
        'এখন আবার বোতাম চাপলে কাজ শুরু হবে।',
    ].join('\n');
    elements.taskEstimate.hidden = false;
}

elements.taskList.addEventListener('click', async (event) => {
    const taskButton = event.target.closest('[data-task-id]');
    if (!taskButton) {
        return;
    }

    try {
        await loadTask(taskButton.dataset.taskId);
    } catch (error) {
        showNotice(elements.error, error.message);
    }
});

elements.taskDetail.addEventListener('click', async (event) => {
    const replayButton = event.target.closest('[data-replay-task-id]');
    const transitionButton = event.target.closest('[data-live-transition-task-id]');

    try {
        if (replayButton) {
            await loadSandboxReplay(replayButton.dataset.replayTaskId);
        }

        if (transitionButton) {
            const shouldRequestTransition = window.confirm(
                'সব safety gate পাস হলেই আলাদা Live task শুরু হবে। Live tool action-এ approval লাগবে। এগোবেন?',
            );
            if (!shouldRequestTransition) {
                return;
            }

            const responseData = await apiRequest(
                `/tasks/${encodeURIComponent(transitionButton.dataset.liveTransitionTaskId)}/transition-to-live`,
                {
                    method: 'POST',
                    body: JSON.stringify({ confirm_live_transition: true }),
                },
            );
            selectedTaskId = responseData.task.id;
            await loadTasks();
            showNotice(elements.success, 'নতুন Live task queue হয়েছে।');
        }
    } catch (error) {
        showNotice(elements.error, error.message);
    }
});

elements.approvalList.addEventListener('click', async (event) => {
    const decisionButton = event.target.closest('[data-approval-id][data-decision]');
    if (!decisionButton) {
        return;
    }

    const approvalId = decisionButton.dataset.approvalId;
    const decision = decisionButton.dataset.decision;
    decisionButton.disabled = true;

    try {
        await apiRequest(`/approvals/${encodeURIComponent(approvalId)}/${decision}`, {
            method: 'POST',
            body: JSON.stringify({}),
        });
        await loadApprovals();
        await loadTasks();
        showNotice(elements.success, decision === 'approve' ? 'কাজটি অনুমোদিত হয়েছে।' : 'কাজটি প্রত্যাখ্যান করা হয়েছে।');
    } catch (error) {
        showNotice(elements.error, error.message);
    } finally {
        decisionButton.disabled = false;
    }
});

elements.navigationButtons.forEach((button) => {
    button.addEventListener('click', () => switchView(button.dataset.view));
});

document.querySelector('#refresh-tasks').addEventListener('click', async () => {
    try {
        await loadTasks();
    } catch (error) {
        showNotice(elements.error, error.message);
    }
});

setAuthMode('login');
setConnected(false);
if (apiToken) {
    activateToken(apiToken)
        .catch((error) => {
            apiToken = '';
            sessionStorage.removeItem(TOKEN_STORAGE_KEY);
            setConnected(false);
            showNotice(elements.error, error.message);
        });
}
