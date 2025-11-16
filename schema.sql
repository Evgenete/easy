-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Хост: localhost
-- Время создания: Ноя 16 2025 г., 10:41
-- Версия сервера: 9.5.0
-- Версия PHP: 8.2.29

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- База данных: `easygo`
--
CREATE DATABASE IF NOT EXISTS `easygo` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
USE `easygo`;

-- --------------------------------------------------------

--
-- Структура таблицы `routes`
--

CREATE TABLE IF NOT EXISTS `routes` (
  `route_id` int NOT NULL AUTO_INCREMENT,
  `route_number` varchar(10) NOT NULL,
  `route_name` varchar(255) NOT NULL,
  `transport_type_id` int DEFAULT '1',
  `price` decimal(5,2) DEFAULT '35.00',
  `interval_minutes` int DEFAULT '15',
  PRIMARY KEY (`route_id`),
  UNIQUE KEY `unique_route_number` (`route_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Структура таблицы `route_stops`
--

CREATE TABLE IF NOT EXISTS `route_stops` (
  `route_stop_id` int NOT NULL AUTO_INCREMENT,
  `route_id` int NOT NULL,
  `stop_id` int NOT NULL,
  `stop_sequence` int NOT NULL,
  `travel_time_from_previous` int DEFAULT '0',
  PRIMARY KEY (`route_stop_id`),
  KEY `route_id` (`route_id`),
  KEY `stop_id` (`stop_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Структура таблицы `schedule`
--

CREATE TABLE IF NOT EXISTS `schedule` (
  `schedule_id` int NOT NULL AUTO_INCREMENT,
  `route_id` int NOT NULL,
  `stop_id` int NOT NULL,
  `arrival_time` time NOT NULL,
  `stop_order` int NOT NULL,
  `day_type` enum('weekday','weekend','both') DEFAULT 'both',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`schedule_id`),
  KEY `route_id` (`route_id`),
  KEY `stop_id` (`stop_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `search_history`
--

CREATE TABLE IF NOT EXISTS `search_history` (
  `history_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `search_query` text NOT NULL,
  `search_type` enum('route','stop') NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `stops`
--

CREATE TABLE IF NOT EXISTS `stops` (
  `stop_id` int NOT NULL AUTO_INCREMENT,
  `stop_name` varchar(255) NOT NULL,
  `stop_address` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`stop_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Структура таблицы `transport_types`
--

CREATE TABLE IF NOT EXISTS `transport_types` (
  `type_id` int NOT NULL AUTO_INCREMENT,
  `type_name` varchar(20) NOT NULL,
  PRIMARY KEY (`type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `users`
--

CREATE TABLE IF NOT EXISTS `users` (
  `user_id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `notifications_enabled` tinyint(1) DEFAULT '1',
  `theme` varchar(10) DEFAULT 'light',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `user_favorites`
--

CREATE TABLE IF NOT EXISTS `user_favorites` (
  `user_id` int NOT NULL,
  `route_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`,`route_id`),
  KEY `route_id` (`route_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `user_tickets`
--

CREATE TABLE IF NOT EXISTS `user_tickets` (
  `ticket_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `ticket_type` varchar(20) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `rides_remaining` int DEFAULT '0',
  `expires_at` datetime NOT NULL,
  `status` enum('active','used','expired') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ticket_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Ограничения внешнего ключа сохраненных таблиц
--

--
-- Ограничения внешнего ключа таблицы `route_stops`
--
ALTER TABLE `route_stops`
  ADD CONSTRAINT `route_stops_ibfk_1` FOREIGN KEY (`route_id`) REFERENCES `routes` (`route_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `route_stops_ibfk_2` FOREIGN KEY (`stop_id`) REFERENCES `stops` (`stop_id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `schedule`
--
ALTER TABLE `schedule`
  ADD CONSTRAINT `schedule_ibfk_1` FOREIGN KEY (`route_id`) REFERENCES `routes` (`route_id`),
  ADD CONSTRAINT `schedule_ibfk_2` FOREIGN KEY (`stop_id`) REFERENCES `stops` (`stop_id`);

--
-- Ограничения внешнего ключа таблицы `search_history`
--
ALTER TABLE `search_history`
  ADD CONSTRAINT `search_history_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `user_favorites`
--
ALTER TABLE `user_favorites`
  ADD CONSTRAINT `user_favorites_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `user_favorites_ibfk_2` FOREIGN KEY (`route_id`) REFERENCES `routes` (`route_id`);

--
-- Ограничения внешнего ключа таблицы `user_tickets`
--
ALTER TABLE `user_tickets`
  ADD CONSTRAINT `user_tickets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
