<?php
require_once 'includes/auth.php';

if(!$auth->isLoggedIn() || !$auth->isAdmin()) {
    header("Location: login.php");
    exit;
}

// Класс для работы с настройками сайта
class SiteSettings {
    private $conn;
    private $table_name = "site_settings";

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getSettings() {
        $query = "SELECT * FROM " . $this->table_name;
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        
        $settings = [];
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row;
        }
        return $settings;
    }

    public function updateSetting($key, $value) {
        $query = "UPDATE " . $this->table_name . " 
                  SET setting_value = :value, updated_at = CURRENT_TIMESTAMP 
                  WHERE setting_key = :key";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":value", $value);
        $stmt->bindParam(":key", $key);
        
        return $stmt->execute();
    }
}

$siteSettings = new SiteSettings($db);
$settings = $siteSettings->getSettings();

// Обработка обновления настроек
if(isset($_POST['update_settings'])) {
    foreach($_POST['settings'] as $key => $value) {
        $siteSettings->updateSetting($key, $value);
    }
    $success_message = "Настройки успешно обновлены";
    $settings = $siteSettings->getSettings(); // Обновляем данные
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Админ-панель - EasyGo</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="css/main.css">
</head>
<body class="bg-background text-foreground">
    <header class="header-fixed" id="header">
        <div class="container mx-auto px-4 py-4 flex justify-between items-center">
            <div class="flex items-center space-x-3">
                <img src="img/logo.svg" alt="EasyGo" class="logo">
                <span class="font-bold text-xl">Админ-панель</span>
            </div>
            
            <div class="flex items-center space-x-4">
                <a href="dashboard.php" class="px-4 py-2 rounded-xl btn-outline font-medium text-sm">
                    <i class="fas fa-arrow-left mr-2"></i>В личный кабинет
                </a>
                <a href="logout.php" class="px-4 py-2 rounded-xl bg-red-500 text-white font-medium text-sm hover:bg-red-600">
                    Выйти
                </a>
            </div>
        </div>
    </header>

    <main class="pt-20 pb-10">
        <div class="container mx-auto px-4">
            <h1 class="text-4xl font-bold mb-8">Панель администратора</h1>

            <?php if(isset($success_message)): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
                    <?php echo $success_message; ?>
                </div>
            <?php endif; ?>

            <div class="grid lg:grid-cols-2 gap-8">
                <!-- Настройки сайта -->
                <div class="bg-white rounded-2xl shadow-lg p-8">
                    <h2 class="text-2xl font-bold mb-6">Настройки сайта</h2>
                    
                    <form method="POST">
                        <?php foreach($settings as $setting): ?>
                            <div class="form-group mb-4">
                                <label class="form-label"><?php echo $setting['description']; ?></label>
                                <input type="text" 
                                       name="settings[<?php echo $setting['setting_key']; ?>]" 
                                       class="form-input" 
                                       value="<?php echo htmlspecialchars($setting['setting_value']); ?>">
                            </div>
                        <?php endforeach; ?>
                        
                        <button type="submit" name="update_settings" class="btn-primary">
                            <i class="fas fa-save mr-2"></i> Сохранить настройки
                        </button>
                    </form>
                </div>

                <!-- Статистика -->
                <div class="bg-white rounded-2xl shadow-lg p-8">
                    <h2 class="text-2xl font-bold mb-6">Статистика</h2>
                    
                    <div class="space-y-4">
                        <div class="flex justify-between items-center p-4 bg-blue-50 rounded-lg">
                            <div>
                                <h3 class="font-semibold">Всего пользователей</h3>
                                <p class="text-2xl font-bold text-primary">1,234</p>
                            </div>
                            <i class="fas fa-users text-3xl text-primary"></i>
                        </div>
                        
                        <div class="flex justify-between items-center p-4 bg-green-50 rounded-lg">
                            <div>
                                <h3 class="font-semibold">Активных маршрутов</h3>
                                <p class="text-2xl font-bold text-green-600">567</p>
                            </div>
                            <i class="fas fa-route text-3xl text-green-600"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</body>
</html>