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

---

## 3. CẤU TRÚC THƯ MỤC
```text
status_page/
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
│   ├── schema.sql              # Cấu trúc CSDL hoàn chỉnh 12 bảng (Version 2.0)
│   ├── update_v2.sql           # Script nâng cấp CSDL (Chạy nếu đã có CSDL cũ)
│   └── seed_utc.php            # Khởi tạo dữ liệu mẫu chuẩn an toàn (Idempotent Upsert)
├── index.php                   # Giao diện người dùng theo dõi trạng thái hệ thống
├── verify.php                  # Trang kích hoạt xác minh Email đăng ký nhận thông báo
├── unsubscribe.php             # Trang hủy đăng ký nhận thông báo
└── README.md                   # Hướng dẫn chi tiết triển khai & sử dụng
```

---

## 4. HƯỚNG DẪN CÀI ĐẶT TRÊN XAMPP

### Bước 1: Copy mã nguồn vào thư mục XAMPP
- Giải nén mã nguồn vào thư mục: `C:\xampp\htdocs\status_page`

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
  & "C:\xampp\mysql\bin\mysql.exe" --default-character-set=utf8mb4 -u root status_page_db < "C:\xampp\htdocs\status_page\database\schema.sql"
  ```

  Khi đã nhập SQL bằng client chưa cấu hình UTF-8 và nội dung tiếng Việt bị lỗi, có thể khôi phục các chuỗi CP850 bị ảnh hưởng mà không thay đổi trạng thái dịch vụ hoặc lịch sự cố:
  ```powershell
  & "C:\xampp\php\php.exe" "C:\xampp\htdocs\status_page\database\repair_encoding.php" --dry-run
  & "C:\xampp\php\php.exe" "C:\xampp\htdocs\status_page\database\repair_encoding.php"
  ```

### Bước 4: Khởi tạo dữ liệu mẫu UTC
- Chạy file seeder trong trình duyệt: `http://localhost/status_page/database/seed_utc.php`
- Hoặc chạy qua PowerShell:
  ```powershell
  & "C:\xampp\php\php.exe" "C:\xampp\htdocs\status_page\database\seed_utc.php"
  ```

---

## 5. ĐƯỜNG DẪN TRUY CẬP HỆ THỐNG
1. **Trang người dùng (Public Status Page):**
   - URL: [http://localhost/status_page/index.php](http://localhost/status_page/index.php)
2. **Trang đăng nhập Quản trị (Admin Panel):**
   - URL: [http://localhost/status_page/admin/login.php](http://localhost/status_page/admin/login.php)
3. **API JSON Endpoints:**
   - Tổng quan & Dịch vụ: `http://localhost/status_page/api/status.php`
   - Sự cố: `http://localhost/status_page/api/incidents.php`
   - Bảo trì: `http://localhost/status_page/api/maintenances.php`
   - Chi tiết Uptime 90 ngày: `http://localhost/status_page/api/uptime.php?service_id=2`
   - Tự động Health Check: `http://localhost/status_page/api/health_check.php`


4. **Cổng Xác Thực Quản Trị & Vận Hành (Auth System):**
   - Đăng nhập (Login): [http://localhost/status_page/admin/login.php](http://localhost/status_page/admin/login.php)
   - Đăng ký Biên tập viên (Register): [http://localhost/status_page/admin/register.php](http://localhost/status_page/admin/register.php)
   - Quên mật khẩu OTP (Forgot Password): [http://localhost/status_page/admin/forgot_password.php](http://localhost/status_page/admin/forgot_password.php)
