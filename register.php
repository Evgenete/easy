<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once 'config/database.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    
    if (empty($name) || empty($email) || empty($password)) {
        $error = "Все поля обязательны для заполнения";
    } elseif (strlen($password) < 6) {
        $error = "Пароль должен содержать не менее 6 символов";
    } else {
        try {
            $database = new Database();
            $db = $database->getConnection();
            
            // Проверяем, существует ли email
            $check_query = "SELECT user_id FROM users WHERE email = :email";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(":email", $email);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() > 0) {
                $error = "Пользователь с таким email уже существует";
            } else {
                // Создаем нового пользователя
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                
                $insert_query = "INSERT INTO users (username, email, password_hash, full_name) VALUES (:username, :email, :password_hash, :full_name)";
                $insert_stmt = $db->prepare($insert_query);
                $insert_stmt->bindParam(":username", $name);
                $insert_stmt->bindParam(":email", $email);
                $insert_stmt->bindParam(":password_hash", $password_hash);
                $insert_stmt->bindParam(":full_name", $name);
                
                if ($insert_stmt->execute()) {
                    // Получаем ID нового пользователя
                    $user_id = $db->lastInsertId();
                    
                    // Автоматически входим после регистрации
                    $_SESSION['user_id'] = $user_id;
                    $_SESSION['username'] = $name;
                    $_SESSION['email'] = $email;
                    
                    header("Location: personal_cabinet.php");
                    exit();
                } else {
                    $error = "Ошибка при регистрации";
                }
            }
        } catch(PDOException $exception) {
            $error = "Ошибка базы данных: " . $exception->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Регистрация - EasyGo</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .btn-primary {
            background: linear-gradient(135deg, #2563EB 0%, #3B82F6 100%);
            color: white;
            padding: 12px 24px;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            width: 100%;
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }
        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            margin-bottom: 16px;
            transition: border-color 0.3s ease;
        }
        .form-input:focus {
            outline: none;
            border-color: #3B82F6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center">
    <div class="max-w-md w-full bg-white rounded-2xl shadow-lg p-8">
        <div class="text-center mb-8">
            <div class="w-16 h-16 bg-blue-500 rounded-2xl flex items-center justify-center mx-auto mb-4">
                <span class="text-white font-bold text-xl">EG</span>
            </div>
            <h1 class="text-3xl font-bold text-gray-900">Регистрация</h1>
            <p class="text-gray-600 mt-2">Создайте аккаунт EasyGo</p>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4 flex items-center">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2">Имя пользователя</label>
                <input type="text" name="name" class="form-input" placeholder="ivan_ivanov" required 
                       value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
            </div>
            
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2">Email</label>
                <input type="email" name="email" class="form-input" placeholder="your@email.com" required
                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>
            
            <div class="mb-6">
                <label class="block text-gray-700 text-sm font-bold mb-2">Пароль</label>
                <input type="password" name="password" class="form-input" placeholder="Не менее 6 символов" required>
            </div>
            
            <button type="submit" class="btn-primary">
                <i class="fas fa-user-plus mr-2"></i> Создать аккаунт
            </button>
        </form>

        <div class="text-center mt-6">
            <a href="login.php" class="text-blue-500 hover:text-blue-700 font-medium">
                Уже есть аккаунт? Войдите
            </a>
        </div>
    </div>
</body>
</html>