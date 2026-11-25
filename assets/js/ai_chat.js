/**
 * UTH STATUS – AI Assistant Client Script v2.0
 * Trợ lý ảo AI thông minh Trường Đại học Giao thông Vận tải (UTH)
 * Hỗ trợ Web Audio Chime, Phóng to/Thu nhỏ, Action Triggers, Copy Message, Multi-turn History
 */

(function () {
  'use strict';

  // Xác định đường dẫn tương đối tới API
  const getApiUrl = () => {
    const isInsideAdmin = window.location.pathname.includes('/admin');
    return isInsideAdmin ? '../api/ai_chat.php' : 'api/ai_chat.php';
  };

  // Safe Escape HTML
  function escapeHtml(str) {
    if (!str) return '';
    return str
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  // Markdown Parser đơn giản & an toàn
  function renderMarkdown(rawText) {
    if (!rawText) return '';
    let escaped = escapeHtml(rawText);

    // In đậm **text**
    escaped = escaped.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
    // In nghiêng *text*
    escaped = escaped.replace(/\*(.*?)\*/g, '<em>$1</em>');
    // Code inline `code`
    escaped = escaped.replace(/`([^`]+)`/g, '<code class="bg-light px-1 py-0.5 rounded border text-danger small font-monospace">$1</code>');
    // Dòng mới
    escaped = escaped.replace(/\n/g, '<br>');

    return escaped;
  }

  // Quản lý lịch sử hội thoại trong sessionStorage
  const STORAGE_KEY = 'uth_ai_chat_history_v2';
  function loadHistory() {
    try {
      const data = sessionStorage.getItem(STORAGE_KEY);
      return data ? JSON.parse(data) : [];
    } catch (e) {
      return [];
    }
  }

  function saveHistory(messages) {
    try {
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify(messages.slice(-25))); // Giữ tối đa 25 tin gần nhất
    } catch (e) {}
  }

  // Âm thanh thông báo nhẹ nhàng qua Web Audio API (0 byte, không cần file mp3)
  let audioCtx = null;
  function playNotificationChime() {
    try {
      const soundEnabled = localStorage.getItem('uth_ai_sound') !== 'false';
      if (!soundEnabled) return;

      if (!audioCtx) {
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (AudioContextClass) {
          audioCtx = new AudioContextClass();
        }
      }
      if (!audioCtx) return;
      if (audioCtx.state === 'suspended') {
        audioCtx.resume();
      }

      const now = audioCtx.currentTime;

      // Tone 1: 587.33 Hz (D5)
      const osc1 = audioCtx.createOscillator();
      const gain1 = audioCtx.createGain();
      osc1.type = 'sine';
      osc1.frequency.setValueAtTime(587.33, now);
      gain1.gain.setValueAtTime(0.08, now);
      gain1.gain.exponentialRampToValueAtTime(0.001, now + 0.22);
      osc1.connect(gain1);
      gain1.connect(audioCtx.destination);
      osc1.start(now);
      osc1.stop(now + 0.22);

      // Tone 2: 880 Hz (A5)
      const osc2 = audioCtx.createOscillator();
      const gain2 = audioCtx.createGain();
      osc2.type = 'sine';
      osc2.frequency.setValueAtTime(880, now + 0.08);
      gain2.gain.setValueAtTime(0.09, now + 0.08);
      gain2.gain.exponentialRampToValueAtTime(0.001, now + 0.35);
      osc2.connect(gain2);
      gain2.connect(audioCtx.destination);
      osc2.start(now + 0.08);
      osc2.stop(now + 0.35);
    } catch (e) {
      // Bỏ qua nếu trình duyệt chặn audio autoplay
    }
  }

  // Điều phối các hành động tương tác (Interactive Actions)
  function handleActionClick(actionName, optionalQuestion) {
    if (optionalQuestion) {
      window.utcAiAskSuggestion(optionalQuestion);
      return;
    }

    const isInsideAdmin = window.location.pathname.includes('/admin');

    if (actionName === 'openParkingModal') {
      if (isInsideAdmin) {
        const parkingTabBtn = document.querySelector('button[data-bs-target="#tab-parking"]');
        if (parkingTabBtn && typeof bootstrap !== 'undefined') {
          bootstrap.Tab.getOrCreateInstance(parkingTabBtn).show();
          window.utcAiToggle(false);
          return;
        }
      } else {
        const modalEl = document.getElementById('modalParkingStatus');
        if (modalEl && typeof bootstrap !== 'undefined') {
          bootstrap.Modal.getOrCreateInstance(modalEl).show();
          window.utcAiToggle(false);
          return;
        }
      }
    }

    if (actionName === 'openIncidentModal') {
      if (isInsideAdmin) {
        const fifoTabBtn = document.querySelector('button[data-bs-target="#tab-fifo-incidents"]');
        if (fifoTabBtn && typeof bootstrap !== 'undefined') {
          bootstrap.Tab.getOrCreateInstance(fifoTabBtn).show();
          window.utcAiToggle(false);
          return;
        }
      } else {
        const modalEl = document.getElementById('modalUserReport') || document.getElementById('modalIncidentsDetail');
        if (modalEl && typeof bootstrap !== 'undefined') {
          bootstrap.Modal.getOrCreateInstance(modalEl).show();
          window.utcAiToggle(false);
          return;
        }
      }
    }

    if (actionName === 'openMaintenanceModal') {
      if (isInsideAdmin) {
        const maintTabBtn = document.querySelector('button[data-bs-target="#tab-maintenances"]');
        if (maintTabBtn && typeof bootstrap !== 'undefined') {
          bootstrap.Tab.getOrCreateInstance(maintTabBtn).show();
          window.utcAiToggle(false);
          return;
        }
      } else {
        const modalEl = document.getElementById('modalMaintenancesDetail');
        if (modalEl && typeof bootstrap !== 'undefined') {
          bootstrap.Modal.getOrCreateInstance(modalEl).show();
          window.utcAiToggle(false);
          return;
        }
      }
    }

    if (actionName === 'openUptimeModal') {
      if (!isInsideAdmin) {
        const modalEl = document.getElementById('modalUptimeHistory');
        if (modalEl && typeof bootstrap !== 'undefined') {
          bootstrap.Modal.getOrCreateInstance(modalEl).show();
          window.utcAiToggle(false);
          return;
        }
      }
    }
  }

  // Sao chép nội dung tin nhắn bot
  function copyMessageText(btnEl, rawText) {
    if (!navigator.clipboard) {
      const ta = document.createElement('textarea');
      ta.value = rawText;
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
    } else {
      navigator.clipboard.writeText(rawText);
    }

    const origHtml = btnEl.innerHTML;
    btnEl.innerHTML = '<i class="fa-solid fa-check text-success"></i> Đã chép!';
    setTimeout(() => {
      btnEl.innerHTML = origHtml;
    }, 1800);
  }

  // Tạo UI Widget vào trang
  function initAiChatWidget() {
    if (document.getElementById('utcAiChatLauncher')) return;

    // 1. Thêm Launcher Button
    const launcher = document.createElement('div');
    launcher.id = 'utcAiChatLauncher';
    launcher.className = 'utc-ai-launcher';
    launcher.innerHTML = `
      <div class="utc-ai-pill-label" id="utcAiPill" onclick="window.utcAiToggle(true)">
        <i class="fa-solid fa-sparkles text-warning"></i>
        <span>Hỏi Trợ lý AI UTH</span>
      </div>
      <button class="utc-ai-btn" id="utcAiBtn" title="Trợ lý ảo UTH STATUS (Hỏi bãi xe, LMS, Wifi...)" onclick="window.utcAiToggle()">
        <i class="fa-solid fa-robot"></i>
        <span class="utc-ai-online-dot"></span>
      </button>
    `;
    document.body.appendChild(launcher);

    // 2. Thêm Chat Window Modal
    const isSoundOn = localStorage.getItem('uth_ai_sound') !== 'false';
    const windowEl = document.createElement('div');
    windowEl.id = 'utcAiChatWindow';
    windowEl.className = 'utc-ai-window';
    windowEl.innerHTML = `
      <div class="utc-ai-header">
        <div class="utc-ai-bot-info">
          <div class="utc-ai-avatar">
            <i class="fa-solid fa-robot"></i>
          </div>
          <div class="utc-ai-title-group">
            <h6>Trợ Lý Ảo UTH STATUS</h6>
            <div class="utc-ai-status-text">
              <span class="badge bg-success p-1 rounded-circle" style="width:7px;height:7px;"></span>
              Trực tuyến • Hỗ trợ 24/7
            </div>
          </div>
        </div>
        <div class="utc-ai-header-actions">
          <button class="utc-ai-tool-btn ${isSoundOn ? 'active' : ''}" id="utcAiSoundBtn" onclick="window.utcAiToggleSound()" title="${isSoundOn ? 'Tắt âm thanh' : 'Bật âm thanh'}">
            <i class="fa-solid ${isSoundOn ? 'fa-volume-high' : 'fa-volume-xmark'}"></i>
          </button>
          <button class="utc-ai-tool-btn" id="utcAiMaxBtn" onclick="window.utcAiToggleMaximize()" title="Phóng to / Thu nhỏ">
            <i class="fa-solid fa-up-right-and-down-left-from-center"></i>
          </button>
          <button class="utc-ai-tool-btn" onclick="window.utcAiClearChat()" title="Xóa đoạn chat">
            <i class="fa-solid fa-trash-can"></i>
          </button>
          <button class="utc-ai-tool-btn" onclick="window.utcAiToggle(false)" title="Đóng cửa sổ">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </div>
      </div>

      <div class="utc-ai-messages" id="utcAiMessages"></div>

      <div class="utc-ai-footer">
        <input 
          type="text" 
          id="utcAiInput" 
          class="utc-ai-input" 
          placeholder="Hỏi về bãi xe, LMS, Wifi eduroam..." 
          maxlength="500" 
          autocomplete="off"
        />
        <button class="utc-ai-send-btn" id="utcAiSendBtn" onclick="window.utcAiSendMessage()" title="Gửi câu hỏi">
          <i class="fa-solid fa-paper-plane"></i>
        </button>
      </div>
    `;
    document.body.appendChild(windowEl);

    // Lắng nghe sự kiện phím
    const inputEl = document.getElementById('utcAiInput');
    inputEl.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        window.utcAiSendMessage();
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        const win = document.getElementById('utcAiChatWindow');
        if (win && win.classList.contains('open')) {
          window.utcAiToggle(false);
        }
      }
    });

    // Render tin nhắn đã lưu hoặc tin mở đầu
    renderInitialMessages();
  }

  // Render tin nhắn
  function renderInitialMessages() {
    const history = loadHistory();
    const container = document.getElementById('utcAiMessages');
    container.innerHTML = '';

    if (history.length === 0) {
      // Tin nhắn mở đầu mặc định
      appendBotMessage(
        "👋 **Xin chào bạn!**\n\nMình là **Trợ lý số UTH STATUS**, sẵn sàng hỗ trợ bạn tra cứu trực tiếp:\n• 🅿️ Sức chứa & chỗ trống tại **Bãi xe sân trường (Khu A, B, C)**\n• 🟢 Trạng thái hệ thống: **LMS Moodle, Wifi eduroam, Cổng tín chỉ**\n• 🛠️ Sự cố đang giải quyết & lịch trình bảo trì trong tuần.\n\nBạn có thể gõ câu hỏi hoặc bấm vào các câu hỏi mẫu bên dưới nhé!",
        [
          "🅿️ Bãi xe sân trường lúc này thế nào?",
          "⚠️ Có dịch vụ nào đang gặp sự cố không?",
          "🛠️ Lịch bảo trì trong tuần tới?",
          "📶 Hướng dẫn kết nối Wi-Fi eduroam?"
        ],
        [
          { label: "🅿️ Bản Đồ Bãi Xe", action: "openParkingModal" },
          { label: "⚠️ Báo Cáo Sự Cố", action: "openIncidentModal" }
        ],
        false
      );
    } else {
      history.forEach((m) => {
        if (m.role === 'user') {
          appendUserMessage(m.text, false);
        } else {
          appendBotMessage(m.text, m.suggestions || [], m.actions || [], false);
        }
      });
      scrollToBottom();
    }
  }

  function appendUserMessage(text, save = true) {
    const container = document.getElementById('utcAiMessages');
    const timeStr = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

    const group = document.createElement('div');
    group.className = 'utc-ai-msg-group user';
    group.innerHTML = `
      <div class="utc-ai-bubble">${escapeHtml(text)}</div>
      <div class="utc-ai-time">${timeStr}</div>
    `;
    container.appendChild(group);
    scrollToBottom();

    if (save) {
      const history = loadHistory();
      history.push({ role: 'user', text: text, time: timeStr });
      saveHistory(history);
    }
  }

  function appendBotMessage(markdownText, suggestions = [], actions = [], save = true) {
    const container = document.getElementById('utcAiMessages');
    const timeStr = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

    const group = document.createElement('div');
    group.className = 'utc-ai-msg-group bot';

    // 1. Action Buttons Container
    let actionsHtml = '';
    if (actions && actions.length > 0) {
      actionsHtml = `<div class="utc-ai-actions-container">`;
      actions.forEach((act, idx) => {
        const safeLabel = escapeHtml(act.label || 'Xem chi tiết');
        const actionAttr = escapeHtml(act.action || '');
        const qAttr = escapeHtml(act.question || '');
        actionsHtml += `<button type="button" class="utc-ai-action-btn" data-act="${actionAttr}" data-q="${qAttr}">${safeLabel}</button>`;
      });
      actionsHtml += `</div>`;
    }

    // 2. Suggestion Chips
    let chipsHtml = '';
    if (suggestions && suggestions.length > 0) {
      chipsHtml = `<div class="utc-ai-chips-container">`;
      suggestions.forEach((s) => {
        chipsHtml += `<button type="button" class="utc-ai-chip" onclick="window.utcAiAskSuggestion('${escapeHtml(s)}')">${escapeHtml(s)}</button>`;
      });
      chipsHtml += `</div>`;
    }

    group.innerHTML = `
      <div class="utc-ai-bubble">
        ${renderMarkdown(markdownText)}
        ${actionsHtml}
        ${chipsHtml}
      </div>
      <div class="utc-ai-time-row">
        <span class="utc-ai-time">${timeStr} • UTH Assistant</span>
        <button type="button" class="utc-ai-copy-btn" title="Sao chép tin nhắn">
          <i class="fa-regular fa-copy"></i> Chép
        </button>
      </div>
    `;

    // Gán sự kiện click cho các nút Action
    group.querySelectorAll('.utc-ai-action-btn').forEach((btn) => {
      btn.addEventListener('click', function () {
        const act = this.getAttribute('data-act');
        const q = this.getAttribute('data-q');
        handleActionClick(act, q);
      });
    });

    // Gán sự kiện copy
    const copyBtn = group.querySelector('.utc-ai-copy-btn');
    if (copyBtn) {
      copyBtn.addEventListener('click', function () {
        copyMessageText(this, markdownText);
      });
    }

    container.appendChild(group);
    scrollToBottom();

    if (save) {
      const history = loadHistory();
      history.push({
        role: 'bot',
        text: markdownText,
        suggestions: suggestions,
        actions: actions,
        time: timeStr
      });
      saveHistory(history);
      playNotificationChime();
    }
  }

  function showTypingIndicator() {
    removeTypingIndicator();
    const container = document.getElementById('utcAiMessages');
    const typing = document.createElement('div');
    typing.id = 'utcAiTyping';
    typing.className = 'utc-ai-typing';
    typing.innerHTML = `
      <div class="utc-ai-dot"></div>
      <div class="utc-ai-dot"></div>
      <div class="utc-ai-dot"></div>
    `;
    container.appendChild(typing);
    scrollToBottom();
  }

  function removeTypingIndicator() {
    const typing = document.getElementById('utcAiTyping');
    if (typing) typing.remove();
  }

  function scrollToBottom() {
    const container = document.getElementById('utcAiMessages');
    if (container) {
      container.scrollTop = container.scrollHeight;
    }
  }

  // ── CÁC HÀM PUBLIC ĐIỀU KHIỂN (GLOBAL API) ───────────────────
  window.utcAiToggle = function (forceState) {
    const win = document.getElementById('utcAiChatWindow');
    if (!win) return;

    const isOpen = win.classList.contains('open');
    const shouldOpen = typeof forceState === 'boolean' ? forceState : !isOpen;

    if (shouldOpen) {
      win.classList.add('open');
      const pill = document.getElementById('utcAiPill');
      if (pill) pill.style.display = 'none';
      setTimeout(() => {
        document.getElementById('utcAiInput')?.focus();
        scrollToBottom();
      }, 150);
    } else {
      win.classList.remove('open');
    }
  };

  window.utcAiToggleMaximize = function () {
    const win = document.getElementById('utcAiChatWindow');
    const maxBtn = document.getElementById('utcAiMaxBtn');
    if (!win || !maxBtn) return;

    const isMax = win.classList.toggle('maximized');
    if (isMax) {
      maxBtn.innerHTML = '<i class="fa-solid fa-down-left-and-up-right-to-center"></i>';
      maxBtn.title = 'Thu nhỏ kích thước';
    } else {
      maxBtn.innerHTML = '<i class="fa-solid fa-up-right-and-down-left-from-center"></i>';
      maxBtn.title = 'Phóng to kích thước';
    }
    scrollToBottom();
  };

  window.utcAiToggleSound = function () {
    const soundBtn = document.getElementById('utcAiSoundBtn');
    const current = localStorage.getItem('uth_ai_sound') !== 'false';
    const newState = !current;
    localStorage.setItem('uth_ai_sound', newState ? 'true' : 'false');

    if (soundBtn) {
      if (newState) {
        soundBtn.classList.add('active');
        soundBtn.innerHTML = '<i class="fa-solid fa-volume-high"></i>';
        soundBtn.title = 'Tắt âm thanh';
        playNotificationChime();
      } else {
        soundBtn.classList.remove('active');
        soundBtn.innerHTML = '<i class="fa-solid fa-volume-xmark"></i>';
        soundBtn.title = 'Bật âm thanh';
      }
    }
  };

  window.utcAiClearChat = function () {
    if (confirm('Bạn có muốn xóa toàn bộ lịch sử trò chuyện không?')) {
      sessionStorage.removeItem(STORAGE_KEY);
      renderInitialMessages();
    }
  };

  window.utcAiAskSuggestion = function (question) {
    const input = document.getElementById('utcAiInput');
    if (input) {
      input.value = question;
      window.utcAiSendMessage();
    }
  };

  window.utcAiSendMessage = function () {
    const input = document.getElementById('utcAiInput');
    const sendBtn = document.getElementById('utcAiSendBtn');
    if (!input || !sendBtn) return;

    const message = input.value.trim();
    if (!message) return;

    // 1. Hiển thị tin nhắn người dùng
    appendUserMessage(message, true);
    input.value = '';
    input.disabled = true;
    sendBtn.disabled = true;

    showTypingIndicator();

    // 2. Gửi request tới Backend API kèm lịch sử hội thoại gần nhất
    const apiUrl = getApiUrl();
    const history = loadHistory().slice(-6); // 6 tin nhắn gần nhất để duy trì ngữ cảnh

    fetch(apiUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify({
        message: message,
        history: history
      })
    })
      .then((res) => res.json())
      .then((data) => {
        removeTypingIndicator();
        input.disabled = false;
        sendBtn.disabled = false;
        input.focus();

        if (data.success && data.message) {
          appendBotMessage(data.message, data.suggestions || [], data.actions || [], true);
        } else {
          appendBotMessage(
            '⚠️ ' + (data.error || 'Hệ thống đang bận. Vui lòng thử lại sau giây lát!'),
            ['🅿️ Kiểm tra bãi xe', '⚠️ Dịch vụ nào lỗi?'],
            [{ label: '🅿️ Xem Bãi Xe', action: 'openParkingModal' }],
            true
          );
        }
      })
      .catch((err) => {
        removeTypingIndicator();
        input.disabled = false;
        sendBtn.disabled = false;
        input.focus();
        appendBotMessage(
          '❌ Không thể kết nối tới máy chủ. Vui lòng kiểm tra lại kết nối mạng hoặc thử lại sau!',
          ['🅿️ Bãi xe sân trường', '🛠️ Lịch bảo trì'],
          [{ label: '🅿️ Xem Bãi Xe', action: 'openParkingModal' }],
          true
        );
      });
  };

  // Tự động khởi tạo sau khi DOM sẵn sàng
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAiChatWidget);
  } else {
    initAiChatWidget();
  }
})();
