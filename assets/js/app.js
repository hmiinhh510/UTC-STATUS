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
    const uptimeVal = s.avg_uptime_90d || 99.8;
    featUptime.tooltip = `Uptime trung bình 90 ngày đạt ${uptimeVal}% (Chuẩn SLA)`;
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
    operational:    { label: 'Hoạt động tốt',    cls: 'badge-operational',    dot: '#22c55e' },
    degraded:       { label: 'Hiệu năng giảm',   cls: 'badge-degraded',       dot: '#eab308' },
    partial_outage: { label: 'Gián đoạn 1 phần', cls: 'badge-partial_outage', dot: '#f97316' },
    major_outage:   { label: 'Sự cố nghiêm trọng', cls: 'badge-major_outage', dot: '#ef4444' },
    maintenance:    { label: 'Đang bảo trì',     cls: 'badge-maintenance',    dot: '#3b82f6' }
  };

  listEl.innerHTML = services.map(svc => {
    const meta = statusLabels[svc.status] || statusLabels.operational;
    return `
      <div class="col-md-6 col-lg-4">
        <div class="p-3 rounded border bg-white h-100 d-flex flex-column justify-content-between shadow-sm">
          <div>
            <div class="d-flex align-items-center justify-content-between mb-2">
              <span class="badge bg-light text-secondary border small">${escHtml(svc.group_name || 'Hệ thống')}</span>
              <span class="utc-badge-status ${meta.cls}" style="font-size:0.72rem; padding: 2px 8px;">
                <span class="badge-dot" style="background-color:${meta.dot}"></span>${meta.label}
              </span>
            </div>
            <div class="d-flex align-items-center gap-2 mb-2">
              <i class="fa-solid ${svc.icon || 'fa-server'} fs-4 text-teal"></i>
              <strong class="fs-6 text-dark">${escHtml(svc.name)}</strong>
            </div>
            <p class="text-muted small mb-3" style="min-height: 38px;">${escHtml(svc.description || '')}</p>
          </div>
          <button class="btn btn-sm btn-outline-secondary w-100 d-flex align-items-center justify-content-center gap-1"
                  onclick="openServiceDetailModal(${svc.id})">
            <i class="fa-solid fa-chart-simple text-teal"></i>Xem Biểu Đồ Uptime 90 Ngày
          </button>
        </div>
      </div>`;
  }).join('');

  modals['modalRealtimeOverview'].show();
}

// ══════════════════════════════════════════════════════════════
//  2. MODAL: UPTIME & INCIDENT HISTORY (SLA ANALYTICS)
// ══════════════════════════════════════════════════════════════
async function openUptimeHistoryModal() {
  const modalEl = document.getElementById('modalUptimeHistory');
  if (!modalEl) return;

  const timelineEl = document.getElementById('uptimeIncidentTimeline');
  timelineEl.innerHTML = '<div class="text-center py-4"><i class="fa-solid fa-spinner fa-spin fa-2x text-teal"></i></div>';

  modals['modalUptimeHistory'].show();

  try {
    const res = await fetch(`${API_BASE}/incidents.php?limit=10`);
    const data = await res.json();
    const incidents = data.incidents || [];

    const totalEl = document.getElementById('uptimeTotalIncidents');
    if (totalEl) totalEl.textContent = incidents.length;

    if (incidents.length === 0) {
      timelineEl.innerHTML = `
        <div class="p-4 rounded border text-center text-success bg-success-subtle">
          <i class="fa-solid fa-circle-check fa-2x mb-2"></i>
          <div class="fw-700">100% Hoàn Hảo! Không ghi nhận sự cố nào trong 90 ngày qua.</div>
          <div class="small">Tất cả các dịch vụ đều duy trì liên tục và đạt chỉ tiêu cam kết SLA.</div>
        </div>`;
      return;
    }

    const severityBadges = {
      minor:    '<span class="badge bg-info-subtle text-info border">Sự cố nhẹ</span>',
      major:    '<span class="badge bg-warning-subtle text-warning border">Nghiêm trọng</span>',
      critical: '<span class="badge bg-danger text-white">Rất nghiêm trọng</span>'
    };

    timelineEl.innerHTML = incidents.map(inc => {
      const sevBadge = severityBadges[inc.severity] || severityBadges.minor;
      const isResolved = inc.status === 'resolved';
      const statusPill = isResolved
        ? '<span class="badge bg-success-subtle text-success"><i class="fa-solid fa-check me-1"></i>Đã khắc phục hoàn tất</span>'
        : '<span class="badge bg-danger-subtle text-danger"><i class="fa-solid fa-spinner fa-spin me-1"></i>Đang trong tiến trình xử lý</span>';

      const updatesHtml = (inc.updates || []).map(u => `
        <div class="ps-3 border-start border-2 border-primary-subtle py-1 mb-2">
          <div class="d-flex align-items-center gap-2 small">
            <span class="badge bg-secondary-subtle text-dark text-capitalize">${u.status}</span>
            <span class="text-muted">${u.created_at}</span>
          </div>
          <div class="small text-dark mt-1">${escHtml(u.message)}</div>
        </div>
      `).join('');

      return `
        <div class="p-3 rounded border mb-3 bg-white shadow-sm">
          <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-2">
            <div>
              <div class="d-flex align-items-center gap-2 mb-1">
                ${sevBadge}
                ${statusPill}
              </div>
              <h6 class="fw-700 text-dark mb-1">${escHtml(inc.title)}</h6>
              <div class="small text-muted">
                <i class="fa-regular fa-clock me-1"></i>Bắt đầu: ${inc.started_at}
                ${inc.resolved_at ? ` &bull; <i class="fa-solid fa-flag-checkered me-1 text-success"></i>Đã đóng: ${inc.resolved_at}` : ''}
              </div>
            </div>
          </div>
          <div class="small mb-2 text-secondary">
            <strong>Dịch vụ ảnh hưởng:</strong> ${escHtml(inc.affected_services || 'Hệ thống chung')}
          </div>
          ${updatesHtml ? `<div class="mt-2 pt-2 border-top">${updatesHtml}</div>` : ''}
        </div>`;
    }).join('');

  } catch (e) {
    timelineEl.innerHTML = '<div class="alert alert-danger small">Không thể tải dữ liệu lịch sử sự cố.</div>';
  }
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
    const results = data.results || [];

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

      return `
        <tr>
          <td>
            <strong>${escHtml(r.name)}</strong>
            <div class="small text-muted">ID: #${r.service_id}</div>
          </td>
          <td>
            ${r.check_url ? `<a href="${r.check_url}" target="_blank" class="small text-decoration-none font-monospace">${escHtml(r.check_url)}</a>` : '<span class="text-muted small fst-italic">Dịch vụ mạng nội bộ</span>'}
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
          <i class="fa-solid fa-triangle-exclamation me-1"></i>Lỗi khi thực thi tiến trình Health Check.
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
//  MODAL: SERVICE DETAIL & 90 DAYS UPTIME
// ══════════════════════════════════════════════════════════════
async function openServiceDetailModal(serviceId) {
  const modalEl = document.getElementById('modalServiceDetail');
  if (!modalEl) return;

  const titleEl = document.getElementById('svcModalTitle');
  const bodyEl  = document.getElementById('svcModalBody');

  titleEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Đang tải dữ liệu…';
  bodyEl.innerHTML  = '<div class="text-center py-5"><i class="fa-solid fa-circle-notch fa-spin fa-2x text-teal"></i></div>';

  modals['modalServiceDetail'].show();

  try {
    const res = await fetch(`${API_BASE}/uptime.php?service_id=${serviceId}`);
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Lỗi tải dịch vụ');

    const svc = data.service;
    const summary = data.summary;
    const daily = data.daily || [];

    const statusBadgeClass = {
      operational:    'badge-operational',
      degraded:       'badge-degraded',
      partial_outage: 'badge-partial_outage',
      major_outage:   'badge-major_outage',
      maintenance:    'badge-maintenance'
    }[svc.status] || 'badge-operational';

    const statusLabels = {
      operational:    'Hoạt động tốt',
      degraded:       'Hiệu năng giảm',
      partial_outage: 'Gián đoạn một phần',
      major_outage:   'Sự cố nghiêm trọng',
      maintenance:    'Đang bảo trì'
    };

    titleEl.innerHTML = `<i class="fa-solid fa-circle-nodes me-2" style="color:var(--teal-primary)"></i>${escHtml(svc.name)}`;

    // Build 90-day bars
    const barsHtml = daily.map(d => {
      const pct = parseFloat(d.uptime_pct);
      const h = Math.max(6, Math.round((pct / 100) * 44));
      const cls = pct >= 99 ? '' : (pct >= 90 ? 'mid' : 'down');
      return `<div class="modal-bar-day ${cls}" style="height:${h}px" title="${d.date}: ${pct}% Uptime (${d.incident_count} sự cố)"></div>`;
    }).join('');

    bodyEl.innerHTML = `
      <div class="d-flex align-items-center justify-content-between mb-3 pb-3 border-bottom">
        <div>
          <span class="utc-badge-status ${statusBadgeClass}">${statusLabels[svc.status] || svc.status}</span>
          <span class="text-muted small ms-2">Mã dịch vụ: <code>#${svc.id}</code></span>
        </div>
        <button class="btn btn-sm btn-outline-secondary" onclick="openSubscribeModal('${escHtml(svc.name)}')">
          <i class="fa-regular fa-bell me-1"></i>Nhận thông báo dịch vụ này
        </button>
      </div>

      <!-- KPI 3 ô -->
      <div class="row g-2 text-center mb-4">
        <div class="col-4">
          <div class="p-2 border rounded bg-light">
            <div class="fs-4 fw-800" style="color:var(--teal-primary)">${summary.avg_uptime_pct}%</div>
            <div class="small text-muted">Uptime TB 90 ngày</div>
          </div>
        </div>
        <div class="col-4">
          <div class="p-2 border rounded bg-light">
            <div class="fs-4 fw-800 text-danger">${summary.total_incidents_90d}</div>
            <div class="small text-muted">Tổng số sự cố</div>
          </div>
        </div>
        <div class="col-4">
          <div class="p-2 border rounded bg-light">
            <div class="fs-4 fw-800 text-warning">${summary.total_downtime_minutes}m</div>
            <div class="small text-muted">Thời gian gián đoạn</div>
          </div>
        </div>
      </div>

      <!-- Biểu đồ 90 ngày -->
      <h6 class="fw-700 text-muted small text-uppercase mb-2"><i class="fa-solid fa-chart-simple me-1"></i>Lịch sử Uptime 90 ngày</h6>
      <div class="modal-uptime-bars mb-1">${barsHtml}</div>
      <div class="d-flex justify-content-between text-muted small mb-4" style="font-size:0.75rem">
        <span>90 ngày trước</span>
        <span>Hôm nay (100%)</span>
      </div>

      <!-- Quick status change for admin -->
      <div class="p-3 bg-light rounded border">
        <div class="d-flex align-items-center justify-content-between">
          <span class="small fw-600 text-secondary"><i class="fa-solid fa-wrench me-1"></i>Đổi nhanh trạng thái dịch vụ này (Admin):</span>
          <a href="admin/login.php" class="btn btn-sm btn-outline-primary py-0" style="font-size:0.8rem">Đăng nhập Quản trị</a>
        </div>
      </div>`;

  } catch (err) {
    bodyEl.innerHTML = `<div class="alert alert-danger">${err.message}</div>`;
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
async function openMaintenancesModal() {
  modals['modalMaintenancesDetail'].show();
  const listEl = document.getElementById('maintenancesModalList');
  listEl.innerHTML = '<div class="text-center py-4"><i class="fa-solid fa-spinner fa-spin fa-2x text-teal"></i></div>';

  try {
    const res = await fetch(`${API_BASE}/maintenances.php?limit=20`);
    const json = await res.json();
    const rows = json.data || [];

    if (rows.length === 0) {
      listEl.innerHTML = '<div class="text-center py-4 text-muted"><i class="fa-solid fa-calendar-check text-success fa-2x mb-2"></i><br>Không có lịch bảo trì nào sắp tới.</div>';
      return;
    }

    listEl.innerHTML = rows.map(m => {
      const isProgress = m.status === 'in_progress';
      const badgeCls   = isProgress ? 'badge-maintenance' : 'badge-operational';
      const badgeLabel = isProgress ? 'Đang tiến hành' : 'Đã lên lịch';

      return `
        <div class="border rounded p-3 mb-3 bg-white shadow-sm">
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
            <h6 class="fw-700 mb-0" style="color:var(--teal-primary)"><i class="fa-solid fa-screwdriver-wrench me-2"></i>${escHtml(m.title)}</h6>
            <span class="utc-badge-status ${badgeCls}">${badgeLabel}</span>
          </div>
          <p class="small text-secondary mb-2">${escHtml(m.description || 'Bảo trì định kỳ hệ thống.')}</p>
          <div class="d-flex flex-wrap gap-3 small text-muted bg-light p-2 rounded">
            <div><i class="fa-regular fa-clock me-1"></i>Bắt đầu: <strong>${m.scheduled_at}</strong></div>
            <div><i class="fa-solid fa-hourglass-end me-1"></i>Dự kiến xong: <strong>${m.expected_end}</strong></div>
            <div><i class="fa-solid fa-server me-1"></i>Ảnh hưởng: <strong>${escHtml(m.affected_services || 'Tất cả')}</strong></div>
          </div>
        </div>`;
    }).join('');

  } catch (err) {
    listEl.innerHTML = `<div class="alert alert-danger">${err.message}</div>`;
  }
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
