<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#111827">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/icons/app-icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/icons/app-icon.svg">
    <title>{{ config('app.name', 'Multi-AI-Agent') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div class="application-shell" id="application">
        <section class="auth-panel" id="auth-panel" aria-labelledby="auth-heading">
            <a class="brand auth-brand" href="/" aria-label="Multi-AI-Agent হোম">
                <span class="brand-mark">M</span>
                <span>
                    <strong>Multi-AI-Agent</strong>
                    <small>আপনার AI workspace</small>
                </span>
            </a>
            <div class="panel auth-card">
                <p class="eyebrow">SECURE WORKSPACE</p>
                <h1 id="auth-heading">আপনার অ্যাকাউন্টে প্রবেশ করুন</h1>
                <p class="auth-description" id="auth-description">নিজের AI workspace-এ নিরাপদে প্রবেশ করুন।</p>
                <div class="notice notice-error" id="auth-error" role="alert" hidden></div>
                <form id="auth-form">
                    <div class="auth-field" id="auth-name-field" hidden>
                        <label for="auth-name">আপনার নাম</label>
                        <input id="auth-name" name="name" type="text" maxlength="255" autocomplete="name">
                    </div>
                    <div class="auth-field">
                        <label for="auth-email">ইমেইল</label>
                        <input id="auth-email" name="email" type="email" maxlength="255" autocomplete="email" required>
                    </div>
                    <div class="auth-field">
                        <label for="auth-password">পাসওয়ার্ড</label>
                        <input id="auth-password" name="password" type="password" autocomplete="current-password" required>
                    </div>
                    <div class="auth-field" id="auth-password-confirmation" hidden>
                        <label for="auth-password-confirmation-input">পাসওয়ার্ড আবার লিখুন</label>
                        <input id="auth-password-confirmation-input" name="password_confirmation" type="password" minlength="12" autocomplete="new-password">
                    </div>
                    <button class="button button-primary auth-submit" id="auth-submit" type="submit">প্রবেশ করুন</button>
                </form>
                <button class="auth-mode-toggle" id="auth-mode-toggle" type="button">নতুন account তৈরি করুন</button>
                <details class="token-connect">
                    <summary>আগে থেকে থাকা API token দিয়ে সংযুক্ত হন</summary>
                    <form class="connection-form" id="connection-form">
                        <label class="visually-hidden" for="api-token">Sanctum API token</label>
                        <input id="api-token" name="api-token" type="password" autocomplete="off" placeholder="API token দিন" required>
                        <button class="button button-dark button-small" type="submit">সংযুক্ত করুন</button>
                    </form>
                </details>
            </div>
        </section>

        <aside class="sidebar" aria-label="প্রধান নেভিগেশন">
            <a class="brand" href="/" aria-label="Multi-AI-Agent হোম">
                <span class="brand-mark">M</span>
                <span>
                    <strong>Multi-AI-Agent</strong>
                    <small>আপনার AI workspace</small>
                </span>
            </a>

            <nav class="primary-navigation">
                <button class="navigation-link is-active" data-view="tasks" type="button">
                    <span aria-hidden="true">▦</span>
                    <span>আমার কাজ</span>
                </button>
                <button class="navigation-link" data-view="approvals" type="button">
                    <span aria-hidden="true">◷</span>
                    <span>অনুমোদন</span>
                    <span class="navigation-count" id="approval-count" hidden>0</span>
                </button>
                <button class="navigation-link" data-view="agents" type="button">
                    <span aria-hidden="true">♙</span>
                    <span>এজেন্ট</span>
                </button>
                <button class="navigation-link" data-view="brain" type="button">
                    <span aria-hidden="true">✦</span>
                    <span>Brain</span>
                </button>
                <button class="navigation-link" data-view="skills" type="button">
                    <span aria-hidden="true">◇</span>
                    <span>Skills</span>
                </button>
                <button class="navigation-link" data-view="access" type="button">
                    <span aria-hidden="true">⚿</span>
                    <span>Access</span>
                </button>
                <button class="navigation-link" data-view="notifications" type="button">
                    <span aria-hidden="true">♧</span>
                    <span>Notifications</span>
                </button>
                <button class="navigation-link" data-view="knowledge" type="button">
                    <span aria-hidden="true">▤</span>
                    <span>ফাইল ও Memory</span>
                </button>
                <button class="navigation-link" data-view="admin" id="platform-admin-nav" type="button" hidden>
                    <span aria-hidden="true">▥</span>
                    <span>Admin</span>
                </button>
            </nav>

            <div class="sidebar-footer">
                <span class="connection-indicator" id="connection-indicator"></span>
                <span id="connection-label">API সংযুক্ত নয়</span>
            </div>
        </aside>

        <div class="application-main">
            <header class="topbar">
                <div>
                    <p class="eyebrow">MILESTONE 1 · SANDBOX</p>
                    <h1 id="page-title">আমার কাজ</h1>
                </div>
                <div class="account-controls" id="account-controls" hidden>
                    <span id="account-name"></span>
                    <button class="button button-quiet button-small" id="disconnect-button" type="button">লগ আউট</button>
                </div>
            </header>

            <div class="notice notice-error" id="global-error" role="alert" hidden></div>
            <div class="notice notice-success" id="global-success" role="status" hidden></div>

            <main>
                <section class="view-panel is-visible" id="tasks-view" aria-labelledby="tasks-heading">
                    <div class="section-heading">
                        <div>
                            <p class="eyebrow">কাজের জায়গা</p>
                            <h2 id="tasks-heading">নতুন কাজ শুরু করুন</h2>
                            <p class="section-description">আপনার কাজ sandbox-এ উপযুক্ত একটি বা একাধিক agent দিয়ে সম্পন্ন হবে।</p>
                        </div>
                        <span class="mode-badge"><span></span> Sandbox mode (default)</span>
                    </div>

                    <form class="task-form panel" id="task-form">
                        <label for="task-title">কাজের শিরোনাম</label>
                        <input id="task-title" name="title" type="text" maxlength="255" placeholder="যেমন: একটি বিষয় নিয়ে গবেষণা" required>
                        <label for="task-prompt">কী করতে হবে?</label>
                        <textarea id="task-prompt" name="prompt" rows="5" maxlength="20000" placeholder="কাজটি বিস্তারিত লিখুন..." required></textarea>
                        <div class="assistant-input-actions">
                            <button class="button button-quiet button-small" id="voice-input-button" type="button" disabled>কণ্ঠে লিখুন</button>
                            <span id="voice-input-status" class="form-help" role="status">Voice input সেটিংসে বন্ধ। Voice কখনো authentication নয়।</span>
                        </div>
                        <div class="vision-capture">
                            <div class="assistant-input-actions">
                                <button class="button button-quiet button-small" id="camera-start-button" type="button" disabled>ক্যামেরা চালু করুন</button>
                                <button class="button button-quiet button-small" id="camera-capture-button" type="button" disabled hidden>ছবি তুলুন</button>
                                <button class="button button-quiet button-small" id="camera-stop-button" type="button" hidden>ক্যামেরা বন্ধ</button>
                                <span id="camera-status" class="form-help" role="status">Camera vision সেটিংসে বন্ধ।</span>
                            </div>
                            <video id="camera-preview" playsinline muted hidden></video>
                            <img id="camera-captured-image" alt="Task-এর জন্য তোলা private camera image" hidden>
                            <label id="vision-confirmation-label" class="vision-confirmation" hidden>
                                <input id="vision-confirmation" type="checkbox">
                                এই ছবিটি নির্বাচিত approved vision model-এ পাঠাতে সম্মতি দিচ্ছি। এটি private task attachment হিসেবে সর্বোচ্চ ৩০ দিন থাকবে।
                            </label>
                        </div>
                        <label for="task-success-criteria">সাফল্যের শর্ত (প্রতি লাইনে একটি, ঐচ্ছিক)</label>
                        <textarea id="task-success-criteria" name="success_criteria" rows="3" maxlength="5000" placeholder="যেমন: অন্তত ৩টি নির্ভরযোগ্য উৎস ব্যবহার করবে&#10;উত্তরটি বাংলায় দেবে"></textarea>
                        <button class="button button-quiet button-small" id="apply-task-criteria" type="button" hidden>প্রস্তাবিত criteria সম্পাদনার জন্য যোগ করুন</button>
                        <label for="task-agent-pack">Agent pack (ঐচ্ছিক)</label>
                        <select id="task-agent-pack" name="agent_pack">
                            <option value="">কোনো pack নয়</option>
                        </select>
                        <fieldset class="task-knowledge-fieldset">
                            <legend>এই কাজের জন্য ফাইল (ঐচ্ছিক)</legend>
                            <div id="task-knowledge-documents"><small>ফাইল যোগ করলে এখানে বেছে নিতে পারবেন।</small></div>
                        </fieldset>
                        <fieldset class="task-knowledge-fieldset">
                            <legend>এই কাজের জন্য ব্যক্তিগত memory (ঐচ্ছিক)</legend>
                            <div id="task-memory-choices"><small>Memory-তে নির্বাচিত active ব্যক্তিগত memory এখানে বেছে নিতে পারবেন।</small></div>
                        </fieldset>
                        <label for="task-max-cost">এই কাজের খরচের সীমা (ঐচ্ছিক)</label>
                        <input id="task-max-cost" name="max_cost" type="number" min="0.000001" max="1000000" step="any" inputmode="decimal" placeholder="যেমন: 0.50">
                        <small class="form-help">মুদ্রা Settings-এর cost budget currency অনুযায়ী হবে। Account limit থাকলে কাজের সীমা সেটি অতিক্রম করতে পারবে না।</small>
                        <div class="form-footer">
                            <span>কোনো live action চালানো হবে না।</span>
                            <button class="button button-primary" type="submit">কাজ জমা দিন <span aria-hidden="true">→</span></button>
                        </div>
                        <div class="task-estimate" id="task-estimate" role="status" hidden></div>
                    </form>

                    <section class="panel task-schedules-panel" id="proactive-briefing" aria-labelledby="proactive-briefing-heading" hidden>
                        <div class="list-heading">
                            <div>
                                <h2 id="proactive-briefing-heading">আজকের সংক্ষিপ্ত বিবরণ</h2>
                                <p id="proactive-briefing-status">শুধু আপনার অসমাপ্ত কাজের তথ্য দেখানো হবে।</p>
                            </div>
                            <button class="button button-quiet button-small" id="load-proactive-briefing" type="button">আজকের প্রস্তাব দেখুন</button>
                        </div>
                        <div class="task-list" id="proactive-briefing-list" aria-live="polite"></div>
                    </section>

                    <section class="panel task-schedules-panel" aria-labelledby="task-schedules-heading">
                        <div class="list-heading">
                            <div>
                                <h2 id="task-schedules-heading">নির্ধারিত কাজ</h2>
                                <p>সর্বোচ্চ ৯০ দিনের জন্য একবার, দৈনিক বা সাপ্তাহিক কাজ schedule করুন। প্রতিটি run Sandbox-এ শুরু হবে।</p>
                            </div>
                        </div>
                        <form class="task-form" id="task-schedule-form">
                            <label for="schedule-title">শিরোনাম</label>
                            <input id="schedule-title" name="title" type="text" maxlength="255" required>
                            <label for="schedule-prompt">কাজের নির্দেশনা</label>
                            <textarea id="schedule-prompt" name="prompt" rows="3" maxlength="20000" required></textarea>
                            <div class="schedule-fields">
                                <label for="schedule-cadence">পুনরাবৃত্তি
                                    <select id="schedule-cadence" name="cadence">
                                        <option value="once">একবার</option>
                                        <option value="daily">প্রতিদিন</option>
                                        <option value="weekly">প্রতি সপ্তাহে</option>
                                    </select>
                                </label>
                                <label for="schedule-starts-at">শুরুর সময়
                                    <input id="schedule-starts-at" name="starts_at" type="datetime-local" required>
                                </label>
                                <label for="schedule-expires-at" id="schedule-expiry-field" hidden>পুনরাবৃত্তির শেষ সময়
                                    <input id="schedule-expires-at" name="expires_at" type="datetime-local">
                                </label>
                            </div>
                            <input id="schedule-timezone" name="timezone" type="hidden">
                            <small class="form-help">সময় আপনার browser timezone অনুযায়ী। পুনরাবৃত্ত schedule-এর শেষ সময় বাধ্যতামূলক। প্রতিটি তৈরি task স্বাভাবিক approval ও trust policy মেনে চলে।</small>
                            <button class="button button-primary" type="submit">Schedule সংরক্ষণ</button>
                            <div id="task-schedule-estimate" class="task-estimate" role="status" hidden></div>
                        </form>
                        <div id="task-schedule-list" class="task-schedule-list" aria-live="polite">
                            <div class="empty-state">Schedule দেখতে API token দিয়ে সংযুক্ত করুন।</div>
                        </div>
                    </section>

                    <div class="list-heading">
                        <div>
                            <h2>সাম্প্রতিক কাজ</h2>
                            <p>চলমান কাজের অবস্থা স্বয়ংক্রিয়ভাবে আপডেট হবে।</p>
                        </div>
                        <button class="button button-quiet button-small" id="refresh-tasks" type="button">রিফ্রেশ</button>
                    </div>

                    <section class="observability-panel panel" aria-labelledby="observability-heading">
                        <div>
                            <h2 id="observability-heading">রান পর্যবেক্ষণ</h2>
                            <p>গত ৩০ দিনের ব্যর্থতার ধরন ও সীমিত সতর্কতা।</p>
                        </div>
                        <div class="observability-metrics" aria-live="polite">
                            <span>নীরব ব্যর্থতা <strong id="silent-failure-count">—</strong></span>
                            <span>একই ধাপে আটকে যাওয়া <strong id="stagnation-count">—</strong></span>
                            <span>Reviewer signal <strong id="skeptic-signal">—</strong></span>
                        </div>
                        <ul id="failure-categories" class="failure-category-list">
                            <li>পর্যবেক্ষণ লোড হচ্ছে…</li>
                        </ul>
                    </section>

                    <div class="task-layout">
                        <div class="task-list panel" id="task-list" aria-live="polite">
                            <div class="empty-state">কাজ দেখতে API token দিয়ে সংযুক্ত করুন।</div>
                        </div>
                        <section class="task-detail panel" id="task-detail" aria-live="polite">
                            <div class="empty-state">
                                <span class="empty-icon" aria-hidden="true">✦</span>
                                <strong>একটি কাজ নির্বাচন করুন</strong>
                                <span>কাজের ধাপ ও agent-এর ফলাফল এখানে দেখা যাবে।</span>
                            </div>
                        </section>
                    </div>
                </section>

                <section class="view-panel" id="approvals-view" aria-labelledby="approvals-heading">
                    <div class="section-heading">
                        <div>
                            <p class="eyebrow">আপনার নিয়ন্ত্রণ</p>
                            <h2 id="approvals-heading">অনুমোদনের অপেক্ষায়</h2>
                            <p class="section-description">প্রতিটি সংবেদনশীল কাজ আলাদাভাবে পর্যালোচনা করে অনুমোদন দিন।</p>
                        </div>
                    </div>
                    <button class="button button-approve button-small" id="batch-approval-button" type="button" disabled>একই ধরনের নির্বাচিত কাজ অনুমোদন</button>
                    <div class="approval-list" id="approval-list" aria-live="polite">
                        <div class="panel empty-state">অনুমোদন দেখতে API token দিয়ে সংযুক্ত করুন।</div>
                    </div>
                </section>

                <section class="view-panel" id="agents-view" aria-labelledby="agents-heading">
                    <div class="section-heading">
                        <div>
                            <p class="eyebrow">AGENT WORKSPACE</p>
                            <h2 id="agents-heading">আপনার এজেন্ট</h2>
                            <p class="section-description">প্রতিটি এজেন্টের Brain mode ও নির্বাচিত model পর্যালোচনা করুন।</p>
                        </div>
                    </div>
                    <div class="agent-directory" id="agent-directory">
                        <div class="panel empty-state">এজেন্ট সেটিংস দেখতে Brain পেজ খুলুন।</div>
                    </div>
                    <section class="panel trust-section" aria-labelledby="trust-heading">
                        <div class="brain-section-heading">
                            <div>
                                <h3 id="trust-heading">এজেন্ট Trust Level</h3>
                                <p>Orchestrator, Writer ও Reviewer level 0-তে, Research read-only access-এর জন্য level 1-এ শুরু করে। কোনো agent নিজে Trust বাড়াতে পারে না।</p>
                            </div>
                        </div>
                        <div class="agent-trust-list" id="agent-trust-list" aria-live="polite">
                            <div class="empty-state">Trust settings লোড হচ্ছে।</div>
                        </div>
                    </section>
                    <section class="panel pack-section" aria-labelledby="agent-packs-heading">
                        <div class="brain-section-heading">
                            <div>
                                <h3 id="agent-packs-heading">Agent packs</h3>
                                <p>Pack কেবল domain guidance যোগ করে; agent, tool বা Trust permission বাড়ায় না।</p>
                            </div>
                        </div>
                        <div class="agent-pack-list" id="agent-pack-list" aria-live="polite">
                            <div class="empty-state">Agent pack লোড হচ্ছে।</div>
                        </div>
                    </section>
                    <section class="panel pack-section" id="agent-pack-admin" aria-labelledby="agent-pack-admin-heading" hidden>
                        <div class="brain-section-heading">
                            <div>
                                <h3 id="agent-pack-admin-heading">Pack authoring (admin)</h3>
                                <p>Custom pack draft করুন, version update করুন এবং review শেষে activate করুন।</p>
                            </div>
                        </div>
                        <form class="pack-admin-form" id="agent-pack-form">
                            <input name="name" type="text" maxlength="120" placeholder="Pack name" required>
                            <input name="slug" type="text" maxlength="64" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" placeholder="unique-pack-slug" required>
                            <textarea name="description" rows="2" maxlength="1000" placeholder="Description" required></textarea>
                            <div class="pack-agent-choices">
                                <label><input type="checkbox" name="agents" value="Orchestrator"> Orchestrator</label>
                                <label><input type="checkbox" name="agents" value="Research"> Research</label>
                                <label><input type="checkbox" name="agents" value="Writer"> Writer</label>
                                <label><input type="checkbox" name="agents" value="Reviewer"> Reviewer</label>
                            </div>
                            <textarea name="guidance" rows="3" maxlength="4000" placeholder="Shared domain guidance for selected agents" required></textarea>
                            <textarea name="examples" rows="2" maxlength="3000" placeholder="Example task, one per line" required></textarea>
                            <div class="form-footer">
                                <span id="agent-pack-form-status">Guidance cannot change safety or permissions.</span>
                                <button class="button button-primary" type="submit">Draft সংরক্ষণ</button>
                            </div>
                        </form>
                        <div class="agent-pack-admin-list" id="agent-pack-admin-list"></div>
                    </section>
                </section>

                <section class="view-panel" id="brain-view" aria-labelledby="brain-heading">
                    <div class="section-heading">
                        <div>
                            <p class="eyebrow">USER-CONTROLLED ROUTING</p>
                            <h2 id="brain-heading">Brain ও model routing</h2>
                            <p class="section-description">শুধু আপনার অনুমোদিত model বেছে নেওয়া হবে। Brain পরিবর্তন routing preference বদলায়, Trust বা task mode নয়।</p>
                        </div>
                        <span class="mode-badge"><span></span> Sandbox-first</span>
                    </div>

                    <form id="brain-settings-form">
                        <section class="panel brain-section">
                            <div class="brain-section-heading">
                                <div><h3>Agent Brain</h3><p>Auto policy অনুসরণ করে, Fixed Settings-এর model নেয়, Custom agent-এর নির্দিষ্ট model নেয়।</p></div>
                            </div>
                            <div class="agent-brain-grid" id="agent-brain-grid">
                                <div class="empty-state">Agent Brain settings লোড হচ্ছে।</div>
                            </div>
                        </section>

                        <section class="panel brain-section">
                            <div class="brain-section-heading">
                                <div><h3>Routing policy</h3><p>দাম আপনার দেওয়া rate থেকে; model quality-র দাবি trusted evaluation ছাড়া করা হয় না।</p></div>
                            </div>
                            <div class="brain-form-grid">
                                <label class="brain-field" for="max-task-cost">
                                    <span>Account-এর প্রতি কাজের সর্বোচ্চ খরচ (ঐচ্ছিক)</span>
                                    <input id="max-task-cost" name="max_task_cost" type="number" min="0.000001" max="1000000" step="any" inputmode="decimal" placeholder="খালি রাখলে সীমা নেই">
                                </label>
                                <label class="brain-field" for="budget-currency">
                                    <span>Budget currency (ISO code)</span>
                                    <input id="budget-currency" name="budget_currency" type="text" minlength="3" maxlength="3" pattern="[A-Za-z]{3}" placeholder="যেমন USD">
                                </label>
                            </div>
                            <label class="brain-field" for="routing-policy">
                                <span>Auto policy</span>
                                <select id="routing-policy" name="routing_policy">
                                    <option value="cheapest">সবচেয়ে সস্তা (user-entered rates)</option>
                                    <option value="balanced">সুষম (rate + context capacity)</option>
                                    <option value="best_quality">সেরা মান (verified evaluation থাকলে)</option>
                                </select>
                            </label>
                            <div class="brain-form-grid">
                                <label class="brain-field" for="default-provider"><span>Fixed provider</span><select id="default-provider" name="provider"></select></label>
                                <label class="brain-field" for="default-model"><span>Fixed model</span><select id="default-model" name="model"></select></label>
                                <label class="brain-field" for="fallback-provider"><span>Fallback provider</span><select id="fallback-provider" name="fallback_provider"></select></label>
                                <label class="brain-field" for="fallback-model"><span>Fallback model</span><select id="fallback-model" name="fallback_model"></select></label>
                            </div>
                            <div class="notice notice-info">Fallback সর্বোচ্চ একবার চেষ্টা হবে, কেবল প্রথম provider request agent output বা tool call ফেরানোর আগে ব্যর্থ হলে।</div>
                        </section>

                        <section class="panel brain-section">
                            <div class="brain-section-heading">
                                <div><h3>Model approval ও rate</h3><p>Capabilities catalog থেকে আসে। দাম optional এবং আপনার নিজের provider rate card অনুযায়ী দিন।</p></div>
                            </div>
                            <div class="model-catalog" id="model-catalog">
                                <div class="empty-state">Provider catalog লোড হচ্ছে।</div>
                            </div>
                        </section>

                        <section class="panel brain-section">
                            <div class="brain-section-heading">
                                <div><h3>Agent provider keys</h3><p>প্রতিটি agent-এর key আলাদা ও encrypted থাকে; account-level পুরোনো key কোনো agent-এর fallback হিসেবে ব্যবহার হয় না।</p></div>
                            </div>
                            <div class="provider-key-list" id="provider-key-list">
                                <div class="empty-state">Provider key settings লোড হচ্ছে।</div>
                            </div>
                        </section>

                        <div class="brain-save-row">
                            <p>Routing-এর প্রতিটি সিদ্ধান্ত task event log-এ কারণসহ সংরক্ষিত হয়। Evaluation ফলাফল/স্বয়ংক্রিয় পরিবর্তন এখনো উপলব্ধ নয়।</p>
                            <button class="button button-primary" type="submit">Brain settings সংরক্ষণ</button>
                        </div>
                    </form>

                    <section class="panel brain-section" aria-labelledby="assistant-settings-heading">
                        <div class="brain-section-heading">
                            <div><h3 id="assistant-settings-heading">Jarvis layer: persona, voice, vision ও smart home</h3><p>Persona শুধু tone বদলায়; safety, Trust, Access, tool permission বা authentication নয়।</p></div>
                        </div>
                        <form id="assistant-settings-form" class="task-form">
                            <label for="assistant-persona">Assistant persona</label>
                            <select id="assistant-persona" name="persona">
                                <option value="friendly">বন্ধুসুলভ</option>
                                <option value="concise">সংক্ষিপ্ত</option>
                                <option value="professional">পেশাদার</option>
                                <option value="custom">নিজস্ব style</option>
                            </select>
                            <label for="assistant-custom-persona">নিজস্ব style preference (সর্বোচ্চ ৫০০ অক্ষর)</label>
                            <textarea id="assistant-custom-persona" name="custom_persona" rows="2" maxlength="500" placeholder="উদাহরণ: বাংলায় সংক্ষিপ্ত উত্তর দিন।"></textarea>
                            <label><input id="assistant-voice-input" name="voice_input_enabled" type="checkbox"> Browser speech recognition দিয়ে prompt লিখুন</label>
                            <label><input id="assistant-voice-output" name="voice_output_enabled" type="checkbox"> ফলাফল শুনতে browser speech ব্যবহার করুন</label>
                            <label for="assistant-voice-language">Voice language
                                <select id="assistant-voice-language" name="voice_language">
                                    <option value="bn-BD">বাংলা</option>
                                    <option value="en-US">English</option>
                                </select>
                            </label>
                            <label><input id="assistant-vision-enabled" name="vision_enabled" type="checkbox"> Camera capture option চালু করুন (camera নিজে থেকে চালু হবে না)</label>
                            <label><input id="assistant-proactive-enabled" name="proactive_enabled" type="checkbox"> ঐচ্ছিক দৈনিক task briefing চালু করুন</label>
                            <label for="assistant-proactive-limit">প্রতিদিন সর্বোচ্চ প্রস্তাব
                                <input id="assistant-proactive-limit" name="proactive_max_suggestions" type="number" min="1" max="10" value="3">
                            </label>
                            <div class="notice notice-info">Voice recognition কেবল সুবিধা—কখনো authentication বা অনুমোদন নয়। Browser বা operating-system speech service audio process করতে পারে; microphone শুধু আপনার click-এর পর চালু হয়। Camera default-এ off; ছবি তুলতে user action ও upload consent লাগে।</div>
                            <div class="notice notice-info">Smart-home actions বর্তমানে বন্ধ। Locks, stoves ও gas devices সবসময় Never-Auto; কোনো device control integration চালু নেই।</div>
                            <div class="form-footer">
                                <span id="assistant-settings-status">Smart-home actions disabled.</span>
                                <button class="button button-primary" type="submit">Assistant settings সংরক্ষণ</button>
                            </div>
                        </form>
                    </section>

                    <section class="panel brain-section" aria-labelledby="web-access-heading">
                        <div class="brain-section-heading">
                            <div>
                                <h3 id="web-access-heading">Web search ও domain allowlist</h3>
                                <p>Search API key আপনার ডাটাবেসে encrypted থাকে। Page reader শুধু আপনার allowlist করা public HTTPS domain পড়তে পারে; কোনো redirect অনুসরণ করে না।</p>
                            </div>
                        </div>
                        <form id="web-access-form" class="task-form">
                            <label class="brain-field" for="web-search-provider">
                                <span>Search provider</span>
                                <select id="web-search-provider" name="search_provider">
                                    <option value="">বন্ধ</option>
                                    <option value="brave">Brave Search</option>
                                    <option value="tavily">Tavily</option>
                                </select>
                            </label>
                            <div class="brain-form-grid">
                                <label class="brain-field" for="web-search-api-key">
                                    <span>নিজের Search API key</span>
                                    <input id="web-search-api-key" name="search_api_key" type="password" maxlength="4096" autocomplete="new-password" placeholder="সেভ করা key ফাঁকা রাখুন">
                                </label>
                                <label class="brain-field" for="web-allowed-domains">
                                    <span>Page reader allowlist (প্রতি লাইনে domain)</span>
                                    <textarea id="web-allowed-domains" name="allowed_domains" rows="4" placeholder="laravel.com"></textarea>
                                </label>
                                <label class="brain-field" for="web-denied-domains">
                                    <span>অতিরিক্ত denylist (প্রতি লাইনে domain)</span>
                                    <textarea id="web-denied-domains" name="denied_domains" rows="4" placeholder="example.com"></textarea>
                                </label>
                            </div>
                            <p class="form-help" id="web-access-status">Web settings লোড হচ্ছে।</p>
                            <div class="brain-save-row">
                                <button class="button button-primary" type="submit">Web settings সংরক্ষণ</button>
                                <button class="button button-quiet" id="delete-web-search-key" type="button">Search key মুছুন</button>
                            </div>
                        </form>
                    </section>

                    <section class="panel brain-section" aria-labelledby="mcp-heading">
                        <div class="brain-section-heading">
                            <div>
                                <h3 id="mcp-heading">MCP server connections</h3>
                                <p>শুধু HTTPS, allowlisted public domain ও JSON Streamable HTTP endpoint। প্রতিটি tool আলাদা করে approve করুন; Live call-এর জন্য outbound Trust Level এবং exact call approval লাগবে। Auto কখনো MCP চালায় না।</p>
                            </div>
                        </div>
                        <form id="mcp-server-form" class="task-form">
                            <div class="brain-form-grid">
                                <label class="brain-field" for="mcp-server-name"><span>Server name</span><input id="mcp-server-name" name="name" maxlength="120" required></label>
                                <label class="brain-field" for="mcp-server-endpoint"><span>HTTPS endpoint (domain আগে allowlist করুন)</span><input id="mcp-server-endpoint" name="endpoint" type="url" maxlength="2048" placeholder="https://mcp.example.com/mcp" required></label>
                                <label class="brain-field" for="mcp-server-token"><span>Optional bearer token (encrypted at rest)</span><input id="mcp-server-token" name="access_token" type="password" maxlength="4096" autocomplete="new-password"></label>
                            </div>
                            <div class="brain-save-row">
                                <span>Server যোগ করলে tool catalog দেখা হবে, কিন্তু কোনো tool enabled হবে না।</span>
                                <button class="button button-primary" type="submit">MCP server যোগ করুন</button>
                            </div>
                        </form>
                        <div class="agent-directory" id="mcp-server-list" aria-live="polite">
                            <div class="empty-state">MCP server তালিকা লোড হচ্ছে।</div>
                        </div>
                    </section>

                    <section class="panel brain-section" aria-labelledby="api-integration-heading">
                        <div class="brain-section-heading">
                            <div>
                                <h3 id="api-integration-heading">REST API ও webhook connections</h3>
                                <p>আগে domain allowlist-এ থাকা HTTPS endpoint যোগ করুন। শুধু GET/POST scope, saved bearer token, owner enable এবং প্রতিটি exact call-এ approval চলে; Auto বন্ধ। Sandbox শুধু simulate করে।</p>
                            </div>
                        </div>
                        <form id="api-integration-form" class="task-form">
                            <div class="brain-form-grid">
                                <label class="brain-field" for="api-integration-name"><span>Connection name</span><input id="api-integration-name" name="name" maxlength="120" required></label>
                                <label class="brain-field" for="api-integration-endpoint"><span>HTTPS base endpoint</span><input id="api-integration-endpoint" name="endpoint" type="url" maxlength="2048" placeholder="https://api.example.com/v1" required></label>
                                <label class="brain-field" for="api-integration-token"><span>Optional bearer token (encrypted at rest)</span><input id="api-integration-token" name="access_token" type="password" maxlength="4096" autocomplete="new-password"></label>
                            </div>
                            <fieldset class="task-knowledge-fieldset">
                                <legend>Allowed methods</legend>
                                <label><input type="checkbox" name="allowed_methods" value="GET" checked> GET (read)</label>
                                <label><input type="checkbox" name="allowed_methods" value="POST"> POST (write; always approval)</label>
                            </fieldset>
                            <div class="brain-save-row">
                                <span>New connections start disabled. DELETE, PUT, PATCH and arbitrary headers are not supported.</span>
                                <button class="button button-primary" type="submit">REST connection যোগ করুন</button>
                            </div>
                        </form>
                        <div class="agent-directory" id="api-integration-list" aria-live="polite">
                            <div class="empty-state">REST connection তালিকা লোড হচ্ছে।</div>
                        </div>
                    </section>
                </section>

                <section class="view-panel" id="skills-view" aria-labelledby="skills-heading">
                    <div class="section-heading">
                        <div>
                            <p class="eyebrow">PROGRESSIVE TASK GUIDANCE</p>
                            <h2 id="skills-heading">Skills</h2>
                            <p class="section-description">Task অনুযায়ী সর্বোচ্চ দুটি verified skill-এর নির্দেশনা দেওয়া হয়; কোনো skill tool বা trust permission দিতে পারে না।</p>
                        </div>
                    </div>
                    <form class="task-form panel skill-form" id="skill-form">
                        <strong id="skill-form-heading">নিজস্ব skill draft তৈরি করুন</strong>
                        <label for="skill-name">নাম</label>
                        <input id="skill-name" name="name" type="text" maxlength="120" required>
                        <label for="skill-description">সংক্ষিপ্ত বর্ণনা</label>
                        <textarea id="skill-description" name="description" rows="2" maxlength="1000" required></textarea>
                        <label for="skill-instructions">SKILL.md নির্দেশনা</label>
                        <textarea id="skill-instructions" name="instructions" rows="5" minlength="20" maxlength="20000" required></textarea>
                        <div class="schedule-fields">
                            <label for="skill-visibility">দৃশ্যমানতা
                                <select id="skill-visibility" name="visibility">
                                    <option value="personal">শুধু আমার জন্য</option>
                                    <option value="tenant">আমার workspace (যাচাইয়ের পর)</option>
                                </select>
                            </label>
                            <label for="skill-source">উৎস
                                <select id="skill-source" name="source">
                                    <option value="authored">নিজে তৈরি</option>
                                    <option value="external_upload">বাইরে থেকে কপি করা</option>
                                </select>
                            </label>
                        </div>
                        <div id="external-skill-fields" hidden>
                            <label for="skill-publisher">প্রকাশকের দাবি (যাচাই করা নয়)</label>
                            <input id="skill-publisher" name="publisher_claim" type="text" maxlength="160">
                            <label for="skill-source-reference">উৎসের উল্লেখ (ফেচ বা যাচাই করা হবে না)</label>
                            <input id="skill-source-reference" name="source_reference" type="text" maxlength="500">
                            <label><input id="skill-external-confirmed" name="external_install_confirmed" type="checkbox"> আমি এই বাইরের skill text নিজে পর্যালোচনা করে জমা দিচ্ছি।</label>
                        </div>
                        <input id="skill-edit-id" type="hidden">
                        <input id="skill-expected-hash" type="hidden">
                        <div class="brain-save-row">
                            <span id="skill-form-status">Draft নিষ্ক্রিয় থাকবে যতক্ষণ না hash মিলিয়ে owner verify করেন। Script execution বন্ধ।</span>
                            <div>
                                <button class="button button-quiet button-small" id="skill-form-cancel" type="button" hidden>সম্পাদনা বাতিল</button>
                                <button class="button button-primary" id="skill-form-submit" type="submit">Draft সংরক্ষণ</button>
                            </div>
                        </div>
                    </form>
                    <div class="agent-directory" id="skill-directory" aria-live="polite">
                        <div class="empty-state">Skills তালিকা লোড হচ্ছে।</div>
                    </div>
                    <section class="panel skill-evaluation-panel" id="skill-evaluation-panel" aria-live="polite" hidden></section>
                    <p class="form-help">External content শুধু ব্যবহারকারীর স্পষ্ট জমায় আসে; remote fetch বা agent-initiated install নেই। Skill script execution সবসময় বন্ধ।</p>
                </section>

                <section class="view-panel" id="access-view" aria-labelledby="access-heading">
                    <div class="section-heading">
                        <div>
                            <p class="eyebrow">OWNER-CONTROLLED ACCESS</p>
                            <h2 id="access-heading">Access mode ও Auto policies</h2>
                            <p class="section-description">ডিফল্ট User mode। Auto policy নির্দিষ্ট agent/tool, scope, ব্যবহার-সীমা ও expiry-তে সীমাবদ্ধ। Sandbox-এ শুধু simulate চলে।</p>
                        </div>
                    </div>
                    <form class="panel task-form" id="access-settings-form">
                        <label for="access-mode">Account access mode</label>
                        <select id="access-mode" name="mode">
                            <option value="user">User — প্রতিটি live tool call-এ অনুমোদন</option>
                            <option value="auto">Auto — শুধু মিল থাকা সক্রিয় policy</option>
                        </select>
                        <div id="agent-access-modes" class="agent-trust-list"></div>
                        <fieldset class="task-knowledge-fieldset">
                            <legend>Notification channel preferences</legend>
                            <label><input data-notification-channel="in_app" type="checkbox" checked> In-app</label>
                            <label><input data-notification-channel="email" type="checkbox"> Email</label>
                            <label><input data-notification-channel="push" type="checkbox"> Push</label>
                            <label><input data-notification-channel="telegram" type="checkbox"> Telegram</label>
                            <label><input data-notification-channel="whatsapp" type="checkbox"> WhatsApp</label>
                            <div class="brain-form-grid">
                                <label for="quiet-hours-start">Quiet hours start<input id="quiet-hours-start" type="time"></label>
                                <label for="quiet-hours-end">Quiet hours end<input id="quiet-hours-end" type="time"></label>
                                <label for="notification-digest-time">Digest time<input id="notification-digest-time" type="time"></label>
                            </div>
                        </fieldset>
                        <div class="form-footer">
                            <span>Agent নিজে mode বা policy বদলাতে পারে না।</span>
                            <button class="button button-primary" type="submit">Access settings সংরক্ষণ</button>
                        </div>
                    </form>
                    <section class="panel pack-section">
                        <h3>Notification delivery channels</h3>
                        <p class="form-help">Secrets encrypted database-এ রাখা হয়; সংরক্ষণের পর কখনো plaintext দেখানো হবে না। High-risk approval link বাইরে পাঠানো হয় না।</p>
                        <form class="task-form" data-channel-form="email">
                            <h4>Email (SMTP)</h4>
                            <input name="host" type="text" placeholder="SMTP host" required>
                            <div class="brain-form-grid">
                                <input name="port" type="number" placeholder="Port" value="587" required>
                                <select name="encryption"><option value="tls">TLS</option><option value="ssl">SSL</option><option value="none">None</option></select>
                            </div>
                            <input name="username" type="text" placeholder="SMTP username">
                            <input name="password" type="password" autocomplete="new-password" placeholder="SMTP password">
                            <input name="from_address" type="email" placeholder="From email" required>
                            <input name="from_name" type="text" placeholder="From name">
                            <input name="recipient" type="email" placeholder="Recipient email" required>
                            <label><input name="enabled" type="checkbox"> Email channel চালু</label>
                            <button class="button button-quiet button-small" type="submit">Email settings সংরক্ষণ</button>
                        </form>
                        <form class="task-form" data-channel-form="telegram">
                            <h4>Telegram</h4>
                            <input name="bot_token" type="password" autocomplete="new-password" placeholder="Bot token" required>
                            <input name="chat_id" type="text" placeholder="Chat ID" required>
                            <label><input name="enabled" type="checkbox"> Telegram চালু</label>
                            <button class="button button-quiet button-small" type="submit">Telegram সংরক্ষণ</button>
                        </form>
                        <form class="task-form" data-channel-form="whatsapp">
                            <h4>WhatsApp Business</h4>
                            <input name="access_token" type="password" autocomplete="new-password" placeholder="Access token" required>
                            <input name="phone_number_id" type="text" placeholder="Phone number ID" required>
                            <input name="recipient" type="tel" placeholder="+8801XXXXXXXXX" required>
                            <label><input name="enabled" type="checkbox"> WhatsApp চালু</label>
                            <button class="button button-quiet button-small" type="submit">WhatsApp সংরক্ষণ</button>
                        </form>
                        <div class="task-form">
                            <h4>Web Push</h4>
                            <input id="push-vapid-subject" type="text" placeholder="mailto:contact@example.com বা HTTPS contact URL">
                            <button class="button button-quiet button-small" id="generate-push-vapid" type="button">VAPID key তৈরি করুন</button>
                            <button class="button button-quiet button-small" id="subscribe-push" type="button">এই browser-এ Push চালু</button>
                            <label><input id="enable-push-channel" type="checkbox"> Push channel চালু</label>
                        </div>
                        <div id="notification-channel-status" class="knowledge-list"></div>
                    </section>
                    <section class="panel pack-section">
                        <h3>সীমিত Auto policy তৈরি করুন</h3>
                        <form class="task-form" id="access-policy-form">
                            <label for="policy-agent">Agent</label>
                            <select id="policy-agent" name="agent_name" required>
                                <option>Orchestrator</option><option>Research</option><option>Writer</option><option>Reviewer</option>
                            </select>
                            <label for="policy-tool">নির্দিষ্ট tool name</label>
                            <input id="policy-tool" name="tool_name" type="text" maxlength="100" required>
                            <label for="policy-input-constraints">Exact input constraints (write tools-এর জন্য আবশ্যক JSON)</label>
                            <textarea id="policy-input-constraints" name="input_constraints" rows="2" placeholder='{"path":"reports/summary.txt","content":"approved text"}'></textarea>
                            <label for="policy-scope">Scope</label>
                            <select id="policy-scope" name="scope">
                                <option value="task">এই task</option><option value="day">আজ</option>
                                <option value="week">এই সপ্তাহ</option><option value="always">নির্ধারিত expiry পর্যন্ত</option>
                            </select>
                            <label for="policy-task-id">Task ID (শুধু এই task scope)</label>
                            <input id="policy-task-id" name="task_id" type="number" min="1">
                            <div class="brain-form-grid">
                                <label for="policy-max-uses">সর্বোচ্চ ব্যবহার<input id="policy-max-uses" name="maximum_uses" type="number" min="1" max="1000" value="1" required></label>
                                <label for="policy-expiry">মেয়াদ (মিনিট)<input id="policy-expiry" name="expires_in_minutes" type="number" min="1" max="10080" value="60" required></label>
                                <label for="policy-undo">Undo window (সেকেন্ড)<input id="policy-undo" name="undo_window_seconds" type="number" min="0" max="600" value="0" required></label>
                            </div>
                            <button class="button button-primary" type="submit">Policy যোগ করুন</button>
                        </form>
                        <div id="access-policy-list" class="knowledge-list"></div>
                        <p class="form-help">Lock, stove, gas, payment, transfer, purchase, email/message এবং access-settings tool কখনো Auto নয়। Undo window-সহ policy এখন approval বাধ্যতামূলক রাখে।</p>
                    </section>
                    <section class="panel pack-section">
                        <h3>Security audit history</h3>
                        <p class="form-help">Access, trust, model, integration ও tool-approval সিদ্ধান্তের owner-only record।</p>
                        <div id="audit-log-list" class="audit-log-list" aria-live="polite"></div>
                    </section>
                </section>

                <section class="view-panel" id="notifications-view" aria-labelledby="notifications-heading">
                    <div class="section-heading">
                        <div><p class="eyebrow">IN-APP NOTIFICATION CENTER</p><h2 id="notifications-heading">Notifications</h2></div>
                    </div>
                    <div id="notification-list" class="approval-list" aria-live="polite"></div>
                    <p class="form-help">প্রতি channel-এর delivery status ও failure লগ হয়। Approval না এলে কোনো tool action অনুমোদিত ধরা হয় না।</p>
                </section>

                <section class="view-panel" id="knowledge-view" aria-labelledby="knowledge-heading">
                    <div class="section-heading">
                        <div>
                            <p class="eyebrow">PRIVATE KNOWLEDGE</p>
                            <h2 id="knowledge-heading">ফাইল, RAG ও Memory</h2>
                            <p class="section-description">আপনার ফাইল private storage-এ থাকে। Index করতে নির্বাচিত embedding provider-এ ফাইলের পাঠানো অংশ যাবে।</p>
                        </div>
                    </div>
                    <section class="panel knowledge-section">
                        <div class="brain-section-heading">
                            <div>
                                <h3>Embedding model</h3>
                                <p>নিজের Brain settings-এ সংরক্ষিত key ব্যবহার হবে। Embedding খরচ task estimate-এ অন্তর্ভুক্ত নয়।</p>
                            </div>
                        </div>
                        <form id="embedding-settings-form" class="knowledge-form">
                            <label class="brain-field" for="embedding-provider"><span>Provider</span><select id="embedding-provider" name="provider" required></select></label>
                            <label class="brain-field" for="embedding-model"><span>Embedding model</span><select id="embedding-model" name="model" required></select></label>
                            <button class="button button-primary" type="submit">Embedding settings সংরক্ষণ</button>
                            <span id="embedding-settings-status" class="form-help"></span>
                        </form>
                    </section>
                    <section class="panel knowledge-section" id="knowledge-admin-settings" hidden>
                        <div class="brain-section-heading">
                            <div>
                                <h3>Knowledge processing settings (admin)</h3>
                                <p>সিস্টেমজুড়ে upload/extraction limits ও chunking policy। OCR executable path এখানে রাখা হয় না; সেটি server configuration।</p>
                            </div>
                        </div>
                        <form id="knowledge-admin-settings-form" class="knowledge-form">
                            <label class="brain-field" for="knowledge-max-upload"><span>সর্বোচ্চ upload (KB)</span><input id="knowledge-max-upload" name="maximum_upload_kilobytes" type="number" min="1" max="51200" required></label>
                            <label class="brain-field" for="knowledge-max-extracted"><span>সর্বোচ্চ extracted characters</span><input id="knowledge-max-extracted" name="maximum_extracted_characters" type="number" min="10000" max="2000000" required></label>
                            <label class="brain-field" for="knowledge-max-chunks"><span>প্রতি ফাইলে সর্বোচ্চ chunks</span><input id="knowledge-max-chunks" name="maximum_chunks_per_document" type="number" min="1" max="5000" required></label>
                            <label class="brain-field" for="knowledge-chunk-size"><span>Chunk characters</span><input id="knowledge-chunk-size" name="chunk_characters" type="number" min="200" max="5000" required></label>
                            <label class="brain-field" for="knowledge-chunk-overlap"><span>Chunk overlap characters</span><input id="knowledge-chunk-overlap" name="chunk_overlap_characters" type="number" min="0" max="4999" required></label>
                            <button class="button button-primary" type="submit">Admin settings সংরক্ষণ</button>
                        </form>
                    </section>
                    <section class="panel knowledge-section" id="security-admin" hidden>
                        <div class="brain-section-heading">
                            <div>
                                <h3>Execution security</h3>
                                <p>Global kill switch blocks new agent execution. Running provider calls stop at their next security checkpoint.</p>
                            </div>
                        </div>
                        <button class="button button-quiet" id="system-kill-switch-button" type="button" aria-pressed="false">System kill switch loading...</button>
                    </section>
                    <section class="panel knowledge-section">
                        <div class="brain-section-heading">
                            <div><h3>ফাইল যোগ করুন</h3><p>PDF, Excel, CSV, TXT, Markdown এবং image সমর্থিত। Upload limit admin সেট করেন। Image OCR-এর জন্য server-এ Tesseract লাগবে।</p></div>
                        </div>
                        <form id="knowledge-upload-form" class="knowledge-form">
                            <input type="file" name="file" accept=".pdf,.xlsx,.xls,.csv,.txt,.md,.png,.jpg,.jpeg,.webp" required>
                            <button class="button button-primary" type="submit">Private file আপলোড ও index</button>
                        </form>
                        <div id="knowledge-document-list" class="knowledge-list" aria-live="polite"><div class="empty-state">ফাইল লোড হচ্ছে।</div></div>
                    </section>
                    <section class="panel knowledge-section">
                        <div class="brain-section-heading">
                            <div><h3>ফাইলে semantic search</h3><p>ফলাফলে document ও chunk citation থাকবে।</p></div>
                        </div>
                        <form id="knowledge-search-form" class="knowledge-form">
                            <input name="query" type="search" maxlength="2000" placeholder="ফাইলগুলোতে কী খুঁজবেন?" required>
                            <button class="button button-quiet" type="submit">Search</button>
                        </form>
                        <div id="knowledge-search-results" class="knowledge-list" aria-live="polite"></div>
                    </section>
                    <section class="panel knowledge-section">
                        <div class="brain-section-heading">
                            <div><h3>Memory</h3><p>Short-term memory মেয়াদ শেষে মুছে যাবে; long-term memory আপনি নিজে মুছতে পারবেন।</p></div>
                        </div>
                        <form id="memory-form" class="knowledge-form">
                            <label class="brain-field" for="memory-scope"><span>Scope</span>
                                <select id="memory-scope" name="scope_type">
                                    <option value="0">শুধু আমার জন্য</option>
                                    <option value="1">নির্দিষ্ট task-এর জন্য</option>
                                </select>
                            </label>
                            <input name="task_id" type="number" min="1" placeholder="Task ID" hidden>
                            <label class="brain-field" for="memory-type"><span>ধরন</span>
                                <select id="memory-type" name="type">
                                    <option value="0">Short-term</option>
                                    <option value="1">Long-term</option>
                                </select>
                            </label>
                            <input name="label" type="text" maxlength="120" placeholder="নাম (ঐচ্ছিক)">
                            <input name="expires_in_days" type="number" min="1" max="365" placeholder="Short-term মেয়াদ (দিন)">
                            <textarea name="content" rows="3" maxlength="5000" placeholder="আপনার memory লিখুন" required></textarea>
                            <button class="button button-primary" type="submit">Memory সংরক্ষণ</button>
                        </form>
                        <div id="memory-list" class="knowledge-list" aria-live="polite"><div class="empty-state">Memory লোড হচ্ছে।</div></div>
                    </section>
                </section>

                <section class="view-panel" id="admin-view" aria-labelledby="admin-heading">
                    <div class="section-heading">
                        <div>
                            <p class="eyebrow">PLATFORM OPERATIONS</p>
                            <h2 id="admin-heading">Admin dashboard</h2>
                            <p class="section-description">Aggregate usage, prompt versions, evaluation comparisons এবং system alerts।</p>
                        </div>
                        <button class="button button-quiet button-small" id="admin-refresh" type="button">Refresh</button>
                    </div>
                    <div id="admin-alert-list" class="admin-alert-list" aria-live="polite"></div>
                    <div id="admin-summary" class="admin-summary"></div>
                    <div class="admin-grid">
                        <section class="panel admin-panel">
                            <h3>Active agents and prompt versions</h3>
                            <div id="admin-agent-list" class="admin-list"></div>
                            <div id="admin-prompt-lint-results" class="admin-list" aria-live="polite"></div>
                        </section>
                        <section class="panel admin-panel">
                            <h3>Recent users</h3>
                            <div id="admin-user-list" class="admin-list"></div>
                        </section>
                    </div>
                    <section class="panel admin-panel">
                        <h3>Create evaluation case</h3>
                        <p class="form-help">Evaluation sends the case prompt and selected prompt version to the chosen user-approved models. Tools remain disabled; provider charges may apply.</p>
                        <form id="admin-evaluation-case-form" class="admin-form">
                            <input name="name" type="text" maxlength="120" placeholder="Case name" required>
                            <select name="agent_name" id="admin-case-agent" required></select>
                            <textarea name="prompt" rows="3" maxlength="4000" placeholder="Evaluation prompt" required></textarea>
                            <textarea name="reference_answer" rows="3" maxlength="4000" placeholder="Reference answer (optional if criteria are supplied)"></textarea>
                            <textarea name="criteria" rows="2" placeholder="One measurable criterion per line"></textarea>
                            <button class="button button-primary" type="submit">Save evaluation case</button>
                        </form>
                    </section>
                    <section class="panel admin-panel">
                        <h3>Compare approved models and prompt versions</h3>
                        <form id="admin-evaluation-run-form" class="admin-form">
                            <label class="brain-field">Evaluation set (up to 5 cases from one agent)<select id="admin-evaluation-case" name="case_ids[]" multiple size="5" required></select></label>
                            <label class="brain-field">Approved models (up to 3)<select id="admin-evaluation-models" multiple size="4" required></select></label>
                            <label class="brain-field">Prompt versions for this agent (up to 2)<select id="admin-evaluation-versions" multiple size="4" required></select></label>
                            <p class="form-help">Scoring is deterministic lexical token-F1 plus literal criterion coverage. It is useful for comparisons, not a semantic quality certification or automatic router signal.</p>
                            <button class="button button-primary" type="submit">Run evaluation</button>
                        </form>
                        <div id="admin-evaluation-results" class="admin-list" aria-live="polite"></div>
                    </section>
                </section>
            </main>

            <footer class="page-footer">
                <span>API-first · আপনার সিদ্ধান্ত ছাড়া কোনো sensitive live action নয়</span>
                <span>বাংলা · English</span>
            </footer>
        </div>

        <nav class="mobile-navigation" aria-label="মোবাইল নেভিগেশন">
            <button class="navigation-link is-active" data-view="tasks" type="button">
                <span aria-hidden="true">▦</span><span>কাজ</span>
            </button>
            <button class="navigation-link" data-view="approvals" type="button">
                <span aria-hidden="true">◷</span><span>অনুমোদন</span>
                <span class="navigation-count" id="mobile-approval-count" hidden>0</span>
            </button>
            <button class="navigation-link" data-view="agents" type="button">
                <span aria-hidden="true">♙</span><span>এজেন্ট</span>
            </button>
            <button class="navigation-link" data-view="brain" type="button">
                <span aria-hidden="true">✦</span><span>Brain</span>
            </button>
            <button class="navigation-link more-navigation-button" id="mobile-more-button" type="button" aria-expanded="false">
                <span aria-hidden="true">•••</span><span>আরও</span>
            </button>
        </nav>
        <div class="mobile-more-menu" id="mobile-more-menu" hidden>
            <p>Provider, API key ও routing controls Brain পেজে পরিচালনা করুন।</p>
            <button class="button button-quiet button-small" data-view="brain" type="button">Brain settings খুলুন</button>
            <button class="button button-quiet button-small" data-view="skills" type="button">Skills খুলুন</button>
            <button class="button button-quiet button-small" data-view="access" type="button">Access settings খুলুন</button>
            <button class="button button-quiet button-small" data-view="notifications" type="button">Notifications খুলুন</button>
            <button class="button button-quiet button-small" data-view="knowledge" type="button">ফাইল ও Memory খুলুন</button>
            <button class="button button-quiet button-small" data-view="admin" id="platform-admin-more" type="button" hidden>Admin dashboard খুলুন</button>
        </div>
    </div>
</body>
</html>
