<?php
declare(strict_types=1);

/**
 * UTC STATUS - Bảng Điều Hướng & Danh Mục Địa Chỉ Truy Cập Hệ Thống
 * File: admin/links.php
 * Tổng hợp toàn bộ các liên kết Web Client, Admin Panel và RESTful APIs
 */

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443 ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '', 2), '/\\');
$baseUrl = $protocol . '://' . $host . ($baseDir ? $baseDir : '');

$linksData = [
  'public' => [
    'title' => 'Địa Chỉ Giao Diện Người Dùng (Public Web Client)',
    'icon' => 'fa-globe',
    'color' => '#0d9488',
    'items' => [
      [
        'name' => 'Trang chủ Trang Trạng Thái (Status Page)',
        'path' => '/index.php',
        'desc' => 'Theo dõi trạng thái hệ thống theo thời gian thực, lịch tháng, donut meter và 7 khối chức năng.',
        'method' => 'GET',
        'target' => '_blank'
      ],
      [
        'name' => 'Dữ Liệu JSON API Trực Tiếp',
        'path' => '/api/status.php',
        'desc' => 'Phản hồi dữ liệu JSON chuẩn RESTful của toàn bộ hệ thống phục vụ tích hợp bên ngoài.',
        'method' => 'GET',
        'target' => '_blank'
      ],
      [
        'name' => 'Kích Hoạt / Xác Minh Email Đăng Ký',
        'path' => '/verify.php?token=EXAMPLE_TOKEN',
        'desc' => 'Trang xác thực email kích hoạt nhận thông báo sự cố tự động (token 64 ký tự).',
        'method' => 'GET',
        'target' => '_blank'
      ],
      [
        'name' => 'Hủy Đăng Ký Nhận Thông Báo',
        'path' => '/unsubscribe.php?token=EXAMPLE_TOKEN',
        'desc' => 'Trang cho phép người dùng hủy nhận cảnh báo bảo trì và sự cố.',
        'method' => 'GET',
        'target' => '_blank'
      ]
    ]
  ],
  'admin' => [
    'title' => 'Địa Chỉ Bảng Điều Khiển Quản Trị (Admin Control Panel)',
    'icon' => 'fa-shield-halved',
    'color' => '#ef4444',
    'items' => [
      [
        'name' => 'Trang Đăng Nhập Quản Trị (Login)',
        'path' => '/admin/login.php',
        'desc' => 'Xác thực tài khoản Admin/Editor, chống Brute-force, ghi nhớ đăng nhập và kiểm tra CSRF token.',
        'method' => 'GET/POST',
        'target' => '_self'
      ],
      [
        'name' => 'Trang Đăng Ký Tài Khoản (Register)',
        'path' => '/admin/register.php',
        'desc' => 'Đăng ký tài khoản Biên tập viên (Editor) mới với thanh đo độ mạnh mật khẩu và bảo mật CSRF.',
        'method' => 'GET/POST',
        'target' => '_self'
      ],
      [
        'name' => 'Trang Quên Mật Khẩu (Forgot Password)',
        'path' => '/admin/forgot_password.php',
        'desc' => 'Quy trình 2 bước khôi phục mật khẩu an toàn với mã xác nhận OTP 6 chữ số và thời hạn 15 phút.',
        'method' => 'GET/POST',
        'target' => '_self'
      ],
      [
        'name' => 'Bảng Điều Khiển Quản Trị (Dashboard)',
        'path' => '/admin/index.php',
        'desc' => 'Trung tâm điều khiển: Quick status change, KPI metrics, CRUD dịch vụ, sự cố và bảo trì.',
        'method' => 'GET/POST',
        'target' => '_self'
      ],
      [
        'name' => 'Quản Lý Dịch Vụ (Services CRUD)',
        'path' => '/admin/index.php#tab-services',
        'desc' => 'Thêm mới, chỉnh sửa thông tin, biểu tượng, check_url và xóa dịch vụ (Quyền Admin).',
        'method' => 'GET',
        'target' => '_self'
      ],
      [
        'name' => 'Quản Lý Nhóm Dịch Vụ (Service Groups)',
        'path' => '/admin/index.php#tab-groups',
        'desc' => 'Phân loại các cụm dịch vụ: Hạ tầng Mạng, Phần mềm & Ứng dụng, Email & Giao tiếp.',
        'method' => 'GET',
        'target' => '_self'
      ],
      [
        'name' => 'Quản Lý Sự Cố & Tiến Trình (Incidents)',
        'path' => '/admin/index.php#tab-incidents',
        'desc' => 'Tạo sự cố mới, cập nhật mốc timeline (Investigating -> Resolved) và tự phục hồi dịch vụ.',
        'method' => 'GET',
        'target' => '_self'
      ],
      [
        'name' => 'Quản Lý Lịch Bảo Trì (Maintenances)',
        'path' => '/admin/index.php#tab-maintenances',
        'desc' => 'Lên lịch bảo trì định kỳ, thời gian bắt đầu, kết thúc dự kiến và các node chịu ảnh hưởng.',
        'method' => 'GET',
        'target' => '_self'
      ],
      [
        'name' => 'Báo Cáo & Phân Tích Uptime (Analytics)',
        'path' => '/admin/index.php#tab-reports',
        'desc' => 'Biểu đồ thanh Chart.js so sánh tỷ lệ Uptime 90 ngày và biểu đồ tròn tình trạng dịch vụ.',
        'method' => 'GET',
        'target' => '_self'
      ],
      [
        'name' => 'Nhật Ký Kiểm Toán (Audit Activity Logs)',
        'path' => '/admin/index.php#tab-logs',
        'desc' => 'Theo dõi vết thao tác: ai đã đổi trạng thái, thời gian, IP và lịch sử trạng thái dịch vụ.',
        'method' => 'GET',
        'target' => '_self'
      ],
      [
        'name' => 'Đăng Xuất Khỏi Hệ Thống (Logout)',
        'path' => '/admin/logout.php',
        'desc' => 'Hủy phiên làm việc an toàn, xóa Session và chuyển hướng về trang đăng nhập.',
        'method' => 'GET',
        'target' => '_self'
      ]
    ]
  ],
  'api' => [
    'title' => 'Danh Mục RESTful API (JSON Endpoints)',
    'icon' => 'fa-code',
    'color' => '#0284c7',
    'items' => [
      [
        'name' => 'API Trạng Thái Tổng Quan',
        'path' => '/api/status.php',
        'desc' => 'Trả về trạng thái chung, số liệu thống kê, danh sách dịch vụ và mốc sự kiện lịch tháng.',
        'method' => 'GET',
        'target' => '_blank'
      ],
      [
        'name' => 'API Lịch Sử Sự Cố (Incidents)',
        'path' => '/api/incidents.php',
        'desc' => 'Danh sách sự cố hiện tại và quá khứ kèm các bản tin cập nhật tiến độ.',
        'method' => 'GET',
        'target' => '_blank'
      ],
      [
        'name' => 'API Kế Hoạch Bảo Trì (Maintenances)',
        'path' => '/api/maintenances.php',
        'desc' => 'Danh sách kế hoạch bảo dưỡng, nâng cấp hạ tầng mạng và máy chủ.',
        'method' => 'GET',
        'target' => '_blank'
      ],
      [
        'name' => 'API Uptime 90 Ngày Dịch Vụ',
        'path' => '/api/uptime.php?service_id=2',
        'desc' => 'Trả về 90 ngày uptime và tỷ lệ khả dụng chi tiết theo từng ngày cho dịch vụ ID cụ thể.',
        'method' => 'GET',
        'target' => '_blank'
      ],
      [
        'name' => 'API Tự Động Kiểm Tra Sức Khỏe (Health Check)',
        'path' => '/api/health_check.php',
        'desc' => 'Quét live qua cURL, đo độ trễ Latency (ms), mã HTTP và đánh giá mức độ ổn định.',
        'method' => 'GET',
        'target' => '_blank'
      ],
      [
        'name' => 'API Đăng Ký Nhận Thông Báo (Subscribe)',
        'path' => '/api/subscribe.php',
        'desc' => 'Tiếp nhận đăng ký qua Email hoặc Slack Webhook với xác thực token.',
        'method' => 'POST',
        'target' => '_blank'
      ],
      [
        'name' => 'API Tiếp Nhận Báo Cáo Sự Cố Người Dùng',
        'path' => '/api/report_incident.php',
        'desc' => 'Kênh tiếp nhận báo cáo lỗi từ giảng viên/sinh viên phục vụ quy trình Triage nhanh.',
        'method' => 'POST',
        'target' => '_blank'
      ]
    ]
  ]
];
?>
<!DOCTYPE html>
<html lang="vi">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Danh Mục Địa Chỉ Web &amp; Admin - UTC STATUS</title>
  <!-- Bootstrap 5.3 CDN -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" />
  <!-- Font Awesome 6 -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <!-- Google Fonts Inter -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/css/style.css" />
  <style>
    body {
      background-color: var(--bg-page);
      color: #1e293b;
      font-family: 'Inter', sans-serif;
    }

    .urls-header {
      background: #ffffff;
      border-bottom: 1px solid var(--border-color);
    }

    .url-item-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 1.1rem;
      transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
      height: 100%;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }

    .url-item-card:hover {
      transform: translateY(-2px);
      border-color: #cbd5e1;
      box-shadow: 0 6px 16px rgba(0, 0, 0, 0.06);
    }

    .method-badge {
      font-size: 0.7rem;
      font-weight: 700;
      padding: 2px 7px;
      border-radius: 4px;
      font-family: monospace;
    }

    .method-get {
      background: #ecfdf5;
      color: #059669;
      border: 1px solid #a7f3d0;
    }

    .method-post {
      background: #eff6ff;
      color: #2563eb;
      border: 1px solid #bfdbfe;
    }

    .url-path-code {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 6px;
      padding: 6px 10px;
      font-size: 0.78rem;
      font-family: SFMono-Regular, Menlo, Monaco, Consolas, monospace;
      color: #0f172a;
      word-break: break-all;
      margin: 0.5rem 0;
    }

    .btn-copy-url {
      font-size: 0.75rem;
      padding: 3px 8px;
      border-radius: 6px;
    }

    .section-pill-tag {
      width: 4px;
      height: 18px;
      border-radius: 2px;
      display: inline-block;
    }
  </style>
</head>

<body>

  <!-- Top Navigation -->
  <header class="urls-header py-3 px-3 px-lg-4 sticky-top">
    <div class="container-fluid d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div class="d-flex align-items-center gap-3">
        <a href="index.php" class="text-decoration-none d-flex align-items-center gap-2">
          <span class="fs-4 text-danger fw-800">UTC <span style="color:var(--teal-primary)">STATUS</span></span>
          <span class="text-muted small fw-600">| Sơ Đồ Địa Chỉ Truy Cập (Sitemap &amp; URLs)</span>
        </a>
      </div>
      <div class="d-flex align-items-center gap-2">
        <a href="../index.php" target="_blank" class="btn btn-sm btn-outline-secondary">
          <i class="fa-solid fa-arrow-up-right-from-square me-1"></i>Trang Public
        </a>
        <a href="index.php" class="btn btn-sm btn-primary"
          style="background-color:var(--teal-primary);border-color:var(--teal-primary)">
          <i class="fa-solid fa-gauge-high me-1"></i>Vào Bảng Quản Trị
        </a>
      </div>
    </div>
  </header>

  <!-- Main Body -->
  <main class="container-fluid px-3 px-lg-4 py-4">

    <!-- Credentials Notice Box -->
    <div
      class="p-3 mb-4 rounded-3 border bg-white shadow-sm d-flex align-items-center justify-content-between flex-wrap gap-3">
      <div>
        <div class="fw-700 text-dark mb-1">
          <i class="fa-solid fa-key me-2 text-warning"></i>Tài Khoản Đăng Nhập Quản Trị Hệ Thống
        </div>
        <div class="small text-muted">
          Quản trị viên (Admin): <code class="text-danger fw-600">admin</code> / <code
            class="text-danger fw-600">Admin@123</code> &bull;
          Biên tập viên (Editor): <code class="text-primary fw-600">editor</code> / <code
            class="text-primary fw-600">Editor@123</code>
        </div>
      </div>
      <div class="text-muted small">
        Máy chủ cục bộ: <code class="bg-light px-2 py-1 rounded"><?= htmlspecialchars($baseUrl) ?></code>
      </div>
    </div>

    <!-- URL Sections -->
    <?php foreach ($linksData as $groupKey => $group): ?>
      <section class="mb-5">
        <div class="d-flex align-items-center gap-2 mb-3">
          <span class="section-pill-tag" style="background-color: <?= $group['color'] ?>;"></span>
          <h5 class="fw-700 mb-0 text-dark fs-6 text-uppercase" style="letter-spacing: 0.04em;">
            <i class="fa-solid <?= $group['icon'] ?> me-2" style="color: <?= $group['color'] ?>;"></i>
            <?= htmlspecialchars($group['title']) ?>
          </h5>
        </div>

        <div class="row g-3">
          <?php foreach ($group['items'] as $item): ?>
            <?php $fullUrl = $baseUrl . $item['path']; ?>
            <div class="col-md-6 col-lg-4">
              <div class="url-item-card">
                <div>
                  <div class="d-flex align-items-center justify-content-between mb-2">
                    <strong class="text-dark small fw-700"><?= htmlspecialchars($item['name']) ?></strong>
                    <span
                      class="method-badge <?= strpos($item['method'], 'POST') !== false ? 'method-post' : 'method-get' ?>">
                      <?= htmlspecialchars($item['method']) ?>
                    </span>
                  </div>

                  <p class="text-muted small mb-2" style="font-size: 0.8rem; min-height: 38px;">
                    <?= htmlspecialchars($item['desc']) ?>
                  </p>

                  <div class="url-path-code">
                    <?= htmlspecialchars($fullUrl) ?>
                  </div>
                </div>

                <div class="d-flex align-items-center justify-content-between mt-3 pt-2 border-top">
                  <button type="button" class="btn btn-sm btn-outline-secondary btn-copy-url"
                    onclick="copyToClipboard('<?= addslashes($fullUrl) ?>', this)">
                    <i class="fa-regular fa-copy me-1"></i>Sao chép URL
                  </button>
                  <a href="<?= htmlspecialchars($fullUrl) ?>" target="<?= $item['target'] ?>"
                    class="btn btn-sm btn-outline-primary py-1 px-2" style="font-size:0.75rem;">
                    <i class="fa-solid fa-arrow-up-right-from-square me-1"></i>Mở liên kết
                  </a>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>

  </main>

  <footer class="py-3 text-center border-top bg-white small text-muted">
    UTC STATUS &bull; Trung tâm CNTT – Trường Đại học Giao thông Vận tải &bull; Phiên bản 2.0
  </footer>

  <script>
    function copyToClipboard(text, btn) {
      navigator.clipboard.writeText(text).then(() => {
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-check me-1 text-success"></i>Đã sao chép!';
        btn.classList.remove('btn-outline-secondary');
        btn.classList.add('btn-success', 'text-white');
        setTimeout(() => {
          btn.innerHTML = originalHtml;
          btn.classList.remove('btn-success', 'text-white');
          btn.classList.add('btn-outline-secondary');
        }, 1800);
      }).catch(err => {
        alert('Không thể sao chép URL: ' + err);
      });
    }
  </script>
</body>

</html>