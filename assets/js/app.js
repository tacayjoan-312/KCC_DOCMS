(() => {
    'use strict';

    const state = {
        user: null,
        csrfToken: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        currentView: 'dashboard',
        recipients: [],
        adminUsers: [],
        masterDeleteKeyConfigured: false,
        selectedFiles: [],
        editExistingFiles: [],
        editRemoveFileIds: new Set(),
        editNewFiles: [],
        editSelectedRecipientIds: new Set(),
        currentModalDocId: null,
        currentModalDoc: null,
        currentModalFileId: null,
        notifTimer: null,
        lastUnreadCount: null,
        lastTopNotifId: null,
        audioCtx: null,
        notifAudio: null,
        audioUnlocked: false,
        pendingNotificationSound: false,
        deferredPrompt: null,
        pendingDeepLink: (() => {
            const params = new URLSearchParams(window.location.search);
            return {
                view: params.get('view') || '',
                doc: params.get('doc') ? Number(params.get('doc')) : 0,
            };
        })(),
    };

    const VIEW_TITLES = {
        dashboard: 'Dashboard',
        send: 'Send / Upload Document',
        inbox: 'Received Files (Inbox)',
        sent: 'Sent Documents',
        notifications: 'Notifications',
        'admin-docs': 'Admin: Manage All Documents',
        'admin-users': 'Admin: Manage Users',
        'admin-email': 'Admin: Gmail / Email Notifications',
        'admin-logs': 'Admin: System Audit Logs',
    };

    const $ = (sel) => document.querySelector(sel);
    const $$ = (sel) => Array.from(document.querySelectorAll(sel));

    function escapeHtml(val) {
        const str = String(val ?? '');
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function formatBytes(bytes) {
        const b = Number(bytes) || 0;
        if (b < 1024) return `${b} B`;
        if (b < 1024 * 1024) return `${(b / 1024).toFixed(1)} KB`;
        return `${(b / (1024 * 1024)).toFixed(2)} MB`;
    }

    function formatDate(dt) {
        if (!dt) return '—';
        const d = new Date(dt.replace(' ', 'T'));
        if (Number.isNaN(d.getTime())) return dt;
        return d.toLocaleString([], {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    }

    function toast(message, type = 'info') {
        const container = $('#toast-container');
        const el = document.createElement('div');
        el.className = `toast ${type}`;
        el.textContent = message;
        container.appendChild(el);
        setTimeout(() => {
            el.remove();
        }, 3600);
    }

    async function apiFetch(url, options = {}) {
        const headers = new Headers(options.headers || {});
        if (options.method && options.method.toUpperCase() !== 'GET') {
            headers.set('X-CSRF-Token', state.csrfToken);
        }
        if (options.json !== undefined) {
            headers.set('Content-Type', 'application/json');
            options.body = JSON.stringify(options.json);
            delete options.json;
        }

        const res = await fetch(url, {
            credentials: 'same-origin',
            ...options,
            headers,
        });

        let data = {};
        try {
            data = await res.json();
        } catch {
            data = { success: false, error: 'Invalid server response.' };
        }

        if (!res.ok || data.success === false) {
            if (res.status === 401 && state.user) {
                showAuthScreen();
            }
            throw new Error(data.error || `Request failed (${res.status})`);
        }
        return data;
    }

    // ---- PWA & Mobile Helpers ----
    function initPwaSupport() {
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('sw.js').catch(() => {});
        }

        const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent);
        const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

        if (isIos && !isStandalone) {
            $('#auth-pwa-bar')?.classList.remove('hidden');
            $('#ios-install-hint')?.classList.remove('hidden');
        }

        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            state.deferredPrompt = e;
            $('#auth-pwa-bar')?.classList.remove('hidden');
            $('#auth-install-btn')?.classList.remove('hidden');
            $('#btn-install-app')?.classList.remove('hidden');
            $('#modal-install-app-btn')?.classList.remove('hidden');
        });

        window.addEventListener('appinstalled', () => {
            state.deferredPrompt = null;
            $('#auth-install-btn')?.classList.add('hidden');
            $('#btn-install-app')?.classList.add('hidden');
            $('#modal-install-app-btn')?.classList.add('hidden');
            toast('KCC DOCMS installed as an app!', 'success');
        });
    }

    async function triggerPwaInstall() {
        if (!state.deferredPrompt) {
            toast('Use your browser menu ("Install App" or "Add to Home Screen") to install.', 'info');
            return;
        }
        state.deferredPrompt.prompt();
        const choice = await state.deferredPrompt.userChoice;
        if (choice?.outcome === 'accepted') {
            state.deferredPrompt = null;
            $('#auth-install-btn')?.classList.add('hidden');
            $('#btn-install-app')?.classList.add('hidden');
            $('#modal-install-app-btn')?.classList.add('hidden');
        }
    }

    function setSidebarOpen(open) {
        $('#sidebar').classList.toggle('open', open);
        $('#sidebar-backdrop').classList.toggle('hidden', !open);
    }

    // ---- Auth Flow ----
    async function initApp() {
        initPwaSupport();
        bindEvents();
        const urlParams = new URLSearchParams(window.location.search);
        const authNotice = urlParams.get('auth_notice');
        if (authNotice) {
            urlParams.delete('auth_notice');
            const qs = urlParams.toString();
            const cleanUrl = window.location.pathname + (qs ? `?${qs}` : '') + window.location.hash;
            window.history.replaceState({}, '', cleanUrl);
        }
        try {
            const data = await apiFetch('api/auth.php?action=me');
            if (data.csrf_token) state.csrfToken = data.csrf_token;
            if (data.authenticated && data.user) {
                onAuthenticated(data.user);
            } else {
                showAuthScreen();
                if (authNotice === 'link_expired') {
                    toast('That email login link has expired or is invalid. Please sign in.', 'info');
                }
            }
        } catch {
            showAuthScreen();
        }
    }

    function showAuthScreen() {
        state.user = null;
        if (state.notifTimer) {
            clearInterval(state.notifTimer);
            state.notifTimer = null;
        }
        $('#app-shell').classList.add('hidden');
        $('#auth-screen').classList.remove('hidden');
    }

    function renderSidebarProfile(user) {
        $('#sidebar-fullname').textContent = user.full_name;
        const sidebarEmailEl = $('#sidebar-email');
        if (sidebarEmailEl) {
            sidebarEmailEl.textContent = user.email || '';
            sidebarEmailEl.title = user.email || '';
        }
        $('#sidebar-role').textContent = `${user.role} • ${user.department}`;
        $('#sidebar-avatar').textContent = (user.full_name || 'U').charAt(0).toUpperCase();
    }

    function onAuthenticated(user) {
        state.user = user;
        state.lastUnreadCount = null;
        state.lastTopNotifId = null;
        $('#auth-screen').classList.add('hidden');
        $('#app-shell').classList.remove('hidden');

        renderSidebarProfile(user);

        if (user.role === 'admin') {
            $('#admin-nav-section').classList.remove('hidden');
        } else {
            $('#admin-nav-section').classList.add('hidden');
        }

        const initialView = VIEW_TITLES[state.pendingDeepLink.view] ? state.pendingDeepLink.view : 'dashboard';
        switchView(initialView);
        if (state.pendingDeepLink.doc > 0) {
            const docIdToOpen = state.pendingDeepLink.doc;
            state.pendingDeepLink.doc = 0;
            openDocumentModal(docIdToOpen);
        }
        state.pendingDeepLink.view = '';

        pollNotifications();
        if (state.notifTimer) clearInterval(state.notifTimer);
        state.notifTimer = setInterval(pollNotifications, 10000);
    }

    // ---- Navigation ----
    function switchView(viewName) {
        if (viewName.startsWith('admin-') && state.user?.role !== 'admin') {
            viewName = 'dashboard';
        }
        state.currentView = viewName;

        $$('.nav-item').forEach((btn) => {
            btn.classList.toggle('active', btn.dataset.view === viewName);
        });
        $$('.bottom-nav-item[data-view]').forEach((btn) => {
            btn.classList.toggle('active', btn.dataset.view === viewName);
        });
        $$('.view-section').forEach((sec) => {
            sec.classList.toggle('hidden', sec.id !== `view-${viewName}`);
        });

        $('#page-title').textContent = VIEW_TITLES[viewName] || 'KCC DOCMS';
        setSidebarOpen(false);

        if (viewName === 'dashboard') loadDashboard();
        if (viewName === 'send') loadRecipients();
        if (viewName === 'inbox') loadInbox();
        if (viewName === 'sent') loadSent();
        if (viewName === 'notifications') loadNotificationsView();
        if (viewName === 'admin-docs') loadAdminDocs();
        if (viewName === 'admin-users') loadAdminUsers();
        if (viewName === 'admin-email') loadAdminEmail();
        if (viewName === 'admin-logs') loadAdminLogs();
    }

    // ---- Notifications ----
    const NOTIF_SOUND_STORAGE_KEY = 'kcc_docms_notif_sound';

    function isNotifSoundEnabled() {
        return localStorage.getItem(NOTIF_SOUND_STORAGE_KEY) !== '0';
    }

    function updateNotifSoundToggleUI() {
        const btn = $('#notif-sound-toggle');
        if (!btn) return;
        const enabled = isNotifSoundEnabled();
        btn.textContent = enabled ? 'Sound: On' : 'Sound: Off';
        btn.setAttribute('aria-pressed', enabled ? 'true' : 'false');
    }

    function getNotificationAudioContext() {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) return null;
        if (!state.audioCtx) {
            state.audioCtx = new AudioCtx();
        }
        return state.audioCtx;
    }

    function getNotificationAudioElement() {
        if (state.notifAudio) return state.notifAudio;
        try {
            const sampleRate = 22050;
            const duration = 0.45;
            const numSamples = Math.floor(sampleRate * duration);
            const buffer = new ArrayBuffer(44 + numSamples * 2);
            const view = new DataView(buffer);

            const writeStr = (offset, str) => {
                for (let i = 0; i < str.length; i++) {
                    view.setUint8(offset + i, str.charCodeAt(i));
                }
            };

            writeStr(0, 'RIFF');
            view.setUint32(4, 36 + numSamples * 2, true);
            writeStr(8, 'WAVE');
            writeStr(12, 'fmt ');
            view.setUint32(16, 16, true);
            view.setUint16(20, 1, true);
            view.setUint16(22, 1, true);
            view.setUint32(24, sampleRate, true);
            view.setUint32(28, sampleRate * 2, true);
            view.setUint16(32, 2, true);
            view.setUint16(34, 16, true);
            writeStr(36, 'data');
            view.setUint32(40, numSamples * 2, true);

            for (let i = 0; i < numSamples; i++) {
                const t = i / sampleRate;
                let sample = 0;
                if (t < 0.16) {
                    const env = Math.min(1, t / 0.012) * Math.exp(-t * 16);
                    sample += Math.sin(2 * Math.PI * 880 * t) * env * 0.55;
                }
                if (t >= 0.11) {
                    const t2 = t - 0.11;
                    const env2 = Math.min(1, t2 / 0.012) * Math.exp(-t2 * 10);
                    sample += Math.sin(2 * Math.PI * 1174.66 * t2) * env2 * 0.65;
                }
                const clamped = Math.max(-1, Math.min(1, sample));
                view.setInt16(44 + i * 2, Math.round(clamped * 32767), true);
            }

            const blob = new Blob([buffer], { type: 'audio/wav' });
            const url = URL.createObjectURL(blob);
            const audio = new Audio(url);
            audio.preload = 'auto';
            audio.volume = 0.85;
            state.notifAudio = audio;
            return audio;
        } catch {
            return null;
        }
    }

    function unlockNotificationAudio() {
        if (state.audioUnlocked) return;
        const ctx = getNotificationAudioContext();
        if (ctx && ctx.state === 'suspended') {
            ctx.resume().catch(() => {});
        }
        const audio = getNotificationAudioElement();
        if (audio) {
            const prevVol = audio.volume;
            audio.volume = 0;
            const p = audio.play();
            if (p && typeof p.then === 'function') {
                p.then(() => {
                    audio.pause();
                    audio.currentTime = 0;
                    audio.volume = prevVol;
                    state.audioUnlocked = true;
                    if (state.pendingNotificationSound) {
                        state.pendingNotificationSound = false;
                        playNotificationSound();
                    }
                }).catch(() => {
                    audio.volume = prevVol;
                });
            }
        } else if (ctx && ctx.state === 'running') {
            state.audioUnlocked = true;
        }
    }

    async function playWebAudioChime() {
        const ctx = getNotificationAudioContext();
        if (!ctx) return false;
        if (ctx.state === 'suspended') {
            await ctx.resume();
        }
        if (ctx.state !== 'running') return false;

        const now = ctx.currentTime;
        const tones = [
            { freq: 880, start: 0, duration: 0.16 },
            { freq: 1174.66, start: 0.11, duration: 0.3 },
        ];

        tones.forEach(({ freq, start, duration }) => {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(freq, now + start);

            gain.gain.setValueAtTime(0.0001, now + start);
            gain.gain.exponentialRampToValueAtTime(0.42, now + start + 0.015);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + start + duration);

            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start(now + start);
            osc.stop(now + start + duration + 0.02);
        });
        return true;
    }

    async function playNotificationSound(force = false) {
        if (!force && !isNotifSoundEnabled()) return;
        const audio = getNotificationAudioElement();
        if (audio) {
            try {
                audio.pause();
                audio.currentTime = 0;
                audio.volume = 0.85;
                await audio.play();
                state.pendingNotificationSound = false;
                return;
            } catch {
                // Fall back to Web Audio API
            }
        }

        try {
            const played = await playWebAudioChime();
            if (!played) {
                state.pendingNotificationSound = true;
            } else {
                state.pendingNotificationSound = false;
            }
        } catch {
            state.pendingNotificationSound = true;
        }
    }

    async function pollNotifications() {
        if (!state.user) return;
        try {
            const data = await apiFetch('api/notifications.php?action=list');
            const notifications = data.notifications || [];
            const unread = Number(data.unread_count || 0);
            const unreadInbox = Number(data.unread_inbox || 0);
            const topNotif = notifications[0] || null;
            const topNotifId = topNotif ? Number(topNotif.id || 0) : 0;

            updateBadge($('#topbar-notif-badge'), unread);
            updateBadge($('#nav-notif-badge'), unread);
            updateBadge($('#nav-inbox-badge'), unreadInbox);
            updateBadge($('#bottom-inbox-badge'), unreadInbox);
            updateBadge($('#bottom-notif-badge'), unread);

            const hasUnreadIncrease = state.lastUnreadCount !== null && unread > state.lastUnreadCount;
            const hasNewTopUnread = state.lastTopNotifId !== null
                && topNotifId > state.lastTopNotifId
                && Number(topNotif?.is_read) === 0;

            if (hasUnreadIncrease || hasNewTopUnread) {
                const newCount = hasUnreadIncrease ? (unread - state.lastUnreadCount) : 1;
                playNotificationSound();
                toast(`You have ${newCount} new notification(s).`, 'info');
                if (state.currentView === 'inbox') loadInbox();
                if (state.currentView === 'dashboard') loadDashboard();
                if (state.currentView === 'notifications') loadNotificationsView();
            }
            state.lastUnreadCount = unread;
            state.lastTopNotifId = topNotifId;

            renderNotificationDropdown(notifications);
        } catch {
            // Silent polling error
        }
    }

    function updateBadge(el, count) {
        if (!el) return;
        if (count > 0) {
            el.textContent = count > 99 ? '99+' : String(count);
            el.classList.remove('hidden');
        } else {
            el.classList.add('hidden');
        }
    }

    function renderNotificationDropdown(items) {
        const list = $('#notif-dropdown-list');
        if (!items.length) {
            list.innerHTML = '<div class="empty-state">No notifications yet.</div>';
            return;
        }
        list.innerHTML = items.slice(0, 12).map((n) => `
            <div class="notif-item ${Number(n.is_read) === 0 ? 'unread' : ''}" data-notif-id="${n.id}" data-doc-id="${n.document_id || ''}">
                <span class="notif-dot"></span>
                <div>
                    <div class="notif-title">${escapeHtml(n.title)}</div>
                    <div class="notif-msg">${escapeHtml(n.message)}</div>
                    <div class="notif-time">${escapeHtml(formatDate(n.created_at))}</div>
                </div>
            </div>
        `).join('');
    }

    async function loadNotificationsView() {
        const container = $('#notifications-full-list');
        container.innerHTML = '<div class="empty-state">Loading notifications...</div>';
        try {
            const data = await apiFetch('api/notifications.php?action=list');
            const items = data.notifications || [];
            if (!items.length) {
                container.innerHTML = '<div class="empty-state">You have no notifications.</div>';
                return;
            }
            container.innerHTML = items.map((n) => `
                <div class="notif-item ${Number(n.is_read) === 0 ? 'unread' : ''}" data-notif-id="${n.id}" data-doc-id="${n.document_id || ''}">
                    <span class="notif-dot"></span>
                    <div style="flex:1">
                        <div class="notif-title">${escapeHtml(n.title)}</div>
                        <div class="notif-msg">${escapeHtml(n.message)}</div>
                        <div class="notif-time">${escapeHtml(formatDate(n.created_at))}</div>
                    </div>
                    ${n.document_id ? '<button type="button" class="btn btn-outline btn-xs">Open Document</button>' : ''}
                </div>
            `).join('');
        } catch (err) {
            container.innerHTML = `<div class="empty-state">${escapeHtml(err.message)}</div>`;
        }
    }

    async function handleNotificationClick(notifId, docId) {
        try {
            await apiFetch('api/notifications.php?action=mark_read', {
                method: 'POST',
                json: { id: Number(notifId) },
            });
            $('#notif-dropdown').classList.add('hidden');
            pollNotifications();
            if (docId) {
                openDocumentModal(Number(docId));
            } else if (state.currentView === 'notifications') {
                loadNotificationsView();
            }
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    // ---- Dashboard ----
    async function loadDashboard() {
        try {
            const [statsRes, inboxRes, notifRes] = await Promise.all([
                apiFetch('api/admin.php?action=user_stats'),
                apiFetch('api/documents.php?action=inbox'),
                apiFetch('api/notifications.php?action=list'),
            ]);

            const s = statsRes.stats;
            $('#dashboard-stats').innerHTML = `
                <div class="stat-card">
                    <div class="stat-label">Received Files</div>
                    <div class="stat-value">${s.inbox_total}</div>
                    <div class="stat-sub">${s.inbox_unread} unread document(s)</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Sent Documents</div>
                    <div class="stat-value">${s.sent_total}</div>
                    <div class="stat-sub">Files uploaded by you</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Unread Alerts</div>
                    <div class="stat-value">${s.unread_notifications}</div>
                    <div class="stat-sub">Real-time notifications</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Account Role</div>
                    <div class="stat-value" style="font-size:22px;text-transform:uppercase">${escapeHtml(state.user.role)}</div>
                    <div class="stat-sub">${escapeHtml(state.user.department)}</div>
                </div>
            `;

            const recentDocs = (inboxRes.documents || []).slice(0, 5);
            $('#dash-recent-inbox').innerHTML = recentDocs.length
                ? recentDocs.map((d) => `
                    <div class="notif-item ${Number(d.is_read) === 0 ? 'unread' : ''}" data-open-doc="${d.id}">
                        <span class="notif-dot"></span>
                        <div style="flex:1">
                            <div class="notif-title">${escapeHtml(d.title)} <span class="badge badge-${escapeHtml(d.priority)}">${escapeHtml(d.priority)}</span></div>
                            <div class="notif-msg">From ${escapeHtml(d.sender_name)} &bull; ${escapeHtml(d.original_filename)} (${formatBytes(d.file_size)})</div>
                            <div class="notif-time">${escapeHtml(formatDate(d.created_at))}</div>
                        </div>
                        <button type="button" class="btn btn-outline btn-xs">View</button>
                    </div>
                `).join('')
                : '<div class="empty-state">No received documents yet.</div>';

            const recentNotifs = (notifRes.notifications || []).slice(0, 5);
            $('#dash-recent-notifs').innerHTML = recentNotifs.length
                ? recentNotifs.map((n) => `
                    <div class="notif-item ${Number(n.is_read) === 0 ? 'unread' : ''}" data-notif-id="${n.id}" data-doc-id="${n.document_id || ''}">
                        <span class="notif-dot"></span>
                        <div>
                            <div class="notif-title">${escapeHtml(n.title)}</div>
                            <div class="notif-msg">${escapeHtml(n.message)}</div>
                            <div class="notif-time">${escapeHtml(formatDate(n.created_at))}</div>
                        </div>
                    </div>
                `).join('')
                : '<div class="empty-state">No notifications yet.</div>';
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    // ---- Send / Upload ----
    async function loadRecipients() {
        try {
            const data = await apiFetch('api/users.php?action=recipients');
            state.recipients = data.recipients || [];
            renderRecipientCheckboxes();
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    function renderRecipientCheckboxes() {
        const query = ($('#rec-search').value || '').trim().toLowerCase();
        const checkedIds = new Set($$('input[name="recipient_id"]:checked').map((i) => i.value));
        const filtered = state.recipients.filter((r) =>
            r.full_name.toLowerCase().includes(query) ||
            r.username.toLowerCase().includes(query) ||
            r.department.toLowerCase().includes(query)
        );

        const wrap = $('#recipient-list');
        if (!filtered.length) {
            wrap.innerHTML = '<div class="muted">No matching recipients found.</div>';
            return;
        }

        wrap.innerHTML = filtered.map((r) => `
            <label class="recipient-chip">
                <input type="checkbox" name="recipient_id" value="${r.id}" ${checkedIds.has(String(r.id)) ? 'checked' : ''}>
                <div class="recipient-chip-meta">
                    <strong>${escapeHtml(r.full_name)}</strong>
                    <span>@${escapeHtml(r.username)} &bull; ${escapeHtml(r.department)}</span>
                </div>
            </label>
        `).join('');
        updateSelectedRecipientCount();
    }

    function updateSelectedRecipientCount() {
        const count = $$('input[name="recipient_id"]:checked').length;
        $('#selected-rec-count').textContent = `${count} selected`;
    }

    function dedupeAppendFiles(targetList, incomingFiles) {
        const seen = new Set(targetList.map((f) => `${f.name}:${f.size}:${f.lastModified}`));
        Array.from(incomingFiles || []).forEach((f) => {
            const key = `${f.name}:${f.size}:${f.lastModified}`;
            if (!seen.has(key)) {
                seen.add(key);
                targetList.push(f);
            }
        });
    }

    function renderSelectedFiles() {
        const list = $('#selected-files-list');
        const clearBtn = $('#clear-files-btn');
        const dropText = $('#drop-zone-text');
        const files = state.selectedFiles;

        if (!files.length) {
            list.classList.add('hidden');
            list.innerHTML = '';
            clearBtn?.classList.add('hidden');
            dropText.innerHTML = '<strong>Click to browse</strong> or drag &amp; drop files here (supports multiple files)';
            return;
        }

        const totalBytes = files.reduce((sum, f) => sum + (f.size || 0), 0);
        dropText.innerHTML = `<strong>${files.length} file(s) attached</strong> (${formatBytes(totalBytes)}) &mdash; click or drop to add more`;
        clearBtn?.classList.remove('hidden');
        list.classList.remove('hidden');
        list.innerHTML = `
            <div class="selected-files-summary">${files.length} file(s) queued &bull; ${formatBytes(totalBytes)} total</div>
            ${files.map((f, idx) => `
                <div class="file-chip-row">
                    <div class="file-chip-main">
                        <span class="file-chip-name" title="${escapeHtml(f.name)}">${idx + 1}. ${escapeHtml(f.name)}</span>
                        <span class="file-chip-meta">${formatBytes(f.size)}</span>
                    </div>
                    <div class="file-chip-actions">
                        <button type="button" class="btn btn-outline btn-xs" data-remove-send-file="${idx}">Remove</button>
                    </div>
                </div>
            `).join('')}
        `;
    }

    function addSelectedFiles(fileList) {
        if (!fileList || !fileList.length) return;
        dedupeAppendFiles(state.selectedFiles, fileList);
        if (state.selectedFiles.length && !$('#doc-title').value.trim()) {
            $('#doc-title').value = state.selectedFiles[0].name.replace(/\.[^/.]+$/, '');
        }
        $('#doc-file').value = '';
        renderSelectedFiles();
    }

    function clearSelectedFiles() {
        state.selectedFiles = [];
        const input = $('#doc-file');
        if (input) input.value = '';
        renderSelectedFiles();
    }

    function renderDocFileSummary(d) {
        const count = Number(d.file_count || 1);
        const extraLabel = count > 1
            ? ` <span class="file-count-pill">${count} files</span>`
            : '';
        return `${escapeHtml(d.original_filename)} (${formatBytes(d.file_size)})${extraLabel}`;
    }

    // ---- Inbox ----
    async function loadInbox() {
        const wrap = $('#inbox-table-wrap');
        wrap.innerHTML = '<div class="empty-state">Loading received documents...</div>';

        const params = new URLSearchParams({
            action: 'inbox',
            search: $('#inbox-search').value.trim(),
            read_state: $('#inbox-read-filter').value,
            category: $('#inbox-cat-filter').value,
            priority: $('#inbox-priority-filter').value,
        });

        try {
            const data = await apiFetch(`api/documents.php?${params.toString()}`);
            const docs = data.documents || [];
            if (!docs.length) {
                wrap.innerHTML = '<div class="empty-state">No received documents match your filters.</div>';
                return;
            }

            wrap.innerHTML = `
                <table class="responsive-table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Document</th>
                            <th>Sender</th>
                            <th>Category / Priority</th>
                            <th>Received</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${docs.map((d) => `
                            <tr class="${Number(d.is_read) === 0 ? 'row-unread' : ''}">
                                <td data-label="Code"><span class="doc-code">${escapeHtml(d.doc_code)}</span></td>
                                <td data-label="Document">
                                    <div class="doc-title-cell">
                                        <strong>${escapeHtml(d.title)}</strong>
                                        <span class="muted">${renderDocFileSummary(d)}</span>
                                    </div>
                                </td>
                                <td data-label="Sender">
                                    <div>
                                        <div>${escapeHtml(d.sender_name)}</div>
                                        <div class="muted">${escapeHtml(d.sender_department)}</div>
                                    </div>
                                </td>
                                <td data-label="Category / Priority">
                                    <div>
                                        <span class="badge badge-info">${escapeHtml(d.category)}</span>
                                        <span class="badge badge-${escapeHtml(d.priority)}">${escapeHtml(d.priority)}</span>
                                    </div>
                                </td>
                                <td data-label="Received">${escapeHtml(formatDate(d.created_at))}</td>
                                <td data-label="Status">
                                    <div>
                                        ${Number(d.is_read) === 0
                                            ? '<span class="badge badge-urgent">Unread</span>'
                                            : `<span class="badge badge-success">Read</span><div class="muted">Views: ${d.viewed_count} &bull; DL: ${d.downloaded_count}</div>`}
                                    </div>
                                </td>
                                <td class="td-actions">
                                    <div class="actions-cell">
                                        <button type="button" class="btn btn-outline btn-xs" data-open-doc="${d.id}">View</button>
                                        <a href="api/file.php?id=${d.id}&mode=download" class="btn btn-primary btn-xs" data-dl-doc="${d.id}">Download</a>
                                    </div>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;
        } catch (err) {
            wrap.innerHTML = `<div class="empty-state">${escapeHtml(err.message)}</div>`;
        }
    }

    // ---- Sent ----
    async function loadSent() {
        const wrap = $('#sent-table-wrap');
        wrap.innerHTML = '<div class="empty-state">Loading sent documents...</div>';

        const params = new URLSearchParams({
            action: 'sent',
            search: $('#sent-search').value.trim(),
            category: $('#sent-cat-filter').value,
        });

        try {
            const data = await apiFetch(`api/documents.php?${params.toString()}`);
            const docs = data.documents || [];
            if (!docs.length) {
                wrap.innerHTML = '<div class="empty-state">You have not sent any documents yet.</div>';
                return;
            }

            wrap.innerHTML = `
                <table class="responsive-table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Document</th>
                            <th>Recipients</th>
                            <th>Tracking</th>
                            <th>Status</th>
                            <th>Sent At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${docs.map((d) => `
                            <tr>
                                <td data-label="Code"><span class="doc-code">${escapeHtml(d.doc_code)}</span></td>
                                <td data-label="Document">
                                    <div class="doc-title-cell">
                                        <strong>${escapeHtml(d.title)}</strong>
                                        <span class="muted">${renderDocFileSummary(d)}</span>
                                    </div>
                                </td>
                                <td data-label="Recipients">
                                    <div>
                                        <div><strong>${d.recipient_count}</strong> recipient(s)</div>
                                        <div class="muted">${escapeHtml(d.recipient_names || '—')}</div>
                                    </div>
                                </td>
                                <td data-label="Tracking">
                                    <div>
                                        <div>${d.read_count}/${d.recipient_count} Read</div>
                                        <div class="muted">${d.total_views} views &bull; ${d.total_downloads} downloads</div>
                                    </div>
                                </td>
                                <td data-label="Status"><span class="badge badge-${escapeHtml(d.status)}">${escapeHtml(d.status)}</span></td>
                                <td data-label="Sent At">${escapeHtml(formatDate(d.created_at))}</td>
                                <td class="td-actions">
                                    <div class="actions-cell">
                                        <button type="button" class="btn btn-outline btn-xs" data-open-doc="${d.id}">View &amp; Receipts</button>
                                        <a href="api/file.php?id=${d.id}&mode=download" class="btn btn-primary btn-xs">Download</a>
                                        ${d.status !== 'archived'
                                            ? `<button type="button" class="btn btn-outline btn-xs" data-edit-doc="${d.id}">Edit / Replace</button>`
                                            : ''}
                                        <button type="button" class="btn btn-danger btn-xs" data-delete-sent="${d.id}" data-code="${escapeHtml(d.doc_code)}">Delete</button>
                                    </div>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;
        } catch (err) {
            wrap.innerHTML = `<div class="empty-state">${escapeHtml(err.message)}</div>`;
        }
    }

    // ---- Admin Views ----
    async function loadAdminDocs() {
        const wrap = $('#admin-docs-table-wrap');
        wrap.innerHTML = '<div class="empty-state">Loading all system documents...</div>';

        const params = new URLSearchParams({
            action: 'all',
            search: $('#admin-docs-search').value.trim(),
            status: $('#admin-docs-status').value,
            category: $('#admin-docs-cat').value,
        });

        try {
            const [statsRes, docsRes] = await Promise.all([
                apiFetch('api/admin.php?action=admin_stats'),
                apiFetch(`api/documents.php?${params.toString()}`),
            ]);

            const st = statsRes.stats;
            $('#admin-doc-stats').innerHTML = `
                <div class="stat-card">
                    <div class="stat-label">Total Documents</div>
                    <div class="stat-value">${st.total_documents}</div>
                    <div class="stat-sub">${st.active_documents} active &bull; ${st.archived_documents} archived &bull; ${st.flagged_documents} flagged</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Storage Used</div>
                    <div class="stat-value">${formatBytes(st.total_bytes)}</div>
                    <div class="stat-sub">Across all uploaded files</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Engagement</div>
                    <div class="stat-value">${st.total_downloads}</div>
                    <div class="stat-sub">${st.total_views} inline views</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">System Users</div>
                    <div class="stat-value">${st.active_users}</div>
                    <div class="stat-sub">${st.total_users} total accounts</div>
                </div>
            `;

            const docs = docsRes.documents || [];
            if (!docs.length) {
                wrap.innerHTML = '<div class="empty-state">No documents found matching filters.</div>';
                return;
            }

            wrap.innerHTML = `
                <table class="responsive-table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Document</th>
                            <th>Uploaded By</th>
                            <th>Recipients</th>
                            <th>Status</th>
                            <th>Uploaded</th>
                            <th>Admin Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${docs.map((d) => `
                            <tr>
                                <td data-label="Code"><span class="doc-code">${escapeHtml(d.doc_code)}</span></td>
                                <td data-label="Document">
                                    <div class="doc-title-cell">
                                        <strong>${escapeHtml(d.title)}</strong>
                                        <span class="muted">${renderDocFileSummary(d)}</span> &bull; ${escapeHtml(d.category)}
                                    </div>
                                </td>
                                <td data-label="Uploaded By">
                                    <div>
                                        <div>${escapeHtml(d.sender_name)}</div>
                                        <div class="muted">@${escapeHtml(d.sender_username)} (${escapeHtml(d.sender_department)})</div>
                                    </div>
                                </td>
                                <td data-label="Recipients">
                                    <div>
                                        <div>${d.recipient_count} user(s)</div>
                                        <div class="muted">${d.read_count} read &bull; ${d.total_downloads} DL</div>
                                    </div>
                                </td>
                                <td data-label="Status">
                                    <div>
                                        <span class="badge badge-${escapeHtml(d.status)}">${escapeHtml(d.status)}</span>
                                        <span class="badge badge-${escapeHtml(d.priority)}">${escapeHtml(d.priority)}</span>
                                    </div>
                                </td>
                                <td data-label="Uploaded">${escapeHtml(formatDate(d.created_at))}</td>
                                <td class="td-actions">
                                    <div class="actions-cell">
                                        <button type="button" class="btn btn-outline btn-xs" data-open-doc="${d.id}">View</button>
                                        <a href="api/file.php?id=${d.id}&mode=download" class="btn btn-outline btn-xs">DL</a>
                                        ${d.status !== 'archived'
                                            ? `<button type="button" class="btn btn-outline btn-xs" data-admin-status="${d.id}" data-status="archived">Archive</button>`
                                            : `<button type="button" class="btn btn-outline btn-xs" data-admin-status="${d.id}" data-status="active">Restore</button>`}
                                        ${d.status !== 'flagged'
                                            ? `<button type="button" class="btn btn-outline btn-xs" data-admin-status="${d.id}" data-status="flagged">Flag</button>`
                                            : `<button type="button" class="btn btn-outline btn-xs" data-admin-status="${d.id}" data-status="active">Unflag</button>`}
                                        <button type="button" class="btn btn-danger btn-xs" data-admin-delete="${d.id}" data-code="${escapeHtml(d.doc_code)}">Delete</button>
                                    </div>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;
        } catch (err) {
            wrap.innerHTML = `<div class="empty-state">${escapeHtml(err.message)}</div>`;
        }
    }

    function updateDeleteKeyBadge() {
        const badge = $('#delete-key-status-badge');
        const btn = $('#btn-open-delete-key-modal');
        if (!badge) return;
        if (state.masterDeleteKeyConfigured) {
            badge.className = 'badge badge-active';
            badge.textContent = 'Delete Key: Configured';
            if (btn) btn.textContent = 'Change Delete Key';
        } else {
            badge.className = 'badge badge-flagged';
            badge.textContent = 'Delete Key: Not Set';
            if (btn) btn.textContent = 'Set Master Delete Key';
        }
    }

    function openMasterDeleteKeyModal() {
        const form = $('#master-delete-key-form');
        form.reset();
        const isConfigured = Boolean(state.masterDeleteKeyConfigured);
        $('#mdk-modal-title').textContent = isConfigured
            ? 'Change Master Admin Security Key'
            : 'Set Master Admin Security Key';
        $('#mdk-current-key-group').classList.toggle('hidden', !isConfigured);
        $('#mdk-current-key').required = isConfigured;
        $('#mdk-submit-btn').textContent = isConfigured ? 'Update Security Key' : 'Save Security Key';
        $('#master-delete-key-modal').classList.remove('hidden');
    }

    function syncDeleteDocActionUi() {
        const selected = document.querySelector('input[name="du-doc-action"]:checked')?.value || 'reassign';
        $('#du-reassign-group').classList.toggle('hidden', selected !== 'reassign');
        $('#du-delete-warning').classList.toggle('hidden', selected !== 'delete');
    }

    function openDeleteUserModal(targetUserId) {
        const target = state.adminUsers.find((u) => Number(u.id) === Number(targetUserId));
        if (!target) return;

        $('#delete-user-form').reset();
        $('#du-user-id').value = String(target.id);
        $('#du-target-summary').innerHTML = `
            Deleting <strong>${escapeHtml(target.full_name)}</strong>
            (<code>@${escapeHtml(target.username)}</code>, ${escapeHtml(target.role)})
            &bull; ${Number(target.sent_count || 0)} sent document(s).
        `;

        const activeOthers = state.adminUsers.filter(
            (u) => Number(u.id) !== Number(target.id) && u.status === 'active'
        );
        const reassignSelect = $('#du-reassign-user');
        reassignSelect.innerHTML = activeOthers.map((u) => {
            const isCurrentAdmin = Number(u.id) === Number(state.user?.id);
            const label = `${u.full_name} (@${u.username})${isCurrentAdmin ? ' — Current Admin' : ''}`;
            return `<option value="${u.id}" ${isCurrentAdmin ? 'selected' : ''}>${escapeHtml(label)}</option>`;
        }).join('');

        const keyMissing = !state.masterDeleteKeyConfigured;
        $('#du-key-missing-notice').classList.toggle('hidden', !keyMissing);
        $('#du-submit-btn').disabled = keyMissing;

        const defaultRadio = document.querySelector('input[name="du-doc-action"][value="reassign"]');
        if (defaultRadio) defaultRadio.checked = true;
        syncDeleteDocActionUi();

        $('#delete-user-modal').classList.remove('hidden');
    }

    async function loadAdminUsers() {
        const wrap = $('#admin-users-table-wrap');
        wrap.innerHTML = '<div class="empty-state">Loading users...</div>';
        try {
            const data = await apiFetch('api/users.php?action=list');
            const users = data.users || [];
            state.adminUsers = users;
            state.masterDeleteKeyConfigured = Boolean(data.master_delete_key_configured);
            updateDeleteKeyBadge();
            wrap.innerHTML = `
                <table class="responsive-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Email</th>
                            <th>Department</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Activity</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${users.map((u) => {
                            const isSelf = Number(u.id) === Number(state.user.id);
                            return `
                                <tr>
                                    <td data-label="User">
                                        <div>
                                            <strong>${escapeHtml(u.full_name)}</strong>
                                            <div class="muted">@${escapeHtml(u.username)}</div>
                                        </div>
                                    </td>
                                    <td data-label="Email">${escapeHtml(u.email)}</td>
                                    <td data-label="Department">${escapeHtml(u.department)}</td>
                                    <td data-label="Role"><span class="badge ${u.role === 'admin' ? 'badge-confidential' : 'badge-info'}">${escapeHtml(u.role)}</span></td>
                                    <td data-label="Status"><span class="badge ${u.status === 'active' ? 'badge-active' : 'badge-flagged'}">${escapeHtml(u.status)}</span></td>
                                    <td data-label="Activity">${u.sent_count} sent &bull; ${u.received_count} received</td>
                                    <td class="td-actions">
                                        <div class="actions-cell">
                                            ${!isSelf ? `
                                                <button type="button" class="btn btn-outline btn-xs" data-toggle-status="${u.id}" data-next="${u.status === 'active' ? 'inactive' : 'active'}">
                                                    ${u.status === 'active' ? 'Deactivate' : 'Activate'}
                                                </button>
                                                <button type="button" class="btn btn-outline btn-xs" data-toggle-role="${u.id}" data-next="${u.role === 'admin' ? 'user' : 'admin'}">
                                                    Make ${u.role === 'admin' ? 'User' : 'Admin'}
                                                </button>
                                            ` : '<span class="muted">Current Admin</span>'}
                                            <button type="button" class="btn btn-outline btn-xs" data-edit-email="${u.id}" data-email="${escapeHtml(u.email)}" data-username="${escapeHtml(u.username)}">Edit Email</button>
                                            <button type="button" class="btn btn-outline btn-xs" data-reset-pw="${u.id}" data-username="${escapeHtml(u.username)}">Reset PW</button>
                                            ${!isSelf ? `
                                                <button type="button" class="btn btn-danger btn-xs" data-delete-user="${u.id}">Delete</button>
                                            ` : ''}
                                        </div>
                                    </td>
                                </tr>
                            `;
                        }).join('')}
                    </tbody>
                </table>
            `;
        } catch (err) {
            wrap.innerHTML = `<div class="empty-state">${escapeHtml(err.message)}</div>`;
        }
    }

    async function loadAdminEmail() {
        const logsWrap = $('#email-logs-table-wrap');
        logsWrap.innerHTML = '<div class="empty-state">Loading email settings...</div>';
        try {
            const data = await apiFetch('api/email.php?action=settings');
            const s = data.settings;

            $('#smtp-enabled').checked = Boolean(s.enabled);
            $('#smtp-username').value = s.smtp_username || '';
            $('#smtp-password').value = '';
            $('#smtp-pw-hint').textContent = s.has_password ? '(saved - leave blank to keep)' : '(required)';
            $('#smtp-from-name').value = s.from_name || 'KCC Document Management System';
            $('#smtp-base-url').value = s.app_base_url || window.location.origin + '/KCC_DOCMS';
            $('#smtp-host').value = s.smtp_host || 'smtp.gmail.com';
            $('#smtp-port').value = s.smtp_port || 587;
            $('#smtp-encryption').value = s.encryption || 'tls';

            const pill = $('#email-status-pill');
            pill.textContent = s.enabled ? 'Enabled' : 'Disabled';
            pill.className = `badge ${s.enabled ? 'badge-active' : 'badge-normal'}`;

            if (!$('#smtp-test-email').value && state.user?.email) {
                $('#smtp-test-email').value = state.user.email;
            }

            const logs = data.logs || [];
            if (!logs.length) {
                logsWrap.innerHTML = '<div class="empty-state">No email delivery logs yet.</div>';
                return;
            }

            logsWrap.innerHTML = `
                <table class="responsive-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Doc Code</th>
                            <th>Recipient</th>
                            <th>Subject</th>
                            <th>Status</th>
                            <th>Error / Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${logs.map((l) => `
                            <tr>
                                <td data-label="Time">${escapeHtml(formatDate(l.created_at))}</td>
                                <td data-label="Doc Code">${l.doc_code ? `<span class="doc-code">${escapeHtml(l.doc_code)}</span>` : '<span class="muted">Test</span>'}</td>
                                <td data-label="Recipient">
                                    <div>
                                        <div>${escapeHtml(l.recipient_name || '—')}</div>
                                        <div class="muted">${escapeHtml(l.recipient_email)}</div>
                                    </div>
                                </td>
                                <td data-label="Subject">${escapeHtml(l.subject)}</td>
                                <td data-label="Status"><span class="badge ${l.status === 'sent' ? 'badge-success' : 'badge-flagged'}">${escapeHtml(l.status)}</span></td>
                                <td data-label="Details" class="muted">${escapeHtml(l.error_message || 'Delivered via SMTP')}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;
        } catch (err) {
            logsWrap.innerHTML = `<div class="empty-state">${escapeHtml(err.message)}</div>`;
        }
    }

    async function loadAdminLogs() {
        const wrap = $('#admin-logs-table-wrap');
        wrap.innerHTML = '<div class="empty-state">Loading audit logs...</div>';
        const search = encodeURIComponent($('#admin-logs-search').value.trim());
        try {
            const data = await apiFetch(`api/admin.php?action=logs&search=${search}`);
            const logs = data.logs || [];
            if (!logs.length) {
                wrap.innerHTML = '<div class="empty-state">No audit logs found.</div>';
                return;
            }
            wrap.innerHTML = `
                <table class="responsive-table">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Details</th>
                            <th>IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${logs.map((l) => `
                            <tr>
                                <td data-label="Timestamp">${escapeHtml(formatDate(l.created_at))}</td>
                                <td data-label="User">${l.full_name ? `${escapeHtml(l.full_name)} <span class="muted">(@${escapeHtml(l.username)})</span>` : '<span class="muted">System</span>'}</td>
                                <td data-label="Action"><span class="badge badge-info">${escapeHtml(l.action)}</span></td>
                                <td data-label="Details">${escapeHtml(l.details || '—')}</td>
                                <td data-label="IP Address" class="muted">${escapeHtml(l.ip_address || '—')}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;
        } catch (err) {
            wrap.innerHTML = `<div class="empty-state">${escapeHtml(err.message)}</div>`;
        }
    }

    // ---- Document Viewer & Detail Modal ----
    function setDocModalMobileTab(tabName) {
        $('#doc-modal-split').dataset.activePane = tabName;
        $$('.mobile-modal-tab').forEach((btn) => {
            btn.classList.toggle('active', btn.dataset.docTab === tabName);
        });
    }

    function getDocFiles(doc) {
        if (Array.isArray(doc.files) && doc.files.length) {
            return doc.files;
        }
        return [{
            id: 0,
            original_filename: doc.original_filename,
            file_size: doc.file_size,
            mime_type: doc.mime_type,
            file_ext: doc.file_ext,
            is_previewable: doc.is_previewable,
        }];
    }

    function buildFileUrl(docId, fileId, mode) {
        const fileParam = Number(fileId) > 0 ? `&file_id=${Number(fileId)}` : '';
        return `api/file.php?id=${docId}${fileParam}&mode=${mode}`;
    }

    function buildPreviewUrl(docId, fileId) {
        const fileParam = Number(fileId) > 0 ? `&file_id=${Number(fileId)}` : '';
        return `api/preview.php?id=${docId}${fileParam}`;
    }

    function renderTableHtml(rows, useFirstRowHeader = false) {
        if (!rows || !rows.length) {
            return '<div class="preview-empty-note">This sheet or file has no rows to display.</div>';
        }
        const maxCols = rows.reduce((m, r) => Math.max(m, r.length), 0);
        const startIdx = useFirstRowHeader && rows.length > 1 ? 1 : 0;
        const headerRow = useFirstRowHeader && rows.length > 1 ? rows[0] : null;

        const theadHtml = headerRow
            ? `<thead><tr><th class="row-num">#</th>${Array.from({ length: maxCols }, (_, c) => `<th>${escapeHtml(headerRow[c] ?? '')}</th>`).join('')}</tr></thead>`
            : `<thead><tr><th class="row-num">#</th>${Array.from({ length: maxCols }, (_, c) => {
                let col = '';
                let n = c + 1;
                while (n > 0) {
                    const rem = (n - 1) % 26;
                    col = String.fromCharCode(65 + rem) + col;
                    n = Math.floor((n - 1) / 26);
                }
                return `<th>${col}</th>`;
            }).join('')}</tr></thead>`;

        const tbodyHtml = rows.slice(startIdx).map((r, idx) => `
            <tr>
                <td class="row-num">${startIdx + idx + 1}</td>
                ${Array.from({ length: maxCols }, (_, c) => `<td>${escapeHtml(r[c] ?? '')}</td>`).join('')}
            </tr>
        `).join('');

        return `<div class="preview-table-scroll"><table class="preview-data-table">${theadHtml}<tbody>${tbodyHtml}</tbody></table></div>`;
    }

    function renderStructuredPreview(previewPane, preview, file, downloadUrl) {
        const kind = preview?.kind || 'unsupported';

        if (kind === 'html' && preview.html) {
            const iframe = document.createElement('iframe');
            iframe.setAttribute('sandbox', 'allow-popups');
            iframe.title = file.original_filename || 'Document Preview';
            iframe.srcdoc = preview.html;
            previewPane.innerHTML = '';
            previewPane.appendChild(iframe);
            return;
        }

        if (kind === 'sheets' && Array.isArray(preview.sheets) && preview.sheets.length) {
            const shell = document.createElement('div');
            shell.className = 'preview-doc-shell';

            const tabsBar = document.createElement('div');
            tabsBar.className = 'preview-sheet-tabs';

            const bodyWrap = document.createElement('div');
            bodyWrap.className = 'preview-doc-body';

            const renderSheet = (index) => {
                const sheet = preview.sheets[index] || preview.sheets[0];
                Array.from(tabsBar.children).forEach((btn, idx) => {
                    btn.classList.toggle('active', idx === index);
                });
                const truncNote = sheet.truncated
                    ? `<div class="preview-info-banner">Showing first ${sheet.rows.length} of ${sheet.total_rows} rows (max 40 columns). Download the workbook for full data.</div>`
                    : '';
                bodyWrap.innerHTML = truncNote + renderTableHtml(sheet.rows, false);
            };

            preview.sheets.forEach((s, idx) => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = `preview-sheet-tab ${idx === 0 ? 'active' : ''}`;
                btn.textContent = s.name || `Sheet ${idx + 1}`;
                btn.addEventListener('click', () => renderSheet(idx));
                tabsBar.appendChild(btn);
            });

            shell.appendChild(tabsBar);
            shell.appendChild(bodyWrap);
            previewPane.innerHTML = '';
            previewPane.appendChild(shell);
            renderSheet(0);
            return;
        }

        if (kind === 'table' && Array.isArray(preview.rows)) {
            const shell = document.createElement('div');
            shell.className = 'preview-doc-shell';
            const truncNote = preview.truncated
                ? `<div class="preview-info-banner">Showing ${preview.rows.length} of ${preview.total_rows} rows. Download the CSV for the complete file.</div>`
                : '';
            shell.innerHTML = `<div class="preview-doc-body">${truncNote}${renderTableHtml(preview.rows, true)}</div>`;
            previewPane.innerHTML = '';
            previewPane.appendChild(shell);
            return;
        }

        if (kind === 'slides' && Array.isArray(preview.slides) && preview.slides.length) {
            const shell = document.createElement('div');
            shell.className = 'preview-doc-shell';
            const slidesHtml = preview.slides.map((s) => {
                const blocksHtml = (s.blocks || []).map((b) => `<p>${escapeHtml(b)}</p>`).join('');
                const tablesHtml = (s.tables || []).map((tbl) => renderTableHtml(tbl, true)).join('');
                const emptySlide = !s.title && !blocksHtml && !tablesHtml
                    ? '<p class="muted">(No text content on this slide)</p>'
                    : '';
                return `
                    <div class="preview-slide-card">
                        <div class="preview-slide-header">
                            <span class="badge badge-info">Slide ${Number(s.number)}</span>
                            ${s.title ? `<h4>${escapeHtml(s.title)}</h4>` : ''}
                        </div>
                        <div class="preview-slide-body">
                            ${blocksHtml}
                            ${tablesHtml}
                            ${emptySlide}
                        </div>
                    </div>
                `;
            }).join('');
            shell.innerHTML = `<div class="preview-slides-list">${slidesHtml}</div>`;
            previewPane.innerHTML = '';
            previewPane.appendChild(shell);
            return;
        }

        if (kind === 'archive' && Array.isArray(preview.entries)) {
            const shell = document.createElement('div');
            shell.className = 'preview-doc-shell';
            const note = `<div class="preview-info-banner">ZIP Archive: ${preview.total_entries} item(s)${preview.truncated ? ' (showing first 500)' : ''}.</div>`;
            const rowsHtml = preview.entries.map((entry) => `
                <tr>
                    <td>${escapeHtml(entry.name)}</td>
                    <td>${entry.is_dir ? 'Folder' : 'File'}</td>
                    <td>${entry.is_dir ? '—' : escapeHtml(formatBytes(entry.size))}</td>
                    <td>${entry.is_dir ? '—' : escapeHtml(formatBytes(entry.compressed_size))}</td>
                    <td>${escapeHtml(entry.modified_at || '—')}</td>
                </tr>
            `).join('');
            shell.innerHTML = `
                <div class="preview-doc-body">
                    ${note}
                    <div class="preview-table-scroll">
                        <table class="preview-data-table">
                            <thead>
                                <tr>
                                    <th>Path</th>
                                    <th>Type</th>
                                    <th>Size</th>
                                    <th>Compressed</th>
                                    <th>Modified</th>
                                </tr>
                            </thead>
                            <tbody>${rowsHtml}</tbody>
                        </table>
                    </div>
                </div>
            `;
            previewPane.innerHTML = '';
            previewPane.appendChild(shell);
            return;
        }

        if (kind === 'text' && typeof preview.text === 'string') {
            const shell = document.createElement('div');
            shell.className = 'preview-doc-shell preview-doc-dark';
            if (preview.note) {
                const banner = document.createElement('div');
                banner.className = 'preview-info-banner';
                banner.textContent = preview.note;
                shell.appendChild(banner);
            }
            const pre = document.createElement('pre');
            pre.textContent = preview.text;
            shell.appendChild(pre);
            previewPane.innerHTML = '';
            previewPane.appendChild(shell);
            return;
        }

        const ext = (file.file_ext || '').toLowerCase();
        previewPane.innerHTML = `
            <div class="preview-fallback">
                <h4>${escapeHtml(preview?.message || `Inline preview unavailable for .${ext} files`)}</h4>
                <p>Download "${escapeHtml(file.original_filename)}" (${formatBytes(file.file_size)}) to open it on your device.</p>
                <a href="${downloadUrl}" class="btn btn-primary">Download File</a>
            </div>
        `;
    }

    async function renderModalAttachmentPreview(doc, file) {
        const previewPane = $('#modal-preview-pane');
        const activeId = Number(file.id || 0);
        state.currentModalFileId = activeId;

        const viewUrl = buildFileUrl(doc.id, activeId, 'view');
        const downloadUrl = buildFileUrl(doc.id, activeId, 'download');
        const ext = (file.file_ext || '').toLowerCase();
        const nativeInline = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'txt', 'csv'].includes(ext);

        const openBtn = $('#modal-open-btn');
        if (openBtn) {
            openBtn.href = viewUrl;
            openBtn.classList.toggle('hidden', !nativeInline);
        }
        $('#modal-download-btn').href = downloadUrl;

        $$('#modal-attachments-bar .attachment-pill').forEach((btn) => {
            btn.classList.toggle('active', Number(btn.dataset.fileId) === activeId);
        });

        if (ext === 'pdf') {
            previewPane.innerHTML = `<iframe src="${viewUrl}" title="${escapeHtml(file.original_filename)}"></iframe>`;
        } else if (['png', 'jpg', 'jpeg', 'gif', 'webp'].includes(ext)) {
            previewPane.innerHTML = `<img src="${viewUrl}" alt="${escapeHtml(file.original_filename)}">`;
        } else if (ext === 'txt') {
            previewPane.innerHTML = '<div class="preview-fallback">Loading preview...</div>';
            const res = await fetch(viewUrl, { credentials: 'same-origin' });
            const text = await res.text();
            if (state.currentModalDoc?.id !== doc.id || state.currentModalFileId !== activeId) return;
            const pre = document.createElement('pre');
            pre.textContent = text;
            previewPane.innerHTML = '';
            previewPane.appendChild(pre);
        } else if (['docx', 'xlsx', 'pptx', 'csv', 'zip', 'doc', 'xls', 'ppt'].includes(ext)) {
            previewPane.innerHTML = '<div class="preview-fallback">Generating document preview...</div>';
            try {
                const data = await apiFetch(buildPreviewUrl(doc.id, activeId));
                if (state.currentModalDoc?.id !== doc.id || state.currentModalFileId !== activeId) return;
                renderStructuredPreview(previewPane, data.preview, file, downloadUrl);
            } catch (err) {
                if (state.currentModalDoc?.id !== doc.id || state.currentModalFileId !== activeId) return;
                previewPane.innerHTML = `
                    <div class="preview-fallback">
                        <h4>Unable to render preview</h4>
                        <p>${escapeHtml(err.message || 'Please download the file to view it.')}</p>
                        <a href="${downloadUrl}" class="btn btn-primary">Download File</a>
                    </div>
                `;
            }
        } else {
            previewPane.innerHTML = `
                <div class="preview-fallback">
                    <h4>Inline preview unavailable for .${escapeHtml(ext)} files</h4>
                    <p>Download "${escapeHtml(file.original_filename)}" (${formatBytes(file.file_size)}) to open it on your device.</p>
                    <a href="${downloadUrl}" class="btn btn-primary">Download File</a>
                </div>
            `;
        }
    }

    async function openDocumentModal(docId) {
        const modal = $('#doc-modal');
        const previewPane = $('#modal-preview-pane');
        const metaPane = $('#modal-meta-pane');
        const attachBar = $('#modal-attachments-bar');

        setDocModalMobileTab('preview');
        attachBar?.classList.add('hidden');
        if (attachBar) attachBar.innerHTML = '';
        previewPane.innerHTML = '<div class="preview-fallback">Loading preview...</div>';
        metaPane.innerHTML = '<div class="muted">Loading document details...</div>';
        modal.classList.remove('hidden');

        try {
            const data = await apiFetch(`api/documents.php?action=detail&id=${docId}`);
            const doc = data.document;
            const files = getDocFiles(doc);
            state.currentModalDocId = doc.id;
            state.currentModalDoc = doc;

            $('#modal-doc-code').textContent = doc.doc_code;
            $('#modal-doc-title').textContent = doc.title;

            const canEdit = (Number(doc.sender_id) === Number(state.user?.id) && doc.status !== 'archived')
                || state.user?.role === 'admin';
            $('#modal-edit-btn')?.classList.toggle('hidden', !canEdit);

            if (attachBar && files.length > 1) {
                attachBar.innerHTML = files.map((f, idx) => `
                    <button type="button" class="attachment-pill ${idx === 0 ? 'active' : ''}" data-file-id="${Number(f.id || 0)}">
                        <span>${idx + 1}. ${escapeHtml(f.original_filename)}</span>
                        <small>${formatBytes(f.file_size)}</small>
                    </button>
                `).join('');
                attachBar.classList.remove('hidden');
            }

            await renderModalAttachmentPreview(doc, files[0]);

            // Ensure mark_read happens even if the first attachment is non-previewable
            if (!files[0].is_previewable) {
                await apiFetch('api/documents.php?action=mark_read', {
                    method: 'POST',
                    json: { document_id: doc.id },
                }).catch(() => {});
            }

            const recipientsHtml = (doc.recipients || []).map((r) => `
                <div class="receipt-card">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
                        <strong>${escapeHtml(r.full_name)}</strong>
                        <span class="badge ${Number(r.is_read) === 1 ? 'badge-success' : 'badge-urgent'}">
                            ${Number(r.is_read) === 1 ? 'Read' : 'Unread'}
                        </span>
                    </div>
                    <div class="muted">@${escapeHtml(r.username)} &bull; ${escapeHtml(r.department)}</div>
                    <div class="muted" style="margin-top:4px">
                        Views: ${r.viewed_count} &bull; Downloads: ${r.downloaded_count}
                        ${r.last_downloaded_at ? `<br>Last downloaded: ${escapeHtml(formatDate(r.last_downloaded_at))}` : ''}
                    </div>
                </div>
            `).join('');

            const attachmentsMetaHtml = files.map((f, idx) => `
                <div class="file-chip-row">
                    <div class="file-chip-main">
                        <span class="file-chip-name" title="${escapeHtml(f.original_filename)}">${idx + 1}. ${escapeHtml(f.original_filename)}</span>
                        <span class="file-chip-meta">${formatBytes(f.file_size)}</span>
                    </div>
                    <div class="file-chip-actions">
                        <button type="button" class="btn btn-outline btn-xs" data-preview-attachment="${Number(f.id || 0)}">Preview</button>
                        <a href="${buildFileUrl(doc.id, f.id, 'download')}" class="btn btn-primary btn-xs">Download</a>
                    </div>
                </div>
            `).join('');

            metaPane.innerHTML = `
                <div class="meta-list">
                    <div class="meta-row"><span>${files.length > 1 ? 'Primary File' : 'File Name'}</span><strong>${escapeHtml(doc.original_filename)}</strong></div>
                    <div class="meta-row"><span>Attachments</span><span>${files.length} file(s) &bull; ${formatBytes(doc.file_size)} total</span></div>
                    <div class="meta-row"><span>Category</span><span>${escapeHtml(doc.category)}</span></div>
                    <div class="meta-row"><span>Priority</span><span class="badge badge-${escapeHtml(doc.priority)}">${escapeHtml(doc.priority)}</span></div>
                    <div class="meta-row"><span>Status</span><span class="badge badge-${escapeHtml(doc.status)}">${escapeHtml(doc.status)}</span></div>
                    <div class="meta-row"><span>Uploaded By</span><span>${escapeHtml(doc.sender_name)} (${escapeHtml(doc.sender_department)})</span></div>
                    <div class="meta-row"><span>Sent Date</span><span>${escapeHtml(formatDate(doc.created_at))}</span></div>
                </div>
                <div style="margin-bottom:16px">
                    <strong style="font-size:12.5px">Attached Files (${files.length})</strong>
                    <div class="selected-files-list" style="margin-top:6px">${attachmentsMetaHtml}</div>
                </div>
                ${doc.description ? `<div style="margin-bottom:16px"><strong style="font-size:12.5px">Notes</strong><p class="muted" style="margin-top:4px;white-space:pre-wrap">${escapeHtml(doc.description)}</p></div>` : ''}
                <div>
                    <strong style="font-size:12.5px">Delivery &amp; Read Receipts</strong>
                    <div class="receipt-list">${recipientsHtml || '<div class="muted">No recipients</div>'}</div>
                </div>
            `;

            pollNotifications();
            if (state.currentView === 'inbox') loadInbox();
        } catch (err) {
            previewPane.innerHTML = `<div class="preview-fallback"><p>${escapeHtml(err.message)}</p></div>`;
        }
    }

    // ---- Edit / Replace Document Modal ----
    function renderEditFiles() {
        const existingWrap = $('#edit-existing-files');
        const newWrap = $('#edit-new-files');
        const dropText = $('#edit-drop-zone-text');

        const keptCount = state.editExistingFiles.filter((f) => !state.editRemoveFileIds.has(Number(f.id))).length;
        const totalRemaining = keptCount + state.editNewFiles.length;

        if (existingWrap) {
            existingWrap.innerHTML = state.editExistingFiles.length
                ? `
                    <div class="selected-files-summary">Current attachments (${keptCount} kept${state.editRemoveFileIds.size ? `, ${state.editRemoveFileIds.size} marked for removal` : ''})</div>
                    ${state.editExistingFiles.map((f, idx) => {
                        const fid = Number(f.id);
                        const markedRemoved = state.editRemoveFileIds.has(fid);
                        return `
                            <div class="file-chip-row ${markedRemoved ? 'is-removed' : ''}">
                                <div class="file-chip-main">
                                    <span class="file-chip-name" title="${escapeHtml(f.original_filename)}">${idx + 1}. ${escapeHtml(f.original_filename)}</span>
                                    <span class="file-chip-meta">${formatBytes(f.file_size)}</span>
                                </div>
                                <div class="file-chip-actions">
                                    <button type="button" class="btn ${markedRemoved ? 'btn-outline' : 'btn-danger'} btn-xs" data-toggle-remove-file="${fid}">
                                        ${markedRemoved ? 'Undo' : 'Remove'}
                                    </button>
                                </div>
                            </div>
                        `;
                    }).join('')}
                `
                : '<div class="muted">No existing files recorded.</div>';
        }

        if (newWrap) {
            if (state.editNewFiles.length) {
                const newBytes = state.editNewFiles.reduce((sum, f) => sum + (f.size || 0), 0);
                newWrap.classList.remove('hidden');
                newWrap.innerHTML = `
                    <div class="selected-files-summary">New files to add (${state.editNewFiles.length} &bull; ${formatBytes(newBytes)})</div>
                    ${state.editNewFiles.map((f, idx) => `
                        <div class="file-chip-row">
                            <div class="file-chip-main">
                                <span class="file-chip-name" title="${escapeHtml(f.name)}">+ ${escapeHtml(f.name)}</span>
                                <span class="file-chip-meta">${formatBytes(f.size)}</span>
                            </div>
                            <div class="file-chip-actions">
                                <button type="button" class="btn btn-outline btn-xs" data-remove-edit-new="${idx}">Remove</button>
                            </div>
                        </div>
                    `).join('')}
                `;
            } else {
                newWrap.classList.add('hidden');
                newWrap.innerHTML = '';
            }
        }

        if (dropText) {
            dropText.innerHTML = state.editNewFiles.length
                ? `<strong>${state.editNewFiles.length} new file(s) queued</strong> (${totalRemaining} total after save) &mdash; click or drop to add more`
                : '<strong>Click to add more files</strong> or drag &amp; drop (keep at least 1 attachment)';
        }
    }

    function addEditFiles(fileList) {
        if (!fileList || !fileList.length) return;
        dedupeAppendFiles(state.editNewFiles, fileList);
        const input = $('#edit-doc-file');
        if (input) input.value = '';
        renderEditFiles();
    }

    function renderEditRecipients() {
        const query = ($('#edit-rec-search').value || '').trim().toLowerCase();
        const filtered = state.recipients.filter((r) =>
            r.full_name.toLowerCase().includes(query) ||
            r.username.toLowerCase().includes(query) ||
            r.department.toLowerCase().includes(query)
        );
        const wrap = $('#edit-recipient-list');
        if (!filtered.length) {
            wrap.innerHTML = '<div class="muted">No matching recipients found.</div>';
            $('#edit-rec-count').textContent = `${state.editSelectedRecipientIds.size} selected`;
            return;
        }
        wrap.innerHTML = filtered.map((r) => `
            <label class="recipient-chip">
                <input type="checkbox" name="edit_recipient_id" value="${r.id}" ${state.editSelectedRecipientIds.has(String(r.id)) ? 'checked' : ''}>
                <div class="recipient-chip-meta">
                    <strong>${escapeHtml(r.full_name)}</strong>
                    <span>@${escapeHtml(r.username)} &bull; ${escapeHtml(r.department)}</span>
                </div>
            </label>
        `).join('');
        $('#edit-rec-count').textContent = `${state.editSelectedRecipientIds.size} selected`;
    }

    async function openEditDocModal(docId) {
        const modal = $('#edit-doc-modal');
        state.editExistingFiles = [];
        state.editRemoveFileIds = new Set();
        state.editNewFiles = [];
        $('#edit-doc-file').value = '';
        $('#edit-rec-search').value = '';
        renderEditFiles();
        modal.classList.remove('hidden');

        try {
            const [detailRes, recRes] = await Promise.all([
                apiFetch(`api/documents.php?action=detail&id=${docId}`),
                apiFetch('api/users.php?action=recipients'),
            ]);
            const doc = detailRes.document;
            state.recipients = recRes.recipients || [];
            state.editExistingFiles = getDocFiles(doc);
            state.editSelectedRecipientIds = new Set(
                (doc.recipients || []).map((r) => String(r.recipient_id))
            );

            $('#edit-doc-id').value = doc.id;
            $('#edit-doc-code').textContent = doc.doc_code;
            $('#edit-doc-title').value = doc.title || '';
            $('#edit-doc-category').value = doc.category || 'Memo';
            $('#edit-doc-priority').value = doc.priority || 'normal';
            $('#edit-doc-desc').value = doc.description || '';

            renderEditFiles();
            renderEditRecipients();
        } catch (err) {
            modal.classList.add('hidden');
            toast(err.message, 'error');
        }
    }

    // ---- Mobile App & Network Access Modal ----
    async function openMobileAccessModal() {
        const modal = $('#mobile-access-modal');
        const urlsList = $('#lan-urls-list');
        const tokensList = $('#active-tokens-list');
        const tokenBox = $('#generated-token-box');
        const installNote = $('#modal-install-note');

        tokenBox.classList.add('hidden');
        tokenBox.innerHTML = '';
        urlsList.innerHTML = '<div class="muted">Detecting network URLs...</div>';
        tokensList.innerHTML = '<div class="muted">Loading active API tokens...</div>';

        const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
        if (isStandalone) {
            installNote.textContent = 'Running in installed app mode.';
        } else if (state.deferredPrompt) {
            $('#modal-install-app-btn').classList.remove('hidden');
            installNote.textContent = '';
        } else if (/iphone|ipad|ipod/i.test(navigator.userAgent)) {
            installNote.textContent = 'iOS Safari: tap Share -> Add to Home Screen.';
        } else {
            installNote.textContent = 'Use browser menu -> Install App / Add to Home Screen.';
        }

        modal.classList.remove('hidden');
        setSidebarOpen(false);

        try {
            const data = await apiFetch('api/auth.php?action=connection_info');
            const urls = Array.from(new Set([data.primary_url, ...(data.lan_urls || []), data.current_url].filter(Boolean)));
            urlsList.innerHTML = urls.map((u, idx) => `
                <div class="url-item">
                    <div>
                        ${idx === 0 ? '<div class="muted" style="font-size:11px;font-weight:700;text-transform:uppercase">Recommended Phone URL</div>' : ''}
                        <code>${escapeHtml(u)}</code>
                    </div>
                    <button type="button" class="btn btn-outline btn-xs" data-copy-text="${escapeHtml(u)}">Copy</button>
                </div>
            `).join('');

            renderActiveTokens(data.tokens || []);
        } catch (err) {
            urlsList.innerHTML = `<div class="muted">${escapeHtml(err.message)}</div>`;
            tokensList.innerHTML = '';
        }
    }

    function renderActiveTokens(tokens) {
        const list = $('#active-tokens-list');
        if (!tokens.length) {
            list.innerHTML = '<div class="muted">No active API tokens yet.</div>';
            return;
        }
        list.innerHTML = tokens.map((t) => `
            <div class="receipt-card" style="display:flex;justify-content:space-between;align-items:center;gap:10px">
                <div>
                    <strong>${escapeHtml(t.device_name)}</strong>
                    <div class="muted">
                        Expires: ${escapeHtml(formatDate(t.expires_at))}
                        ${t.last_used_at ? ` &bull; Last used: ${escapeHtml(formatDate(t.last_used_at))}` : ''}
                    </div>
                </div>
                <button type="button" class="btn btn-danger btn-xs" data-revoke-token="${t.id}">Revoke</button>
            </div>
        `).join('');
    }

    // ---- Events ----
    function bindEvents() {
        // Auth tabs
        $$('.auth-tab').forEach((tab) => {
            tab.addEventListener('click', () => {
                $$('.auth-tab').forEach((t) => t.classList.toggle('active', t === tab));
                const mode = tab.dataset.authTab;
                $('#login-form').classList.toggle('hidden', mode !== 'login');
                $('#register-form').classList.toggle('hidden', mode !== 'register');
            });
        });

        // Login submit
        $('#login-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            try {
                const data = await apiFetch('api/auth.php?action=login', {
                    method: 'POST',
                    json: {
                        username: $('#login-username').value.trim(),
                        password: $('#login-password').value,
                    },
                });
                state.csrfToken = data.csrf_token;
                toast(`Welcome back, ${data.user.full_name}!`, 'success');
                onAuthenticated(data.user);
            } catch (err) {
                toast(err.message, 'error');
            }
        });

        // Register submit
        $('#register-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            try {
                const data = await apiFetch('api/auth.php?action=register', {
                    method: 'POST',
                    json: {
                        full_name: $('#reg-fullname').value.trim(),
                        username: $('#reg-username').value.trim(),
                        department: $('#reg-dept').value.trim(),
                        email: $('#reg-email').value.trim(),
                        password: $('#reg-password').value,
                    },
                });
                state.csrfToken = data.csrf_token;
                toast('Account created successfully!', 'success');
                onAuthenticated(data.user);
            } catch (err) {
                toast(err.message, 'error');
            }
        });

        // Logout
        $('#btn-logout').addEventListener('click', async () => {
            try {
                await apiFetch('api/auth.php?action=logout', { method: 'POST', json: {} });
            } catch {
                // ignore
            }
            showAuthScreen();
        });

        // Sidebar & Mobile Bottom navigation
        $$('.nav-item').forEach((btn) => {
            btn.addEventListener('click', () => switchView(btn.dataset.view));
        });
        $$('.bottom-nav-item[data-view]').forEach((btn) => {
            btn.addEventListener('click', () => switchView(btn.dataset.view));
        });

        $('#topbar-send-btn').addEventListener('click', () => switchView('send'));
        $('#sidebar-toggle').addEventListener('click', () => setSidebarOpen(true));
        $('#bottom-menu-btn').addEventListener('click', () => setSidebarOpen(true));
        $('#sidebar-close').addEventListener('click', () => setSidebarOpen(false));
        $('#sidebar-backdrop').addEventListener('click', () => setSidebarOpen(false));

        // PWA Install & Mobile Access Modal
        $('#auth-install-btn')?.addEventListener('click', triggerPwaInstall);
        $('#btn-install-app')?.addEventListener('click', triggerPwaInstall);
        $('#modal-install-app-btn')?.addEventListener('click', triggerPwaInstall);
        $('#btn-mobile-access')?.addEventListener('click', openMobileAccessModal);

        $$('.mobile-modal-tab').forEach((tab) => {
            tab.addEventListener('click', () => setDocModalMobileTab(tab.dataset.docTab));
        });

        $('#modal-attachments-bar')?.addEventListener('click', (e) => {
            const pill = e.target.closest('.attachment-pill');
            if (!pill || !state.currentModalDoc) return;
            const fid = Number(pill.dataset.fileId);
            const file = getDocFiles(state.currentModalDoc).find((f) => Number(f.id || 0) === fid);
            if (file) renderModalAttachmentPreview(state.currentModalDoc, file);
        });

        $('#modal-meta-pane')?.addEventListener('click', (e) => {
            const previewBtn = e.target.closest('[data-preview-attachment]');
            if (!previewBtn || !state.currentModalDoc) return;
            const fid = Number(previewBtn.dataset.previewAttachment);
            const file = getDocFiles(state.currentModalDoc).find((f) => Number(f.id || 0) === fid);
            if (file) {
                renderModalAttachmentPreview(state.currentModalDoc, file);
                setDocModalMobileTab('preview');
            }
        });

        $('#modal-edit-btn')?.addEventListener('click', () => {
            if (!state.currentModalDocId) return;
            $('#doc-modal').classList.add('hidden');
            $('#modal-preview-pane').innerHTML = '';
            openEditDocModal(state.currentModalDocId);
        });

        const editDropZone = $('#edit-drop-zone');
        const editFileInput = $('#edit-doc-file');
        editDropZone?.addEventListener('click', () => editFileInput.click());
        editFileInput?.addEventListener('change', () => {
            if (editFileInput.files.length) addEditFiles(editFileInput.files);
        });
        editDropZone?.addEventListener('dragover', (e) => {
            e.preventDefault();
            editDropZone.classList.add('drag-over');
        });
        editDropZone?.addEventListener('dragleave', () => editDropZone.classList.remove('drag-over'));
        editDropZone?.addEventListener('drop', (e) => {
            e.preventDefault();
            editDropZone.classList.remove('drag-over');
            if (e.dataTransfer.files.length) addEditFiles(e.dataTransfer.files);
        });

        $('#edit-existing-files')?.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-toggle-remove-file]');
            if (!btn) return;
            const fid = Number(btn.dataset.toggleRemoveFile);
            if (state.editRemoveFileIds.has(fid)) {
                state.editRemoveFileIds.delete(fid);
            } else {
                const keptCount = state.editExistingFiles.filter((f) => !state.editRemoveFileIds.has(Number(f.id))).length;
                if (keptCount + state.editNewFiles.length <= 1) {
                    toast('A document must keep at least one attached file. Add another file before removing this one.', 'error');
                    return;
                }
                state.editRemoveFileIds.add(fid);
            }
            renderEditFiles();
        });

        $('#edit-new-files')?.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-remove-edit-new]');
            if (!btn) return;
            const idx = Number(btn.dataset.removeEditNew);
            if (idx >= 0 && idx < state.editNewFiles.length) {
                state.editNewFiles.splice(idx, 1);
                renderEditFiles();
            }
        });

        $('#edit-rec-search')?.addEventListener('input', renderEditRecipients);
        $('#edit-recipient-list')?.addEventListener('change', (e) => {
            const cb = e.target.closest('input[name="edit_recipient_id"]');
            if (!cb) return;
            if (cb.checked) state.editSelectedRecipientIds.add(cb.value);
            else state.editSelectedRecipientIds.delete(cb.value);
            $('#edit-rec-count').textContent = `${state.editSelectedRecipientIds.size} selected`;
        });
        $('#edit-rec-select-all')?.addEventListener('click', () => {
            $$('input[name="edit_recipient_id"]').forEach((cb) => {
                cb.checked = true;
                state.editSelectedRecipientIds.add(cb.value);
            });
            $('#edit-rec-count').textContent = `${state.editSelectedRecipientIds.size} selected`;
        });
        $('#edit-rec-clear-all')?.addEventListener('click', () => {
            $$('input[name="edit_recipient_id"]').forEach((cb) => {
                cb.checked = false;
                state.editSelectedRecipientIds.delete(cb.value);
            });
            $('#edit-rec-count').textContent = `${state.editSelectedRecipientIds.size} selected`;
        });

        $('#edit-doc-form')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const docId = Number($('#edit-doc-id').value);
            if (!state.editSelectedRecipientIds.size) {
                toast('Please select at least one recipient.', 'error');
                return;
            }
            const keptCount = state.editExistingFiles.filter((f) => !state.editRemoveFileIds.has(Number(f.id))).length;
            if (keptCount + state.editNewFiles.length < 1) {
                toast('Please keep at least one attached file.', 'error');
                return;
            }
            const btn = $('#edit-doc-submit-btn');
            btn.disabled = true;
            btn.textContent = (state.editNewFiles.length || state.editRemoveFileIds.size)
                ? 'Updating Attachments...'
                : 'Saving Changes...';

            try {
                const fd = new FormData();
                fd.append('document_id', String(docId));
                fd.append('title', $('#edit-doc-title').value.trim());
                fd.append('category', $('#edit-doc-category').value);
                fd.append('priority', $('#edit-doc-priority').value);
                fd.append('description', $('#edit-doc-desc').value.trim());
                state.editNewFiles.forEach((f) => fd.append('files[]', f));
                state.editRemoveFileIds.forEach((id) => fd.append('remove_file_ids[]', String(id)));
                state.editSelectedRecipientIds.forEach((id) => fd.append('recipients[]', id));

                const data = await apiFetch('api/documents.php?action=update', {
                    method: 'POST',
                    body: fd,
                });
                toast(data.message, 'success');
                $('#edit-doc-modal').classList.add('hidden');
                if (state.currentView === 'sent') loadSent();
                if (state.currentView === 'admin-docs') loadAdminDocs();
                if (state.currentView === 'dashboard') loadDashboard();
                pollNotifications();
            } catch (err) {
                toast(err.message, 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = 'Save Changes';
            }
        });

        $('#create-token-form')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const deviceName = ($('#token-device-name').value || '').trim() || 'Mobile App';
            try {
                const data = await apiFetch('api/auth.php?action=create_token', {
                    method: 'POST',
                    json: { device_name: deviceName, ttl_days: 30 },
                });
                const box = $('#generated-token-box');
                box.innerHTML = `
                    <div><strong>Token Created (${escapeHtml(data.device_name)})</strong> — copy it now, it will not be shown again:</div>
                    <code>${escapeHtml(data.token)}</code>
                    <button type="button" class="btn btn-outline btn-xs" data-copy-text="${escapeHtml(data.token)}">Copy Token</button>
                `;
                box.classList.remove('hidden');
                toast('API token created.', 'success');
                const info = await apiFetch('api/auth.php?action=connection_info');
                renderActiveTokens(info.tokens || []);
            } catch (err) {
                toast(err.message, 'error');
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                setSidebarOpen(false);
                $('#notif-dropdown').classList.add('hidden');
                $$('.modal').forEach((m) => {
                    if (!m.classList.contains('hidden')) {
                        m.classList.add('hidden');
                        if (m.id === 'doc-modal') $('#modal-preview-pane').innerHTML = '';
                    }
                });
            }
        });

        document.addEventListener('click', async (e) => {
            const copyBtn = e.target.closest('[data-copy-text]');
            if (copyBtn) {
                const txt = copyBtn.dataset.copyText || '';
                let copied = false;
                if (navigator.clipboard && window.isSecureContext) {
                    try {
                        await navigator.clipboard.writeText(txt);
                        copied = true;
                    } catch {
                        copied = false;
                    }
                }
                if (!copied) {
                    const ta = document.createElement('textarea');
                    ta.value = txt;
                    ta.style.position = 'fixed';
                    ta.style.opacity = '0';
                    document.body.appendChild(ta);
                    ta.select();
                    try {
                        copied = document.execCommand('copy');
                    } catch {
                        copied = false;
                    }
                    ta.remove();
                }
                toast(copied ? 'Copied to clipboard.' : 'Select and copy the text manually.', copied ? 'success' : 'info');
                return;
            }

            const revokeBtn = e.target.closest('[data-revoke-token]');
            if (revokeBtn) {
                try {
                    await apiFetch('api/auth.php?action=revoke_token', {
                        method: 'POST',
                        json: { token_id: Number(revokeBtn.dataset.revokeToken) },
                    });
                    toast('Token revoked.', 'success');
                    const info = await apiFetch('api/auth.php?action=connection_info');
                    renderActiveTokens(info.tokens || []);
                } catch (err) {
                    toast(err.message, 'error');
                }
                return;
            }

            const jumpBtn = e.target.closest('[data-jump]');
            if (jumpBtn) switchView(jumpBtn.dataset.jump);

            const openDocBtn = e.target.closest('[data-open-doc]');
            if (openDocBtn) openDocumentModal(Number(openDocBtn.dataset.openDoc));

            const editDocBtn = e.target.closest('[data-edit-doc]');
            if (editDocBtn) openEditDocModal(Number(editDocBtn.dataset.editDoc));

            const delSentBtn = e.target.closest('[data-delete-sent]');
            if (delSentBtn) {
                const docId = Number(delSentBtn.dataset.deleteSent);
                const code = delSentBtn.dataset.code || '';
                if (!window.confirm(`Permanently delete ${code} and remove it for all recipients? This cannot be undone.`)) {
                    return;
                }
                try {
                    const data = await apiFetch('api/documents.php?action=delete', {
                        method: 'POST',
                        json: { document_id: docId },
                    });
                    toast(data.message, 'success');
                    loadSent();
                    pollNotifications();
                } catch (err) {
                    toast(err.message, 'error');
                }
                return;
            }

            const notifEl = e.target.closest('[data-notif-id]');
            if (notifEl) handleNotificationClick(notifEl.dataset.notifId, notifEl.dataset.docId);

            const closeModalEl = e.target.closest('[data-close-modal]');
            if (closeModalEl) {
                const targetModal = document.getElementById(closeModalEl.dataset.closeModal);
                if (targetModal) {
                    targetModal.classList.add('hidden');
                    if (targetModal.id === 'doc-modal') {
                        $('#modal-preview-pane').innerHTML = '';
                    }
                }
            }

            if (!e.target.closest('.notif-wrapper')) {
                $('#notif-dropdown').classList.add('hidden');
            }
        });

        // Notification bell dropdown
        updateNotifSoundToggleUI();
        ['pointerdown', 'click', 'keydown', 'touchstart'].forEach((evt) => {
            document.addEventListener(evt, unlockNotificationAudio, { passive: true });
        });

        $('#notif-sound-toggle')?.addEventListener('click', (e) => {
            e.stopPropagation();
            const nextEnabled = !isNotifSoundEnabled();
            localStorage.setItem(NOTIF_SOUND_STORAGE_KEY, nextEnabled ? '1' : '0');
            updateNotifSoundToggleUI();
            if (nextEnabled) {
                playNotificationSound(true);
                toast('Notification sound enabled.', 'info');
            } else {
                toast('Notification sound muted.', 'info');
            }
        });

        $('#notif-bell-btn').addEventListener('click', (e) => {
            e.stopPropagation();
            $('#notif-dropdown').classList.toggle('hidden');
        });

        $('#dropdown-view-all').addEventListener('click', () => {
            $('#notif-dropdown').classList.add('hidden');
            switchView('notifications');
        });

        const markAllReadHandler = async () => {
            try {
                await apiFetch('api/notifications.php?action=mark_all_read', { method: 'POST', json: {} });
                pollNotifications();
                if (state.currentView === 'notifications') loadNotificationsView();
                toast('All notifications marked as read.', 'success');
            } catch (err) {
                toast(err.message, 'error');
            }
        };
        $('#dropdown-mark-all').addEventListener('click', markAllReadHandler);
        $('#notifs-mark-all').addEventListener('click', markAllReadHandler);

        // Drop zone & multi-file upload
        const dropZone = $('#drop-zone');
        const fileInput = $('#doc-file');

        dropZone.addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', () => {
            if (fileInput.files.length) addSelectedFiles(fileInput.files);
        });
        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('drag-over');
        });
        dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('drag-over');
            if (e.dataTransfer.files.length) {
                addSelectedFiles(e.dataTransfer.files);
            }
        });

        $('#selected-files-list')?.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-remove-send-file]');
            if (!btn) return;
            const idx = Number(btn.dataset.removeSendFile);
            if (idx >= 0 && idx < state.selectedFiles.length) {
                state.selectedFiles.splice(idx, 1);
                renderSelectedFiles();
            }
        });
        $('#clear-files-btn')?.addEventListener('click', clearSelectedFiles);

        $('#rec-search').addEventListener('input', renderRecipientCheckboxes);
        $('#recipient-list').addEventListener('change', updateSelectedRecipientCount);
        $('#rec-select-all').addEventListener('click', () => {
            $$('input[name="recipient_id"]').forEach((cb) => { cb.checked = true; });
            updateSelectedRecipientCount();
        });
        $('#rec-clear-all').addEventListener('click', () => {
            $$('input[name="recipient_id"]').forEach((cb) => { cb.checked = false; });
            updateSelectedRecipientCount();
        });

        $('#upload-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            if (!state.selectedFiles.length) {
                toast('Please attach at least one file before sending.', 'error');
                return;
            }
            const selectedRecs = $$('input[name="recipient_id"]:checked').map((i) => i.value);
            if (!selectedRecs.length) {
                toast('Please select at least one recipient.', 'error');
                return;
            }

            const btn = $('#upload-submit-btn');
            btn.disabled = true;
            btn.textContent = `Uploading ${state.selectedFiles.length} file(s)...`;

            try {
                const fd = new FormData();
                fd.append('title', $('#doc-title').value.trim());
                fd.append('category', $('#doc-category').value);
                fd.append('priority', $('#doc-priority').value);
                fd.append('description', $('#doc-desc').value.trim());
                state.selectedFiles.forEach((f) => fd.append('files[]', f));
                selectedRecs.forEach((id) => fd.append('recipients[]', id));

                const data = await apiFetch('api/documents.php?action=upload', {
                    method: 'POST',
                    body: fd,
                });

                let msg = `${data.doc_code} (${data.file_count || 1} file(s)) sent to ${data.recipient_count} recipient(s)!`;
                if (data.email_summary && data.email_summary.enabled) {
                    msg += ` Emails sent: ${data.email_summary.sent}, failed: ${data.email_summary.failed}.`;
                }
                toast(msg, data.email_summary?.failed > 0 ? 'info' : 'success');
                $('#upload-form').reset();
                clearSelectedFiles();
                $$('input[name="recipient_id"]').forEach((cb) => { cb.checked = false; });
                updateSelectedRecipientCount();
                switchView('sent');
            } catch (err) {
                toast(err.message, 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = 'Send Document Now';
            }
        });

        // Filters
        ['inbox-search', 'inbox-read-filter', 'inbox-cat-filter', 'inbox-priority-filter'].forEach((id) => {
            $(`#${id}`).addEventListener('input', loadInbox);
        });
        ['sent-search', 'sent-cat-filter'].forEach((id) => {
            $(`#${id}`).addEventListener('input', loadSent);
        });
        ['admin-docs-search', 'admin-docs-status', 'admin-docs-cat'].forEach((id) => {
            $(`#${id}`).addEventListener('input', loadAdminDocs);
        });
        $('#admin-logs-search').addEventListener('input', loadAdminLogs);
        $('#admin-logs-refresh').addEventListener('click', loadAdminLogs);

        // Refresh inbox after download click
        $('#inbox-table-wrap').addEventListener('click', (e) => {
            if (e.target.closest('[data-dl-doc]')) {
                setTimeout(() => {
                    loadInbox();
                    pollNotifications();
                }, 800);
            }
        });

        $('#modal-download-btn').addEventListener('click', () => {
            setTimeout(() => {
                pollNotifications();
                if (state.currentView === 'inbox') loadInbox();
            }, 800);
        });

        // Admin document actions
        $('#admin-docs-table-wrap').addEventListener('click', async (e) => {
            const statusBtn = e.target.closest('[data-admin-status]');
            if (statusBtn) {
                const docId = Number(statusBtn.dataset.adminStatus);
                const status = statusBtn.dataset.status;
                try {
                    const data = await apiFetch('api/documents.php?action=admin_status', {
                        method: 'POST',
                        json: { document_id: docId, status },
                    });
                    toast(data.message, 'success');
                    loadAdminDocs();
                } catch (err) {
                    toast(err.message, 'error');
                }
                return;
            }

            const delBtn = e.target.closest('[data-admin-delete]');
            if (delBtn) {
                const docId = Number(delBtn.dataset.adminDelete);
                const code = delBtn.dataset.code;
                if (!window.confirm(`Permanently delete ${code} and remove its file from storage? This cannot be undone.`)) {
                    return;
                }
                try {
                    const data = await apiFetch('api/documents.php?action=delete', {
                        method: 'POST',
                        json: { document_id: docId },
                    });
                    toast(data.message, 'success');
                    loadAdminDocs();
                } catch (err) {
                    toast(err.message, 'error');
                }
            }
        });

        // Admin user management
        $('#btn-open-create-user').addEventListener('click', () => {
            $('#create-user-form').reset();
            $('#user-modal').classList.remove('hidden');
        });

        $('#btn-open-delete-key-modal').addEventListener('click', openMasterDeleteKeyModal);

        $('#du-go-set-key-btn').addEventListener('click', () => {
            $('#delete-user-modal').classList.add('hidden');
            openMasterDeleteKeyModal();
        });

        $$('input[name="du-doc-action"]').forEach((radio) => {
            radio.addEventListener('change', syncDeleteDocActionUi);
        });

        $('#master-delete-key-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            try {
                const data = await apiFetch('api/users.php?action=set_delete_key', {
                    method: 'POST',
                    json: {
                        admin_password: $('#mdk-admin-password').value,
                        current_key: $('#mdk-current-key').value,
                        new_key: $('#mdk-new-key').value,
                        confirm_key: $('#mdk-confirm-key').value,
                    },
                });
                state.masterDeleteKeyConfigured = Boolean(data.master_delete_key_configured ?? true);
                updateDeleteKeyBadge();
                toast(data.message, 'success');
                $('#master-delete-key-modal').classList.add('hidden');
            } catch (err) {
                toast(err.message, 'error');
            }
        });

        $('#delete-user-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const docAction = document.querySelector('input[name="du-doc-action"]:checked')?.value || 'reassign';
            try {
                const data = await apiFetch('api/users.php?action=delete', {
                    method: 'POST',
                    json: {
                        user_id: Number($('#du-user-id').value),
                        security_key: $('#du-security-key').value,
                        document_action: docAction,
                        reassign_to_user_id: docAction === 'reassign' ? Number($('#du-reassign-user').value || 0) : 0,
                    },
                });
                toast(data.message, 'success');
                $('#delete-user-modal').classList.add('hidden');
                loadAdminUsers();
                loadRecipients();
            } catch (err) {
                toast(err.message, 'error');
            }
        });

        $('#create-user-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            try {
                const data = await apiFetch('api/users.php?action=create', {
                    method: 'POST',
                    json: {
                        full_name: $('#cu-fullname').value.trim(),
                        username: $('#cu-username').value.trim(),
                        role: $('#cu-role').value,
                        email: $('#cu-email').value.trim(),
                        department: $('#cu-dept').value.trim(),
                        password: $('#cu-password').value,
                    },
                });
                toast(data.message, 'success');
                $('#user-modal').classList.add('hidden');
                loadAdminUsers();
            } catch (err) {
                toast(err.message, 'error');
            }
        });

        $('#admin-users-table-wrap').addEventListener('click', async (e) => {
            const statusBtn = e.target.closest('[data-toggle-status]');
            if (statusBtn) {
                try {
                    await apiFetch('api/users.php?action=update', {
                        method: 'POST',
                        json: { user_id: Number(statusBtn.dataset.toggleStatus), status: statusBtn.dataset.next },
                    });
                    toast('User status updated.', 'success');
                    loadAdminUsers();
                } catch (err) {
                    toast(err.message, 'error');
                }
                return;
            }

            const roleBtn = e.target.closest('[data-toggle-role]');
            if (roleBtn) {
                try {
                    await apiFetch('api/users.php?action=update', {
                        method: 'POST',
                        json: { user_id: Number(roleBtn.dataset.toggleRole), role: roleBtn.dataset.next },
                    });
                    toast('User role updated.', 'success');
                    loadAdminUsers();
                } catch (err) {
                    toast(err.message, 'error');
                }
                return;
            }

            const pwBtn = e.target.closest('[data-reset-pw]');
            if (pwBtn) {
                const newPassword = window.prompt(`Enter new password for @${pwBtn.dataset.username} (min 6 chars):`);
                if (!newPassword) return;
                try {
                    await apiFetch('api/users.php?action=update', {
                        method: 'POST',
                        json: { user_id: Number(pwBtn.dataset.resetPw), new_password: newPassword },
                    });
                    toast('Password reset successfully.', 'success');
                } catch (err) {
                    toast(err.message, 'error');
                }
                return;
            }

            const emailBtn = e.target.closest('[data-edit-email]');
            if (emailBtn) {
                const newEmail = window.prompt(`Update email address for @${emailBtn.dataset.username}:`, emailBtn.dataset.email || '');
                if (!newEmail) return;
                const trimmedEmail = newEmail.trim();
                try {
                    await apiFetch('api/users.php?action=update', {
                        method: 'POST',
                        json: { user_id: Number(emailBtn.dataset.editEmail), email: trimmedEmail },
                    });
                    if (Number(emailBtn.dataset.editEmail) === Number(state.user?.id)) {
                        state.user.email = trimmedEmail;
                        renderSidebarProfile(state.user);
                    }
                    toast('User email updated.', 'success');
                    loadAdminUsers();
                } catch (err) {
                    toast(err.message, 'error');
                }
                return;
            }

            const deleteBtn = e.target.closest('[data-delete-user]');
            if (deleteBtn) {
                openDeleteUserModal(Number(deleteBtn.dataset.deleteUser));
            }
        });

        // Admin Gmail / Email Settings
        $('#email-settings-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            try {
                const data = await apiFetch('api/email.php?action=save', {
                    method: 'POST',
                    json: {
                        enabled: $('#smtp-enabled').checked,
                        smtp_username: $('#smtp-username').value.trim(),
                        smtp_password: $('#smtp-password').value,
                        from_name: $('#smtp-from-name').value.trim(),
                        app_base_url: $('#smtp-base-url').value.trim(),
                        smtp_host: $('#smtp-host').value.trim(),
                        smtp_port: Number($('#smtp-port').value) || 587,
                        encryption: $('#smtp-encryption').value,
                    },
                });
                toast(data.message, 'success');
                loadAdminEmail();
            } catch (err) {
                toast(err.message, 'error');
            }
        });

        $('#email-test-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = $('#btn-send-test-email');
            btn.disabled = true;
            btn.textContent = 'Sending...';
            try {
                const data = await apiFetch('api/email.php?action=test', {
                    method: 'POST',
                    json: { test_email: $('#smtp-test-email').value.trim() },
                });
                toast(data.message, 'success');
                loadAdminEmail();
            } catch (err) {
                toast(err.message, 'error');
                loadAdminEmail();
            } finally {
                btn.disabled = false;
                btn.textContent = 'Send Test Email';
            }
        });

        $('#btn-refresh-email-logs').addEventListener('click', loadAdminEmail);
    }

    initApp();
})();
