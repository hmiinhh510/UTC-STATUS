<?php
/**
 * UTH STATUS – Trang Trạng thái Dịch vụ CNTT Đại học Giao thông Vận tải (UTH)
 * Main Frontend Portal - Hệ thống Khép kín (Private Status Page)
 */
declare(strict_types=1);
session_start();
header('Content-Type: text/html; charset=UTF-8');

// Bắt buộc đăng nhập toàn hệ thống: Tuyệt đối chặn khách vãng lai
if (!isset($_SESSION['admin_id'])) {
    header('Location: admin/login.php');
    exit;
}

$adminId   = (int) $_SESSION['admin_id'];
$adminName = $_SESSION['admin_name'] ?? 'Thành viên UTH';
$adminRole = $_SESSION['admin_role'] ?? 'student'; // 'student', 'editor', 'admin'
$flash     = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/service_status.php';

// Khởi tạo các chỉ số dịch vụ, sự cố & bảo trì
$totalServices = 0;
$operationalCount = 0;
$outageCount = 0;
$degradedCount = 0;
$troubledServicesCount = 0;
$overallStatus = 'operational';
$overallMessage = 'Tất cả hệ thống đang hoạt động bình thường';
$overallColor = '#22c55e';
$avgUptime = 99.3;
$effectiveActiveIncidentsCount = 0;
$effectiveMaintenanceCount = 0;
$scheduledMaintCount = 0;
$inProgressMaintCount = 0;
$totalAlerts = 0;

try {
    $pdo = Database::getInstance();

    // Nạp toàn bộ chỉ số sự cố, bảo trì và trạng thái hệ thống chuẩn xác từ máy chủ
    $metrics = getSystemMetrics($pdo);
    $totalServices                 = (int) $metrics['total_services'];
    $operationalCount              = (int) $metrics['operational_count'];
    $outageCount                   = (int) $metrics['outage_count'];
    $degradedCount                 = (int) $metrics['degraded_count'];
    $troubledServicesCount         = (int) $metrics['troubled_services_count'];
    $overallStatus                 = $metrics['overall_status'];
    $overallMessage                = $metrics['overall_message'];
    $overallColor                  = $metrics['overall_color'];
    $avgUptime                     = (float) $metrics['avg_uptime_90d'];
    $effectiveActiveIncidentsCount = (int) $metrics['effective_active_incidents'];
    $effectiveMaintenanceCount     = (int) $metrics['effective_maintenances_count'];
    $scheduledMaintCount           = (int) $metrics['scheduled_maint_count'];
    $inProgressMaintCount          = (int) $metrics['in_progress_maint_count'];
    $totalAlerts                   = $effectiveActiveIncidentsCount + $inProgressMaintCount;
} catch (\Throwable $e) {
    // Fallback if table not ready
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>UTH STATUS – Trạng thái dịch vụ Đại học GTVT (UTH)</title>
  <meta name="description"
    content="Trang trạng thái dịch vụ CNTT theo thời gian thực Trường Đại học Giao thông Vận tải (UTH)." />
  <!-- Bootstrap 5.3 CDN -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" />
  <!-- Font Awesome 6 -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <!-- Page Stylesheet -->
  <link rel="stylesheet" href="assets/css/style.css?v=3.7" />
  <!-- AI Chatbot Stylesheet -->
  <link rel="stylesheet" href="assets/css/ai_chat.css?v=1.0" />
  <script>
    function applyUtcTheme(theme) {
      var isDark = (theme === 'dark');
      document.documentElement.setAttribute('data-theme', theme);
      document.documentElement.setAttribute('data-bs-theme', theme);
      if (document.body) {
        document.body.setAttribute('data-theme', theme);
        document.body.setAttribute('data-bs-theme', theme);
      }
      try { localStorage.setItem('utc_theme', theme); } catch (e) {}

      // Đồng bộ toàn bộ các switch checkbox trên trang
      var cbs = document.querySelectorAll('.utc-theme-switch-checkbox, #themeSwitchCheckbox');
      cbs.forEach(function(cb) {
        cb.checked = isDark;
      });

      var icons = document.querySelectorAll('#iconThemeToggle, .icon-theme-toggle');
      icons.forEach(function(icon) {
        if (isDark) {
          icon.className = 'fa-solid fa-sun text-warning';
          if (icon.parentElement) icon.parentElement.setAttribute('title', 'Chuyển sang Giao diện Sáng (Light Mode)');
        } else {
          icon.className = 'fa-regular fa-moon';
          if (icon.parentElement) icon.parentElement.setAttribute('title', 'Chuyển sang Giao diện Tối (Dark Mode)');
        }
      });
    }

    function toggleUtcTheme(forcedTheme) {
      var current = document.documentElement.getAttribute('data-theme') || 'light';
      var next = (typeof forcedTheme === 'string') ? forcedTheme : (current === 'dark' ? 'light' : 'dark');
      applyUtcTheme(next);
    }

    function openIncidentsModal() {
      var modalEl = document.getElementById('modalIncidentsDetail');
      if (modalEl && window.bootstrap && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
      }
    }

    function openMaintenancesModal() {
      var modalEl = document.getElementById('modalMaintenancesDetail');
      if (modalEl && window.bootstrap && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
      }
    }

    function openIncidentReportModal() {
      var modalEl = document.getElementById('modalUserReport');
      if (modalEl && window.bootstrap && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
      }
    }

    (function() {
      var saved = 'light';
      try { saved = localStorage.getItem('utc_theme') || 'light'; } catch (e) {}
      document.documentElement.setAttribute('data-theme', saved);
      document.documentElement.setAttribute('data-bs-theme', saved);
      document.addEventListener('DOMContentLoaded', function() {
        applyUtcTheme(saved);
      });
    })();
  </script>
</head>

<body>

  <!-- ══════════════════════════════════════════════════════════
     TOP NAVBAR
  ══════════════════════════════════════════════════════════════ -->
  <header class="utc-navbar sticky-top">
    <div class="container-fluid px-3 px-lg-4">
      <div class="d-flex align-items-center justify-content-between">

        <!-- Left: Hamburger + UTC Brand Logo -->
        <div class="d-flex align-items-center gap-3">
          <button class="btn btn-link text-dark p-0 fs-4 text-decoration-none" type="button" data-bs-toggle="offcanvas"
            data-bs-target="#offcanvasSidebar" aria-label="Menu">
            <i class="fa-solid fa-bars"></i>
          </button>

          <a href="index.php" class="utc-brand">
            <div class="utc-logo-icon">
              <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"
                stroke-linecap="round" stroke-linejoin="round">
                <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
              </svg>
              <div class="utc-logo-dot"></div>
            </div>
            <div>
              <div class="utc-brand-title">UTH <span>STATUS</span></div>
              <div class="utc-brand-subtitle">Trạng thái dịch vụ Đại học GTVT (UTH)</div>
            </div>
          </a>
        </div>

        <!-- Right: Calendar Btn + Bell Notification + User Greeting/Badge + Action Buttons -->
        <div class="d-flex align-items-center gap-2 gap-sm-3">

          <!-- Calendar shortcut button -->
          <button type="button" id="btnNavCalendar" class="nav-action-btn teal-box" title="Xem lịch bảo trì và sự cố">
            <i class="fa-regular fa-calendar"></i>
          </button>

          <!-- Notification bell button -->
          <button type="button" id="btnNavBell" class="nav-action-btn" title="Đăng ký nhận thông báo sự cố">
            <i class="fa-regular fa-bell"></i>
            <span id="navBellBadge" class="nav-badge-dot <?= $totalAlerts > 0 ? '' : 'd-none' ?>"><?= $totalAlerts ?></span>
          </button>

          <!-- Nút gạt chuyển đổi giao diện Sáng / Tối (Toggle Switch Slider) -->
          <div class="utc-theme-slider-container" title="Gạt để chuyển đổi Sáng / Tối">
            <input type="checkbox" id="themeSwitchCheckbox" class="utc-theme-switch-checkbox" aria-label="Chuyển đổi giao diện Sáng / Tối" onchange="toggleUtcTheme(this.checked ? 'dark' : 'light')">
            <label for="themeSwitchCheckbox" class="utc-theme-switch-track" id="themeSwitchTrack">
              <span class="switch-side switch-side-light" title="Chế độ Sáng"><i class="fa-solid fa-sun"></i></span>
              <span class="switch-side switch-side-dark" title="Chế độ Tối"><i class="fa-solid fa-moon"></i></span>
              <span class="utc-theme-switch-knob">
                <i class="fa-solid fa-sun knob-icon-sun"></i>
                <i class="fa-solid fa-moon knob-icon-moon"></i>
              </span>
            </label>
          </div>

          <!-- Nút quay lại trang Quản trị (Dành riêng cho Admin & Editor) -->
          <?php if ($adminRole === 'admin' || $adminRole === 'editor'): ?>
            <a href="admin/index.php" class="btn btn-sm btn-outline-teal fw-700 d-none d-md-inline-flex align-items-center gap-2 px-3 py-1 rounded-pill shadow-xs" title="Quay lại Trang Quản Trị">
              <i class="fa-solid fa-gauge-high"></i>
              <span>Quay lại trang Quản trị</span>
            </a>
          <?php endif; ?>

          <!-- User Profile Dropdown & Badge phân quyền -->
          <div class="dropdown">
            <button class="admin-dropdown-btn dropdown-toggle d-flex align-items-center gap-2 py-1 px-2 px-sm-3" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="border-radius:12px">
              <div class="d-flex flex-column text-start">
                <span class="fw-700 small lh-sm text-dark d-none d-sm-inline"><?= htmlspecialchars($adminName) ?></span>
                <span class="small lh-sm text-dark d-inline d-sm-none"><?= htmlspecialchars(mb_substr($adminName, 0, 10)) ?>..</span>
                <div class="mt-0-5">
                  <?php if ($adminRole === 'student'): ?>
                    <span class="badge bg-teal-soft text-teal-primary px-2 py-0-5 rounded border border-teal-subtle font-weight-600" style="font-size:0.68rem">
                      <i class="fa-solid fa-graduation-cap me-1"></i>Sinh viên (Student)
                    </span>
                  <?php elseif ($adminRole === 'editor'): ?>
                    <span class="badge bg-warning-subtle text-warning-emphasis px-2 py-0-5 rounded border border-warning-subtle font-weight-600" style="font-size:0.68rem">
                      <i class="fa-solid fa-pen-nib me-1"></i>Biên tập viên (Editor)
                    </span>
                  <?php else: ?>
                    <span class="badge bg-danger-subtle text-danger-emphasis px-2 py-0-5 rounded border border-danger-subtle font-weight-600" style="font-size:0.68rem">
                      <i class="fa-solid fa-shield-halved me-1"></i>Quản trị viên (Admin)
                    </span>
                  <?php endif; ?>
                </div>
              </div>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 py-2 mt-2" style="border-radius:14px; min-width: 240px;">
              <li class="px-3 py-2 border-bottom mb-1">
                <div class="fw-700 text-dark"><?= htmlspecialchars($adminName) ?></div>
                <div class="small text-muted text-capitalize">Vai trò: <?= htmlspecialchars($adminRole) ?></div>
              </li>
              <?php if ($adminRole === 'admin' || $adminRole === 'editor'): ?>
                <li>
                  <a class="dropdown-item py-2 fw-600 text-teal" href="admin/index.php">
                    <i class="fa-solid fa-gauge-high me-2"></i>Quay lại trang Quản trị
                  </a>
                </li>
              <?php endif; ?>
              <li>
                <a class="dropdown-item py-2" href="admin/links.php">
                  <i class="fa-solid fa-list-check me-2 text-info"></i>Sơ đồ Địa chỉ Web &amp; Admin
                </a>
              </li>
              <li>
                <button class="dropdown-item py-2" onclick="openSubscribeModal()">
                  <i class="fa-regular fa-bell me-2 text-teal"></i>Đăng ký nhận Email thông báo
                </button>
              </li>
              <li>
                <hr class="dropdown-divider my-1" />
              </li>
              <li>
                <a class="dropdown-item py-2 text-danger fw-600" href="admin/logout.php">
                  <i class="fa-solid fa-right-from-bracket me-2"></i>Đăng xuất
                </a>
              </li>
            </ul>
          </div>

          <!-- Nút Đăng xuất trực tiếp trên Header cho mọi vai trò -->
          <a href="admin/logout.php" class="btn btn-sm btn-outline-danger fw-600 d-inline-flex align-items-center gap-1 rounded-pill px-2 px-sm-3 py-1-5" title="Đăng xuất khỏi hệ thống">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span class="d-none d-sm-inline">Đăng xuất</span>
          </a>

        </div>
      </div>
    </div>
  </header>

  <!-- ══════════════════════════════════════════════════════════
     MAIN CONTAINER (DASHBOARD CHUẨN UTC: TRẮNG - XANH LÁ - ĐỎ)
  ══════════════════════════════════════════════════════════════ -->
  <main class="container-fluid px-3 px-lg-4 py-4">

    <!-- Flash message thông báo đăng nhập hoặc chuyển hướng -->
    <?php if ($flash): ?>
      <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show shadow-sm d-flex align-items-center justify-content-between mb-4" role="alert">
        <div class="d-flex align-items-center gap-2">
          <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check text-success' : 'fa-circle-info text-teal' ?> fs-5"></i>
          <span><?= $flash['message'] ?></span>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
      </div>
    <?php endif; ?>

    <!-- Top Grid: Left (Thông tin dịch vụ & 2 Thẻ Chỉ số) | Right (Lịch theo tháng) -->
    <div class="row g-3 g-lg-4 mb-4 align-items-stretch">

      <!-- ── Left Column: Thông tin dịch vụ + 2 Thẻ trạng thái ───── -->
      <div class="col-lg-7 col-xl-8 d-flex flex-column gap-3">

        <!-- Big Card: Thông tin dịch vụ (Nền Pastel Mint Đồng Bộ) -->
        <div class="utc-card service-info-card flex-grow-1">
          <div class="d-flex align-items-center justify-content-between mb-3">
            <h5 class="utc-card-title">Thông tin dịch vụ</h5>
            <a href="#" id="btnJsonData" class="utc-json-link" title="Xem dữ liệu API JSON">
              DỮ LIỆU JSON
            </a>
          </div>

          <div class="row align-items-center g-3">

            <!-- Vòng tròn Donut Chart SVG trong khung Pastel Xanh Mint dịu mắt -->
            <div class="col-12 col-sm-auto d-flex justify-content-center">
              <div class="donut-chart-box">
                <div class="service-donut-container">
                  <svg class="donut-svg" viewBox="0 0 100 100">
                    <circle class="donut-meter" cx="50" cy="50" r="40" />
                    <circle id="donutValuePath" class="donut-value" cx="50" cy="50" r="40"
                      style="stroke-dasharray: 195 251.32;" />
                  </svg>
                  <div class="donut-inner-text">
                    <div id="donutRatio" class="donut-ratio">7/9</div>
                    <div class="donut-label">hoạt động</div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Cột dữ liệu 1 -->
            <div class="col-12 col-sm">
              <div class="mb-2">
                <div class="status-stat-label header-label">Tình trạng chung:</div>
                <div id="statGeneralMsg" class="status-stat-val-bold <?= $overallStatus === 'operational' ? 'green-ok' : 'red-alert' ?>"><?= htmlspecialchars($overallMessage) ?></div>
              </div>
              <div class="d-flex flex-column gap-1 small">
                <div><span class="status-stat-label">Tổng số dịch vụ:</span> <strong id="statTotalServices"><?= $totalServices ?></strong>
                </div>
                <div><span class="status-stat-label">Hoạt động:</span> <strong id="statOperationalCount"><?= $operationalCount ?></strong>
                </div>
                <div><span class="status-stat-label">Lỗi:</span> <strong id="statOutageCount"
                    class="<?= $troubledServicesCount > 0 ? 'text-danger' : '' ?>"><?= $troubledServicesCount ?></strong></div>
                <div><span class="status-stat-label">Cập nhật lúc:</span> <strong id="statUpdatedTime"><?= date('H:i') ?></strong>
                </div>
              </div>
            </div>

            <!-- Cột dữ liệu 2 -->
            <div class="col-12 col-sm">
              <div class="mb-2">
                <div class="status-stat-label header-label">Uptime TB 90 ngày: <strong id="statAvgUptime"
                    class="text-dark"><?= $avgUptime ?>%</strong></div>
              </div>
              <div class="d-flex flex-column gap-1 small">
                <div><span class="status-stat-label">Sự cố đang xử lý:</span> <strong
                    id="statActiveIncidents"><?= $effectiveActiveIncidentsCount ?></strong></div>
                <div><span class="status-stat-label">Bảo trì sắp tới:</span> <strong id="statUpcomingMaint"><?= $scheduledMaintCount ?></strong>
                </div>
                <div><span class="status-stat-label">Đang bảo trì:</span> <strong id="statInProgressMaint"><?= $inProgressMaintCount ?></strong>
                </div>
                <div><span class="status-stat-label">Tự làm mới:</span> sau <span id="statCountdown">30</span> giây
                </div>
              </div>
            </div>

          </div>
        </div>

        <!-- 2 Thẻ Trạng thái: Sự cố đang xử lý & Bảo trì trong tuần -->
        <div class="row g-3">
          <!-- Thẻ 1: Sự cố đang xử lý (Nền xanh Teal, Chữ trắng, Icon trắng) -->
          <div class="col-12 col-md-6">
            <div id="cardIncidentAction" class="utc-stat-card stat-card-green-incident h-100" role="button" tabindex="0">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="utc-stat-card-title text-white">Sự cố đang xử lý</span>
                <span class="utc-stat-card-icon text-white"><i class="fa-regular fa-bell"></i></span>
              </div>
              <div id="metricIncidentsCount" class="utc-stat-card-number text-white"><?= $effectiveActiveIncidentsCount ?></div>
              <div class="utc-stat-card-link text-white">
                <span>Xem chi tiết <i class="fa-solid fa-arrow-right ms-1"></i></span>
              </div>
            </div>
          </div>

          <!-- Thẻ 2: Bảo trì trong tuần (Nền xanh rêu đậm, Chữ trắng, Icon trắng) -->
          <div class="col-12 col-md-6">
            <div id="cardMaintenanceAction" class="utc-stat-card stat-card-green-maint h-100" role="button" tabindex="0">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="utc-stat-card-title text-white">Bảo trì trong tuần</span>
                <span class="utc-stat-card-icon text-white"><i class="fa-regular fa-calendar-days"></i></span>
              </div>
              <div id="metricMaintenancesCount" class="utc-stat-card-number text-white"><?= $effectiveMaintenanceCount ?></div>
              <div class="utc-stat-card-link text-white">
                <span>Xem chi tiết <i class="fa-solid fa-arrow-right ms-1"></i></span>
              </div>
            </div>
          </div>
        </div>

      </div>

      <!-- ── Right Column: Lịch theo tháng (Nền nhẹ & Chữ đậm rõ ràng) ── -->
      <div class="col-lg-5 col-xl-4 d-flex">
        <div class="utc-card calendar-card w-100 d-flex flex-column justify-content-between">
          <div>
            <div class="d-flex align-items-center justify-content-between mb-2">
              <h5 class="utc-card-title">Lịch theo tháng</h5>
              <div class="calendar-header-nav">
                <button id="calPrevMonth" class="calendar-nav-btn" aria-label="Tháng trước">&lsaquo;</button>
                <span id="calMonthLabel" class="calendar-month-text">tháng 10 2026</span>
                <button id="calNextMonth" class="calendar-nav-btn" aria-label="Tháng sau">&rsaquo;</button>
              </div>
            </div>

            <table class="calendar-table">
              <thead>
                <tr>
                  <th>CN</th>
                  <th>T2</th>
                  <th>T3</th>
                  <th>T4</th>
                  <th>T5</th>
                  <th>T6</th>
                  <th>T7</th>
                </tr>
              </thead>
              <tbody id="calTableBody">
                <!-- Rendered via JavaScript -->
              </tbody>
            </table>
          </div>

          <div class="calendar-legend border-top pt-2 mt-3">
            <span><span class="cal-dot cal-dot-incident d-inline-block me-1"></span>Sự cố</span>
            <span><span class="cal-dot cal-dot-maint d-inline-block me-1"></span>Bảo trì</span>
          </div>
        </div>
      </div>

    </div>

    <!-- ── KHỐI DƯỚI: HỆ SINH THÁI 7 THẺ CHỨC NĂNG (STATUS PAGE ECOSYSTEM) ── -->
    <section class="dashboard-features-hub">

      <!-- Tiêu đề danh mục tinh gọn -->
      <div class="features-section-header">
        <div class="d-flex align-items-center gap-2">
          <span class="header-accent-bar"></span>
          <h6 class="section-title-text mb-0">
            Danh mục chức năng hệ thống
          </h6>
        </div>
        <div class="section-note-text">
          <i class="fa-solid fa-arrow-pointer me-1"></i>Nhấp vào từng chức năng để mở bảng điều khiển tương tác
        </div>
      </div>

      <!-- Hàng 7 ô chức năng lộ rõ 100%, không bị bất kỳ khối nào đè lấn -->
      <div id="servicesGridContainer" class="services-grid">
        <!-- Rendered via JavaScript from STATUS_PAGE_FEATURES -->
      </div>

    </section>

  </main>

  </main>

  <!-- ══════════════════════════════════════════════════════════
     FOOTER
  ══════════════════════════════════════════════════════════════ -->
  <footer class="utc-footer text-center">
    <div class="container-fluid px-4">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="small">
          <strong>UTH STATUS</strong> &bull; Trung tâm CNTT – Trường Đại học Giao thông Vận tải (UTH)
        </div>
        <div class="small">
          <a href="#" onclick="openJsonModal(); return false;" class="text-decoration-none text-muted me-3"><i
              class="fa-solid fa-code me-1"></i>RESTful API</a>
          <a href="admin/login.php" class="text-decoration-none text-muted"><i
              class="fa-solid fa-shield-halved me-1"></i>Khu vực Quản trị</a>
        </div>
      </div>
    </div>
  </footer>

  <!-- ══════════════════════════════════════════════════════════
     MODALS (CLICK ON ANY FEATURE TO SHOW DETAIL)
  ══════════════════════════════════════════════════════════════ -->

  <!-- 1. Modal: Chi tiết Dịch Vụ & 90 ngày Uptime (Datadog / Statuspage Style - Stacked Modal Support) -->
  <div class="modal fade" id="modalServiceDetail" tabindex="-1" aria-labelledby="svcModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content utc-modal modal-uptime-pro shadow-lg">
        <div class="modal-header py-3 px-4 d-flex align-items-center justify-content-between border-bottom">
          <div class="d-flex align-items-center gap-3">
            <div id="svcModalIconBox" class="svc-icon-avatar">
              <i class="fa-solid fa-server fs-5 text-teal"></i>
            </div>
            <div>
              <div class="d-flex align-items-center gap-2 flex-wrap">
                <h5 id="svcModalTitle" class="modal-title fw-700 mb-0 text-dark">Chi tiết Dịch vụ</h5>
                <span id="svcModalStatusBadge"></span>
              </div>
              <div id="svcModalGroupSub" class="small text-muted mt-1">
                <!-- Group name & ID code populated dynamically -->
              </div>
            </div>
          </div>
          <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1 rounded-pill px-3 py-1-5" id="btnBackToRealtime" data-bs-dismiss="modal" title="Quay lại danh sách">
              <i class="fa-solid fa-arrow-left"></i>
              <span class="d-none d-sm-inline">Quay lại danh sách</span>
            </button>
            <button type="button" class="btn-close ms-1" data-bs-dismiss="modal" aria-label="Đóng"></button>
          </div>
        </div>
        <div id="svcModalBody" class="modal-body p-4">
          <!-- Dynamically populated -->
        </div>
        <div id="svcModalFooter" class="modal-footer py-2 px-4 bg-light border-top d-flex align-items-center justify-content-between">
          <!-- Quick actions footer populated dynamically -->
        </div>
      </div>
    </div>
  </div>

  <!-- 2. Modal: Chi tiết Sự Cố Đang Xử Lý -->
  <div class="modal fade" id="modalIncidentsDetail" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content utc-modal">
        <div class="modal-header">
          <h5 class="modal-title fw-700 text-danger"><i class="fa-solid fa-triangle-exclamation me-2"></i>Sự cố Hệ thống
            & Tiến trình Xử lý</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div id="incidentsModalList"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- 3. Modal: Kế Hoạch Bảo Trì (Đồng bộ chuẩn Pro & Màu xanh web) -->
  <div class="modal fade" id="modalMaintenancesDetail" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content utc-modal modal-maintenance-pro shadow-lg">
        <div class="modal-header py-3 px-4 d-flex align-items-center justify-content-between">
          <div class="d-flex align-items-center gap-3">
            <div class="realtime-header-icon-box" style="color: #d97706; border-color: #fde68a;">
              <i class="fa-solid fa-calendar-check fs-5"></i>
            </div>
            <div>
              <h5 class="modal-title mb-0 fw-700" style="color: var(--green-dark);">
                Kế Hoạch &amp; Lịch Trình Bảo Trì
              </h5>
              <div class="small text-muted mt-0-5">
                Lịch Nâng Cấp Kỹ Thuật Định Kỳ – Trung Tâm CNTT Đại Học Giao Thông Vận Tải
              </div>
            </div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
        </div>
        <div class="modal-body p-4">
          <!-- 3 Maintenance KPI Cards -->
          <div class="row g-3 mb-4" id="maintenanceKpiCards">
            <div class="col-4">
              <div class="stats-card-minimal h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                  <span class="stats-card-label">Đang diễn ra</span>
                  <div class="stats-card-icon-box text-warning bg-warning-subtle-box"><i class="fa-solid fa-spinner fa-spin"></i></div>
                </div>
                <div id="maintKpiInProgress" class="stats-card-value text-warning">0</div>
                <div class="stats-card-meta"><i class="fa-solid fa-clock-rotate-left text-warning me-1"></i>Bảo trì live</div>
              </div>
            </div>
            <div class="col-4">
              <div class="stats-card-minimal h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                  <span class="stats-card-label">Kế hoạch sắp tới</span>
                  <div class="stats-card-icon-box text-teal bg-teal-subtle-box"><i class="fa-regular fa-calendar-clock"></i></div>
                </div>
                <div id="maintKpiUpcoming" class="stats-card-value text-teal">0</div>
                <div class="stats-card-meta"><i class="fa-solid fa-calendar-check text-teal me-1"></i>Đã xếp lịch</div>
              </div>
            </div>
            <div class="col-4">
              <div class="stats-card-minimal h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                  <span class="stats-card-label">Lịch sử hoàn tất</span>
                  <div class="stats-card-icon-box text-success bg-success-subtle-box"><i class="fa-solid fa-circle-check"></i></div>
                </div>
                <div id="maintKpiCompleted" class="stats-card-value text-success">0</div>
                <div class="stats-card-meta"><i class="fa-solid fa-check-double text-success me-1"></i>Đúng tiến độ</div>
              </div>
            </div>
          </div>

          <!-- Filter Tabs & Title -->
          <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2 pt-2 border-top">
            <h6 class="fw-700 text-dark mb-0 d-flex align-items-center gap-2">
              <i class="fa-solid fa-screwdriver-wrench text-teal"></i>
              <span>Danh Sách Cửa Sổ Bảo Trì &amp; Nâng Cấp Hệ Thống</span>
            </h6>
            <div id="maintenanceFilterTabs" class="d-flex gap-1"></div>
          </div>

          <div id="maintenancesModalList"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- 4. Modal: Sự Kiện Ngày Trong Lịch -->
  <div class="modal fade" id="modalDayEvents" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content utc-modal">
        <div class="modal-header">
          <h5 id="dayEventsModalTitle" class="modal-title fw-700">Sự kiện ngày</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div id="dayEventsModalBody" class="modal-body"></div>
      </div>
    </div>
  </div>

  <!-- 5. Modal: Xem Dữ Liệu JSON API -->
  <div class="modal fade" id="modalJsonData" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content utc-modal">
        <div class="modal-header">
          <h5 class="modal-title fw-700"><i class="fa-solid fa-code me-2" style="color:var(--teal-primary)"></i>Dữ liệu
            JSON Trạng thái (/api/status.php)</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="small text-muted">Phản hồi chuẩn RESTful JSON theo thời gian thực</span>
            <div>
              <button class="btn btn-sm btn-outline-secondary me-2" onclick="copyJsonToClipboard()"><i
                  class="fa-regular fa-copy me-1"></i>Sao chép</button>
              <a href="api/status.php" target="_blank" class="btn btn-sm btn-primary"><i
                  class="fa-solid fa-arrow-up-right-from-square me-1"></i>Mở Tab Mới</a>
            </div>
          </div>
          <pre id="jsonCodeBox" class="json-code-box"></pre>
        </div>
      </div>
    </div>
  </div>

  <!-- 6. Modal: Đăng ký Nhận Thông Báo -->
  <div class="modal fade" id="modalSubscribe" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content utc-modal">
        <div class="modal-header">
          <h5 class="modal-title fw-700"><i class="fa-regular fa-bell me-2" style="color:var(--teal-primary)"></i>Đăng
            ký Nhận Thông Báo Sự Cố</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form id="formSubscribe">
          <div class="modal-body">
            <p id="subNote" class="small text-muted mb-3">Nhận thông báo tự động khi có sự cố hoặc bảo trì hệ thống.</p>
            <div class="mb-3">
              <label class="form-label small fw-600">Hình thức nhận thông báo:</label>
              <select id="subType" class="form-select">
                <option value="email">Hộp thư Email</option>
                <option value="slack">Slack Webhook</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label small fw-600">Địa chỉ Email hoặc Slack URL:</label>
              <input type="text" id="subEndpoint" class="form-control" placeholder="sinhvien@student.uth.edu.vn"
                required />
            </div>
            <div id="subFeedback"></div>
          </div>
          <div class="modal-footer border-0">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
            <button type="submit" id="btnSubmitSub" class="btn btn-primary"
              style="background-color:var(--teal-primary);border-color:var(--teal-primary)">Đăng ký ngay</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- 7. Modal: Real-time Monitoring & Status (Giám sát toàn bộ dịch vụ - Đồng bộ màu xanh web) -->
  <div class="modal fade" id="modalRealtimeOverview" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content utc-modal modal-realtime-pro shadow-lg">
        <div class="modal-header py-3 px-4 d-flex align-items-center justify-content-between">
          <div class="d-flex align-items-center gap-3">
            <div class="realtime-header-icon-box">
              <i class="fa-solid fa-heart-pulse fs-5"></i>
            </div>
            <div>
              <h5 class="modal-title mb-0">
                Real-time Monitoring &amp; Status
              </h5>
              <div class="small text-muted mt-0-5">
                Giám Sát Trạng Thái Tức Thời – Hệ Thống Dịch Vụ Đại Học Giao Thông Vận Tải
              </div>
            </div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
        </div>
        <div class="modal-body p-4">
          <div class="realtime-matrix-banner d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
            <div>
              <div class="fw-700 fs-6 text-dark d-flex align-items-center gap-2">
                <i class="fa-solid fa-network-wired text-teal"></i>
                <span>Ma trận trạng thái dịch vụ (Datadog Component Matrix)</span>
              </div>
              <div class="text-muted small mt-0-5">
                <i class="fa-solid fa-arrows-rotate me-1 text-teal"></i>Cập nhật tự động theo thời gian thực mỗi 30 giây
              </div>
            </div>
            <div id="realtimeFilterBadges" class="d-flex gap-2"></div>
          </div>
          <div id="realtimeServicesList" class="row g-3">
            <!-- Dynamically populated -->
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- 8. Modal: Uptime & Incident History (SLA / SLI Analytics - Đồng bộ màu xanh web) -->
  <div class="modal fade" id="modalUptimeHistory" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content utc-modal modal-uptime-history-pro shadow-lg">
        <div class="modal-header py-3 px-4 d-flex align-items-center justify-content-between">
          <div class="d-flex align-items-center gap-3">
            <div class="realtime-header-icon-box" style="color: #2563eb; border-color: #bfdbfe;">
              <i class="fa-solid fa-chart-line fs-5"></i>
            </div>
            <div>
              <h5 class="modal-title mb-0 fw-700" style="color: var(--green-dark);">
                Uptime &amp; Incident History
              </h5>
              <div class="small text-muted mt-0-5">
                Thống Kê Cam Kết SLA 90 Ngày &amp; Lịch Sử Xử Lý Sự Cố Hệ Thống
              </div>
            </div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
        </div>
        <div class="modal-body p-4">
          <!-- 4 SLA KPI Cards -->
          <div class="row g-3 mb-4" id="uptimeKpiCards">
            <div class="col-6 col-md-3">
              <div class="stats-card-minimal h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                  <span class="stats-card-label">Uptime SLA 90 ngày</span>
                  <div class="stats-card-icon-box text-success bg-success-subtle-box"><i class="fa-solid fa-shield-check"></i></div>
                </div>
                <div id="uptimeKpiSla" class="stats-card-value text-success">99.88%</div>
                <div class="stats-card-meta"><i class="fa-solid fa-circle-check text-success me-1"></i>Đạt chuẩn SLA UTH</div>
              </div>
            </div>
            <div class="col-6 col-md-3">
              <div class="stats-card-minimal h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                  <span class="stats-card-label">Sự cố 90 ngày</span>
                  <div class="stats-card-icon-box text-danger bg-danger-subtle-box"><i class="fa-solid fa-triangle-exclamation"></i></div>
                </div>
                <div id="uptimeTotalIncidents" class="stats-card-value text-danger">7</div>
                <div class="stats-card-meta">100% Đã giải quyết</div>
              </div>
            </div>
            <div class="col-6 col-md-3">
              <div class="stats-card-minimal h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                  <span class="stats-card-label">MTTR (Xử lý TB)</span>
                  <div class="stats-card-icon-box text-primary bg-teal-subtle-box"><i class="fa-solid fa-stopwatch"></i></div>
                </div>
                <div id="uptimeKpiMttr" class="stats-card-value text-primary">58 min</div>
                <div class="stats-card-meta"><i class="fa-solid fa-bolt text-warning me-1"></i>Dưới mục tiêu 2h</div>
              </div>
            </div>
            <div class="col-6 col-md-3">
              <div class="stats-card-minimal h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                  <span class="stats-card-label">Khả dụng</span>
                  <div class="stats-card-icon-box text-teal bg-teal-subtle-box"><i class="fa-solid fa-server"></i></div>
                </div>
                <div id="uptimeKpiHours" class="stats-card-value text-teal">2,153h</div>
                <div class="stats-card-meta">Giám sát 24/7</div>
              </div>
            </div>
          </div>

          <!-- Filter Tabs & Timeline -->
          <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2 pt-2 border-top">
            <h6 class="fw-700 text-dark mb-0 d-flex align-items-center gap-2">
              <i class="fa-solid fa-timeline text-teal"></i>
              <span>Nhật ký Sự cố &amp; Tiến trình Giải quyết</span>
            </h6>
            <div id="uptimeIncidentFilterTabs" class="d-flex gap-1"></div>
          </div>
          <div id="uptimeIncidentTimeline"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- 9. Modal: User Incident Reporting (Triage Form) -->
  <div class="modal fade" id="modalUserReport" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content utc-modal">
        <div class="modal-header">
          <h5 class="modal-title fw-700" style="color:#d97706">
            <i class="fa-solid fa-triangle-exclamation me-2"></i>User Incident Reporting – Tiếp Nhận Báo Cáo Sự Cố
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form id="formUserReport">
          <div class="modal-body p-4">
            <div class="alert alert-info py-2 small mb-3">
              <i class="fa-solid fa-circle-info me-1"></i>
              Nếu bạn phát hiện sự cố gián đoạn dịch vụ, hãy gửi thông tin mô tả bên dưới. Đội ngũ IT Trung tâm CNTT sẽ
              tiếp nhận và tiến hành quy trình phân loại (Triage) xử lý ngay.
            </div>

            <div class="row g-3 mb-3">
              <div class="col-md-6">
                <label class="form-label small fw-600">Họ và tên người báo cáo <span
                    class="text-danger">*</span></label>
                <input type="text" id="repName" class="form-control" placeholder="Nguyễn Văn A" required />
              </div>
              <div class="col-md-6">
                <label class="form-label small fw-600">Email liên hệ (@uth.edu.vn) <span
                    class="text-danger">*</span></label>
                <input type="email" id="repEmail" class="form-control" placeholder="sv@student.uth.edu.vn" required />
              </div>
            </div>

            <div class="row g-3 mb-3">
              <div class="col-md-6">
                <label class="form-label small fw-600">Mã sinh viên / Cán bộ <span class="text-muted">(nếu có)</span></label>
                <input type="text" id="repStudentCode" class="form-control" placeholder="VD: 201200189 hoặc K62-CNTT" />
              </div>
              <div class="col-md-6">
                <label class="form-label small fw-600">Vị trí / Địa điểm gặp sự cố <span
                    class="text-danger">*</span></label>
                <input type="text" id="repLocation" class="form-control" placeholder="VD: Nhà A7 - P302, Thư viện, KTX Cầu Giấy..." required />
              </div>
            </div>

            <div class="row g-3 mb-3">
              <div class="col-md-6">
                <label class="form-label small fw-600">Dịch vụ bị ảnh hưởng</label>
                <select id="repServiceId" class="form-select">
                  <option value="">-- Chọn dịch vụ gặp lỗi --</option>
                  <!-- Populated from systemData.services -->
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label small fw-600">Mức độ ảnh hưởng dự kiến</label>
                <select id="repSeverity" class="form-select">
                  <option value="low">Thấp (Chậm nhẹ, không chặn thao tác)</option>
                  <option value="medium" selected>Trung bình (Một số tính năng bị gián đoạn)</option>
                  <option value="high">Cao (Không thể đăng nhập/nộp bài)</option>
                  <option value="critical">Nghiêm trọng (Toàn bộ dịch vụ sập hoàn toàn)</option>
                </select>
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label small fw-600">Tiêu đề sự cố tóm tắt <span class="text-danger">*</span></label>
              <input type="text" id="repTitle" class="form-control"
                placeholder="Ví dụ: Không tải được danh sách thi trắc nghiệm trên LMS" required />
            </div>

            <div class="mb-3">
              <label class="form-label small fw-600">Mô tả chi tiết hiện tượng / Mã lỗi (nếu có) <span
                  class="text-danger">*</span></label>
              <textarea id="repDesc" class="form-control" rows="3"
                placeholder="Mô tả cụ thể thời điểm xảy ra lỗi, đường link và mã lỗi xuất hiện trên màn hình..."
                required></textarea>
            </div>

            <div id="repFeedback"></div>
          </div>
          <div class="modal-footer border-0">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
            <button type="submit" id="btnSubmitReport" class="btn btn-warning fw-600 text-dark">
              <i class="fa-solid fa-paper-plane me-1"></i>Gửi Báo Cáo Sự Cố (Submit Triage)
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- 10. Modal: Incident Commander (Trung tâm chỉ huy sự cố) -->
  <div class="modal fade" id="modalCommander" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content utc-modal">
        <div class="modal-header">
          <h5 class="modal-title fw-700 text-danger">
            <i class="fa-solid fa-shield-halved me-2"></i>Admin Incident Commander – Trung Tâm Chỉ Huy Sự Cố
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body p-4">
          <!-- Incident Workflow Roadmap -->
          <div class="mb-4">
            <div class="fw-700 text-dark mb-2">Quy Trình Xử Lý Sự Cố Chuẩn (Atlassian Incident Lifecycle)</div>
            <div class="d-flex align-items-center justify-content-between p-3 rounded bg-light border flex-wrap gap-2">
              <div class="text-center">
                <span class="badge bg-danger px-3 py-2 mb-1 d-inline-block">1. Investigating</span>
                <div class="small fw-600">Điều tra sơ bộ</div>
              </div>
              <i class="fa-solid fa-arrow-right text-muted d-none d-sm-inline"></i>
              <div class="text-center">
                <span class="badge bg-warning text-dark px-3 py-2 mb-1 d-inline-block">2. Identified</span>
                <div class="small fw-600">Xác định lỗi</div>
              </div>
              <i class="fa-solid fa-arrow-right text-muted d-none d-sm-inline"></i>
              <div class="text-center">
                <span class="badge bg-primary px-3 py-2 mb-1 d-inline-block">3. Monitoring</span>
                <div class="small fw-600">Giám sát vá</div>
              </div>
              <i class="fa-solid fa-arrow-right text-muted d-none d-sm-inline"></i>
              <div class="text-center">
                <span class="badge bg-success px-3 py-2 mb-1 d-inline-block">4. Resolved</span>
                <div class="small fw-600">Hoàn tất &amp; SLA</div>
              </div>
            </div>
          </div>

          <!-- Current Outage / Commander Alert -->
          <div id="commanderActiveAlertBox" class="mb-4"></div>

          <!-- Incident Postmortem Checklist -->
          <div class="p-3 rounded border mb-4">
            <div class="fw-700 mb-2 text-dark"><i class="fa-solid fa-file-shield me-2 text-primary"></i>Quy chuẩn
              Incident Postmortem (RCA Checklist)</div>
            <ul class="small text-muted mb-0 ps-3">
              <li><strong>Phát hiện &amp; Phân loại:</strong> Thời gian phát hiện (TTD) và mức độ ảnh hưởng (Severity).
              </li>
              <li><strong>Phân tích Nguyên nhân Gốc rễ (RCA):</strong> Xác định mã nguồn, cơ sở dữ liệu hay hạ tầng mạng
                gây lỗi.</li>
              <li><strong>Biện pháp Khắc phục Triệt để:</strong> Các đầu việc ngăn ngừa tái diễn (Action Items &amp;
                Patches).</li>
            </ul>
          </div>

          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="small text-muted">Dành riêng cho cán bộ kỹ thuật và quản trị viên Trung tâm CNTT</span>
            <a href="admin/login.php" class="btn btn-danger fw-600">
              <i class="fa-solid fa-arrow-right-to-bracket me-1"></i>Vào Bảng Điều Khiển Quản Trị (Admin Panel)
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- 11. Modal: Component Health Checks (APIs & Latency Probe) -->
  <div class="modal fade" id="modalHealthChecks" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content utc-modal">
        <div class="modal-header">
          <h5 class="modal-title fw-700" style="color:#06b6d4">
            <i class="fa-solid fa-network-wired me-2"></i>Component Health Checks – Kiểm Tra Sức Khỏe Endpoint &amp; Độ
            Trễ Live
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body p-4">
          <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
            <div>
              <span class="fw-700 fs-6 text-dark me-2">Bảng kiểm tra tự động Endpoint (Live HTTP Latency Probe)</span>
              <span id="healthCheckLastRun" class="text-muted small">Sẵn sàng kiểm tra</span>
            </div>
            <button id="btnRunHealthProbe" class="btn btn-sm btn-outline-info fw-600"
              onclick="triggerHealthCheckProbe()">
              <i class="fa-solid fa-rotate me-1"></i>Chạy Kiểm Tra Ngay (Run Probe)
            </button>
          </div>

          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light small text-uppercase">
                <tr>
                  <th>Dịch vụ / Endpoint</th>
                  <th>URL Kiểm tra</th>
                  <th>Mã HTTP</th>
                  <th>Độ trễ Latency</th>
                  <th>Trạng thái Đánh giá</th>
                </tr>
              </thead>
              <tbody id="healthProbeTableBody">
                <tr>
                  <td colspan="5" class="text-center py-4 text-muted">
                    Nhấp nút <strong>"Chạy Kiểm Tra Ngay"</strong> để bắt đầu quét độ trễ và trạng thái HTTP của các
                    dịch vụ.
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- 7. Offcanvas: Sidebar Menu -->
  <div class="offcanvas offcanvas-start" tabindex="-1" id="offcanvasSidebar" aria-labelledby="offcanvasSidebarLabel">
    <div class="offcanvas-header border-bottom">
      <h5 class="offcanvas-title fw-800 text-danger" id="offcanvasSidebarLabel">UTH <span
          style="color:var(--teal-primary)">STATUS</span></h5>
      <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body d-flex flex-direction-column justify-content-between">
      <ul class="nav nav-pills flex-column gap-2">
        <li class="nav-item">
          <a class="nav-link active" style="background-color:var(--teal-primary)" href="index.php"><i
              class="fa-solid fa-house me-2"></i>Trang chủ Tổng quan</a>
        </li>
        <li class="nav-item">
          <a class="nav-link text-dark" href="#" onclick="openIncidentsModal(); return false;"><i
              class="fa-solid fa-triangle-exclamation me-2 text-danger"></i>Sự cố Đang xử lý</a>
        </li>
        <li class="nav-item">
          <a class="nav-link text-dark" href="#" onclick="openMaintenancesModal(); return false;"><i
              class="fa-solid fa-screwdriver-wrench me-2 text-warning"></i>Kế hoạch Bảo trì</a>
        </li>
        <li class="nav-item">
          <a class="nav-link text-dark" href="#" onclick="openJsonModal(); return false;"><i
              class="fa-solid fa-code me-2 text-info"></i>Dữ liệu JSON API</a>
        </li>
        <?php if ($adminRole === 'admin' || $adminRole === 'editor'): ?>
        <li class="nav-item">
          <a class="nav-link text-dark" href="admin/index.php"><i class="fa-solid fa-gauge-high me-2 text-primary"></i>Trang Quản trị Hệ thống</a>
        </li>
        <?php endif; ?>
        <li class="nav-item">
          <a class="nav-link text-danger fw-600" href="admin/logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Đăng xuất tài khoản</a>
        </li>
        <li class="nav-item">
          <a class="nav-link text-dark" href="admin/links.php"><i class="fa-solid fa-list-check me-2 text-info"></i>Sơ
            đồ Địa chỉ Web &amp; Admin</a>
        </li>
      </ul>
      <div class="text-muted small border-top pt-3">
        Phiên bản 2.0 &bull; Đại học GTVT (UTH)
      </div>
    </div>
  </div>

  <!-- Global App Config -->
  <script>
    window.CURRENT_USER_ROLE = '<?= htmlspecialchars($adminRole, ENT_QUOTES, 'UTF-8') ?>';
    window.CURRENT_USER_NAME = '<?= htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8') ?>';
  </script>

  <!-- Bootstrap 5 Bundle JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <!-- Application JS (v3.6 cache-buster) -->
  <script src="assets/js/app.js?v=3.6"></script>
  <!-- AI Assistant Chatbot JS -->
  <script src="assets/js/ai_chat.js?v=1.0"></script>
</body>

</html>