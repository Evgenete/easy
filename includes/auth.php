<?php
session_start();
require_once 'config/database.php';

class Auth {
    private $conn;
    private $table_name = "users";

    public function __construct($db) {
        $this->conn = $db;
    }

    public function register($name, $email, $phone, $password) {
        // Проверка существования email
        $query = "SELECT id FROM " . $this->table_name . " WHERE email = :email";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":email", $email);
        $stmt->execute();

        if($stmt->rowCount() > 0) {
            return "Email уже зарегистрирован";
        }

        // Хеширование пароля
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // Вставка пользователя
        $query = "INSERT INTO " . $this->table_name . " 
                  SET name=:name, email=:email, phone=:phone, password=:password";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":name", $name);
        $stmt->bindParam(":email", $email);
        $stmt->bindParam(":phone", $phone);
        $stmt->bindParam(":password", $hashed_password);

        if($stmt->execute()) {
            return true;
        }
        return "Ошибка регистрации";
    }

    public function login($email, $password) {
        $query = "SELECT id, name, email, phone, password, is_admin, avatar 
                  FROM " . $this->table_name . " 
                  WHERE email = :email";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":email", $email);
        $stmt->execute();

        if($stmt->rowCount() == 1) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if(password_verify($password, $row['password'])) {
                $_SESSION['user_id'] = $row['id'];
                $_SESSION['user_name'] = $row['name'];
                $_SESSION['user_email'] = $row['email'];
                $_SESSION['user_phone'] = $row['phone'];
                $_SESSION['is_admin'] = $row['is_admin'];
                $_SESSION['user_avatar'] = $row['avatar'];
                return true;
            }
        }
        return "Неверный email или пароль";
    }

    public function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }

    public function isAdmin() {
        return isset($_SESSION['is_admin']) && $_SESSION['is_admin'];
    }

    public function logout() {
        session_destroy();
        header("Location: login.php");
        exit;
    }

    public function updateProfile($user_id, $name, $phone, $avatar = null) {
        $query = "UPDATE " . $this->table_name . " 
                  SET name=:name, phone=:phone";
        
        if($avatar) {
            $query .= ", avatar=:avatar";
        }
        
        $query .= " WHERE id=:id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":name", $name);
        $stmt->bindParam(":phone", $phone);
        $stmt->bindParam(":id", $user_id);
        
        if($avatar) {
            $stmt->bindParam(":avatar", $avatar);
        }

        if($stmt->execute()) {
            // Обновляем сессию
            $_SESSION['user_name'] = $name;
            $_SESSION['user_phone'] = $phone;
            if($avatar) {
                $_SESSION['user_avatar'] = $avatar;
            }
            return true;
        }
        return false;
    }
}

// Создание экземпляра аутентификации
$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
?>