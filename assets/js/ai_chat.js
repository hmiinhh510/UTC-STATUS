/**
 * UTC STATUS – AI Assistant Client Script
 * Trợ lý ảo AI hỗ trợ thông tin trạng thái dịch vụ, bãi xe và sự cố UTC
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
  const STORAGE_KEY = 'utc_ai_chat_history_v1';
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
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify(messages.slice(-20))); // Giữ tối đa 20 tin gần nhất
    } catch (e) {}
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
        <span>Hỏi Trợ lý AI UTC</span>
      </div>
      <button class="utc-ai-btn" id="utcAiBtn" title="Trợ lý ảo UTC STATUS" onclick="window.utcAiToggle()">
        <i class="fa-solid fa-robot"></i>
        <span class="utc-ai-online-dot"></span>
      </button>
    `;
    document.body.appendChild(launcher);

    // 2. Thêm Chat Window Modal
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
            <h6>Trợ Lý Ảo UTC STATUS</h6>
            <div class="utc-ai-status-text">
              <span class="badge bg-success p-1 rounded-circle" style="width:7px;height:7px;"></span>
              Trực tuyến • Hỗ trợ 24/7
            </div>
          </div>
        </div>
        <div class="utc-ai-header-actions">
          <button class="utc-ai-tool-btn" onclick="window.utcAiClearChat()" title="Xóa đoạn chat">
            <i class="fa-solid fa-trash-can"></i>
          </button>
          <button class="utc-ai-tool-btn" onclick="window.utcAiToggle(false)" title="Đóng">
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

    // Lắng nghe sự kiện phím Enter
    const inputEl = document.getElementById('utcAiInput');
    inputEl.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        window.utcAiSendMessage();
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
        "👋 **Xin chào bạn!**\n\nMình là **Trợ lý số UTC STATUS**, sẵn sàng hỗ trợ bạn tra cứu trực tiếp:\n• 🅿️ Sức chứa & chỗ trống tại **Bãi xe sân trường**\n• 🟢 Trạng thái hệ thống: **LMS, Wifi eduroam, Cổng tín chỉ**\n• 🛠️ Sự cố đang giải quyết & lịch trình bảo trì trong tuần.\n\nBạn có thể gõ câu hỏi hoặc bấm vào các câu hỏi mẫu bên dưới nhé!",
        [
          "🅿️ Bãi xe sân trường lúc này thế nào?",
          "⚠️ Có dịch vụ nào đang gặp sự cố không?",
          "🛠️ Lịch bảo trì trong tuần tới?",
          "📶 Hướng dẫn kết nối Wi-Fi eduroam?"
        ]
      );
    } else {
      history.forEach((m) => {
        if (m.role === 'user') {
          appendUserMessage(m.text, false);
        } else {
          appendBotMessage(m.text, m.suggestions || [], false);
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

  function appendBotMessage(markdownText, suggestions = [], save = true) {
    const container = document.getElementById('utcAiMessages');
    const timeStr = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

    const group = document.createElement('div');
    group.className = 'utc-ai-msg-group bot';

    let chipsHtml = '';
    if (suggestions && suggestions.length > 0) {
      chipsHtml = `<div class="utc-ai-chips-container mt-2">`;
      suggestions.forEach((s) => {
        chipsHtml += `<button class="utc-ai-chip" onclick="window.utcAiAskSuggestion('${escapeHtml(s)}')">${escapeHtml(s)}</button>`;
      });
      chipsHtml += `</div>`;
    }

    group.innerHTML = `
      <div class="utc-ai-bubble">
        ${renderMarkdown(markdownText)}
        ${chipsHtml}
      </div>
      <div class="utc-ai-time">${timeStr} • UTC Assistant</div>
    `;
    container.appendChild(group);
    scrollToBottom();

    if (save) {
      const history = loadHistory();
      history.push({ role: 'bot', text: markdownText, suggestions: suggestions, time: timeStr });
      saveHistory(history);
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

    // 2. Gửi request tới Backend API
    const apiUrl = getApiUrl();

    fetch(apiUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify({ message: message })
    })
      .then((res) => res.json())
      .then((data) => {
        removeTypingIndicator();
        input.disabled = false;
        sendBtn.disabled = false;
        input.focus();

        if (data.success && data.message) {
          appendBotMessage(data.message, data.suggestions || [], true);
        } else {
          appendBotMessage(
            '⚠️ ' + (data.error || 'Hệ thống đang bận. Vui lòng thử lại sau giây lát!'),
            ['🅿️ Kiểm tra bãi xe', '⚠️ Dịch vụ nào lỗi?'],
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
