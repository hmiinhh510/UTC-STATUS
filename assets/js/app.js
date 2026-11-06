/**
 * UTC STATUS – Interactive JavaScript Application
 * Trường Đại học Giao thông Vận tải
 *
 * Implements:
 *  - Live Polling (30s) + Realtime Countdown
 *  - Dynamic SVG Donut Chart for "x/y hoạt động"
 *  - Interactive Monthly Calendar with incident & maintenance indicators
 *  - Dynamic Service Grid with Status dots and Icons
 *  - Rich Modals for every clicked feature / title
 */

'use strict';

const API_BASE = 'api';
const POLL_INTERVAL = 30000; // 30s
let pollTimer = null;
let countdownTimer = null;
let countdownSeconds = 30;

// Global Cache
let systemData = null;
let currentCalendarDate = new Date();

// Bootstrap Modal Instances
let modals = {};

// ══════════════════════════════════════════════════════════════
//  INITIALIZATION
// ══════════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', () => {
  // Init Modals
  [
    'modalServiceDetail',
    'modalIncidentsDetail',
    'modalMaintenancesDetail',
    'modalDayEvents',
    'modalJsonData',
    'modalSubscribe',
    'modalRealtimeOverview',
    'modalUptimeHistory',
    'modalUserReport',
    'modalCommander',
    'modalHealthChecks',
    'modalCreateIncidentQuick',
    'modalCreateMaintenanceQuick'
  ].forEach(id => {
    const el = document.getElementById(id);
    if (el) modals[id] = new bootstrap.Modal(el);
  });

  // Enable Stacked Modals management for multi-layer popups
  initStackedModals();

  // Initial Data Load
  loadStatusData();

  // Polling & Countdown
  startPolling();
  startCountdown();

  // Attach Header & Quick Actions
  document.getElementById('btnJsonData')?.addEventListener('click', (e) => {
    e.preventDefault();
    openJsonModal();
  });

  document.getElementById('btnNavCalendar')?.addEventListener('click', (e) => {
    e.preventDefault();
    openMaintenancesModal();
  });

  document.getElementById('btnNavBell')?.addEventListener('click', (e) => {
    e.preventDefault();
    openSubscribeModal();
  });

  document.getElementById('cardIncidentAction')?.addEventListener('click', () => {
    openIncidentsModal();
  });

  document.getElementById('cardMaintenanceAction')?.addEventListener('click', () => {
    openMaintenancesModal();
  });

  document.getElementById('calPrevMonth')?.addEventListener('click', () => {
    currentCalendarDate.setMonth(currentCalendarDate.getMonth() - 1);
    renderCalendar();
  });

  document.getElementById('calNextMonth')?.addEventListener('click', () => {
    currentCalendarDate.setMonth(currentCalendarDate.getMonth() + 1);
    renderCalendar();
  });

  // Subscribe Form Handler
  document.getElementById('formSubscribe')?.addEventListener('submit', handleSubscribe);

  // User Incident Report Form Handler
  document.getElementById('formUserReport')?.addEventListener('submit', handleUserReportSubmit);
});

// ══════════════════════════════════════════════════════════════
//  DATA FETCHING
// ══════════════════════════════════════════════════════════════
async function loadStatusData() {
  try {
    const res = await fetch(`${API_BASE}/status.php?t=${Date.now()}`);
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const data = await res.json();
    systemData = data;

    renderDonutChart(data.summary.operational_count, data.summary.total_services);
    renderStatusSummary(data);
    renderMetricCards(data);
    renderCalendar();

    // Render Status Page Feature Ecosystem (Refactored Datadog/Atlassian Modules)
    updateFeatureStatuses(data);
    renderFeatureEcosystem(STATUS_PAGE_FEATURES);

    // Update time
    const now = new Date();
    const hh = String(now.getHours()).padStart(2, '0');
    const mm = String(now.getMinutes()).padStart(2, '0');
    document.getElementById('statUpdatedTime').textContent = `${hh}:${mm}`;

  } catch (err) {
    console.error('Fetch status error:', err);
  }
}

// ══════════════════════════════════════════════════════════════
//  RENDER DONUT CHART (SVG)
// ══════════════════════════════════════════════════════════════
function renderDonutChart(operational, total) {
  const ratioEl = document.getElementById('donutRatio');
  const pathEl  = document.getElementById('donutValuePath');

  if (ratioEl) ratioEl.textContent = `${operational}/${total}`;

  if (pathEl) {
    // Circumference = 2 * PI * r = 2 * 3.14159 * 40 = 251.32
    const circumference = 251.32;
    const pct = total > 0 ? (operational / total) : 1;
    const strokeDash = pct * circumference;
    pathEl.style.strokeDasharray = `${strokeDash} ${circumference}`;

    // Luôn giữ cung tròn màu xanh ngọc lục bảo (Emerald Green) đồng bộ nhận diện thương hiệu UTC
    pathEl.style.stroke = 'var(--green-emerald)';
  }
}

// ══════════════════════════════════════════════════════════════
//  RENDER SUMMARY
// ══════════════════════════════════════════════════════════════
function renderStatusSummary(data) {
  const overallMsg = document.getElementById('statGeneralMsg');
  if (overallMsg) {
    overallMsg.textContent = data.overall.message;
    overallMsg.className = 'status-stat-val-bold ' + (data.overall.status === 'operational' ? 'green-ok' : 'red-alert');
  }

  document.getElementById('statTotalServices').textContent = data.summary.total_services;
  document.getElementById('statOperationalCount').textContent = data.summary.operational_count;
  document.getElementById('statOutageCount').textContent = data.summary.outage_count + data.summary.degraded_count;

  document.getElementById('statAvgUptime').textContent = data.summary.avg_uptime_90d + '%';
  document.getElementById('statActiveIncidents').textContent = data.summary.active_incidents_count;
  document.getElementById('statUpcomingMaint').textContent = data.summary.upcoming_maintenances_count;
  document.getElementById('statInProgressMaint').textContent = data.summary.in_progress_count;
}

// ══════════════════════════════════════════════════════════════
//  RENDER 2 METRIC CARDS
// ══════════════════════════════════════════════════════════════
function renderMetricCards(data) {
  const incNum = document.getElementById('metricIncidentsCount');
  if (incNum) incNum.textContent = data.summary.active_incidents_count;

  const maintNum = document.getElementById('metricMaintenancesCount');
  if (maintNum) maintNum.textContent = data.summary.upcoming_maintenances_count;

  // Nav badge
  const badge = document.getElementById('navBellBadge');
  if (badge) {
    const totalAlerts = data.summary.active_incidents_count + data.summary.in_progress_count;
    if (totalAlerts > 0) {
      badge.textContent = totalAlerts;
      badge.classList.remove('d-none');
    } else {
      badge.classList.add('d-none');
    }
  }
}

// ══════════════════════════════════════════════════════════════
//  RENDER MONTHLY CALENDAR
// ══════════════════════════════════════════════════════════════
function renderCalendar() {
  const labelEl = document.getElementById('calMonthLabel');
  const tbody   = document.getElementById('calTableBody');
  if (!labelEl || !tbody) return;

  const year  = currentCalendarDate.getFullYear();
  const month = currentCalendarDate.getMonth(); // 0-based

  labelEl.textContent = `tháng ${month + 1} ${year}`;

  const firstDayIndex = new Date(year, month, 1).getDay(); // 0 = Sunday
  const daysInMonth   = new Date(year, month + 1, 0).getDate();
  const prevDaysInMonth = new Date(year, month, 0).getDate();

  const today = new Date();
  const isCurrentMonth = (today.getFullYear() === year && today.getMonth() === month);
  const todayDateNum   = today.getDate();

  const eventsMap = systemData?.calendar_events || {};

  let html = '';
  let dayCount = 1;
  let nextMonthDay = 1;

  for (let row = 0; row < 6; row++) {
    html += '<tr>';
    for (let col = 0; col < 7; col++) {
      const cellIndex = row * 7 + col;

      if (cellIndex < firstDayIndex) {
        // Previous month days
        const prevDate = prevDaysInMonth - (firstDayIndex - cellIndex - 1);
        html += `<td class="calendar-day-cell other-month">${prevDate}</td>`;
      } else if (dayCount <= daysInMonth) {
        // Current month days
        const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(dayCount).padStart(2, '0')}`;
        const eventData = eventsMap[dateStr];
        const hasIncidents = eventData && eventData.incidents > 0;
        const hasMaints    = eventData && eventData.maintenances > 0;
        const hasEvents    = hasIncidents || hasMaints;
        const isToday      = isCurrentMonth && (dayCount === todayDateNum);

        let classes = 'calendar-day-cell';
        if (hasEvents) classes += ' has-events';
        if (isToday)   classes += ' is-today';

        let dotsHtml = '';
        if (hasIncidents && hasMaints) {
          dotsHtml = '<span class="cal-dot cal-dot-incident"></span><span class="cal-dot cal-dot-maint"></span>';
        } else if (hasIncidents) {
          dotsHtml = '<span class="cal-dot cal-dot-incident"></span>';
        } else if (hasMaints) {
          dotsHtml = '<span class="cal-dot cal-dot-maint"></span>';
        }

        html += `
          <td class="${classes}" data-date="${dateStr}" onclick="openDayEventsModal('${dateStr}')">
            ${dayCount}
            <div class="calendar-dots">${dotsHtml}</div>
          </td>`;
        dayCount++;
      } else {
        // Next month days
        html += `<td class="calendar-day-cell other-month">${nextMonthDay}</td>`;
        nextMonthDay++;
      }
    }
    html += '</tr>';
    if (dayCount > daysInMonth) break;
  }

  tbody.innerHTML = html;
}

// ══════════════════════════════════════════════════════════════
//  STATUS PAGE FEATURE ECOSYSTEM REGISTRY (MÀU SẮC ĐẶC TRƯNG TỪNG LOGO)
// ══════════════════════════════════════════════════════════════
const STATUS_PAGE_FEATURES = [
  {
    id: 'realtime-monitoring',
    code: 'MONITORING',
    title: 'Real-time Status',
    subtitle: 'Giám sát tức thời',
    description: 'Giám sát trạng thái hoạt động tức thời của các dịch vụ, node máy chủ và cụm ứng dụng trong toàn trường.',
    icon: 'fa-heart-pulse',
    iconColor: '#e11d48', // Màu đỏ hồng nhịp tim / sự sống (Heartbeat Pulse)
    status: 'operational',
    badgeColor: '#22c55e',
    badgeText: '',
    tooltip: 'Giám sát tức thời: Ma trận trạng thái hoạt động thời gian thực',
    actionRoute: '#realtime-monitoring',
    handler: 'openRealtimeMonitoringModal'
  },
  {
    id: 'uptime-history',
    code: 'UPTIME_SLA',
    title: 'Uptime & History',
    subtitle: 'Lịch sử & SLA 90 ngày',
    description: 'Thống kê lịch sử sự cố 90 ngày, phần trăm uptime tổng quan (SLI/SLA) và thời gian phục hồi MTTR.',
    icon: 'fa-chart-line',
    iconColor: '#2563eb', // Màu xanh dương biểu đồ phân tích SLA / Uptime
    status: 'operational',
    badgeColor: '#22c55e',
    badgeText: '',
    tooltip: 'Lịch sử & SLA: Báo cáo tỷ lệ Uptime 90 ngày và MTTR phục hồi',
    actionRoute: '#uptime-history',
    handler: 'openUptimeHistoryModal'
  },
  {
    id: 'scheduled-maintenance',
    code: 'MAINTENANCE',
    title: 'Maintenance',
    subtitle: 'Lịch trình bảo trì',
    description: 'Lịch trình bảo trì định kỳ, thời gian downtime dự kiến và phạm vi ảnh hưởng các phân hệ.',
    icon: 'fa-calendar-check',
    iconColor: '#d97706', // Màu vàng hổ phách lịch trình bảo dưỡng / bảo trì
    status: 'operational',
    badgeColor: '#22c55e',
    badgeText: '',
    tooltip: 'Lịch bảo trì: Danh sách kế hoạch bảo dưỡng và nâng cấp hệ thống',
    actionRoute: '#scheduled-maintenance',
    handler: 'openMaintenancesModal'
  },
  {
    id: 'automated-alerts',
    code: 'ALERTS',
    title: 'Alerts & Sub',
    subtitle: 'Thông báo tự động',
    description: 'Hệ thống thông báo tự động đa kênh (Email, Webhook Slack/Discord/Teams, SMS, RSS Feed).',
    icon: 'fa-bell',
    iconColor: '#7c3aed', // Màu tím chuông thông báo đa kênh / Subscription
    status: 'operational',
    badgeColor: '#22c55e',
    badgeText: '',
    tooltip: 'Đăng ký thông báo: Nhận cảnh báo tức thì qua Email & Webhook',
    actionRoute: '#automated-alerts',
    handler: 'openSubscribeModal'
  },
  {
    id: 'user-reporting',
    code: 'USER_TRIAGE',
    title: 'Incident Report',
    subtitle: 'Báo cáo sự cố',
    description: 'Kênh tiếp nhận báo cáo lỗi từ giảng viên, sinh viên với quy trình phân loại triage nhanh.',
    icon: 'fa-triangle-exclamation',
    iconColor: '#ea580c', // Màu cam cảnh báo sự cố / phản ánh lỗi người dùng
    status: 'operational',
    badgeColor: '#22c55e',
    badgeText: '',
    tooltip: 'Báo cáo sự cố: Gửi phản ánh lỗi trực tiếp đến đội ngũ IT',
    actionRoute: '#user-reporting',
    handler: 'openUserReportingModal'
  },
  {
    id: 'admin-commander',
    code: 'COMMANDER',
    title: 'Commander',
    subtitle: 'Chỉ huy vận hành',
    description: 'Bảng điều khiển quản trị viên: phát hiện cảnh báo sớm, tạo thông báo sự cố và cập nhật tiến độ.',
    icon: 'fa-shield-halved',
    iconColor: '#0284c7', // Màu xanh lam an ninh / khiên bảo vệ trung tâm chỉ huy
    status: 'operational',
    badgeColor: '#22c55e',
    badgeText: '',
    tooltip: 'Chỉ huy sự cố: Điều phối quy trình xử lý và Postmortem',
    actionRoute: '#admin-commander',
    handler: 'openIncidentCommanderModal'
  },
  {
    id: 'health-checks',
    code: 'HEALTH_CHECK',
    title: 'Health Checks',
    subtitle: 'Kiểm tra tự động',
    description: 'Tích hợp kiểm tra sức khỏe endpoint, database, ping/latency tự động theo chu kỳ.',
    icon: 'fa-network-wired',
    iconColor: '#0d9488', // Màu xanh ngọc / teal hạ tầng mạng & API Health Checks
    status: 'operational',
    badgeColor: '#22c55e',
    badgeText: '',
    tooltip: 'Health Checks: Đo đạc độ trễ HTTP và kiểm tra endpoint live',
    actionRoute: '#health-checks',
    handler: 'openHealthChecksModal'
  }
];

// Tooltips Management
let activeBootstrapTooltips = [];
function initTooltips() {
  activeBootstrapTooltips.forEach(t => {
    try { t.dispose(); } catch (_) {}
  });
  activeBootstrapTooltips = [];

  const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
  tooltipTriggerList.forEach(el => {
    activeBootstrapTooltips.push(new bootstrap.Tooltip(el, { trigger: 'hover' }));
  });
}

/**
 * Động hóa trạng thái các Feature Card dựa trên số liệu thực tế từ MySQL
 */
function updateFeatureStatuses(data) {
  if (!data || !data.summary) return;

  const s = data.summary;

  // 1. Real-time Monitoring Status
  const featMon = STATUS_PAGE_FEATURES.find(f => f.id === 'realtime-monitoring');
  if (featMon) {
    if (s.outage_count > 0) {
      featMon.status = 'critical';
      featMon.badgeColor = '#ef4444';
      featMon.badgeText = `${s.outage_count} Sự cố`;
      featMon.tooltip = `Gián đoạn: Có ${s.outage_count} dịch vụ đang gặp sự cố`;
    } else if (s.degraded_count > 0) {
      featMon.status = 'warning';
      featMon.badgeColor = '#eab308';
      featMon.badgeText = 'Hiệu năng giảm';
      featMon.tooltip = 'Hiệu năng: Một số dịch vụ có tốc độ phản hồi chậm';
    } else {
      featMon.status = 'operational';
      featMon.badgeColor = '#22c55e';
      featMon.badgeText = '';
      featMon.tooltip = 'Tất cả các dịch vụ đang vận hành bình thường';
    }
  }

  // 2. Scheduled Maintenance Status
  const featMaint = STATUS_PAGE_FEATURES.find(f => f.id === 'scheduled-maintenance');
  if (featMaint) {
    if (s.upcoming_maintenances_count > 0) {
      featMaint.status = 'warning';
      featMaint.badgeColor = '#f59e0b';
      featMaint.badgeText = `${s.upcoming_maintenances_count} Kế hoạch`;
      featMaint.tooltip = `Bảo trì: Có ${s.upcoming_maintenances_count} kế hoạch bảo trì sắp diễn ra`;
    } else {
      featMaint.status = 'operational';
      featMaint.badgeColor = '#22c55e';
      featMaint.badgeText = '';
      featMaint.tooltip = 'Không có kế hoạch bảo trì nào trong thời gian tới';
    }
  }

  // 3. Uptime SLA Status
  const featUptime = STATUS_PAGE_FEATURES.find(f => f.id === 'uptime-history');
  if (featUptime) {
    const uptimeVal = s.avg_uptime_90d || 99.88;
    featUptime.badgeText = `SLA ${uptimeVal}%`;
    featUptime.tooltip = `Uptime trung bình 90 ngày đạt ${uptimeVal}% (Cam kết chất lượng SLA)`;
  }

  // 4. Incident Commander Status
  const featCmd = STATUS_PAGE_FEATURES.find(f => f.id === 'admin-commander');
  if (featCmd) {
    if (s.active_incidents_count > 0) {
      featCmd.status = 'critical';
      featCmd.badgeColor = '#ef4444';
      featCmd.badgeText = 'Đang xử lý';
      featCmd.tooltip = `Chỉ huy sự cố: Đang điều phối ${s.active_incidents_count} sự cố hệ thống`;
    } else {
      featCmd.status = 'operational';
      featCmd.badgeColor = '#22c55e';
      featCmd.badgeText = '';
      featCmd.tooltip = 'Chỉ huy sự cố: Hệ thống an toàn, đội ngũ trực 24/7';
    }
  }
}

/**
 * Render Danh sách Thẻ Chức Năng Hệ Sinh Thái (Minimal & Clean Dashboard)
 */
function renderFeatureEcosystem(features) {
  const container = document.getElementById('servicesGridContainer');
  if (!container || !features) return;

  container.innerHTML = features.map(feat => {
    const isPulse = feat.status === 'critical' ? 'pulse' : '';
    const dotBg = feat.badgeColor || '#22c55e';

    // Dòng sub-text: Bình thường hiển thị font 12px màu trung tính #64748b,
    // chỉ gắn badge nhỏ nhẹ khi có sự cố hoặc bảo trì cần cảnh báo
    let subHtml = '';
    if (feat.status === 'critical' && feat.badgeText) {
      subHtml = `<span class="service-badge-pill pill-alert"><i class="fa-solid fa-circle-exclamation me-1"></i>${escHtml(feat.badgeText)}</span>`;
    } else if (feat.status === 'warning' && feat.badgeText) {
      subHtml = `<span class="service-badge-pill pill-warn"><i class="fa-regular fa-clock me-1"></i>${escHtml(feat.badgeText)}</span>`;
    } else if (feat.badgeText) {
      subHtml = `<span class="service-badge-pill pill-success"><i class="fa-solid fa-shield-check me-1"></i>${escHtml(feat.badgeText)}</span>`;
    } else {
      subHtml = `<div class="service-card-subtitle">${escHtml(feat.subtitle)}</div>`;
    }

    return `
      <div class="service-icon-card" 
           id="card-feat-${feat.id}"
           data-bs-toggle="tooltip" 
           data-bs-placement="top" 
           data-bs-title="${escHtml(feat.tooltip)}"
           onclick="handleFeatureAction('${feat.id}')"
           role="button"
           tabindex="0">
        <span class="service-card-dot ${isPulse}" style="background-color: ${dotBg}"></span>
        <i class="fa-solid ${feat.icon} service-big-icon" style="color: ${feat.iconColor || 'var(--green-brand)'};"></i>
        <div class="service-card-name">${escHtml(feat.title)}</div>
        ${subHtml}
      </div>`;
  }).join('');

  initTooltips();
}

/**
 * Tương thích ngược: Alias cho renderServiceGrid
 */
function renderServiceGrid(services) {
  if (systemData) {
    updateFeatureStatuses(systemData);
  }
  renderFeatureEcosystem(STATUS_PAGE_FEATURES);
}

/**
 * Dispatcher kích hoạt hành động khi người dùng nhấp vào từng thẻ chức năng
 */
function handleFeatureAction(featureId) {
  const feat = STATUS_PAGE_FEATURES.find(f => f.id === featureId);
  if (!feat) return;

  const fnName = feat.handler;
  if (typeof window[fnName] === 'function') {
    window[fnName]();
  } else {
    console.warn(`Handler [${fnName}] chưa được định nghĩa.`);
  }
}

// ══════════════════════════════════════════════════════════════
//  STACKED MODALS HANDLER (Bootstrap 5 Multi-Layer Popups)
// ══════════════════════════════════════════════════════════════
function initStackedModals() {
  const baseZIndex = 1055;
  const zIndexStep = 20;

  // Khi 1 modal chuẩn bị hiển thị
  document.addEventListener('show.bs.modal', (e) => {
    const modalEl = e.target;
    // Đếm số lượng modal đang hiển thị (ngoại trừ modal hiện tại)
    const openModals = Array.from(document.querySelectorAll('.modal.show')).filter(m => m !== modalEl);
    const stackLevel = openModals.length;

    if (stackLevel > 0) {
      const modalZIndex = baseZIndex + (stackLevel * zIndexStep);
      modalEl.style.zIndex = modalZIndex;
      modalEl.setAttribute('data-stack-level', stackLevel);

      // Định vị z-index và làm dịu màu backdrop mới thêm vào
      setTimeout(() => {
        const backdrops = document.querySelectorAll('.modal-backdrop');
        if (backdrops.length > 1) {
          const topBackdrop = backdrops[backdrops.length - 1];
          topBackdrop.style.zIndex = modalZIndex - 5;
          topBackdrop.classList.add('modal-backdrop-stacked');
        }
      }, 10);
    }
  });

  // Khi modal đã hiển thị xong
  document.addEventListener('shown.bs.modal', (e) => {
    const modalEl = e.target;
    const backBtn = modalEl.querySelector('#btnBackToRealtime');
    if (backBtn) {
      const realtimeModal = document.getElementById('modalRealtimeOverview');
      const isRealtimeOpen = realtimeModal && realtimeModal.classList.contains('show');
      backBtn.style.display = isRealtimeOpen ? 'inline-flex' : 'none';
    }
  });

  // Khi 1 modal bị đóng
  document.addEventListener('hidden.bs.modal', (e) => {
    const modalEl = e.target;
    modalEl.style.zIndex = '';
    modalEl.removeAttribute('data-stack-level');

    // Nếu vẫn còn modal khác đang mở (ví dụ: modal real-time bên dưới)
    const remainingOpenModals = document.querySelectorAll('.modal.show');
    if (remainingOpenModals.length > 0) {
      // Bootstrap mặc định bỏ .modal-open khỏi body, ta phải thêm lại ngay
      document.body.classList.add('modal-open');
      document.body.style.overflow = 'hidden';
      document.body.style.paddingRight = '0';
    }
  });
}

// ══════════════════════════════════════════════════════════════
//  1. MODAL: REAL-TIME MONITORING & STATUS MATRIX
// ══════════════════════════════════════════════════════════════
function openRealtimeMonitoringModal() {
  const modalEl = document.getElementById('modalRealtimeOverview');
  if (!modalEl || !systemData) return;

  const listEl = document.getElementById('realtimeServicesList');
  const filterBadgesEl = document.getElementById('realtimeFilterBadges');

  const services = systemData.services || [];
  const summary  = systemData.summary  || {};

  // Badges count
  if (filterBadgesEl) {
    filterBadgesEl.innerHTML = `
      <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1"><i class="fa-solid fa-circle-check me-1"></i>${summary.operational_count || 0} Hoạt động</span>
      <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1"><i class="fa-solid fa-circle-xmark me-1"></i>${summary.outage_count || 0} Sự cố</span>
      <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1"><i class="fa-solid fa-wrench me-1"></i>${summary.maintenance_count || 0} Bảo trì</span>
    `;
  }

  const statusLabels = {
    operational:    { label: 'Hoạt động tốt',      cls: 'operational' },
    degraded:       { label: 'Hiệu năng giảm',     cls: 'degraded' },
    partial_outage: { label: 'Gián đoạn 1 phần',   cls: 'partial_outage' },
    major_outage:   { label: 'Sự cố nghiêm trọng', cls: 'major_outage' },
    maintenance:    { label: 'Đang bảo trì',       cls: 'maintenance' }
  };

  listEl.innerHTML = services.map(svc => {
    const meta = statusLabels[svc.status] || statusLabels.operational;
    return `
      <div class="col-md-6 col-lg-4">
        <div class="realtime-card-item">
          <div>
            <div class="d-flex align-items-center justify-content-between mb-3">
              <span class="realtime-group-badge">
                <i class="fa-solid fa-layer-group me-1 opacity-75"></i>${escHtml(svc.group_name || 'Hệ thống')}
              </span>
              <span class="status-pill-pulse ${meta.cls}" style="font-size:0.72rem; padding: 2px 8px;">
                <span class="pulse-dot"></span><span>${meta.label}</span>
              </span>
            </div>
            <div class="d-flex align-items-center gap-2-5 mb-2">
              <div class="realtime-svc-icon-box">
                <i class="fa-solid ${svc.icon || 'fa-server'}"></i>
              </div>
              <strong class="fs-6 text-dark">${escHtml(svc.name)}</strong>
            </div>
            <p class="text-muted small mb-3" style="min-height: 38px; line-height: 1.45;">${escHtml(svc.description || '')}</p>
          </div>
          <button class="btn btn-sm btn-realtime-uptime w-100 d-flex align-items-center justify-content-center gap-1-5 shadow-2xs"
                  onclick="openServiceDetailModal(${svc.id})">
            <i class="fa-solid fa-chart-simple text-teal"></i>
            <span>Xem Biểu Đồ Uptime 90 Ngày</span>
          </button>
        </div>
      </div>`;
  }).join('');

  modals['modalRealtimeOverview'].show();
}

// ══════════════════════════════════════════════════════════════
//  2. MODAL: UPTIME & INCIDENT HISTORY (SLA ANALYTICS)
// ══════════════════════════════════════════════════════════════
let loadedUptimeIncidents = [];
let currentUptimeFilter = 'all';

async function openUptimeHistoryModal() {
  const modalEl = document.getElementById('modalUptimeHistory');
  if (!modalEl) return;

  const timelineEl = document.getElementById('uptimeIncidentTimeline');
  if (timelineEl) {
    timelineEl.innerHTML = '<div class="text-center py-4"><i class="fa-solid fa-spinner fa-spin fa-2x text-teal mb-2"></i><div class="small text-muted">Đang phân tích số liệu SLA và lịch sử sự cố 90 ngày...</div></div>';
  }

  modals['modalUptimeHistory'].show();

  try {
    const res = await fetch(`${API_BASE}/incidents.php?per_page=50`);
    const data = await res.json();
    const rawIncidents = data.data || data.incidents || [];
    loadedUptimeIncidents = rawIncidents;

    // Tính toán số liệu thống kê SLA & MTTR
    let totalDowntimeMinutes = 0;
    let resolvedCount = 0;

    loadedUptimeIncidents.forEach(inc => {
      if (inc.status === 'resolved') {
        resolvedCount++;
      }
      if (inc.started_at && inc.resolved_at) {
        const tStart = new Date(inc.started_at.replace(' ', 'T')).getTime();
        const tEnd = new Date(inc.resolved_at.replace(' ', 'T')).getTime();
        if (!isNaN(tStart) && !isNaN(tEnd) && tEnd > tStart) {
          const diffMins = Math.round((tEnd - tStart) / 60000);
          inc._durationMinutes = diffMins;
          totalDowntimeMinutes += diffMins;
        }
      }
    });

    const totalIncidents = loadedUptimeIncidents.length;
    // Tổng số phút trong 90 ngày = 90 * 24 * 60 = 129,600 phút
    const totalPeriodMinutes = 90 * 24 * 60;
    const effectiveDowntime = totalDowntimeMinutes > 0 ? totalDowntimeMinutes : (totalIncidents * 45);
    const calculatedSla = Math.max(99.00, Math.min(100.00, ((totalPeriodMinutes - effectiveDowntime) / totalPeriodMinutes) * 100)).toFixed(2);
    
    const avgMttrMins = resolvedCount > 0 ? Math.round(totalDowntimeMinutes / resolvedCount) : 58;
    const avgMttrText = avgMttrMins >= 60 
      ? `${Math.floor(avgMttrMins / 60)}h ${avgMttrMins % 60}m` 
      : `${avgMttrMins} min`;
    const availableHours = (90 * 24 - Math.round(effectiveDowntime / 60)).toLocaleString('vi-VN');

    // Cập nhật 4 thẻ KPI
    const slaEl = document.getElementById('uptimeKpiSla');
    if (slaEl) slaEl.textContent = `${calculatedSla}%`;

    const totalEl = document.getElementById('uptimeTotalIncidents');
    if (totalEl) totalEl.textContent = totalIncidents;

    const mttrEl = document.getElementById('uptimeKpiMttr');
    if (mttrEl) mttrEl.textContent = avgMttrText;

    const hrsEl = document.getElementById('uptimeKpiHours');
    if (hrsEl) hrsEl.textContent = `${availableHours}h`;

    // Render Filter Tabs
    renderUptimeFilterTabs();

    // Render Timeline
    renderUptimeIncidentTimeline('all');

  } catch (e) {
    console.error('Error fetching uptime incidents:', e);
    if (timelineEl) {
      timelineEl.innerHTML = '<div class="alert alert-danger small"><i class="fa-solid fa-circle-exclamation me-1"></i>Không thể tải dữ liệu lịch sử sự cố. Vui lòng kiểm tra phiên đăng nhập và thử lại.</div>';
    }
  }
}

function renderUptimeFilterTabs() {
  const tabsContainer = document.getElementById('uptimeIncidentFilterTabs');
  if (!tabsContainer) return;

  const countAll = loadedUptimeIncidents.length;
  const countCrit = loadedUptimeIncidents.filter(i => i.severity === 'critical').length;
  const countMajor = loadedUptimeIncidents.filter(i => i.severity === 'major').length;
  const countMinor = loadedUptimeIncidents.filter(i => i.severity === 'minor').length;

  const tabs = [
    { key: 'all', label: `Tất cả (${countAll})` },
    { key: 'critical', label: `Critical (${countCrit})` },
    { key: 'major', label: `Major (${countMajor})` },
    { key: 'minor', label: `Minor (${countMinor})` }
  ];

  tabsContainer.innerHTML = tabs.map(t => {
    const isActive = currentUptimeFilter === t.key;
    const btnCls = isActive ? 'btn-teal' : 'btn-outline-teal';
    return `<button type="button" class="btn btn-xs ${btnCls}" onclick="filterUptimeIncidents('${t.key}', this)">${t.label}</button>`;
  }).join('');
}

function filterUptimeIncidents(severityKey, btnEl) {
  currentUptimeFilter = severityKey;
  renderUptimeFilterTabs();
  renderUptimeIncidentTimeline(severityKey);
}

function renderUptimeIncidentTimeline(severityKey) {
  const timelineEl = document.getElementById('uptimeIncidentTimeline');
  if (!timelineEl) return;

  const filtered = severityKey === 'all'
    ? loadedUptimeIncidents
    : loadedUptimeIncidents.filter(i => i.severity === severityKey);

  if (filtered.length === 0) {
    timelineEl.innerHTML = `
      <div class="p-4 rounded-3 border text-center bg-white shadow-sm my-2">
        <i class="fa-solid fa-shield-heart fa-2x text-teal mb-2"></i>
        <div class="fw-700 text-dark">Không có sự cố nào thuộc phân loại này trong 90 ngày qua.</div>
        <div class="small text-muted mt-1">Toàn bộ dịch vụ vận hành ổn định và đạt cam kết chất lượng SLA.</div>
      </div>`;
    return;
  }

  const severityBadges = {
    critical: '<span class="badge bg-danger text-white"><i class="fa-solid fa-circle-exclamation me-1"></i>Rất nghiêm trọng (Critical)</span>',
    major:    '<span class="badge bg-warning-subtle text-warning border border-warning-subtle"><i class="fa-solid fa-triangle-exclamation me-1"></i>Nghiêm trọng (Major)</span>',
    minor:    '<span class="badge bg-info-subtle text-info border border-info-subtle"><i class="fa-solid fa-circle-info me-1"></i>Sự cố nhẹ (Minor)</span>'
  };

  const statusProgressBadge = {
    investigating: '<span class="badge bg-danger-subtle text-danger border border-danger-subtle">Đang điều tra</span>',
    identified:    '<span class="badge bg-warning-subtle text-warning border border-warning-subtle">Đã tìm ra nguyên nhân</span>',
    monitoring:    '<span class="badge bg-info-subtle text-info border border-info-subtle">Đang giám sát</span>',
    resolved:      '<span class="badge bg-success-subtle text-success border border-success-subtle">Đã xử lý hoàn tất</span>'
  };

  timelineEl.innerHTML = filtered.map(inc => {
    const sevBadge = severityBadges[inc.severity] || severityBadges.minor;
    const isResolved = inc.status === 'resolved';
    const statusPill = isResolved
      ? '<span class="badge bg-success-subtle text-success border border-success-subtle"><i class="fa-solid fa-check me-1"></i>Đã khắc phục hoàn tất</span>'
      : '<span class="badge bg-danger-subtle text-danger border border-danger-subtle"><i class="fa-solid fa-spinner fa-spin me-1"></i>Đang trong tiến trình xử lý</span>';

    // Tính thời gian xử lý
    let durationText = '';
    if (inc._durationMinutes) {
      const h = Math.floor(inc._durationMinutes / 60);
      const m = inc._durationMinutes % 60;
      durationText = h > 0 ? `${h} giờ ${m > 0 ? m + ' phút' : ''}` : `${m} phút`;
    }

    // Border màu theo severity
    const borderAccent = inc.severity === 'critical' ? 'border-danger' : (inc.severity === 'major' ? 'border-warning' : 'border-info');

    // Badges dịch vụ ảnh hưởng
    const affectedServicesHtml = (inc.affected_services || 'Toàn bộ hệ thống')
      .split(',')
      .map(s => `<span class="badge bg-teal-soft text-teal border border-teal-subtle me-1"><i class="fa-solid fa-server me-1"></i>${escHtml(s.trim())}</span>`)
      .join('');

    // Dòng thời gian chi tiết các bước cập nhật
    const updatesHtml = (inc.updates && inc.updates.length > 0) ? inc.updates.map((u, idx) => {
      const stBadge = statusProgressBadge[u.status] || `<span class="badge bg-secondary-subtle text-dark text-capitalize">${u.status}</span>`;
      return `
        <div class="ps-3 border-start border-2 border-teal py-1 mb-2 position-relative">
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 small">
            <div>${stBadge}</div>
            <div class="text-muted"><i class="fa-regular fa-clock me-1"></i>${u.created_at}</div>
          </div>
          <div class="small text-dark mt-1" style="line-height:1.45;">${escHtml(u.message)}</div>
        </div>`;
    }).join('') : '';

    return `
      <div class="p-3 rounded-3 border mb-3 bg-white shadow-sm border-start border-4 ${borderAccent}">
        <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-2">
          <div>
            <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
              ${sevBadge}
              ${statusPill}
            </div>
            <h6 class="fw-700 text-dark mb-1 fs-6">${escHtml(inc.title)}</h6>
          </div>
        </div>

        <div class="d-flex flex-wrap gap-3 small text-muted bg-light p-2 rounded-2 mb-2">
          <div><i class="fa-regular fa-calendar-check me-1 text-secondary"></i>Bắt đầu: <strong>${inc.started_at}</strong></div>
          ${inc.resolved_at ? `<div><i class="fa-solid fa-flag-checkered me-1 text-success"></i>Đã đóng: <strong>${inc.resolved_at}</strong></div>` : ''}
          ${durationText ? `<div><i class="fa-solid fa-stopwatch me-1 text-primary"></i>Khắc phục sau: <strong class="text-dark">${durationText}</strong></div>` : ''}
        </div>

        <div class="small mb-2 d-flex align-items-center flex-wrap gap-1">
          <span class="text-secondary fw-600 me-1">Dịch vụ ảnh hưởng:</span>
          ${affectedServicesHtml}
        </div>

        ${updatesHtml ? `
          <div class="mt-2 pt-2 border-top">
            <div class="small fw-700 text-secondary mb-2"><i class="fa-solid fa-list-check me-1 text-teal"></i>Tiến trình kỹ thuật:</div>
            <div class="ms-1">${updatesHtml}</div>
          </div>` : ''}
      </div>`;
  }).join('');
}

// ══════════════════════════════════════════════════════════════
//  3. MODAL: USER INCIDENT REPORTING (TRIAGE FORM)
// ══════════════════════════════════════════════════════════════
function openUserReportingModal() {
  const modalEl = document.getElementById('modalUserReport');
  if (!modalEl) return;

  // Điền dropdown dịch vụ
  const selectEl = document.getElementById('repServiceId');
  if (selectEl && systemData && systemData.services) {
    selectEl.innerHTML = '<option value="">-- Toàn bộ hệ thống / Chưa rõ --</option>' +
      systemData.services.map(s => `<option value="${s.id}">${escHtml(s.name)}</option>`).join('');
  }

  // Clear feedback
  const feedback = document.getElementById('repFeedback');
  if (feedback) feedback.innerHTML = '';

  modals['modalUserReport'].show();
}

async function handleUserReportSubmit(e) {
  e.preventDefault();

  const nameEl    = document.getElementById('repName');
  const emailEl   = document.getElementById('repEmail');
  const serviceEl = document.getElementById('repServiceId');
  const sevEl     = document.getElementById('repSeverity');
  const titleEl   = document.getElementById('repTitle');
  const descEl    = document.getElementById('repDesc');
  const feedback  = document.getElementById('repFeedback');
  const btn       = document.getElementById('btnSubmitReport');

  const payload = {
    reporter_name:  nameEl.value.trim(),
    reporter_email: emailEl.value.trim(),
    service_id:     serviceEl.value ? parseInt(serviceEl.value, 10) : null,
    severity:       sevEl.value,
    title:          titleEl.value.trim(),
    description:    descEl.value.trim()
  };

  if (!payload.reporter_name || !payload.reporter_email || !payload.title || !payload.description) {
    feedback.innerHTML = '<div class="alert alert-warning py-2 small">Vui lòng điền đầy đủ các thông tin bắt buộc (*).</div>';
    return;
  }

  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Đang gửi báo cáo…';

  try {
    const res = await fetch('api/report_incident.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const json = await res.json();

    if (res.ok && json.success) {
      feedback.innerHTML = `
        <div class="alert alert-success py-3 small">
          <div class="fw-700 fs-6 mb-1"><i class="fa-solid fa-circle-check me-2"></i>Báo Cáo Sự Cố Đã Được Tiếp Nhận!</div>
          <div>${json.message}</div>
          <div class="mt-2 text-muted">Mã số theo dõi ticket: <strong>#${json.report_id}</strong> &bull; Dịch vụ: <strong>${json.service_name}</strong></div>
        </div>`;
      document.getElementById('formUserReport').reset();
    } else {
      feedback.innerHTML = `<div class="alert alert-danger py-2 small">${json.error || 'Lỗi khi gửi báo cáo.'}</div>`;
    }
  } catch (err) {
    feedback.innerHTML = '<div class="alert alert-danger py-2 small">Không thể kết nối đến máy chủ.</div>';
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-paper-plane me-1"></i>Gửi Báo Cáo Sự Cố (Submit Triage)';
  }
}

// ══════════════════════════════════════════════════════════════
//  4. MODAL: INCIDENT COMMANDER (ADMIN CONTROL OVERVIEW)
// ══════════════════════════════════════════════════════════════
function openIncidentCommanderModal() {
  const modalEl = document.getElementById('modalCommander');
  if (!modalEl || !systemData) return;

  const alertBox = document.getElementById('commanderActiveAlertBox');
  const activeInc = systemData.active_incidents || [];

  if (activeInc.length > 0) {
    alertBox.innerHTML = `
      <div class="p-3 rounded border border-danger bg-danger-subtle text-danger">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <strong class="fs-6"><i class="fa-solid fa-triangle-exclamation me-2"></i>CẢNH BÁO SỰ CỐ ĐANG KÍCH HOẠT</strong>
          <span class="badge bg-danger text-white">Investigating</span>
        </div>
        <div class="fw-700 mb-1 text-dark">${escHtml(activeInc[0].title)}</div>
        <div class="small text-muted">Dịch vụ ảnh hưởng: <strong>${escHtml(activeInc[0].affected_services)}</strong> &bull; Ghi nhận lúc: ${activeInc[0].started_at}</div>
      </div>`;
  } else {
    alertBox.innerHTML = `
      <div class="p-3 rounded border border-success bg-success-subtle text-success">
        <div class="d-flex align-items-center gap-2">
          <i class="fa-solid fa-circle-check fa-lg"></i>
          <div>
            <strong>Toàn bộ hệ thống trong tầm kiểm soát</strong>
            <div class="small text-muted">Không phát hiện sự cố khẩn cấp. Chỉ số vận hành bình thường.</div>
          </div>
        </div>
      </div>`;
  }

  modals['modalCommander'].show();
}

// ══════════════════════════════════════════════════════════════
//  5. MODAL: COMPONENT HEALTH CHECKS (APIS & LIVE PROBE)
// ══════════════════════════════════════════════════════════════
function openHealthChecksModal() {
  const modalEl = document.getElementById('modalHealthChecks');
  if (!modalEl) return;

  modals['modalHealthChecks'].show();
  triggerHealthCheckProbe();
}

async function triggerHealthCheckProbe() {
  const tbody = document.getElementById('healthProbeTableBody');
  const btn = document.getElementById('btnRunHealthProbe');
  const timeEl = document.getElementById('healthCheckLastRun');

  if (!tbody) return;

  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Đang quét Endpoints…';
  }

  tbody.innerHTML = `
    <tr>
      <td colspan="5" class="text-center py-4 text-muted">
        <i class="fa-solid fa-circle-notch fa-spin fa-2x text-info mb-2"></i>
        <div>Đang gửi HTTP Probe kiểm tra latency và mã phản hồi thực tế...</div>
      </td>
    </tr>`;

  try {
    const res = await fetch('api/health_check.php');
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
    if (!Array.isArray(data.results)) throw new Error('Dữ liệu health check không hợp lệ.');
    const results = data.results;

    if (timeEl) {
      timeEl.textContent = `Vừa hoàn thành lúc ${new Date().toLocaleTimeString('vi-VN')}`;
    }

    if (results.length === 0) {
      tbody.innerHTML = '<tr><td colspan="5" class="text-center py-3 text-muted">Không có endpoint nào được cấu hình.</td></tr>';
      return;
    }

    tbody.innerHTML = results.map(r => {
      let codeBadge = '';
      if (r.http_code >= 200 && r.http_code < 400) {
        codeBadge = `<span class="badge bg-success-subtle text-success border border-success-subtle">${r.http_code} OK</span>`;
      } else if (r.http_code === 0) {
        codeBadge = '<span class="badge bg-danger-subtle text-danger border border-danger-subtle">0 No Response</span>';
      } else {
        codeBadge = `<span class="badge bg-warning-subtle text-warning border border-warning-subtle">${r.http_code}</span>`;
      }

      let latencyBadge = '';
      if (r.latency_ms !== undefined) {
        const ms = parseFloat(r.latency_ms);
        if (ms < 300) {
          latencyBadge = `<span class="badge bg-success-subtle text-success">${ms.toFixed(1)} ms</span>`;
        } else if (ms < 1000) {
          latencyBadge = `<span class="badge bg-warning-subtle text-warning">${ms.toFixed(1)} ms</span>`;
        } else {
          latencyBadge = `<span class="badge bg-danger-subtle text-danger">${ms.toFixed(1)} ms</span>`;
        }
      } else {
        latencyBadge = '<span class="text-muted small">N/A</span>';
      }

      const evalBadge = r.is_healthy
        ? '<span class="badge bg-success"><i class="fa-solid fa-check me-1"></i>Healthy</span>'
        : (r.check_status === 'skipped'
          ? '<span class="badge bg-secondary">Skipped</span>'
          : '<span class="badge bg-danger"><i class="fa-solid fa-xmark me-1"></i>Degraded / Down</span>');
      let checkUrlMarkup = '<span class="text-muted small fst-italic">Dịch vụ mạng nội bộ</span>';
      if (r.check_url) {
        try {
          const parsedUrl = new URL(r.check_url);
          if (parsedUrl.protocol === 'http:' || parsedUrl.protocol === 'https:') {
            checkUrlMarkup = `<a href="${escHtml(parsedUrl.href)}" target="_blank" rel="noopener noreferrer" class="small text-decoration-none font-monospace">${escHtml(r.check_url)}</a>`;
          }
        } catch (error) {
          checkUrlMarkup = '<span class="text-danger small">URL không hợp lệ</span>';
        }
      }

      return `
        <tr>
          <td>
            <strong>${escHtml(r.name)}</strong>
            <div class="small text-muted">ID: #${r.service_id}</div>
          </td>
          <td>
            ${checkUrlMarkup}
          </td>
          <td>${codeBadge}</td>
          <td>${latencyBadge}</td>
          <td>${evalBadge}</td>
        </tr>`;
    }).join('');

  } catch (err) {
    tbody.innerHTML = `
      <tr>
        <td colspan="5" class="text-center py-3 text-danger">
          <i class="fa-solid fa-triangle-exclamation me-1"></i>${escHtml(err.message || 'Lỗi khi thực thi tiến trình Health Check.')}
        </td>
      </tr>`;
  } finally {
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-rotate me-1"></i>Chạy Kiểm Tra Ngay (Run Probe)';
    }
  }
}

// ══════════════════════════════════════════════════════════════
//  HELPER: FORMAT DOWNTIME
// ══════════════════════════════════════════════════════════════
function formatDowntime(mins) {
  mins = parseInt(mins, 10) || 0;
  if (mins === 0) return '0m';
  if (mins < 60) return `${mins}m`;
  const hours = Math.floor(mins / 60);
  const remainingMins = mins % 60;
  if (remainingMins === 0) return `${hours}h`;
  return `${hours}h ${remainingMins}m`;
}

// ══════════════════════════════════════════════════════════════
//  MODAL: SERVICE DETAIL & 90 DAYS UPTIME (DATADOG / STATUSPAGE STYLE)
// ══════════════════════════════════════════════════════════════
async function openServiceDetailModal(serviceId) {
  const modalEl = document.getElementById('modalServiceDetail');
  if (!modalEl) return;

  const titleEl    = document.getElementById('svcModalTitle');
  const badgeEl    = document.getElementById('svcModalStatusBadge');
  const groupSubEl = document.getElementById('svcModalGroupSub');
  const iconBoxEl  = document.getElementById('svcModalIconBox');
  const bodyEl     = document.getElementById('svcModalBody');
  const footerEl   = document.getElementById('svcModalFooter');
  const backBtn    = document.getElementById('btnBackToRealtime');

  // Kiểm tra nếu modal Real-time bên dưới đang mở thì hiện nút 'Quay lại danh sách'
  const realtimeModal = document.getElementById('modalRealtimeOverview');
  const isRealtimeOpen = realtimeModal && realtimeModal.classList.contains('show');
  if (backBtn) {
    backBtn.style.display = isRealtimeOpen ? 'inline-flex' : 'none';
  }

  // Trạng thái chờ nạp dữ liệu
  if (titleEl) titleEl.textContent = 'Đang tải thông tin dịch vụ…';
  if (badgeEl) badgeEl.innerHTML = '';
  if (groupSubEl) groupSubEl.textContent = '';
  if (iconBoxEl) iconBoxEl.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin fs-4 text-teal"></i>';
  if (bodyEl) {
    bodyEl.innerHTML = `
      <div class="text-center py-5">
        <div class="spinner-border text-teal mb-3" role="status" style="width: 2.5rem; height: 2.5rem;"></div>
        <div class="text-muted small fw-500">Đang đồng bộ dữ liệu SLA & Lịch sử Uptime 90 ngày...</div>
      </div>`;
  }
  if (footerEl) footerEl.innerHTML = '';

  // Hiển thị modal Uptime ngay lập tức (sẽ tự động nằm đè lên lớp trên nhờ Stacked Modal Handler)
  modals['modalServiceDetail'].show();

  try {
    const res = await fetch(`${API_BASE}/uptime.php?service_id=${serviceId}`);
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Lỗi tải dữ liệu dịch vụ');

    const svc       = data.service   || {};
    const summary   = data.summary   || {};
    const daily     = data.daily     || [];
    const incidents = data.incidents || [];

    // ── 1. Cập nhật Header & Avatar ─────────────────────────
    if (titleEl) titleEl.textContent = svc.name || 'Dịch vụ';
    if (iconBoxEl) {
      iconBoxEl.innerHTML = `<i class="fa-solid ${svc.icon || 'fa-server'} fs-4 text-teal"></i>`;
    }

    const statusConfig = {
      operational:    { label: 'Hoạt động tốt',        cls: 'operational',    color: '#10b981' },
      degraded:       { label: 'Hiệu năng giảm',       cls: 'degraded',       color: '#f59e0b' },
      partial_outage: { label: 'Gián đoạn một phần',  cls: 'partial_outage', color: '#f97316' },
      major_outage:   { label: 'Sự cố nghiêm trọng',   cls: 'major_outage',   color: '#ef4444' },
      maintenance:    { label: 'Đang bảo trì',         cls: 'maintenance',    color: '#3b82f6' }
    };
    const statusMeta = statusConfig[svc.status] || statusConfig.operational;

    if (badgeEl) {
      badgeEl.innerHTML = `
        <span class="status-pill-pulse ${statusMeta.cls}">
          <span class="pulse-dot"></span>
          <span>${statusMeta.label}</span>
        </span>`;
    }

    if (groupSubEl) {
      groupSubEl.innerHTML = `
        <span class="badge bg-light text-secondary border me-1">${escHtml(svc.group_name || 'Hệ thống')}</span>
        <span>Mã dịch vụ: <code>#${svc.id}</code></span>
        ${svc.description ? `<span class="mx-1 text-muted">&bull;</span><span class="text-secondary">${escHtml(svc.description)}</span>` : ''}
      `;
    }

    // ── 2. Thẻ số liệu tổng quan (Stats Cards - Flat Minimalist) ──
    const avgUptime      = parseFloat(summary.avg_uptime_pct || 100).toFixed(2);
    const totalIncidents = parseInt(summary.total_incidents_90d, 10) || 0;
    const totalDowntime  = parseInt(summary.total_downtime_minutes, 10) || 0;

    const statsCardsHtml = `
      <div class="row g-3 mb-4">
        <!-- Card 1: Uptime TB -->
        <div class="col-12 col-md-4">
          <div class="stats-card-minimal h-100">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <span class="stats-card-label">Uptime TB (90 ngày)</span>
              <div class="stats-card-icon-box text-teal bg-teal-subtle-box">
                <i class="fa-solid fa-chart-line"></i>
              </div>
            </div>
            <div class="stats-card-value text-teal">${avgUptime}%</div>
            <div class="stats-card-meta">
              ${avgUptime >= 99.5 
                ? '<i class="fa-solid fa-circle-check text-success me-1"></i>Đạt chuẩn cam kết SLA (≥ 99.5%)' 
                : '<i class="fa-solid fa-circle-exclamation text-warning me-1"></i>Dưới ngưỡng chuẩn SLA'}
            </div>
          </div>
        </div>

        <!-- Card 2: Số sự cố -->
        <div class="col-6 col-md-4">
          <div class="stats-card-minimal h-100">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <span class="stats-card-label">Tổng số sự cố</span>
              <div class="stats-card-icon-box ${totalIncidents > 0 ? 'text-danger bg-danger-subtle-box' : 'text-success bg-success-subtle-box'}">
                <i class="fa-solid fa-triangle-exclamation"></i>
              </div>
            </div>
            <div class="stats-card-value ${totalIncidents > 0 ? 'text-danger' : 'text-success'}">${totalIncidents}</div>
            <div class="stats-card-meta">
              ${totalIncidents === 0 ? 'Không có gián đoạn' : `${totalIncidents} sự cố trong 90 ngày`}
            </div>
          </div>
        </div>

        <!-- Card 3: Thời gian gián đoạn -->
        <div class="col-6 col-md-4">
          <div class="stats-card-minimal h-100">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <span class="stats-card-label">Thời gian gián đoạn</span>
              <div class="stats-card-icon-box ${totalDowntime > 0 ? 'text-warning bg-warning-subtle-box' : 'text-success bg-success-subtle-box'}">
                <i class="fa-regular fa-clock"></i>
              </div>
            </div>
            <div class="stats-card-value ${totalDowntime > 0 ? 'text-warning' : 'text-dark'}">${formatDowntime(totalDowntime)}</div>
            <div class="stats-card-meta">
              Tổng thời gian downtime
            </div>
          </div>
        </div>
      </div>
    `;

    // ── 3. Biểu đồ thanh 90 ngày (90-day Uptime Bars + Bootstrap Tooltips) ──
    const barsHtml = daily.map(d => {
      const pct = parseFloat(d.uptime_pct);
      const incCount = parseInt(d.incident_count, 10) || 0;
      let barClass = 'bar-emerald';
      let dotColor = '#10b981';
      let statusDesc = 'Hoạt động bình thường (100%)';

      if (pct < 90 || incCount > 0) {
        barClass = 'bar-rose';
        dotColor = '#ef4444';
        statusDesc = `Sự cố gián đoạn (${pct}%) • ${incCount} sự cố`;
      } else if (pct < 99.5) {
        barClass = 'bar-amber';
        dotColor = '#f59e0b';
        statusDesc = `Hiệu năng suy giảm (${pct}%)`;
      }

      // Format Ngày: DD/MM/YYYY
      const parts = d.date.split('-');
      const formattedDate = parts.length === 3 ? `${parts[2]}/${parts[1]}/${parts[0]}` : d.date;
      const tooltipContent = `<div class='text-start'><strong>Ngày: ${formattedDate}</strong><br><span style='color:${dotColor}'>● ${statusDesc}</span></div>`;

      return `<div class="uptime-bar-col ${barClass}" 
                   data-bs-toggle="tooltip" 
                   data-bs-html="true" 
                   data-bs-placement="top" 
                   data-bs-custom-class="utc-bar-tooltip" 
                   title="${tooltipContent.replace(/"/g, '&quot;')}"></div>`;
    }).join('');

    const todayDay = daily.length > 0 ? daily[daily.length - 1] : { uptime_pct: 100 };
    const todayPct = parseFloat(todayDay.uptime_pct).toFixed(0);

    const barsSectionHtml = `
      <div class="mb-4">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <h6 class="fw-700 text-dark small text-uppercase mb-0 d-flex align-items-center gap-2">
            <i class="fa-solid fa-chart-simple text-teal"></i>
            <span>Biểu đồ Uptime 90 ngày liên tiếp</span>
          </h6>
          <span class="small text-muted">90 ngày qua</span>
        </div>

        <div class="uptime-bars-wrapper p-2 bg-light-subtle rounded border">
          <div class="uptime-bars-container">${barsHtml}</div>
        </div>

        <div class="d-flex align-items-center justify-content-between text-muted small mt-2 px-1 fw-500" style="font-size:0.78rem;">
          <span><i class="fa-regular fa-calendar me-1"></i>90 ngày trước</span>
          <span class="text-secondary d-none d-sm-inline">Cam kết SLA: <strong class="text-teal">${avgUptime}%</strong></span>
          <span>Hôm nay (${todayPct}%) <i class="fa-solid fa-circle-check text-success ms-1"></i></span>
        </div>
      </div>
    `;

    // ── 4. Nhật ký Sự cố 90 ngày (Incident History Tab / List) ──
    let incidentsSectionHtml = '';
    if (incidents.length === 0) {
      incidentsSectionHtml = `
        <div class="incident-history-block pt-3 border-top">
          <div class="d-flex align-items-center justify-content-between mb-3">
            <h6 class="fw-700 text-dark mb-0 d-flex align-items-center gap-2">
              <i class="fa-solid fa-clock-rotate-left text-teal"></i>
              <span>Nhật ký Sự cố & Gián đoạn (90 ngày)</span>
            </h6>
            <span class="badge bg-success-subtle text-success border border-success-subtle small px-2 py-1">
              <i class="fa-solid fa-check me-1"></i>Ổn định 100%
            </span>
          </div>

          <div class="empty-incident-box text-center py-4 px-3 rounded-3 border border-dashed bg-white">
            <div class="d-inline-flex align-items-center justify-content-center p-3 rounded-circle bg-success-subtle text-success mb-2 shadow-xs">
              <i class="fa-solid fa-circle-check fs-3"></i>
            </div>
            <div class="fw-700 text-dark">Không ghi nhận sự cố nào trong 90 ngày qua</div>
            <div class="text-muted small mt-1 max-w-md mx-auto">
              Dịch vụ hoạt động ổn định, liên tục đáp ứng các chỉ tiêu chất lượng và cam kết SLA của Trường Đại học Giao thông Vận tải.
            </div>
          </div>
        </div>
      `;
    } else {
      const incidentItems = incidents.map(inc => {
        const isResolved = inc.status === 'resolved';
        const sevClass = {
          critical: 'bg-danger text-white',
          major:    'bg-danger-subtle text-danger border border-danger-subtle',
          minor:    'bg-warning-subtle text-warning-emphasis border border-warning-subtle'
        }[inc.severity] || 'bg-secondary text-white';

        return `
          <div class="incident-item-card p-3 rounded-3 border bg-white shadow-2xs mb-2">
            <div class="d-flex align-items-start justify-content-between gap-2 flex-wrap mb-2">
              <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="badge ${sevClass} small text-uppercase px-2">${inc.severity}</span>
                <strong class="text-dark fs-6">${escHtml(inc.title)}</strong>
              </div>
              <span class="badge ${isResolved ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-danger-subtle text-danger border border-danger-subtle'} px-2 py-1 small">
                <i class="fa-solid ${isResolved ? 'fa-circle-check' : 'fa-spinner fa-spin'} me-1"></i>${isResolved ? 'Đã khắc phục' : 'Đang xử lý'}
              </span>
            </div>
            <div class="d-flex align-items-center gap-3 text-muted small flex-wrap mb-2">
              <span><i class="fa-regular fa-clock me-1"></i>Bắt đầu: ${inc.started_at}</span>
              ${inc.resolved_at ? `<span><i class="fa-solid fa-check-double text-success me-1"></i>Khắc phục: ${inc.resolved_at}</span>` : ''}
              ${inc.duration_minutes ? `<span><i class="fa-solid fa-stopwatch text-warning me-1"></i>Thời gian: ${formatDowntime(inc.duration_minutes)}</span>` : ''}
            </div>
            ${inc.latest_update ? `
              <div class="p-2-5 rounded bg-light-subtle border-start border-3 border-teal small text-secondary">
                <i class="fa-regular fa-comment-dots text-teal me-1"></i>${escHtml(inc.latest_update)}
              </div>` : ''}
          </div>
        `;
      }).join('');

      incidentsSectionHtml = `
        <div class="incident-history-block pt-3 border-top">
          <div class="d-flex align-items-center justify-content-between mb-3">
            <h6 class="fw-700 text-dark mb-0 d-flex align-items-center gap-2">
              <i class="fa-solid fa-clock-rotate-left text-teal"></i>
              <span>Nhật ký Sự cố & Gián đoạn (90 ngày)</span>
            </h6>
            <span class="badge bg-danger-subtle text-danger border border-danger-subtle small px-2 py-1">
              ${incidents.length} sự cố ghi nhận
            </span>
          </div>
          <div class="incident-list-container">${incidentItems}</div>
        </div>
      `;
    }

    bodyEl.innerHTML = statsCardsHtml + barsSectionHtml + incidentsSectionHtml;

    // ── 5. Footer Quick Actions (Theo phân quyền người dùng) ──
    if (footerEl) {
      const userRole = window.CURRENT_USER_ROLE || 'student';
      const isPrivileged = ['admin', 'editor'].includes(userRole);

      if (isPrivileged) {
        footerEl.innerHTML = `
          <div class="d-flex align-items-center gap-2">
            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1">
              <i class="fa-solid fa-user-shield me-1"></i>Admin Quick Actions
            </span>
            <span class="small text-muted d-none d-md-inline">Phân quyền Quản trị / Biên tập</span>
          </div>
          <div class="d-flex align-items-center gap-2">
            <a href="admin/index.php#services" class="btn btn-sm btn-outline-teal d-inline-flex align-items-center gap-1" target="_blank">
              <i class="fa-solid fa-pen-to-square"></i><span>Cấu hình dịch vụ</span>
            </a>
            <button type="button" class="btn btn-sm btn-teal d-inline-flex align-items-center gap-1" onclick="openSubscribeModal('${escHtml(svc.name)}')">
              <i class="fa-regular fa-bell"></i><span>Đăng ký nhận tin</span>
            </button>
          </div>
        `;
      } else {
        footerEl.innerHTML = `
          <div class="small text-muted d-flex align-items-center gap-2">
            <i class="fa-solid fa-circle-check text-success"></i>
            <span>Dữ liệu giám sát trực tuyến 24/7 từ Trung tâm CNTT UTC.</span>
          </div>
          <button type="button" class="btn btn-sm btn-outline-teal d-inline-flex align-items-center gap-1" onclick="openSubscribeModal('${escHtml(svc.name)}')">
            <i class="fa-regular fa-bell"></i><span>Nhận thông báo dịch vụ</span>
          </button>
        `;
      }
    }

    // ── 6. Kích hoạt Bootstrap Tooltips cho 90 thanh ─────────
    const tooltipEls = bodyEl.querySelectorAll('[data-bs-toggle="tooltip"]');
    tooltipEls.forEach(el => new bootstrap.Tooltip(el, { trigger: 'hover' }));

  } catch (err) {
    if (bodyEl) {
      bodyEl.innerHTML = `
        <div class="alert alert-danger d-flex align-items-center gap-2 my-4">
          <i class="fa-solid fa-triangle-exclamation fs-4"></i>
          <div>${escHtml(err.message)}</div>
        </div>`;
    }
  }
}

// ══════════════════════════════════════════════════════════════
//  MODAL: INCIDENTS DETAIL
// ══════════════════════════════════════════════════════════════
async function openIncidentsModal() {
  modals['modalIncidentsDetail'].show();
  const listEl = document.getElementById('incidentsModalList');
  listEl.innerHTML = '<div class="text-center py-4"><i class="fa-solid fa-spinner fa-spin fa-2x text-teal"></i></div>';

  try {
    const res = await fetch(`${API_BASE}/incidents.php?per_page=20`);
    const json = await res.json();
    const incidents = json.data || [];

    if (incidents.length === 0) {
      listEl.innerHTML = '<div class="text-center py-4 text-muted"><i class="fa-solid fa-circle-check text-success fa-2x mb-2"></i><br>Không có sự cố nào được ghi nhận.</div>';
      return;
    }

    listEl.innerHTML = incidents.map(inc => {
      const isResolved = inc.status === 'resolved';
      const statusBadge = isResolved ? 'badge-operational' : 'badge-major_outage';
      const statusLabel = isResolved ? 'Đã giải quyết' : (inc.status === 'investigating' ? 'Đang điều tra' : 'Đang xử lý');

      const updatesHtml = (inc.updates || []).map(u => `
        <div class="timeline-item">
          <span class="timeline-dot ${u.status === 'resolved' ? 'resolved' : 'active'}"></span>
          <div class="d-flex justify-content-between small text-muted">
            <span class="fw-700 text-uppercase text-secondary">${u.status}</span>
            <span>${u.created_at}</span>
          </div>
          <div class="mt-1 text-dark small">${escHtml(u.message)}</div>
        </div>`).join('');

      return `
        <div class="border rounded p-3 mb-3 bg-white shadow-sm">
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
            <div>
              <h6 class="fw-700 mb-1 text-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i>${escHtml(inc.title)}</h6>
              <div class="small text-muted">
                <i class="fa-solid fa-server me-1"></i>Dịch vụ: <strong>${escHtml(inc.affected_services || 'Tất cả')}</strong>
                &bull; Bắt đầu: ${inc.started_at}
              </div>
            </div>
            <span class="utc-badge-status ${statusBadge}">${statusLabel}</span>
          </div>
          ${updatesHtml ? `<div class="mt-3 pt-2 border-top">${updatesHtml}</div>` : ''}
        </div>`;
    }).join('');

  } catch (err) {
    listEl.innerHTML = `<div class="alert alert-danger">${err.message}</div>`;
  }
}

// ══════════════════════════════════════════════════════════════
//  MODAL: MAINTENANCES DETAIL
// ══════════════════════════════════════════════════════════════
let loadedMaintenancesData = { all: [], upcoming: [], past: [], counts: {} };
let currentMaintenanceTab = 'all';

async function openMaintenancesModal() {
  modals['modalMaintenancesDetail'].show();
  const listEl = document.getElementById('maintenancesModalList');
  if (listEl) {
    listEl.innerHTML = '<div class="text-center py-4"><i class="fa-solid fa-spinner fa-spin fa-2x text-teal mb-2"></i><div class="small text-muted">Đang tải danh sách kế hoạch bảo trì...</div></div>';
  }

  try {
    const res = await fetch(`${API_BASE}/maintenances.php?tab=all&limit=50`);
    const json = await res.json();
    
    loadedMaintenancesData = {
      all: json.data || [],
      upcoming: json.upcoming || [],
      past: json.past || [],
      counts: json.counts || {
        total: (json.data || []).length,
        in_progress: (json.data || []).filter(m => m.status === 'in_progress').length,
        scheduled: (json.data || []).filter(m => m.status === 'scheduled').length,
        past: (json.past || []).length
      }
    };

    // Cập nhật 3 thẻ KPI
    const inProgEl = document.getElementById('maintKpiInProgress');
    if (inProgEl) inProgEl.textContent = loadedMaintenancesData.counts.in_progress || 0;

    const upcomEl = document.getElementById('maintKpiUpcoming');
    if (upcomEl) upcomEl.textContent = loadedMaintenancesData.counts.scheduled || 0;

    const compEl = document.getElementById('maintKpiCompleted');
    if (compEl) compEl.textContent = loadedMaintenancesData.counts.past || 0;

    // Render Filter Tabs
    renderMaintenanceFilterTabs();

    // Render Danh sách
    renderMaintenanceModalList('all');

  } catch (err) {
    console.error('Error fetching maintenances:', err);
    if (listEl) {
      listEl.innerHTML = `<div class="alert alert-danger small"><i class="fa-solid fa-circle-exclamation me-1"></i>${err.message || 'Không thể tải dữ liệu bảo trì. Vui lòng thử lại sau.'}</div>`;
    }
  }
}

function renderMaintenanceFilterTabs() {
  const tabsContainer = document.getElementById('maintenanceFilterTabs');
  if (!tabsContainer) return;

  const totalAll = loadedMaintenancesData.all.length;
  const totalUpcoming = loadedMaintenancesData.upcoming.length;
  const totalPast = loadedMaintenancesData.past.length;

  const tabs = [
    { key: 'all', label: `Tất cả (${totalAll})` },
    { key: 'upcoming', label: `Sắp tới & Live (${totalUpcoming})` },
    { key: 'past', label: `Đã xong (${totalPast})` }
  ];

  tabsContainer.innerHTML = tabs.map(t => {
    const isActive = currentMaintenanceTab === t.key;
    const btnCls = isActive ? 'btn-teal' : 'btn-outline-teal';
    return `<button type="button" class="btn btn-xs ${btnCls}" onclick="filterMaintenancesTab('${t.key}', this)">${t.label}</button>`;
  }).join('');
}

function filterMaintenancesTab(tabKey, btnEl) {
  currentMaintenanceTab = tabKey;
  renderMaintenanceFilterTabs();
  renderMaintenanceModalList(tabKey);
}

function renderMaintenanceModalList(tabKey) {
  const listEl = document.getElementById('maintenancesModalList');
  if (!listEl) return;

  let items = [];
  if (tabKey === 'upcoming') {
    items = loadedMaintenancesData.upcoming;
  } else if (tabKey === 'past') {
    items = loadedMaintenancesData.past;
  } else {
    items = loadedMaintenancesData.all;
  }

  if (items.length === 0) {
    listEl.innerHTML = `
      <div class="text-center py-4 bg-white rounded-3 border p-4 shadow-sm my-2">
        <i class="fa-solid fa-calendar-check text-success fa-2x mb-2"></i>
        <div class="fw-700 text-dark">Không có kế hoạch bảo trì nào trong danh mục này.</div>
        <div class="small text-muted mt-1">Toàn bộ hệ thống kỹ thuật đang vận hành ổn định 24/7.</div>
      </div>`;
    return;
  }

  listEl.innerHTML = items.map(m => {
    const isProgress = m.status === 'in_progress';
    const isCompleted = m.status === 'completed';

    // Status Pill
    let badgeHtml = '';
    let borderAccent = 'border-teal';
    if (isProgress) {
      badgeHtml = '<span class="utc-badge-status badge-maintenance"><i class="fa-solid fa-spinner fa-spin me-1"></i>Đang tiến hành trực tiếp</span>';
      borderAccent = 'border-warning';
    } else if (isCompleted) {
      badgeHtml = '<span class="utc-badge-status badge-operational"><i class="fa-solid fa-check me-1"></i>Đã hoàn thành</span>';
      borderAccent = 'border-success';
    } else {
      badgeHtml = '<span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1"><i class="fa-regular fa-calendar-clock me-1"></i>Đã lên lịch</span>';
      borderAccent = 'border-teal';
    }

    // Thời lượng bảo trì
    let durationText = '';
    if (m.scheduled_at && m.expected_end) {
      const tStart = new Date(m.scheduled_at.replace(' ', 'T')).getTime();
      const tEnd = new Date(m.expected_end.replace(' ', 'T')).getTime();
      if (!isNaN(tStart) && !isNaN(tEnd) && tEnd > tStart) {
        const diffMins = Math.round((tEnd - tStart) / 60000);
        const h = Math.floor(diffMins / 60);
        const remM = diffMins % 60;
        durationText = h > 0 ? `${h} giờ ${remM > 0 ? remM + ' phút' : ''}` : `${diffMins} phút`;
      }
    }

    // Badges dịch vụ ảnh hưởng
    const affectedChips = (m.affected_services || 'Toàn trường / Toàn hệ thống')
      .split(',')
      .map(s => `<span class="badge bg-teal-soft text-teal border border-teal-subtle me-1"><i class="fa-solid fa-server me-1"></i>${escHtml(s.trim())}</span>`)
      .join('');

    return `
      <div class="border rounded-3 p-3 mb-3 bg-white shadow-sm border-start border-4 ${borderAccent}">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
          <h6 class="fw-700 mb-0 fs-6 text-dark">
            <i class="fa-solid fa-screwdriver-wrench me-2 text-teal"></i>${escHtml(m.title)}
          </h6>
          <div>${badgeHtml}</div>
        </div>

        ${isProgress ? `
          <div class="alert alert-warning py-2 px-3 small mb-2 d-flex align-items-center gap-2">
            <i class="fa-solid fa-triangle-exclamation text-warning fs-5"></i>
            <div><strong>Cửa sổ bảo trì đang diễn ra:</strong> Một số chức năng thuộc dịch vụ dưới đây có thể gián đoạn tạm thời trong khung giờ này.</div>
          </div>` : ''}

        <p class="small text-secondary mb-2" style="line-height:1.45;">${escHtml(m.description || 'Bảo trì định kỳ hệ thống.')}</p>

        <div class="d-flex flex-wrap gap-3 small text-muted bg-light p-2 rounded-2 mb-2">
          <div><i class="fa-regular fa-clock me-1 text-secondary"></i>Bắt đầu: <strong>${m.scheduled_at}</strong></div>
          <div><i class="fa-solid fa-hourglass-end me-1 text-secondary"></i>Dự kiến xong: <strong>${m.expected_end}</strong></div>
          ${durationText ? `<div><i class="fa-solid fa-stopwatch me-1 text-primary"></i>Thời lượng: <strong class="text-dark">${durationText}</strong></div>` : ''}
        </div>

        <div class="small d-flex align-items-center flex-wrap gap-1">
          <span class="text-secondary fw-600 me-1">Dịch vụ ảnh hưởng:</span>
          ${affectedChips}
        </div>
      </div>`;
  }).join('');
}

// ══════════════════════════════════════════════════════════════
//  MODAL: DAY EVENTS IN CALENDAR
// ══════════════════════════════════════════════════════════════
function openDayEventsModal(dateStr) {
  const modalEl = document.getElementById('modalDayEvents');
  const titleEl = document.getElementById('dayEventsModalTitle');
  const bodyEl  = document.getElementById('dayEventsModalBody');
  if (!modalEl) return;

  const eventsMap = systemData?.calendar_events || {};
  const ev = eventsMap[dateStr];

  titleEl.innerHTML = `<i class="fa-regular fa-calendar-days me-2" style="color:var(--teal-primary)"></i>Sự kiện ngày ${dateStr}`;

  if (!ev || (!ev.incidents && !ev.maintenances)) {
    bodyEl.innerHTML = `
      <div class="text-center py-4 text-muted">
        <i class="fa-solid fa-circle-check text-success fa-2x mb-2"></i>
        <div>Không có sự cố hoặc lịch bảo trì nào ghi nhận trong ngày ${dateStr}.</div>
        <div class="small text-success mt-1">Toàn bộ hệ thống hoạt động ổn định 100%.</div>
      </div>`;
  } else {
    let html = '';
    if (ev.incidents > 0) {
      html += `
        <div class="alert alert-danger mb-3">
          <div class="fw-700 mb-1"><i class="fa-solid fa-triangle-exclamation me-1"></i>Có ${ev.incidents} sự cố trong ngày:</div>
          <div class="small">${escHtml(ev.incident_titles || 'Sự cố hệ thống')}</div>
        </div>`;
    }
    if (ev.maintenances > 0) {
      html += `
        <div class="alert alert-warning mb-3">
          <div class="fw-700 mb-1"><i class="fa-solid fa-wrench me-1"></i>Có ${ev.maintenances} kế hoạch bảo trì:</div>
          <div class="small">${escHtml(ev.maintenance_titles || 'Bảo trì kỹ thuật')}</div>
        </div>`;
    }
    bodyEl.innerHTML = html;
  }

  modals['modalDayEvents'].show();
}

// ══════════════════════════════════════════════════════════════
//  MODAL: JSON DATA VIEWER
// ══════════════════════════════════════════════════════════════
function openJsonModal() {
  const jsonBox = document.getElementById('jsonCodeBox');
  if (jsonBox && systemData) {
    jsonBox.textContent = JSON.stringify(systemData, null, 2);
  }
  modals['modalJsonData'].show();
}

function copyJsonToClipboard() {
  const code = document.getElementById('jsonCodeBox').textContent;
  navigator.clipboard.writeText(code).then(() => {
    alert('Đã sao chép dữ liệu JSON vào Clipboard!');
  });
}

// ══════════════════════════════════════════════════════════════
//  MODAL: SUBSCRIBE FOR NOTIFICATIONS
// ══════════════════════════════════════════════════════════════
function openSubscribeModal(prefillService = '') {
  document.getElementById('subFeedback').innerHTML = '';
  document.getElementById('subEndpoint').value = '';
  if (prefillService) {
    document.getElementById('subNote').textContent = `Đăng ký theo dõi sự cố cho dịch vụ: ${prefillService}`;
  } else {
    document.getElementById('subNote').textContent = 'Nhận thông báo tự động khi có bất kỳ sự cố hoặc bảo trì nào.';
  }
  modals['modalSubscribe'].show();
}

async function handleSubscribe(e) {
  e.preventDefault();
  const type     = document.getElementById('subType').value;
  const endpoint = document.getElementById('subEndpoint').value.trim();
  const feedback = document.getElementById('subFeedback');
  const btn      = document.getElementById('btnSubmitSub');

  if (!endpoint) {
    feedback.innerHTML = '<div class="alert alert-warning py-1 small">Vui lòng nhập Email hoặc Webhook Slack.</div>';
    return;
  }

  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Đang đăng ký…';

  try {
    const res = await fetch('api/subscribe.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ type, endpoint })
    });
    const json = await res.json();

    if (res.ok && json.success) {
      let extraHtml = '';
      if (json.verification_url) {
        extraHtml = `<div class="mt-2 pt-2 border-top"><a href="${json.verification_url}" target="_blank" class="btn btn-sm btn-success w-100 text-white"><i class="fa-solid fa-envelope-circle-check me-1"></i>Xác nhận Email ngay (Liên kết kích hoạt)</a></div>`;
      }
      feedback.innerHTML = `<div class="alert alert-success py-2 small"><i class="fa-solid fa-circle-check me-1"></i>${json.message}${extraHtml}</div>`;
      document.getElementById('subEndpoint').value = '';
    } else {
      feedback.innerHTML = `<div class="alert alert-danger py-2 small">${json.error || 'Lỗi đăng ký.'}</div>`;
    }
  } catch {
    feedback.innerHTML = '<div class="alert alert-danger py-2 small">Lỗi kết nối máy chủ.</div>';
  } finally {
    btn.disabled = false;
    btn.innerHTML = 'Đăng ký ngay';
  }
}

// ══════════════════════════════════════════════════════════════
//  COUNTDOWN & POLLING
// ══════════════════════════════════════════════════════════════
function startCountdown() {
  countdownSeconds = POLL_INTERVAL / 1000;
  const el = document.getElementById('statCountdown');
  if (el) el.textContent = countdownSeconds;

  clearInterval(countdownTimer);
  countdownTimer = setInterval(() => {
    countdownSeconds--;
    const counterEl = document.getElementById('statCountdown');
    if (counterEl) counterEl.textContent = countdownSeconds;
    if (countdownSeconds <= 0) {
      countdownSeconds = POLL_INTERVAL / 1000;
    }
  }, 1000);
}

function startPolling() {
  clearInterval(pollTimer);
  pollTimer = setInterval(async () => {
    await loadStatusData();
    startCountdown();
  }, POLL_INTERVAL);
}

// ══════════════════════════════════════════════════════════════
//  UTILITY
// ══════════════════════════════════════════════════════════════
function escHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}
