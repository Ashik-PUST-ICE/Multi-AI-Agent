<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#111827">
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
                <button class="navigation-link" data-view="knowledge" type="button">
                    <span aria-hidden="true">▤</span>
                    <span>ফাইল ও Memory</span>
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
                        <label for="task-success-criteria">সাফল্যের শর্ত (প্রতি লাইনে একটি, ঐচ্ছিক)</label>
                        <textarea id="task-success-criteria" name="success_criteria" rows="3" maxlength="5000" placeholder="যেমন: অন্তত ৩টি নির্ভরযোগ্য উৎস ব্যবহার করবে&#10;উত্তরটি বাংলায় দেবে"></textarea>
                        <label for="task-agent-pack">Agent pack (ঐচ্ছিক)</label>
                        <select id="task-agent-pack" name="agent_pack">
                            <option value="">কোনো pack নয়</option>
                        </select>
                        <fieldset class="task-knowledge-fieldset">
                            <legend>এই কাজের জন্য ফাইল (ঐচ্ছিক)</legend>
                            <div id="task-knowledge-documents"><small>ফাইল যোগ করলে এখানে বেছে নিতে পারবেন।</small></div>
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

                    <div class="list-heading">
                        <div>
                            <h2>সাম্প্রতিক কাজ</h2>
                            <p>চলমান কাজের অবস্থা স্বয়ংক্রিয়ভাবে আপডেট হবে।</p>
                        </div>
                        <button class="button button-quiet button-small" id="refresh-tasks" type="button">রিফ্রেশ</button>
                    </div>

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
                                <div><h3>Provider keys</h3><p>Key encrypted অবস্থায় সংরক্ষণ হয়; এখানে plaintext আবার দেখানো হয় না।</p></div>
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
            <button class="button button-quiet button-small" data-view="knowledge" type="button">ফাইল ও Memory খুলুন</button>
        </div>
    </div>
</body>
</html>
