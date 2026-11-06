# UTC STATUS – TRANG TRẠNG THÁI DỊCH VỤ THEO THỜI GIAN THỰC
### Trung tâm Ứng dụng CNTT – Trường Đại học Giao thông Vận tải (UTC)

Hệ thống theo dõi trạng thái hoạt động theo thời gian thực (Status Page) của toàn bộ các dịch vụ công nghệ thông tin trong trường: Cổng thông tin sinh viên, Đăng ký học phần, LMS (Moodle), Học phí trực tuyến, Email sinh viên, Wifi eduroam, Thư viện số, Website trường, v.v.

---

## 1. CÔNG NGHỆ SỬ DỤNG
- **Frontend:** HTML5, CSS3 Glassmorphism, Bootstrap 5.3 CDN, FontAwesome 6, Chart.js.
- **Backend:** PHP thuần (Kiến trúc hướng dịch vụ, chuẩn bị sẵn Prepared Statements chống SQL Injection, CSRF Token, Session Security, Anti-brute force, RBAC).
- **Cơ sở dữ liệu:** MySQL / MariaDB (Bộ mã `utf8mb4_unicode_ci`, quan hệ khóa ngoại RDBMS chuẩn).
- **Giao diện:** Thiết kế theo chuẩn giao diện mẫu UTC STATUS (Donut Chart SVG tỷ lệ hoạt động, 2 thẻ KPI sự cố & bảo trì, Lịch theo tháng tương tác mốc sự cố/bảo trì, Lưới 9 dịch vụ với biểu tượng trực quan).

---

## 2. TÀI KHOẢN HỆ THỐNG
| Vai trò | Tên đăng nhập | Mật khẩu mặc định | Quyền hạn |
| :--- | :--- | :--- | :--- |
| **Quản trị viên (Admin)** | `admin` | `Admin@123` | Toàn quyền: Thêm/Sửa/Xóa Dịch vụ & Nhóm, Quản lý Sự cố & Bảo trì, Health Check, Xem Nhật ký Audit |
| **Biên tập viên (Editor)**| `editor` | `Editor@123` | Thao tác vận hành: Đổi trạng thái nhanh, Cập nhật sự cố & lịch bảo trì, Chạy Health Check. *(Bị chặn xóa dịch vụ/nhóm)* |

> Đổi các mật khẩu mẫu ngay sau khi cài đặt và không dùng tài khoản mặc định trên hệ thống công khai.

---

## 3. CẤU TRÚC THƯ MỤC
```text
UTC-STATUS/
├── admin/                      # Module Quản trị viên
│   ├── index.php               # Bảng điều khiển Quản trị (Dashboard CRUD, Charts, Logs)
│   ├── login.php               # Đăng nhập bảo mật (CSRF, Anti-brute force, Audit log)
│   └── logout.php              # Đăng xuất an toàn, hủy Session
├── api/                        # RESTful API JSON Endpoints
│   ├── status.php              # Trạng thái tổng quan, dịch vụ & mốc sự kiện lịch tháng
│   ├── incidents.php           # Danh sách sự cố & các bước điều tra theo mốc thời gian
│   ├── maintenances.php        # Kế hoạch bảo trì tương lai & tiến độ
│   ├── uptime.php              # Tỉ lệ Uptime & lịch sử 90 ngày cho từng dịch vụ
│   ├── health_check.php        # Bộ quét tự động kiểm tra HTTP/Latency cho các dịch vụ
│   └── subscribe.php           # Đăng ký nhận thông báo có xác thực email an toàn
├── assets/
│   ├── css/style.css           # Bảng phong cách CSS giao diện chuẩn UTC STATUS
│   └── js/app.js               # Logic Client: Polling 30s, SVG Donut, Lịch tháng, Modals
├── config/
│   └── database.php            # Kết nối PDO Singleton (UTF-8, Prepared Statements)
├── database/
│   ├── schema.sql              # Cấu trúc CSDL hoàn chỉnh (Version 2.0)
│   ├── update_v2.sql           # Script nâng cấp CSDL (Chạy nếu đã có CSDL cũ)
│   └── seed_utc.php            # Chỉ bổ sung dữ liệu mẫu còn thiếu, không ghi đè trạng thái hiện có
├── index.php                   # Giao diện người dùng theo dõi trạng thái hệ thống
├── verify.php                  # Trang kích hoạt xác minh Email đăng ký nhận thông báo
├── unsubscribe.php             # Trang hủy đăng ký nhận thông báo
└── README.md                   # Hướng dẫn chi tiết triển khai & sử dụng
```

---

## 4. HƯỚNG DẪN CÀI ĐẶT TRÊN XAMPP

### Bước 1: Đặt mã nguồn vào thư mục XAMPP
- Đặt project tại `C:\xampp\htdocs\UTC-STATUS` (hoặc dùng VirtualHost riêng).

### Bước 2: Khởi động dịch vụ XAMPP
- Mở **XAMPP Control Panel** và nhấn **Start** cho cả hai dịch vụ:
  - **Apache** (Cổng 80 hoặc 443)
  - **MySQL** (Cổng 3306)

### Bước 3: Nhập cơ sở dữ liệu (Import Database)
- Cách 1: Truy cập `http://localhost/phpmyadmin/`
  - Tạo mới cơ sở dữ liệu: `status_page_db` (Collation: `utf8mb4_unicode_ci`).
  - Chọn thẻ **Import** và tải lên file `database/schema.sql`.
- Cách 2: Sử dụng dòng lệnh Terminal:
  ```powershell
  & "C:\xampp\mysql\bin\mysql.exe" -u root -e "CREATE DATABASE IF NOT EXISTS status_page_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  & "C:\xampp\mysql\bin\mysql.exe" --default-character-set=utf8mb4 -u root status_page_db < "C:\xampp\htdocs\UTC-STATUS\database\schema.sql"
  ```

  Khi đã nhập SQL bằng client chưa cấu hình UTF-8 và nội dung tiếng Việt bị lỗi, có thể khôi phục các chuỗi CP850 bị ảnh hưởng mà không thay đổi trạng thái dịch vụ hoặc lịch sự cố:
  ```powershell
  & "C:\xampp\php\php.exe" "C:\xampp\htdocs\UTC-STATUS\database\repair_encoding.php" --dry-run
  & "C:\xampp\php\php.exe" "C:\xampp\htdocs\UTC-STATUS\database\repair_encoding.php"
  ```

### Bước 4: Khởi tạo dữ liệu mẫu UTC
- Chỉ chạy seeder từ PowerShell/CLI (không chạy qua trình duyệt):
  ```powershell
  & "C:\xampp\php\php.exe" "C:\xampp\htdocs\UTC-STATUS\database\seed_utc.php"
  ```
- Seeder thường chỉ bổ sung bản ghi mẫu còn thiếu, không ghi đè trạng thái dịch vụ, sự cố, bảo trì hay số liệu uptime đã có. Tùy chọn `--force` sẽ xóa dữ liệu dịch vụ, sự cố, bảo trì, báo cáo, lịch sử trạng thái và uptime trước khi tạo lại dữ liệu mẫu; chỉ dùng trên database có thể khôi phục.

### Cấu hình database và URL
- Mặc định XAMPP dùng `127.0.0.1:3306`, database `status_page_db`, user `root` không mật khẩu. Nên dùng tài khoản database riêng có quyền tối thiểu khi triển khai thật.
- Có thể cấu hình qua biến môi trường `UTC_STATUS_DB_HOST`, `UTC_STATUS_DB_PORT`, `UTC_STATUS_DB_NAME`, `UTC_STATUS_DB_USER`, `UTC_STATUS_DB_PASS`.
- Múi giờ ứng dụng/database mặc định là `Asia/Ho_Chi_Minh` (UTC+7). Nếu chạy sau reverse proxy hoặc domain khác, đặt `UTC_STATUS_BASE_URL` thành URL gốc công khai, ví dụ `https://status.example.edu.vn`.
- Khôi phục mật khẩu gửi OTP bằng PHP `mail()`; cần cấu hình mail transport trong PHP/XAMPP và đặt `UTC_STATUS_MAIL_FROM` thành địa chỉ gửi hợp lệ. OTP chỉ được lưu dạng băm, không hiển thị trên trang.

---

## 5. ĐƯỜNG DẪN TRUY CẬP HỆ THỐNG
1. **Trang trạng thái (yêu cầu đăng nhập):**
   - URL: [http://localhost/UTC-STATUS/index.php](http://localhost/UTC-STATUS/index.php)
2. **Trang đăng nhập Quản trị (Admin Panel):**
   - URL: [http://localhost/UTC-STATUS/admin/login.php](http://localhost/UTC-STATUS/admin/login.php)
3. **API JSON Endpoints:**
   - Tổng quan & Dịch vụ: `http://localhost/UTC-STATUS/api/status.php`
   - Sự cố: `http://localhost/UTC-STATUS/api/incidents.php`
   - Bảo trì: `http://localhost/UTC-STATUS/api/maintenances.php`
   - Chi tiết Uptime 90 ngày: `http://localhost/UTC-STATUS/api/uptime.php?service_id=2`
   - Health Check (đọc): `GET http://localhost/UTC-STATUS/api/health_check.php`
   - Health Check (cập nhật trạng thái): `POST` kèm session, CSRF header `X-CSRF-Token` và vai trò admin/editor.


4. **Cổng Xác Thực Quản Trị & Vận Hành (Auth System):**
   - Đăng nhập (Login): [http://localhost/UTC-STATUS/admin/login.php](http://localhost/UTC-STATUS/admin/login.php)
   - Đăng ký (Register): [http://localhost/UTC-STATUS/admin/register.php](http://localhost/UTC-STATUS/admin/register.php)
   - Quên mật khẩu OTP (Forgot Password): [http://localhost/UTC-STATUS/admin/forgot_password.php](http://localhost/UTC-STATUS/admin/forgot_password.php)
