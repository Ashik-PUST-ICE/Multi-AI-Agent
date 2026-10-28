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
};

let apiToken = sessionStorage.getItem(TOKEN_STORAGE_KEY) || '';
let selectedTaskId = null;
let refreshTimer = null;
let pendingTaskConfirmation = null;

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
    element.textContent = message;
    element.hidden = false;
}

function clearNotices() {
    elements.error.hidden = true;
    elements.success.hidden = true;
}

function setConnected(isConnected) {
    elements.connectionIndicator.classList.toggle('is-connected', isConnected);
    elements.connectionLabel.textContent = isConnected ? 'API সংযুক্ত' : 'API সংযুক্ত নয়';
    elements.disconnectButton.hidden = !isConnected;
    elements.tokenInput.hidden = isConnected;
    elements.connectionForm.querySelector('button[type="submit"]').hidden = isConnected;
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

function switchView(viewName) {
    const isApprovals = viewName === 'approvals';

    elements.views.forEach((view) => {
        view.classList.toggle('is-visible', view.id === `${viewName}-view`);
    });
    elements.navigationButtons.forEach((button) => {
        button.classList.toggle('is-active', button.dataset.view === viewName);
    });
    elements.pageTitle.textContent = isApprovals ? 'অনুমোদন' : 'আমার কাজ';

    if (isApprovals && apiToken) {
        loadApprovals();
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

elements.connectionForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearNotices();

    const suppliedToken = elements.tokenInput.value.trim();
    if (!suppliedToken) {
        showNotice(elements.error, 'Sanctum API token দিন।');
        return;
    }

    apiToken = suppliedToken;
    try {
        await apiRequest('/tasks');
        sessionStorage.setItem(TOKEN_STORAGE_KEY, apiToken);
        elements.tokenInput.value = '';
        setConnected(true);
        await loadTasks();
        await loadApprovals();
        showNotice(elements.success, 'API সংযোগ সফল হয়েছে।');
    } catch (error) {
        apiToken = '';
        sessionStorage.removeItem(TOKEN_STORAGE_KEY);
        setConnected(false);
        showNotice(elements.error, error.message);
    }
});

elements.disconnectButton.addEventListener('click', () => {
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
    const costLine = costEstimate.status === 'available'
        ? `সর্বোচ্চ আনুমানিক খরচ: ${costEstimate.maximum_cost} ${costEstimate.currency}`
        : `টাকার হিসাব নেই: ${costEstimate.reason}`;

    elements.taskEstimate.textContent = [
        `পথ: ${routing.strategy === 'multi_agent' ? 'একাধিক agent' : 'একটি agent'} (${routing.agent_sequence.join(' → ')})`,
        `কেন: ${routing.reason}`,
        `সর্বোচ্চ token: ${costEstimate.estimated_tokens ?? 'হিসাব করা যায়নি'} / ${costEstimate.maximum_tokens}`,
        costEstimate.can_complete === false
            ? 'এই prompt-এর জন্য token সীমায় উত্তর দেওয়ার জায়গা নেই; prompt ছোট করে আবার অনুমান করুন।'
            : '',
        costLine,
        'হিসাবটি সর্বোচ্চ token budget ও আনুমানিক input token-এর উপর ভিত্তি করে; provider-এর প্রকৃত usage ভিন্ন হতে পারে।',
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

setConnected(Boolean(apiToken));
if (apiToken) {
    Promise.all([loadTasks(), loadApprovals()])
        .catch((error) => {
            apiToken = '';
            sessionStorage.removeItem(TOKEN_STORAGE_KEY);
            setConnected(false);
            showNotice(elements.error, error.message);
        });
}
