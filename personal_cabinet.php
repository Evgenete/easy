<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Инициализация переменных
$success_message = '';
$error_message = '';
$favorites_error = '';
$history_error = '';
$debug_info = '';

// Сначала проверяем и добавляем недостающие столбцы
try {
    $columns_to_add = [
        'phone' => "ALTER TABLE users ADD COLUMN phone VARCHAR(20) NULL AFTER email",
        'notifications_enabled' => "ALTER TABLE users ADD COLUMN notifications_enabled TINYINT(1) DEFAULT 1 AFTER phone",
        'theme' => "ALTER TABLE users ADD COLUMN theme VARCHAR(10) DEFAULT 'light' AFTER notifications_enabled"
    ];
    
    foreach ($columns_to_add as $column => $sql) {
        $check_column = $db->query("SHOW COLUMNS FROM users LIKE '$column'");
        if ($check_column->rowCount() == 0) {
            $db->exec($sql);
        }
    }
} catch (PDOException $e) {
    // Игнорируем ошибки, если столбцы уже существуют
}

// Создаем таблицы для расписания если их нет
try {
    $db->exec("CREATE TABLE IF NOT EXISTS stops (
        stop_id INT AUTO_INCREMENT PRIMARY KEY,
        stop_name VARCHAR(255) NOT NULL,
        stop_address VARCHAR(255),
        latitude DECIMAL(10, 8),
        longitude DECIMAL(11, 8),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    $db->exec("CREATE TABLE IF NOT EXISTS schedule (
        schedule_id INT AUTO_INCREMENT PRIMARY KEY,
        route_id INT NOT NULL,
        stop_id INT NOT NULL,
        arrival_time TIME NOT NULL,
        stop_order INT NOT NULL,
        day_type ENUM('weekday', 'weekend', 'both') DEFAULT 'both',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (route_id) REFERENCES routes(route_id),
        FOREIGN KEY (stop_id) REFERENCES stops(stop_id)
    )");
    
    // Создаем индекс для оптимизации
    $db->exec("CREATE INDEX IF NOT EXISTS idx_schedule_route ON schedule(route_id)");
    
} catch (PDOException $e) {
    // Таблицы могут уже существовать
}

// Получаем данные пользователя
$user_query = "SELECT username, email, created_at, phone, notifications_enabled, theme FROM users WHERE user_id = :user_id";
$user_stmt = $db->prepare($user_query);
$user_stmt->bindParam(":user_id", $_SESSION['user_id']);
$user_stmt->execute();
$user = $user_stmt->fetch(PDO::FETCH_ASSOC);

// Устанавливаем значения по умолчанию для новых полей
if (!isset($user['phone'])) $user['phone'] = '';
if (!isset($user['notifications_enabled'])) $user['notifications_enabled'] = 1;
if (!isset($user['theme'])) $user['theme'] = 'light';

// Получаем билеты пользователя
$tickets = [];
try {
    $tickets_query = "SELECT * FROM user_tickets WHERE user_id = :user_id AND expires_at > NOW() AND status = 'active' ORDER BY created_at DESC";
    $tickets_stmt = $db->prepare($tickets_query);
    $tickets_stmt->bindParam(":user_id", $_SESSION['user_id']);
    $tickets_stmt->execute();
    $tickets = $tickets_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Таблица может не существовать - это нормально
}

// Функция для получения расписания маршрута
function getRouteSchedule($db, $route_id, $day_type = null) {
    try {
        if ($day_type && in_array($day_type, ['weekday', 'weekend'])) {
            $sql = "SELECT 
                        s.stop_name,
                        s.stop_address,
                        sch.arrival_time,
                        sch.stop_order,
                        sch.day_type
                    FROM schedule sch
                    JOIN stops s ON sch.stop_id = s.stop_id
                    WHERE sch.route_id = :route_id 
                    AND (sch.day_type = :day_type OR sch.day_type = 'both')
                    ORDER BY sch.stop_order, sch.arrival_time";
            
            $stmt = $db->prepare($sql);
            $stmt->bindParam(":route_id", $route_id);
            $stmt->bindParam(":day_type", $day_type);
        } else {
            $sql = "SELECT 
                        s.stop_name,
                        s.stop_address,
                        sch.arrival_time,
                        sch.stop_order,
                        sch.day_type
                    FROM schedule sch
                    JOIN stops s ON sch.stop_id = s.stop_id
                    WHERE sch.route_id = :route_id
                    ORDER BY sch.stop_order, sch.arrival_time";
            
            $stmt = $db->prepare($sql);
            $stmt->bindParam(":route_id", $route_id);
        }
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Ошибка получения расписания: " . $e->getMessage());
        return [];
    }
}

// Функция для получения ближайших отправлений
function getNextDepartures($db, $route_id, $limit = 5) {
    try {
        $current_day_type = (date('N') >= 1 && date('N') <= 5) ? 'weekday' : 'weekend';
        
        $sql = "SELECT 
                    s.stop_name,
                    sch.arrival_time,
                    CASE 
                        WHEN sch.arrival_time >= CURTIME() THEN TIMEDIFF(sch.arrival_time, CURTIME())
                        ELSE TIMEDIFF(ADDTIME(sch.arrival_time, '24:00:00'), CURTIME())
                    END as time_until
                FROM schedule sch
                JOIN stops s ON sch.stop_id = s.stop_id
                WHERE sch.route_id = :route_id 
                AND (sch.day_type = 'both' OR sch.day_type = :day_type)
                HAVING time_until > '00:00:00'
                ORDER BY time_until ASC
                LIMIT :limit";
        
        $stmt = $db->prepare($sql);
        $stmt->bindParam(":route_id", $route_id);
        $stmt->bindParam(":day_type", $current_day_type);
        $stmt->bindParam(":limit", $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Ошибка получения ближайших отправлений: " . $e->getMessage());
        return [];
    }
}

// Обработка AJAX запроса для получения расписания
if (($_GET['action'] ?? '') === 'get_schedule') {
    $route_id = $_GET['route_id'] ?? '';
    $day_type = $_GET['day_type'] ?? '';
    
    if (!empty($route_id)) {
        $schedule = getRouteSchedule($db, $route_id, $day_type);
        $next_departures = getNextDepartures($db, $route_id);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'schedule' => $schedule,
            'next_departures' => $next_departures
        ]);
        exit;
    }
}

// Обработка покупки билета
if (($_POST['action'] ?? '') === 'buy_ticket') {
    $ticket_type = $_POST['ticket_type'] ?? 'single';
    
    try {
        // Проверяем существование таблицы билетов
        $db->exec("CREATE TABLE IF NOT EXISTS user_tickets (
            ticket_id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            ticket_type VARCHAR(20) NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            rides_remaining INT DEFAULT 0,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            status ENUM('active', 'used', 'expired') DEFAULT 'active',
            FOREIGN KEY (user_id) REFERENCES users(user_id)
        )");
        
        // Определяем цену, количество поездок и срок действия
        $ticket_types = [
            'single' => ['price' => 30.00, 'rides' => 1, 'hours' => 1],
            'day' => ['price' => 100.00, 'rides' => 999, 'hours' => 24],
            'week' => ['price' => 500.00, 'rides' => 999, 'hours' => 168],
            'month' => ['price' => 1500.00, 'rides' => 999, 'hours' => 720]
        ];
        
        $price = $ticket_types[$ticket_type]['price'];
        $rides_remaining = $ticket_types[$ticket_type]['rides'];
        $expires_at = date('Y-m-d H:i:s', strtotime("+{$ticket_types[$ticket_type]['hours']} hours"));
        
        $ticket_query = "INSERT INTO user_tickets (user_id, ticket_type, price, rides_remaining, expires_at) 
                         VALUES (:user_id, :ticket_type, :price, :rides_remaining, :expires_at)";
        $ticket_stmt = $db->prepare($ticket_query);
        $ticket_stmt->bindParam(":user_id", $_SESSION['user_id']);
        $ticket_stmt->bindParam(":ticket_type", $ticket_type);
        $ticket_stmt->bindParam(":price", $price);
        $ticket_stmt->bindParam(":rides_remaining", $rides_remaining);
        $ticket_stmt->bindParam(":expires_at", $expires_at);
        
        if ($ticket_stmt->execute()) {
            $ticket_id = $db->lastInsertId();
            $success_message = "Билет успешно приобретен!";
            // Обновляем список билетов
            $tickets_stmt->execute();
            $tickets = $tickets_stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $error_message = "Ошибка при покупке билета";
        }
    } catch (PDOException $e) {
        $error_message = "Ошибка базы данных: " . $e->getMessage();
    }
}

// Обработка списания поездки
if (($_POST['action'] ?? '') === 'use_ride') {
    $ticket_id = $_POST['ticket_id'] ?? '';
    $vehicle_qr = $_POST['vehicle_qr'] ?? '';
    
    if (!empty($ticket_id) && !empty($vehicle_qr)) {
        try {
            // Проверяем существование билета и его статус
            $check_ticket_query = "SELECT * FROM user_tickets 
                                 WHERE ticket_id = :ticket_id 
                                 AND user_id = :user_id 
                                 AND status = 'active' 
                                 AND expires_at > NOW()";
            $check_ticket_stmt = $db->prepare($check_ticket_query);
            $check_ticket_stmt->bindParam(":ticket_id", $ticket_id);
            $check_ticket_stmt->bindParam(":user_id", $_SESSION['user_id']);
            $check_ticket_stmt->execute();
            
            $ticket = $check_ticket_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($ticket) {
                // Проверяем остались ли поездки
                if ($ticket['rides_remaining'] > 0 || $ticket['rides_remaining'] == 999) {
                    // Создаем запись о поездке
                    $db->exec("CREATE TABLE IF NOT EXISTS ride_history (
                        ride_id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        ticket_id INT NOT NULL,
                        vehicle_qr VARCHAR(100) NOT NULL,
                        ride_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        fare_amount DECIMAL(10,2) NOT NULL,
                        FOREIGN KEY (user_id) REFERENCES users(user_id),
                        FOREIGN KEY (ticket_id) REFERENCES user_tickets(ticket_id)
                    )");
                    
                    // Определяем стоимость поездки
                    $fare_amount = $ticket['ticket_type'] === 'single' ? $ticket['price'] : 0;
                    
                    $ride_query = "INSERT INTO ride_history (user_id, ticket_id, vehicle_qr, fare_amount) 
                                 VALUES (:user_id, :ticket_id, :vehicle_qr, :fare_amount)";
                    $ride_stmt = $db->prepare($ride_query);
                    $ride_stmt->bindParam(":user_id", $_SESSION['user_id']);
                    $ride_stmt->bindParam(":ticket_id", $ticket_id);
                    $ride_stmt->bindParam(":vehicle_qr", $vehicle_qr);
                    $ride_stmt->bindParam(":fare_amount", $fare_amount);
                    
                    if ($ride_stmt->execute()) {
                        // Обновляем количество оставшихся поездок (только для разовых билетов)
                        if ($ticket['rides_remaining'] != 999) {
                            $new_rides = $ticket['rides_remaining'] - 1;
                            $update_ticket_query = "UPDATE user_tickets 
                                                  SET rides_remaining = :rides_remaining,
                                                      status = CASE WHEN :rides_remaining = 0 THEN 'used' ELSE 'active' END
                                                  WHERE ticket_id = :ticket_id";
                            $update_ticket_stmt = $db->prepare($update_ticket_query);
                            $update_ticket_stmt->bindParam(":rides_remaining", $new_rides);
                            $update_ticket_stmt->bindParam(":ticket_id", $ticket_id);
                            $update_ticket_stmt->execute();
                        }
                        
                        $success_message = "Поездка успешно списана!";
                        // Обновляем список билетов
                        $tickets_stmt->execute();
                        $tickets = $tickets_stmt->fetchAll(PDO::FETCH_ASSOC);
                    } else {
                        $error_message = "Ошибка при списании поездки";
                    }
                } else {
                    $error_message = "В билете не осталось поездок";
                }
            } else {
                $error_message = "Билет не найден или не активен";
            }
        } catch (PDOException $e) {
            $error_message = "Ошибка базы данных: " . $e->getMessage();
        }
    } else {
        $error_message = "Неверные данные для списания поездки";
    }
}

// Обработка обновления профиля
if (($_POST['action'] ?? '') === 'update_profile') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $notifications_enabled = isset($_POST['notifications_enabled']) ? 1 : 0;
    $theme = $_POST['theme'] ?? 'light';
    
    if (!empty($username) && !empty($email)) {
        try {
            // Проверяем, не занят ли username другим пользователем
            $check_username_query = "SELECT user_id FROM users WHERE username = :username AND user_id != :user_id";
            $check_username_stmt = $db->prepare($check_username_query);
            $check_username_stmt->bindParam(":username", $username);
            $check_username_stmt->bindParam(":user_id", $_SESSION['user_id']);
            $check_username_stmt->execute();
            
            // Проверяем, не занят ли email другим пользователем
            $check_email_query = "SELECT user_id FROM users WHERE email = :email AND user_id != :user_id";
            $check_email_stmt = $db->prepare($check_email_query);
            $check_email_stmt->bindParam(":email", $email);
            $check_email_stmt->bindParam(":user_id", $_SESSION['user_id']);
            $check_email_stmt->execute();
            
            $errors = [];
            
            if ($check_username_stmt->rowCount() > 0) {
                $errors[] = "Это имя пользователя уже занято";
            }
            
            if ($check_email_stmt->rowCount() > 0) {
                $errors[] = "Этот email уже используется другим пользователем";
            }
            
            if (empty($errors)) {
                $update_query = "UPDATE users SET username = :username, email = :email, phone = :phone, notifications_enabled = :notifications_enabled, theme = :theme WHERE user_id = :user_id";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bindParam(":username", $username);
                $update_stmt->bindParam(":email", $email);
                $update_stmt->bindParam(":phone", $phone);
                $update_stmt->bindParam(":notifications_enabled", $notifications_enabled);
                $update_stmt->bindParam(":theme", $theme);
                $update_stmt->bindParam(":user_id", $_SESSION['user_id']);
                
                if ($update_stmt->execute()) {
                    $success_message = "Профиль успешно обновлен!";
                    $_SESSION['username'] = $username;
                    // Обновляем данные пользователя
                    $user_stmt->execute();
                    $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    $error_message = "Ошибка при обновлении профиля";
                }
            } else {
                $error_message = implode(". ", $errors);
            }
        } catch (PDOException $e) {
            $error_message = "Ошибка базы данных: " . $e->getMessage();
        }
    } else {
        $error_message = "Заполните обязательные поля";
    }
}

// Получаем избранные маршруты
$favorites = [];
try {
    $favorites_query = "SELECT r.*, uf.created_at 
                       FROM user_favorites uf 
                       JOIN routes r ON uf.route_id = r.route_id 
                       WHERE uf.user_id = :user_id 
                       ORDER BY uf.created_at DESC";
    $favorites_stmt = $db->prepare($favorites_query);
    $favorites_stmt->bindParam(":user_id", $_SESSION['user_id']);
    $favorites_stmt->execute();
    $favorites = $favorites_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $favorites_error = "Не удалось загрузить избранные маршруты: " . $e->getMessage();
}

// Получаем историю поиска
$history = [];
try {
    $history_query = "SELECT * FROM search_history 
                     WHERE user_id = :user_id 
                     ORDER BY created_at DESC 
                     LIMIT 10";
    $history_stmt = $db->prepare($history_query);
    $history_stmt->bindParam(":user_id", $_SESSION['user_id']);
    $history_stmt->execute();
    $history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $history_error = "Не удалось загрузить историю поиска: " . $e->getMessage();
}

// Получаем историю поездок
$ride_history = [];
try {
    $ride_history_query = "SELECT rh.*, ut.ticket_type, ut.price 
                          FROM ride_history rh 
                          JOIN user_tickets ut ON rh.ticket_id = ut.ticket_id 
                          WHERE rh.user_id = :user_id 
                          ORDER BY rh.ride_date DESC 
                          LIMIT 10";
    $ride_history_stmt = $db->prepare($ride_history_query);
    $ride_history_stmt->bindParam(":user_id", $_SESSION['user_id']);
    $ride_history_stmt->execute();
    $ride_history = $ride_history_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Таблица может не существовать - это нормально
}

// Получаем реальные данные транспорта Иркутска
$transport_data = getRealTransportData();

// Функция для получения данных транспорта
function getRealTransportData() {
    $transport_data = [];
    
    // Основные маршруты Иркутска
    $main_routes = [
        ['number' => '4', 'type' => 'bus', 'lat' => 52.2864, 'lng' => 104.2806],
        ['number' => '4к', 'type' => 'bus', 'lat' => 52.2890, 'lng' => 104.2750],
        ['number' => '8', 'type' => 'bus', 'lat' => 52.2830, 'lng' => 104.2850],
        ['number' => '20', 'type' => 'bus', 'lat' => 52.2900, 'lng' => 104.2700],
        ['number' => '64', 'type' => 'bus', 'lat' => 52.2800, 'lng' => 104.2900],
        ['number' => '1', 'type' => 'trolleybus', 'lat' => 52.2870, 'lng' => 104.2780],
        ['number' => '3', 'type' => 'trolleybus', 'lat' => 52.2840, 'lng' => 104.2820],
        ['number' => '4', 'type' => 'trolleybus', 'lat' => 52.2850, 'lng' => 104.2790],
        ['number' => '1', 'type' => 'tram', 'lat' => 52.2880, 'lng' => 104.2760],
        ['number' => '2', 'type' => 'tram', 'lat' => 52.2820, 'lng' => 104.2840],
    ];
    
    // Добавляем немного случайности к координатам для реалистичности
    foreach ($main_routes as $route) {
        $transport_data[] = [
            'route_number' => $route['number'],
            'latitude' => $route['lat'] + (rand(-5, 5) / 10000),
            'longitude' => $route['lng'] + (rand(-5, 5) / 10000),
            'last_update' => date('Y-m-d H:i:s'),
            'vehicle_type' => $route['type'],
            'speed' => rand(20, 60),
            'direction' => rand(0, 360),
            'capacity' => rand(30, 100)
        ];
    }
    
    return $transport_data;
}

// Обработка поиска
$search_results = [];
$search_query = '';
$search_type = 'route';

if (($_POST['action'] ?? '') === 'search') {
    $search_query = trim($_POST['search_query'] ?? '');
    $search_type = $_POST['search_type'] ?? 'route';
    
    if (!empty($search_query)) {
        try {
            // Сохраняем поиск в историю
            $history_insert = "INSERT INTO search_history (user_id, search_query, search_type) 
                             VALUES (:user_id, :query, :type)";
            $history_insert_stmt = $db->prepare($history_insert);
            $history_insert_stmt->bindParam(":user_id", $_SESSION['user_id']);
            $history_insert_stmt->bindParam(":query", $search_query);
            $history_insert_stmt->bindParam(":type", $search_type);
            $history_insert_stmt->execute();
            
            // Выполняем поиск в зависимости от типа
            if ($search_type === 'route') {
                $search_sql = "SELECT r.* 
                              FROM routes r 
                              WHERE r.route_number LIKE :query 
                                 OR r.route_name LIKE :query 
                              ORDER BY 
                                CASE 
                                    WHEN r.route_number = :exact_query THEN 1
                                    WHEN r.route_number LIKE :query_start THEN 2
                                    ELSE 3
                                END,
                                r.route_number, 
                                r.route_name 
                              LIMIT 20";
                
                $search_stmt = $db->prepare($search_sql);
                $search_param = "%$search_query%";
                $search_param_start = "$search_query%";
                
                $search_stmt->bindParam(":query", $search_param);
                $search_stmt->bindParam(":exact_query", $search_query);
                $search_stmt->bindParam(":query_start", $search_param_start);
                
                $debug_info = "Поиск маршрутов: '$search_query'";
            } else {
                // Поиск по остановкам
                $search_sql = "SELECT s.* 
                              FROM stops s 
                              WHERE s.stop_name LIKE :query 
                                 OR s.stop_address LIKE :query 
                              ORDER BY s.stop_name 
                              LIMIT 20";
                
                $search_stmt = $db->prepare($search_sql);
                $search_param = "%$search_query%";
                $search_stmt->bindParam(":query", $search_param);
                
                $debug_info = "Поиск остановок: '$search_query'";
            }
            
            $search_stmt->execute();
            $search_results = $search_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $debug_info .= " - Найдено: " . count($search_results) . " результатов";
            
            // Обновляем историю поиска для отображения
            $history_stmt->execute();
            $history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            $error_message = "Ошибка поиска: " . $e->getMessage();
            $debug_info = "Ошибка: " . $e->getMessage();
        }
    } else {
        $error_message = "Введите поисковый запрос";
    }
}

// Обработка добавления маршрута в избранное
if (($_POST['action'] ?? '') === 'add_favorite') {
    $route_number = trim($_POST['route_number'] ?? '');
    $route_name = trim($_POST['route_name'] ?? '');
    
    if (!empty($route_number) && !empty($route_name)) {
        try {
            // Сначала проверяем, существует ли уже такой маршрут
            $check_route_query = "SELECT route_id FROM routes WHERE route_number = :route_number AND route_name = :route_name";
            $check_route_stmt = $db->prepare($check_route_query);
            $check_route_stmt->bindParam(":route_number", $route_number);
            $check_route_stmt->bindParam(":route_name", $route_name);
            $check_route_stmt->execute();
            
            $existing_route = $check_route_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing_route) {
                $route_id = $existing_route['route_id'];
            } else {
                // Создаем новый маршрут
                $route_query = "INSERT INTO routes (route_number, route_name) VALUES (:route_number, :route_name)";
                $route_stmt = $db->prepare($route_query);
                $route_stmt->bindParam(":route_number", $route_number);
                $route_stmt->bindParam(":route_name", $route_name);
                
                if ($route_stmt->execute()) {
                    $route_id = $db->lastInsertId();
                } else {
                    throw new Exception("Не удалось создать маршрут");
                }
            }
            
            // Проверяем, не добавлен ли уже маршрут в избранное
            $check_favorite_query = "SELECT * FROM user_favorites WHERE user_id = :user_id AND route_id = :route_id";
            $check_favorite_stmt = $db->prepare($check_favorite_query);
            $check_favorite_stmt->bindParam(":user_id", $_SESSION['user_id']);
            $check_favorite_stmt->bindParam(":route_id", $route_id);
            $check_favorite_stmt->execute();
            
            if ($check_favorite_stmt->rowCount() === 0) {
                // Добавляем в избранное
                $favorite_query = "INSERT INTO user_favorites (user_id, route_id) 
                                  VALUES (:user_id, :route_id)";
                $favorite_stmt = $db->prepare($favorite_query);
                $favorite_stmt->bindParam(":user_id", $_SESSION['user_id']);
                $favorite_stmt->bindParam(":route_id", $route_id);
                
                if ($favorite_stmt->execute()) {
                    $success_message = "Маршрут '$route_number - $route_name' добавлен в избранное!";
                    // Обновляем список избранного
                    $favorites_stmt->execute();
                    $favorites = $favorites_stmt->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $error_message = "Ошибка при добавлении в избранное";
                }
            } else {
                $error_message = "Этот маршрут уже есть в избранном";
            }
            
        } catch (PDOException $e) {
            $error_message = "Ошибка базы данных: " . $e->getMessage();
        } catch (Exception $e) {
            $error_message = $e->getMessage();
        }
    } else {
        $error_message = "Заполните все поля";
    }
}

// Обработка добавления существующего маршрута в избранное
if (($_GET['add_to_favorites'] ?? '')) {
    $route_id = $_GET['add_to_favorites'];
    try {
        // Проверяем, не добавлен ли уже маршрут
        $check_query = "SELECT * FROM user_favorites WHERE user_id = :user_id AND route_id = :route_id";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(":user_id", $_SESSION['user_id']);
        $check_stmt->bindParam(":route_id", $route_id);
        $check_stmt->execute();
        
        if ($check_stmt->rowCount() === 0) {
            $favorite_query = "INSERT INTO user_favorites (user_id, route_id) 
                              VALUES (:user_id, :route_id)";
            $favorite_stmt = $db->prepare($favorite_query);
            $favorite_stmt->bindParam(":user_id", $_SESSION['user_id']);
            $favorite_stmt->bindParam(":route_id", $route_id);
            
            if ($favorite_stmt->execute()) {
                $success_message = "Маршрут добавлен в избранное!";
                // Обновляем список избранного
                $favorites_stmt->execute();
                $favorites = $favorites_stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $error_message = "Ошибка при добавлении в избранное";
            }
        } else {
            $error_message = "Маршрут уже в избранном";
        }
    } catch (PDOException $e) {
        $error_message = "Ошибка базы данных: " . $e->getMessage();
    }
}

// Обработка удаления маршрута из избранного
if (($_GET['delete'] ?? '')) {
    $route_id = $_GET['delete'];
    try {
        $delete_query = "DELETE FROM user_favorites 
                        WHERE user_id = :user_id AND route_id = :route_id";
        $delete_stmt = $db->prepare($delete_query);
        $delete_stmt->bindParam(":user_id", $_SESSION['user_id']);
        $delete_stmt->bindParam(":route_id", $route_id);
        
        if ($delete_stmt->execute()) {
            $success_message = "Маршрут удален из избранного";
            // Обновляем список
            $favorites_stmt->execute();
            $favorites = $favorites_stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $error_message = "Ошибка при удалении маршрута";
        }
    } catch (PDOException $e) {
        $error_message = "Ошибка базы данных: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Личный кабинет - EasyGo</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
    <script src="https://unpkg.com/html5-qrcode@2.3.8/minified/html5-qrcode.min.js"></script>
    <style>
        /* Основные стили */
        body {
            scroll-behavior: smooth;
        }
        
        .ticket-card {
            background: linear-gradient(135deg, #3B82F6 0%, #1D4ED8 100%);
            color: white;
            border-radius: 12px;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }
        
        .ticket-type-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            background: rgba(255,255,255,0.2);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .pricing-card {
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }
        
        .pricing-card:hover {
            transform: translateY(-5px);
            border-color: #3B82F6;
        }
        
        .pricing-card.popular {
            border-color: #3B82F6;
            position: relative;
        }
        
        .popular-badge {
            position: absolute;
            top: -10px;
            left: 50%;
            transform: translateX(-50%);
            background: #3B82F6;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .nav-tab {
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
        }
        
        .nav-tab.active {
            border-bottom-color: #3B82F6;
            color: #3B82F6;
        }
        
        /* Стили для формы поиска */
        .search-form-container {
            background: #3B82F6;
            border-radius: 16px;
            padding: 32px;
            color: white;
            box-shadow: 0 10px 25px rgba(59, 130, 246, 0.2);
        }
        
        .search-input {
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            color: white;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }
        
        .search-input:focus {
            background: rgba(255,255,255,0.15);
            border-color: rgba(255,255,255,0.4);
            box-shadow: 0 0 0 3px rgba(255,255,255,0.1);
        }
        
        .search-input::placeholder {
            color: rgba(255,255,255,0.7);
        }
        
        .search-select {
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            color: white;
            backdrop-filter: blur(10px);
        }
        
        .search-select option {
            background: #4A5568;
            color: white;
        }
        
        /* Исправления для карты */
        #map {
            height: 500px;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            position: relative;
            z-index: 1;
        }
        
        /* Фиксируем элементы управления карты */
        .leaflet-top {
            top: 70px !important;
        }
        
        .leaflet-bottom {
            bottom: 10px !important;
        }
        
        .leaflet-control {
            z-index: 1000 !important;
        }
        
        /* Стили для фильтров карты */
        .map-filters {
            position: relative;
            z-index: 1000;
            margin-bottom: 15px;
        }
        
        /* Улучшаем отображение маркеров */
        .bus-marker, .trolleybus-marker, .tram-marker {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            font-size: 14px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        .bus-marker {
            background: #10B981;
            color: white;
        }
        
        .trolleybus-marker {
            background: #F59E0B;
            color: white;
        }
        
        .tram-marker {
            background: #EF4444;
            color: white;
        }
        
        /* Стили для модального окна */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            backdrop-filter: blur(5px);
        }
        
        .modal-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0.9);
            background: white;
            padding: 30px;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            max-width: 500px;
            width: 90%;
            opacity: 0;
            transition: all 0.3s ease;
        }
        
        .modal-content.active {
            opacity: 1;
            transform: translate(-50%, -50%) scale(1);
        }
        
        .payment-success {
            text-align: center;
            padding: 40px 20px;
        }
        
        .success-animation {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: #10B981;
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: scaleIn 0.5s ease-out;
        }
        
        .success-animation i {
            color: white;
            font-size: 40px;
        }
        
        @keyframes scaleIn {
            from { transform: scale(0); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
        
        .card-input {
            background: #F7FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 8px;
            padding: 12px 16px;
            transition: all 0.3s ease;
        }
        
        .card-input:focus {
            border-color: #3B82F6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .payment-processing {
            display: none;
            text-align: center;
            padding: 20px;
        }
        
        .processing-spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #E2E8F0;
            border-top: 4px solid #3B82F6;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Стили для сканера QR */
        .scanner-container {
            text-align: center;
            padding: 20px;
        }
        
        #qr-reader {
            width: 100%;
            max-width: 400px;
            margin: 0 auto 20px;
            border: 2px solid #E5E7EB;
            border-radius: 12px;
            overflow: hidden;
        }
        
        .scanner-instructions {
            background: #F3F4F6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            text-align: left;
        }
        
        .ride-history-item {
            border-left: 4px solid #10B981;
            background: #F0FDF4;
            padding: 12px;
            margin-bottom: 8px;
            border-radius: 0 8px 8px 0;
        }
        
        .ticket-rides {
            display: inline-block;
            background: #3B82F6;
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 8px;
        }
        
        .ticket-unlimited {
            background: #10B981;
        }
        
        .use-ticket-btn {
            background: #10B981;
            color: white;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .use-ticket-btn:hover {
            background: #059669;
            transform: translateY(-1px);
        }
        
        .use-ticket-btn:disabled {
            background: #9CA3AF;
            cursor: not-allowed;
            transform: none;
        }
        
        /* Стили для модального окна расписания */
        .schedule-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .schedule-table th,
        .schedule-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #E5E7EB;
        }
        
        .schedule-table th {
            background-color: #F9FAFB;
            font-weight: 600;
            color: #374151;
        }
        
        .schedule-table tr:hover {
            background-color: #F9FAFB;
        }
        
        .day-type-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .day-type-weekday {
            background-color: #DBEAFE;
            color: #1E40AF;
        }
        
        .day-type-weekend {
            background-color: #D1FAE5;
            color: #065F46;
        }
        
        .day-type-both {
            background-color: #F3F4F6;
            color: #374151;
        }
        
        .departure-card {
            background: white;
            border: 1px solid #E5E7EB;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 8px;
        }
        
        .time-until {
            color: #059669;
            font-weight: 600;
        }
        
        /* Кнопка "Наверх" */
        .back-to-top {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: #3B82F6;
            color: white;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        
        .back-to-top.visible {
            opacity: 1;
            visibility: visible;
        }
        
        .back-to-top:hover {
            background: #2563EB;
            transform: translateY(-2px);
        }
        
        /* Анимации */
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Улучшенные карточки */
        .search-result-card {
            border: 1px solid #E5E7EB;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 16px;
            background: white;
            transition: all 0.3s ease;
        }
        
        .search-result-card:hover {
            border-color: #3B82F6;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.1);
        }
        
        /* Просторные контейнеры */
        .spacious-container {
            padding: 24px;
            margin-bottom: 24px;
        }
        
        /* Валидация форм */
        .input-error {
            border-color: #EF4444 !important;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1) !important;
        }
        
        .error-message {
            color: #EF4444;
            font-size: 12px;
            margin-top: 4px;
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen transition-colors duration-300">
    <!-- Кнопка "Наверх" -->
    <div class="back-to-top" id="backToTop">
        <i class="fas fa-chevron-up"></i>
    </div>

    <!-- Модальное окно оплаты -->
    <div id="payment-modal" class="modal-overlay">
        <div class="modal-content">
            <div id="payment-form">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-bold text-gray-900">Оплата тарифа</h3>
                    <button id="close-payment-modal" class="text-gray-400 hover:text-gray-600 transition-colors">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <div class="mb-6">
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                        <div class="flex justify-between items-center">
                            <div>
                                <h4 id="tariff-name" class="font-semibold text-blue-900">Суточный тариф</h4>
                                <p id="tariff-description" class="text-sm text-blue-700">Действует 24 часа</p>
                            </div>
                            <div id="tariff-price" class="text-2xl font-bold text-blue-900">100 ₽</div>
                        </div>
                    </div>
                </div>
                
                <form id="payment-form-data">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Номер карты</label>
                            <input type="text" id="card-number" class="card-input w-full" placeholder="1234 5678 9012 3456" maxlength="19" required>
                            <div class="error-message" id="card-number-error"></div>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Срок действия</label>
                                <input type="text" id="card-expiry" class="card-input w-full" placeholder="ММ/ГГ" maxlength="5" required>
                                <div class="error-message" id="card-expiry-error"></div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">CVV</label>
                                <input type="text" id="card-cvv" class="card-input w-full" placeholder="123" maxlength="3" required>
                                <div class="error-message" id="card-cvv-error"></div>
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Имя владельца карты</label>
                            <input type="text" id="card-holder" class="card-input w-full" placeholder="IVAN IVANOV" required>
                            <div class="error-message" id="card-holder-error"></div>
                        </div>
                        
                        <button type="submit" class="w-full px-4 py-3 bg-blue-500 text-white rounded-lg font-semibold hover:bg-blue-600 transition-all duration-300 hover:shadow-md mt-4">
                            <i class="fas fa-lock mr-2"></i> Оплатить <span id="pay-amount">100 ₽</span>
                        </button>
                    </div>
                </form>
                
                <div id="payment-processing" class="payment-processing" style="display: none;">
                    <div class="processing-spinner"></div>
                    <p class="text-gray-600 font-medium">Обработка платежа...</p>
                    <p class="text-sm text-gray-500 mt-2">Пожалуйста, не закрывайте страницу</p>
                </div>
            </div>
            
            <div id="payment-success" class="payment-success" style="display: none;">
                <div class="success-animation">
                    <i class="fas fa-check"></i>
                </div>
                <h3 class="text-2xl font-bold text-gray-900 mb-2">Оплата прошла успешно!</h3>
                <p class="text-gray-600 mb-4">Тариф активирован и добавлен в ваш профиль</p>
                <p id="success-details" class="text-sm text-gray-500 mb-6"></p>
                <button id="success-close" class="px-6 py-2 bg-blue-500 text-white rounded-lg font-medium hover:bg-blue-600 transition-all duration-300">
                    <i class="fas fa-check mr-2"></i> Понятно
                </button>
            </div>
            
            <div id="payment-error" class="payment-success" style="display: none;">
                <div class="success-animation" style="background: #EF4444;">
                    <i class="fas fa-times"></i>
                </div>
                <h3 class="text-2xl font-bold text-gray-900 mb-2">Ошибка оплаты</h3>
                <p class="text-gray-600 mb-4" id="error-message">Проверьте данные карты и попробуйте снова</p>
                <button id="error-retry" class="px-6 py-2 bg-blue-500 text-white rounded-lg font-medium hover:bg-blue-600 transition-all duration-300 mr-3">
                    <i class="fas fa-redo mr-2"></i> Попробовать снова
                </button>
                <button id="error-close" class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg font-medium hover:bg-gray-50 transition-all duration-300">
                    Отмена
                </button>
            </div>
        </div>
    </div>

    <!-- Модальное окно сканирования QR -->
    <div id="scan-modal" class="modal-overlay" style="display: none;">
        <div class="modal-content" style="max-width: 500px;">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-bold text-gray-900">Сканирование QR-кода</h3>
                <button id="close-scan-modal" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <div class="scanner-instructions">
                <h4 class="font-semibold text-gray-900 mb-2">Как использовать:</h4>
                <ol class="text-sm text-gray-600 space-y-1 list-decimal list-inside">
                    <li>Найдите QR-код в салоне транспорта</li>
                    <li>Наведите камеру на код</li>
                    <li>Поездка будет автоматически списана с вашего билета</li>
                </ol>
            </div>
            
            <div class="scanner-container">
                <div id="qr-reader"></div>
                <div id="scan-result" class="mt-4"></div>
                
                <div class="mt-6">
                    <button id="start-scan" class="px-4 py-2 bg-blue-500 text-white rounded-lg font-medium hover:bg-blue-600 transition-all duration-300 hover:shadow-md mr-3">
                        <i class="fas fa-camera mr-2"></i> Начать сканирование
                    </button>
                    <button id="stop-scan" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg font-medium hover:bg-gray-50 transition-all duration-300 hover:shadow-md">
                        <i class="fas fa-stop mr-2"></i> Остановить
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно расписания -->
    <div id="schedule-modal" class="modal-overlay" style="display: none;">
        <div class="modal-content" style="max-width: 800px;">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-bold text-gray-900" id="schedule-title">Расписание маршрута</h3>
                <button id="close-schedule-modal" class="text-gray-400 hover:text-gray-600 transition-colors">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Тип дня:</label>
                <div class="flex space-x-4">
                    <label class="inline-flex items-center">
                        <input type="radio" name="day_type" value="both" checked class="schedule-filter">
                        <span class="ml-2">Все дни</span>
                    </label>
                    <label class="inline-flex items-center">
                        <input type="radio" name="day_type" value="weekday" class="schedule-filter">
                        <span class="ml-2">Будни</span>
                    </label>
                    <label class="inline-flex items-center">
                        <input type="radio" name="day_type" value="weekend" class="schedule-filter">
                        <span class="ml-2">Выходные</span>
                    </label>
                </div>
            </div>
            
            <!-- Ближайшие отправления -->
            <div id="next-departures" class="mb-6 p-4 bg-blue-50 rounded-lg">
                <h4 class="font-semibold text-blue-900 mb-3">Ближайшие отправления:</h4>
                <div id="departures-list" class="space-y-2"></div>
            </div>
            
            <!-- Полное расписание -->
            <div class="overflow-y-auto max-h-96">
                <table class="schedule-table">
                    <thead class="bg-gray-50 sticky top-0">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">№</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Остановка</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Время</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Дни</th>
                        </tr>
                    </thead>
                    <tbody id="schedule-table-body" class="bg-white divide-y divide-gray-200">
                        <!-- Данные будут загружены через AJAX -->
                    </tbody>
                </table>
            </div>
            
            <div class="mt-6 text-center">
                <button id="close-schedule" class="px-4 py-2 bg-blue-500 text-white rounded-lg font-medium hover:bg-blue-600 transition-all duration-300">
                    Закрыть
                </button>
            </div>
        </div>
    </div>

    <!-- Кнопка переключения темы -->
    <div class="fixed top-4 right-4 z-50">
        <button id="theme-toggle" class="w-12 h-12 bg-white shadow-lg rounded-full flex items-center justify-center interactive-element">
            <i class="fas fa-moon text-gray-700"></i>
        </button>
    </div>

    <!-- Header -->
    <header class="bg-white shadow-lg sticky top-0 z-50 transition-colors duration-300">
        <div class="container mx-auto px-4 py-4">
            <div class="flex justify-between items-center">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 bg-blue-500 rounded-lg flex items-center justify-center interactive-element">
                        <span class="text-white font-bold text-lg">EG</span>
                    </div>
                    <span class="text-xl font-bold text-gray-800">EasyGo</span>
                </div>
                
                <!-- Навигация по разделам -->
                <nav class="hidden md:flex space-x-8">
                    <a href="#search" class="font-medium nav-tab text-gray-700 py-2">Поиск</a>
                    <a href="#map-section" class="font-medium nav-tab text-gray-700 py-2">Карта</a>
                    <a href="#tickets" class="font-medium nav-tab text-gray-700 py-2">Билеты</a>
                    <a href="#favorites" class="font-medium nav-tab text-gray-700 py-2">Избранное</a>
                    <a href="#profile" class="font-medium nav-tab text-gray-700 py-2">Профиль</a>
                </nav>
                
                <div class="flex items-center space-x-4">
                    <div class="flex items-center space-x-3">
                        <div class="w-8 h-8 bg-blue-500 rounded-full flex items-center justify-center interactive-element">
                            <span class="text-white text-sm font-bold"><?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?></span>
                        </div>
                        <span class="font-medium text-gray-700 hidden sm:block"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                    </div>
                    <a href="logout.php" class="px-4 py-2 border border-gray-300 rounded-lg font-medium text-gray-700 hover:bg-gray-50 transition-all duration-300 hover:shadow-md">
                        Выйти
                    </a>
                </div>
            </div>
        </div>
    </header>

    <main class="py-8">
        <div class="container mx-auto px-4">
            <!-- Уведомления -->
            <?php if (!empty($success_message)): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6 floating-notification flex items-center fade-in">
                    <i class="fas fa-check-circle mr-2"></i>
                    <?php echo $success_message; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 floating-notification flex items-center fade-in">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <?php echo $error_message; ?>
                </div>
            <?php endif; ?>

            <!-- Заголовок и статистика -->
            <div class="bg-white rounded-2xl shadow-lg p-8 mb-8 transition-colors duration-300 spacious-container">
                <div class="flex justify-between items-center mb-8">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900 mb-2">Личный кабинет</h1>
                        <p class="text-gray-600 text-lg">Добро пожаловать, <span class="font-semibold text-blue-600"><?php echo htmlspecialchars($_SESSION['username']); ?></span>!</p>
                    </div>
                    <div class="flex items-center space-x-2 text-sm text-gray-500">
                        <span class="live-indicator"></span>
                        <span>LIVE данные</span>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                    <div class="stat-card bg-blue-50 rounded-xl p-6 text-center hover:bg-blue-100 cursor-pointer interactive-element transition-all duration-300" onclick="scrollToSection('favorites')">
                        <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-route text-blue-500 text-2xl"></i>
                        </div>
                        <h3 class="text-3xl font-bold text-gray-900"><?php echo count($favorites); ?></h3>
                        <p class="text-gray-600 mt-2">Избранных маршрутов</p>
                    </div>
                    
                    <div class="stat-card bg-green-50 rounded-xl p-6 text-center hover:bg-green-100 cursor-pointer interactive-element transition-all duration-300" onclick="scrollToSection('tickets')">
                        <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-ticket-alt text-green-500 text-2xl"></i>
                        </div>
                        <h3 class="text-3xl font-bold text-gray-900"><?php echo count($tickets); ?></h3>
                        <p class="text-gray-600 mt-2">Активных билетов</p>
                    </div>
                    
                    <div class="stat-card bg-purple-50 rounded-xl p-6 text-center hover:bg-purple-100 cursor-pointer interactive-element transition-all duration-300" onclick="scrollToSection('map-section')">
                        <div class="w-16 h-16 bg-purple-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-bus text-purple-500 text-2xl"></i>
                        </div>
                        <h3 class="text-3xl font-bold text-gray-900"><?php echo count($transport_data); ?></h3>
                        <p class="text-gray-600 mt-2">ТС на карте</p>
                    </div>
                    
                    <div class="stat-card bg-orange-50 rounded-xl p-6 text-center hover:bg-orange-100 cursor-pointer interactive-element transition-all duration-300">
                        <div class="w-16 h-16 bg-orange-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-calendar text-orange-500 text-2xl"></i>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900"><?php echo date('d.m.Y', strtotime($user['created_at'])); ?></h3>
                        <p class="text-gray-600 mt-2">Дата регистрации</p>
                    </div>
                </div>
            </div>

            <!-- Поиск маршрутов и остановок -->
            <section id="search" class="mb-8">
                <div class="search-form-container">
                    <h2 class="text-2xl font-bold text-white mb-6 flex items-center">
                        <i class="fas fa-search mr-3"></i>
                        Поиск маршрутов и остановок
                    </h2>
                    
                    <form method="POST" class="space-y-6" id="search-form">
                        <input type="hidden" name="action" value="search">
                        
                        <div class="grid md:grid-cols-4 gap-6">
                            <div class="md:col-span-3">
                                <label class="block text-sm font-medium text-white mb-3">
                                    <?php if ($search_type === 'route'): ?>
                                        Введите номер маршрута:
                                    <?php else: ?>
                                        Введите название остановки:
                                    <?php endif; ?>
                                </label>
                                <input type="text" name="search_query" class="search-input w-full px-6 py-4 rounded-lg text-lg" 
                                       placeholder="<?php echo $search_type === 'route' ? 'Например: 4, 8, 20, 64...' : 'Например: Центр, Вокзал...'; ?>" 
                                       value="<?php echo htmlspecialchars($search_query); ?>" required>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-white mb-3">Тип поиска</label>
                                <select name="search_type" class="search-select w-full px-6 py-4 rounded-lg text-lg">
                                    <option value="route" <?php echo $search_type === 'route' ? 'selected' : ''; ?>>Маршруты</option>
                                    <option value="stop" <?php echo $search_type === 'stop' ? 'selected' : ''; ?>>Остановки</option>
                                </select>
                            </div>
                        </div>
                        
                        <button type="submit" class="bg-white text-blue-600 px-8 py-4 rounded-lg font-semibold hover:bg-blue-50 transition-all duration-300 hover:shadow-lg text-lg w-full md:w-auto" id="search-btn">
                            <i class="fas fa-search mr-3"></i> Найти маршрут
                        </button>
                    </form>
                </div>

                <!-- Результаты поиска -->
                <?php if (!empty($search_results)): ?>
                    <div class="mt-8 bg-white rounded-2xl shadow-lg p-8 spacious-container">
                        <h3 class="text-xl font-semibold text-gray-900 mb-6">
                            Найдено <span class="text-blue-600"><?php echo count($search_results); ?> результатов</span>:
                        </h3>
                        <div class="space-y-4">
                            <?php foreach ($search_results as $result): ?>
                                <div class="search-result-card fade-in">
                                    <div class="flex justify-between items-start">
                                        <div class="flex-1">
                                            <?php if ($search_type === 'route'): ?>
                                                <!-- Результат поиска маршрутов -->
                                                <div class="flex items-start space-x-6">
                                                    <div class="bg-blue-100 text-blue-800 px-4 py-2 rounded-full text-lg font-semibold min-w-16 text-center">
                                                        <?php echo htmlspecialchars($result['route_number'] ?? '№'); ?>
                                                    </div>
                                                    <div class="flex-1">
                                                        <h4 class="font-semibold text-xl text-gray-900 mb-2">
                                                            <?php echo htmlspecialchars($result['route_name'] ?? 'Маршрут'); ?>
                                                        </h4>
                                                        <div class="text-gray-600 space-y-2">
                                                            <?php if (isset($result['price'])): ?>
                                                                <p class="flex items-center"><i class="fas fa-tag mr-3"></i> Цена: <?php echo $result['price']; ?> руб.</p>
                                                            <?php endif; ?>
                                                            <?php if (isset($result['interval_minutes'])): ?>
                                                                <p class="flex items-center"><i class="fas fa-clock mr-3"></i> Интервал: <?php echo $result['interval_minutes']; ?> мин.</p>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <!-- Результат поиска остановок -->
                                                <div class="flex items-start space-x-6">
                                                    <div class="bg-red-100 text-red-800 p-3 rounded-full">
                                                        <i class="fas fa-map-marker-alt text-xl"></i>
                                                    </div>
                                                    <div class="flex-1">
                                                        <h4 class="font-semibold text-xl text-gray-900 mb-2">
                                                            <?php echo htmlspecialchars($result['stop_name'] ?? 'Остановка'); ?>
                                                        </h4>
                                                        <div class="text-gray-600">
                                                            <?php if (isset($result['stop_address'])): ?>
                                                                <p class="flex items-center"><i class="fas fa-location-dot mr-3"></i> <?php echo htmlspecialchars($result['stop_address']); ?></p>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="flex space-x-3">
                                            <?php if ($search_type === 'route' && isset($result['route_id'])): ?>
                                                <a href="?add_to_favorites=<?php echo $result['route_id']; ?>" 
                                                   class="px-4 py-2 bg-yellow-500 text-white rounded-lg font-medium hover:bg-yellow-600 transition-colors favorite-add-btn interactive-element flex items-center">
                                                    <i class="fas fa-star mr-2"></i> В избранное
                                                </a>
                                                <button class="px-4 py-2 bg-blue-500 text-white rounded-lg font-medium hover:bg-blue-600 transition-colors schedule-btn interactive-element flex items-center"
                                                        onclick="openScheduleModal('<?php echo $result['route_id']; ?>', '<?php echo htmlspecialchars($result['route_number'] ?? ''); ?>', '<?php echo htmlspecialchars($result['route_name'] ?? ''); ?>')">
                                                    <i class="fas fa-clock mr-2"></i> Расписание
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php elseif (($_POST['action'] ?? '') === 'search'): ?>
                    <div class="mt-8 bg-white rounded-2xl shadow-lg p-12 text-center spacious-container">
                        <i class="fas fa-search text-6xl text-gray-300 mb-4"></i>
                        <p class="text-gray-500 text-xl mb-2">По вашему запросу ничего не найдено</p>
                        <p class="text-gray-400 text-lg">
                            <?php if ($search_type === 'route'): ?>
                                Попробуйте ввести другой номер маршрута
                            <?php else: ?>
                                Попробуйте ввести другое название остановки
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Интерактивная карта -->
            <section id="map-section" class="bg-white rounded-2xl shadow-lg p-8 mb-8 transition-colors duration-300 spacious-container">
                <h2 class="text-2xl font-bold text-gray-900 mb-6 flex items-center">
                    <i class="fas fa-map-marked-alt mr-3 text-green-500"></i>
                    Карта транспорта Иркутска
                    <span class="live-indicator ml-2"></span>
                </h2>
                
                <!-- Фильтры карты -->
                <div class="map-filters mb-6 flex space-x-3 overflow-x-auto pb-2">
                    <button class="filter-btn px-4 py-2 bg-blue-500 text-white rounded-full text-sm font-medium transition-all duration-300 hover:shadow-md interactive-element" data-type="all">
                        Все <span class="type-count">(<?php echo count($transport_data); ?>)</span>
                    </button>
                    <?php
                    $bus_count = count(array_filter($transport_data, fn($v) => $v['vehicle_type'] === 'bus'));
                    $trolleybus_count = count(array_filter($transport_data, fn($v) => $v['vehicle_type'] === 'trolleybus'));
                    $tram_count = count(array_filter($transport_data, fn($v) => $v['vehicle_type'] === 'tram'));
                    ?>
                    <button class="filter-btn px-4 py-2 bg-green-100 text-green-800 rounded-full text-sm font-medium transition-all duration-300 hover:shadow-md hover:bg-green-200 interactive-element" data-type="bus">
                        Автобусы <span class="type-count">(<?php echo $bus_count; ?>)</span>
                    </button>
                    <button class="filter-btn px-4 py-2 bg-yellow-100 text-yellow-800 rounded-full text-sm font-medium transition-all duration-300 hover:shadow-md hover:bg-yellow-200 interactive-element" data-type="trolleybus">
                        Троллейбусы <span class="type-count">(<?php echo $trolleybus_count; ?>)</span>
                    </button>
                    <button class="filter-btn px-4 py-2 bg-red-100 text-red-800 rounded-full text-sm font-medium transition-all duration-300 hover:shadow-md hover:bg-red-200 interactive-element" data-type="tram">
                        Трамваи <span class="type-count">(<?php echo $tram_count; ?>)</span>
                    </button>
                </div>
                
                <!-- Контейнер карты -->
                <div id="map"></div>
                
                <!-- Управление картой -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-6">
                    <button id="refresh-map" class="px-6 py-3 border border-gray-300 rounded-lg font-medium text-gray-700 hover:bg-gray-50 transition-all duration-300 hover:shadow-md text-lg">
                        <i class="fas fa-sync-alt mr-3"></i> Обновить карту
                    </button>
                    <button id="auto-update-toggle" class="px-6 py-3 bg-blue-500 text-white rounded-lg font-medium hover:bg-blue-600 transition-all duration-300 hover:shadow-md text-lg">
                        <i class="fas fa-play mr-3"></i> Автообновление
                    </button>
                </div>
                <p class="text-sm text-gray-500 mt-3 text-center">Обновлено: <span id="update-time"><?php echo date('H:i:s'); ?></span></p>
            </section>

            <!-- Билеты -->
            <section id="tickets" class="bg-white rounded-2xl shadow-lg p-8 mb-8 transition-colors duration-300 spacious-container">
                <h2 class="text-2xl font-bold text-gray-900 mb-6 flex items-center">
                    <i class="fas fa-ticket-alt mr-3 text-purple-500"></i>
                    Мои билеты
                </h2>

                <!-- Активные билеты -->
                <div class="mb-8">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-semibold text-gray-900">Активные билеты</h3>
                        <button id="open-scanner" class="px-6 py-3 bg-green-500 text-white rounded-lg font-medium hover:bg-green-600 transition-all duration-300 hover:shadow-md text-lg">
                            <i class="fas fa-qrcode mr-3"></i> Сканировать QR
                        </button>
                    </div>
                    
                    <?php if (empty($tickets)): ?>
                        <div class="text-center py-12">
                            <i class="fas fa-ticket-alt text-6xl text-gray-300 mb-4"></i>
                            <p class="text-gray-500 text-xl">Нет активных билетов</p>
                            <p class="text-gray-400 text-lg mt-2">Приобретите билет ниже</p>
                        </div>
                    <?php else: ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <?php foreach ($tickets as $ticket): ?>
                                <div class="ticket-card fade-in">
                                    <div class="ticket-type-badge">
                                        <?php 
                                        $type_names = [
                                            'single' => 'Разовый',
                                            'day' => 'Суточный',
                                            'week' => 'Недельный',
                                            'month' => 'Месячный'
                                        ];
                                        echo $type_names[$ticket['ticket_type']] ?? $ticket['ticket_type'];
                                        ?>
                                        <?php if ($ticket['rides_remaining'] == 999): ?>
                                            <span class="ticket-rides ticket-unlimited">∞</span>
                                        <?php else: ?>
                                            <span class="ticket-rides"><?php echo $ticket['rides_remaining']; ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mb-6">
                                        <h4 class="text-2xl font-bold mb-3">Проездной билет</h4>
                                        <p class="text-blue-100 text-lg">№<?php echo $ticket['ticket_id']; ?></p>
                                    </div>
                                    <div class="space-y-3 text-base">
                                        <p><i class="fas fa-ruble-sign mr-3"></i> Стоимость: <?php echo $ticket['price']; ?> руб.</p>
                                        <p><i class="fas fa-route mr-3"></i> Поездок: 
                                            <?php if ($ticket['rides_remaining'] == 999): ?>
                                                неограниченно
                                            <?php else: ?>
                                                осталось <?php echo $ticket['rides_remaining']; ?>
                                            <?php endif; ?>
                                        </p>
                                        <p><i class="fas fa-clock mr-3"></i> Действует до: <?php echo date('d.m.Y H:i', strtotime($ticket['expires_at'])); ?></p>
                                        <p><i class="fas fa-calendar mr-3"></i> Куплен: <?php echo date('d.m.Y', strtotime($ticket['created_at'])); ?></p>
                                    </div>
                                    <div class="mt-6 text-center">
                                        <button class="use-ticket-btn w-full text-lg py-3" 
                                                data-ticket-id="<?php echo $ticket['ticket_id']; ?>"
                                                <?php echo ($ticket['rides_remaining'] == 0 && $ticket['rides_remaining'] != 999) ? 'disabled' : ''; ?>>
                                            <i class="fas fa-qrcode mr-3"></i>
                                            Сканировать для поездки
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- История поездок -->
                <?php if (!empty($ride_history)): ?>
                <div class="mt-8">
                    <h3 class="text-xl font-semibold text-gray-900 mb-6">История поездок</h3>
                    <div class="space-y-4">
                        <?php foreach ($ride_history as $ride): ?>
                            <div class="ride-history-item fade-in">
                                <div class="flex justify-between items-center">
                                    <div>
                                        <p class="font-semibold text-gray-900 text-lg">
                                            <?php 
                                            $type_names = [
                                                'single' => 'Разовый',
                                                'day' => 'Суточный',
                                                'week' => 'Недельный',
                                                'month' => 'Месячный'
                                            ];
                                            echo $type_names[$ride['ticket_type']] ?? $ride['ticket_type'];
                                            ?>
                                        </p>
                                        <p class="text-gray-600 text-base">
                                            Транспорт: <?php echo htmlspecialchars($ride['vehicle_qr']); ?> • 
                                            <?php echo date('d.m.Y H:i', strtotime($ride['ride_date'])); ?>
                                        </p>
                                    </div>
                                    <div class="text-right">
                                        <p class="font-semibold text-gray-900 text-lg">
                                            <?php echo $ride['fare_amount'] > 0 ? '-' . $ride['fare_amount'] . ' ₽' : 'Списано'; ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Покупка билетов -->
                <div class="mt-8">
                    <h3 class="text-xl font-semibold text-gray-900 mb-6">Приобрести билет</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        <!-- Разовый билет -->
                        <div class="pricing-card bg-white border border-gray-200 rounded-xl p-6 text-center">
                            <h4 class="text-lg font-semibold text-gray-900 mb-3">Разовый</h4>
                            <div class="text-3xl font-bold text-gray-900 mb-4">30 ₽</div>
                            <ul class="text-gray-600 space-y-3 mb-6">
                                <li class="flex items-center"><i class="fas fa-check text-green-500 mr-2"></i> 1 поездка</li>
                                <li class="flex items-center"><i class="fas fa-check text-green-500 mr-2"></i> Действует 1 час</li>
                                <li class="flex items-center"><i class="fas fa-check text-green-500 mr-2"></i> Любой транспорт</li>
                            </ul>
                            <button onclick="openPaymentModal('single', 'Разовый билет', '1 поездка, действует 1 час', 30)" class="px-4 py-3 border border-gray-300 rounded-lg font-medium text-gray-700 hover:bg-gray-50 transition-all duration-300 hover:shadow-md w-full text-lg">
                                <i class="fas fa-shopping-cart mr-2"></i> Купить
                            </button>
                        </div>

                        <!-- Суточный билет -->
                        <div class="pricing-card bg-white border border-gray-200 rounded-xl p-6 text-center popular">
                            <div class="popular-badge">Популярный</div>
                            <h4 class="text-lg font-semibold text-gray-900 mb-3">Суточный</h4>
                            <div class="text-3xl font-bold text-gray-900 mb-4">100 ₽</div>
                            <ul class="text-gray-600 space-y-3 mb-6">
                                <li class="flex items-center"><i class="fas fa-check text-green-500 mr-2"></i> Неограниченно поездок</li>
                                <li class="flex items-center"><i class="fas fa-check text-green-500 mr-2"></i> Действует 24 часа</li>
                                <li class="flex items-center"><i class="fas fa-check text-green-500 mr-2"></i> Любой транспорт</li>
                            </ul>
                            <button onclick="openPaymentModal('day', 'Суточный билет', 'Неограниченно поездок, действует 24 часа', 100)" class="px-4 py-3 bg-blue-500 text-white rounded-lg font-medium hover:bg-blue-600 transition-all duration-300 hover:shadow-md w-full text-lg">
                                <i class="fas fa-shopping-cart mr-2"></i> Купить
                            </button>
                        </div>

                        <!-- Недельный билет -->
                        <div class="pricing-card bg-white border border-gray-200 rounded-xl p-6 text-center">
                            <h4 class="text-lg font-semibold text-gray-900 mb-3">Недельный</h4>
                            <div class="text-3xl font-bold text-gray-900 mb-4">500 ₽</div>
                            <ul class="text-gray-600 space-y-3 mb-6">
                                <li class="flex items-center"><i class="fas fa-check text-green-500 mr-2"></i> Неограниченно поездок</li>
                                <li class="flex items-center"><i class="fas fa-check text-green-500 mr-2"></i> Действует 7 дней</li>
                                <li class="flex items-center"><i class="fas fa-check text-green-500 mr-2"></i> Любой транспорт</li>
                            </ul>
                            <button onclick="openPaymentModal('week', 'Недельный билет', 'Неограниченно поездок, действует 7 дней', 500)" class="px-4 py-3 border border-gray-300 rounded-lg font-medium text-gray-700 hover:bg-gray-50 transition-all duration-300 hover:shadow-md w-full text-lg">
                                <i class="fas fa-shopping-cart mr-2"></i> Купить
                            </button>
                        </div>

                        <!-- Месячный билет -->
                        <div class="pricing-card bg-white border border-gray-200 rounded-xl p-6 text-center">
                            <h4 class="text-lg font-semibold text-gray-900 mb-3">Месячный</h4>
                            <div class="text-3xl font-bold text-gray-900 mb-4">1500 ₽</div>
                            <ul class="text-gray-600 space-y-3 mb-6">
                                <li class="flex items-center"><i class="fas fa-check text-green-500 mr-2"></i> Неограниченно поездок</li>
                                <li class="flex items-center"><i class="fas fa-check text-green-500 mr-2"></i> Действует 30 дней</li>
                                <li class="flex items-center"><i class="fas fa-check text-green-500 mr-2"></i> Любой транспорт</li>
                            </ul>
                            <button onclick="openPaymentModal('month', 'Месячный билет', 'Неограниченно поездок, действует 30 дней', 1500)" class="px-4 py-3 border border-gray-300 rounded-lg font-medium text-gray-700 hover:bg-gray-50 transition-all duration-300 hover:shadow-md w-full text-lg">
                                <i class="fas fa-shopping-cart mr-2"></i> Купить
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Добавление маршрута в избранное -->
                <section id="add-route" class="bg-white rounded-2xl shadow-lg p-8 transition-colors duration-300 spacious-container">
                    <h2 class="text-2xl font-bold text-gray-900 mb-6 flex items-center">
                        <i class="fas fa-plus-circle mr-3 text-green-500"></i>
                        Добавить маршрут
                    </h2>
                    <form method="POST" id="add-favorite-form">
                        <input type="hidden" name="action" value="add_favorite">
                        <div class="space-y-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-3">Номер маршрута *</label>
                                <input type="text" name="route_number" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-300 text-lg" 
                                       placeholder="Например: 4, 8, 20, 64..." required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-3">Название маршрута *</label>
                                <input type="text" name="route_name" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-300 text-lg" 
                                       placeholder="Например: Дом-Работа" required>
                            </div>
                            <button type="submit" class="px-6 py-3 border border-gray-300 rounded-lg font-medium text-gray-700 hover:bg-gray-50 transition-all duration-300 hover:shadow-md w-full text-lg" id="add-favorite-btn">
                                <i class="fas fa-star mr-3"></i> Добавить в избранное
                            </button>
                        </div>
                    </form>
                </section>

                <!-- Избранные маршруты -->
                <section id="favorites" class="bg-white rounded-2xl shadow-lg p-8 transition-colors duration-300 spacious-container">
                    <h2 class="text-2xl font-bold text-gray-900 mb-6 flex items-center">
                        <i class="fas fa-star mr-3 text-yellow-500"></i>
                        Избранные маршруты
                    </h2>
                    <?php if (!empty($favorites_error)): ?>
                        <p class="text-red-500 text-lg"><?php echo $favorites_error; ?></p>
                    <?php elseif (empty($favorites)): ?>
                        <div class="text-center py-8">
                            <i class="fas fa-route text-6xl text-gray-300 mb-4"></i>
                            <p class="text-gray-500 text-xl">Нет избранных маршрутов</p>
                            <p class="text-gray-400 text-lg mt-2">Добавьте маршруты через поиск или форму слева</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($favorites as $favorite): ?>
                                <div class="favorite-card border border-gray-200 rounded-lg p-6 hover:border-blue-300 transition-colors fade-in">
                                    <div class="flex justify-between items-start">
                                        <div class="flex items-start space-x-4">
                                            <?php if (isset($favorite['route_number'])): ?>
                                                <div class="bg-blue-100 text-blue-800 px-4 py-2 rounded text-lg font-semibold">
                                                    <?php echo htmlspecialchars($favorite['route_number']); ?>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <h3 class="font-semibold text-xl"><?php echo htmlspecialchars($favorite['route_name'] ?? 'Маршрут'); ?></h3>
                                            </div>
                                        </div>
                                        <div class="flex space-x-3">
                                            <button class="text-blue-500 hover:text-blue-700 transition-colors p-2 schedule-btn interactive-element text-lg"
                                                    onclick="openScheduleModal('<?php echo $favorite['route_id']; ?>', '<?php echo htmlspecialchars($favorite['route_number'] ?? ''); ?>', '<?php echo htmlspecialchars($favorite['route_name'] ?? ''); ?>')"
                                                    title="Посмотреть расписание">
                                                <i class="fas fa-clock"></i>
                                            </button>
                                            <a href="?delete=<?php echo $favorite['route_id']; ?>" 
                                               class="text-red-500 hover:text-red-700 transition-colors p-2 delete-favorite-btn interactive-element text-lg"
                                               onclick="return confirm('Удалить маршрут из избранного?')"
                                               title="Удалить из избранного">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </div>
                                    <div class="text-sm text-gray-400 mt-3">
                                        Добавлено: <?php echo date('d.m.Y H:i', strtotime($favorite['created_at'])); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <!-- Настройки профиля -->
            <section id="profile" class="bg-white rounded-2xl shadow-lg p-8 mt-8 transition-colors duration-300 spacious-container">
                <h2 class="text-2xl font-bold text-gray-900 mb-6 flex items-center">
                    <i class="fas fa-user-cog mr-3 text-blue-500"></i>
                    Настройки профиля
                </h2>
                
                <div class="flex flex-col items-center mb-8">
                    <div class="w-24 h-24 bg-gradient-to-br from-blue-500 to-purple-600 rounded-full flex items-center justify-center mb-4 shadow-lg interactive-element">
                        <span class="text-white text-3xl font-bold"><?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?></span>
                    </div>
                    <p class="text-xl font-semibold text-gray-800"><?php echo htmlspecialchars($_SESSION['username']); ?></p>
                    <p class="text-gray-500 mt-1">Участник с <?php echo date('d.m.Y', strtotime($user['created_at'])); ?></p>
                </div>
                
                <form method="POST" id="profile-form">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="space-y-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-3">Имя пользователя *</label>
                            <input type="text" name="username" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-300 text-lg" 
                                   value="<?php echo htmlspecialchars($user['username']); ?>" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-3">Email *</label>
                            <input type="email" name="email" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-300 text-lg" 
                                   value="<?php echo htmlspecialchars($user['email']); ?>" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-3">Телефон</label>
                            <input type="tel" name="phone" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-300 text-lg" 
                                   value="<?php echo htmlspecialchars($user['phone']); ?>" 
                                   placeholder="+7 (XXX) XXX-XX-XX">
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="flex items-center space-x-3 cursor-pointer">
                                    <input type="checkbox" name="notifications_enabled" value="1" 
                                           <?php echo $user['notifications_enabled'] ? 'checked' : ''; ?> 
                                           class="rounded border-gray-300 w-5 h-5">
                                    <span class="text-gray-700 text-lg">Уведомления</span>
                                </label>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-3">Тема</label>
                                <select name="theme" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-300 text-lg">
                                    <option value="light" <?php echo $user['theme'] === 'light' ? 'selected' : ''; ?>>Светлая</option>
                                    <option value="dark" <?php echo $user['theme'] === 'dark' ? 'selected' : ''; ?>>Темная</option>
                                </select>
                            </div>
                        </div>
                        
                        <button type="submit" class="px-6 py-3 bg-blue-500 text-white rounded-lg font-medium hover:bg-blue-600 transition-all duration-300 hover:shadow-md w-full text-lg" id="save-profile-btn">
                            <i class="fas fa-save mr-3"></i> Сохранить изменения
                        </button>
                    </div>
                </form>
            </section>

            <!-- История поиска -->
            <section id="history" class="bg-white rounded-2xl shadow-lg p-8 mt-8 transition-colors duration-300 spacious-container">
                <h2 class="text-2xl font-bold text-gray-900 mb-6 flex items-center">
                    <i class="fas fa-history mr-3 text-purple-500"></i>
                    История поиска
                </h2>
                <?php if (!empty($history_error)): ?>
                    <p class="text-red-500 text-lg"><?php echo $history_error; ?></p>
                <?php elseif (empty($history)): ?>
                    <div class="text-center py-8">
                        <i class="fas fa-history text-6xl text-gray-300 mb-4"></i>
                        <p class="text-gray-500 text-xl">История поиска пуста</p>
                        <p class="text-gray-400 text-lg mt-2">Ваши поисковые запросы появятся здесь</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($history as $item): ?>
                            <div class="border-l-4 border-blue-500 bg-gray-50 p-4 rounded-r-lg hover:bg-gray-100 transition-colors history-item fade-in">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <p class="text-gray-800 font-medium text-lg">
                                            "<?php echo htmlspecialchars($item['search_query'] ?? 'Поиск'); ?>"
                                        </p>
                                        <p class="text-gray-500 mt-2">
                                            <span class="bg-blue-100 text-blue-800 px-3 py-1 rounded text-sm">
                                                <?php echo $item['search_type'] === 'route' ? 'Маршруты' : 'Остановки'; ?>
                                            </span>
                                            • <?php echo date('d.m.Y в H:i', strtotime($item['created_at'])); ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <script>
        // Инициализация карты
        function initMap() {
            // Центр карты на Иркутске
            const map = L.map('map').setView([52.2864, 104.2806], 13);
            
            // Добавляем слой карты
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(map);
            
            // Иконки для разных типов транспорта
            const icons = {
                bus: L.divIcon({
                    html: '<div class="bus-marker"><i class="fas fa-bus"></i></div>',
                    className: 'bus-icon',
                    iconSize: [30, 30],
                    iconAnchor: [15, 15]
                }),
                trolleybus: L.divIcon({
                    html: '<div class="trolleybus-marker"><i class="fas fa-bus"></i></div>',
                    className: 'trolleybus-icon',
                    iconSize: [30, 30],
                    iconAnchor: [15, 15]
                }),
                tram: L.divIcon({
                    html: '<div class="tram-marker"><i class="fas fa-train"></i></div>',
                    className: 'tram-icon',
                    iconSize: [30, 30],
                    iconAnchor: [15, 15]
                })
            };
            
            // Добавляем маркеры транспорта
            const transportData = <?php echo json_encode($transport_data); ?>;
            const markers = [];
            
            transportData.forEach(vehicle => {
                const marker = L.marker([vehicle.latitude, vehicle.longitude], {
                    icon: icons[vehicle.vehicle_type]
                }).addTo(map);
                
                marker.bindPopup(`
                    <div class="p-2">
                        <h4 class="font-bold">Маршрут ${vehicle.route_number}</h4>
                        <p>Тип: ${getVehicleTypeName(vehicle.vehicle_type)}</p>
                        <p>Скорость: ${vehicle.speed} км/ч</p>
                        <p>Вместимость: ${vehicle.capacity} чел.</p>
                        <p>Обновлено: ${new Date(vehicle.last_update).toLocaleTimeString()}</p>
                    </div>
                `);
                
                markers.push({
                    marker: marker,
                    type: vehicle.vehicle_type
                });
            });
            
            // Функция для получения названия типа транспорта
            function getVehicleTypeName(type) {
                const names = {
                    'bus': 'Автобус',
                    'trolleybus': 'Троллейбус',
                    'tram': 'Трамвай'
                };
                return names[type] || type;
            }
            
            // Фильтрация маркеров
            document.querySelectorAll('.filter-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const type = this.dataset.type;
                    
                    markers.forEach(item => {
                        if (type === 'all' || item.type === type) {
                            item.marker.addTo(map);
                        } else {
                            map.removeLayer(item.marker);
                        }
                    });
                    
                    // Обновляем активную кнопку
                    document.querySelectorAll('.filter-btn').forEach(b => {
                        b.classList.remove('bg-blue-500', 'text-white');
                        b.classList.add('bg-gray-100', 'text-gray-800');
                    });
                    this.classList.remove('bg-gray-100', 'text-gray-800');
                    this.classList.add('bg-blue-500', 'text-white');
                });
            });
            
            // Обновление времени
            document.getElementById('refresh-map').addEventListener('click', function() {
                document.getElementById('update-time').textContent = new Date().toLocaleTimeString();
                this.innerHTML = '<i class="fas fa-check mr-2"></i> Обновлено';
                setTimeout(() => {
                    this.innerHTML = '<i class="fas fa-sync-alt mr-2"></i> Обновить карту';
                }, 2000);
            });
            
            // Автообновление
            let autoUpdateInterval = null;
            document.getElementById('auto-update-toggle').addEventListener('click', function() {
                if (autoUpdateInterval) {
                    clearInterval(autoUpdateInterval);
                    autoUpdateInterval = null;
                    this.innerHTML = '<i class="fas fa-play mr-2"></i> Автообновление';
                    this.classList.remove('bg-red-500');
                    this.classList.add('bg-blue-500');
                } else {
                    autoUpdateInterval = setInterval(() => {
                        document.getElementById('update-time').textContent = new Date().toLocaleTimeString();
                        console.log('Автообновление карты...');
                    }, 10000);
                    this.innerHTML = '<i class="fas fa-pause mr-2"></i> Остановить';
                    this.classList.remove('bg-blue-500');
                    this.classList.add('bg-red-500');
                }
            });
            
            // Добавляем контроль масштаба
            L.control.zoom({
                position: 'topright'
            }).addTo(map);
            
            return map;
        }
        
        // Функция для открытия модального окна расписания
        function openScheduleModal(routeId, routeNumber, routeName) {
            const modal = document.getElementById('schedule-modal');
            const title = document.getElementById('schedule-title');
            
            title.textContent = `Расписание маршрута ${routeNumber} - ${routeName}`;
            modal.style.display = 'block';
            
            setTimeout(() => {
                document.querySelector('#schedule-modal .modal-content').classList.add('active');
            }, 10);
            
            // Загружаем расписание
            loadSchedule(routeId);
            
            // Сохраняем ID маршрута для фильтрации
            modal.dataset.routeId = routeId;
        }

        // Функция загрузки расписания
        function loadSchedule(routeId, dayType = 'both') {
            const tableBody = document.getElementById('schedule-table-body');
            const departuresList = document.getElementById('departures-list');
            
            // Показываем загрузку
            tableBody.innerHTML = '<tr><td colspan="4" class="px-4 py-4 text-center"><i class="fas fa-spinner fa-spin mr-2"></i>Загрузка расписания...</td></tr>';
            departuresList.innerHTML = '<div class="text-blue-600"><i class="fas fa-spinner fa-spin mr-2"></i>Загрузка...</div>';
            
            // AJAX запрос для получения расписания
            fetch(`?action=get_schedule&route_id=${routeId}&day_type=${dayType}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Обновляем таблицу расписания
                        if (data.schedule.length > 0) {
                            tableBody.innerHTML = data.schedule.map((stop, index) => `
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 text-sm text-gray-900">${stop.stop_order}</td>
                                    <td class="px-4 py-3">
                                        <div class="text-sm font-medium text-gray-900">${stop.stop_name}</div>
                                        <div class="text-sm text-gray-500">${stop.stop_address || ''}</div>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-900">${stop.arrival_time}</td>
                                    <td class="px-4 py-3">
                                        <span class="day-type-badge ${
                                            stop.day_type === 'weekday' ? 'day-type-weekday' :
                                            stop.day_type === 'weekend' ? 'day-type-weekend' :
                                            'day-type-both'
                                        }">
                                            ${
                                                stop.day_type === 'weekday' ? 'Будни' :
                                                stop.day_type === 'weekend' ? 'Выходные' : 'Все дни'
                                            }
                                        </span>
                                    </td>
                                </tr>
                            `).join('');
                        } else {
                            tableBody.innerHTML = '<tr><td colspan="4" class="px-4 py-4 text-center text-gray-500">Расписание не найдено</td></tr>';
                        }
                        
                        // Обновляем ближайшие отправления
                        if (data.next_departures.length > 0) {
                            departuresList.innerHTML = data.next_departures.map(departure => `
                                <div class="departure-card">
                                    <div class="flex justify-between items-center">
                                        <span class="font-medium text-gray-900">${departure.stop_name}</span>
                                        <div class="text-right">
                                            <div class="font-semibold text-gray-900">${departure.arrival_time}</div>
                                            <div class="text-sm time-until">
                                                через ${departure.time_until}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            `).join('');
                        } else {
                            departuresList.innerHTML = '<div class="text-gray-500 text-center">Нет ближайших отправлений</div>';
                        }
                    } else {
                        tableBody.innerHTML = '<tr><td colspan="4" class="px-4 py-4 text-center text-red-500">Ошибка загрузки расписания</td></tr>';
                        departuresList.innerHTML = '<div class="text-red-500 text-center">Ошибка загрузки</div>';
                    }
                })
                .catch(error => {
                    console.error('Ошибка:', error);
                    tableBody.innerHTML = '<tr><td colspan="4" class="px-4 py-4 text-center text-red-500">Ошибка загрузки</td></tr>';
                    departuresList.innerHTML = '<div class="text-red-500 text-center">Ошибка загрузки</div>';
                });
        }

        // Закрытие модального окна расписания
        document.getElementById('close-schedule-modal').addEventListener('click', closeScheduleModal);
        document.getElementById('close-schedule').addEventListener('click', closeScheduleModal);

        function closeScheduleModal() {
            const modal = document.getElementById('schedule-modal');
            document.querySelector('#schedule-modal .modal-content').classList.remove('active');
            setTimeout(() => {
                modal.style.display = 'none';
            }, 300);
        }

        // Обработка изменения фильтра дней
        document.querySelectorAll('.schedule-filter').forEach(radio => {
            radio.addEventListener('change', function() {
                const modal = document.getElementById('schedule-modal');
                const routeId = modal.dataset.routeId;
                if (routeId && this.checked) {
                    loadSchedule(routeId, this.value);
                }
            });
        });
        
        // Переменные для модального окна оплаты
        let currentTariffType = '';
        let currentTariffPrice = 0;

        // Функция открытия модального окна оплаты
        function openPaymentModal(tariffType, tariffName, description, price) {
            currentTariffType = tariffType;
            currentTariffPrice = price;
            
            const modal = document.getElementById('payment-modal');
            const tariffNameEl = document.getElementById('tariff-name');
            const tariffDescEl = document.getElementById('tariff-description');
            const tariffPriceEl = document.getElementById('tariff-price');
            const payAmountEl = document.getElementById('pay-amount');
            
            tariffNameEl.textContent = tariffName;
            tariffDescEl.textContent = description;
            tariffPriceEl.textContent = price + ' ₽';
            payAmountEl.textContent = price + ' ₽';
            
            modal.style.display = 'block';
            setTimeout(() => {
                modal.querySelector('.modal-content').classList.add('active');
            }, 10);
            
            resetPaymentForm();
        }

        // Функция закрытия модального окна оплаты
        function closePaymentModal() {
            const modal = document.getElementById('payment-modal');
            modal.querySelector('.modal-content').classList.remove('active');
            setTimeout(() => {
                modal.style.display = 'none';
            }, 300);
        }

        // Сброс формы оплаты
        function resetPaymentForm() {
            document.getElementById('payment-form-data').style.display = 'block';
            document.getElementById('payment-processing').style.display = 'none';
            document.getElementById('payment-success').style.display = 'none';
            document.getElementById('payment-error').style.display = 'none';
            document.getElementById('payment-form-data').reset();
            clearErrors();
        }

        // Очистка ошибок
        function clearErrors() {
            document.querySelectorAll('.error-message').forEach(el => el.textContent = '');
            document.querySelectorAll('.card-input').forEach(el => el.classList.remove('input-error'));
        }

        // Валидация данных карты
        function validateCardData(cardNumber, cardExpiry, cardCvv, cardHolder) {
            let isValid = true;
            clearErrors();

            // Валидация номера карты (только цифры, 16 символов)
            const cardNumberRegex = /^\d{16}$/;
            if (!cardNumberRegex.test(cardNumber)) {
                document.getElementById('card-number').classList.add('input-error');
                document.getElementById('card-number-error').textContent = 'Номер карты должен содержать 16 цифр';
                isValid = false;
            }

            // Валидация срока действия (ММ/ГГ)
            const expiryRegex = /^(0[1-9]|1[0-2])\/([0-9]{2})$/;
            if (!expiryRegex.test(cardExpiry)) {
                document.getElementById('card-expiry').classList.add('input-error');
                document.getElementById('card-expiry-error').textContent = 'Введите срок в формате ММ/ГГ';
                isValid = false;
            }

            // Валидация CVV (3 цифры)
            const cvvRegex = /^\d{3}$/;
            if (!cvvRegex.test(cardCvv)) {
                document.getElementById('card-cvv').classList.add('input-error');
                document.getElementById('card-cvv-error').textContent = 'CVV должен содержать 3 цифры';
                isValid = false;
            }

            // Валидация имени владельца (только буквы и пробелы)
            const holderRegex = /^[A-Za-zА-Яа-я\s]+$/;
            if (!holderRegex.test(cardHolder) || cardHolder.trim().length < 2) {
                document.getElementById('card-holder').classList.add('input-error');
                document.getElementById('card-holder-error').textContent = 'Введите корректное имя владельца';
                isValid = false;
            }

            return isValid;
        }

        // Ограничение ввода только цифр
        function restrictToNumbers(input) {
            input.addEventListener('input', function(e) {
                this.value = this.value.replace(/\D/g, '');
            });
        }

        // Форматирование номера карты
        function formatCardNumber(input) {
            input.addEventListener('input', function(e) {
                let value = e.target.value.replace(/\s+/g, '').replace(/[^0-9]/gi, '');
                let formattedValue = '';
                
                for (let i = 0; i < value.length; i++) {
                    if (i > 0 && i % 4 === 0) {
                        formattedValue += ' ';
                    }
                    formattedValue += value[i];
                }
                
                e.target.value = formattedValue;
            });
        }

        // Форматирование срока действия
        function formatExpiryDate(input) {
            input.addEventListener('input', function(e) {
                let value = e.target.value.replace(/\D/g, '');
                if (value.length >= 2) {
                    e.target.value = value.substring(0, 2) + '/' + value.substring(2, 4);
                }
            });
        }
        
        // Инициализация после загрузки DOM
        document.addEventListener('DOMContentLoaded', function() {
            const map = initMap();
            
            // Инициализация модального окна оплаты
            const paymentModal = document.getElementById('payment-modal');
            const closePaymentBtn = document.getElementById('close-payment-modal');
            const paymentForm = document.getElementById('payment-form-data');
            const paymentProcessing = document.getElementById('payment-processing');
            const paymentSuccess = document.getElementById('payment-success');
            const paymentError = document.getElementById('payment-error');
            const successClose = document.getElementById('success-close');
            const errorRetry = document.getElementById('error-retry');
            const errorClose = document.getElementById('error-close');

            // Закрытие модального окна
            closePaymentBtn.addEventListener('click', closePaymentModal);
            successClose.addEventListener('click', closePaymentModal);
            errorClose.addEventListener('click', closePaymentModal);
            
            // Повторная попытка при ошибке
            errorRetry.addEventListener('click', function() {
                resetPaymentForm();
            });

            // Настройка валидации полей
            restrictToNumbers(document.getElementById('card-cvv'));
            formatCardNumber(document.getElementById('card-number'));
            formatExpiryDate(document.getElementById('card-expiry'));

            // Обработка формы оплаты
            paymentForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Получаем данные формы
                const cardNumber = document.getElementById('card-number').value.replace(/\s/g, '');
                const cardExpiry = document.getElementById('card-expiry').value;
                const cardCvv = document.getElementById('card-cvv').value;
                const cardHolder = document.getElementById('card-holder').value;
                
                // Валидация
                if (!validateCardData(cardNumber, cardExpiry, cardCvv, cardHolder)) {
                    return;
                }
                
                // Показываем процесс обработки
                paymentForm.style.display = 'none';
                paymentProcessing.style.display = 'block';
                
                // Имитация обработки платежа (50% успеха для демонстрации)
                setTimeout(() => {
                    const isSuccess = Math.random() > 0.5;
                    
                    if (isSuccess) {
                        paymentProcessing.style.display = 'none';
                        paymentSuccess.style.display = 'block';
                        document.getElementById('success-details').textContent = 
                            `Тариф "${document.getElementById('tariff-name').textContent}" успешно активирован`;
                        
                        // Отправляем запрос на сервер для покупки билета
                        const formData = new FormData();
                        formData.append('action', 'buy_ticket');
                        formData.append('ticket_type', currentTariffType);
                        
                        fetch(window.location.href, {
                            method: 'POST',
                            body: formData
                        }).then(response => {
                            // Обновляем страницу через 3 секунды
                            setTimeout(() => {
                                window.location.reload();
                            }, 3000);
                        });
                    } else {
                        paymentProcessing.style.display = 'none';
                        paymentError.style.display = 'block';
                        document.getElementById('error-message').textContent = 
                            'Ошибка обработки платежа. Проверьте данные карты и попробуйте снова';
                    }
                }, 3000);
            });

            // Модальное окно сканирования
            const scanModal = document.getElementById('scan-modal');
            const closeScanModal = document.getElementById('close-scan-modal');
            const openScanner = document.getElementById('open-scanner');
            const startScan = document.getElementById('start-scan');
            const stopScan = document.getElementById('stop-scan');
            const scanResult = document.getElementById('scan-result');
            const useTicketButtons = document.querySelectorAll('.use-ticket-btn');
            
            let html5QrcodeScanner = null;
            let currentTicketId = null;

            // Открытие сканера по кнопке в хедере
            openScanner.addEventListener('click', function() {
                currentTicketId = null;
                openScanModal();
            });

            // Открытие сканера для конкретного билета
            useTicketButtons.forEach(button => {
                button.addEventListener('click', function() {
                    if (!this.disabled) {
                        currentTicketId = this.dataset.ticketId;
                        openScanModal();
                    }
                });
            });

            function openScanModal() {
                scanModal.style.display = 'block';
                setTimeout(() => {
                    document.querySelector('#scan-modal .modal-content').classList.add('active');
                }, 10);
            }

            function closeScanModalFunc() {
                if (html5QrcodeScanner) {
                    html5QrcodeScanner.clear();
                }
                document.querySelector('#scan-modal .modal-content').classList.remove('active');
                setTimeout(() => {
                    scanModal.style.display = 'none';
                    scanResult.innerHTML = '';
                }, 300);
            }

            closeScanModal.addEventListener('click', closeScanModalFunc);

            // Запуск сканирования
            startScan.addEventListener('click', function() {
                startScanning();
            });

            // Остановка сканирования
            stopScan.addEventListener('click', function() {
                stopScanning();
            });

            function startScanning() {
                scanResult.innerHTML = '<div class="text-blue-600"><i class="fas fa-spinner fa-spin mr-2"></i>Запуск камеры...</div>';
                
                html5QrcodeScanner = new Html5Qrcode("qr-reader");
                
                const config = {
                    fps: 10,
                    qrbox: { width: 250, height: 250 }
                };
                
                html5QrcodeScanner.start(
                    { facingMode: "environment" },
                    config,
                    onScanSuccess,
                    onScanFailure
                ).then(() => {
                    scanResult.innerHTML = '<div class="text-green-600"><i class="fas fa-camera mr-2"></i>Камера запущена. Наведите на QR-код</div>';
                }).catch(err => {
                    scanResult.innerHTML = '<div class="text-red-600"><i class="fas fa-exclamation-triangle mr-2"></i>Ошибка запуска камеры: ' + err + '</div>';
                });
            }

            function stopScanning() {
                if (html5QrcodeScanner) {
                    html5QrcodeScanner.stop().then(() => {
                        scanResult.innerHTML = '<div class="text-gray-600">Сканирование остановлено</div>';
                    }).catch(err => {
                        console.error("Ошибка остановки сканера:", err);
                    });
                }
            }

            function onScanSuccess(decodedText, decodedResult) {
                // Останавливаем сканер после успешного сканирования
                stopScanning();
                
                // Показываем результат
                scanResult.innerHTML = '<div class="text-green-600"><i class="fas fa-check mr-2"></i>QR-код распознан!</div>';
                
                // Если билет не выбран, показываем ошибку
                if (!currentTicketId) {
                    scanResult.innerHTML += '<div class="text-red-600 mt-2">Сначала выберите билет для списания поездки</div>';
                    return;
                }
                
                // Отправляем запрос на списание поездки
                useRide(currentTicketId, decodedText);
            }

            function onScanFailure(error) {
                // Ошибки сканирования игнорируем (просто продолжаем сканировать)
            }

            function useRide(ticketId, vehicleQr) {
                scanResult.innerHTML = '<div class="text-blue-600"><i class="fas fa-spinner fa-spin mr-2"></i>Списываем поездку...</div>';
                
                const formData = new FormData();
                formData.append('action', 'use_ride');
                formData.append('ticket_id', ticketId);
                formData.append('vehicle_qr', vehicleQr);
                
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text())
                .then(data => {
                    if (data.includes('Поездка успешно списана')) {
                        scanResult.innerHTML = '<div class="text-green-600"><i class="fas fa-check-circle mr-2"></i>Поездка успешно списана!</div>';
                        setTimeout(() => {
                            closeScanModalFunc();
                            window.location.reload();
                        }, 2000);
                    } else {
                        scanResult.innerHTML = '<div class="text-red-600"><i class="fas fa-exclamation-triangle mr-2"></i>Ошибка: ' + (data.match(/Ошибка[^<]*/) || 'Неизвестная ошибка') + '</div>';
                    }
                })
                .catch(error => {
                    scanResult.innerHTML = '<div class="text-red-600"><i class="fas fa-exclamation-triangle mr-2"></i>Ошибка сети: ' + error + '</div>';
                });
            }

            // Кнопка "Наверх"
            const backToTop = document.getElementById('backToTop');
            
            window.addEventListener('scroll', function() {
                if (window.pageYOffset > 300) {
                    backToTop.classList.add('visible');
                } else {
                    backToTop.classList.remove('visible');
                }
            });
            
            backToTop.addEventListener('click', function() {
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            });

            // Активация вкладок навигации при прокрутке
            const sections = document.querySelectorAll('section[id]');
            const navLinks = document.querySelectorAll('.nav-tab');
            
            function activateCurrentSection() {
                let current = '';
                sections.forEach(section => {
                    const sectionTop = section.offsetTop;
                    const sectionHeight = section.clientHeight;
                    if (scrollY >= sectionTop - 100) {
                        current = section.getAttribute('id');
                    }
                });

                navLinks.forEach(link => {
                    link.classList.remove('active');
                    if (link.getAttribute('href') === `#${current}`) {
                        link.classList.add('active');
                    }
                });
            }

            window.addEventListener('scroll', activateCurrentSection);
            activateCurrentSection();
            
            // Функция для плавной прокрутки к секциям
            function scrollToSection(sectionId) {
                document.getElementById(sectionId).scrollIntoView({
                    behavior: 'smooth'
                });
            }

            // Обработка навигационных ссылок
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function (e) {
                    e.preventDefault();
                    const target = document.querySelector(this.getAttribute('href'));
                    if (target) {
                        target.scrollIntoView({
                            behavior: 'smooth'
                        });
                    }
                });
            });
        });
    </script>
</body>
</html>