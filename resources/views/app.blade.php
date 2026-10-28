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
                <form class="connection-form" id="connection-form">
                    <label class="visually-hidden" for="api-token">Sanctum API token</label>
                    <input id="api-token" name="api-token" type="password" autocomplete="off" placeholder="API token দিন" required>
                    <button class="button button-dark button-small" type="submit">সংযুক্ত করুন</button>
                    <button class="button button-quiet button-small" id="disconnect-button" type="button" hidden>বিচ্ছিন্ন</button>
                </form>
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
                        <span class="mode-badge"><span></span> Sandbox mode</span>
                    </div>

                    <form class="task-form panel" id="task-form">
                        <label for="task-title">কাজের শিরোনাম</label>
                        <input id="task-title" name="title" type="text" maxlength="255" placeholder="যেমন: একটি বিষয় নিয়ে গবেষণা" required>
                        <label for="task-prompt">কী করতে হবে?</label>
                        <textarea id="task-prompt" name="prompt" rows="5" maxlength="20000" placeholder="কাজটি বিস্তারিত লিখুন..." required></textarea>
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
        </nav>
    </div>
</body>
</html>
