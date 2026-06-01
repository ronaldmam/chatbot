// ==UserScript==
// @name         Naldike Chatbot Marketplace Automation
// @namespace    http://tampermonkey.net/
// @version      1.9
// @description  Automate replies and manual dashboard sending for personal Facebook Marketplace & Messenger using Naldike Store local RAG Chatbot API.
// @author       Antigravity AI
// @match        https://*.facebook.com/messages/*
// @match        https://*.messenger.com/*
// @match        https://*.facebook.com/messages/t/*
// @grant        GM_xmlhttpRequest
// @connect      localhost
// @run-at       document-end
// ==/UserScript==

(function() {
    'use strict';

    // 1. Create and Inject floating widget UI for visual feedback
    let isMinimized = false;
    let logs = [];
    const maxLogs = 5;

    const cssStyles = `
        #naldike-widget-container {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 280px;
            background: rgba(15, 23, 42, 0.92);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(245, 158, 11, 0.4);
            border-radius: 12px;
            box-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.6), 0 8px 10px -6px rgba(0, 0, 0, 0.6);
            z-index: 2147483647;
            color: #f1f5f9;
            font-family: system-ui, -apple-system, sans-serif;
            font-size: 13px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            user-select: none;
        }
        #naldike-widget-container.minimized {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            cursor: pointer;
            justify-content: center;
            align-items: center;
            background: rgba(245, 158, 11, 0.95);
            border: 2px solid #ffffff;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.4);
        }
        #naldike-widget-header {
            background: rgba(30, 41, 59, 0.7);
            padding: 10px 14px;
            border-bottom: 1px solid rgba(245, 158, 11, 0.2);
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-weight: 600;
            color: #fbbf24;
            font-size: 12px;
            letter-spacing: 0.5px;
        }
        #naldike-widget-header span {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        #naldike-widget-pulse-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: #10b981; /* Default green */
            box-shadow: 0 0 8px #10b981;
            display: inline-block;
        }
        #naldike-widget-pulse-dot.pulse {
            animation: naldike-glow 1.5s infinite ease-in-out;
        }
        #naldike-widget-pulse-dot.scanning {
            background-color: #f59e0b;
            box-shadow: 0 0 8px #f59e0b;
        }
        #naldike-widget-pulse-dot.processing {
            background-color: #3b82f6;
            box-shadow: 0 0 8px #3b82f6;
        }
        #naldike-widget-pulse-dot.human {
            background-color: #ec4899;
            box-shadow: 0 0 8px #ec4899;
        }
        #naldike-widget-pulse-dot.error {
            background-color: #ef4444;
            box-shadow: 0 0 8px #ef4444;
        }
        @keyframes naldike-glow {
            0% { transform: scale(0.9); opacity: 0.6; }
            50% { transform: scale(1.1); opacity: 1; box-shadow: 0 0 14px currentColor; }
            100% { transform: scale(0.9); opacity: 0.6; }
        }
        #naldike-widget-body {
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .naldike-widget-info-row {
            display: flex;
            justify-content: space-between;
            line-height: 1.4;
            font-size: 12px;
        }
        .naldike-widget-info-label {
            color: #94a3b8;
        }
        .naldike-widget-info-value {
            font-weight: 500;
            color: #f8fafc;
            max-width: 160px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        #naldike-widget-logs {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 6px;
            padding: 6px 10px;
            max-height: 100px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-family: monospace;
            font-size: 10px;
            color: #cbd5e1;
        }
        .naldike-widget-log-line {
            line-height: 1.3;
            word-break: break-all;
        }
        .naldike-widget-log-time {
            color: #fbbf24;
            margin-right: 4px;
        }
        #naldike-widget-toggle {
            cursor: pointer;
            padding: 2px 6px;
            border-radius: 4px;
            background: rgba(255, 255, 255, 0.08);
            color: #94a3b8;
            font-size: 10px;
        }
        #naldike-widget-toggle:hover {
            background: rgba(255, 255, 255, 0.15);
            color: #f8fafc;
        }
        #naldike-widget-mini-icon {
            display: none;
            font-size: 24px;
            line-height: 1;
        }
    `;

    // Inject styles
    const styleEl = document.createElement('style');
    styleEl.innerHTML = cssStyles;
    document.head.appendChild(styleEl);

    // Build DOM structure
    const widget = document.createElement('div');
    widget.id = 'naldike-widget-container';
    
    const miniIcon = document.createElement('div');
    miniIcon.id = 'naldike-widget-mini-icon';
    miniIcon.innerHTML = '🤖';
    widget.appendChild(miniIcon);

    const fullContent = document.createElement('div');
    fullContent.id = 'naldike-widget-content';
    fullContent.innerHTML = `
        <div id="naldike-widget-header">
            <span>
                <div id="naldike-widget-pulse-dot" class="pulse"></div>
                Naldike Bot Automation
            </span>
            <div id="naldike-widget-toggle">Minimizar</div>
        </div>
        <div id="naldike-widget-body">
            <div class="naldike-widget-info-row">
                <span class="naldike-widget-info-label">Estado:</span>
                <span class="naldike-widget-info-value" id="naldike-widget-state">Inicializando...</span>
            </div>
            <div class="naldike-widget-info-row">
                <span class="naldike-widget-info-label">Chat Activo:</span>
                <span class="naldike-widget-info-value" id="naldike-widget-active-chat">Ninguno</span>
            </div>
            <div class="naldike-widget-info-row">
                <span class="naldike-widget-info-label">Canal:</span>
                <span class="naldike-widget-info-value" id="naldike-widget-channel">Facebook / Marketplace</span>
            </div>
            <div style="font-size: 11px; color: #94a3b8; margin-top: 4px; font-weight: 600;">Historial Reciente:</div>
            <div id="naldike-widget-logs"></div>
        </div>
    `;
    widget.appendChild(fullContent);
    document.body.appendChild(widget);

    // Event handlers for minimizing
    const btnToggle = widget.querySelector('#naldike-widget-toggle');
    btnToggle.addEventListener('click', (e) => {
        e.stopPropagation();
        toggleWidget(true);
    });

    widget.addEventListener('click', () => {
        if (isMinimized) {
            toggleWidget(false);
        }
    });

    function toggleWidget(minimize) {
        isMinimized = minimize;
        if (minimize) {
            widget.classList.add('minimized');
            fullContent.style.display = 'none';
            miniIcon.style.display = 'block';
        } else {
            widget.classList.remove('minimized');
            fullContent.style.display = 'block';
            miniIcon.style.display = 'none';
        }
    }

    function addLog(msg) {
        const time = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        logs.unshift({ time, msg });
        if (logs.length > maxLogs) logs.pop();

        const logsDiv = widget.querySelector('#naldike-widget-logs');
        if (logsDiv) {
            logsDiv.innerHTML = logs.map(l => `
                <div class="naldike-widget-log-line">
                    <span class="naldike-widget-log-time">[${l.time}]</span>${l.msg}
                </div>
            `).join('');
            logsDiv.scrollTop = 0;
        }
        console.log(`%c[Naldike Automation]%c ${msg}`, "color: #f59e0b; font-weight: bold;", "color: default;");
    }

    function updateWidget(stateText, statusType, activeChat = null) {
        const stateVal = widget.querySelector('#naldike-widget-state');
        if (stateVal) stateVal.innerText = stateText;

        if (activeChat) {
            const chatVal = widget.querySelector('#naldike-widget-active-chat');
            if (chatVal) chatVal.innerText = activeChat;
        }

        const dot = widget.querySelector('#naldike-widget-pulse-dot');
        if (dot) {
            dot.className = 'pulse'; // reset
            if (statusType === 'scanning') dot.classList.add('scanning');
            else if (statusType === 'processing') dot.classList.add('processing');
            else if (statusType === 'human') dot.classList.add('human');
            else if (statusType === 'error') dot.classList.add('error');
        }
    }

    addLog("Script cargado con éxito. Buscando hilos activos...");
    updateWidget("Buscando chats...", "scanning");

    // 2. Browser scraping automation logic
    //
    // KEY DESIGN RULE:
    // The bot ONLY sends a reply when scanUnreadChats() detected and auto-clicked a
    // chat that had the unread indicator (blue dot). If the user navigates manually,
    // refreshes, or clicks a chat themselves, the bot stays completely silent.
    //
    const processedMsgMap = new Map(); // Map<convKey, lastMsgText> — per-chat dedup
    let autoOpenedKey = null;          // Set by scanUnreadChats when it clicks an unread chat
    let isProcessing = false;
    let lastUnreadClickTime = 0;
    const followUpTimerMap = new Map(); // Map<convKey, timeoutId> — 3-min no-reply timers

    // Periodic scanner to look for new incoming messages every 3 seconds
    setInterval(() => {
        if (isProcessing) return;
        scanActiveChat();
    }, 3000);

    // Periodic scanner to search for unread chats in the sidebar to auto-open them
    setInterval(() => {
        if (isProcessing) return;
        scanUnreadChats();
    }, 4000);

    // Periodic poller to look for pending manual manager replies from the dashboard
    setInterval(() => {
        if (isProcessing) return;
        pollPendingAgentMessages();
    }, 3000);

    function scanActiveChat() {
        // ── A. Extract the Facebook thread ID from the current URL ──────────────────
        // URL format: /messages/t/THREAD_ID
        // This numeric ID is also the real PSID used by the Facebook Graph API.
        let facebookThreadId = null;
        try {
            const pathParts = window.location.pathname.split('/').filter(Boolean);
            const tIdx = pathParts.indexOf('t');
            if (tIdx >= 0 && pathParts[tIdx + 1] && /^\d+$/.test(pathParts[tIdx + 1])) {
                facebookThreadId = pathParts[tIdx + 1];
            }
        } catch (err) {}

        const convKey = facebookThreadId || window.location.pathname;

        // ── B. Extract the customer name for display and API calls ────────────────
        let customerName = '';

        // Primary: match sidebar link by thread ID
        try {
            if (facebookThreadId) {
                const anchor = document.querySelector(`a[href*="/messages/t/${facebookThreadId}"]`);
                if (anchor) {
                    const firstLine = anchor.innerText.split('\n')[0].trim();
                    const parts = firstLine.split(/[·\-]/);
                    const candidate = parts[0].trim();
                    const bad = ['messenger','facebook','marketplace','mensajes','bandeja'];
                    if (candidate.length > 1 && candidate.length < 100 && !bad.some(w => candidate.toLowerCase().includes(w))) {
                        customerName = candidate;
                    }
                }
            }
        } catch (err) {}

        // Fallback: document title
        if (!customerName && document.title) {
            const titleClean = document.title.replace(/^\(\d+\)\s*/, '').split('|')[0].split(/[·\-]/)[0].trim();
            const bad = ['messenger','facebook','marketplace','mensajes','bandeja','inbox'];
            if (titleClean.length > 1 && titleClean.length < 50 && !bad.some(w => titleClean.toLowerCase().includes(w))) {
                customerName = titleClean;
            }
        }

        if (!customerName) {
            updateWidget('Buscando chats...', 'scanning', 'Ninguno');
            return;
        }

        updateWidget('Escaneando chat...', 'scanning', customerName);

        // ── C. Gate: only proceed if this chat was auto-opened by the unread scanner ──
        // If the user navigated here manually (or on page load), we only pre-populate
        // the map silently so the bot doesn't fire. We NEVER call the API in that case.
        const isAutoOpened = (autoOpenedKey === convKey);

        // ── D. Find the last customer message in the active chat ─────────────────
        // We query the last "incoming" message bubble by looking for the page's own
        // avatar being ABSENT from the message row (outgoing rows have the page photo).
        // We use a simple heuristic: find all message rows and pick the last one where
        // the row does NOT contain the page's own profile picture.
        const sidebar = document.querySelector('nav, [role="navigation"], aside');
        const mainRegion = document.querySelector('[role="main"]');
        if (!mainRegion) return;

        // Get all text bubbles inside the main region, excluding sidebar and link previews
        const textNodes = Array.from(mainRegion.querySelectorAll('div[dir="auto"], span[dir="auto"]')).filter(el => {
            const txt = el.innerText?.trim();
            if (!txt || txt.length < 1 || txt.length > 800) return false;
            if (el.closest('a[href]')) return false;
            if (el.closest('[role="heading"], h1, h2, h3, h4')) return false;
            if (sidebar && sidebar.contains(el)) return false;
            return true;
        });

        if (textNodes.length === 0) return;

        const lastNode = textNodes[textNodes.length - 1];
        const messageText = lastNode.innerText.trim();
        if (!messageText) return;

        if (processedMsgMap.get(convKey) === messageText) {
            return; 
        }

        if (!isAutoOpened) {
            if (followUpTimerMap.has(convKey)) {
                cancelFollowUpTimer(convKey);
            } else {
                processedMsgMap.set(convKey, messageText);
                return;
            }
        }

        let checkEl = lastNode.parentElement;
        let isOutgoing = false;
        for (let i = 0; i < 12 && checkEl && checkEl !== document.body; i++) {
            const st = window.getComputedStyle(checkEl);
            if (st.justifyContent === 'flex-end' || st.alignItems === 'flex-end') {
                isOutgoing = true; break;
            }
            const inlineStyle = checkEl.getAttribute ? (checkEl.getAttribute('style') || '') : '';
            if (inlineStyle.includes('flex-end')) { isOutgoing = true; break; }
            checkEl = checkEl.parentElement;
        }

        if (isOutgoing) {
            cancelFollowUpTimer(convKey);
            processedMsgMap.set(convKey, messageText);
            autoOpenedKey = null;
            updateWidget('Escaneando chat...', 'scanning');
            return;
        }

        let isMarketplace = 0;
        let marketplaceRef = null;
        const banners = mainRegion.querySelectorAll('div, span[role="heading"]');
        for (const b of banners) {
            const txt = b.innerText;
            if (txt && (txt.includes('Marketplace') || txt.includes('S/') || txt.includes('Artículo en venta'))) {
                isMarketplace = 1;
                marketplaceRef = txt.replace(/[\r\n]+/g, ' ').substring(0, 100);
                break;
            }
        }

        const effectivePsid = facebookThreadId || customerName.replace(/[^a-zA-Z0-9]/g, '_').toLowerCase();
        isProcessing = true;
        autoOpenedKey = null;
        processedMsgMap.set(convKey, messageText);

        updateWidget('Llamando a API...', 'processing');
        addLog(`Nuevo msg de ${customerName}: "${messageText.substring(0, 30)}${messageText.length > 30 ? '...' : ''}"`);

        GM_xmlhttpRequest({
            method: 'POST',
            url: 'http://localhost:8000/api/automation/message',
            data: JSON.stringify({
                psid: effectivePsid,
                customer_name: customerName,
                message_text: messageText,
                is_marketplace: isMarketplace,
                marketplace_ref: marketplaceRef
            }),
            headers: { 'Content-Type': 'application/json' },
            onload: function(response) {
                try {
                    if (response.status !== 200) throw new Error('HTTP ' + response.status);
                    const data = JSON.parse(response.responseText);

                    if (data.status === 'new_conversation') {
                        const name = data.customer_name || customerName;
                        const mktRef = data.marketplace_ref;
                        addLog(`[Saludo] Nueva conv con ${name}. Enviando bienvenida...`);
                        updateWidget('Enviando bienvenida...', 'processing', name);
                        sendWelcomeSequence(name, effectivePsid, customerName, mktRef, convKey);
                    } else if (data.status === 'automated_reply' && data.reply) {
                        addLog('API retornó respuesta automática. Enviando...');
                        updateWidget('Respondiendo...', 'processing');
                        sendReplyToChat(data.reply);
                    } else if (data.status === 'human_in_control') {
                        addLog('Gestor Humano en control. Bot silenciado.');
                        updateWidget('Gestor en control 👤', 'human');
                    } else {
                        addLog('API no generó respuesta automática.');
                        updateWidget('Escaneando chat...', 'scanning');
                    }
                } catch (e) {
                    addLog('Error API: ' + e.message);
                    updateWidget('Error de API 🔴', 'error');
                } finally {
                    isProcessing = false;
                }
            },
            onerror: function() {
                addLog('Error de conexión. ¿PHP server activo en localhost:8000?');
                updateWidget('Sin conexión a API 🔴', 'error');
                isProcessing = false;
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WELCOME SEQUENCE — sends the 3 warm greeting messages sequentially
    // and then starts the 3-minute follow-up timer.
    // ─────────────────────────────────────────────────────────────────────────
    function sendWelcomeSequence(name, psid, customerName, mktRef, convKey) {
        const DELAY = 900; // ms between messages

        const msg1 = `Hola ${name} 😊`;
        const msg2 = `Si tenemos el producto disponible 🛍️, tenemos tienda física y hacemos envíos`;
        const msg3 = `Te envío más detalles 📝 para que puedas realizar la compra 💰`;

        // Send messages one by one with delays
        sendReplyToChat(msg1);
        setTimeout(() => {
            sendReplyToChat(msg2);
            setTimeout(() => {
                sendReplyToChat(msg3);
                setTimeout(() => {
                    // Start the 3-minute follow-up timer
                    addLog(`[Timer] Iniciando timer 3 min para ${name}. Si no responde, envío info.`);
                    updateWidget('Esperando respuesta (3 min)...', 'scanning', name);

                    const timerId = setTimeout(() => {
                        triggerFollowUp(psid, customerName, mktRef, convKey);
                    }, 3 * 60 * 1000); // 3 minutes

                    followUpTimerMap.set(convKey, timerId);
                }, DELAY);
            }, DELAY);
        }, DELAY);
    }

    // Cancel a pending follow-up timer for a conversation
    function cancelFollowUpTimer(convKey) {
        if (followUpTimerMap.has(convKey)) {
            clearTimeout(followUpTimerMap.get(convKey));
            followUpTimerMap.delete(convKey);
            addLog(`[Timer] Timer de seguimiento cancelado (cliente respondió).`);
            updateWidget('Escaneando chat...', 'scanning');
        }
    }

    // Called when the 3-minute timer fires: sends address, schedule and product link as 3 separate messages
    function triggerFollowUp(psid, customerName, mktRef, convKey) {
        followUpTimerMap.delete(convKey);
        addLog(`[Timer] 3 min sin respuesta de ${customerName}. Enviando dirección, horario y link...`);
        updateWidget('Enviando seguimiento...', 'processing', customerName);

        GM_xmlhttpRequest({
            method: 'POST',
            url: 'http://localhost:8000/api/automation/followup',
            data: JSON.stringify({
                psid: psid,
                customer_name: customerName,
                marketplace_ref: mktRef
            }),
            headers: { 'Content-Type': 'application/json' },
            onload: function(response) {
                try {
                    const data = JSON.parse(response.responseText);
                    if (data.status === 'followup_reply') {
                        const DELAY = 900;
                        sendReplyToChat(data.address_msg);
                        setTimeout(() => {
                            sendReplyToChat(data.schedule_msg);
                            setTimeout(() => {
                                sendReplyToChat(data.product_msg);
                                addLog(`[Timer] Seguimiento enviado ✅ (dirección + horario + link)`);
                                updateWidget('Seguimiento enviado ✅', 'scanning');
                            }, DELAY);
                        }, DELAY);
                    }
                } catch(e) {
                    addLog('[Timer] Error al enviar seguimiento: ' + e.message);
                }
            },
            onerror: function() {
                addLog('[Timer] Error de conexión al enviar seguimiento.');
            }
        });
    }


    // Function to search for unread conversations in the sidebar and auto-click them
    function scanUnreadChats() {
        if (isProcessing) return;

        // Anti-loop cooling period (8 seconds after last auto-click)
        const now = Date.now();
        if (now - lastUnreadClickTime < 8000) return;

        const sidebarLinks = document.querySelectorAll('a[href*="/messages/t/"]');
        for (const link of sidebarLinks) {
            let isUnread = false;

            // Method 1: aria-label unread badge
            if (link.querySelector('[aria-label*="leído"], [aria-label*="leido"], [aria-label*="unread"], [aria-label*="no leído"]')) {
                isUnread = true;
            }

            // Method 2: blue dot color detector
            if (!isUnread) {
                for (const b of link.querySelectorAll('div, span')) {
                    const bg = window.getComputedStyle(b).backgroundColor;
                    if (bg && bg.startsWith('rgb')) {
                        const m = bg.match(/rgb\((\d+),\s*(\d+),\s*(\d+)\)/);
                        if (m) {
                            const r = +m[1], g = +m[2], bVal = +m[3];
                            if (bVal > 150 && bVal > r * 1.5 && bVal > g * 1.2) {
                                const w = parseInt(window.getComputedStyle(b).width);
                                const h = parseInt(window.getComputedStyle(b).height);
                                if (w > 4 && w < 24 && h > 4 && h < 24) { isUnread = true; break; }
                            }
                        }
                    }
                }
            }

            if (isUnread) {
                // Extract the thread ID from the link href to set autoOpenedKey
                const href = link.getAttribute('href') || '';
                const hrefParts = href.split('/').filter(Boolean);
                const tIdx = hrefParts.indexOf('t');
                const threadId = (tIdx >= 0 && hrefParts[tIdx + 1]) ? hrefParts[tIdx + 1] : null;

                const firstLine = link.innerText.split('\n')[0].trim();
                const cleanName = firstLine.split(/[·\-]/)[0].trim();

                addLog(`[Buzón] Chat no leído detectado: ${cleanName}. Abriendo...`);

                // Set the gate BEFORE clicking so scanActiveChat knows this was auto-opened
                autoOpenedKey = threadId || window.location.pathname;
                processedMsgMap.delete(autoOpenedKey); // clear any cached signature for fresh processing

                link.click();
                lastUnreadClickTime = now;
                break;
            }
        }
    }

    // Function to poll the local PHP server for pending manager replies typed in the dashboard
    function pollPendingAgentMessages() {
        // Poll globally for ANY pending manager replies in the database queue
        GM_xmlhttpRequest({
            method: "GET",
            url: "http://localhost:8000/api/automation/pending",
            onload: function(response) {
                try {
                    const data = JSON.parse(response.responseText);
                    if (data.status === 'success' && data.messages && data.messages.length > 0) {
                        // Take the first pending message
                        const firstMsg = data.messages[0];
                        const targetPsid = firstMsg.psid;
                        const targetName = firstMsg.customer_name || targetPsid;

                        // ── CRITICAL FIX: Get the currently-open thread's real ID from the URL ──
                        // We compare this with the pending message's psid to know if we're
                        // already in the right conversation.
                        let currentThreadId = null;
                        try {
                            let pathParts = window.location.pathname.split('/').filter(Boolean);
                            let tIndex = pathParts.indexOf('t');
                            if (tIndex >= 0 && pathParts[tIndex + 1] && /^\d+$/.test(pathParts[tIndex + 1])) {
                                currentThreadId = pathParts[tIndex + 1];
                            }
                        } catch(e) {}

                        if (currentThreadId && currentThreadId === targetPsid) {
                            // The target chat is already open! Send all pending messages for this customer.
                            const customerMessages = data.messages.filter(m => m.psid === targetPsid);
                            isProcessing = true;
                            addLog(`[Dashboard] Chat correcto abierto (${targetName}). Enviando respuesta del gestor...`);
                            sendPendingMessages(customerMessages, 0);
                        } else {
                            // The target chat is NOT currently open.
                            // Try to find it in the sidebar by looking for a link whose href contains the psid.
                            const sidebarLinks = document.querySelectorAll('a[href*="/messages/t/"]');
                            let found = false;
                            for (let link of sidebarLinks) {
                                const href = link.getAttribute('href') || '';
                                // Check if this link's href contains the target psid as a path segment
                                if (href.includes(`/t/${targetPsid}`) || href.includes(`/${targetPsid}`)) {
                                    let firstLine = link.innerText.split('\n')[0].trim();
                                    let cleanName = firstLine.split(/[·-]/)[0].trim();
                                    addLog(`[Dashboard] Detectada respuesta de gestor para ${cleanName}. Abriendo chat...`);
                                    link.click();
                                    found = true;
                                    break;
                                }
                                // Fallback: match by customer name (for name-slug based psids)
                                let firstLine = link.innerText.split('\n')[0].trim();
                                let cleanName = firstLine.split(/[·-]/)[0].trim();
                                let linkPsid = cleanName.replace(/[^a-zA-Z0-9]/g, '_').toLowerCase();
                                if (linkPsid === targetPsid || cleanName.toLowerCase().includes(targetName.toLowerCase())) {
                                    addLog(`[Dashboard] Detectada respuesta de gestor para ${cleanName} (por nombre). Abriendo chat...`);
                                    link.click();
                                    found = true;
                                    break;
                                }
                            }
                            if (!found) {
                                // Chat not visible in sidebar list - can't deliver right now
                                addLog(`[Dashboard] Mensaje pendiente para ${targetName} (psid:${targetPsid}), pero no se encontró su chat en la lista.`);
                            }
                        }
                    }
                } catch (e) {
                    // Silent retry
                }
            }
        });
    }

    // Send pending manager messages sequentially
    function sendPendingMessages(messages, index) {
        if (index >= messages.length) {
            isProcessing = false;
            return;
        }

        const msg = messages[index];
        addLog(`[Dashboard] Escribiendo respuesta manual de gestor: "${msg.message_text.substring(0, 30)}..."`);

        // Perform typing and sending
        sendReplyToChat(msg.message_text);

        // Notify backend that this message was successfully delivered
        GM_xmlhttpRequest({
            method: "POST",
            url: "http://localhost:8000/api/automation/delivered",
            data: JSON.stringify({
                message_ids: [msg.id]
            }),
            headers: {
                "Content-Type": "application/json"
            },
            onload: function(response) {
                // Pacing delay to avoid triggering spam triggers
                setTimeout(() => {
                    sendPendingMessages(messages, index + 1);
                }, 1500);
            },
            onerror: function(err) {
                isProcessing = false;
            }
        });
    }

    // Function to simulate keyboard typing and click events directly in the Messenger TextBox DOM
    function sendReplyToChat(text) {
        const inputDiv = document.querySelector(
            'div[role="textbox"][contenteditable="true"], ' +
            'div[aria-label="Mensaje"], ' +
            'div[aria-label="Message"], ' +
            'div[aria-label="Escribe una respuesta..."]'
        );
        if (!inputDiv) {
            addLog("Error: No se encontró cuadro de texto en Messenger.");
            updateWidget("Error de envío", "error");
            return;
        }

        inputDiv.focus();

        setTimeout(() => {
            try {
                document.execCommand('insertText', false, text);
            } catch (err) {
                inputDiv.textContent = text;
                inputDiv.dispatchEvent(new Event('input', { bubbles: true }));
            }
            
            setTimeout(() => {
                let sendBtn = document.querySelector(
                    'div[aria-label="Enviar"], ' +
                    'div[aria-label="Send"], ' +
                    'span[data-icon="send"], ' +
                    'div[role="button"][aria-label="Mensaje"] + div'
                );

                if (!sendBtn) {
                    const svgs = document.querySelectorAll('svg');
                    for (let svg of svgs) {
                        if (svg.innerHTML.includes('polygon') || svg.innerHTML.includes('path d="M16') || svg.innerHTML.includes('M2.01 21L23 12 2.01 3')) {
                            const btnParent = svg.closest('div[role="button"], button');
                            if (btnParent) {
                                sendBtn = btnParent;
                                break;
                            }
                        }
                    }
                }

                if (sendBtn) {
                    sendBtn.click();
                    addLog("Mensaje enviado con éxito.");
                    updateWidget("Respuesta enviada! ✔", "scanning");
                } else {
                    const enterEvent = new KeyboardEvent('keydown', {
                        key: 'Enter',
                        code: 'Enter',
                        keyCode: 13,
                        which: 13,
                        bubbles: true,
                        cancelable: true
                    });
                    inputDiv.dispatchEvent(enterEvent);
                    addLog("Intento de enviar con tecla Enter completado.");
                    updateWidget("Respuesta enviada (Enter) ✔", "scanning");
                }
            }, 200);
        }, 100);
    }
})();
