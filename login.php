<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once 'config/database.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = "Все поля обязательны для заполнения";
    } else {
        try {
            $database = new Database();
            $db = $database->getConnection();

            // Используем правильные названия столбцов из вашей БД
            $query = "SELECT user_id, username, email, password_hash FROM users WHERE email = :email";
            $stmt = $db->prepare($query);
            $stmt->bindParam(":email", $email);
            $stmt->execute();

            if ($stmt->rowCount() == 1) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Проверяем пароль
                if (password_verify($password, $user['password_hash'])) {
                    // Успешный вход
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['email'] = $user['email'];
                    
                    header("Location: personal_cabinet.php");
                    exit();
                } else {
                    $error = "Неверный email или пароль";
                }
            } else {
                $error = "Неверный email или пароль";
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
    <title>Вход - EasyGo</title>
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
            <h1 class="text-3xl font-bold text-gray-900">Вход в аккаунт</h1>
            <p class="text-gray-600 mt-2">Войдите в свой аккаунт EasyGo</p>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4 flex items-center">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2">Email</label>
                <input type="email" name="email" class="form-input" placeholder="your@email.com" required
                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>
            
            <div class="mb-6">
                <label class="block text-gray-700 text-sm font-bold mb-2">Пароль</label>
                <input type="password" name="password" class="form-input" placeholder="Введите ваш пароль" required>
            </div>
            
            <button type="submit" class="btn-primary">
                <i class="fas fa-sign-in-alt mr-2"></i> Войти
            </button>
        </form>

        <div class="text-center mt-6">
            <a href="register.php" class="text-blue-500 hover:text-blue-700 font-medium">
                Нет аккаунта? Зарегистрируйтесь
            </a>
        </div>
    </div>
</body>
</html>