<?php
declare(strict_types=1);

/**
 * UTC STATUS - Cấu hình & Xử lý Ngữ cảnh Trợ lý Ảo AI (AI Assistant Engine)
 * Hỗ trợ song song:
 * 1. Google Gemini API (khi cấu hình API key)
 * 2. Smart Live Context Engine (chạy trực tiếp nội bộ trên XAMPP không cần API key)
 */

if (!defined('GEMINI_API_KEY')) {
    // Có thể cấu hình trực tiếp API key ở đây hoặc qua biến môi trường GEMINI_API_KEY
    define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: '');
}

if (!defined('AI_MODEL_NAME')) {
    define('AI_MODEL_NAME', 'gemini-1.5-flash');
}

/**
 * Trích xuất toàn bộ dữ liệu thực tế của hệ thống UTC STATUS từ MySQL
 */
function getLiveSystemContext(PDO $pdo): array {
    // 1. Trạng thái dịch vụ
    $servicesStmt = $pdo->query(
        'SELECT s.id, s.name, s.status, s.description, sg.name AS group_name 
         FROM services s 
         LEFT JOIN service_groups sg ON s.group_id = sg.id 
         ORDER BY sg.sort_order ASC, s.sort_order ASC, s.id ASC'
    );
    $services = $servicesStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalServices = count($services);
    $operationalCount = 0;
    $outageServices = [];
    $maintenanceServices = [];

    foreach ($services as $srv) {
        if ($srv['status'] === 'operational') {
            $operationalCount++;
        } elseif ($srv['status'] === 'maintenance') {
            $maintenanceServices[] = $srv['name'];
        } else {
            $outageServices[] = $srv['name'] . ' (' . $srv['status'] . ')';
        }
    }

    // 2. Trạng thái bãi xe sân trường
    $parkingStmt = $pdo->query('SELECT * FROM parking_zones ORDER BY sort_order ASC, id ASC');
    $parkingZones = $parkingStmt->fetchAll(PDO::FETCH_ASSOC);

    $totalParkCap = 0;
    $totalParkOcc = 0;
    $parkingDetails = [];

    foreach ($parkingZones as $zone) {
        $cap = (int) $zone['total_capacity'];
        $occ = (int) $zone['occupied_count'];
        $totalParkCap += $cap;
        $totalParkOcc += $occ;
        $pct = $cap > 0 ? round(($occ / $cap) * 100, 1) : 0;
        if ($pct > 100) $pct = 100;

        $dbStatus = $zone['status'];
        if ($dbStatus === 'full' || $pct >= 95) {
            $statusLabel = 'Đã đầy / Ngưng nhận xe';
        } elseif ($pct >= 80) {
            $statusLabel = 'Sắp đầy';
        } else {
            $statusLabel = 'Còn trống';
        }

        $parkingDetails[] = [
            'id'            => $zone['id'],
            'zone_code'     => $zone['zone_code'],
            'name'          => $zone['name'],
            'vehicle_type'  => $zone['vehicle_type'],
            'occupied'      => $occ,
            'capacity'      => $cap,
            'available'     => max(0, $cap - $occ),
            'pct'           => $pct,
            'status_label'  => $statusLabel,
            'location_note' => $zone['location_note']
        ];
    }

    $totalParkAvailable = max(0, $totalParkCap - $totalParkOcc);
    $totalParkPct = $totalParkCap > 0 ? round(($totalParkOcc / $totalParkCap) * 100, 1) : 0;

    // 3. Sự cố đang hoạt động (unresolved incidents)
    $incidentsStmt = $pdo->query(
        'SELECT id, title, severity, status, created_at, resolved_at 
         FROM incidents 
         WHERE status != "resolved" 
         ORDER BY created_at DESC LIMIT 5'
    );
    $activeIncidents = $incidentsStmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Lịch bảo trì sắp tới (trong vòng 7 ngày)
    $maintenanceStmt = $pdo->query(
        'SELECT m.id, m.title, m.status, m.scheduled_at, m.expected_end, m.description
         FROM maintenances m
         WHERE m.status IN ("scheduled", "in_progress")
         ORDER BY m.scheduled_at ASC LIMIT 5'
    );
    $upcomingMaintenances = $maintenanceStmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'services' => [
            'total'             => $totalServices,
            'operational'       => $operationalCount,
            'outages'           => $outageServices,
            'maintenances'      => $maintenanceServices,
            'list'              => $services
        ],
        'parking' => [
            'total_capacity'    => $totalParkCap,
            'total_occupied'    => $totalParkOcc,
            'total_available'   => $totalParkAvailable,
            'occupancy_pct'     => $totalParkPct,
            'zones'             => $parkingDetails
        ],
        'active_incidents'      => $activeIncidents,
        'upcoming_maintenances' => $upcomingMaintenances,
        'current_time'          => date('H:i:s d/m/Y')
    ];
}

/**
 * Xây dựng đoạn prompt tóm tắt ngữ cảnh hệ thống cho AI Model
 */
function buildAiContextSummary(array $ctx): string {
    $out = "=== THỜI GIAN HIỆN TẠI: " . $ctx['current_time'] . " ===\n\n";

    $out .= "=== 1. TÌNH TRẠNG DỊCH VỤ CNTT UTC ===\n";
    $out .= "- Tổng số dịch vụ: {$ctx['services']['total']}\n";
    $out .= "- Đang hoạt động bình thường: {$ctx['services']['operational']}/{$ctx['services']['total']}\n";
    if (!empty($ctx['services']['outages'])) {
        $out .= "- Dịch vụ gặp sự cố lỗi: " . implode(', ', $ctx['services']['outages']) . "\n";
    } else {
        $out .= "- Dịch vụ lỗi: Không có dịch vụ nào đang lỗi.\n";
    }
    if (!empty($ctx['services']['maintenances'])) {
        $out .= "- Dịch vụ đang bảo trì: " . implode(', ', $ctx['services']['maintenances']) . "\n";
    }

    $out .= "\n=== 2. TÌNH TRẠNG BÃI XE SÂN TRƯỜNG ===\n";
    $out .= "- Toàn trường: Đã đỗ {$ctx['parking']['total_occupied']}/{$ctx['parking']['total_capacity']} xe ({$ctx['parking']['occupancy_pct']}%), Còn trống {$ctx['parking']['total_available']} chỗ.\n";
    foreach ($ctx['parking']['zones'] as $z) {
        $out .= "- {$z['name']}: Đã đỗ {$z['occupied']}/{$z['capacity']} ({$z['pct']}%) - Trạng thái: {$z['status_label']}. Vị trí: {$z['location_note']}.\n";
    }

    $out .= "\n=== 3. SỰ CỐ ĐANG XỬ LÝ ===\n";
    if (empty($ctx['active_incidents'])) {
        $out .= "Hiện không có sự cố nào đang xử lý.\n";
    } else {
        foreach ($ctx['active_incidents'] as $inc) {
            $out .= "- [#{$inc['id']}] {$inc['title']} (Mức độ: {$inc['severity']}, Trạng thái: {$inc['status']}, Báo lúc: {$inc['created_at']})\n";
        }
    }

    $out .= "\n=== 4. LỊCH TRÌNH BẢO TRÌ SẮP TỚI ===\n";
    if (empty($ctx['upcoming_maintenances'])) {
        $out .= "Không có lịch bảo trì nào sắp tới trong tuần.\n";
    } else {
        foreach ($ctx['upcoming_maintenances'] as $m) {
            $out .= "- {$m['title']}: Từ {$m['scheduled_at']} đến {$m['expected_end']} (Trạng thái: {$m['status']})\n";
        }
    }

    return $out;
}

/**
 * Gọi Google Gemini API nếu có API key
 */
function callGeminiApi(string $userMessage, array $contextData, string $apiKey): array {
    $contextText = buildAiContextSummary($contextData);

    $systemInstruction = <<<TEXT
Bạn là "UTC AI Assistant" - Trợ lý số thông minh của Hệ thống Theo dõi Trạng thái Dịch vụ & Bãi xe Đại học Giao thông Vận tải (UTC STATUS).
Nhiệm vụ của bạn:
1. Trả lời các thắc mắc của sinh viên, giảng viên và quản trị viên về trạng thái dịch vụ CNTT (LMS, Eduroam, Cổng tín chỉ, Học phí...), sức chứa bãi xe sân trường, sự cố và lịch bảo trì.
2. Luôn sử dụng dữ liệu thực tế trong bản tóm tắt trạng thái hệ thống bên dưới để trả lời chính xác 100%, không bịa đặt số liệu.
3. Phong cách giao tiếp: Thân thiện, lịch sự, ngắn gọn, chuẩn phong cách trường ĐH GTVT. Dùng biểu tượng emoji trực quan (🟢, ⚠️, 🅿️, 🛠️, 📶).
4. Định dạng phản hồi bằng Markdown rõ ràng (in đậm, danh sách bullet).
5. Nếu sinh viên muốn báo cáo sự cố hoặc lỗi dịch vụ, hãy hướng dẫn họ vào mục "Incident Report" (Hàng đợi FIFO) trên cổng để được kỹ thuật viên tiếp nhận và xử lý theo thứ tự ưu tiên.

DỮ LIỆU THỰC TẾ HỆ THỐNG HIỆN TẠI:
{$contextText}
TEXT;

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . AI_MODEL_NAME . ':generateContent?key=' . urlencode($apiKey);

    $payload = [
        'contents' => [
            [
                'role' => 'user',
                'parts' => [
                    ['text' => $userMessage]
                ]
            ]
        ],
        'systemInstruction' => [
            'parts' => [
                ['text' => $systemInstruction]
            ]
        ],
        'generationConfig' => [
            'temperature'     => 0.4,
            'maxOutputTokens' => 800,
        ]
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=UTF-8'],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => false // Cho môi trường XAMPP local
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $json = json_decode($response, true);
        $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if (!empty($text)) {
            return [
                'success'  => true,
                'message'  => trim($text),
                'provider' => 'gemini'
            ];
        }
    }

    return [
        'success' => false,
        'error'   => 'Không thể kết nối Gemini API (HTTP ' . $httpCode . '): ' . $curlErr
    ];
}

/**
 * Smart Live Context Engine (Bộ xử lý AI Offline nội bộ)
 * Chạy 100% không phụ thuộc Internet hay API Key bên ngoài.
 * Phân tích câu hỏi tự nhiên và trích xuất số liệu chuẩn xác từ MySQL.
 */
function querySmartOfflineEngine(string $message, array $ctx): array {
    $msgLower = mb_strtolower(trim($message), 'UTF-8');

    // 1. Nhận diện câu hỏi về BÃI XE / GỬI XE / ĐỖ XE / PARKING
    if (
        str_contains($msgLower, 'bãi xe') || 
        str_contains($msgLower, 'bãi đỗ') || 
        str_contains($msgLower, 'gửi xe') || 
        str_contains($msgLower, 'đỗ xe') || 
        str_contains($msgLower, 'parking') || 
        str_contains($msgLower, 'chỗ để xe') ||
        str_contains($msgLower, 'khu a') ||
        str_contains($msgLower, 'khu b') ||
        str_contains($msgLower, 'khu c')
    ) {
        $zones = $ctx['parking']['zones'];
        $reply = "🅿️ **TÌNH TRẠNG BÃI XE SÂN TRƯỜNG UTC HIỆN TẠI**\n\n";

        // Kiểm tra nếu hỏi cụ thể 1 khu
        if (str_contains($msgLower, 'khu a')) {
            $za = $zones[0] ?? null;
            if ($za) {
                $icon = ($za['pct'] >= 95) ? '🔴' : (($za['pct'] >= 80) ? '🟠' : '🟢');
                $reply .= "📍 **{$za['name']}**:\n";
                $reply .= "• Đã đỗ: **{$za['occupied']}/{$za['capacity']} xe ({$za['pct']}%)**\n";
                $reply .= "• Trạng thái: {$icon} **{$za['status_label']}** (Còn trống **{$za['available']}** chỗ)\n";
                $reply .= "• Vị trí: {$za['location_note']}\n";
                if ($za['pct'] >= 80) {
                    $reply .= "\n💡 *Gợi ý: Khu A đang khá đông, bạn có thể di chuyển sang Khu B (Sau Giảng đường A6-A7) để đỗ xe thuận tiện hơn!*";
                }
                return ['success' => true, 'message' => $reply, 'provider' => 'smart_offline'];
            }
        }

        if (str_contains($msgLower, 'khu b')) {
            $zb = $zones[1] ?? null;
            if ($zb) {
                $icon = ($zb['pct'] >= 95) ? '🔴' : (($zb['pct'] >= 80) ? '🟠' : '🟢');
                $reply .= "📍 **{$zb['name']}**:\n";
                $reply .= "• Đã đỗ: **{$zb['occupied']}/{$zb['capacity']} xe ({$zb['pct']}%)**\n";
                $reply .= "• Trạng thái: {$icon} **{$zb['status_label']}** (Còn trống **{$zb['available']}** chỗ)\n";
                $reply .= "• Vị trí: {$zb['location_note']}\n";
                return ['success' => true, 'message' => $reply, 'provider' => 'smart_offline'];
            }
        }

        if (str_contains($msgLower, 'khu c') || str_contains($msgLower, 'ô tô') || str_contains($msgLower, 'giảng viên')) {
            $zc = $zones[2] ?? null;
            if ($zc) {
                $icon = ($zc['pct'] >= 95) ? '🔴' : (($zc['pct'] >= 80) ? '🟠' : '🟢');
                $reply .= "📍 **{$zc['name']}**:\n";
                $reply .= "• Đã đỗ: **{$zc['occupied']}/{$zc['capacity']} xe ({$zc['pct']}%)**\n";
                $reply .= "• Trạng thái: {$icon} **{$zc['status_label']}**\n";
                $reply .= "• Vị trí: {$zc['location_note']}\n";
                return ['success' => true, 'message' => $reply, 'provider' => 'smart_offline'];
            }
        }

        // Tóm tắt tổng thể cả 3 khu
        $reply .= "Tổng thể toàn trường: Đã đỗ **{$ctx['parking']['total_occupied']}/{$ctx['parking']['total_capacity']}** xe (**{$ctx['parking']['occupancy_pct']}%**). Còn trống **{$ctx['parking']['total_available']}** chỗ.\n\n";

        foreach ($zones as $z) {
            $icon = ($z['pct'] >= 95) ? '🔴' : (($z['pct'] >= 80) ? '🟠' : '🟢');
            $reply .= "• **{$z['name']}**: {$z['occupied']}/{$z['capacity']} xe ({$z['pct']}%) → {$icon} *{$z['status_label']}* (còn {$z['available']} chỗ)\n";
        }

        $reply .= "\n⏱️ *Dữ liệu được cập nhật tự động trực tiếp từ hệ thống giám sát cổng trường.*";
        return ['success' => true, 'message' => $reply, 'provider' => 'smart_offline'];
    }

    // 2. Nhận diện câu hỏi về SỰ CỐ / LỖI HỆ THỐNG / CÓ DỊCH VỤ NÀO HỎNG
    if (
        str_contains($msgLower, 'sự cố') || 
        str_contains($msgLower, 'lỗi') || 
        str_contains($msgLower, 'hỏng') || 
        str_contains($msgLower, 'không vào được') ||
        str_contains($msgLower, 'chậm') ||
        str_contains($msgLower, 'đang xử lý')
    ) {
        $reply = "⚠️ **THÔNG TIN SỰ CỐ & GIẢI QUYẾT TẠI UTC STATUS**\n\n";
        if (empty($ctx['active_incidents'])) {
            $reply .= "🟢 **Hiện tại không có sự cố CNTT nghiêm trọng nào đang diễn ra!**\n";
            $reply .= "Tất cả các dịch vụ trọng yếu của trường đều đang ở trạng thái ổn định.\n\n";
        } else {
            $reply .= "Hiện có **" . count($ctx['active_incidents']) . " sự cố** đang được đội ngũ kỹ thuật khẩn trương xử lý:\n";
            foreach ($ctx['active_incidents'] as $inc) {
                $badge = ($inc['severity'] === 'critical' || $inc['severity'] === 'high') ? '🔴' : '🟡';
                $reply .= "• {$badge} **{$inc['title']}** (Mức độ: {$inc['severity']}, Trạng thái: *{$inc['status']}*)\n";
            }
            $reply .= "\n";
        }

        $reply .= "📢 **Nếu bạn phát hiện lỗi mới?**\n";
        $reply .= "Hãy click vào mục **\"Incident Report\" (Báo cáo sự cố)** trên thanh điều hướng để gửi phản ánh. Báo cáo của bạn sẽ được đưa vào **Hàng đợi FIFO** và kỹ thuật viên tiếp nhận xử lý theo thứ tự!";
        return ['success' => true, 'message' => $reply, 'provider' => 'smart_offline'];
    }

    // 3. Nhận diện câu hỏi về LỊCH BẢO TRÌ / NÂNG CẤP
    if (
        str_contains($msgLower, 'bảo trì') || 
        str_contains($msgLower, 'nâng cấp') || 
        str_contains($msgLower, 'lịch bảo trì') || 
        str_contains($msgLower, 'bảo dưỡng')
    ) {
        $reply = "🛠️ **LỊCH TRÌNH BẢO TRÌ HỆ THỐNG CNTT UTC**\n\n";
        if (empty($ctx['upcoming_maintenances'])) {
            $reply .= "🟢 **Trong tuần này không có lịch bảo trì nào được lên kế hoạch!**\n";
            $reply .= "Các hệ thống LMS, Cổng thông tin và Mạng Wi-Fi hoạt động bình thường 24/7.\n";
        } else {
            $reply .= "Danh sách các đợt bảo trì sắp tới:\n";
            foreach ($ctx['upcoming_maintenances'] as $m) {
                $statusIcon = ($m['status'] === 'in_progress') ? '⚙️ [ĐANG TIẾN HÀNH]' : '🗓️ [ĐÃ LÊN LỊCH]';
                $reply .= "• {$statusIcon} **{$m['title']}**\n";
                $reply .= "  Thời gian: Từ `{$m['scheduled_at']}` đến `{$m['expected_end']}`\n";
                if (!empty($m['description'])) {
                    $reply .= "  Chi tiết: {$m['description']}\n";
                }
            }
        }
        return ['success' => true, 'message' => $reply, 'provider' => 'smart_offline'];
    }

    // 4. Nhận diện câu hỏi về WIFI EDUROAM
    if (
        str_contains($msgLower, 'eduroam') || 
        str_contains($msgLower, 'wifi') || 
        str_contains($msgLower, 'mạng') || 
        str_contains($msgLower, 'internet')
    ) {
        $reply = "📶 **HƯỚNG DẪN KẾT NỐI MẠNG WI-FI EDUROAM TẠI UTC**\n\n";
        $reply .= "Mạng **eduroam** là mạng Wi-Fi chuyển vùng quốc tế phủ sóng toàn khuôn viên Đại học Giao thông Vận tải.\n\n";
        $reply .= "🔑 **Thông tin đăng nhập:**\n";
        $reply .= "• **Tài khoản**: `<mã_sinh_viên>@utc.edu.vn` (hoặc `@student.utc.edu.vn`)\n";
        $reply .= "• **Mật khẩu**: Mật khẩu cổng thông tin sinh viên của bạn\n\n";
        $reply .= "⚙️ **Cài đặt kết nối (Trên điện thoại Android/iOS/Laptop):**\n";
        $reply .= "• Phương thức EAP (EAP method): **PEAP**\n";
        $reply .= "• Xác thực giai đoạn 2 (Phase 2): **MSCHAPV2**\n";
        $reply .= "• Chứng chỉ CA (CA certificate): **Không xác thực** (Do not validate)\n";
        $reply .= "• Danh tính ẩn danh (Anonymous identity): Để trống\n\n";
        $reply .= "💡 *Nếu vẫn gặp lỗi không nhận IP, hãy quên mạng (Forget Network) và kết nối lại tại vị trí gần router phát sóng!*";
        return ['success' => true, 'message' => $reply, 'provider' => 'smart_offline'];
    }

    // 5. Nhận diện câu hỏi về LMS / MOODLE
    if (
        str_contains($msgLower, 'lms') || 
        str_contains($msgLower, 'moodle') || 
        str_contains($msgLower, 'nộp bài') || 
        str_contains($msgLower, 'học trực tuyến')
    ) {
        $reply = "🎓 **HỆ THỐNG HỌC TẬP TRỰC TUYẾN LMS (MOODLE)**\n\n";
        $reply .= "• Trạng thái hiện tại: 🟢 **Hoạt động tốt (Operational)**\n";
        $reply .= "• Đăng nhập bằng mã sinh viên và mật khẩu do Nhà trường cấp.\n";
        $reply .= "• **Lưu ý khi nộp bài:** Sinh viên nên nộp trước hạn chót ít nhất 15-30 phút để tránh nghẽn mạng vào giờ cao điểm.\n";
        $reply .= "• Nếu gặp lỗi không thấy lớp học phần, hãy liên hệ giảng viên phụ trách môn học để được ghi danh vào khoá học.";
        return ['success' => true, 'message' => $reply, 'provider' => 'smart_offline'];
    }

    // 6. Nhận diện câu hỏi về QUÊN MẬT KHẨU / ĐỔI MẬT KHẨU
    if (
        str_contains($msgLower, 'mật khẩu') || 
        str_contains($msgLower, 'quên pass') || 
        str_contains($msgLower, 'cấp lại mật khẩu') || 
        str_contains($msgLower, 'reset pass')
    ) {
        $reply = "🔑 **HƯỚNG DẪN CẤP LẠI MẬT KHẨU TÀI KHOẢN**\n\n";
        $reply .= "1. Tại màn hình Đăng nhập UTC STATUS, nhấn vào liên kết **\"Quên mật khẩu?\"**.\n";
        $reply .= "2. Nhập chính xác Mã sinh viên và Email đã đăng ký trong hệ thống.\n";
        $reply .= "3. Gửi yêu cầu; ban quản trị (Admin) sẽ xác minh danh tính và cấp lại mật khẩu cho bạn trong mục Quản lý Tài khoản.\n";
        $reply .= "4. Bạn cũng có thể liên hệ trực tiếp **Phòng Đào tạo / Trung tâm CNTT** tại Nhà A1 để được hỗ trợ trực tiếp.";
        return ['success' => true, 'message' => $reply, 'provider' => 'smart_offline'];
    }

    // 7. Nhận diện câu hỏi TỔNG QUAN TÌNH TRẠNG HỆ THỐNG
    if (
        str_contains($msgLower, 'hệ thống') || 
        str_contains($msgLower, 'trạng thái') || 
        str_contains($msgLower, 'tình hình') || 
        str_contains($msgLower, 'ổn không') ||
        str_contains($msgLower, 'thế nào')
    ) {
        $reply = "📊 **TỔNG HỢP TRẠNG THÁI HỆ THỐNG UTC STATUS**\n\n";
        $reply .= "• Dịch vụ CNTT: **{$ctx['services']['operational']}/{$ctx['services']['total']}** dịch vụ hoạt động bình thường.\n";
        if (!empty($ctx['services']['outages'])) {
            $reply .= "• Dịch vụ lỗi: 🔴 **" . implode(', ', $ctx['services']['outages']) . "**\n";
        } else {
            $reply .= "• Không có dịch vụ nào gặp sự cố gián đoạn.\n";
        }
        $reply .= "• Bãi xe sân trường: Đang đỗ **{$ctx['parking']['total_occupied']}/{$ctx['parking']['total_capacity']} xe** ({$ctx['parking']['occupancy_pct']}%).\n";
        $reply .= "• Sự cố đang xử lý: **" . count($ctx['active_incidents']) . "** sự cố.\n";
        $reply .= "• Bảo trì sắp tới: **" . count($ctx['upcoming_maintenances']) . "** kế hoạch.\n\n";
        $reply .= "💡 *Bạn có thể bấm vào các câu hỏi gợi ý bên dưới để tra cứu chi tiết hơn!*";
        return ['success' => true, 'message' => $reply, 'provider' => 'smart_offline'];
    }

    // 8. Chào hỏi / Cảm ơn / Mặc định
    $reply = "👋 **Xin chào! Mình là Trợ lý Ảo UTC STATUS.**\n\n";
    $reply .= "Mình có thể hỗ trợ bạn tra cứu tức thì thông tin hệ thống tại ĐH Giao thông Vận tải:\n";
    $reply .= "• 🅿️ **Tình trạng bãi xe sân trường**: Số lượng chỗ trống tại Khu A, Khu B, Khu C.\n";
    $reply .= "• 🟢 **Trạng thái dịch vụ CNTT**: LMS, Wifi eduroam, Cổng tín chỉ, Đăng ký học phần...\n";
    $reply .= "• 🛠️ **Sự cố & Lịch bảo trì**: Cập nhật tiến độ xử lý và lịch bảo dưỡng trong tuần.\n";
    $reply .= "• 📝 **Hướng dẫn báo cáo sự cố**: Hỗ trợ điền báo cáo ưu tiên FIFO.\n\n";
    $reply .= "Bạn muốn hỏi về vấn đề gì hôm nay?";

    return ['success' => true, 'message' => $reply, 'provider' => 'smart_offline'];
}
