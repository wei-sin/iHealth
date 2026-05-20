<?php
// --- 1. 載入 .env 檔案設定 ---
$envFilePath = __DIR__ . '/.env';
if (file_exists($envFilePath)) {
    $lines = file($envFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, '#') === 0 || strpos($line, '=') === false) continue;
        list($name, $value) = explode('=', $line, 2);
        putenv(sprintf('%s=%s', trim($name), trim($value, " \t\n\r\0\x0B\"'")));
        $_ENV[trim($name)] = trim($value, " \t\n\r\0\x0B\"'");
    }
}

// --- 2. 安全啟動 Session (修復重複啟動警告) ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- 3. 資料庫連線與自動建表 (PDO) ---
$db_host = '127.0.0.1';
$db_name = 'ihealth_db';
$db_user = 'root';
$db_pass = '';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);

    // 【自動建表機制】確保所有需要的資料表都存在
    $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `username` varchar(50) NOT NULL UNIQUE,
        `password` varchar(255) NOT NULL,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO users (username, password) VALUES ('admin', 'admin123')");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `user_profile` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `name` varchar(50) NOT NULL,
      `gender` varchar(10) NOT NULL,
      `birthday` date NOT NULL DEFAULT '2000-01-01',
      `height` float NOT NULL,
      `weight` float NOT NULL,
      `activity_level` varchar(20) NOT NULL,
      `diet_habit` varchar(50) NOT NULL,
      `allergies` varchar(255) DEFAULT '',
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 【自動升級機制】如果資料表存在，且舊有 age 欄位還在，則進行無痛轉換
    try {
        $checkAge = $pdo->query("SHOW COLUMNS FROM `user_profile` LIKE 'age'");
        if ($checkAge->rowCount() > 0) {
            $pdo->exec("ALTER TABLE `user_profile` ADD COLUMN `birthday` DATE NOT NULL DEFAULT '2000-01-01' AFTER `gender`");
            $pdo->exec("ALTER TABLE `user_profile` DROP COLUMN `age`");
        }
    } catch (PDOException $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS `health_records` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `date` date NOT NULL,
      `weight` float DEFAULT NULL,
      `notes` text DEFAULT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `unique_date` (`date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `saved_recipes` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `title` varchar(100) NOT NULL,
      `category` varchar(50) NOT NULL,
      `prep_time` varchar(50) NOT NULL,
      `steps` json NOT NULL,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

} catch (PDOException $e) {
    die("資料庫連線失敗！請確認 XAMPP MySQL 已啟動，並已在 phpMyAdmin 建立 ihealth_db 資料庫。<br>詳細錯誤：" . $e->getMessage());
}

// --- 4. 處理登出 ---
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    header("Location: index.php?page=login");
    exit;
}

// --- 5. 處理登入與註冊表單 ---
$auth_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $username = trim(htmlspecialchars($_POST['username'] ?? ''));
    $password = $_POST['password'] ?? '';

    if ($action === 'login') {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND password = ?");
        $stmt->execute([$username, $password]);
        if ($stmt->rowCount() > 0) {
            $_SESSION['logged_in_user'] = $username;
            header("Location: index.php?page=home");
            exit;
        } else {
            $auth_error = '帳號或密碼錯誤，請重試！';
        }
    } elseif ($action === 'register') {
        $confirm_password = $_POST['confirm_password'] ?? '';
        if (empty($username) || empty($password)) {
            $auth_error = '請填寫完整註冊資訊！';
        } elseif ($password !== $confirm_password) {
            $auth_error = '兩次輸入的密碼不一致！';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
                $stmt->execute([$username, $password]);
                $_SESSION['logged_in_user'] = $username;
                header("Location: index.php?page=home");
                exit;
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $auth_error = '此帳號已被註冊，請換一個或直接登入！';
                } else {
                    $auth_error = '系統錯誤，請稍後再試。';
                }
            }
        }
    }
}

// --- 6. 頁面權限控管 ---
$page = $_GET['page'] ?? 'home';
$public_pages = ['login', 'register'];
if (!isset($_SESSION['logged_in_user']) && !in_array($page, $public_pages)) {
    header("Location: index.php?page=login");
    exit;
}
if (isset($_SESSION['logged_in_user']) && in_array($page, $public_pages)) {
    header("Location: index.php?page=home");
    exit;
}

// --- 7. 處理儀表板內的表單提交 ---
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'save_daily_record') {
        $inputDate = htmlspecialchars($_POST['date']);
        $inputWeight = filter_input(INPUT_POST, 'weight', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        $inputNotes = htmlspecialchars($_POST['notes']);

        $stmt = $pdo->prepare("INSERT INTO health_records (date, weight, notes) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE weight = ?, notes = ?");
        $stmt->execute([$inputDate, $inputWeight, $inputNotes, $inputWeight, $inputNotes]);
        $message = 'record_saved';
    }

    if ($action === 'save_profile') {
        // 加入防呆：如果 POST 沒收到，給予預設值，避免報錯
        $gender = htmlspecialchars($_POST['gender'] ?? 'female');
        $birthday = htmlspecialchars($_POST['birthday'] ?? '2000-01-01');
        $height = filter_input(INPUT_POST, 'height', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION) ?: 160.0;
        $weight = filter_input(INPUT_POST, 'weight', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION) ?: 50.0;
        $activity_level = htmlspecialchars($_POST['activity_level'] ?? '1.2');
        $diet_habit = htmlspecialchars($_POST['diet_habit'] ?? '無特殊偏好');
        $allergies = htmlspecialchars($_POST['allergies'] ?? '');

        $stmt = $pdo->prepare("UPDATE user_profile SET gender=?, birthday=?, height=?, weight=?, activity_level=?, diet_habit=?, allergies=? WHERE id=1");
        $stmt->execute([$gender, $birthday, $height, $weight, $activity_level, $diet_habit, $allergies]);
        $message = 'profile_saved';
    }

    if ($action === 'delete_recipe') {
        $delete_id = filter_input(INPUT_POST, 'recipe_id', FILTER_SANITIZE_NUMBER_INT);
        $stmt = $pdo->prepare("DELETE FROM saved_recipes WHERE id = ?");
        $stmt->execute([$delete_id]);
        $message = 'recipe_deleted';
    }

    if ($action === 'change_password') {
        $old_password = $_POST['old_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $username = $_SESSION['logged_in_user'];

        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND password = ?");
        $stmt->execute([$username, $old_password]);
        if ($stmt->rowCount() == 0) {
            $message = 'password_error';
        } elseif ($new_password !== $confirm_password) {
            $message = 'password_mismatch';
        } else {
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE username = ?");
            $stmt->execute([$new_password, $username]);
            $message = 'password_changed';
        }
    }

    if ($action === 'delete_account') {
        $username = $_SESSION['logged_in_user'];
        $stmt = $pdo->prepare("DELETE FROM users WHERE username = ?");
        $stmt->execute([$username]);
        
        // 刪除後自動登出系統
        session_unset();
        session_destroy();
        header("Location: index.php?page=login");
        exit;
    }

    if ($action === 'save_generated_recipe') {
        if (isset($_SESSION['current_recipe'])) {
            $recipe = $_SESSION['current_recipe'];
            $steps_json = json_encode($recipe['steps'], JSON_UNESCAPED_UNICODE);
            $stmt = $pdo->prepare("INSERT INTO saved_recipes (title, category, prep_time, steps) VALUES (?, ?, ?, ?)");
            $stmt->execute([$recipe['title'], 'AI 專屬生成', '約 30 分鐘', $steps_json]);
            $message = 'recipe_saved';
        }
    }

    if ($action === 'generate_recipe') {
        $ingredients = trim(htmlspecialchars($_POST['ingredients']));
        if (!empty($ingredients)) {
            $apiKey = $_ENV['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY') ?: '';
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-lite:generateContent?key=' . $apiKey;
            $prompt = "我現在有這些食材：{$ingredients}。請幫我生成一道健康食譜。請務必嚴格以 JSON 格式回傳，格式如下：\n{\"title\": \"食譜名稱\",\"calories\": 450,\"protein\": 30,\"carbs\": 40,\"fat\": 15,\"steps\": [\"步驟1\",\"步驟2\"]}\n不要有其他多餘文字。";
            $data = ['contents' => [['parts' => [['text' => $prompt]]]]];

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $result = curl_exec($ch);
            curl_close($ch);

            if ($result !== FALSE) {
                $response = json_decode($result, true);
                if (isset($response['candidates'][0]['content']['parts'][0]['text'])) {
                    $aiText = trim($response['candidates'][0]['content']['parts'][0]['text']);
                    $aiText = preg_replace('/```json\s*/', '', $aiText);
                    $aiText = preg_replace('/```\s*/', '', $aiText);
                    $recipeData = json_decode($aiText, true);
                    if($recipeData) $_SESSION['current_recipe'] = $recipeData;
                }
            }
        }
    }
}

// --- 8. 從 MySQL 讀取資料供前端顯示 ---
$stmt = $pdo->query("SELECT * FROM user_profile LIMIT 1");
$p = $stmt->fetch();
if (!$p) {
    $pdo->exec("INSERT INTO user_profile (name, gender, birthday, height, weight, activity_level, diet_habit, allergies) VALUES ('User', 'female', '2000-01-01', 168.0, 58.0, '1.375', '無特殊偏好', '')");
    $p = $pdo->query("SELECT * FROM user_profile LIMIT 1")->fetch();
}
$p['name'] = $_SESSION['logged_in_user'] ?? $p['name'];

$health_records = $pdo->query("SELECT * FROM health_records ORDER BY date DESC")->fetchAll();
$saved_recipes = $pdo->query("SELECT * FROM saved_recipes ORDER BY id DESC")->fetchAll();
foreach ($saved_recipes as &$recipe) {
    $recipe['steps'] = json_decode($recipe['steps'], true);
    $recipe['time'] = $recipe['prep_time']; 
}
unset($recipe); // [修復] 解除變數參考，避免污染後續 HTML 顯示區塊的 foreach 迴圈

// --- 9. 準備圖表與個人資料計算 ---
$records_for_chart = array_reverse($health_records); 
$chart_labels = [];
$chart_weights = [];
foreach ($records_for_chart as $record) {
    if (!empty($record['weight']) && $record['weight'] > 0) {
        $chart_labels[] = date('D d', strtotime($record['date']));
        $chart_weights[] = $record['weight'];
    }
}
$chart_labels_json = json_encode($chart_labels);
$chart_weights_json = json_encode($chart_weights);

// 自動由生日計算當前年齡
$age = 0;
if (!empty($p['birthday'])) {
    try {
        $birthDate = new DateTime($p['birthday']);
        $today = new DateTime('today');
        $age = $birthDate->diff($today)->y;
    } catch (Exception $e) {
        $age = 25; // 例外防護預設值
    }
}

// 防呆處理：確保即使資料庫剛好缺少某些欄位或為 null，也不會導致 PHP 報錯
$u_gender = $p['gender'] ?? 'female';
$u_weight = $p['weight'] ?? 50;
$u_height = $p['height'] ?? 160;
$u_activity = $p['activity_level'] ?? 1.2;

$bmr = 0;
if ($u_gender === 'male') {
    $bmr = (10 * $u_weight) + (6.25 * $u_height) - (5 * $age) + 5;
} else {
    $bmr = (10 * $u_weight) + (6.25 * $u_height) - (5 * $age) - 161;
}
$tdee = $bmr * (float)$u_activity;
$protein_suggestion = $u_weight * 2; 
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>iHealth 智慧生活管家</title>
    <!-- 引入 Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- 引入 Phosphor Icons -->
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <!-- 引入 Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        /* --- 顏色設定變數 --- */
        :root {
            --color-primary: #073B4C;    /* 主色 */
            --color-secondary: #FFF2C6;  /* 副色 */
            --color-accent1: #E0FFFF;    /* 輔助點綴色 1 (背景) - 改為淡奶藍 */
            --color-accent2: #AAC4F5;    /* 輔助點綴色 2 (點綴/邊框) */
            --text-main: #495057;
            --text-dark: #212529;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #FFFFFF;
            color: var(--text-main);
            height: 100vh;
            overflow: hidden;
        }

        /* --- 登入/註冊畫面背景 --- */
        .auth-bg {
            background: linear-gradient(135deg, var(--color-accent1) 0%, #FFFFFF 100%);
            position: relative;
        }
        .auth-card {
            background-color: #ffffff;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(7, 59, 76, 0.15);
            border: 1px solid var(--color-accent2);
        }

        /* --- 自訂按鈕與輸入框 --- */
        .btn-custom-primary {
            background-color: var(--color-primary);
            border-color: var(--color-primary);
            color: #ffffff;
            font-weight: 500;
        }
        .btn-custom-primary:hover {
            background-color: var(--color-accent2);
            border-color: var(--color-accent2);
            color: #ffffff;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--color-primary);
            box-shadow: 0 0 0 0.25rem rgba(7, 59, 76, 0.25);
        }
        .input-group-text {
            background-color: transparent;
            border-right: none;
            color: var(--color-primary);
        }
        .form-control.border-left-0 {
            border-left: none;
        }

        /* --- 側邊欄設計 (卡片化與收折) --- */
        .sidebar-wrapper {
            width: 320px; /* 加寬側邊欄以容納不換行的文字 */
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            background-color: #FFFFFF;
        }
        .sidebar-wrapper.collapsed {
            width: 0;
            padding: 0 !important;
            opacity: 0;
        }
        .sidebar-card {
            background-color: #ffffff;
            border: 1px solid rgba(7, 59, 76, 0.3); /* 淡淡的主色邊框 */
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(7, 59, 76, 0.08);
            transition: all 0.3s ease;
        }
        .nav-link-custom {
            color: var(--text-main);
            padding: 12px 20px;
            border-radius: 10px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            transition: all 0.3s;
            white-space: nowrap; /* 強制文字不換行 */
        }
        .nav-link-custom:hover {
            background-color: rgba(7, 59, 76, 0.1);
            color: var(--color-primary);
        }
        .nav-link-custom:hover .nav-radio {
            border-color: var(--color-primary);
        }
        .nav-link-custom.active {
            background-color: #E0FFFF; /* 更新：淡奶藍/極淺青色 */
            color: var(--text-dark);
            font-weight: bold;
        }
        .nav-radio {
            width: 14px; height: 14px; border-radius: 50%; border: 2px solid var(--color-accent2);
            display: inline-block; position: relative; margin-right: 12px; flex-shrink: 0;
        }
        .nav-link-custom.active .nav-radio {
            border-color: var(--color-primary);
        }
        .nav-link-custom.active .nav-radio::after {
            content: ''; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
            width: 6px; height: 6px; background-color: var(--color-primary); border-radius: 50%;
        }

        /* --- 內容區塊與卡片 --- */
        .main-content {
            background-color: #FFFFFF;
            overflow-y: auto;
        }
        .card-custom {
            background-color: #ffffff;
            border: 1px solid rgba(7, 59, 76, 0.2);
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            margin-bottom: 24px;
        }
        .card-header-custom {
            background-color: transparent;
            border-bottom: 1px solid var(--color-accent1);
            padding: 20px 24px;
            font-weight: bold;
            color: var(--text-dark);
            font-size: 1.25rem;
        }
        .recipe-highlight {
            background-color: #E0FFFF; /* 更新：淡奶藍/極淺青色 */
            border-radius: 16px;
            padding: 24px;
        }

        /* 新增：右上角使用者按鈕的懸停效果 */
        .user-profile-btn {
            transition: background-color 0.2s;
            cursor: pointer;
        }
        .user-profile-btn:hover {
            background-color: rgba(255, 255, 255, 0.15);
        }
        
        /* 隱藏數字輸入框箭頭 */
        input[type="number"]::-webkit-outer-spin-button,
        input[type="number"]::-webkit-inner-spin-button {
            -webkit-appearance: none; margin: 0;
        }
        input[type="number"] { 
            -moz-appearance: textfield; 
            appearance: textfield; 
        }

        .text-primary-custom { color: var(--color-primary) !important; }
        .bg-secondary-custom { background-color: var(--color-secondary) !important; }
    </style>
</head>
<body>

    <?php if (in_array($page, $public_pages)): ?>
        
        <!-- ================= 登入 / 註冊視圖 ================= -->
        <div class="d-flex vh-100 align-items-center justify-content-center auth-bg">
            <div class="container">
                <div class="row justify-content-center">
                    <div class="col-12 col-md-6 col-lg-5">
                        <div class="auth-card p-5">
                            <div class="text-center mb-4">
                                <div class="d-inline-flex align-items-center justify-content-center bg-secondary-custom rounded-circle mb-3" style="width: 70px; height: 70px;">
                                    <i class="ph-fill ph-heartbeat fs-1 text-primary-custom"></i>
                                </div>
                                <h2 class="fw-bold" style="color: var(--text-dark);">iHealth 智慧管家</h2>
                                <p class="text-muted">
                                    <?= $page === 'login' ? '歡迎回來，請登入您的帳號' : '建立您的專屬健康帳號' ?>
                                </p>
                            </div>

                            <?php if (!empty($auth_error)): ?>
                                <div class="alert alert-danger d-flex align-items-center" role="alert">
                                    <i class="ph-fill ph-warning-circle me-2 fs-5"></i>
                                    <div><?= htmlspecialchars($auth_error) ?></div>
                                </div>
                            <?php endif; ?>

                            <?php if ($page === 'login'): ?>
                                <form method="POST" action="index.php?page=login">
                                    <input type="hidden" name="action" value="login">
                                    <div class="mb-3">
                                        <label class="form-label text-muted small fw-bold">帳號</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="ph ph-user"></i></span>
                                            <input type="text" name="username" required placeholder="例如: admin" class="form-control border-left-0 pl-0">
                                        </div>
                                    </div>
                                    <div class="mb-4">
                                        <label class="form-label text-muted small fw-bold">密碼</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="ph ph-lock-key"></i></span>
                                            <input type="password" name="password" required placeholder="••••••••" class="form-control border-left-0 pl-0">
                                        </div>
                                    </div>
                                    <button type="submit" class="btn btn-custom-primary w-100 py-2 mb-3">登入系統</button>
                                    <div class="text-center text-muted small">
                                        還沒有帳號嗎？ <a href="?page=register" class="text-primary-custom fw-bold text-decoration-none">立即註冊</a>
                                    </div>
                                    <hr class="my-4" style="border-color: var(--color-accent2);">
                                    <div class="text-center text-black-50 small">預設測試帳號: admin / admin123</div>
                                </form>

                            <?php else: ?>
                                <form method="POST" action="index.php?page=register">
                                    <input type="hidden" name="action" value="register">
                                    <div class="mb-3">
                                        <label class="form-label text-muted small fw-bold">設定帳號</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="ph ph-user"></i></span>
                                            <input type="text" name="username" required class="form-control border-left-0">
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label text-muted small fw-bold">設定密碼</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="ph ph-lock-key"></i></span>
                                            <input type="password" name="password" required class="form-control border-left-0">
                                        </div>
                                    </div>
                                    <div class="mb-4">
                                        <label class="form-label text-muted small fw-bold">確認密碼</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="ph ph-check-circle"></i></span>
                                            <input type="password" name="confirm_password" required class="form-control border-left-0">
                                        </div>
                                    </div>
                                    <button type="submit" class="btn btn-dark w-100 py-2 mb-3">建立帳號</button>
                                    <div class="text-center text-muted small">
                                        已經有帳號了？ <a href="?page=login" class="text-primary-custom fw-bold text-decoration-none">返回登入</a>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <?php else: ?>

        <!-- ================= 系統主畫面 (Dashboard) ================= -->
        <div class="d-flex flex-column h-100">
            <!-- 頂部藍色 Header -->
            <header class="d-flex align-items-center px-4 py-3" style="background-color: var(--color-primary); color: white; box-shadow: 0 2px 10px rgba(7, 59, 76, 0.3); z-index: 1050;">
                <button id="sidebarToggle" class="btn border-0 text-white p-0 me-3 fs-3 d-flex align-items-center hover-opacity" style="opacity: 0.9; transition: opacity 0.2s;">
                    <i class="ph ph-list"></i>
                </button>
                <div class="fs-4 fw-bold d-flex align-items-center">
                    <i class="ph-fill ph-heartbeat me-2 fs-3"></i> iHealth 智慧管家
                </div>
                
                <!-- 使用者區塊下拉選單 (改為點擊展開) -->
                <div class="dropdown ms-auto">
                    <div class="d-flex align-items-center text-white p-2 rounded user-profile-btn" data-bs-toggle="dropdown" aria-expanded="false" role="button" style="background-color: <?= in_array($page, ['profile', 'settings']) ? 'rgba(255,255,255,0.2)' : 'transparent' ?>;">
                        <span class="d-none d-md-inline me-3 small fw-bold">Hi, <?= htmlspecialchars($p['name']) ?></span>
                        <div class="bg-white rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 36px; height: 36px; font-weight: bold; color: var(--color-primary);">
                            <?= mb_substr(htmlspecialchars($p['name']), 0, 1, 'UTF-8') ?>
                        </div>
                    </div>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="border-radius: 12px; margin-top: 8px;">
                        <li><a class="dropdown-item py-2 d-flex align-items-center <?= $page === 'profile' ? 'active bg-light text-dark fw-bold' : '' ?>" href="?page=profile"><i class="ph ph-user me-2 fs-5 text-muted"></i> 個人資料管理</a></li>
                        <li><a class="dropdown-item py-2 d-flex align-items-center <?= $page === 'settings' ? 'active bg-light text-dark fw-bold' : '' ?>" href="?page=settings"><i class="ph ph-gear me-2 fs-5 text-muted"></i> 系統設定</a></li>
                    </ul>
                </div>
            </header>

            <div class="d-flex flex-grow-1 overflow-hidden">
                <!-- 側邊欄 (卡片風格 & 可收折) -->
                <aside id="sidebar" class="sidebar-wrapper p-3">
                    <div class="sidebar-card h-100 d-flex flex-column py-4 px-2">
                        <div class="px-3 flex-grow-1 overflow-auto">
                            <div class="text-muted small fw-bold mb-3 px-2">導覽選單</div>
                            <nav class="nav flex-column">
                                <a href="?page=home" class="nav-link-custom text-decoration-none <?= $page === 'home' ? 'active' : '' ?>">
                                    <span class="nav-radio"></span><span class="fs-5 me-2">🏠</span> 首頁
                                </a>
                                <a href="?page=recipe" class="nav-link-custom text-decoration-none <?= $page === 'recipe' ? 'active' : '' ?>">
                                    <span class="nav-radio"></span><span class="fs-5 me-2">✨</span> iChef 食譜生成
                                </a>
                                <a href="?page=bookmarks" class="nav-link-custom text-decoration-none <?= $page === 'bookmarks' ? 'active' : '' ?>">
                                    <span class="nav-radio"></span><span class="fs-5 me-2">🔍</span> 我的收藏食譜
                                </a>
                            </nav>
                        </div>
                    </div>
                </aside>

                <!-- 右側主內容區 -->
                <main class="main-content flex-grow-1 p-4 p-md-5">
                    <div class="container-fluid max-w-custom">
                    
                    <?php if ($page === 'home'): ?>
                        <!-- ================= 首頁 ================= -->
                        <div class="mb-4">
                            <h2 class="fw-bold" style="color: var(--text-dark);">🏠 我的健康儀表板</h2>
                            <p class="text-muted">歡迎回來，<?= htmlspecialchars($p['name']) ?>！這裡是您的專屬健康與飲食追蹤中心。</p>
                        </div>
                        
                        <div class="row g-4">
                            <!-- 左側表單 -->
                            <div class="col-lg-5">
                                <div class="card card-custom h-100">
                                    <div class="card-header-custom d-flex align-items-center">
                                        <span class="fs-4 me-2">📅</span> 新增/編輯每日紀錄
                                    </div>
                                    <div class="card-body p-4">
                                        <form method="POST" action="index.php?page=home">
                                            <input type="hidden" name="action" value="save_daily_record">
                                            <div class="mb-3">
                                                <label class="form-label text-muted small fw-bold">選擇日期</label>
                                                <input type="date" name="date" value="<?= date('Y-m-d') ?>" required class="form-control bg-light border-0 py-2">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label text-muted small fw-bold">今日體重 (kg)</label>
                                                <input type="number" step="0.1" name="weight" value="0.00" class="form-control bg-light border-0 py-2">
                                            </div>
                                            <div class="mb-4">
                                                <label class="form-label text-muted small fw-bold">飲食狀況與筆記</label>
                                                <textarea name="notes" rows="4" placeholder="例如：今天吃了自己做的健康便當，沒有喝手搖飲！" class="form-control bg-light border-0 py-2" style="resize: none;"></textarea>
                                            </div>
                                            <button type="submit" class="btn btn-custom-primary w-100 py-2 d-flex align-items-center justify-content-center">
                                                <span class="me-2 fs-5">💾</span> 儲存本日紀錄
                                            </button>
                                            <?php if ($message === 'record_saved') echo '<div class="text-success text-center mt-3 small fw-bold">✅ 紀錄已成功儲存！</div>'; ?>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- 右側圖表與紀錄 -->
                            <div class="col-lg-7">
                                <div class="card card-custom mb-4">
                                    <div class="card-header-custom d-flex align-items-center">
                                        <span class="fs-4 me-2">📈</span> 體重變化趨勢
                                    </div>
                                    <div class="card-body p-4">
                                        <div style="height: 250px; width: 100%;">
                                            <canvas id="dashboardChart"></canvas>
                                        </div>
                                    </div>
                                </div>

                                <div class="card card-custom">
                                    <div class="card-header-custom d-flex align-items-center">
                                        <span class="fs-4 me-2">📝</span> 近期飲食日記
                                    </div>
                                    <div class="card-body p-4">
                                        <?php 
                                        $hasNotes = false;
                                        foreach ($health_records as $record) {
                                            if (!empty($record['notes'])) {
                                                $hasNotes = true;
                                                $displayDate = date('m/d', strtotime($record['date']));
                                                echo "<div class='border-start border-3 px-3 py-2 mb-3' style='border-color: var(--color-accent2) !important; background-color: var(--color-accent1); border-radius: 0 8px 8px 0;'>
                                                        <div class='small text-muted mb-1 fw-bold'>{$displayDate}</div>
                                                        <div class='text-dark'>".htmlspecialchars($record['notes'])."</div>
                                                      </div>";
                                            }
                                        }
                                        if (!$hasNotes) echo '<p class="text-muted">尚無飲食筆記。</p>'; 
                                        ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                    <?php elseif ($page === 'recipe'): ?>
                        <!-- ================= 食譜生成 ================= -->
                        <div class="mb-4">
                            <h2 class="fw-bold" style="color: var(--text-dark);">✨ iChef 智慧食譜生成</h2>
                            <p class="text-muted">輸入現有食材，AI 立即為您運算健康營養餐點。</p>
                        </div>
                        
                        <div class="row g-4">
                            <!-- 輸入區 -->
                            <div class="col-lg-5">
                                <div class="card card-custom h-100">
                                    <div class="card-body p-4">
                                        <form method="POST" action="index.php?page=recipe">
                                            <input type="hidden" name="action" value="generate_recipe">
                                            <div class="mb-4">
                                                <label class="form-label fw-bold">可用的食材清單</label>
                                                <textarea name="ingredients" rows="5" required placeholder="例如: 雞胸肉、番茄、雞蛋、高麗菜..." class="form-control bg-light border-0 p-3" style="resize: none;"></textarea>
                                            </div>
                                            <button type="submit" class="btn btn-dark w-100 py-3 fw-bold fs-6">✨ 生成專屬食譜</button>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <!-- 結果區 -->
                            <div class="col-lg-7">
                                <div class="recipe-highlight h-100 shadow-sm border border-white">
                                    <?php if (isset($_SESSION['current_recipe']) && isset($_SESSION['current_recipe']['title'])): ?>
                                        <?php $recipe = $_SESSION['current_recipe']; ?>
                                        <h3 class="fw-bold mb-4">🍽️ <?= htmlspecialchars($recipe['title']) ?></h3>
                                        
                                        <div class="row g-2 mb-4 text-center">
                                            <div class="col-6 col-md-3">
                                                <div class="bg-white p-3 rounded shadow-sm h-100">
                                                    <div class="small text-muted mb-1">卡路里</div>
                                                    <div class="fs-5 fw-bold text-dark"><?= $recipe['calories']??0 ?></div>
                                                </div>
                                            </div>
                                            <div class="col-6 col-md-3">
                                                <div class="bg-white p-3 rounded shadow-sm h-100">
                                                    <div class="small text-muted mb-1">蛋白質</div>
                                                    <div class="fs-5 fw-bold text-primary-custom"><?= $recipe['protein']??0 ?>g</div>
                                                </div>
                                            </div>
                                            <div class="col-6 col-md-3">
                                                <div class="bg-white p-3 rounded shadow-sm h-100">
                                                    <div class="small text-muted mb-1">碳水</div>
                                                    <div class="fs-5 fw-bold text-success"><?= $recipe['carbs']??0 ?>g</div>
                                                </div>
                                            </div>
                                            <div class="col-6 col-md-3">
                                                <div class="bg-white p-3 rounded shadow-sm h-100">
                                                    <div class="small text-muted mb-1">脂肪</div>
                                                    <div class="fs-5 fw-bold text-danger"><?= $recipe['fat']??0 ?>g</div>
                                                </div>
                                            </div>
                                        </div>

                                        <h5 class="fw-bold mb-3">步驟：</h5>
                                        <ol class="list-group list-group-numbered list-group-flush mb-4 rounded">
                                            <?php foreach ($recipe['steps'] as $step): ?>
                                                <li class="list-group-item bg-transparent border-0 py-2 ps-0"><?= htmlspecialchars($step) ?></li>
                                            <?php endforeach; ?>
                                        </ol>

                                        <form method="POST" action="index.php?page=recipe">
                                            <input type="hidden" name="action" value="save_generated_recipe">
                                            <button type="submit" class="btn btn-custom-primary w-100 py-2 d-flex justify-content-center align-items-center">
                                                <i class="ph-bold ph-bookmark-simple fs-5 me-2"></i> 儲存至我的收藏
                                            </button>
                                        </form>
                                        <?php if ($message === 'recipe_saved') echo '<div class="text-success text-center mt-3 fw-bold">✅ 食譜已成功儲存！請至左側「我的收藏食譜」查看。</div>'; ?>
                                    
                                    <?php else: ?>
                                        <div class="d-flex flex-column justify-content-center align-items-center h-100 text-black-50 py-5">
                                            <i class="ph-thin ph-bowl-food" style="font-size: 5rem;"></i>
                                            <p class="mt-3">等待生成中...</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                    <?php elseif ($page === 'bookmarks'): ?>
                        <!-- ================= 收藏食譜 ================= -->
                        <div class="mb-4">
                            <h2 class="fw-bold" style="color: var(--text-dark);">🔍 我的收藏食譜</h2>
                        </div>

                        <div class="mb-4">
                            <input type="text" placeholder="搜尋收藏... (例如：番茄、雞肉)" class="form-control form-control-lg bg-white border-0 shadow-sm rounded-pill px-4 py-2">
                        </div>

                        <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
                            <?php if (!empty($saved_recipes)): ?>
                                <?php foreach ($saved_recipes as $recipe): ?>
                                    <div class="col">
                                        <div class="card card-custom h-100 d-flex flex-column">
                                            <div class="card-body p-4 d-flex flex-column">
                                                <h5 class="fw-bold mb-2">🍴 <?= htmlspecialchars($recipe['title']) ?></h5>
                                                <p class="text-muted small mb-4">
                                                    <span class="badge bg-secondary-custom text-dark me-1"><?= htmlspecialchars($recipe['category']) ?></span> 
                                                    ⏳ <?= htmlspecialchars($recipe['time']) ?>
                                                </p>
                                                
                                                <div class="mt-auto">
                                                    <button type="button" onclick="toggleSteps(this)" class="btn btn-outline-secondary w-100 mb-3 d-flex justify-content-between align-items-center" style="border-color: var(--color-accent2);">
                                                        <span>查看步驟</span><i class="ph-bold ph-caret-down toggle-icon"></i>
                                                    </button>
                                                    
                                                    <div class="d-none mb-3 bg-light p-3 rounded small">
                                                        <ol class="ps-3 mb-0">
                                                            <?php if (!empty($recipe['steps'])): ?>
                                                                <?php foreach ($recipe['steps'] as $step): ?>
                                                                    <li class="mb-1"><?= htmlspecialchars($step) ?></li>
                                                                <?php endforeach; ?>
                                                            <?php else: ?>
                                                                <li>無步驟詳細資料。</li>
                                                            <?php endif; ?>
                                                        </ol>
                                                    </div>

                                                    <form method="POST" action="index.php?page=bookmarks" onsubmit="return confirm('確定要刪除此收藏嗎？');">
                                                        <input type="hidden" name="action" value="delete_recipe">
                                                        <input type="hidden" name="recipe_id" value="<?= $recipe['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-light text-danger w-100">🗑️ 刪除</button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="col-12 text-center text-muted py-5">
                                    <p>目前還沒有任何收藏的食譜喔！</p>
                                </div>
                            <?php endif; ?>
                        </div>

                    <?php elseif ($page === 'profile'): ?>
                        <!-- ================= 個人資料與營養設定 ================= -->
                        <div class="mb-4">
                            <h2 class="fw-bold" style="color: var(--text-dark);">👤 個人資料與營養設定</h2>
                            <p class="text-muted">設定您的身體數值，iChef 將為您計算專屬的 TDEE 與營養目標！</p>
                        </div>

                        <div class="card card-custom mb-5">
                            <div class="card-body p-4 p-md-5">
                                <form method="POST" action="index.php?page=profile">
                                    <input type="hidden" name="action" value="save_profile">
                                    
                                    <!-- 【第一排】 性別 / 生日 / 活動量 -->
                                    <div class="row g-4 mb-4">
                                        <div class="col-md-4">
                                            <label class="form-label text-muted small fw-bold">性別</label>
                                            <select name="gender" class="form-select bg-light border-0 py-2">
                                                <option value="female" <?= ($p['gender'] ?? 'female') === 'female' ? 'selected' : '' ?>>女</option>
                                                <option value="male" <?= ($p['gender'] ?? 'female') === 'male' ? 'selected' : '' ?>>男</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label text-muted small fw-bold d-flex justify-content-between">
                                                <span>生日</span>
                                                <span class="text-primary-custom">(目前年齡: <?= $age ?> 歲)</span>
                                            </label>
                                            <input type="date" name="birthday" value="<?= htmlspecialchars($p['birthday'] ?? '2000-01-01') ?>" required class="form-control bg-light border-0 py-2">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label text-muted small fw-bold">活動量</label>
                                            <select name="activity_level" class="form-select bg-light border-0 py-2">
                                                <option value="1.2" <?= ($p['activity_level'] ?? '1.2') == '1.2' ? 'selected' : '' ?>>久坐</option>
                                                <option value="1.375" <?= ($p['activity_level'] ?? '1.2') == '1.375' ? 'selected' : '' ?>>輕度活動</option>
                                                <option value="1.55" <?= ($p['activity_level'] ?? '1.2') == '1.55' ? 'selected' : '' ?>>中度活動</option>
                                                <option value="1.725" <?= ($p['activity_level'] ?? '1.2') == '1.725' ? 'selected' : '' ?>>高度活動</option>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- 【第二排】 身高 / 體重 / 飲食偏好 -->
                                    <div class="row g-4 mb-4">
                                        <div class="col-md-4">
                                            <label class="form-label text-muted small fw-bold">身高 (cm)</label>
                                            <div class="input-group bg-light rounded px-2 border-0">
                                                <input type="number" step="0.1" name="height" id="input_height" value="<?= number_format($p['height'] ?? 160, 2) ?>" class="form-control bg-transparent border-0 px-2 py-2">
                                                <button type="button" class="btn border-0 text-muted fs-5 py-0 px-2 fw-bold" onclick="adjustValue('input_height', -1)">-</button>
                                                <button type="button" class="btn border-0 text-muted fs-5 py-0 px-2 fw-bold" onclick="adjustValue('input_height', 1)">+</button>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label text-muted small fw-bold">體重 (kg)</label>
                                            <div class="input-group bg-light rounded px-2 border-0">
                                                <input type="number" step="0.1" name="weight" id="input_weight" value="<?= number_format($p['weight'] ?? 50, 2) ?>" class="form-control bg-transparent border-0 px-2 py-2">
                                                <button type="button" class="btn border-0 text-muted fs-5 py-0 px-2 fw-bold" onclick="adjustValue('input_weight', -1)">-</button>
                                                <button type="button" class="btn border-0 text-muted fs-5 py-0 px-2 fw-bold" onclick="adjustValue('input_weight', 1)">+</button>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label text-muted small fw-bold">飲食偏好</label>
                                            <select name="diet_habit" class="form-select bg-light border-0 py-2">
                                                <option value="無特殊偏好" <?= ($p['diet_habit'] ?? '無特殊偏好') === '無特殊偏好' ? 'selected' : '' ?>>無特殊偏好</option>
                                                <option value="減脂" <?= ($p['diet_habit'] ?? '無特殊偏好') === '減脂' ? 'selected' : '' ?>>減脂</option>
                                                <option value="增肌" <?= ($p['diet_habit'] ?? '無特殊偏好') === '增肌' ? 'selected' : '' ?>>增肌</option>
                                                <option value="蛋奶素" <?= ($p['diet_habit'] ?? '無特殊偏好') === '蛋奶素' ? 'selected' : '' ?>>蛋奶素</option>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- 【第三排】 過敏原 -->
                                    <div class="row g-4 mb-4">
                                        <div class="col-md-12">
                                            <label class="form-label text-muted small fw-bold">過敏原 (用逗號隔開)</label>
                                            <input type="text" name="allergies" value="<?= htmlspecialchars($p['allergies'] ?? '') ?>" class="form-control bg-light border-0 py-2">
                                        </div>
                                    </div>

                                    <div class="mt-4">
                                        <button type="submit" class="btn btn-custom-primary px-4 py-2">
                                            <span class="me-2">💾</span> 儲存並計算目標
                                        </button>
                                        <?php if ($message === 'profile_saved') echo '<span class="text-success ms-3 fw-bold">✅ 資料已更新！</span>'; ?>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <h3 class="fw-bold mb-4 d-flex align-items-center text-dark"><span class="fs-3 me-2">📊</span> 您的專屬營養目標</h3>
                        <div class="row g-4">
                            <div class="col-6 col-md-3">
                                <div class="bg-white rounded-3 p-4 shadow-sm border-start border-4" style="border-color: var(--color-primary) !important;">
                                    <p class="text-muted small fw-bold mb-2">基礎代謝 (BMR)</p>
                                    <div class="d-flex align-items-baseline"><span class="fs-2 fw-bold text-dark me-1"><?= round($bmr) ?></span><span class="text-muted">kcal</span></div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="bg-white rounded-3 p-4 shadow-sm border-start border-4" style="border-color: var(--color-accent2) !important;">
                                    <p class="text-muted small fw-bold mb-2">總消耗 (TDEE)</p>
                                    <div class="d-flex align-items-baseline"><span class="fs-2 fw-bold text-dark me-1"><?= round($tdee) ?></span><span class="text-muted">kcal</span></div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="bg-white rounded-3 p-4 shadow-sm border-start border-4" style="border-color: var(--color-secondary) !important;">
                                    <p class="text-muted small fw-bold mb-2">建議總熱量</p>
                                    <div class="d-flex align-items-baseline"><span class="fs-2 fw-bold text-dark me-1"><?= round($tdee) ?></span><span class="text-muted">kcal</span></div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="bg-white rounded-3 p-4 shadow-sm border-start border-4" style="border-color: #ffb3b3 !important;">
                                    <p class="text-muted small fw-bold mb-2">蛋白質建議</p>
                                    <div class="d-flex align-items-baseline"><span class="fs-2 fw-bold text-dark me-1"><?= round($protein_suggestion) ?></span><span class="text-muted">g</span></div>
                                </div>
                            </div>
                        </div>

                    <?php elseif ($page === 'settings'): ?>
                        <!-- ================= 系統設定 ================= -->
                        <div class="mb-4">
                            <h2 class="fw-bold" style="color: var(--text-dark);">⚙️ 系統設定</h2>
                            <p class="text-muted">管理您的帳號安全與系統狀態。</p>
                        </div>

                        <div class="row g-4">
                            <!-- 修改密碼卡片 -->
                            <div class="col-md-6">
                                <div class="card card-custom h-100 mb-0">
                                    <div class="card-header-custom d-flex align-items-center">
                                        <span class="fs-4 me-2">🔑</span> 修改密碼
                                    </div>
                                    <div class="card-body p-4">
                                        <form method="POST" action="index.php?page=settings">
                                            <input type="hidden" name="action" value="change_password">
                                            <div class="mb-3">
                                                <label class="form-label text-muted small fw-bold">目前密碼</label>
                                                <input type="password" name="old_password" required class="form-control bg-light border-0 py-2">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label text-muted small fw-bold">新密碼</label>
                                                <input type="password" name="new_password" required class="form-control bg-light border-0 py-2">
                                            </div>
                                            <div class="mb-4">
                                                <label class="form-label text-muted small fw-bold">確認新密碼</label>
                                                <input type="password" name="confirm_password" required class="form-control bg-light border-0 py-2">
                                            </div>
                                            <button type="submit" class="btn btn-custom-primary w-100 py-2 d-flex align-items-center justify-content-center">
                                                <span class="me-2 fs-5">💾</span> 更新密碼
                                            </button>
                                            <?php if ($message === 'password_changed') echo '<div class="text-success text-center mt-3 small fw-bold">✅ 密碼更新成功！</div>'; ?>
                                            <?php if ($message === 'password_error') echo '<div class="text-danger text-center mt-3 small fw-bold">❌ 原密碼輸入錯誤！</div>'; ?>
                                            <?php if ($message === 'password_mismatch') echo '<div class="text-danger text-center mt-3 small fw-bold">❌ 兩次新密碼不一致！</div>'; ?>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <!-- 帳號管理卡片 -->
                            <div class="col-md-6">
                                <div class="card card-custom h-100 mb-0">
                                    <div class="card-header-custom d-flex align-items-center">
                                        <span class="fs-4 me-2">🛡️</span> 帳號管理
                                    </div>
                                    <div class="card-body p-4 d-flex flex-column">
                                        <div class="mb-auto">
                                            <p class="text-muted mb-4">登出系統或永久刪除您的帳號資料。刪除帳號後將無法復原，請謹慎操作。</p>
                                            
                                            <a href="?action=logout" onclick="return confirm('確定要登出系統嗎？');" class="btn btn-outline-secondary w-100 d-flex align-items-center justify-content-center mb-3 fw-bold">
                                                <span class="me-2 fs-5">🚪</span> 登出系統
                                            </a>
                                        </div>

                                        <div class="pt-3 border-top mt-4" style="border-color: var(--color-accent1) !important;">
                                            <form method="POST" action="index.php" onsubmit="return confirm('⚠️ 警告：這將會永久刪除您的帳號與所有資料，且無法復原！確定要刪除嗎？');">
                                                <input type="hidden" name="action" value="delete_account">
                                                <button type="submit" class="btn btn-outline-danger w-100 d-flex align-items-center justify-content-center fw-bold">
                                                    <span class="me-2 fs-5">🗑️</span> 永久刪除帳號
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    <?php endif; ?>
                </div>
            </main>
        </div>
    <?php endif; ?>

    <!-- 引入 Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // 收合食譜步驟
        function toggleSteps(btn) {
            const stepsDiv = btn.nextElementSibling;
            const icon = btn.querySelector('.toggle-icon');
            if(stepsDiv.classList.contains('d-none')) {
                stepsDiv.classList.remove('d-none');
                icon.classList.remove('ph-caret-down');
                icon.classList.add('ph-caret-up');
            } else {
                stepsDiv.classList.add('d-none');
                icon.classList.remove('ph-caret-up');
                icon.classList.add('ph-caret-down');
            }
        }

        // 數字增減微調功能
        function adjustValue(inputId, amount) {
            const input = document.getElementById(inputId);
            if(input) {
                let currentVal = parseFloat(input.value) || 0;
                input.value = (currentVal + amount).toFixed(input.step.includes('.') ? 2 : 0);
            }
        }

        // 側邊欄收折控制
        document.addEventListener('DOMContentLoaded', function() {
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebar = document.getElementById('sidebar');
            if (sidebarToggle && sidebar) {
                sidebarToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('collapsed');
                });
            }
        });

        // 圖表繪製
        <?php if ($page === 'home' && !in_array($page, $public_pages)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('dashboardChart');
            if (!ctx) return;
            
            const labels = <?php echo $chart_labels_json; ?>;
            const weightData = <?php echo $chart_weights_json; ?>;

            new Chart(ctx.getContext('2d'), {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: '體重 (kg)',
                        data: weightData,
                        borderColor: '#073B4C',      // 使用您的主色
                        backgroundColor: 'rgba(7, 59, 76, 0.1)', // 主色半透明
                        borderWidth: 3,
                        pointBackgroundColor: '#073B4C',
                        pointBorderColor: '#ffffff',
                        pointHoverBackgroundColor: '#ffffff',
                        pointHoverBorderColor: '#073B4C',
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        tension: 0.3,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(255, 255, 255, 0.95)',
                            titleColor: '#212529',
                            bodyColor: '#212529',
                            borderColor: '#AAC4F5',
                            borderWidth: 1,
                            padding: 12,
                            displayColors: false,
                            titleFont: {size: 13, family: 'Segoe UI'},
                            bodyFont: {size: 14, weight: 'bold', family: 'Segoe UI'}
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: { color: '#6c757d', font: { size: 12 } },
                            border: { display: false }
                        },
                        y: {
                            min: 0,
                            max: Math.max(...weightData, 55) + 5, // 動態計算上限
                            grid: { color: '#FFF8DE', drawBorder: false }, // 使用輔助色作為網格
                            ticks: { stepSize: 5, color: '#6c757d', font: { size: 12 }, padding: 10 },
                            border: { display: false }
                        }
                    }
                }
            });
        });
        <?php endif; ?>
    </script>
</body>
</html>