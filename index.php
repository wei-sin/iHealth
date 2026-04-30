<?php
// 載入 .env 檔案設定 (保留原有邏輯以確保 API 正常運作)
$envFilePath = __DIR__ . '/.env';
if (file_exists($envFilePath)) {
    $lines = file($envFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, '#') === 0 || strpos($line, '=') === false) continue;
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        putenv(sprintf('%s=%s', $name, $value));
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

session_start();

// --- 初始化模擬的「使用者資料庫」 ---
// 預設提供一組測試帳號：admin / admin123
if (!isset($_SESSION['users_db'])) {
    $_SESSION['users_db'] = [
        'admin' => 'admin123'
    ];
}

// --- 處理登出 ---
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    unset($_SESSION['logged_in_user']);
    header("Location: index.php?page=login");
    exit;
}

// --- 處理登入與註冊表單提交 ---
$auth_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        $username = trim(htmlspecialchars($_POST['username'] ?? ''));
        $password = $_POST['password'] ?? '';

        if ($action === 'login') {
            if (isset($_SESSION['users_db'][$username]) && $_SESSION['users_db'][$username] === $password) {
                $_SESSION['logged_in_user'] = $username;
                $_SESSION['user_profile']['name'] = $username; // 同步儀表板名稱
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
            } elseif (isset($_SESSION['users_db'][$username])) {
                $auth_error = '此帳號已被註冊，請換一個或直接登入！';
            } else {
                // 註冊成功，寫入模擬資料庫並直接登入
                $_SESSION['users_db'][$username] = $password;
                $_SESSION['logged_in_user'] = $username;
                $_SESSION['user_profile']['name'] = $username;
                header("Location: index.php?page=home");
                exit;
            }
        }
    }
}

// --- 取得當前頁面路由 ---
$page = $_GET['page'] ?? 'home';

// --- 頁面權限控管 (Access Control) ---
// 如果未登入，且試圖訪問非公開頁面，強制導向登入頁
$public_pages = ['login', 'register'];
if (!isset($_SESSION['logged_in_user']) && !in_array($page, $public_pages)) {
    header("Location: index.php?page=login");
    exit;
}
// 如果已登入，但試圖訪問登入頁，強制導向首頁
if (isset($_SESSION['logged_in_user']) && in_array($page, $public_pages)) {
    header("Location: index.php?page=home");
    exit;
}


// --- 1. 模擬資料庫初始化 (健康數據) ---
if (!isset($_SESSION['health_records'])) {
    $_SESSION['health_records'] = [
        ['id' => 1, 'date' => date('Y-m-d', strtotime('-1 days')), 'weight' => 52.5, 'notes' => '今天吃了自己做的健康便當，沒有喝手搖飲！'],
        ['id' => 2, 'date' => date('Y-m-d', strtotime('-3 days')), 'weight' => 51.8, 'notes' => '跟朋友去吃火鍋，稍微有點罪惡感...'],
        ['id' => 3, 'date' => date('Y-m-d', strtotime('-5 days')), 'weight' => 51.5, 'notes' => '持續控制碳水攝取。'],
    ];
}

$default_profile = [
    'name' => $_SESSION['logged_in_user'] ?? 'User',
    'gender' => 'female',
    'age' => 23,
    'height' => 168.0,
    'weight' => 58.0,
    'activity_level' => '1.375',
    'diet_habit' => '無特殊偏好',
    'allergies' => ''
];

if (!isset($_SESSION['user_profile'])) {
    $_SESSION['user_profile'] = $default_profile;
} else {
    $_SESSION['user_profile'] = array_merge($default_profile, $_SESSION['user_profile']);
}

if (!isset($_SESSION['saved_recipes'])) {
    $_SESSION['saved_recipes'] = [
        [
            'id' => time(),
            'title' => '瞬享奶粉軟糖',
            'category' => '點心',
            'time' => '45 分鐘',
            'steps' => ['準備奶粉與少許水...', '均勻攪拌至糊狀...', '放入冰箱冷藏成型。']
        ]
    ];
}

// --- 2. 處理儀表板內的表單提交 ---
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 新增每日紀錄
    if (isset($_POST['action']) && $_POST['action'] === 'save_daily_record') {
        $inputDate = htmlspecialchars($_POST['date']);
        $inputWeight = filter_input(INPUT_POST, 'weight', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        $inputNotes = htmlspecialchars($_POST['notes']);

        $recordExists = false;
        foreach ($_SESSION['health_records'] as &$record) {
            if ($record['date'] === $inputDate) {
                $record['weight'] = $inputWeight;
                $record['notes'] = $inputNotes;
                $recordExists = true;
                break;
            }
        }
        
        if (!$recordExists) {
            $newRecord = [
                'id' => time(),
                'date' => $inputDate,
                'weight' => $inputWeight,
                'notes' => $inputNotes
            ];
            array_unshift($_SESSION['health_records'], $newRecord);
            usort($_SESSION['health_records'], function($a, $b) {
                return strtotime($b['date']) - strtotime($a['date']);
            });
        }
        $message = 'record_saved';
    }

    // 更新個人資料
    if (isset($_POST['action']) && $_POST['action'] === 'save_profile') {
        $_SESSION['user_profile'] = [
            'name' => $_SESSION['user_profile']['name'],
            'gender' => htmlspecialchars($_POST['gender']),
            'age' => filter_input(INPUT_POST, 'age', FILTER_SANITIZE_NUMBER_INT),
            'height' => filter_input(INPUT_POST, 'height', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION),
            'weight' => filter_input(INPUT_POST, 'weight', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION),
            'activity_level' => htmlspecialchars($_POST['activity_level']),
            'diet_habit' => htmlspecialchars($_POST['diet_habit']),
            'allergies' => htmlspecialchars($_POST['allergies'])
        ];
        $message = 'profile_saved';
    }

    // 刪除食譜
    if (isset($_POST['action']) && $_POST['action'] === 'delete_recipe') {
        $delete_id = $_POST['recipe_id'];
        foreach ($_SESSION['saved_recipes'] as $key => $recipe) {
            if ($recipe['id'] == $delete_id) {
                unset($_SESSION['saved_recipes'][$key]);
                break;
            }
        }
        $_SESSION['saved_recipes'] = array_values($_SESSION['saved_recipes']);
        $message = 'recipe_deleted';
    }

    // 儲存生成的食譜至收藏 (這段之前漏掉了，請補上)
    if (isset($_POST['action']) && $_POST['action'] === 'save_generated_recipe') {
        if (isset($_SESSION['current_recipe'])) {
            $newSavedRecipe = [
                'id' => time(),
                'title' => $_SESSION['current_recipe']['title'],
                'category' => 'AI 專屬生成',
                'time' => '約 30 分鐘', 
                'steps' => $_SESSION['current_recipe']['steps']
            ];
            // 加到收藏清單的最前面
            array_unshift($_SESSION['saved_recipes'], $newSavedRecipe);
            $message = 'recipe_saved';
        }
    }

    // 產生食譜
    if (isset($_POST['action']) && $_POST['action'] === 'generate_recipe') {
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

// --- 3. 準備圖表與個人資料計算 ---
$records_for_chart = array_reverse($_SESSION['health_records'] ?? []); 
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

$p = $_SESSION['user_profile'];
$bmr = 0;
if ($p['gender'] === 'male') {
    $bmr = (10 * $p['weight']) + (6.25 * $p['height']) - (5 * $p['age']) + 5;
} else {
    $bmr = (10 * $p['weight']) + (6.25 * $p['height']) - (5 * $p['age']) - 161;
}
$tdee = $bmr * (float)$p['activity_level'];
$protein_suggestion = $p['weight'] * 2; 
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>iHealth 智慧生活管家</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        
        .nav-item { position: relative; }
        .nav-item input[type="radio"] { display: none; }
        .nav-radio {
            width: 14px; height: 14px; border-radius: 50%; border: 1px solid #cbd5e1;
            display: inline-block; position: relative; margin-right: 8px; flex-shrink: 0;
        }
        .nav-item.active .nav-radio { border-color: #ef4444; }
        .nav-item.active .nav-radio::after {
            content: ''; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
            width: 6px; height: 6px; background-color: #ef4444; border-radius: 50%;
        }

        input[type="number"]::-webkit-outer-spin-button,
        input[type="number"]::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        input[type="number"] {
            -moz-appearance: textfield;
            appearance: textfield;
        }
    </style>
</head>
<body class="bg-white text-slate-800 antialiased h-screen flex overflow-hidden">

    <?php if (in_array($page, $public_pages)): ?>
        
        <!-- ================= 登入 / 註冊視圖 ================= -->
        <div class="w-full h-full flex items-center justify-center bg-slate-50 relative">
            <!-- 裝飾背景 -->
            <div class="absolute top-0 left-0 w-full h-full overflow-hidden pointer-events-none">
                <div class="absolute -top-[20%] -right-[10%] w-[50%] h-[50%] rounded-full bg-blue-100/50 blur-[80px]"></div>
                <div class="absolute -bottom-[20%] -left-[10%] w-[40%] h-[40%] rounded-full bg-emerald-100/50 blur-[80px]"></div>
            </div>

            <div class="w-full max-w-md bg-white rounded-2xl shadow-xl border border-slate-100 p-8 relative z-10">
                <div class="text-center mb-8">
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-blue-50 text-blue-600 mb-4">
                        <i class="ph-fill ph-heartbeat text-3xl"></i>
                    </div>
                    <h1 class="text-2xl font-bold text-slate-800">iHealth 智慧管家</h1>
                    <p class="text-sm text-slate-500 mt-2">
                        <?= $page === 'login' ? '歡迎回來，請登入您的帳號' : '建立您的專屬健康帳號' ?>
                    </p>
                </div>

                <?php if (!empty($auth_error)): ?>
                    <div class="mb-4 p-3 bg-red-50 text-red-600 text-sm rounded-lg border border-red-100 flex items-center">
                        <i class="ph-fill ph-warning-circle mr-2"></i> <?= htmlspecialchars($auth_error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($page === 'login'): ?>
                    <!-- 登入表單 -->
                    <form method="POST" action="index.php?page=login" class="space-y-5">
                        <input type="hidden" name="action" value="login">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">帳號</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                                    <i class="ph ph-user"></i>
                                </div>
                                <input type="text" name="username" required placeholder="例如: admin" class="w-full pl-10 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-lg text-sm focus:bg-white focus:ring-2 focus:ring-blue-100 focus:border-blue-400 outline-none transition">
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">密碼</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                                    <i class="ph ph-lock-key"></i>
                                </div>
                                <input type="password" name="password" required placeholder="••••••••" class="w-full pl-10 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-lg text-sm focus:bg-white focus:ring-2 focus:ring-blue-100 focus:border-blue-400 outline-none transition">
                            </div>
                        </div>
                        <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-3 rounded-lg transition shadow-md shadow-blue-500/20">
                            登入系統
                        </button>
                        <p class="text-center text-sm text-slate-500 mt-4">
                            還沒有帳號嗎？ <a href="?page=register" class="text-blue-600 font-medium hover:underline">立即註冊</a>
                        </p>
                        <!-- 開發提示 -->
                        <p class="text-center text-xs text-slate-400 mt-6 border-t pt-4">預設測試帳號: admin / admin123</p>
                    </form>

                <?php else: ?>
                    <!-- 註冊表單 -->
                    <form method="POST" action="index.php?page=register" class="space-y-4">
                        <input type="hidden" name="action" value="register">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">設定帳號</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                                    <i class="ph ph-user"></i>
                                </div>
                                <input type="text" name="username" required class="w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm focus:bg-white focus:ring-2 focus:ring-blue-100 focus:border-blue-400 outline-none transition">
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">設定密碼</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                                    <i class="ph ph-lock-key"></i>
                                </div>
                                <input type="password" name="password" required class="w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm focus:bg-white focus:ring-2 focus:ring-blue-100 focus:border-blue-400 outline-none transition">
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">確認密碼</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                                    <i class="ph ph-check-circle"></i>
                                </div>
                                <input type="password" name="confirm_password" required class="w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm focus:bg-white focus:ring-2 focus:ring-blue-100 focus:border-blue-400 outline-none transition">
                            </div>
                        </div>
                        <button type="submit" class="w-full bg-slate-800 hover:bg-slate-900 text-white font-medium py-3 rounded-lg transition shadow-md mt-2">
                            建立帳號
                        </button>
                        <p class="text-center text-sm text-slate-500 mt-4">
                            已經有帳號了？ <a href="?page=login" class="text-blue-600 font-medium hover:underline">返回登入</a>
                        </p>
                    </form>
                <?php endif; ?>
            </div>
        </div>

    <?php else: ?>

        <!-- ================= 系統主畫面 (Dashboard) ================= -->
        <!-- 左側導覽列 (Sidebar) -->
        <aside class="w-64 bg-slate-50 border-r border-slate-200 flex flex-col h-full flex-shrink-0">
            <div class="p-8 pb-4">
                <h2 class="text-xl font-medium text-slate-900 truncate">Hi, <?= htmlspecialchars($_SESSION['user_profile']['name']) ?></h2>
            </div>
            
            <div class="px-6 mb-2">
                <div class="h-px bg-slate-200 w-full"></div>
            </div>

            <div class="flex-1 px-6 py-4 overflow-y-auto no-scrollbar">
                <p class="text-xs text-slate-500 mb-4 font-medium tracking-wider">導覽選單</p>
                <nav class="space-y-3">
                    <a href="?page=home" class="nav-item flex items-center text-sm <?= $page === 'home' ? 'active font-bold text-slate-800' : 'text-slate-600 hover:text-slate-800' ?>">
                        <span class="nav-radio"></span>
                        <span class="text-lg mr-2">🏠</span> 首頁
                    </a>
                    <a href="?page=recipe" class="nav-item flex items-center text-sm <?= $page === 'recipe' ? 'active font-bold text-slate-800' : 'text-slate-600 hover:text-slate-800' ?>">
                        <span class="nav-radio"></span>
                        <span class="text-lg mr-2">✨</span> iChef 食譜生成
                    </a>
                    <a href="?page=bookmarks" class="nav-item flex items-center text-sm <?= $page === 'bookmarks' ? 'active font-bold text-slate-800' : 'text-slate-600 hover:text-slate-800' ?>">
                        <span class="nav-radio"></span>
                        <span class="text-lg mr-2">🔍</span> 我的收藏食譜
                    </a>
                    <a href="?page=profile" class="nav-item flex items-center text-sm <?= $page === 'profile' ? 'active font-bold text-slate-800' : 'text-slate-600 hover:text-slate-800' ?>">
                        <span class="nav-radio"></span>
                        <span class="text-lg mr-2">👤</span> 個人資料
                    </a>
                </nav>
            </div>

            <div class="px-6 mb-4">
                <div class="h-px bg-slate-200 w-full"></div>
            </div>

            <div class="p-6 pt-2">
                <!-- 實裝登出按鈕，連到 action=logout -->
                <a href="?action=logout" onclick="return confirm('確定要登出系統嗎？');" class="flex items-center gap-2 border border-slate-300 rounded-md py-1.5 px-4 bg-white hover:bg-slate-50 transition shadow-sm text-sm font-medium text-slate-700 relative overflow-hidden block w-full text-center">
                    <div class="absolute left-0 top-0 bottom-0 w-1 bg-amber-700"></div>
                    <span class="text-amber-700 ml-1">🚪</span> 登出
                </a>
            </div>
        </aside>

        <!-- 右側主內容區 (Main Content) -->
        <main class="flex-1 h-full overflow-y-auto bg-white relative">
            <div class="absolute top-4 right-6 flex items-center gap-4 text-sm text-slate-600">
                <span>Deploy</span>
                <i class="ph-bold ph-dots-three-vertical cursor-pointer"></i>
            </div>

            <div class="max-w-6xl mx-auto px-10 py-12">
                
                <?php if ($page === 'home'): ?>
                    <!-- ================= 首頁儀表板視圖 ================= -->
                    <div class="mb-8">
                        <h1 class="text-4xl font-bold text-slate-800 flex items-center gap-3">
                            🏠 我的健康儀表板
                            <i class="ph ph-link text-slate-400 text-lg cursor-pointer"></i>
                        </h1>
                        <p class="text-slate-600 mt-4">歡迎回來，<?= htmlspecialchars($_SESSION['user_profile']['name']) ?>！這裡是您的專屬健康與飲食追蹤中心。</p>
                    </div>
                    <div class="border-b border-slate-200 w-full mb-10"></div>
                    <div class="grid grid-cols-1 lg:grid-cols-12 gap-12">
                        <div class="lg:col-span-5">
                            <h2 class="text-2xl font-bold text-slate-800 mb-6 flex items-center gap-2">📅 新增/編輯每日紀錄</h2>
                            <form method="POST" action="index.php?page=home" class="space-y-5">
                                <input type="hidden" name="action" value="save_daily_record">
                                <div>
                                    <label class="block text-sm text-slate-700 mb-2">選擇日期</label>
                                    <input type="date" name="date" value="<?= date('Y-m-d') ?>" required class="w-full bg-slate-100 border-none rounded-md px-4 py-3 text-slate-700 focus:ring-2 focus:ring-blue-100 outline-none transition">
                                </div>
                                <div class="relative">
                                    <div class="flex justify-between items-end mb-2">
                                        <label class="block text-sm text-slate-700">今日體重 (kg)</label>
                                        <i class="ph ph-question text-slate-400 cursor-pointer"></i>
                                    </div>
                                    <div class="flex items-center w-full bg-slate-100 rounded-md overflow-hidden focus-within:ring-2 focus-within:ring-blue-100 transition">
                                        <input type="number" step="0.1" name="weight" value="0.00" class="w-full bg-transparent border-none px-4 py-3 text-slate-700 outline-none text-left appearance-none">
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm text-slate-700 mb-2">飲食狀況與筆記</label>
                                    <textarea name="notes" rows="4" placeholder="例如：今天吃了自己做的健康便當，沒有喝手搖飲！" class="w-full bg-slate-100 border-none rounded-md px-4 py-3 text-slate-700 focus:ring-2 focus:ring-blue-100 outline-none transition resize-none"></textarea>
                                </div>
                                <button type="submit" class="w-full bg-white border border-slate-300 rounded-md py-3 px-4 flex justify-center items-center gap-2 hover:bg-slate-50 transition shadow-sm text-slate-700 font-medium">
                                    <span class="text-purple-700 text-lg">💾</span> 儲存本日紀錄
                                </button>
                                <?php if ($message === 'record_saved') echo '<p class="text-green-600 text-sm text-center mt-2">✅ 紀錄已成功儲存！</p>'; ?>
                            </form>
                        </div>
                        <div class="lg:col-span-7 flex flex-col gap-10">
                            <div>
                                <h2 class="text-2xl font-bold text-slate-800 mb-6 flex items-center gap-2">📈 體重變化趨勢</h2>
                                <div class="w-full h-80 relative"><canvas id="dashboardChart"></canvas></div>
                            </div>
                            <div>
                                <h2 class="text-2xl font-bold text-slate-800 mb-6 flex items-center gap-2">📝 近期飲食日記</h2>
                                <div class="space-y-4">
                                    <?php 
                                    $hasNotes = false;
                                    foreach ($_SESSION['health_records'] as $record) {
                                        if (!empty($record['notes'])) {
                                            $hasNotes = true;
                                            $displayDate = date('m/d', strtotime($record['date']));
                                            echo "<div class='border-l-2 border-slate-300 pl-4 py-1'><p class='text-xs text-slate-400 mb-1'>{$displayDate}</p><p class='text-sm text-slate-600'>".htmlspecialchars($record['notes'])."</p></div>";
                                        }
                                    }
                                    if (!$hasNotes) echo '<p class="text-slate-400 text-sm">尚無飲食筆記。</p>'; 
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>

                <?php elseif ($page === 'recipe'): ?>
                    <!-- ================= 食譜生成視圖 ================= -->
                    <div class="mb-8">
                        <h1 class="text-3xl font-bold text-slate-800 flex items-center gap-3">✨ iChef 智慧食譜生成</h1>
                        <p class="text-slate-600 mt-2">輸入現有食材，AI 立即為您運算健康營養餐點。</p>
                    </div>
                    <div class="border-b border-slate-200 w-full mb-10"></div>
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-10">
                        <div>
                            <form method="POST" action="index.php?page=recipe" class="space-y-4">
                                <input type="hidden" name="action" value="generate_recipe">
                                <div>
                                    <label class="block text-sm font-semibold text-slate-700 mb-2">可用的食材清單</label>
                                    <textarea name="ingredients" rows="4" required placeholder="例如: 雞胸肉、番茄、雞蛋、高麗菜..." class="w-full bg-slate-50 border border-slate-200 rounded-md px-4 py-3 focus:ring-1 focus:ring-blue-500 outline-none transition text-slate-700 resize-none"></textarea>
                                </div>
                                <button type="submit" class="w-full bg-slate-800 hover:bg-slate-900 text-white font-medium py-3 px-4 rounded-md transition shadow-sm flex justify-center items-center gap-2">
                                    ✨ 生成專屬食譜
                                </button>
                            </form>
                        </div>
                        <div class="bg-slate-50 border border-slate-200 rounded-lg p-6">
                            <?php if (isset($_SESSION['current_recipe']) && isset($_SESSION['current_recipe']['title'])): ?>
                                <?php $recipe = $_SESSION['current_recipe']; ?>
                                <h3 class="text-xl font-bold text-slate-800 mb-2">🍽️ <?= htmlspecialchars($recipe['title']) ?></h3>
                                <div class="grid grid-cols-4 gap-2 mb-6 mt-4">
                                    <div class="bg-white p-2 rounded border border-slate-100 text-center shadow-sm"><div class="text-xs text-slate-500">卡路里</div><div class="font-bold text-slate-700"><?= $recipe['calories']??0 ?></div></div>
                                    <div class="bg-white p-2 rounded border border-slate-100 text-center shadow-sm"><div class="text-xs text-slate-500">蛋白質</div><div class="font-bold text-blue-600"><?= $recipe['protein']??0 ?>g</div></div>
                                    <div class="bg-white p-2 rounded border border-slate-100 text-center shadow-sm"><div class="text-xs text-slate-500">碳水</div><div class="font-bold text-emerald-600"><?= $recipe['carbs']??0 ?>g</div></div>
                                    <div class="bg-white p-2 rounded border border-slate-100 text-center shadow-sm"><div class="text-xs text-slate-500">脂肪</div><div class="font-bold text-rose-500"><?= $recipe['fat']??0 ?>g</div></div>
                                </div>
                                <h4 class="font-bold text-slate-700 mb-2 text-sm">步驟：</h4>
                                <ul class="list-decimal list-inside space-y-1 text-sm text-slate-600">
                                    <?php foreach ($recipe['steps'] as $step): ?>
                                        <li><?= htmlspecialchars($step) ?></li>
                                    <?php endforeach; ?>
                                </ul>

                                <!-- 新增：儲存食譜按鈕與成功訊息 -->
                                <form method="POST" action="index.php?page=recipe" class="mt-6 border-t border-slate-200 pt-4">
                                    <input type="hidden" name="action" value="save_generated_recipe">
                                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2.5 px-4 rounded-md transition shadow-sm flex justify-center items-center gap-2">
                                        <i class="ph-bold ph-bookmark-simple text-lg"></i> 儲存至我的收藏
                                    </button>
                                </form>
                                <?php if ($message === 'recipe_saved'): ?>
                                    <p class="text-emerald-600 text-sm text-center mt-3 font-medium">✅ 食譜已成功儲存！請至左側「我的收藏食譜」查看。</p>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="h-full flex flex-col items-center justify-center text-slate-400 py-10">
                                    <i class="ph-thin ph-bowl-food text-5xl mb-2 text-slate-300"></i>
                                    <p class="text-sm">等待生成中...</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php elseif ($page === 'bookmarks'): ?>
                    <!-- ================= 收藏食譜視圖 ================= -->
                    <div class="mb-8">
                        <h1 class="text-4xl font-bold text-slate-800 flex items-center gap-3">🔍 我的收藏食譜</h1>
                    </div>
                    <div class="mb-8">
                        <p class="text-sm text-slate-600 mb-2">輸入關鍵字搜尋收藏...</p>
                        <input type="text" placeholder="例如：番茄、雞肉..." class="w-full bg-slate-100 border-none rounded-md px-4 py-3 text-slate-700 focus:ring-2 focus:ring-blue-100 outline-none transition">
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <?php if (!empty($_SESSION['saved_recipes'])): ?>
                            <?php foreach ($_SESSION['saved_recipes'] as $recipe): ?>
                                <div class="border border-slate-200 rounded-lg p-6 bg-white shadow-sm flex flex-col">
                                    <h3 class="text-2xl font-bold text-slate-700 mb-2 flex items-center gap-2">
                                        <span class="text-slate-400">🍴</span> <?= htmlspecialchars($recipe['title']) ?>
                                    </h3>
                                    <p class="text-sm text-slate-500 mb-6"><?= htmlspecialchars($recipe['category']) ?> | ⏳ <?= htmlspecialchars($recipe['time']) ?></p>
                                    <div class="mt-auto">
                                        <!-- 加上 onclick 事件與 type="button" -->
                                        <button type="button" onclick="toggleSteps(this)" class="w-full flex justify-between items-center px-4 py-2 border border-slate-200 rounded-md text-sm text-slate-700 hover:bg-slate-50 transition mb-3">
                                            <span>查看步驟</span><i class="ph-bold ph-caret-right text-slate-400 transition-transform duration-200"></i>
                                        </button>
                                        
                                        <!-- 新增：食譜步驟內容 (預設隱藏 hidden) -->
                                        <div class="hidden mb-4 bg-slate-50 border border-slate-100 p-4 rounded-md text-sm text-slate-600">
                                            <ul class="list-decimal list-inside space-y-1">
                                                <?php if (!empty($recipe['steps'])): ?>
                                                    <?php foreach ($recipe['steps'] as $step): ?>
                                                        <li><?= htmlspecialchars($step) ?></li>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <li>無步驟詳細資料。</li>
                                                <?php endif; ?>
                                            </ul>
                                        </div>

                                        <form method="POST" action="index.php?page=bookmarks" onsubmit="return confirm('確定要刪除此收藏嗎？');">
                                            <input type="hidden" name="action" value="delete_recipe">
                                            <input type="hidden" name="recipe_id" value="<?= $recipe['id'] ?>">
                                            <button type="submit" class="flex items-center gap-2 px-3 py-1.5 border border-slate-200 rounded-md text-xs text-slate-600 hover:bg-slate-100 transition">
                                                <span>🗑️</span> 刪除
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="col-span-full py-12 text-center text-slate-400"><p>目前還沒有任何收藏的食譜喔！</p></div>
                        <?php endif; ?>
                    </div>

                <?php elseif ($page === 'profile'): ?>
                    <!-- ================= 個人資料與營養設定視圖 ================= -->
                    <div class="mb-8">
                        <h1 class="text-4xl font-bold text-slate-800 flex items-center gap-3">👤 個人資料與營養設定</h1>
                        <p class="text-slate-600 mt-4">設定您的身體數值，iChef 將為您計算專屬的 TDEE 與營養目標！</p>
                    </div>
                    <div class="border border-slate-200 rounded-lg p-8 mb-12">
                        <form method="POST" action="index.php?page=profile" class="space-y-6">
                            <input type="hidden" name="action" value="save_profile">
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                <div>
                                    <label class="block text-sm text-slate-700 mb-2">性別</label>
                                    <select name="gender" class="w-full bg-slate-100 border-none rounded-md px-4 py-3 text-slate-700 focus:ring-2 focus:ring-blue-100 outline-none transition appearance-none">
                                        <option value="female" <?= $p['gender']==='female' ? 'selected' : '' ?>>女</option>
                                        <option value="male" <?= $p['gender']==='male' ? 'selected' : '' ?>>男</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm text-slate-700 mb-2">身高 (cm)</label>
                                    <div class="flex items-center w-full bg-slate-100 rounded-md overflow-hidden">
                                        <input type="number" step="0.1" name="height" id="input_height" value="<?= number_format($p['height'], 2) ?>" class="w-full bg-transparent border-none px-4 py-3 text-slate-700 outline-none text-left">
                                        <div class="flex px-2 text-lg font-bold text-slate-600 select-none">
                                            <button type="button" onclick="adjustValue('input_height', -1)" class="px-2 hover:text-black">-</button>
                                            <button type="button" onclick="adjustValue('input_height', 1)" class="px-2 hover:text-black">+</button>
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm text-slate-700 mb-2">活動量</label>
                                    <select name="activity_level" class="w-full bg-slate-100 border-none rounded-md px-4 py-3 text-slate-700 focus:ring-2 focus:ring-blue-100 outline-none transition appearance-none">
                                        <option value="1.2" <?= $p['activity_level']=='1.2' ? 'selected' : '' ?>>久坐 (Sedentary)</option>
                                        <option value="1.375" <?= $p['activity_level']=='1.375' ? 'selected' : '' ?>>輕度活動 (Lightly Active)</option>
                                        <option value="1.55" <?= $p['activity_level']=='1.55' ? 'selected' : '' ?>>中度活動 (Moderately Active)</option>
                                        <option value="1.725" <?= $p['activity_level']=='1.725' ? 'selected' : '' ?>>高度活動 (Very Active)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                <div>
                                    <label class="block text-sm text-slate-700 mb-2">年齡</label>
                                    <div class="flex items-center w-full bg-slate-100 rounded-md overflow-hidden">
                                        <input type="number" name="age" id="input_age" value="<?= $p['age'] ?>" class="w-full bg-transparent border-none px-4 py-3 text-slate-700 outline-none text-left">
                                        <div class="flex px-2 text-lg font-bold text-slate-600 select-none">
                                            <button type="button" onclick="adjustValue('input_age', -1)" class="px-2 hover:text-black">-</button>
                                            <button type="button" onclick="adjustValue('input_age', 1)" class="px-2 hover:text-black">+</button>
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm text-slate-700 mb-2">體重 (kg)</label>
                                    <div class="flex items-center w-full bg-slate-100 rounded-md overflow-hidden">
                                        <input type="number" step="0.1" name="weight" id="input_weight" value="<?= number_format($p['weight'], 2) ?>" class="w-full bg-transparent border-none px-4 py-3 text-slate-700 outline-none text-left">
                                        <div class="flex px-2 text-lg font-bold text-slate-600 select-none">
                                            <button type="button" onclick="adjustValue('input_weight', -1)" class="px-2 hover:text-black">-</button>
                                            <button type="button" onclick="adjustValue('input_weight', 1)" class="px-2 hover:text-black">+</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                <div class="md:col-span-1">
                                    <label class="block text-sm text-slate-700 mb-2">飲食偏好</label>
                                    <select name="diet_habit" class="w-full bg-slate-100 border-none rounded-md px-4 py-3 text-slate-700 focus:ring-2 focus:ring-blue-100 outline-none transition appearance-none">
                                        <option value="無特殊偏好" <?= $p['diet_habit']==='無特殊偏好' ? 'selected' : '' ?>>無特殊偏好</option>
                                        <option value="減脂" <?= $p['diet_habit']==='減脂' ? 'selected' : '' ?>>減脂 (Low Carb)</option>
                                        <option value="增肌" <?= $p['diet_habit']==='增肌' ? 'selected' : '' ?>>增肌 (High Protein)</option>
                                        <option value="蛋奶素" <?= $p['diet_habit']==='蛋奶素' ? 'selected' : '' ?>>蛋奶素</option>
                                    </select>
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block text-sm text-slate-700 mb-2">過敏原 (用逗號隔開)</label>
                                    <input type="text" name="allergies" value="<?= htmlspecialchars($p['allergies']) ?>" class="w-full bg-slate-100 border-none rounded-md px-4 py-3 text-slate-700 focus:ring-2 focus:ring-blue-100 outline-none transition">
                                </div>
                            </div>
                            <div class="pt-2">
                                <button type="submit" class="bg-white border border-slate-300 rounded-md py-2.5 px-6 flex items-center gap-2 hover:bg-slate-50 transition shadow-sm text-slate-700 font-medium text-sm">
                                    <span class="text-purple-700 text-base">💾</span> 儲存並計算目標
                                </button>
                                <?php if ($message === 'profile_saved') echo '<p class="text-green-600 text-sm mt-2">✅ 資料已更新！</p>'; ?>
                            </div>
                        </form>
                    </div>
                    <div class="border-t border-slate-200 pt-10">
                        <h2 class="text-3xl font-bold text-slate-800 mb-8 flex items-center gap-3">📊 您的專屬營養目標</h2>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-8">
                            <div>
                                <p class="text-sm text-slate-600 mb-1">基礎代謝 (BMR)</p>
                                <div class="flex items-baseline gap-2">
                                    <span class="text-4xl font-light text-slate-800"><?= round($bmr) ?></span>
                                    <span class="text-xl text-slate-600">kcal</span>
                                </div>
                            </div>
                            <div>
                                <p class="text-sm text-slate-600 mb-1">總消耗 (TDEE)</p>
                                <div class="flex items-baseline gap-2">
                                    <span class="text-4xl font-light text-slate-800"><?= round($tdee) ?></span>
                                    <span class="text-xl text-slate-600">kcal</span>
                                </div>
                            </div>
                            <div>
                                <p class="text-sm text-slate-600 mb-1">建議總熱量</p>
                                <div class="flex items-baseline gap-2">
                                    <span class="text-4xl font-light text-slate-800"><?= round($tdee) ?></span>
                                    <span class="text-xl text-slate-600">kcal</span>
                                </div>
                            </div>
                            <div>
                                <p class="text-sm text-slate-600 mb-1">蛋白質建議</p>
                                <div class="flex items-baseline gap-2">
                                    <span class="text-4xl font-light text-slate-800"><?= round($protein_suggestion) ?></span>
                                    <span class="text-xl text-slate-600">g</span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    <?php endif; ?>

    <!-- JS 腳本區塊 -->
    <script>
        // 新增：切換食譜步驟顯示/隱藏的函式
        function toggleSteps(btn) {
            const stepsDiv = btn.nextElementSibling;
            const icon = btn.querySelector('i');
            
            stepsDiv.classList.toggle('hidden');
            icon.classList.toggle('rotate-90');
        }

        function adjustValue(inputId, amount) {
            const input = document.getElementById(inputId);
            if(input) {
                let currentVal = parseFloat(input.value) || 0;
                input.value = (currentVal + amount).toFixed(input.step.includes('.') ? 2 : 0);
            }
        }

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
                        borderColor: '#2563eb',
                        borderWidth: 2,
                        backgroundColor: 'transparent',
                        pointBackgroundColor: '#2563eb',
                        pointBorderColor: '#fff',
                        pointHoverBackgroundColor: '#fff',
                        pointHoverBorderColor: '#2563eb',
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        tension: 0.1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(255, 255, 255, 0.9)',
                            titleColor: '#1e293b',
                            bodyColor: '#1e293b',
                            borderColor: '#e2e8f0',
                            borderWidth: 1,
                            padding: 10,
                            displayColors: false
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: { color: '#94a3b8', font: { size: 11 } },
                            border: { display: false }
                        },
                        y: {
                            min: 0,
                            max: 55,
                            grid: { color: '#f1f5f9', drawBorder: false },
                            ticks: { stepSize: 5, color: '#94a3b8', font: { size: 11 }, padding: 10 },
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