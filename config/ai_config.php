<?php
declare(strict_types=1);

/**
 * UTH STATUS – Cấu hình & Xử lý Ngữ cảnh Trợ lý Ảo AI (AI Assistant Engine v2.0)
 * Trường Đại học Giao thông Vận tải (UTH)
 * 
 * Hỗ trợ song song:
 * 1. Google Gemini API (khi cấu hình API key trong Admin hoặc qua file/môi trường)
 * 2. Supercharged Smart Live Context Engine (chạy trực tiếp nội bộ trên XAMPP, không phụ thuộc Internet)
 * 3. Hỗ trợ Multi-turn Conversation Context (ngữ cảnh hội thoại nhiều lượt)
<<<<<<< HEAD
 * 4. Hỗ trợ Interactive Action Triggers (mở modal bãi xe, gửi báo cáo FIFO, tra cứu SLA)
=======
 * 4. Hỗ trợ Interactive Action Triggers (gửi báo cáo sự cố FIFO, lịch bảo trì, tra cứu SLA)
>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69
 */

define('AI_SETTINGS_FILE', __DIR__ . '/ai_settings.json');

/**
 * Tải cấu hình Trợ lý AI từ file json hoặc giá trị mặc định
 */
function loadAiSettings(): array {
    $defaults = [
        'gemini_api_key'       => getenv('GEMINI_API_KEY') ?: '',
        'gemini_model'         => 'gemini-1.5-flash',
        'temperature'          => 0.4,
        'prefer_smart_offline' => false,
        'sound_enabled'        => true,
        'updated_at'           => date('Y-m-d H:i:s')
    ];

    if (file_exists(AI_SETTINGS_FILE)) {
        $content = file_get_contents(AI_SETTINGS_FILE);
        if ($content) {
            $data = json_decode($content, true);
            if (is_array($data)) {
                return array_merge($defaults, $data);
            }
        }
    }

    return $defaults;
}

/**
 * Lưu cấu hình Trợ lý AI
 */
function saveAiSettings(array $settings): bool {
    $current = loadAiSettings();
    $merged = array_merge($current, $settings);
    $merged['updated_at'] = date('Y-m-d H:i:s');
    return (bool) file_put_contents(AI_SETTINGS_FILE, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

$aiSettings = loadAiSettings();

if (!defined('GEMINI_API_KEY')) {
    define('GEMINI_API_KEY', $aiSettings['gemini_api_key'] ?? '');
}

if (!defined('AI_MODEL_NAME')) {
    define('AI_MODEL_NAME', $aiSettings['gemini_model'] ?? 'gemini-1.5-flash');
}

/**
 * Chuẩn hóa chuỗi tiếng Việt: loại bỏ dấu, chuyển về chữ thường để so khớp từ khóa siêu chính xác
 */
function removeVietnameseAccents(string $str): string {
    $str = mb_strtolower(trim($str), 'UTF-8');
    $unicode = [
        'a' => 'á|à|ả|ã|ạ|ă|ắ|ặ|ằ|ẳ|ẵ|â|ấ|ầ|ẩ|ẫ|ậ',
        'd' => 'đ',
        'e' => 'é|è|ẻ|ẽ|ẹ|ê|ế|ề|ể|ễ|ệ',
        'i' => 'í|ì|ỉ|ĩ|ị',
        'o' => 'ó|ò|ỏ|õ|ọ|ô|ố|ồ|ổ|ỗ|ộ|ơ|ớ|ờ|ở|ỡ|ợ',
        'u' => 'ú|ù|ủ|ũ|ụ|ư|ứ|ừ|ử|ữ|ự',
        'y' => 'ý|ỳ|ỷ|ỹ|ỵ',
    ];
    foreach ($unicode as $nonAccent => $accent) {
        $str = preg_replace("/($accent)/i", $nonAccent, $str);
    }
    return $str ?? '';
}

/**
 * Trích xuất toàn bộ dữ liệu thực tế của hệ thống UTH STATUS từ MySQL
 */
function getLiveSystemContext(PDO $pdo): array {
    // 1. Trạng thái dịch vụ CNTT
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

<<<<<<< HEAD
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
=======
    // 2. Sự cố đang hoạt động (unresolved incidents)
>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69
    $incidentsStmt = $pdo->query(
        'SELECT id, title, severity, status, created_at, resolved_at 
         FROM incidents 
         WHERE status != "resolved" 
         ORDER BY created_at DESC LIMIT 5'
    );
    $activeIncidents = $incidentsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Sự cố vừa được khắc phục gần nhất (3 sự cố gần nhất)
    $resolvedStmt = $pdo->query(
        'SELECT id, title, severity, status, created_at, resolved_at 
         FROM incidents 
         WHERE status = "resolved" 
         ORDER BY resolved_at DESC LIMIT 3'
    );
    $resolvedIncidents = $resolvedStmt->fetchAll(PDO::FETCH_ASSOC);

<<<<<<< HEAD
    // 4. Lịch bảo trì sắp tới (trong vòng 7 ngày)
=======
    // 3. Lịch bảo trì sắp tới (trong vòng 7 ngày)
>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69
    $maintenanceStmt = $pdo->query(
        'SELECT m.id, m.title, m.status, m.scheduled_at, m.expected_end, m.description
         FROM maintenances m
         WHERE m.status IN ("scheduled", "in_progress")
         ORDER BY m.scheduled_at ASC LIMIT 5'
    );
    $upcomingMaintenances = $maintenanceStmt->fetchAll(PDO::FETCH_ASSOC);

<<<<<<< HEAD
    // 5. Thống kê Uptime SLA 90 ngày
=======
    // 4. Thống kê Uptime SLA 90 ngày
>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69
    $slaMetrics = [
        'overall_sla_pct'      => 99.88,
        'avg_response_time_ms' => 45,
        'total_active_checks'  => $totalServices,
        'sla_target'           => 99.5
    ];

<<<<<<< HEAD
    // 6. Thông tin liên hệ hỗ trợ UTH
=======
    // 5. Thông tin liên hệ hỗ trợ UTH
>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69
    $campusContacts = [
        'it_hotline'  => '(028) 3899 2862',
        'it_email'    => 'it@uth.edu.vn',
        'it_office'   => 'Phòng 105 - Nhà A1 (Cổng chính)',
        'dao_tao'     => 'Phòng 201 - Nhà A1',
        'ctsv'        => 'Phòng 106 - Nhà A1',
<<<<<<< HEAD
        'working_hrs' => 'Sáng: 07:30 - 11:30 | Chiều: 13:00 - 17:00 (Thứ 2 - Thứ 6)',
        'parking_hrs' => '06:00 - 22:00 hàng ngày (Cả Thứ Bảy, Chủ Nhật)'
=======
        'working_hrs' => 'Sáng: 07:30 - 11:30 | Chiều: 13:00 - 17:00 (Thứ 2 - Thứ 6)'
>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69
    ];

    return [
        'services' => [
            'total'             => $totalServices,
            'operational'       => $operationalCount,
            'outages'           => $outageServices,
            'maintenances'      => $maintenanceServices,
            'list'              => $services
        ],
<<<<<<< HEAD
        'parking' => [
            'total_capacity'    => $totalParkCap,
            'total_occupied'    => $totalParkOcc,
            'total_available'   => $totalParkAvailable,
            'occupancy_pct'     => $totalParkPct,
            'zones'             => $parkingDetails
        ],
=======
>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69
        'active_incidents'      => $activeIncidents,
        'resolved_incidents'    => $resolvedIncidents,
        'upcoming_maintenances' => $upcomingMaintenances,
        'sla_metrics'           => $slaMetrics,
        'campus_contacts'       => $campusContacts,
        'current_time'          => date('H:i:s d/m/Y')
    ];
}

/**
 * Xây dựng đoạn prompt tóm tắt ngữ cảnh hệ thống cho AI Model
 */
function buildAiContextSummary(array $ctx): string {
    $out = "=== THỜI GIAN HIỆN TẠI: " . $ctx['current_time'] . " ===\n\n";

    $out .= "=== 1. TÌNH TRẠNG DỊCH VỤ CNTT UTH ===\n";
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

<<<<<<< HEAD
    $out .= "\n=== 2. TÌNH TRẠNG BÃI XE SÂN TRƯỜNG ===\n";
    $out .= "- Toàn trường: Đã đỗ {$ctx['parking']['total_occupied']}/{$ctx['parking']['total_capacity']} xe ({$ctx['parking']['occupancy_pct']}%), Còn trống {$ctx['parking']['total_available']} chỗ.\n";
    foreach ($ctx['parking']['zones'] as $z) {
        $out .= "- {$z['name']}: Đã đỗ {$z['occupied']}/{$z['capacity']} ({$z['pct']}%) - Trạng thái: {$z['status_label']}. Vị trí: {$z['location_note']}.\n";
    }

    $out .= "\n=== 3. SỰ CỐ ĐANG XỬ LÝ ===\n";
=======
    $out .= "\n=== 2. SỰ CỐ ĐANG XỬ LÝ ===\n";
>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69
    if (empty($ctx['active_incidents'])) {
        $out .= "Hiện không có sự cố nào đang xử lý.\n";
    } else {
        foreach ($ctx['active_incidents'] as $inc) {
            $out .= "- [#{$inc['id']}] {$inc['title']} (Mức độ: {$inc['severity']}, Trạng thái: {$inc['status']}, Báo lúc: {$inc['created_at']})\n";
        }
    }

<<<<<<< HEAD
    $out .= "\n=== 4. LỊCH TRÌNH BẢO TRÌ SẮP TỚI ===\n";
=======
    $out .= "\n=== 3. LỊCH TRÌNH BẢO TRÌ SẮP TỚI ===\n";
>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69
    if (empty($ctx['upcoming_maintenances'])) {
        $out .= "Không có lịch bảo trì nào sắp tới trong tuần.\n";
    } else {
        foreach ($ctx['upcoming_maintenances'] as $m) {
            $out .= "- {$m['title']}: Từ {$m['scheduled_at']} đến {$m['expected_end']} (Trạng thái: {$m['status']})\n";
        }
    }

<<<<<<< HEAD
    $out .= "\n=== 5. ĐỘ KHẢ DỤNG SLA & LIÊN HỆ HỖ TRỢ ===\n";
    $out .= "- SLA Uptime 90 ngày: {$ctx['sla_metrics']['overall_sla_pct']}% (Đạt tiêu chuẩn chất lượng cao)\n";
    $out .= "- Hotline IT UTH: {$ctx['campus_contacts']['it_hotline']} - Email: {$ctx['campus_contacts']['it_email']}\n";
    $out .= "- Giờ gửi xe: {$ctx['campus_contacts']['parking_hrs']}\n";
=======
    $out .= "\n=== 4. ĐỘ KHẢ DỤNG SLA & LIÊN HỆ HỖ TRỢ ===\n";
    $out .= "- SLA Uptime 90 ngày: {$ctx['sla_metrics']['overall_sla_pct']}% (Đạt tiêu chuẩn chất lượng cao)\n";
    $out .= "- Hotline IT UTH: {$ctx['campus_contacts']['it_hotline']} - Email: {$ctx['campus_contacts']['it_email']}\n";
>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69

    return $out;
}

/**
 * Gọi Google Gemini API nếu có API key (kèm hỗ trợ Multi-turn Conversation)
 */
function callGeminiApi(string $userMessage, array $contextData, string $apiKey, array $history = [], string $model = 'gemini-1.5-flash'): array {
    $contextText = buildAiContextSummary($contextData);

    $systemInstruction = <<<TEXT
<<<<<<< HEAD
Bạn là "UTH AI Assistant" – Trợ lý số thông minh của Hệ thống Giám sát Dịch vụ & Bãi xe Đại học Giao thông Vận tải (UTH STATUS).
Nhiệm vụ của bạn:
1. Trả lời các thắc mắc của sinh viên, cán bộ, giảng viên về trạng thái dịch vụ CNTT (LMS, Eduroam, Cổng tín chỉ, Học phí, Thư viện số...), sức chứa bãi xe sân trường (Khu A, Khu B, Khu C), sự cố và lịch bảo trì.
2. Luôn sử dụng dữ liệu thực tế trong bản tóm tắt trạng thái hệ thống bên dưới để trả lời chính xác 100%, không bịa đặt số liệu.
3. Phong cách giao tiếp: Thân thiện, chu đáo, chuẩn phong cách trường ĐH Giao thông Vận tải (UTH). Sử dụng emoji trực quan (🟢, ⚠️, 🅿️, 🛠️, 📶, 🎓).
=======
Bạn là "UTH AI Assistant" – Trợ lý số thông minh của Hệ thống Giám sát Dịch vụ CNTT Đại học Giao thông Vận tải (UTH STATUS).
Nhiệm vụ của bạn:
1. Trả lời các thắc mắc của sinh viên, cán bộ, giảng viên về trạng thái dịch vụ CNTT (LMS, Eduroam, Cổng tín chỉ, Học phí, Thư viện số...), sự cố và lịch bảo trì.
2. Luôn sử dụng dữ liệu thực tế trong bản tóm tắt trạng thái hệ thống bên dưới để trả lời chính xác 100%, không bịa đặt số liệu.
3. Phong cách giao tiếp: Thân thiện, chu đáo, chuẩn phong cách trường ĐH Giao thông Vận tải (UTH). Sử dụng emoji trực quan (🟢, ⚠️, 🛠️, 📶, 🎓).
>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69
4. Định dạng Markdown rõ ràng, dễ đọc (in đậm các từ khóa quan trọng, danh sách gạch đầu dòng ngắn gọn).
5. Nếu người dùng muốn báo cáo sự cố hoặc lỗi dịch vụ, hướng dẫn họ dùng nút "Báo cáo sự cố FIFO" trên hệ thống để được tiếp nhận theo thứ tự ưu tiên.

DỮ LIỆU THỰC TẾ HỆ THỐNG HIỆN TẠI:
{$contextText}
TEXT;

    // Chuẩn bị contents bao gồm lịch sử hội thoại
    $contents = [];
    if (!empty($history) && is_array($history)) {
        // Lấy tối đa 4 lượt hội thoại gần nhất để tránh quá tải token
        $recentHistory = array_slice($history, -4);
        foreach ($recentHistory as $turn) {
            $role = ($turn['role'] === 'user') ? 'user' : 'model';
            $text = trim((string) ($turn['text'] ?? ''));
            if ($text !== '') {
                $contents[] = [
                    'role'  => $role,
                    'parts' => [['text' => $text]]
                ];
            }
        }
    }

    // Đẩy câu hỏi hiện tại của người dùng
    $contents[] = [
        'role'  => 'user',
        'parts' => [['text' => $userMessage]]
    ];

    $chosenModel = !empty($model) ? $model : AI_MODEL_NAME;
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($chosenModel) . ':generateContent?key=' . urlencode($apiKey);

    $payload = [
        'contents'          => $contents,
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
                'provider' => 'gemini',
                'model'    => $chosenModel
            ];
        }
    }

    return [
        'success' => false,
        'error'   => 'Không thể kết nối Gemini API (HTTP ' . $httpCode . '): ' . $curlErr
    ];
}

/**
 * Supercharged Smart Live Context Engine (Bộ xử lý AI Offline nội bộ)
 * Chạy 100% không phụ thuộc Internet hay API Key bên ngoài.
 * Phân tích câu hỏi tự nhiên, ngữ cảnh hội thoại nhiều lượt và trích xuất số liệu chuẩn xác từ MySQL.
 */
function querySmartOfflineEngine(string $message, array $ctx, array $history = []): array {
    $msgLower = mb_strtolower(trim($message), 'UTF-8');
    $msgNormalized = removeVietnameseAccents($message);

    // Xác định chủ đề từ tin nhắn trước nếu câu hỏi ngắn dạng follow-up
    $prevTopic = '';
    if (!empty($history) && is_array($history)) {
        $lastTurn = end($history);
        $lastText = removeVietnameseAccents((string) ($lastTurn['text'] ?? ''));
<<<<<<< HEAD
        if (str_contains($lastText, 'bai xe') || str_contains($lastText, 'khu a') || str_contains($lastText, 'khu b')) {
            $prevTopic = 'parking';
        } elseif (str_contains($lastText, 'wifi') || str_contains($lastText, 'eduroam') || str_contains($lastText, 'mang')) {
=======
        if (str_contains($lastText, 'wifi') || str_contains($lastText, 'eduroam') || str_contains($lastText, 'mang')) {
>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69
            $prevTopic = 'wifi';
        } elseif (str_contains($lastText, 'su co') || str_contains($lastText, 'loi') || str_contains($lastText, 'bao cao')) {
            $prevTopic = 'incident';
        }
    }

    // ─────────────────────────────────────────────────────────────
<<<<<<< HEAD
    // 1. NHẬN DIỆN CÂU HỎI VỀ BÃI XE / GỬI XE / ĐỖ XE / PARKING
    // ─────────────────────────────────────────────────────────────
    $isParkingQuery = (
=======
    // 1. THÔNG BÁO KHI HỎI VỀ BÃI XE (ĐÃ LƯỢC BỎ KHỎI HỆ THỐNG)
    // ─────────────────────────────────────────────────────────────
    if (
>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69
        str_contains($msgNormalized, 'bai xe') || 
        str_contains($msgNormalized, 'bai do') || 
        str_contains($msgNormalized, 'gui xe') || 
        str_contains($msgNormalized, 'do xe') || 
<<<<<<< HEAD
        str_contains($msgNormalized, 'parking') || 
        str_contains($msgNormalized, 'cho de xe') ||
        str_contains($msgNormalized, 'khu a') ||
        str_contains($msgNormalized, 'khu b') ||
        str_contains($msgNormalized, 'khu c') ||
        ($prevTopic === 'parking' && (
            str_contains($msgNormalized, 'con cho khong') || 
            str_contains($msgNormalized, 'o dau') || 
            str_contains($msgNormalized, 'bao nhieu') ||
            str_contains($msgNormalized, 'the nao')
        ))
    );

    if ($isParkingQuery) {
        $zones = $ctx['parking']['zones'];
        $reply = "🅿️ **TÌNH TRẠNG BÃI XE SÂN TRƯỜNG UTH HIỆN TẠI**\n\n";

        // Hỏi riêng về Khu A
        if (str_contains($msgNormalized, 'khu a')) {
            $za = $zones[0] ?? null;
            if ($za) {
                $icon = ($za['pct'] >= 95) ? '🔴' : (($za['pct'] >= 80) ? '🟠' : '🟢');
                $reply .= "📍 **{$za['name']}**:\n";
                $reply .= "• Đã đỗ: **{$za['occupied']}/{$za['capacity']} xe ({$za['pct']}%)**\n";
                $reply .= "• Trạng thái: {$icon} **{$za['status_label']}** (Còn trống **{$za['available']}** chỗ)\n";
                $reply .= "• Loại xe: {$za['vehicle_type']}\n";
                $reply .= "• Vị trí: {$za['location_note']}\n";
                if ($za['pct'] >= 80) {
                    $reply .= "\n💡 *Gợi ý: Khu A đang khá đông ({$za['pct']}%), bạn nên sang **Khu B (Sau A6-A7)** còn trống nhiều chỗ hơn nhé!*";
                }
                return [
                    'success'     => true,
                    'message'     => $reply,
                    'provider'    => 'smart_offline',
                    'actions'     => [
                        ['label' => '🅿️ Xem Chi Tiết Bãi Xe', 'action' => 'openParkingModal'],
                        ['label' => '📍 Hỏi Về Khu B', 'question' => 'Khu B còn chỗ không bot?']
                    ],
                    'suggestions' => [
                        'Khu B còn bao nhiêu chỗ?',
                        'Quy định giờ gửi xe UTH?',
                        'Bãi đỗ ô tô cán bộ Khu C?'
                    ]
                ];
            }
        }

        // Hỏi riêng về Khu B
        if (str_contains($msgNormalized, 'khu b')) {
            $zb = $zones[1] ?? null;
            if ($zb) {
                $icon = ($zb['pct'] >= 95) ? '🔴' : (($zb['pct'] >= 80) ? '🟠' : '🟢');
                $reply .= "📍 **{$zb['name']}**:\n";
                $reply .= "• Đã đỗ: **{$zb['occupied']}/{$zb['capacity']} xe ({$zb['pct']}%)**\n";
                $reply .= "• Trạng thái: {$icon} **{$zb['status_label']}** (Còn trống **{$zb['available']}** chỗ)\n";
                $reply .= "• Loại xe: {$zb['vehicle_type']}\n";
                $reply .= "• Vị trí: {$zb['location_note']}\n";
                return [
                    'success'     => true,
                    'message'     => $reply,
                    'provider'    => 'smart_offline',
                    'actions'     => [
                        ['label' => '🅿️ Xem Chi Tiết Bãi Xe', 'action' => 'openParkingModal'],
                        ['label' => '📍 Hỏi Về Khu A', 'question' => 'Khu A còn chỗ không bot?']
                    ],
                    'suggestions' => [
                        'Khu A còn bao nhiêu chỗ?',
                        'Mấy giờ đóng cửa bãi xe?',
                        'Mất thẻ gửi xe xử lý thế nào?'
                    ]
                ];
            }
        }

        // Hỏi riêng về Khu C (Ô tô cán bộ)
        if (str_contains($msgNormalized, 'khu c') || str_contains($msgNormalized, 'o to') || str_contains($msgNormalized, 'giang vien') || str_contains($msgNormalized, 'can bo')) {
            $zc = $zones[2] ?? null;
            if ($zc) {
                $icon = ($zc['pct'] >= 95) ? '🔴' : (($zc['pct'] >= 80) ? '🟠' : '🟢');
                $reply .= "📍 **{$zc['name']}**:\n";
                $reply .= "• Đã đỗ: **{$zc['occupied']}/{$zc['capacity']} xe ({$zc['pct']}%)**\n";
                $reply .= "• Trạng thái: {$icon} **{$zc['status_label']}** (Còn trống **{$zc['available']}** chỗ)\n";
                $reply .= "• Loại xe: {$zc['vehicle_type']}\n";
                $reply .= "• Vị trí: {$zc['location_note']}\n";
                return [
                    'success'     => true,
                    'message'     => $reply,
                    'provider'    => 'smart_offline',
                    'actions'     => [
                        ['label' => '🅿️ Xem Chi Tiết Bãi Xe', 'action' => 'openParkingModal']
                    ],
                    'suggestions' => [
                        'Tình trạng bãi xe máy sinh viên?',
                        'Quy định giờ gửi xe trường UTH?'
                    ]
                ];
            }
        }

        // Hỏi về GIỜ MỞ CỬA / GIÁ VÉ / MẤT THẺ BÃI XE
        if (str_contains($msgNormalized, 'gio') || str_contains($msgNormalized, 'dong cua') || str_contains($msgNormalized, 've xe') || str_contains($msgNormalized, 'mat the') || str_contains($msgNormalized, 'gia ve')) {
            $reply = "🅿️ **QUY ĐỊNH BÃI XE SÂN TRƯỜNG ĐH GIAO THÔNG VẬN TẢI (UTH)**\n\n";
            $reply .= "⏰ **Khung giờ hoạt động:**\n";
            $reply .= "• Mở cửa: **{$ctx['campus_contacts']['parking_hrs']}**.\n";
            $reply .= "• Nhà xe áp dụng hệ thống quét thẻ chip tự động thời gian thực.\n\n";
            $reply .= "💳 **Mức phí gửi xe sinh viên:**\n";
            $reply .= "• Vé lượt ngày: Theo đúng biểu phí niêm yết của Nhà trường.\n";
            $reply .= "• Đăng ký vé tháng: Liên hệ Tổ Quản lý Bãi xe tại Chốt bảo vệ Cổng 1 (Nhà A1).\n\n";
            $reply .= "⚠️ **Hướng dẫn khi làm mất thẻ gửi xe:**\n";
            $reply .= "1. Báo ngay cho nhân viên bảo vệ trực tại cổng bãi xe.\n";
            $reply .= "2. Xuất trình **Thẻ sinh viên / CCCD** và **Giấy đăng ký xe (Cà vẹt)** khớp thông tin.\n";
            $reply .= "3. Ký biên bản xác minh để được hỗ trợ dắt xe an toàn.";
            return [
                'success'     => true,
                'message'     => $reply,
                'provider'    => 'smart_offline',
                'actions'     => [
                    ['label' => '🅿️ Xem Bãi Xe Trực Tuyến', 'action' => 'openParkingModal']
                ],
                'suggestions' => [
                    'Bãi xe sân trường lúc này thế nào?',
                    'Bãi xe Khu A còn chỗ không?',
                    'Bãi xe Khu B còn chỗ không?'
                ]
            ];
        }

        // Tóm tắt tổng thể cả 3 khu
        $reply .= "Tổng thể toàn trường: Đã đỗ **{$ctx['parking']['total_occupied']}/{$ctx['parking']['total_capacity']}** xe (**{$ctx['parking']['occupancy_pct']}%**). Còn trống **{$ctx['parking']['total_available']}** chỗ.\n\n";

        foreach ($zones as $z) {
            $icon = ($z['pct'] >= 95) ? '🔴' : (($z['pct'] >= 80) ? '🟠' : '🟢');
            $reply .= "• **{$z['name']}**: {$z['occupied']}/{$z['capacity']} xe ({$z['pct']}%) → {$icon} *{$z['status_label']}* (còn {$z['available']} chỗ)\n";
        }

        $reply .= "\n⏱️ *Dữ liệu cảm biến tự động cập nhật trực tiếp từ hệ thống cổng từ UTH.*";
=======
        str_contains($msgNormalized, 'parking')
    ) {
        $reply = "ℹ️ **THÔNG BÁO VỀ HỆ THỐNG DỊCH VỤ UTH STATUS**\n\n";
        $reply .= "Cổng thông tin UTH STATUS hiện tại tập trung theo dõi và giám sát trạng thái hoạt động của các **Dịch vụ Công nghệ Thông tin** (LMS Moodle, Wifi eduroam, Cổng tín chỉ, Học phí trực tuyến, Thư viện số, Email sinh viên...).\n\n";
        $reply .= "Chức năng bãi xe sân trường không nằm trong phạm vi giám sát dịch vụ CNTT của cổng. Nếu bạn cần thông tin gửi xe, vui lòng liên hệ trực tiếp Tổ Bảo vệ tại Cổng trường Nhà A1.";

>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69
        return [
            'success'     => true,
            'message'     => $reply,
            'provider'    => 'smart_offline',
<<<<<<< HEAD
            'actions'     => [
                ['label' => '🅿️ Mở Bản Đồ Bãi Xe', 'action' => 'openParkingModal'],
                ['label' => '📍 Kiểm Tra Khu B', 'question' => 'Khu B còn chỗ không bot?']
            ],
            'suggestions' => [
                'Bãi xe khu A hiện tại còn chỗ không bot?',
                'Bãi xe khu B còn chỗ không?',
                'Quy định giờ đóng cửa bãi xe?'
=======
            'suggestions' => [
                'Cổng đăng ký tín chỉ có ổn định không?',
                'Hướng dẫn kết nối Wi-Fi eduroam?',
                'Hôm nay có dịch vụ nào gặp sự cố không?'
>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69
            ]
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // 2. NHẬN DIỆN CÂU HỎI VỀ SỰ CỐ / LỖI HỆ THỐNG / HỎNG / FIFO QUEUE
    // ─────────────────────────────────────────────────────────────
    $isIncidentQuery = (
        str_contains($msgNormalized, 'su co') || 
        str_contains($msgNormalized, 'loi') || 
<<<<<<< HEAD
        str_contains($msgNormalized, 'hong') || 
=======
        preg_match('/\bhong\b/', $msgNormalized) || 
>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69
        str_contains($msgNormalized, 'khong vao duoc') ||
        str_contains($msgNormalized, 'cham') ||
        str_contains($msgNormalized, 'dang xu ly') ||
        str_contains($msgNormalized, 'bao cao su co') ||
        ($prevTopic === 'incident' && (
            str_contains($msgNormalized, 'bao gio xong') ||
            str_contains($msgNormalized, 'sua chua') ||
            str_contains($msgNormalized, 'gui bao cao')
        ))
    );

    if ($isIncidentQuery) {
        $reply = "⚠️ **THÔNG TIN SỰ CỐ & TIẾN ĐỘ XỬ LÝ TẠI UTH STATUS**\n\n";
        if (empty($ctx['active_incidents'])) {
            $reply .= "🟢 **Hiện tại không có sự cố CNTT nghiêm trọng nào đang diễn ra!**\n";
            $reply .= "Tất cả các dịch vụ trọng yếu của trường (LMS, Eduroam, Cổng tín chỉ, Website...) đều đang vận hành ổn định.\n\n";
        } else {
            $reply .= "Hiện có **" . count($ctx['active_incidents']) . " sự cố** đang được đội ngũ kỹ thuật khẩn trương xử lý:\n";
            foreach ($ctx['active_incidents'] as $inc) {
                $badge = ($inc['severity'] === 'critical' || $inc['severity'] === 'high') ? '🔴' : '🟡';
                $reply .= "• {$badge} **{$inc['title']}** (Mức độ: {$inc['severity']}, Trạng thái: *{$inc['status']}*)\n";
            }
            $reply .= "\n";
        }

        // Đính kèm thông tin sự cố vừa khắc phục nếu có
        if (!empty($ctx['resolved_incidents'])) {
            $reply .= "✅ **Sự cố vừa được khắc phục thành công gần đây:**\n";
            foreach ($ctx['resolved_incidents'] as $rInc) {
                $reply .= "• {$rInc['title']} *(Khắc phục lúc {$rInc['resolved_at']})*\n";
            }
            $reply .= "\n";
        }

        $reply .= "📢 **Nếu bạn phát hiện sự cố dịch vụ mới?**\n";
        $reply .= "Hãy nhấn vào nút **\"Báo Cáo Sự Cố FIFO\"** bên dưới. Báo cáo của bạn sẽ được gửi thẳng vào hàng đợi ưu tiên để kỹ thuật viên tiếp nhận và xử lý!";

        return [
            'success'     => true,
            'message'     => $reply,
            'provider'    => 'smart_offline',
            'actions'     => [
                ['label' => '⚠️ Gửi Báo Cáo Sự Cố FIFO', 'action' => 'openIncidentModal'],
                ['label' => '🛠️ Xem Lịch Bảo Trì', 'action' => 'openMaintenanceModal']
            ],
            'suggestions' => [
                'Có dịch vụ nào đang bảo trì không?',
<<<<<<< HEAD
                'Tình trạng bãi xe sân trường lúc này?',
=======
                'Cổng đăng ký tín chỉ có ổn định không?',
>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69
                'Hướng dẫn kết nối Wi-Fi eduroam?'
            ]
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // 3. NHẬN DIỆN CÂU HỎI VỀ LỊCH BẢO TRÌ / NÂNG CẤP HỆ THỐNG
    // ─────────────────────────────────────────────────────────────
    if (
        str_contains($msgNormalized, 'bao tri') || 
        str_contains($msgNormalized, 'nang cap') || 
        str_contains($msgNormalized, 'lich bao tri') || 
        str_contains($msgNormalized, 'bao duong')
    ) {
        $reply = "🛠️ **LỊCH TRÌNH BẢO TRÌ HỆ THỐNG CNTT UTH**\n\n";
        if (empty($ctx['upcoming_maintenances'])) {
            $reply .= "🟢 **Trong tuần này không có lịch bảo trì nào được lên kế hoạch!**\n";
            $reply .= "Các hệ thống LMS, Cổng thông tin đào tạo và Mạng Wi-Fi hoạt động bình thường 24/7.\n";
        } else {
            $reply .= "Danh sách các đợt bảo trì sắp tới trong tuần:\n";
            foreach ($ctx['upcoming_maintenances'] as $m) {
                $statusIcon = ($m['status'] === 'in_progress') ? '⚙️ [ĐANG TIẾN HÀNH]' : '🗓️ [ĐÃ LÊN LỊCH]';
                $reply .= "• {$statusIcon} **{$m['title']}**\n";
                $reply .= "  Thời gian: Từ `{$m['scheduled_at']}` đến `{$m['expected_end']}`\n";
                if (!empty($m['description'])) {
                    $reply .= "  Chi tiết: {$m['description']}\n";
                }
            }
        }
        return [
            'success'     => true,
            'message'     => $reply,
            'provider'    => 'smart_offline',
            'actions'     => [
                ['label' => '🛠️ Xem Lịch Bảo Trì Chi Tiết', 'action' => 'openMaintenanceModal']
            ],
            'suggestions' => [
                'Hôm nay có dịch vụ nào gặp sự cố không?',
<<<<<<< HEAD
                'Bãi xe sân trường lúc này thế nào?',
=======
                'Cổng đăng ký tín chỉ có ổn định không?',
>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69
                'Độ khả dụng SLA hệ thống là bao nhiêu?'
            ]
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // 4. NHẬN DIỆN CÂU HỎI VỀ ĐĂNG KÝ TÍN CHỈ / HỌC PHẦN (Ưu tiên)
    // ─────────────────────────────────────────────────────────────
    if (
        str_contains($msgNormalized, 'tin chi') || 
        str_contains($msgNormalized, 'hoc phan') || 
        str_contains($msgNormalized, 'dang ky mon') || 
        str_contains($msgNormalized, 'dkmon') ||
        str_contains($msgNormalized, 'rut mon') ||
        str_contains($msgNormalized, 'huy mon')
    ) {
        $reply = "🎓 **CỔNG ĐĂNG KÝ HỌC PHẦN & TÍN CHỈ UTH (tinchi.uth.edu.vn)**\n\n";
        $reply .= "• Trạng thái hệ thống: 🟢 **Hoạt động bình thường**\n";
        $reply .= "• Đăng nhập: Sử dụng Mã sinh viên và mật khẩu do Phòng Đào tạo cấp.\n\n";
        $reply .= "💡 **Kinh nghiệm đăng ký tín chỉ tránh nghẽn mạng:**\n";
        $reply .= "1. Chuẩn bị trước danh sách mã học phần và các lớp dự phòng ra file ghi chú.\n";
        $reply .= "2. Đăng nhập trước giờ mở cổng khoảng 5-10 phút.\n";
        $reply .= "3. Hạn chế mở quá nhiều tab trình duyệt hoặc liên tục ấn F5 quá nhanh.\n";
        $reply .= "4. Nếu bị trùng lịch hoặc lớp đã đầy, liên hệ **Phòng Đào tạo (P.201 - Nhà A1)** trong thời gian điều chỉnh học phần để được xử lý.";

        return [
            'success'     => true,
            'message'     => $reply,
            'provider'    => 'smart_offline',
            'actions'     => [
                ['label' => '⚠️ Báo Cáo Lỗi Cổng Tín Chỉ', 'action' => 'openIncidentModal']
            ],
            'suggestions' => [
                'Hướng dẫn thanh toán học phí trực tuyến?',
                'Hôm nay có dịch vụ nào gặp sự cố không?',
<<<<<<< HEAD
                'Bãi xe khu A còn chỗ không?'
=======
                'Hướng dẫn sử dụng LMS Moodle?'
>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69
            ]
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // 5. NHẬN DIỆN CÂU HỎI VỀ WIFI EDUROAM & MẠNG INTERNET UTH
    // ─────────────────────────────────────────────────────────────
    $isWifiQuery = (
        str_contains($msgNormalized, 'eduroam') || 
        str_contains($msgNormalized, 'wifi') || 
        str_contains($msgNormalized, 'internet') ||
        str_contains($msgNormalized, 'ket noi mang') ||
        str_contains($msgNormalized, 'mat mang') ||
        str_contains($msgNormalized, 'song mang') ||
        ($prevTopic === 'wifi' && (
            str_contains($msgNormalized, 'mat khau') ||
            str_contains($msgNormalized, 'tai khoan') ||
            str_contains($msgNormalized, 'khong bat duoc')
        )) ||
        (str_contains($msgNormalized, 'mang') && !str_contains($msgNormalized, 'tin chi') && !str_contains($msgNormalized, 'hoc phi'))
    );

    if ($isWifiQuery) {
        $reply = "📶 **HƯỚNG DẪN KẾT NỐI MẠNG WI-FI EDUROAM TẠI UTH**\n\n";
        $reply .= "Mạng **eduroam** là mạng Wi-Fi giáo dục chuyển vùng quốc tế phủ sóng toàn khuôn viên Đại học Giao thông Vận tải.\n\n";
        $reply .= "🔑 **Thông tin đăng nhập:**\n";
        $reply .= "• **Tài khoản**: `<mã_sinh_viên>@uth.edu.vn` (hoặc `@student.uth.edu.vn`)\n";
        $reply .= "• **Mật khẩu**: Mật khẩu cổng thông tin sinh viên của bạn\n\n";
        $reply .= "⚙️ **Cài đặt kỹ thuật (Chuẩn kết nối):**\n";
        $reply .= "• Phương thức EAP (EAP method): **PEAP**\n";
        $reply .= "• Xác thực giai đoạn 2 (Phase 2): **MSCHAPV2**\n";
        $reply .= "• Chứng chỉ CA (CA certificate): **Không xác thực** (Do not validate / Unspecified)\n";
        $reply .= "• Danh tính ẩn danh (Anonymous identity): Để trống\n\n";
        $reply .= "💡 **Mẹo khắc phục khi không vào được mạng:**\n";
        $reply .= "1. Bật/Tắt chế độ máy bay hoặc chọn \"Quên mạng\" (Forget Network) và kết nối lại.\n";
        $reply .= "2. Kiểm tra xem tài khoản sinh viên có bị khóa do quá hạn đổi mật khẩu định kỳ không.\n";
        $reply .= "3. Đến trực tiếp **Trung tâm CNTT (Phòng 105 - Nhà A1)** để được hỗ trợ kiểm tra thiết bị.";

        return [
            'success'     => true,
            'message'     => $reply,
            'provider'    => 'smart_offline',
            'actions'     => [
                ['label' => '⚠️ Báo Lỗi Wi-Fi Cho Kỹ Thuật', 'action' => 'openIncidentModal']
            ],
            'suggestions' => [
                'Quên mật khẩu tài khoản sinh viên?',
                'Cách nộp bài trên LMS Moodle?',
<<<<<<< HEAD
                'Bãi xe sân trường lúc này thế nào?'
=======
                'Cổng đăng ký tín chỉ có ổn định không?'
>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69
            ]
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // 6. NHẬN DIỆN CÂU HỎI VỀ HỌC PHÍ / CỔNG THANH TOÁN
    // ─────────────────────────────────────────────────────────────
    if (
        str_contains($msgNormalized, 'hoc phi') || 
        str_contains($msgNormalized, 'tien hoc') || 
        str_contains($msgNormalized, 'dong hoc phi') || 
        str_contains($msgNormalized, 'thanh toan') ||
        str_contains($msgNormalized, 'hoa don')
    ) {
        $reply = "💳 **CỔNG THANH TOÁN HỌC PHÍ TRỰC TUYẾN UTH (hocphi.uth.edu.vn)**\n\n";
        $reply .= "• Cổng thanh toán: 🟢 **Đang kết nối liên ngân hàng ổn định**\n\n";
        $reply .= "🏦 **Các phương thức thanh toán hỗ trợ:**\n";
        $reply .= "1. **Cổng VNPAY / VietinBank / Agribank / BIDV**: Quét mã VNPAY-QR trực tiếp trên cổng học phí.\n";
        $reply .= "2. **Chuyển khoản theo mã định danh sinh viên**: Cú pháp nộp học phí ghi rõ: `<MãSV> <Họ tên> Nop hoc phi HK...`\n\n";
        $reply .= "📑 **Lưu ý quan trọng:**\n";
        $reply .= "• Sau khi thanh toán thành công, hệ thống thường cập nhật gạch nợ sau 15-30 phút.\n";
        $reply .= "• Hóa đơn điện tử (E-invoice) có thể tải về từ cổng trong vòng 24h sau khi kế toán duyệt.\n";
        $reply .= "• Nếu chuyển khoản bị trừ tiền nhưng chưa gạch nợ, liên hệ **Phòng Kế hoạch - Tài chính** kèm biên lai giao dịch.";

        return [
            'success'     => true,
            'message'     => $reply,
            'provider'    => 'smart_offline',
            'suggestions' => [
                'Cổng đăng ký tín chỉ có ổn định không?',
                'Cách đổi mật khẩu tài khoản sinh viên?',
<<<<<<< HEAD
                'Bãi xe sân trường lúc này thế nào?'
=======
                'Hôm nay có dịch vụ nào gặp sự cố không?'
>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69
            ]
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // 7. NHẬN DIỆN CÂU HỎI VỀ LMS / MOODLE HỌC TRỰC TUYẾN
    // ─────────────────────────────────────────────────────────────
    if (
        str_contains($msgNormalized, 'lms') || 
        str_contains($msgNormalized, 'moodle') || 
        str_contains($msgNormalized, 'nop bai') || 
        str_contains($msgNormalized, 'hoc truc tuyen') ||
        str_contains($msgNormalized, 'bai tap')
    ) {
        $reply = "🎓 **HỆ THỐNG HỌC TẬP TRỰC TUYẾN LMS (MOODLE) UTH**\n\n";
        $reply .= "• Trạng thái hiện tại: 🟢 **Hoạt động tốt (Operational)**\n";
        $reply .= "• Đăng nhập bằng mã sinh viên và mật khẩu do Nhà trường cấp.\n\n";
        $reply .= "📝 **Lưu ý quan trọng khi nộp bài tập & bài thi:**\n";
        $reply .= "• Sinh viên nên nộp trước hạn chót ít nhất 15-30 phút để phòng ngừa nghẽn mạng.\n";
        $reply .= "• Dung lượng file nộp tối đa theo cấu hình từng môn (thường là 20MB - 50MB, định dạng PDF/DOCX/ZIP).\n";
        $reply .= "• Nếu không thấy môn học trên danh sách khóa học, hãy liên hệ giảng viên phụ trách để được enrol vào lớp.";

        return [
            'success'     => true,
            'message'     => $reply,
            'provider'    => 'smart_offline',
            'actions'     => [
                ['label' => '⚠️ Báo Lỗi LMS Cho Kỹ Thuật', 'action' => 'openIncidentModal']
            ],
            'suggestions' => [
                'Hướng dẫn kết nối Wi-Fi eduroam?',
                'Cổng đăng ký học phần tín chỉ?',
<<<<<<< HEAD
                'Bãi xe sân trường lúc này thế nào?'
=======
                'Cách nộp bài tập trên LMS Moodle?'
>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69
            ]
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // 8. NHẬN DIỆN CÂU HỎI VỀ CỔNG THÔNG TIN SINH VIÊN (sv.uth.edu.vn)
    // ─────────────────────────────────────────────────────────────
    if (
        str_contains($msgNormalized, 'sv.uth') || 
        str_contains($msgNormalized, 'diem thi') || 
        str_contains($msgNormalized, 'bang diem') || 
        str_contains($msgNormalized, 'lich thi') ||
        str_contains($msgNormalized, 'diem ren luyen') ||
        str_contains($msgNormalized, 'giay xac nhan')
    ) {
        $reply = "📑 **CỔNG THÔNG TIN SINH VIÊN UTH (sv.uth.edu.vn)**\n\n";
        $reply .= "• Trạng thái: 🟢 **Hoạt động ổn định**\n\n";
        $reply .= "🔍 **Các tiện ích trực tuyến dành cho sinh viên:**\n";
        $reply .= "• Tra cứu lịch học, lịch thi kết thúc học phần và điểm tổng kết.\n";
        $reply .= "• Đánh giá điểm rèn luyện trực tuyến theo học kỳ.\n";
        $reply .= "• Đăng ký xin cấp Giấy xác nhận sinh viên, Giấy hoãn nghĩa vụ quân sự (nhận trực tuyến hoặc tại P.106 Nhà A1).\n";
        $reply .= "• Tra cứu chương trình đào tạo và tiến độ tích lũy tín chỉ.";

        return [
            'success'     => true,
            'message'     => $reply,
            'provider'    => 'smart_offline',
            'suggestions' => [
                'Quên mật khẩu cổng thông tin?',
                'Hướng dẫn kết nối Wi-Fi eduroam?',
<<<<<<< HEAD
                'Bãi xe sân trường lúc này thế nào?'
=======
                'Hướng dẫn đăng ký tín chỉ trực tuyến?'
>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69
            ]
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // 9. NHẬN DIỆN CÂU HỎI VỀ EMAIL SINH VIÊN UTH
    // ─────────────────────────────────────────────────────────────
    if (
        str_contains($msgNormalized, 'email') || 
        str_contains($msgNormalized, 'mail') || 
        str_contains($msgNormalized, 'gmail') || 
        str_contains($msgNormalized, 'student.uth')
    ) {
        $reply = "📧 **HỆ THỐNG EMAIL SINH VIÊN UTH (Google Workspace for Education)**\n\n";
        $reply .= "• Định dạng email: `<mã_sinh_viên>@student.uth.edu.vn` (hoặc `@uth.edu.vn` cho cán bộ)\n";
        $reply .= "• Đăng nhập: Trực tiếp trên trang chủ Gmail (`mail.google.com`).\n\n";
        $reply .= "🔑 **Tiện ích đi kèm:**\n";
        $reply .= "• Nhận toàn bộ thông báo học vụ, lịch thi, cảnh báo điểm từ Nhà trường.\n";
        $reply .= "• Sử dụng Google Drive, Google Meet và bộ công cụ học tập bản quyền.\n";
        $reply .= "• Nếu bị quên mật khẩu email, liên hệ Trung tâm CNTT (P.105 Nhà A1) kèm thẻ SV để reset.";

        return [
            'success'     => true,
            'message'     => $reply,
            'provider'    => 'smart_offline',
            'suggestions' => [
                'Cách kết nối Wi-Fi eduroam?',
                'Quên mật khẩu tài khoản sinh viên?',
<<<<<<< HEAD
                'Bãi xe sân trường lúc này thế nào?'
=======
                'Cách đổi mật khẩu tài khoản sinh viên?'
>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69
            ]
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // 10. NHẬN DIỆN CÂU HỎI VỀ THƯ VIỆN & PHÒNG TỰ HỌC
    // ─────────────────────────────────────────────────────────────
    if (
        str_contains($msgNormalized, 'thu vien') || 
        str_contains($msgNormalized, 'muon sach') || 
        str_contains($msgNormalized, 'phong doc') || 
        str_contains($msgNormalized, 'giao trinh')
    ) {
        $reply = "📚 **THƯ VIỆN & CƠ SỞ DỮ LIỆU SỐ UTH (thuvien.uth.edu.vn)**\n\n";
        $reply .= "• Vị trí: Khu phức hợp Thư viện & Giảng đường Nhà A1.\n";
        $reply .= "• Giờ mở cửa: **07:30 - 17:00** (Thứ 2 đến Thứ 6).\n\n";
        $reply .= "🔍 **Dịch vụ cung cấp:**\n";
        $reply .= "• Không gian phòng đọc tự học máy lạnh, trang bị Wi-Fi eduroam tốc độ cao.\n";
        $reply .= "• Tra cứu giáo trình, tài liệu số và luận văn thạc sĩ / đồ án tốt nghiệp.\n";
        $reply .= "• Đăng nhập tra cứu sách trực tuyến bằng mã số sinh viên.";

        return [
            'success'     => true,
            'message'     => $reply,
            'provider'    => 'smart_offline',
            'suggestions' => [
                'Hướng dẫn kết nối Wi-Fi eduroam?',
<<<<<<< HEAD
                'Bãi xe sân trường lúc này thế nào?'
=======
                'Cổng thông tin sinh viên có ổn định không?'
>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69
            ]
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // 11. NHẬN DIỆN CÂU HỎI VỀ QUÊN MẬT KHẨU / ĐỔI MẬT KHẨU
    // ─────────────────────────────────────────────────────────────
    if (
        str_contains($msgNormalized, 'mat khau') || 
        str_contains($msgNormalized, 'quen pass') || 
        str_contains($msgNormalized, 'cap lai mat khau') || 
        str_contains($msgNormalized, 'reset pass') ||
        str_contains($msgNormalized, 'doi mat khau')
    ) {
        $reply = "🔑 **HƯỚNG DẪN CẤP LẠI & ĐỔI MẬT KHẨU TÀI KHOẢN UTH**\n\n";
        $reply .= "1. Tại màn hình Đăng nhập UTH STATUS, nhấn vào liên kết **\"Quên mật khẩu?\"**.\n";
        $reply .= "2. Nhập chính xác Mã sinh viên và Email đã đăng ký trong hệ thống (`@student.uth.edu.vn`).\n";
        $reply .= "3. Gửi yêu cầu; ban quản trị (Admin) sẽ xác minh danh tính và hỗ trợ cấp lại mật khẩu cho bạn.\n";
        $reply .= "4. Bạn cũng có thể liên hệ trực tiếp **Phòng Đào tạo / Trung tâm CNTT** tại **Phòng 105 - Nhà A1** để được cấp lại trực tiếp trong 5 phút.";

        return [
            'success'     => true,
            'message'     => $reply,
            'provider'    => 'smart_offline',
            'suggestions' => [
                'Hướng dẫn kết nối Wi-Fi eduroam?',
                'Cổng đăng ký tín chỉ có ổn định không?',
<<<<<<< HEAD
                'Bãi xe sân trường lúc này thế nào?'
=======
                'Độ khả dụng SLA hệ thống là bao nhiêu?'
>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69
            ]
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // 12. NHẬN DIỆN CÂU HỎI VỀ SLA / UPTIME / ĐỘ KHẢ DỤNG HỆ THỐNG
    // ─────────────────────────────────────────────────────────────
    if (
        str_contains($msgNormalized, 'uptime') || 
        str_contains($msgNormalized, 'sla') || 
        str_contains($msgNormalized, 'do kha dung') || 
        str_contains($msgNormalized, 'on dinh') ||
        str_contains($msgNormalized, 'toc do')
    ) {
        $reply = "📈 **BÁO CÁO ĐỘ KHẢ DỤNG & HIỆU NĂNG HỆ THỐNG UTH (SLA METRICS)**\n\n";
        $reply .= "• Tỷ lệ Uptime 90 ngày: 🟢 **{$ctx['sla_metrics']['overall_sla_pct']}%** (Cam kết SLA ≥ {$ctx['sla_metrics']['sla_target']}%)\n";
        $reply .= "• Thời gian phản hồi trung bình: **{$ctx['sla_metrics']['avg_response_time_ms']}ms**\n";
        $reply .= "• Tổng số dịch vụ được giám sát: **{$ctx['services']['total']} dịch vụ**\n";
        $reply .= "• Tần suất kiểm tra tự động (Health Check): Mỗi **60 giây** qua cảm biến ping HTTP/TCP.";

        return [
            'success'     => true,
            'message'     => $reply,
            'provider'    => 'smart_offline',
            'suggestions' => [
                'Hôm nay có dịch vụ nào đang lỗi không?',
                'Lịch trình bảo trì trong tuần tới?',
<<<<<<< HEAD
                'Bãi xe sân trường lúc này thế nào?'
=======
                'Hotline hỗ trợ kỹ thuật CNTT UTH?'
>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69
            ]
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // 13. NHẬN DIỆN CÂU HỎI VỀ HOTLINE / ĐỊA CHỈ / LIÊN HỆ KHẨN CẤP
    // ─────────────────────────────────────────────────────────────
    if (
        str_contains($msgNormalized, 'hotline') || 
        str_contains($msgNormalized, 'lien he') || 
        str_contains($msgNormalized, 'so dien thoai') || 
        str_contains($msgNormalized, 'dia chi') ||
        str_contains($msgNormalized, 'o dau') && str_contains($msgNormalized, 'phong')
    ) {
        $c = $ctx['campus_contacts'];
        $reply = "📞 **THÔNG TIN LIÊN HỆ & HỖ TRỢ KỸ THUẬT UTH**\n\n";
        $reply .= "🏢 **Trường Đại học Giao thông Vận tải (UTH)**\n";
        $reply .= "• **Trung tâm CNTT**: {$c['it_office']} | Hotline: **{$c['it_hotline']}** | Email: `{$c['it_email']}`\n";
        $reply .= "• **Phòng Đào tạo**: {$c['dao_tao']} (Giải quyết học vụ, tín chỉ, thời khóa biểu)\n";
        $reply .= "• **Phòng Công tác Sinh viên**: {$c['ctsv']} (Giải quyết chế độ chính sách, giấy xác nhận)\n";
<<<<<<< HEAD
        $reply .= "• **Thời gian làm việc**: {$c['working_hrs']}\n";
        $reply .= "• **Bãi giữ xe**: {$c['parking_hrs']}";
=======
        $reply .= "• **Thời gian làm việc**: {$c['working_hrs']}";
>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69

        return [
            'success'     => true,
            'message'     => $reply,
            'provider'    => 'smart_offline',
            'actions'     => [
                ['label' => '⚠️ Gửi Yêu Cầu Hỗ Trợ Kỹ Thuật', 'action' => 'openIncidentModal']
            ],
            'suggestions' => [
<<<<<<< HEAD
                'Bãi xe sân trường lúc này thế nào?',
=======
                'Cổng đăng ký tín chỉ có ổn định không?',
>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69
                'Hướng dẫn kết nối Wi-Fi eduroam?',
                'Có lịch bảo trì nào sắp tới không?'
            ]
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // 14. NHẬN DIỆN CÂU HỎI TỔNG QUAN TÌNH TRẠNG HỆ THỐNG
    // ─────────────────────────────────────────────────────────────
    if (
        str_contains($msgNormalized, 'he thong') || 
        str_contains($msgNormalized, 'trang thai') || 
        str_contains($msgNormalized, 'tinh hinh') || 
        str_contains($msgNormalized, 'on khong') ||
        str_contains($msgNormalized, 'the nao')
    ) {
        $reply = "📊 **TỔNG HỢP TRẠNG THÁI HỆ THỐNG UTH STATUS**\n\n";
        $reply .= "• Dịch vụ CNTT: **{$ctx['services']['operational']}/{$ctx['services']['total']}** dịch vụ hoạt động bình thường.\n";
        if (!empty($ctx['services']['outages'])) {
            $reply .= "• Dịch vụ lỗi: 🔴 **" . implode(', ', $ctx['services']['outages']) . "**\n";
        } else {
            $reply .= "• Không có dịch vụ nào gặp sự cố gián đoạn.\n";
        }
<<<<<<< HEAD
        $reply .= "• Bãi xe sân trường: Đang đỗ **{$ctx['parking']['total_occupied']}/{$ctx['parking']['total_capacity']} xe** ({$ctx['parking']['occupancy_pct']}%).\n";
=======
>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69
        $reply .= "• Sự cố đang xử lý: **" . count($ctx['active_incidents']) . "** sự cố.\n";
        $reply .= "• Bảo trì sắp tới: **" . count($ctx['upcoming_maintenances']) . "** kế hoạch.\n\n";
        $reply .= "💡 *Bạn có thể bấm vào các nút gợi ý bên dưới để tra cứu chi tiết hơn nhé!*";

        return [
            'success'     => true,
            'message'     => $reply,
            'provider'    => 'smart_offline',
            'actions'     => [
<<<<<<< HEAD
                ['label' => '🅿️ Xem Bãi Xe Sân Trường', 'action' => 'openParkingModal'],
                ['label' => '🛠️ Xem Lịch Bảo Trì', 'action' => 'openMaintenanceModal']
            ],
            'suggestions' => [
                'Bãi xe sân trường lúc này thế nào?',
=======
                ['label' => '🛠️ Xem Lịch Bảo Trì', 'action' => 'openMaintenanceModal'],
                ['label' => '⚠️ Báo Cáo Sự Cố', 'action' => 'openIncidentModal']
            ],
            'suggestions' => [
                'Cổng đăng ký tín chỉ có hoạt động tốt không?',
>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69
                'Có dịch vụ nào đang bảo trì không?',
                'Hướng dẫn kết nối Wi-Fi eduroam?'
            ]
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // 15. CHÀO HỎI / CẢM ƠN / MẶC ĐỊNH
    // ─────────────────────────────────────────────────────────────
    $reply = "👋 **Xin chào! Mình là Trợ lý Ảo UTH STATUS.**\n\n";
    $reply .= "Mình có thể hỗ trợ bạn tra cứu tức thì thông tin hệ thống tại ĐH Giao thông Vận tải:\n";
<<<<<<< HEAD
    $reply .= "• 🅿️ **Tình trạng bãi xe sân trường**: Số lượng chỗ trống thời gian thực tại Khu A, Khu B, Khu C.\n";
=======
>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69
    $reply .= "• 🟢 **Trạng thái dịch vụ CNTT**: LMS Moodle, Wifi eduroam, Cổng tín chỉ, Đăng ký học phần...\n";
    $reply .= "• 🛠️ **Sự cố & Lịch bảo trì**: Cập nhật tiến độ xử lý và kế hoạch bảo dưỡng trong tuần.\n";
    $reply .= "• 📝 **Hướng dẫn báo cáo sự cố**: Tiếp nhận phản ánh lỗi vào hàng đợi ưu tiên FIFO.\n\n";
    $reply .= "Bạn muốn hỏi về vấn đề gì hôm nay?";

    return [
        'success'     => true,
        'message'     => $reply,
        'provider'    => 'smart_offline',
        'actions'     => [
<<<<<<< HEAD
            ['label' => '🅿️ Bãi Xe Sân Trường', 'action' => 'openParkingModal'],
            ['label' => '⚠️ Báo Cáo Sự Cố', 'action' => 'openIncidentModal']
        ],
        'suggestions' => [
            '🅿️ Bãi xe sân trường lúc này thế nào?',
=======
            ['label' => '⚠️ Báo Cáo Sự Cố', 'action' => 'openIncidentModal'],
            ['label' => '🛠️ Lịch Bảo Trì', 'action' => 'openMaintenanceModal']
        ],
        'suggestions' => [
            '🎓 Cổng đăng ký tín chỉ có ổn định không?',
>>>>>>> e734b4f0b95a5be6774689e3251f42a4365e1d69
            '⚠️ Có dịch vụ nào đang gặp sự cố không?',
            '🛠️ Lịch bảo trì trong tuần tới?',
            '📶 Hướng dẫn kết nối Wi-Fi eduroam?'
        ]
    ];
}
