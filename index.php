<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
startAppSession();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['auto_login'])) {
    $rawToken = trim((string) $_GET['auto_login']);
    $tokenInfo = $rawToken !== '' ? consumeEmailLoginToken($rawToken) : null;

    if ($tokenInfo !== null) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $tokenInfo['user_id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        logActivity(
            (int) $tokenInfo['user_id'],
            $tokenInfo['document_id'],
            'login',
            'Auto-signed in via email notification link'
        );
    }

    $redirectParams = [];
    $viewParam = preg_replace('/[^a-z0-9\-]/i', '', (string) ($_GET['view'] ?? '')) ?? '';
    if ($viewParam !== '') {
        $redirectParams['view'] = $viewParam;
    }
    $docParam = (int) ($_GET['doc'] ?? 0);
    if ($docParam > 0) {
        $redirectParams['doc'] = $docParam;
    } elseif ($tokenInfo !== null && !empty($tokenInfo['document_id'])) {
        $redirectParams['doc'] = (int) $tokenInfo['document_id'];
    }
    if ($tokenInfo === null) {
        $redirectParams['auth_notice'] = 'link_expired';
    }

    $basePath = getAppBasePath();
    $targetPath = ($basePath !== '' ? $basePath : '') . '/';
    $redirectUrl = $targetPath . (!empty($redirectParams) ? ('?' . http_build_query($redirectParams)) : '');

    header('Cache-Control: no-store');
    header('Location: ' . $redirectUrl, true, 302);
    exit;
}

$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#2563eb">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="KCC DOCMS">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <title><?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" type="image/png" sizes="192x192" href="assets/icons/icon-192.png">
    <link rel="icon" type="image/png" href="assets/icons/kcc-logo.png">
    <link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body>
    <!-- Toast Container -->
    <div id="toast-container" class="toast-container" aria-live="polite"></div>

    <!-- Auth Screen -->
    <div id="auth-screen" class="auth-screen crystal-theme hidden">
        <div class="crystal-bg" aria-hidden="true">
            <span class="crystal-shard crystal-shard-1"></span>
            <span class="crystal-shard crystal-shard-2"></span>
            <span class="crystal-shard crystal-shard-3"></span>
            <span class="crystal-shard crystal-shard-4"></span>
            <span class="crystal-glow crystal-glow-1"></span>
            <span class="crystal-glow crystal-glow-2"></span>
        </div>
        <div class="auth-card">
            <div class="auth-brand">
                <div class="brand-logo">
                    <img src="assets/icons/kcc-logo.png" alt="Kabankalan Catholic College Logo">
                </div>
                <div>
                    <h1>KCC DOCMS</h1>
                    <p>Document Management &amp; File Routing System</p>
                </div>
            </div>

            <div class="auth-tabs">
                <button type="button" class="auth-tab active" data-auth-tab="login">Sign In</button>
                <button type="button" class="auth-tab" data-auth-tab="register">Create Account</button>
            </div>

            <!-- Login Form -->
            <form id="login-form" class="auth-form">
                <div class="form-group">
                    <label for="login-username">Username or Email</label>
                    <input type="text" id="login-username" required autocomplete="username" placeholder="e.g. alice or admin">
                </div>
                <div class="form-group">
                    <label for="login-password">Password</label>
                    <input type="password" id="login-password" required autocomplete="current-password" placeholder="Enter your password">
                </div>
                <button type="submit" class="btn btn-primary btn-block">Sign In</button>
            </form>

            <!-- Register Form -->
            <form id="register-form" class="auth-form hidden">
                <div class="form-group">
                    <label for="reg-fullname">Full Name</label>
                    <input type="text" id="reg-fullname" required placeholder="Juan Dela Cruz">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="reg-username">Username</label>
                        <input type="text" id="reg-username" required placeholder="jdelacruz">
                    </div>
                    <div class="form-group">
                        <label for="reg-dept">Department</label>
                        <input type="text" id="reg-dept" placeholder="Operations" value="General">
                    </div>
                </div>
                <div class="form-group">
                    <label for="reg-email">Email Address</label>
                    <input type="email" id="reg-email" required placeholder="juan@kcc.local">
                </div>
                <div class="form-group">
                    <label for="reg-password">Password (min 6 chars)</label>
                    <input type="password" id="reg-password" required minlength="6" placeholder="Create password">
                </div>
                <button type="submit" class="btn btn-primary btn-block">Create Account</button>
            </form>

            <div id="auth-pwa-bar" class="auth-pwa-bar hidden">
                <button type="button" id="auth-install-btn" class="btn btn-outline btn-sm btn-block hidden">
                    Install KCC DOCMS App (Mobile / Desktop)
                </button>
                <div id="ios-install-hint" class="ios-install-hint hidden">
                    On iPhone/iPad: tap <strong>Share</strong> &rarr; <strong>Add to Home Screen</strong> to use as a mobile app.
                </div>
            </div>
        </div>
    </div>

    <!-- Main App Layout -->
    <div id="app-shell" class="app-shell hidden">
        <div id="sidebar-backdrop" class="sidebar-backdrop hidden" aria-hidden="true"></div>
        <aside id="sidebar" class="sidebar">
            <div class="sidebar-header">
                <div class="brand-logo small">
                    <img src="assets/icons/kcc-logo.png" alt="KCC Logo">
                </div>
                <div class="brand-text">
                    <span class="brand-name">KCC DOCMS</span>
                    <span class="brand-sub">Document Control</span>
                </div>
                <button id="sidebar-close" class="icon-btn mobile-only" aria-label="Close menu">&times;</button>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-section-label">Workspace</div>
                <button class="nav-item active" data-view="dashboard">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                    <span>Dashboard</span>
                </button>
                <button class="nav-item" data-view="send">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    <span>Send Document</span>
                </button>
                <button class="nav-item" data-view="inbox">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>
                    <span>Received Files</span>
                    <span id="nav-inbox-badge" class="nav-badge hidden">0</span>
                </button>
                <button class="nav-item" data-view="sent">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    <span>Sent Files</span>
                </button>
                <button class="nav-item" data-view="notifications">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                    <span>Notifications</span>
                    <span id="nav-notif-badge" class="nav-badge hidden">0</span>
                </button>

                <div id="admin-nav-section" class="hidden">
                    <div class="nav-section-label">Administration</div>
                    <button class="nav-item" data-view="admin-docs">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                        <span>All Documents</span>
                    </button>
                    <button class="nav-item" data-view="admin-users">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        <span>Manage Users</span>
                    </button>
                    <button class="nav-item" data-view="admin-email">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        <span>Gmail / Email</span>
                    </button>
                    <button class="nav-item" data-view="admin-logs">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                        <span>Audit Logs</span>
                    </button>
                </div>
            </nav>

            <div class="sidebar-footer">
                <div class="user-card">
                    <div id="sidebar-avatar" class="avatar">U</div>
                    <div class="user-meta">
                        <div id="sidebar-fullname" class="user-name">User</div>
                        <div id="sidebar-email" class="user-email"></div>
                        <div id="sidebar-role" class="user-role">user</div>
                    </div>
                </div>
                <div class="sidebar-footer-actions">
                    <button type="button" id="btn-install-app" class="btn btn-primary btn-sm btn-block hidden">Install App</button>
                    <button type="button" id="btn-mobile-access" class="btn btn-outline btn-sm btn-block">Mobile App &amp; Network</button>
                    <button id="btn-logout" class="btn btn-outline btn-sm btn-block">Sign Out</button>
                </div>
            </div>
        </aside>

        <div class="main-area">
            <header class="topbar">
                <div class="topbar-left">
                    <button id="sidebar-toggle" class="icon-btn mobile-only" aria-label="Open menu">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                    </button>
                    <h2 id="page-title">Dashboard</h2>
                </div>
                <div class="topbar-right">
                    <button class="btn btn-primary btn-sm" id="topbar-send-btn">
                        + Send Document
                    </button>
                    <div class="notif-wrapper">
                        <button id="notif-bell-btn" class="icon-btn notif-bell" aria-label="Notifications">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                            <span id="topbar-notif-badge" class="bell-badge hidden">0</span>
                        </button>
                        <div id="notif-dropdown" class="notif-dropdown hidden">
                            <div class="notif-dropdown-header">
                                <strong>Notifications</strong>
                                <div class="notif-dropdown-actions">
                                    <button type="button" id="notif-sound-toggle" class="link-btn" title="Toggle notification sound">Sound: On</button>
                                    <button type="button" id="dropdown-mark-all" class="link-btn">Mark all read</button>
                                </div>
                            </div>
                            <div id="notif-dropdown-list" class="notif-dropdown-list"></div>
                            <div class="notif-dropdown-footer">
                                <button type="button" id="dropdown-view-all" class="link-btn">View all notifications</button>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <main class="content-area">
                <!-- VIEW: Dashboard -->
                <section id="view-dashboard" class="view-section">
                    <div id="dashboard-stats" class="stats-grid"></div>
                    <div class="dashboard-grid">
                        <div class="card">
                            <div class="card-header">
                                <h3>Recent Received Documents</h3>
                                <button type="button" class="link-btn" data-jump="inbox">View All</button>
                            </div>
                            <div id="dash-recent-inbox" class="card-body"></div>
                        </div>
                        <div class="card">
                            <div class="card-header">
                                <h3>Latest Notifications</h3>
                                <button type="button" class="link-btn" data-jump="notifications">View All</button>
                            </div>
                            <div id="dash-recent-notifs" class="card-body"></div>
                        </div>
                    </div>
                </section>

                <!-- VIEW: Send Document -->
                <section id="view-send" class="view-section hidden">
                    <div class="card upload-card">
                        <div class="card-header">
                            <h3>Upload &amp; Send Document</h3>
                            <span class="muted">Max file size: 25 MB &bull; Recipients are notified immediately</span>
                        </div>
                        <form id="upload-form" class="card-body">
                            <div class="form-row">
                                <div class="form-group flex-2">
                                    <label for="doc-title">Document Title *</label>
                                    <input type="text" id="doc-title" required placeholder="e.g. Monthly Financial Report - November">
                                </div>
                                <div class="form-group">
                                    <label for="doc-category">Category</label>
                                    <select id="doc-category">
                                        <?php foreach (DOCUMENT_CATEGORIES as $cat): ?>
                                            <option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="doc-priority">Priority</label>
                                    <select id="doc-priority">
                                        <option value="normal">Normal</option>
                                        <option value="urgent">Urgent</option>
                                        <option value="confidential">Confidential</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="doc-desc">Notes / Message to Recipients</label>
                                <textarea id="doc-desc" rows="3" placeholder="Optional instructions or remarks about this document..."></textarea>
                            </div>

                            <div class="form-group">
                                <div class="label-row">
                                    <label>Attach Files *</label>
                                    <button type="button" id="clear-files-btn" class="link-btn hidden">Clear All</button>
                                </div>
                                <div id="drop-zone" class="drop-zone">
                                    <input type="file" id="doc-file" multiple hidden>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                    <div id="drop-zone-text">
                                        <strong>Click to browse</strong> or drag &amp; drop files here (supports multiple files)
                                    </div>
                                    <small>Allowed: PDF, DOC/DOCX, XLS/XLSX, PPT/PPTX, PNG, JPG, GIF, WEBP, TXT, CSV, ZIP</small>
                                </div>
                                <div id="selected-files-list" class="selected-files-list hidden"></div>
                            </div>

                            <div class="form-group">
                                <div class="recipient-header">
                                    <label>Select Recipients * <span id="selected-rec-count" class="badge badge-info">0 selected</span></label>
                                    <div class="recipient-tools">
                                        <input type="text" id="rec-search" class="input-sm" placeholder="Filter users or department...">
                                        <button type="button" id="rec-select-all" class="btn btn-outline btn-xs">Select All</button>
                                        <button type="button" id="rec-clear-all" class="btn btn-outline btn-xs">Clear</button>
                                    </div>
                                </div>
                                <div id="recipient-list" class="recipient-grid"></div>
                            </div>

                            <div class="form-actions">
                                <button type="submit" id="upload-submit-btn" class="btn btn-primary">
                                    Send Document Now
                                </button>
                            </div>
                        </form>
                    </div>
                </section>

                <!-- VIEW: Inbox -->
                <section id="view-inbox" class="view-section hidden">
                    <div class="card">
                        <div class="filter-bar">
                            <input type="text" id="inbox-search" placeholder="Search title, doc code, sender, filename...">
                            <select id="inbox-read-filter">
                                <option value="">All Status</option>
                                <option value="unread">Unread Only</option>
                                <option value="read">Read Only</option>
                            </select>
                            <select id="inbox-cat-filter">
                                <option value="all">All Categories</option>
                                <?php foreach (DOCUMENT_CATEGORIES as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select id="inbox-priority-filter">
                                <option value="">All Priorities</option>
                                <option value="normal">Normal</option>
                                <option value="urgent">Urgent</option>
                                <option value="confidential">Confidential</option>
                            </select>
                        </div>
                        <div id="inbox-table-wrap" class="table-wrap"></div>
                    </div>
                </section>

                <!-- VIEW: Sent -->
                <section id="view-sent" class="view-section hidden">
                    <div class="card">
                        <div class="filter-bar">
                            <input type="text" id="sent-search" placeholder="Search sent documents...">
                            <select id="sent-cat-filter">
                                <option value="all">All Categories</option>
                                <?php foreach (DOCUMENT_CATEGORIES as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div id="sent-table-wrap" class="table-wrap"></div>
                    </div>
                </section>

                <!-- VIEW: Notifications -->
                <section id="view-notifications" class="view-section hidden">
                    <div class="card">
                        <div class="card-header">
                            <h3>All Notifications</h3>
                            <button type="button" id="notifs-mark-all" class="btn btn-outline btn-sm">Mark All as Read</button>
                        </div>
                        <div id="notifications-full-list" class="card-body"></div>
                    </div>
                </section>

                <!-- VIEW: Admin All Documents -->
                <section id="view-admin-docs" class="view-section hidden">
                    <div id="admin-doc-stats" class="stats-grid compact"></div>
                    <div class="card">
                        <div class="filter-bar">
                            <input type="text" id="admin-docs-search" placeholder="Search all uploaded documents, codes, senders...">
                            <select id="admin-docs-status">
                                <option value="all">All Statuses</option>
                                <option value="active">Active</option>
                                <option value="flagged">Flagged</option>
                                <option value="archived">Archived</option>
                            </select>
                            <select id="admin-docs-cat">
                                <option value="all">All Categories</option>
                                <?php foreach (DOCUMENT_CATEGORIES as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div id="admin-docs-table-wrap" class="table-wrap"></div>
                    </div>
                </section>

                <!-- VIEW: Admin Manage Users -->
                <section id="view-admin-users" class="view-section hidden">
                    <div class="card">
                        <div class="card-header">
                            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                                <h3>User Accounts</h3>
                                <span id="delete-key-status-badge" class="badge badge-normal">Delete Key: Checking...</span>
                            </div>
                            <div style="display:flex;gap:8px;flex-wrap:wrap">
                                <button type="button" id="btn-open-delete-key-modal" class="btn btn-outline btn-sm">Master Delete Key</button>
                                <button type="button" id="btn-open-create-user" class="btn btn-primary btn-sm">+ Add User</button>
                            </div>
                        </div>
                        <div id="admin-users-table-wrap" class="table-wrap"></div>
                    </div>
                </section>

                <!-- VIEW: Admin Gmail / Email Settings -->
                <section id="view-admin-email" class="view-section hidden">
                    <div class="dashboard-grid" style="margin-bottom:18px">
                        <div class="card">
                            <div class="card-header">
                                <h3>Connect Gmail Account (SMTP)</h3>
                                <span id="email-status-pill" class="badge badge-normal">Disabled</span>
                            </div>
                            <form id="email-settings-form" class="card-body">
                                <div class="form-group">
                                    <label class="checkbox-inline">
                                        <input type="checkbox" id="smtp-enabled">
                                        <span>Enable automatic email notifications when documents are sent</span>
                                    </label>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="smtp-username">Gmail Address *</label>
                                        <input type="email" id="smtp-username" placeholder="yourname@gmail.com">
                                    </div>
                                    <div class="form-group">
                                        <label for="smtp-password">Google App Password <span id="smtp-pw-hint" class="muted"></span></label>
                                        <input type="password" id="smtp-password" placeholder="16-character App Password">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="smtp-from-name">Sender Display Name</label>
                                        <input type="text" id="smtp-from-name" value="KCC Document Management System">
                                    </div>
                                    <div class="form-group">
                                        <label for="smtp-base-url">Portal URL (included in emails)</label>
                                        <input type="text" id="smtp-base-url" value="http://localhost/KCC_DOCMS">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="smtp-host">SMTP Host</label>
                                        <input type="text" id="smtp-host" value="smtp.gmail.com">
                                    </div>
                                    <div class="form-group">
                                        <label for="smtp-port">SMTP Port</label>
                                        <input type="text" id="smtp-port" value="587">
                                    </div>
                                    <div class="form-group">
                                        <label for="smtp-encryption">Encryption</label>
                                        <select id="smtp-encryption">
                                            <option value="tls">STARTTLS (Port 587 - Recommended)</option>
                                            <option value="ssl">SSL/TLS (Port 465)</option>
                                            <option value="none">None</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary">Save Gmail Settings</button>
                                </div>
                            </form>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <h3>How to Connect Gmail &amp; Test</h3>
                            </div>
                            <div class="card-body">
                                <ol class="setup-steps">
                                    <li>Open your Google Account security settings and enable <strong>2-Step Verification</strong>.</li>
                                    <li>Go to <strong>Security &rarr; App passwords</strong>, create an app password (e.g., named <em>KCC DOCMS</em>), and copy the 16-character code.</li>
                                    <li>Enter your Gmail address and paste the App Password in the form, check <strong>Enable automatic email notifications</strong>, and click <strong>Save Gmail Settings</strong>.</li>
                                    <li>Make sure receiver accounts in <strong>Manage Users</strong> have real email addresses (not <code>@kcc.local</code>).</li>
                                </ol>
                                <hr class="divider">
                                <form id="email-test-form">
                                    <div class="form-group">
                                        <label for="smtp-test-email">Send Test Email To</label>
                                        <div class="form-row">
                                            <input type="email" id="smtp-test-email" placeholder="recipient@gmail.com" style="flex:1">
                                            <button type="submit" id="btn-send-test-email" class="btn btn-outline">Send Test Email</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h3>Recent Email Delivery Logs</h3>
                            <button type="button" id="btn-refresh-email-logs" class="btn btn-outline btn-sm">Refresh</button>
                        </div>
                        <div id="email-logs-table-wrap" class="table-wrap"></div>
                    </div>
                </section>

                <!-- VIEW: Admin Audit Logs -->
                <section id="view-admin-logs" class="view-section hidden">
                    <div class="card">
                        <div class="filter-bar">
                            <input type="text" id="admin-logs-search" placeholder="Filter logs by action, user, document code...">
                            <button type="button" id="admin-logs-refresh" class="btn btn-outline btn-sm">Refresh</button>
                        </div>
                        <div id="admin-logs-table-wrap" class="table-wrap"></div>
                    </div>
                </section>
            </main>
        </div>

        <!-- Mobile Bottom Navigation Bar -->
        <nav id="mobile-bottom-nav" class="mobile-bottom-nav" aria-label="Mobile navigation">
            <button type="button" class="bottom-nav-item active" data-view="dashboard">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                <span>Home</span>
            </button>
            <button type="button" class="bottom-nav-item" data-view="inbox">
                <span class="bottom-nav-icon-wrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>
                    <span id="bottom-inbox-badge" class="bottom-nav-badge hidden">0</span>
                </span>
                <span>Inbox</span>
            </button>
            <button type="button" class="bottom-nav-item bottom-nav-send" data-view="send">
                <span class="bottom-send-pill">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                </span>
                <span>Send</span>
            </button>
            <button type="button" class="bottom-nav-item" data-view="sent">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                <span>Sent</span>
            </button>
            <button type="button" class="bottom-nav-item" id="bottom-menu-btn">
                <span class="bottom-nav-icon-wrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                    <span id="bottom-notif-badge" class="bottom-nav-badge hidden">0</span>
                </span>
                <span>Menu</span>
            </button>
        </nav>
    </div>

    <!-- Document Viewer & Details Modal -->
    <div id="doc-modal" class="modal hidden" role="dialog" aria-modal="true">
        <div class="modal-backdrop" data-close-modal="doc-modal"></div>
        <div class="modal-dialog modal-xl">
            <div class="modal-header">
                <div class="modal-title-wrap">
                    <span id="modal-doc-code" class="doc-code">DOC-0000</span>
                    <h3 id="modal-doc-title">Document Title</h3>
                </div>
                <div class="modal-header-actions">
                    <button type="button" id="modal-edit-btn" class="btn btn-outline btn-sm hidden">Edit / Replace</button>
                    <a id="modal-open-btn" href="#" target="_blank" rel="noopener" class="btn btn-outline btn-sm">Open</a>
                    <a id="modal-download-btn" href="#" class="btn btn-primary btn-sm">Download</a>
                    <button type="button" class="icon-btn" data-close-modal="doc-modal" aria-label="Close">&times;</button>
                </div>
            </div>
            <div class="mobile-modal-tabs" id="doc-modal-mobile-tabs">
                <button type="button" class="mobile-modal-tab active" data-doc-tab="preview">File Preview</button>
                <button type="button" class="mobile-modal-tab" data-doc-tab="meta">Details &amp; Receipts</button>
            </div>
            <div id="modal-attachments-bar" class="modal-attachments-bar hidden"></div>
            <div class="modal-body modal-split" id="doc-modal-split" data-active-pane="preview">
                <div class="modal-preview-pane" id="modal-preview-pane">
                    <!-- Dynamic preview -->
                </div>
                <div class="modal-meta-pane" id="modal-meta-pane">
                    <!-- Dynamic metadata & recipients -->
                </div>
            </div>
        </div>
    </div>

    <!-- Edit / Replace Document Modal -->
    <div id="edit-doc-modal" class="modal hidden" role="dialog" aria-modal="true">
        <div class="modal-backdrop" data-close-modal="edit-doc-modal"></div>
        <div class="modal-dialog modal-md">
            <div class="modal-header">
                <div>
                    <span id="edit-doc-code" class="doc-code">DOC-0000</span>
                    <h3>Edit / Replace Document</h3>
                </div>
                <button type="button" class="icon-btn" data-close-modal="edit-doc-modal" aria-label="Close">&times;</button>
            </div>
            <form id="edit-doc-form" class="modal-body">
                <input type="hidden" id="edit-doc-id">
                <div class="form-row">
                    <div class="form-group flex-2">
                        <label for="edit-doc-title">Document Title *</label>
                        <input type="text" id="edit-doc-title" required>
                    </div>
                    <div class="form-group">
                        <label for="edit-doc-category">Category</label>
                        <select id="edit-doc-category">
                            <?php foreach (DOCUMENT_CATEGORIES as $cat): ?>
                                <option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="edit-doc-priority">Priority</label>
                        <select id="edit-doc-priority">
                            <option value="normal">Normal</option>
                            <option value="urgent">Urgent</option>
                            <option value="confidential">Confidential</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="edit-doc-desc">Notes / Message to Recipients</label>
                    <textarea id="edit-doc-desc" rows="3" placeholder="Optional instructions or remarks..."></textarea>
                </div>

                <div class="form-group">
                    <label>Attached Files</label>
                    <div id="edit-existing-files" class="selected-files-list" style="margin-bottom:8px"></div>
                    <div id="edit-drop-zone" class="drop-zone" style="padding:18px 14px">
                        <input type="file" id="edit-doc-file" multiple hidden>
                        <div id="edit-drop-zone-text">
                            <strong>Click to add more files</strong> or drag &amp; drop (keep at least 1 attachment)
                        </div>
                        <small>Adding or removing attachments notifies recipients and marks the document unread again.</small>
                    </div>
                    <div id="edit-new-files" class="selected-files-list hidden" style="margin-top:8px"></div>
                </div>

                <div class="form-group">
                    <div class="recipient-header">
                        <label>Recipients * <span id="edit-rec-count" class="badge badge-info">0 selected</span></label>
                        <div class="recipient-tools">
                            <input type="text" id="edit-rec-search" class="input-sm" placeholder="Filter users...">
                            <button type="button" id="edit-rec-select-all" class="btn btn-outline btn-xs">Select All</button>
                            <button type="button" id="edit-rec-clear-all" class="btn btn-outline btn-xs">Clear</button>
                        </div>
                    </div>
                    <div id="edit-recipient-list" class="recipient-grid"></div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-outline" data-close-modal="edit-doc-modal">Cancel</button>
                    <button type="submit" id="edit-doc-submit-btn" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Admin Create User Modal -->
    <div id="user-modal" class="modal hidden" role="dialog" aria-modal="true">
        <div class="modal-backdrop" data-close-modal="user-modal"></div>
        <div class="modal-dialog modal-sm">
            <div class="modal-header">
                <h3>Create User Account</h3>
                <button type="button" class="icon-btn" data-close-modal="user-modal" aria-label="Close">&times;</button>
            </div>
            <form id="create-user-form" class="modal-body">
                <div class="form-group">
                    <label for="cu-fullname">Full Name *</label>
                    <input type="text" id="cu-fullname" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="cu-username">Username *</label>
                        <input type="text" id="cu-username" required>
                    </div>
                    <div class="form-group">
                        <label for="cu-role">Role</label>
                        <select id="cu-role">
                            <option value="user">User</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="cu-email">Email *</label>
                    <input type="email" id="cu-email" required>
                </div>
                <div class="form-group">
                    <label for="cu-dept">Department</label>
                    <input type="text" id="cu-dept" value="General">
                </div>
                <div class="form-group">
                    <label for="cu-password">Initial Password (min 6 chars) *</label>
                    <input type="password" id="cu-password" required minlength="6">
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-outline" data-close-modal="user-modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create User</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Admin Master Security Key Modal -->
    <div id="master-delete-key-modal" class="modal hidden" role="dialog" aria-modal="true">
        <div class="modal-backdrop" data-close-modal="master-delete-key-modal"></div>
        <div class="modal-dialog modal-sm">
            <div class="modal-header">
                <h3 id="mdk-modal-title">Master Admin Security Key</h3>
                <button type="button" class="icon-btn" data-close-modal="master-delete-key-modal" aria-label="Close">&times;</button>
            </div>
            <form id="master-delete-key-form" class="modal-body">
                <p id="mdk-description" class="muted" style="margin-top:0">
                    Required before any administrator can permanently delete a user account.
                </p>
                <div class="form-group">
                    <label for="mdk-admin-password">Your Admin Login Password *</label>
                    <input type="password" id="mdk-admin-password" required autocomplete="current-password">
                </div>
                <div id="mdk-current-key-group" class="form-group hidden">
                    <label for="mdk-current-key">Current Master Security Key *</label>
                    <input type="password" id="mdk-current-key" autocomplete="off">
                </div>
                <div class="form-group">
                    <label for="mdk-new-key">New Master Security Key (min 8 chars) *</label>
                    <input type="password" id="mdk-new-key" required minlength="8" autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label for="mdk-confirm-key">Confirm Master Security Key *</label>
                    <input type="password" id="mdk-confirm-key" required minlength="8" autocomplete="new-password">
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-outline" data-close-modal="master-delete-key-modal">Cancel</button>
                    <button type="submit" id="mdk-submit-btn" class="btn btn-primary">Save Security Key</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Admin Delete User Confirmation Modal -->
    <div id="delete-user-modal" class="modal hidden" role="dialog" aria-modal="true">
        <div class="modal-backdrop" data-close-modal="delete-user-modal"></div>
        <div class="modal-dialog modal-md">
            <div class="modal-header">
                <h3>Delete User Account</h3>
                <button type="button" class="icon-btn" data-close-modal="delete-user-modal" aria-label="Close">&times;</button>
            </div>
            <form id="delete-user-form" class="modal-body">
                <input type="hidden" id="du-user-id">
                <div id="du-target-summary" class="muted" style="margin-bottom:12px"></div>
                <div id="du-key-missing-notice" class="hidden" style="margin-bottom:14px;padding:10px 12px;border-radius:8px;background:var(--warning-soft);color:var(--warning);font-size:13px">
                    Master Admin Security Key is not configured yet.
                    <button type="button" id="du-go-set-key-btn" class="btn btn-outline btn-xs" style="margin-left:8px">Configure Key Now</button>
                </div>

                <div class="form-group">
                    <label>Sent Documents &amp; Files Handling *</label>
                    <div style="display:flex;flex-direction:column;gap:8px;margin-top:4px">
                        <label class="checkbox-inline">
                            <input type="radio" name="du-doc-action" value="reassign" checked>
                            <span>Preserve &amp; reassign sent documents/files to an active user</span>
                        </label>
                        <label class="checkbox-inline">
                            <input type="radio" name="du-doc-action" value="delete">
                            <span>Permanently delete all sent documents and uploaded files</span>
                        </label>
                    </div>
                </div>

                <div id="du-reassign-group" class="form-group">
                    <label for="du-reassign-user">Reassign Sent Documents To *</label>
                    <select id="du-reassign-user"></select>
                </div>

                <div id="du-delete-warning" class="hidden" style="margin-bottom:12px;padding:10px 12px;border-radius:8px;background:var(--danger-soft);color:var(--danger);font-size:13px">
                    Warning: All documents sent by this user and their stored attachments will be permanently erased.
                </div>

                <div class="form-group">
                    <label for="du-security-key">Master Admin Security Key *</label>
                    <input type="password" id="du-security-key" required placeholder="Enter Master Admin Security Key" autocomplete="off">
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-outline" data-close-modal="delete-user-modal">Cancel</button>
                    <button type="submit" id="du-submit-btn" class="btn btn-danger">Delete Account</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Mobile App & Network Access Modal -->
    <div id="mobile-access-modal" class="modal hidden" role="dialog" aria-modal="true">
        <div class="modal-backdrop" data-close-modal="mobile-access-modal"></div>
        <div class="modal-dialog modal-md">
            <div class="modal-header">
                <h3>Mobile App &amp; Desktop Access</h3>
                <button type="button" class="icon-btn" data-close-modal="mobile-access-modal" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <div class="access-section">
                    <h4>1. Open on Mobile Phone (Same Wi-Fi / LAN)</h4>
                    <p class="muted" style="margin:4px 0 10px">Open one of these URLs in Safari (iOS) or Chrome (Android) on your phone, or install KCC DOCMS as an app:</p>
                    <div id="lan-urls-list" class="url-list"></div>
                    <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
                        <button type="button" id="modal-install-app-btn" class="btn btn-primary btn-sm hidden">Install App Now</button>
                        <span id="modal-install-note" class="muted"></span>
                    </div>
                </div>

                <hr class="divider">

                <div class="access-section">
                    <h4>2. Native Mobile App API (Bearer Token)</h4>
                    <p class="muted" style="margin:4px 0 10px">Native Android/iOS/Flutter/React Native apps can authenticate via <code>POST api/auth.php?action=login</code> with <code>{"issue_token": true}</code>, or use a generated token below in <code>Authorization: Bearer &lt;token&gt;</code>.</p>
                    <form id="create-token-form" class="form-row" style="align-items:flex-end;margin-bottom:12px">
                        <div class="form-group" style="margin-bottom:0">
                            <label for="token-device-name">Device / App Name</label>
                            <input type="text" id="token-device-name" placeholder="e.g. Android Phone / Desktop Client" value="Mobile App">
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">Generate Token</button>
                    </form>
                    <div id="generated-token-box" class="generated-token-box hidden"></div>
                    <div id="active-tokens-list" class="receipt-list"></div>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
</body>
</html>
