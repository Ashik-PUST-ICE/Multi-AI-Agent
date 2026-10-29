import './bootstrap';

const API_BASE = '/api/v1';
const TOKEN_STORAGE_KEY = 'multi-ai-agent.api-token';
const STATUS_LABELS = {
    0: 'অপেক্ষমাণ',
    1: 'চলছে',
    2: 'সম্পন্ন',
    3: 'ব্যর্থ',
    4: 'বন্ধ করা হয়েছে',
};
const AGENT_SEQUENCE = ['Orchestrator', 'Research', 'Writer', 'Reviewer'];
const AGENT_PACK_STATUS = { DRAFT: 0, ACTIVE: 1, RETIRED: 2 };
const AGENT_PACK_STATUS_LABELS = {
    [AGENT_PACK_STATUS.DRAFT]: 'Draft',
    [AGENT_PACK_STATUS.ACTIVE]: 'Active',
    [AGENT_PACK_STATUS.RETIRED]: 'Retired',
};

const elements = {
    approvalCount: document.querySelector('#approval-count'),
    approvalList: document.querySelector('#approval-list'),
    batchApprovalButton: document.querySelector('#batch-approval-button'),
    application: document.querySelector('#application'),
    accessMode: document.querySelector('#access-mode'),
    accessSettingsForm: document.querySelector('#access-settings-form'),
    agentAccessModes: document.querySelector('#agent-access-modes'),
    accessPolicyForm: document.querySelector('#access-policy-form'),
    accessPolicyList: document.querySelector('#access-policy-list'),
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
    agentTrustList: document.querySelector('#agent-trust-list'),
    agentBrainGrid: document.querySelector('#agent-brain-grid'),
    agentPackList: document.querySelector('#agent-pack-list'),
    agentPackSelect: document.querySelector('#task-agent-pack'),
    agentPackAdmin: document.querySelector('#agent-pack-admin'),
    agentPackAdminList: document.querySelector('#agent-pack-admin-list'),
    agentPackForm: document.querySelector('#agent-pack-form'),
    agentPackFormStatus: document.querySelector('#agent-pack-form-status'),
    embeddingSettingsForm: document.querySelector('#embedding-settings-form'),
    embeddingProvider: document.querySelector('#embedding-provider'),
    embeddingModel: document.querySelector('#embedding-model'),
    embeddingSettingsStatus: document.querySelector('#embedding-settings-status'),
    knowledgeAdminSettings: document.querySelector('#knowledge-admin-settings'),
    knowledgeAdminSettingsForm: document.querySelector('#knowledge-admin-settings-form'),
    securityAdmin: document.querySelector('#security-admin'),
    systemKillSwitchButton: document.querySelector('#system-kill-switch-button'),
    knowledgeUploadForm: document.querySelector('#knowledge-upload-form'),
    knowledgeDocumentList: document.querySelector('#knowledge-document-list'),
    taskKnowledgeDocuments: document.querySelector('#task-knowledge-documents'),
    taskMemoryChoices: document.querySelector('#task-memory-choices'),
    knowledgeSearchForm: document.querySelector('#knowledge-search-form'),
    knowledgeSearchResults: document.querySelector('#knowledge-search-results'),
    memoryForm: document.querySelector('#memory-form'),
    memoryList: document.querySelector('#memory-list'),
    brainSettingsForm: document.querySelector('#brain-settings-form'),
    defaultProvider: document.querySelector('#default-provider'),
    defaultModel: document.querySelector('#default-model'),
    fallbackProvider: document.querySelector('#fallback-provider'),
    fallbackModel: document.querySelector('#fallback-model'),
    routingPolicy: document.querySelector('#routing-policy'),
    maxTaskCost: document.querySelector('#max-task-cost'),
    budgetCurrency: document.querySelector('#budget-currency'),
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
    applyTaskCriteria: document.querySelector('#apply-task-criteria'),
    taskDetail: document.querySelector('#task-detail'),
    taskForm: document.querySelector('#task-form'),
    taskMaxCost: document.querySelector('#task-max-cost'),
    taskList: document.querySelector('#task-list'),
    silentFailureCount: document.querySelector('#silent-failure-count'),
    stagnationCount: document.querySelector('#stagnation-count'),
    skepticSignal: document.querySelector('#skeptic-signal'),
    failureCategories: document.querySelector('#failure-categories'),
    skillDirectory: document.querySelector('#skill-directory'),
    tokenInput: document.querySelector('#api-token'),
    views: document.querySelectorAll('.view-panel'),
    navigationButtons: document.querySelectorAll('[data-view]'),
    mobileMoreButton: document.querySelector('#mobile-more-button'),
    mobileMoreMenu: document.querySelector('#mobile-more-menu'),
    notificationList: document.querySelector('#notification-list'),
    notificationChannelStatus: document.querySelector('#notification-channel-status'),
    webAccessForm: document.querySelector('#web-access-form'),
    webSearchProvider: document.querySelector('#web-search-provider'),
    webSearchApiKey: document.querySelector('#web-search-api-key'),
    webAllowedDomains: document.querySelector('#web-allowed-domains'),
    webDeniedDomains: document.querySelector('#web-denied-domains'),
    webAccessStatus: document.querySelector('#web-access-status'),
    deleteWebSearchKey: document.querySelector('#delete-web-search-key'),
};

let apiToken = sessionStorage.getItem(TOKEN_STORAGE_KEY) || '';
let selectedTaskId = null;
let refreshTimer = null;
let pendingTaskConfirmation = null;
let brainSettings = null;
let brainCatalog = {};
let brainKeys = {};
let agentPacks = [];
let editingAgentPack = null;
let embeddingCatalog = {};
let knowledgeDocuments = [];
let canManageKnowledgeSettings = false;
let canManageSecurity = false;
let systemKillSwitchEnabled = false;
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
    canManageKnowledgeSettings = userResponse.user.can_manage_knowledge_settings === true;
    canManageSecurity = userResponse.user.can_manage_security === true;
    setConnected(true);
    await Promise.all([loadTasks(), loadApprovals(), loadAccess(), loadNotifications()]);
    await loadAgentPacks();
    await loadKnowledge();
}

function switchView(viewName) {
    const viewAliases = { agents: 'agents', brain: 'brain' };
    const selectedView = viewAliases[viewName] || viewName;
    const titleByView = {
        tasks: 'আমার কাজ',
        approvals: 'অনুমোদন',
        agents: 'এজেন্ট',
        brain: 'Brain ও routing',
        skills: 'Skills',
        knowledge: 'ফাইল ও Memory',
        access: 'Access policy',
        notifications: 'Notifications',
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
    if (selectedView === 'brain' && apiToken) {
        loadWebAccessSettings().catch((error) => showNotice(elements.error, error.message));
    }

    if (selectedView === 'agents' && apiToken) {
        loadAgentTrust().catch((error) => showNotice(elements.error, error.message));
        loadAgentPacks().catch((error) => showNotice(elements.error, error.message));
    }

    if (selectedView === 'knowledge' && apiToken) {
        loadKnowledge().catch((error) => showNotice(elements.error, error.message));
    }

    if (selectedView === 'skills' && apiToken) {
        loadSkills().catch((error) => showNotice(elements.error, error.message));
    }

    if (selectedView === 'access' && apiToken) {
        loadAccess().catch((error) => showNotice(elements.error, error.message));
    }

    if (selectedView === 'notifications' && apiToken) {
        loadNotifications().catch((error) => showNotice(elements.error, error.message));
    }
}

async function loadAccess() {
    const [responseData, channelData] = await Promise.all([
        apiRequest('/access'),
        apiRequest('/notifications/channels'),
    ]);
    const access = responseData.access || {};
    elements.accessMode.value = access.mode || 'user';
    const notificationSettings = responseData.notifications || {};
    const enabledChannels = notificationSettings.preferences?.tool_approval?.channels || ['in_app'];
    document.querySelectorAll('[data-notification-channel]').forEach((checkbox) => {
        checkbox.checked = enabledChannels.includes(checkbox.dataset.notificationChannel);
    });
    document.querySelector('#quiet-hours-start').value = notificationSettings.quiet_hours?.start || '';
    document.querySelector('#quiet-hours-end').value = notificationSettings.quiet_hours?.end || '';
    document.querySelector('#notification-digest-time').value = notificationSettings.digest_time || '';
    const agents = ['Orchestrator', 'Research', 'Writer', 'Reviewer'];
    elements.agentAccessModes.innerHTML = agents.map((agent) => `
        <label class="brain-field">${escapeHtml(agent)} access
            <select name="agent_modes.${escapeHtml(agent)}">
                <option value="inherit">Account default (${escapeHtml(access.mode || 'user')})</option>
                <option value="user" ${access.agent_modes?.[agent] === 'user' ? 'selected' : ''}>User</option>
                <option value="auto" ${access.agent_modes?.[agent] === 'auto' ? 'selected' : ''}>Auto</option>
            </select>
        </label>
    `).join('');
    renderAccessPolicies(responseData.policies || []);
    document.querySelector('#enable-push-channel').checked = channelData.channels?.push?.enabled === true;
    const configuredChannels = Object.entries(channelData.channels || {})
        .map(([name, value]) => `<p>${escapeHtml(name)}: ${value.configured ? 'configured' : 'missing'} · ${value.enabled ? 'enabled' : 'disabled'}</p>`);
    const pushSubscriptionStatus = `<p>Push subscriptions: ${escapeHtml(channelData.push_subscriptions || 0)}</p>`;
    elements.notificationChannelStatus.innerHTML = [...configuredChannels, pushSubscriptionStatus].join('');
    elements.notificationChannelStatus.dataset.pushPublicKey = channelData.push_public_key || '';
}

function renderAccessPolicies(policies) {
    if (policies.length === 0) {
        elements.accessPolicyList.innerHTML = '<div class="empty-state">কোনো Auto policy নেই।</div>';
        return;
    }

    elements.accessPolicyList.innerHTML = policies.map((policy) => `
        <article class="panel approval-card">
            <h4>${escapeHtml(policy.agent_name)} · ${escapeHtml(policy.tool_name)}</h4>
            <p>Scope: ${escapeHtml(policy.scope)} · ব্যবহার: ${escapeHtml(policy.uses_count)}/${escapeHtml(policy.maximum_uses)} · expiry: ${escapeHtml(formatDate(policy.expires_at))}</p>
            <p>Undo: ${escapeHtml(policy.undo_window_seconds)}s · Status: ${policy.status === 1 ? 'active' : 'revoked'}</p>
            <button class="button button-danger button-small" type="button" data-revoke-policy="${escapeHtml(policy.id)}">Policy revoke</button>
        </article>
    `).join('');
}

async function loadNotifications() {
    const responseData = await apiRequest('/notifications');
    const notifications = responseData.notifications || [];
    if (notifications.length === 0) {
        elements.notificationList.innerHTML = '<div class="panel empty-state">এখনো কোনো notification নেই।</div>';
        return;
    }

    elements.notificationList.innerHTML = notifications.map((notification) => `
        <article class="panel approval-card">
            <div class="approval-card-header">
                <div><h3>${escapeHtml(notification.title)}</h3><p>${escapeHtml(notification.message)}</p></div>
                <span class="status-chip status-${notification.status === 0 ? 1 : 2}">${notification.status === 0 ? 'Unread' : 'Read'}</span>
            </div>
            <p>${escapeHtml(formatDate(notification.delivered_at))}</p>
            <div class="approval-actions">
                ${notification.data?.approval_id ? `<button class="button button-approve button-small" type="button" data-open-approval>Approval দেখুন</button>` : ''}
                ${notification.status === 0 ? `<button class="button button-quiet button-small" type="button" data-read-notification="${escapeHtml(notification.id)}">পড়া হয়েছে</button>` : ''}
            </div>
        </article>
    `).join('');
}

async function loadSkills() {
    const responseData = await apiRequest('/skills');
    const skills = responseData.skills || [];
    elements.skillDirectory.innerHTML = skills.map((skill) => `
        <article class="panel agent-directory-card">
            <div class="agent-directory-heading">
                <h3>${escapeHtml(skill.name)}</h3>
                <span class="status-chip">${escapeHtml(skill.version)} · ${escapeHtml(skill.status)}</span>
            </div>
            <p>${escapeHtml(skill.description)}</p>
            <small>Owner: ${escapeHtml(skill.owner)} · Minimum trust: ${escapeHtml(skill.minimum_trust_level)} · Required tools: ${skill.required_tools.length ? escapeHtml(skill.required_tools.join(', ')) : 'none'} · Scripts: disabled</small>
        </article>
    `).join('');
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

function renderAgentRuns(agentRuns = [], routing = null, taskId = null) {
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
        const replayAction = agentRun && [2, 3].includes(agentRun.status)
            ? `<button class="button button-quiet button-small" type="button" data-resume-agent-run-id="${escapeHtml(agentRun.id)}">এই ধাপটি Sandbox-এ আবার চালান</button>`
            : '';
        const killAction = agentRun && [0, 1].includes(agentRun.status)
            ? `<button class="button button-quiet button-small" type="button" data-kill-agent-run-id="${escapeHtml(agentRun.id)}" data-task-id="${escapeHtml(taskId)}">এই agent থামান</button>`
            : '';

        return `
            <div class="agent-stage status-${escapeHtml(status)}">
                <span class="stage-icon" aria-hidden="true">${agentRun?.status === 2 ? '✓' : index + 1}</span>
                <span class="stage-name">${escapeHtml(agentName)}</span>
                <span class="status-chip status-${escapeHtml(status)}">${escapeHtml(statusLabel(status))}</span>
                ${extra}
                ${replayAction}
                ${killAction}
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
    const spentByCurrency = Object.entries(task.cost_summary?.spent_by_currency || {})
        .map(([currency, amount]) => `${amount} ${currency}`)
        .join(', ');
    const costLimitSummary = task.cost_budget
        ? ` · Limit: ${task.cost_budget.maximum_cost} ${task.cost_budget.currency}`
        : '';
    const costSummary = task.cost_summary
        ? `<p class="usage-summary">Estimated-rate spend: ${escapeHtml(spentByCurrency || 'এখনো কোনো provider call record হয়নি')}${escapeHtml(costLimitSummary)} · ${escapeHtml(task.cost_summary.priced_call_count)} priced calls${task.cost_summary.unpriced_call_count ? ` · ${escapeHtml(task.cost_summary.unpriced_call_count)} unpriced calls` : ''}</p>`
        : '';
    const checkInSummary = task.check_in
        ? `<div class="notice ${task.check_in.severity === 'warning' ? 'notice-error' : 'notice-info'}" role="status">${escapeHtml(task.check_in.reason)}</div>`
        : '';
    const confidence = task.result?.confidence;
    const confidenceSummary = Number.isInteger(confidence?.score)
        ? `<section class="confidence-card"><strong>Confidence: ${escapeHtml(confidence.score)}%</strong><span>${escapeHtml(confidence.rationale)}</span><span>চিহ্নিত উৎস: ${escapeHtml(confidence.source_count)}</span></section>`
        : '';
    const criteriaChecklist = (task.result?.success_criteria || []).map((item) => `
        <li class="criteria-${escapeHtml(item.status)}">
            <strong>${item.status === 'met' ? '✓' : item.status === 'unmet' ? '✕' : '?'}</strong>
            <span>${escapeHtml(item.criterion)}<small>${escapeHtml(item.evidence || 'প্রমাণ অনুপস্থিত')}</small></span>
        </li>
    `).join('');
    const criteriaPanel = criteriaChecklist
        ? `<section class="criteria-card"><strong>সাফল্যের শর্ত ${task.result?.criteria_passed ? 'পাস' : 'পাস হয়নি'}</strong><ul>${criteriaChecklist}</ul></section>`
        : '';
    const skepticFindings = (task.result?.skeptic_findings || []).map((finding) => `
        <li><span class="status-chip">${escapeHtml(finding.severity)}</span><strong>${escapeHtml(finding.claim)}</strong><span>${escapeHtml(finding.reason)}</span></li>
    `).join('');
    const findingsPanel = skepticFindings
        ? `<section class="criteria-card"><strong>Skeptic-এর পর্যবেক্ষণ</strong><ul>${skepticFindings}</ul></section>`
        : '';
    const routingDetails = (task.routing_events || []).map((routingEvent) => {
        const routing = routingEvent.payload?.routing;
        if (routingEvent.event_type === 'agent.model_fallback') {
            return `<div class="replay-event"><strong>Fallback:</strong> ${escapeHtml(routingEvent.payload.from_model)} → ${escapeHtml(routingEvent.payload.to_model)} · ${escapeHtml(routingEvent.payload.reason)}</div>`;
        }
        return `<div class="replay-event"><strong>${escapeHtml(routingEvent.payload.agent)} · ${escapeHtml(routingEvent.payload.model || 'model')}</strong> · ${escapeHtml(routing?.brain_mode || 'Auto')} / ${escapeHtml(routing?.policy || 'policy')} · ${escapeHtml(routing?.reason || 'Routing reason unavailable')} · ${escapeHtml(routingEvent.mode)}</div>`;
    }).join('');
    const activeShareRows = (task.replay_shares || []).map((share) => `
        <div class="replay-share-entry" data-replay-share-entry="${escapeHtml(share.id)}">
            <span>মেয়াদ শেষ: ${escapeHtml(formatDate(share.expires_at))}</span>
            <button class="button button-quiet button-small" type="button" data-revoke-replay-share-id="${escapeHtml(share.id)}" data-task-id="${escapeHtml(task.id)}">বাতিল</button>
        </div>
    `).join('');
    const sharePanel = activeShareRows
        ? `<section class="panel" data-replay-share-panel><strong>সক্রিয় replay link</strong><div data-replay-share-list>${activeShareRows}</div></section>`
        : '';
    const taskMode = task.mode === 'live' ? 'live' : 'sandbox';
    const modeActions = [
        `<button class="button button-quiet button-small" type="button" data-replay-task-id="${escapeHtml(task.id)}">Sandbox replay (read only)</button>`,
        `<button class="button button-quiet button-small" type="button" data-share-task-replay-id="${escapeHtml(task.id)}">Read-only share link তৈরি</button>`,
    ];

    if (taskMode === 'sandbox' && task.status === 2) {
        modeActions.unshift(
            `<button class="button button-small" type="button" data-live-transition-task-id="${escapeHtml(task.id)}">Live mode-এর safety gate যাচাই</button>`,
        );
    }
    if ([0, 1].includes(task.status)) {
        modeActions.unshift(
            `<button class="button button-quiet button-small" type="button" data-kill-task-id="${escapeHtml(task.id)}">Task থামান</button>`,
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
        ${checkInSummary}
        <div class="agent-timeline">${renderAgentRuns(task.agent_runs || [], task.routing, task.id)}</div>
        ${routingDetails ? `<section class="routing-reasons"><strong>কেন এই model</strong>${routingDetails}</section>` : ''}
        ${sharePanel}
        ${usageSummary}
        ${costSummary}
        ${confidenceSummary}
        ${criteriaPanel}
        ${findingsPanel}
        ${finalResult}
        ${failureMessage}
    `;
}

async function loadSandboxReplay(taskId) {
    const responseData = await apiRequest(`/tasks/${encodeURIComponent(taskId)}/replay`);
    const eventRows = responseData.events.length > 0
        ? responseData.events.map((event) => {
            const details = event.payload && Object.keys(event.payload).length > 0
                ? `<pre>${escapeHtml(JSON.stringify(event.payload, null, 2))}</pre>`
                : '';
            const message = event.message
                ? `<details><summary>${escapeHtml(event.message.role)} message</summary><pre>${escapeHtml(event.message.content)}</pre></details>`
                : '';
            return `
                <article class="replay-event">
                    <strong>${escapeHtml(event.event_type)}</strong>
                    · ${escapeHtml(event.source_mode)} → ${escapeHtml(event.mode)}
                    · ${escapeHtml(formatDate(event.occurred_at))}
                    ${details}
                    ${message}
                </article>
            `;
        }).join('')
        : '<div class="empty-state">এই কাজের জন্য কোনো replay event নেই।</div>';

    const existingReplay = elements.taskDetail.querySelector('[data-replay-events]');
    if (existingReplay) {
        existingReplay.remove();
    }

    const replayPanel = document.createElement('section');
    replayPanel.className = 'panel';
    replayPanel.dataset.replayEvents = 'true';
    replayPanel.innerHTML = `<strong>Sandbox replay · read only</strong><p>Replay শুধু সংরক্ষিত event দেখায়; কোনো tool বা provider চালায় না।</p>${eventRows}`;
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

    await loadObservabilitySummary();
}

async function loadObservabilitySummary() {
    const summary = await apiRequest('/observability/summary');
    const warnings = summary.warnings || {};
    const reviewerSignal = summary.skeptic_review_signal || {};
    elements.silentFailureCount.textContent = String(warnings.silent_failures || 0);
    elements.stagnationCount.textContent = String(warnings.stagnation || 0);
    elements.skepticSignal.textContent = reviewerSignal.warning === true
        ? 'পর্যালোচনা প্রয়োজন'
        : 'কোনো সংকেত নেই';

    const categories = Object.entries(summary.failure_categories || {});
    elements.failureCategories.replaceChildren();
    if (categories.length === 0) {
        const emptyItem = document.createElement('li');
        emptyItem.textContent = 'এই সময়ে কোনো failure record নেই।';
        elements.failureCategories.append(emptyItem);
        return;
    }

    categories.forEach(([category, count]) => {
        const item = document.createElement('li');
        item.textContent = `${category}: ${count}`;
        elements.failureCategories.append(item);
    });
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
    elements.batchApprovalButton.disabled = true;

    if (approvals.length === 0) {
        elements.approvalList.innerHTML = '<div class="panel empty-state"><strong>কোনো অনুমোদন অপেক্ষায় নেই</strong><span>নতুন approval request এলে এখানে দেখা যাবে।</span></div>';
        return;
    }

    elements.approvalList.innerHTML = approvals.map((approval) => `
        <article class="panel approval-card">
            <div class="approval-card-header">
                <div>
                    <label><input type="checkbox" data-approval-select value="${escapeHtml(approval.id)}" data-approval-action="${escapeHtml(approval.action)}"> একই action-এর batch-এ রাখুন</label>
                    <h3>${escapeHtml(approval.action)}</h3>
                    <p>কাজ #${escapeHtml(approval.task_id)} · মেয়াদ: ${escapeHtml(formatDate(approval.expires_at))}</p>
                </div>
                <span class="status-chip status-1">অনুমোদন প্রয়োজন</span>
            </div>
            <pre class="approval-data">${escapeHtml(JSON.stringify(approval.payload?.tool_input || {}, null, 2))}</pre>
            ${approval.deferred_action?.status === 0
                ? `<p>Undo window শেষ হবে: ${escapeHtml(formatDate(approval.deferred_action.scheduled_at))}</p><button class="button button-danger button-small" type="button" data-cancel-deferred="${escapeHtml(approval.deferred_action.id)}">Undo / বাতিল</button>`
                : ''}
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
    elements.maxTaskCost.value = brainSettings?.max_task_cost ?? '';
    elements.budgetCurrency.value = brainSettings?.budget_currency ?? '';
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

async function loadAgentTrust() {
    const responseData = await apiRequest('/trust');
    const agents = responseData.agents || [];
    const historyByAgent = new Map();
    (responseData.history || []).forEach((historyEntry) => {
        const entries = historyByAgent.get(historyEntry.agent_name) || [];
        entries.push(historyEntry);
        historyByAgent.set(historyEntry.agent_name, entries);
    });

    elements.agentTrustList.innerHTML = `
        <div class="agent-trust-grid">
            ${agents.map((agent) => {
                const agentHistory = historyByAgent.get(agent.agent_name) || [];
                const levelOptions = Object.entries(agent.levels).map(([level, description]) => `
                    <option value="${escapeHtml(level)}" ${Number(level) === Number(agent.trust_level) ? 'selected' : ''}>
                        ${escapeHtml(level)} · ${escapeHtml(description)}
                    </option>
                `).join('');
                const historyMarkup = agentHistory.length > 0
                    ? agentHistory.map((entry) => `
                        <div class="trust-history-entry">
                            ${escapeHtml(entry.from_level)} → ${escapeHtml(entry.to_level)}
                            · ${escapeHtml({
                                automatic: 'স্বয়ংক্রিয়ভাবে কমানো',
                                violation: 'সর্বনিম্ন level-এ tool প্রত্যাখ্যাত',
                                user: 'ব্যবহারকারীর পরিবর্তন',
                            }[entry.change_type] || 'Trust পরিবর্তন')}
                            · ${escapeHtml(entry.reason)}
                            · ${escapeHtml(formatDate(entry.changed_at))}
                        </div>
                    `).join('')
                    : '<p>এখনো Trust পরিবর্তনের ইতিহাস নেই।</p>';

                return `
                    <article class="agent-trust-card">
                        <h4>${escapeHtml(agent.agent_name)}</h4>
                        <p>বর্তমান level ${escapeHtml(agent.trust_level)}। প্রয়োজনের বেশি permission দেবেন না।</p>
                        <form class="agent-trust-form" data-trust-agent="${escapeHtml(agent.agent_name)}">
                            <label>
                                Trust Level
                                <select name="trust_level">${levelOptions}</select>
                            </label>
                            <label>
                                পরিবর্তনের কারণ
                                <input name="reason" type="text" maxlength="500" required placeholder="কারণ লিখুন">
                            </label>
                            <button class="button button-quiet button-small" type="submit">Trust Level সংরক্ষণ</button>
                        </form>
                        <div class="trust-history">
                            <h4>পরিবর্তনের ইতিহাস</h4>
                            <div class="trust-history-list">${historyMarkup}</div>
                        </div>
                    </article>
                `;
            }).join('')}
        </div>
    `;
}

async function loadBrainSettings() {
    if (!apiToken) {
        return;
    }

    async function loadWebAccessSettings() {
        if (!apiToken || !elements.webAccessForm) {
            return;
        }

        const responseData = await apiRequest('/web-access');
        const settings = responseData.web_access || {};
        elements.webSearchProvider.value = settings.search_provider || '';
        elements.webSearchApiKey.value = '';
        elements.webAllowedDomains.value = (settings.allowed_domains || []).join('\n');
        elements.webDeniedDomains.value = (settings.denied_domains || []).join('\n');
        elements.webAccessStatus.textContent = settings.has_search_api_key
            ? 'Search key সেভ করা আছে; নিরাপত্তার জন্য key আর দেখানো হয় না।'
            : 'Search provider চালু করতে Settings-এ আপনার API key দিন।';
    }

    function parseDomainList(value) {
        return [...new Set(value.split(/\r?\n/).map((domain) => domain.trim().toLowerCase()).filter(Boolean))];
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

function renderAgentPacks() {
    if (!elements.agentPackList) {
        return;
    }

    elements.agentPackList.innerHTML = agentPacks.length
        ? agentPacks.map((pack) => `
            <article class="agent-pack-card">
                <div>
                    <h4>${escapeHtml(pack.name)}</h4>
                    <p>${escapeHtml(pack.description)}</p>
                    <small>v${escapeHtml(pack.version)} · ${pack.agents.map(escapeHtml).join(', ')}</small>
                </div>
                <button class="button button-quiet button-small" type="button"
                    data-pack-install="${escapeHtml(pack.slug)}"
                    data-pack-enabled="${pack.enabled ? 'true' : 'false'}"
                    data-pack-installed="${pack.installed ? 'true' : 'false'}">
                    ${pack.enabled ? 'বন্ধ করুন' : pack.installed ? 'চালু করুন' : 'Install'}
                </button>
            </article>
        `).join('')
        : '<div class="empty-state">কোনো active agent pack নেই।</div>';

    const enabledPacks = agentPacks.filter((pack) => pack.enabled);
    const selected = elements.agentPackSelect.value;
    elements.agentPackSelect.innerHTML = '<option value="">কোনো pack নয়</option>' + enabledPacks
        .map((pack) => `<option value="${escapeHtml(pack.slug)}">${escapeHtml(pack.name)} (v${escapeHtml(pack.version)})</option>`)
        .join('');
    if (enabledPacks.some((pack) => pack.slug === selected)) {
        elements.agentPackSelect.value = selected;
    }
}

function renderAgentPackAdmin(packs) {
    elements.agentPackAdminList.innerHTML = packs.map((pack) => {
        const isCustom = !pack.is_builtin;
        const actions = [];
        if (isCustom && pack.status === AGENT_PACK_STATUS.ACTIVE) {
            actions.push(`<button class="button button-quiet button-small" type="button" data-pack-new-version="${escapeHtml(pack.slug)}">নতুন version</button>`);
        }
        if (isCustom && pack.status === AGENT_PACK_STATUS.DRAFT) {
            actions.push(`<button class="button button-quiet button-small" type="button" data-pack-edit="${escapeHtml(pack.slug)}" data-pack-version="${escapeHtml(pack.version)}">Edit draft</button>`);
            actions.push(`<button class="button button-quiet button-small" type="button" data-pack-activate="${escapeHtml(pack.slug)}" data-pack-version="${escapeHtml(pack.version)}">Activate</button>`);
        }
        if (isCustom) {
            actions.push(`<button class="button button-quiet button-small" type="button" data-pack-rollback="${escapeHtml(pack.slug)}" data-pack-version="${escapeHtml(pack.version)}">এই version থেকে rollback draft</button>`);
        }

        return `<article class="agent-pack-card">
            <div><h4>${escapeHtml(pack.name)} <small>v${escapeHtml(pack.version)} · ${AGENT_PACK_STATUS_LABELS[pack.status] || 'Unknown'}</small></h4>
            <p>${escapeHtml(pack.description)}</p></div>
            <div class="agent-pack-actions">${actions.join('')}</div>
        </article>`;
    }).join('');
}

async function loadAgentPacks() {
    if (!apiToken) {
        return;
    }

    const responseData = await apiRequest('/agent-packs');
    agentPacks = responseData.packs || [];
    renderAgentPacks();
    elements.agentPackAdmin.hidden = !responseData.can_manage;
    if (responseData.can_manage) {
        const adminData = await apiRequest('/admin/agent-packs');
        renderAgentPackAdmin(adminData.packs || []);
    }
}

function populateEmbeddingModels(selectedModel = '') {
    const models = embeddingCatalog[elements.embeddingProvider.value]?.models || [];
    elements.embeddingModel.innerHTML = models
        .map((model) => `<option value="${escapeHtml(model)}">${escapeHtml(model)}</option>`)
        .join('');
    if (models.includes(selectedModel)) {
        elements.embeddingModel.value = selectedModel;
    }
}

function renderKnowledgeDocuments() {
    const selectedTaskDocuments = new Set(
        [...elements.taskKnowledgeDocuments.querySelectorAll('[data-task-knowledge-document]:checked')]
            .map((input) => String(input.value)),
    );
    const readyDocuments = knowledgeDocuments.filter((document) => document.status === 1 && document.matches_embedding_settings);
    elements.taskKnowledgeDocuments.innerHTML = readyDocuments.length
        ? readyDocuments.map((document) => `<label class="task-knowledge-choice">
            <input type="checkbox" value="${escapeHtml(document.id)}" data-task-knowledge-document ${selectedTaskDocuments.has(String(document.id)) ? 'checked' : ''}>
            <span>${escapeHtml(document.original_name)}</span>
        </label>`).join('')
        : '<small>কোনো indexed ফাইল নেই।</small>';

    if (knowledgeDocuments.length === 0) {
        elements.knowledgeDocumentList.innerHTML = '<div class="empty-state">এখনো কোনো ফাইল যোগ করা হয়নি।</div>';
        return;
    }

    elements.knowledgeDocumentList.innerHTML = knowledgeDocuments.map((document) => {
        const status = {
            0: 'প্রসেস হচ্ছে',
            1: document.matches_embedding_settings ? 'Indexed' : 'Reindex প্রয়োজন',
            2: `ব্যর্থ: ${escapeHtml(document.error_message || 'প্রসেস করা যায়নি')}`,
        }[document.status] || 'অজানা';
        const size = `${Math.max(1, Math.ceil(Number(document.size_bytes || 0) / 1024))} KB`;
        const download = document.status === 1
            ? `<a class="button button-quiet button-small" href="${API_BASE}/knowledge/documents/${encodeURIComponent(document.id)}/download" data-auth-download>ডাউনলোড</a>`
            : '';
        const reindex = document.status === 1 && !document.matches_embedding_settings
            ? `<button class="button button-quiet button-small" type="button" data-reindex-document="${escapeHtml(document.id)}">Reindex</button>`
            : '';

        return `<article class="knowledge-item">
            <label class="knowledge-document-choice">
                <input type="checkbox" value="${escapeHtml(document.id)}" data-knowledge-document-select ${document.status === 1 && document.matches_embedding_settings ? '' : 'disabled'}>
                <span><strong>${escapeHtml(document.original_name)}</strong><small>${size} · ${escapeHtml(document.chunk_count)} chunks · ${status}</small></span>
            </label>
            <div class="knowledge-item-actions">
                ${download}
                ${reindex}
                <button class="button button-quiet button-small" type="button" data-delete-document="${escapeHtml(document.id)}">মুছুন</button>
            </div>
        </article>`;
    }).join('');
}

function renderMemories(memories) {
    elements.memoryList.innerHTML = memories.length
        ? memories.map((memory) => `<article class="knowledge-item">
            <div><strong>${escapeHtml(memory.label || (Number(memory.type) === 0 ? 'Short-term memory' : 'Long-term memory'))}</strong>
                <small>${Number(memory.scope_type) === 1 ? `Task #${escapeHtml(memory.task_id)}` : 'ব্যক্তিগত'} · ${Number(memory.type) === 0 ? 'Short-term' : 'Long-term'}${memory.status === 2 ? ' · পুরোনো version' : ''}${memory.expired ? ' · মেয়াদ শেষ' : ''}${memory.expires_at ? ` · মেয়াদ ${escapeHtml(formatDate(memory.expires_at))}` : ''}</small>
                <p>${escapeHtml(memory.content)}</p></div>
            ${memory.status === 1 ? `<button class="button button-quiet button-small" type="button" data-delete-memory="${escapeHtml(memory.id)}">মুছুন</button>` : ''}
        </article>`).join('')
        : '<div class="empty-state">এখনো কোনো memory সংরক্ষণ করা হয়নি।</div>';

    const selectableMemories = memories.filter((memory) => (
        Number(memory.scope_type) === 0
        && Number(memory.status) === 1
        && memory.expired !== true
    ));
    elements.taskMemoryChoices.innerHTML = selectableMemories.length
        ? selectableMemories.map((memory) => `<label class="knowledge-document-choice">
            <input type="checkbox" value="${escapeHtml(memory.id)}" data-task-memory-select>
            <span><strong>${escapeHtml(memory.label || `Memory #${memory.id}`)}</strong><small>শুধু আপনার পছন্দে এই task-এ যাবে</small></span>
        </label>`).join('')
        : '<small>কোনো active ব্যক্তিগত memory নেই।</small>';
}

async function loadKnowledge() {
    if (!apiToken || !elements.knowledgeDocumentList) {
        return;
    }

    const [embeddingData, documentData, memoryData] = await Promise.all([
        apiRequest('/knowledge/embedding-settings'),
        apiRequest('/knowledge/documents'),
        apiRequest('/memories'),
    ]);
    embeddingCatalog = embeddingData.catalog || {};
    const selectedProvider = embeddingData.settings?.provider || Object.keys(embeddingCatalog)[0] || '';
    elements.embeddingProvider.innerHTML = Object.entries(embeddingCatalog)
        .map(([provider, item]) => `<option value="${escapeHtml(provider)}">${escapeHtml(item.label)}</option>`)
        .join('');
    elements.embeddingProvider.value = selectedProvider;
    populateEmbeddingModels(embeddingData.settings?.model || '');
    elements.embeddingSettingsStatus.textContent = embeddingData.settings?.key_configured
        ? `সংরক্ষিত: ${embeddingData.settings.provider}/${embeddingData.settings.model}`
        : 'Embedding-এর আগে Brain-এ provider key সংরক্ষণ করুন।';

    knowledgeDocuments = documentData.documents || [];
    renderKnowledgeDocuments();
    renderMemories(memoryData.memories || []);

    if (canManageKnowledgeSettings) {
        const settingsData = await apiRequest('/admin/knowledge-settings');
        elements.knowledgeAdminSettings.hidden = false;
        Object.entries(settingsData.settings).forEach(([name, value]) => {
            const input = elements.knowledgeAdminSettingsForm.elements.namedItem(name);
            if (input) {
                input.value = value;
            }
        });
    } else {
        elements.knowledgeAdminSettings.hidden = true;
    }

    if (canManageSecurity) {
        const killSwitchData = await apiRequest('/admin/security/kill-switch');
        systemKillSwitchEnabled = killSwitchData.enabled === true;
        elements.securityAdmin.hidden = false;
        elements.systemKillSwitchButton.textContent = systemKillSwitchEnabled
            ? 'সব agent execution চালু করুন'
            : 'সব agent execution থামান';
        elements.systemKillSwitchButton.setAttribute('aria-pressed', String(systemKillSwitchEnabled));
    } else {
        elements.securityAdmin.hidden = true;
    }
}

elements.systemKillSwitchButton.addEventListener('click', async () => {
    const enabled = !systemKillSwitchEnabled;
    const confirmation = enabled
        ? 'পুরো সিস্টেমের agent execution থামাবেন? চলমান provider call শেষ হওয়া পর্যন্ত অপেক্ষা লাগতে পারে।'
        : 'সিস্টেম kill switch বন্ধ করে নতুন execution অনুমোদন করবেন?';
    if (!window.confirm(confirmation)) {
        return;
    }

    elements.systemKillSwitchButton.disabled = true;
    try {
        const responseData = await apiRequest('/admin/security/kill-switch', {
            method: 'PUT',
            body: JSON.stringify({
                enabled,
                reason: enabled ? 'Enabled by an administrator.' : null,
            }),
        });
        systemKillSwitchEnabled = responseData.enabled === true;
        elements.systemKillSwitchButton.textContent = systemKillSwitchEnabled
            ? 'সব agent execution চালু করুন'
            : 'সব agent execution থামান';
        elements.systemKillSwitchButton.setAttribute('aria-pressed', String(systemKillSwitchEnabled));
        showNotice(elements.success, systemKillSwitchEnabled
            ? 'System kill switch চালু হয়েছে। চলমান কাজ পরবর্তী নিরাপত্তা checkpoint-এ থামবে।'
            : 'System kill switch বন্ধ হয়েছে।');
    } catch (error) {
        showNotice(elements.error, error.message);
    } finally {
        elements.systemKillSwitchButton.disabled = false;
    }
});

elements.knowledgeAdminSettingsForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const submitButton = elements.knowledgeAdminSettingsForm.querySelector('button[type="submit"]');
    submitButton.disabled = true;
    try {
        const settings = Object.fromEntries(new FormData(elements.knowledgeAdminSettingsForm));
        Object.keys(settings).forEach((key) => {
            settings[key] = Number(settings[key]);
        });
        const responseData = await apiRequest('/admin/knowledge-settings', {
            method: 'PUT',
            body: JSON.stringify(settings),
        });
        Object.entries(responseData.settings).forEach(([name, value]) => {
            elements.knowledgeAdminSettingsForm.elements.namedItem(name).value = value;
        });
        showNotice(elements.success, 'Knowledge processing settings সংরক্ষণ হয়েছে।');
    } catch (error) {
        showNotice(elements.error, error.message);
    } finally {
        submitButton.disabled = false;
    }
});

elements.embeddingProvider.addEventListener('change', () => populateEmbeddingModels());
elements.embeddingSettingsForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const submitButton = elements.embeddingSettingsForm.querySelector('button[type="submit"]');
    submitButton.disabled = true;
    try {
        await apiRequest('/knowledge/embedding-settings', {
            method: 'PUT',
            body: JSON.stringify({
                provider: elements.embeddingProvider.value,
                model: elements.embeddingModel.value,
            }),
        });
        await loadKnowledge();
        showNotice(elements.success, 'Embedding settings সংরক্ষণ হয়েছে।');
    } catch (error) {
        showNotice(elements.error, error.message);
    } finally {
        submitButton.disabled = false;
    }
});

elements.knowledgeUploadForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const submitButton = elements.knowledgeUploadForm.querySelector('button[type="submit"]');
    submitButton.disabled = true;
    try {
        const responseData = await apiRequest('/knowledge/documents', {
            method: 'POST',
            body: new FormData(elements.knowledgeUploadForm),
        });
        elements.knowledgeUploadForm.reset();
        await loadKnowledge();
        showNotice(elements.success, `${responseData.document.original_name} private storage-এ indexed হয়েছে।`);
    } catch (error) {
        showNotice(elements.error, error.message);
        await loadKnowledge();
    } finally {
        submitButton.disabled = false;
    }
});

elements.knowledgeDocumentList.addEventListener('click', async (event) => {
    const downloadLink = event.target.closest('[data-auth-download]');
    if (downloadLink) {
        event.preventDefault();
        downloadLink.setAttribute('aria-disabled', 'true');
        try {
            const response = await fetch(downloadLink.href, {
                headers: {
                    Accept: 'application/octet-stream',
                    Authorization: `Bearer ${apiToken}`,
                },
            });
            if (!response.ok) {
                const responseData = await response.json().catch(() => ({}));
                throw new Error(responseData.message || `Download failed (${response.status}).`);
            }
            const documentId = Number(downloadLink.closest('.knowledge-item').querySelector('[data-knowledge-document-select]').value);
            const filename = knowledgeDocuments.find((item) => Number(item.id) === documentId)?.original_name || 'document';
            const objectUrl = URL.createObjectURL(await response.blob());
            const temporaryLink = document.createElement('a');
            temporaryLink.href = objectUrl;
            temporaryLink.download = filename;
            temporaryLink.click();
            URL.revokeObjectURL(objectUrl);
        } catch (error) {
            showNotice(elements.error, error.message);
        } finally {
            downloadLink.removeAttribute('aria-disabled');
        }
        return;
    }

    const button = event.target.closest('[data-delete-document]');
    const reindexButton = event.target.closest('[data-reindex-document]');
    if (reindexButton) {
        reindexButton.disabled = true;
        try {
            const responseData = await apiRequest(`/knowledge/documents/${encodeURIComponent(reindexButton.dataset.reindexDocument)}/reindex`, {
                method: 'POST',
            });
            await loadKnowledge();
            showNotice(elements.success, `${responseData.document.original_name} বর্তমান embedding model-এ reindex হয়েছে।`);
        } catch (error) {
            showNotice(elements.error, error.message);
        } finally {
            reindexButton.disabled = false;
        }
        return;
    }

    if (!button) {
        return;
    }

    button.disabled = true;
    try {
        await apiRequest(`/knowledge/documents/${encodeURIComponent(button.dataset.deleteDocument)}`, { method: 'DELETE' });
        await loadKnowledge();
        showNotice(elements.success, 'ফাইল ও indexed text মুছে দেওয়া হয়েছে।');
    } catch (error) {
        showNotice(elements.error, error.message);
    } finally {
        button.disabled = false;
    }
});

elements.knowledgeSearchForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const formData = new FormData(elements.knowledgeSearchForm);
    const documentIds = [...elements.knowledgeDocumentList.querySelectorAll('[data-knowledge-document-select:checked]')]
        .map((input) => Number(input.value));
    const submitButton = elements.knowledgeSearchForm.querySelector('button[type="submit"]');
    submitButton.disabled = true;
    try {
        const responseData = await apiRequest('/knowledge/search', {
            method: 'POST',
            body: JSON.stringify({
                query: String(formData.get('query') || '').trim(),
                document_ids: documentIds,
                limit: 5,
            }),
        });
        elements.knowledgeSearchResults.innerHTML = responseData.results.length
            ? responseData.results.map((result) => `<article class="knowledge-search-result">
                <p>${escapeHtml(result.content)}</p>
                <small>Source: ${escapeHtml(result.source.name)} · chunk ${escapeHtml(result.source.chunk)} · relevance ${escapeHtml(result.relevance)}</small>
            </article>`).join('')
            : '<div class="empty-state">কোনো indexed ফাইলে প্রাসঙ্গিক অংশ পাওয়া যায়নি।</div>';
    } catch (error) {
        showNotice(elements.error, error.message);
    } finally {
        submitButton.disabled = false;
    }
});

elements.memoryForm.elements.type.addEventListener('change', () => {
    const expiry = elements.memoryForm.elements.expires_in_days;
    expiry.required = elements.memoryForm.elements.type.value === '0';
    expiry.disabled = elements.memoryForm.elements.type.value !== '0';
});
elements.memoryForm.elements.type.dispatchEvent(new Event('change'));
elements.memoryForm.elements.scope_type.addEventListener('change', () => {
    const taskId = elements.memoryForm.elements.task_id;
    const taskScoped = elements.memoryForm.elements.scope_type.value === '1';
    taskId.hidden = !taskScoped;
    taskId.required = taskScoped;
    if (!taskScoped) {
        taskId.value = '';
    }
});
elements.memoryForm.elements.scope_type.dispatchEvent(new Event('change'));
const refreshScopedMemories = async () => {
    const taskId = String(elements.memoryForm.elements.task_id.value || '').trim();
    if (elements.memoryForm.elements.scope_type.value !== '1' || taskId === '') {
        await loadKnowledge();
        return;
    }

    try {
        const responseData = await apiRequest(`/memories?task_id=${encodeURIComponent(taskId)}`);
        renderMemories(responseData.memories || []);
    } catch (error) {
        showNotice(elements.error, error.message);
    }
};
elements.memoryForm.elements.scope_type.addEventListener('change', refreshScopedMemories);
elements.memoryForm.elements.task_id.addEventListener('change', refreshScopedMemories);
elements.memoryForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const formData = new FormData(elements.memoryForm);
    const expiresInDays = String(formData.get('expires_in_days') || '').trim();
    const submitButton = elements.memoryForm.querySelector('button[type="submit"]');
    submitButton.disabled = true;
    try {
        await apiRequest('/memories', {
            method: 'POST',
            body: JSON.stringify({
                type: Number(formData.get('type')),
                scope_type: Number(formData.get('scope_type')),
                task_id: String(formData.get('task_id') || '').trim() === '' ? null : Number(formData.get('task_id')),
                label: String(formData.get('label') || '').trim() || null,
                content: String(formData.get('content') || '').trim(),
                expires_in_days: expiresInDays === '' ? null : Number(expiresInDays),
            }),
        });
        elements.memoryForm.reset();
        elements.memoryForm.elements.type.dispatchEvent(new Event('change'));
        elements.memoryForm.elements.task_id.hidden = true;
        elements.memoryForm.elements.task_id.required = false;
        await loadKnowledge();
        showNotice(elements.success, 'Memory সংরক্ষণ হয়েছে।');
    } catch (error) {
        showNotice(elements.error, error.message);
    } finally {
        submitButton.disabled = false;
    }
});

elements.memoryList.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-delete-memory]');
    if (!button) {
        return;
    }

    button.disabled = true;
    try {
        await apiRequest(`/memories/${encodeURIComponent(button.dataset.deleteMemory)}`, { method: 'DELETE' });
        await loadKnowledge();
        showNotice(elements.success, 'Memory মুছে দেওয়া হয়েছে।');
    } catch (error) {
        showNotice(elements.error, error.message);
    } finally {
        button.disabled = false;
    }
});

function populateAgentPackForm(pack) {
    editingAgentPack = pack ? { slug: pack.slug, version: pack.version } : null;
    const form = elements.agentPackForm;
    form.elements.name.value = pack?.name || '';
    form.elements.slug.value = pack?.slug || '';
    form.elements.slug.readOnly = Boolean(pack);
    form.elements.description.value = pack?.description || '';
    form.elements.guidance.value = pack
        ? Object.values(pack.profiles || {}).map((profile) => profile.guidance).join('\n')
        : '';
    form.elements.examples.value = (pack?.examples || []).join('\n');
    form.querySelectorAll('input[name="agents"]').forEach((input) => {
        input.checked = (pack?.agents || []).includes(input.value);
    });
    elements.agentPackFormStatus.textContent = pack
        ? `Editing ${pack.slug} v${pack.version} draft`
        : 'Guidance cannot change safety or permissions.';
}

elements.agentPackList.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-pack-install]');
    if (!button) {
        return;
    }

    button.disabled = true;
    try {
        const shouldEnable = button.dataset.packEnabled !== 'true';
        await apiRequest(`/agent-packs/${encodeURIComponent(button.dataset.packInstall)}/installation`, {
            method: 'PUT',
            body: JSON.stringify({ enabled: shouldEnable }),
        });
        await loadAgentPacks();
        showNotice(elements.success, shouldEnable ? 'Agent pack চালু হয়েছে।' : 'Agent pack বন্ধ হয়েছে।');
    } catch (error) {
        showNotice(elements.error, error.message);
    } finally {
        button.disabled = false;
    }
});

elements.agentPackAdminList.addEventListener('click', async (event) => {
    const target = event.target.closest('[data-pack-new-version], [data-pack-edit], [data-pack-activate], [data-pack-rollback]');
    if (!target) {
        return;
    }

    target.disabled = true;
    try {
        if (target.dataset.packEdit) {
            const pack = (await apiRequest('/admin/agent-packs')).packs
                .find((item) => item.slug === target.dataset.packEdit && item.version === Number(target.dataset.packVersion));
            if (!pack) {
                throw new Error('Draft pack পাওয়া যায়নি।');
            }
            populateAgentPackForm(pack);
            elements.agentPackForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } else if (target.dataset.packNewVersion) {
            const responseData = await apiRequest(`/admin/agent-packs/${encodeURIComponent(target.dataset.packNewVersion)}/versions`, {
                method: 'POST',
                body: JSON.stringify({}),
            });
            populateAgentPackForm(responseData.pack);
            await loadAgentPacks();
            elements.agentPackForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } else if (target.dataset.packActivate) {
            await apiRequest(`/admin/agent-packs/${encodeURIComponent(target.dataset.packActivate)}/versions/${target.dataset.packVersion}/status`, {
                method: 'POST',
                body: JSON.stringify({ status: AGENT_PACK_STATUS.ACTIVE }),
            });
            await loadAgentPacks();
            showNotice(elements.success, 'Agent pack version সক্রিয় হয়েছে।');
        } else if (target.dataset.packRollback) {
            await apiRequest(`/admin/agent-packs/${encodeURIComponent(target.dataset.packRollback)}/versions/${target.dataset.packVersion}/rollback`, {
                method: 'POST',
                body: JSON.stringify({}),
            });
            await loadAgentPacks();
            showNotice(elements.success, 'Rollback draft তৈরি হয়েছে; activate করার আগে পর্যালোচনা করুন।');
        }
    } catch (error) {
        showNotice(elements.error, error.message);
    } finally {
        target.disabled = false;
    }
});

elements.agentPackForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const formData = new FormData(elements.agentPackForm);
    const agents = formData.getAll('agents');
    const guidanceText = String(formData.get('guidance') || '').trim();
    const packData = {
        name: String(formData.get('name') || '').trim(),
        description: String(formData.get('description') || '').trim(),
        agents,
        guidance: Object.fromEntries(agents.map((agent) => [agent, guidanceText])),
        examples: String(formData.get('examples') || '').split(/\r?\n/).map((value) => value.trim()).filter(Boolean),
    };
    const submitButton = elements.agentPackForm.querySelector('button[type="submit"]');
    submitButton.disabled = true;
    try {
        if (editingAgentPack) {
            await apiRequest(`/admin/agent-packs/${encodeURIComponent(editingAgentPack.slug)}/versions/${editingAgentPack.version}`, {
                method: 'PATCH',
                body: JSON.stringify(packData),
            });
            showNotice(elements.success, 'Pack draft সংরক্ষণ হয়েছে।');
        } else {
            packData.slug = String(formData.get('slug') || '').trim();
            await apiRequest('/admin/agent-packs', { method: 'POST', body: JSON.stringify(packData) });
            showNotice(elements.success, 'Pack draft তৈরি হয়েছে।');
        }
        elements.agentPackForm.reset();
        populateAgentPackForm(null);
        await loadAgentPacks();
    } catch (error) {
        showNotice(elements.error, error.message);
    } finally {
        submitButton.disabled = false;
    }
});

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
        max_task_cost: elements.maxTaskCost.value.trim() === '' ? null : Number(elements.maxTaskCost.value),
        budget_currency: elements.budgetCurrency.value.trim().toUpperCase() || null,
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

        elements.webAccessForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            clearNotices();

            try {
                const provider = elements.webSearchProvider.value || null;
                const apiKey = elements.webSearchApiKey.value.trim();
                await apiRequest('/web-access', {
                    method: 'PUT',
                    body: JSON.stringify({
                        search_provider: provider,
                        ...(apiKey ? { search_api_key: apiKey } : {}),
                        allowed_domains: parseDomainList(elements.webAllowedDomains.value),
                        denied_domains: parseDomainList(elements.webDeniedDomains.value),
                    }),
                });
                elements.webSearchApiKey.value = '';
                await loadWebAccessSettings();
                showNotice(elements.success, 'Web access settings সেভ হয়েছে।');
            } catch (error) {
                showNotice(elements.error, error.message);
            }
        });

        elements.deleteWebSearchKey.addEventListener('click', async () => {
            if (!window.confirm('সেভ করা search API key মুছে Live web search বন্ধ করবেন?')) {
                return;
            }

            try {
                await apiRequest('/web-access/search-key', { method: 'DELETE' });
                await loadWebAccessSettings();
                showNotice(elements.success, 'Search API key মুছে দেওয়া হয়েছে।');
            } catch (error) {
                showNotice(elements.error, error.message);
            }
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

elements.agentTrustList.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-trust-agent]');
    if (!form) {
        return;
    }

    event.preventDefault();
    const formData = new FormData(form);
    const agentName = form.dataset.trustAgent;
    const submitButton = form.querySelector('button[type="submit"]');
    submitButton.disabled = true;

    try {
        await apiRequest(`/trust/${encodeURIComponent(agentName)}`, {
            method: 'PUT',
            body: JSON.stringify({
                trust_level: Number(formData.get('trust_level')),
                reason: String(formData.get('reason') || '').trim(),
            }),
        });
        await loadAgentTrust();
        showNotice(elements.success, `${agentName} এর Trust Level আপডেট হয়েছে।`);
    } catch (error) {
        showNotice(elements.error, error.message);
    } finally {
        submitButton.disabled = false;
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
    canManageKnowledgeSettings = false;
    canManageSecurity = false;
    systemKillSwitchEnabled = false;
    selectedTaskId = null;
    sessionStorage.removeItem(TOKEN_STORAGE_KEY);
    window.clearTimeout(refreshTimer);
    setConnected(false);
    renderTaskList([]);
    elements.knowledgeAdminSettings.hidden = true;
    elements.securityAdmin.hidden = true;
    elements.taskList.innerHTML = '<div class="empty-state">কাজ দেখতে API token দিয়ে সংযুক্ত করুন।</div>';
    elements.taskDetail.innerHTML = '<div class="empty-state"><strong>একটি কাজ নির্বাচন করুন</strong><span>কাজের ধাপ ও agent-এর ফলাফল এখানে দেখা যাবে।</span></div>';
    elements.approvalList.innerHTML = '<div class="panel empty-state">অনুমোদন দেখতে API token দিয়ে সংযুক্ত করুন।</div>';
    agentPacks = [];
    renderAgentPacks();
    knowledgeDocuments = [];
    elements.knowledgeDocumentList.innerHTML = '<div class="empty-state">ফাইল দেখতে API token দিয়ে সংযুক্ত করুন।</div>';
    elements.memoryList.innerHTML = '<div class="empty-state">Memory দেখতে API token দিয়ে সংযুক্ত করুন।</div>';
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
    const successCriteria = String(formData.get('success_criteria') || '')
        .split(/\r?\n/)
        .map((criterion) => criterion.trim())
        .filter(Boolean);
    const taskData = {
        title: String(formData.get('title') || '').trim(),
        prompt: String(formData.get('prompt') || '').trim(),
        success_criteria: successCriteria,
        agent_pack: String(formData.get('agent_pack') || '') || null,
        knowledge_document_ids: [...elements.taskKnowledgeDocuments.querySelectorAll('[data-task-knowledge-document]:checked')]
            .map((input) => Number(input.value)),
        memory_ids: [...elements.taskMemoryChoices.querySelectorAll('[data-task-memory-select]:checked')]
            .map((input) => Number(input.value)),
        max_cost: String(formData.get('max_cost') || '').trim() === ''
            ? null
            : Number(formData.get('max_cost')),
    };
    submitButton.disabled = true;

    try {
        const isCurrentEstimateConfirmed = pendingTaskConfirmation
            && pendingTaskConfirmation.title === taskData.title
            && pendingTaskConfirmation.prompt === taskData.prompt
            && JSON.stringify(pendingTaskConfirmation.success_criteria) === JSON.stringify(taskData.success_criteria)
            && pendingTaskConfirmation.agent_pack === taskData.agent_pack
            && JSON.stringify(pendingTaskConfirmation.knowledge_document_ids) === JSON.stringify(taskData.knowledge_document_ids)
            && JSON.stringify(pendingTaskConfirmation.memory_ids) === JSON.stringify(taskData.memory_ids)
            && pendingTaskConfirmation.max_cost === taskData.max_cost;
        if (!isCurrentEstimateConfirmed) {
            const estimateResponse = await apiRequest('/tasks/estimate', {
                method: 'POST',
                body: JSON.stringify({
                    prompt: taskData.prompt,
                    max_cost: taskData.max_cost,
                    agent_pack: taskData.agent_pack,
                    knowledge_document_ids: taskData.knowledge_document_ids,
                    memory_ids: taskData.memory_ids,
                }),
            });
            renderTaskEstimate(estimateResponse);
            pendingTaskConfirmation = estimateResponse.cost_estimate.can_complete === false
                ? null
                : {
                    ...taskData,
                    task_brief: estimateResponse.task_brief,
                    knowledge_context_token: estimateResponse.knowledge_preview?.token || null,
                    memory_context_hash: estimateResponse.memory_preview?.hash || null,
                };
            submitButton.innerHTML = estimateResponse.cost_estimate.can_complete === false
                ? 'সীমার বাইরে — prompt ছোট করুন'
                : 'অনুমান দেখে শুরু করুন <span aria-hidden="true">→</span>';
            return;
        }

        taskData.knowledge_context_token = pendingTaskConfirmation.knowledge_context_token;
        taskData.memory_context_hash = pendingTaskConfirmation.memory_context_hash;
        const responseData = await apiRequest('/tasks', {
            method: 'POST',
            body: JSON.stringify(taskData),
        });
        pendingTaskConfirmation = null;
        elements.taskEstimate.hidden = true;
        elements.applyTaskCriteria.hidden = true;
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
    const budgetLine = responseData.cost_budget
        ? `খরচের সীমা: ${responseData.cost_budget.maximum_cost} ${responseData.cost_budget.currency}`
        : 'এই কাজের জন্য আলাদা spending limit নেই।';
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

    const estimateLines = [
        `Task brief: ${responseData.task_brief?.language || 'unknown'} / ${responseData.task_brief?.intent || 'unknown'}; risk ${responseData.task_brief?.risk?.level || 'unknown'}; complexity ${responseData.task_brief?.complexity?.level || 'unknown'}`,
        responseData.task_brief?.ambiguity?.questions?.length
            ? `সম্ভাব্য clarification (সর্বোচ্চ ২টি): ${responseData.task_brief.ambiguity.questions.join(' | ')}`
            : '',
        responseData.task_brief?.success_criteria_suggestions?.length
            ? `প্রস্তাবিত success criteria (ইচ্ছামতো সম্পাদনা করুন): ${responseData.task_brief.success_criteria_suggestions.join(' | ')}`
            : '',
        responseData.matched_skills?.length
            ? `Matched skills: ${responseData.matched_skills.map((skill) => `${skill.name} — ${skill.description}`).join(' | ')}`
            : 'Matched skills: none',
        responseData.task_brief?.risk?.never_auto_indicators?.length
            ? `সতর্কতা: সম্ভাব্য Never-Auto কাজ শনাক্ত (${responseData.task_brief.risk.never_auto_indicators.join(', ')}); এই বিশ্লেষণ নিজে কোনো অনুমতি দেয় না।`
            : '',
        `পথ: ${routing.strategy === 'multi_agent' ? 'একাধিক agent' : 'একটি agent'} (${routing.agent_sequence.join(' → ')})`,
        responseData.knowledge_preview?.sources?.length
            ? `Selected file context: ${responseData.knowledge_preview.sources.map((source) => `${source.name} (chunk ${source.chunk})`).join(', ')}`
            : '',
        responseData.memory_preview?.memories?.length
            ? `Selected memory context: ${responseData.memory_preview.memories.map((memory) => memory.label || `Memory #${memory.id}`).join(', ')}`
            : '',
        `কেন: ${routing.reason}`,
        `সর্বোচ্চ token: ${costEstimate.estimated_tokens ?? 'হিসাব করা যায়নি'} / ${costEstimate.maximum_tokens}`,
        costEstimate.can_complete === false
            ? 'এই prompt-এর জন্য token সীমায় উত্তর দেওয়ার জায়গা নেই; prompt ছোট করে আবার অনুমান করুন।'
            : '',
        costLine,
        budgetLine,
        ...comparisonLine,
        ...selectedModelReasons,
        `Routing evaluation: ${routingComparison.evaluation?.reason || 'উপলব্ধ নয়'}`,
        'হিসাবটি configured fallback-সহ সর্বোচ্চ token budget ও আনুমানিক input token-এর উপর ভিত্তি করে; failed request-এর খরচসহ provider-এর প্রকৃত bill ভিন্ন হতে পারে।',
        'এখন আবার বোতাম চাপলে কাজ শুরু হবে।',
    ].filter(Boolean);
    elements.taskEstimate.textContent = estimateLines.join('\n');
    elements.applyTaskCriteria.hidden = costEstimate.can_complete === false
        || !responseData.task_brief?.success_criteria_suggestions?.length;
    elements.taskEstimate.hidden = false;
}

elements.applyTaskCriteria.addEventListener('click', () => {
    const suggestions = pendingTaskConfirmation?.task_brief?.success_criteria_suggestions || [];
    if (suggestions.length === 0) {
        return;
    }

    const criteriaField = elements.taskForm.querySelector('#task-success-criteria');
    const currentCriteria = criteriaField.value.split(/\r?\n/).map((value) => value.trim()).filter(Boolean);
    criteriaField.value = [...new Set([...currentCriteria, ...suggestions])].join('\n');
    pendingTaskConfirmation = null;
    elements.applyTaskCriteria.hidden = true;
    elements.taskForm.querySelector('button[type="submit"]').innerHTML = 'অনুমান দেখুন <span aria-hidden="true">→</span>';
    criteriaField.focus();
});

elements.taskForm.addEventListener('input', () => {
    elements.applyTaskCriteria.hidden = true;
});

elements.taskForm.addEventListener('change', () => {
    elements.applyTaskCriteria.hidden = true;
});

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
    const resumeButton = event.target.closest('[data-resume-agent-run-id]');
    const shareButton = event.target.closest('[data-share-task-replay-id]');
    const revokeShareButton = event.target.closest('[data-revoke-replay-share-id]');
    const copyShareButton = event.target.closest('[data-copy-replay-share-url]');
    const transitionButton = event.target.closest('[data-live-transition-task-id]');
    const killTaskButton = event.target.closest('[data-kill-task-id]');
    const killAgentButton = event.target.closest('[data-kill-agent-run-id]');

    try {
        if (killTaskButton || killAgentButton) {
            const target = killTaskButton || killAgentButton;
            const confirmed = window.confirm('এটি task execution বন্ধ করবে। থামানো কাজ স্বয়ংক্রিয়ভাবে আবার চলবে না।');
            if (!confirmed) {
                return;
            }

            target.disabled = true;
            const taskId = killTaskButton ? killTaskButton.dataset.killTaskId : target.dataset.taskId;
            const endpoint = killAgentButton
                ? `/tasks/${encodeURIComponent(taskId)}/agent-runs/${encodeURIComponent(target.dataset.killAgentRunId)}/kill-switch`
                : `/tasks/${encodeURIComponent(taskId)}/kill-switch`;
            await apiRequest(endpoint, { method: 'POST', body: JSON.stringify({}) });
            await loadTasks();
            await loadTask(taskId);
            showNotice(elements.success, 'Execution থামানো হয়েছে।');
            return;
        }

        if (replayButton) {
            await loadSandboxReplay(replayButton.dataset.replayTaskId);
        }

        if (resumeButton) {
            const shouldResume = window.confirm(
                'এই ধাপটি বর্তমান approved model ও trust level দিয়ে নতুন Sandbox task হিসেবে আবার চালাবেন? কোনো Live action পুনরায় হবে না.',
            );
            if (!shouldResume) {
                return;
            }

            resumeButton.disabled = true;
            const responseData = await apiRequest(
                `/tasks/${encodeURIComponent(selectedTaskId)}/replay/${encodeURIComponent(resumeButton.dataset.resumeAgentRunId)}/resume`,
                { method: 'POST', body: JSON.stringify({}) },
            );
            selectedTaskId = responseData.task.id;
            await loadTasks();
            showNotice(elements.success, 'ধাপটি নতুন Sandbox task হিসেবে queue হয়েছে।');
        }

        if (shareButton) {
            shareButton.disabled = true;
            const responseData = await apiRequest(
                `/tasks/${encodeURIComponent(shareButton.dataset.shareTaskReplayId)}/replay-shares`,
                {
                    method: 'POST',
                    body: JSON.stringify({ expires_in_hours: 24 }),
                },
            );
            let sharePanel = elements.taskDetail.querySelector('[data-replay-share-panel]');
            if (!sharePanel) {
                sharePanel = document.createElement('section');
                sharePanel.className = 'panel replay-share-panel';
                sharePanel.dataset.replaySharePanel = 'true';
                sharePanel.innerHTML = '<strong>সক্রিয় replay link</strong><div data-replay-share-list></div>';
                elements.taskDetail.append(sharePanel);
            }
            const shareEntry = document.createElement('div');
            shareEntry.className = 'replay-share-entry';
            shareEntry.dataset.replayShareEntry = responseData.share.id;
            const shareLink = document.createElement('a');
            shareLink.href = responseData.share.url;
            shareLink.textContent = responseData.share.url;
            shareLink.target = '_blank';
            shareLink.rel = 'noopener noreferrer';
            const expiresAt = document.createElement('p');
            expiresAt.textContent = `মেয়াদ শেষ: ${formatDate(responseData.share.expires_at)}`;
            const shareScope = document.createElement('p');
            shareScope.textContent = 'এই link-এ শুধু sanitized event timeline দেখা যাবে; prompt ও message content থাকবে না।';
            const copyButton = document.createElement('button');
            copyButton.className = 'button button-quiet button-small';
            copyButton.type = 'button';
            copyButton.textContent = 'লিংক কপি';
            copyButton.dataset.copyReplayShareUrl = responseData.share.url;
            const revokeButton = document.createElement('button');
            revokeButton.className = 'button button-quiet button-small';
            revokeButton.type = 'button';
            revokeButton.textContent = 'লিংক বাতিল';
            revokeButton.dataset.revokeReplayShareId = responseData.share.id;
            revokeButton.dataset.taskId = shareButton.dataset.shareTaskReplayId;
            shareEntry.append(shareLink, expiresAt, shareScope, copyButton, revokeButton);
            sharePanel.querySelector('[data-replay-share-list]').append(shareEntry);
        }

        if (revokeShareButton) {
            await apiRequest(
                `/tasks/${encodeURIComponent(revokeShareButton.dataset.taskId)}/replay-shares/${encodeURIComponent(revokeShareButton.dataset.revokeReplayShareId)}`,
                { method: 'DELETE' },
            );
            revokeShareButton.closest('[data-replay-share-entry]')?.remove();
            if (!elements.taskDetail.querySelector('[data-replay-share-list]')?.children.length) {
                elements.taskDetail.querySelector('[data-replay-share-panel]')?.remove();
            }
            showNotice(elements.success, 'Replay link বাতিল হয়েছে।');
        }

        if (copyShareButton) {
            if (!navigator.clipboard?.writeText) {
                throw new Error('এই browser-এ clipboard access পাওয়া যায়নি। লিংকটি নির্বাচন করে কপি করুন।');
            }
            await navigator.clipboard.writeText(copyShareButton.dataset.copyReplayShareUrl);
            showNotice(elements.success, 'Replay link কপি হয়েছে।');
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

function refreshBatchApprovalButton() {
    const selected = Array.from(elements.approvalList.querySelectorAll('[data-approval-select]:checked'));
    const sameAction = new Set(selected.map((checkbox) => checkbox.dataset.approvalAction)).size <= 1;
    elements.batchApprovalButton.disabled = selected.length < 2 || !sameAction;
}

elements.approvalList.addEventListener('change', (event) => {
    if (event.target.matches('[data-approval-select]')) {
        refreshBatchApprovalButton();
    }
});

elements.batchApprovalButton.addEventListener('click', async () => {
    const selected = Array.from(elements.approvalList.querySelectorAll('[data-approval-select]:checked'));
    if (selected.length < 2 || new Set(selected.map((checkbox) => checkbox.dataset.approvalAction)).size !== 1) {
        showNotice(elements.error, 'Batch সিদ্ধান্তে অন্তত দুইটি একই ধরনের approval বেছে নিন।');
        return;
    }

    elements.batchApprovalButton.disabled = true;
    try {
        await apiRequest('/approvals/batch', {
            method: 'POST',
            body: JSON.stringify({
                approval_ids: selected.map((checkbox) => Number(checkbox.value)),
                decision: 'approve',
            }),
        });
        await Promise.all([loadApprovals(), loadTasks()]);
        showNotice(elements.success, `${selected.length}টি একই ধরনের action অনুমোদিত হয়েছে।`);
    } catch (error) {
        showNotice(elements.error, error.message);
    } finally {
        refreshBatchApprovalButton();
    }
});

elements.approvalList.addEventListener('click', async (event) => {
    const cancelDeferred = event.target.closest('[data-cancel-deferred]');
    if (cancelDeferred) {
        cancelDeferred.disabled = true;
        try {
            await apiRequest(`/access/deferred-actions/${encodeURIComponent(cancelDeferred.dataset.cancelDeferred)}`, {
                method: 'DELETE',
            });
            await Promise.all([loadApprovals(), loadTasks()]);
            showNotice(elements.success, 'Undo window-এর মধ্যে কাজটি বাতিল হয়েছে।');
        } catch (error) {
            showNotice(elements.error, error.message);
        }
        return;
    }

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

elements.accessSettingsForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const agentModes = {};
    elements.agentAccessModes.querySelectorAll('select').forEach((select) => {
        const [agentName] = select.name.split('.');
        if (select.value !== 'inherit') {
            agentModes[agentName] = select.value;
        }
    });

    try {
        await apiRequest('/access', {
            method: 'PUT',
            body: JSON.stringify({
                mode: elements.accessMode.value,
                agent_modes: agentModes,
                notification_preferences: {
                    tool_approval: {
                        channels: Array.from(document.querySelectorAll('[data-notification-channel]:checked'))
                            .map((checkbox) => checkbox.dataset.notificationChannel),
                    },
                },
                quiet_hours: {
                    start: document.querySelector('#quiet-hours-start').value || null,
                    end: document.querySelector('#quiet-hours-end').value || null,
                },
                digest_time: document.querySelector('#notification-digest-time').value || null,
            }),
        });
        await loadAccess();
        showNotice(elements.success, 'Access settings সংরক্ষিত হয়েছে।');
    } catch (error) {
        showNotice(elements.error, error.message);
    }
});

elements.accessPolicyForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const formData = Object.fromEntries(new FormData(elements.accessPolicyForm));
    let inputConstraints = null;
    if (formData.input_constraints.trim() !== '') {
        try {
            inputConstraints = JSON.parse(formData.input_constraints);
            if (!inputConstraints || Array.isArray(inputConstraints) || typeof inputConstraints !== 'object') {
                throw new Error('JSON must be an object.');
            }
        } catch (error) {
            showNotice(elements.error, `Input constraints JSON ভুল: ${error.message}`);
            return;
        }
    }
    const policy = {
        ...formData,
        input_constraints: inputConstraints,
        task_id: formData.task_id ? Number(formData.task_id) : null,
        maximum_uses: Number(formData.maximum_uses),
        expires_in_minutes: Number(formData.expires_in_minutes),
        undo_window_seconds: Number(formData.undo_window_seconds),
    };

    try {
        await apiRequest('/access/policies', {
            method: 'POST',
            body: JSON.stringify(policy),
        });
        elements.accessPolicyForm.reset();
        await loadAccess();
        showNotice(elements.success, 'Auto policy তৈরি হয়েছে।');
    } catch (error) {
        showNotice(elements.error, error.message);
    }
});

elements.accessPolicyList.addEventListener('click', async (event) => {
    const revokeButton = event.target.closest('[data-revoke-policy]');
    if (!revokeButton) {
        return;
    }

    revokeButton.disabled = true;
    try {
        await apiRequest(`/access/policies/${encodeURIComponent(revokeButton.dataset.revokePolicy)}`, {
            method: 'DELETE',
        });
        await loadAccess();
        showNotice(elements.success, 'Auto policy revoke করা হয়েছে।');
    } catch (error) {
        showNotice(elements.error, error.message);
    } finally {
        revokeButton.disabled = false;
    }
});

document.querySelectorAll('[data-channel-form]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const channel = form.dataset.channelForm;
        const values = Object.fromEntries(new FormData(form));
        values.enabled = form.querySelector('[name="enabled"]').checked;
        if (channel === 'email') {
            values.port = Number(values.port);
        }
        Object.keys(values).forEach((key) => {
            if (values[key] === '' || values[key] === null) {
                delete values[key];
            }
        });

        try {
            await apiRequest(`/notifications/channels/${channel}`, {
                method: 'PUT',
                body: JSON.stringify(values),
            });
            await loadAccess();
            showNotice(elements.success, `${channel} channel settings সংরক্ষিত হয়েছে।`);
        } catch (error) {
            showNotice(elements.error, error.message);
        }
    });
});

document.querySelector('#generate-push-vapid').addEventListener('click', async () => {
    const subject = document.querySelector('#push-vapid-subject').value.trim();
    try {
        const response = await apiRequest('/notifications/push/vapid', {
            method: 'POST',
            body: JSON.stringify({ subject }),
        });
        elements.notificationChannelStatus.dataset.pushPublicKey = response.public_key;
        showNotice(elements.success, 'VAPID key তৈরি হয়েছে। এখন browser subscription চালু করুন।');
        await loadAccess();
    } catch (error) {
        showNotice(elements.error, error.message);
    }
});

function decodeBase64Url(value) {
    const padding = '='.repeat((4 - (value.length % 4)) % 4);
    const normalized = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
    return Uint8Array.from(atob(normalized), (character) => character.charCodeAt(0));
}

document.querySelector('#subscribe-push').addEventListener('click', async () => {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        showNotice(elements.error, 'এই browser-এ Web Push সমর্থিত নয়।');
        return;
    }

    try {
        const publicKey = elements.notificationChannelStatus.dataset.pushPublicKey;
        if (!publicKey) {
            throw new Error('আগে VAPID key তৈরি করুন।');
        }
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
            throw new Error('Browser notification permission দেওয়া হয়নি।');
        }
        const registration = await navigator.serviceWorker.register('/sw.js');
        let subscription = await registration.pushManager.getSubscription();
        if (!subscription) {
            subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: decodeBase64Url(publicKey),
            });
        }
        await apiRequest('/notifications/push/subscriptions', {
            method: 'POST',
            body: JSON.stringify(subscription.toJSON()),
        });
        await loadAccess();
        showNotice(elements.success, 'এই browser-এ Push subscription যোগ হয়েছে।');
    } catch (error) {
        showNotice(elements.error, error.message);
    }
});

document.querySelector('#enable-push-channel').addEventListener('change', async (event) => {
    try {
        await apiRequest('/notifications/channels/push', {
            method: 'PUT',
            body: JSON.stringify({ enabled: event.target.checked }),
        });
        await loadAccess();
    } catch (error) {
        event.target.checked = false;
        showNotice(elements.error, error.message);
    }
});

elements.notificationList.addEventListener('click', async (event) => {
    if (event.target.closest('[data-open-approval]')) {
        switchView('approvals');
        return;
    }

    const readButton = event.target.closest('[data-read-notification]');
    if (!readButton) {
        return;
    }

    readButton.disabled = true;
    try {
        await apiRequest(`/notifications/${encodeURIComponent(readButton.dataset.readNotification)}/read`, {
            method: 'POST',
            body: JSON.stringify({}),
        });
        await loadNotifications();
    } catch (error) {
        showNotice(elements.error, error.message);
    } finally {
        readButton.disabled = false;
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
