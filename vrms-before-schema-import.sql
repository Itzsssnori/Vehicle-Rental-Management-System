-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Linux (x86_64)
--
-- Host: 127.0.0.1    Database: vrms
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `maintenance`
--

DROP TABLE IF EXISTS `maintenance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `maintenance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vehicle_id` int(11) NOT NULL,
  `description` varchar(255) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `vehicle_id` (`vehicle_id`),
  CONSTRAINT `maintenance_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `maintenance`
--

LOCK TABLES `maintenance` WRITE;
/*!40000 ALTER TABLE `maintenance` DISABLE KEYS */;
/*!40000 ALTER TABLE `maintenance` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `rental_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `method` enum('cash','gcash','card') NOT NULL,
  `recorded_by` int(11) NOT NULL,
  `paid_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `rental_id` (`rental_id`),
  KEY `recorded_by` (`recorded_by`),
  CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`rental_id`) REFERENCES `rentals` (`id`),
  CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payments`
--

LOCK TABLES `payments` WRITE;
/*!40000 ALTER TABLE `payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `rentals`
--

DROP TABLE IF EXISTS `rentals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rentals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `actual_return` date DEFAULT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `payment_method` enum('cash','gcash','card') DEFAULT NULL,
  `purpose` varchar(255) DEFAULT NULL,
  `rejection_reason` varchar(255) DEFAULT NULL,
  `cancellation_reason` varchar(255) DEFAULT NULL,
  `cancellation_requested` tinyint(1) NOT NULL DEFAULT 0,
  `cancellation_requested_at` timestamp NULL DEFAULT NULL,
  `status` enum('pending','confirmed','ongoing','completed','cancelled','rejected') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `vehicle_id` (`vehicle_id`),
  CONSTRAINT `rentals_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `rentals_ibfk_2` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`),
  CONSTRAINT `CONSTRAINT_1` CHECK (`end_date` >= `start_date`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `rentals`
--

LOCK TABLES `rentals` WRITE;
/*!40000 ALTER TABLE `rentals` DISABLE KEYS */;
/*!40000 ALTER TABLE `rentals` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('customer','staff','admin') NOT NULL DEFAULT 'customer',
  `account_status` enum('approved','pending','rejected') NOT NULL DEFAULT 'approved',
  `phone` varchar(30) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `profile_image` text DEFAULT NULL,
  `preferred_payment_method` enum('cash','gcash','card') DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (2,'System Administrator','ty202500280@wmsu.edu.ph','$2y$10$ZwRyr.Ln1KRmIhlpgy8owOPp0TmxkWZbrGV71oO/IzsN4inuW9m0a','admin','approved',NULL,NULL,NULL,NULL,'2026-10-29 16:29:21'),(4,'Norielle John Buhawe','buhawenoriellejohnd@gmail.com','$2y$10$/7I7oUqNi1g456VnoUmB7u0xlPQvsbxcQCGXyt5FFd9r1rmkV5Ke2','staff','pending',NULL,NULL,NULL,NULL,'2026-11-03 14:06:05'),(5,'Haruta Arkish','harutaarkish@gmail.com','$2y$10$9EjxzmhRUwrLGwLRQrmJo.EmEY6Jw6B1fmgEbvDuyLYA2Z4WgfxQC','customer','approved',NULL,NULL,NULL,NULL,'2026-11-03 14:06:38'),(6,'System Administrator','admin@test.com','$2y$10$5bMhDN80bi75tWvYUJzek.2T1FR6aHDrFPGK07CXJWAPBNj4GbLJi','admin','approved',NULL,NULL,NULL,NULL,'2026-11-04 08:54:16');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `vehicles`
--

DROP TABLE IF EXISTS `vehicles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vehicles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `brand` varchar(50) NOT NULL,
  `model` varchar(50) NOT NULL,
  `category` enum('Sedan','SUV','Premium') NOT NULL,
  `transmission` enum('Automatic','Manual') NOT NULL,
  `seats` tinyint(4) NOT NULL,
  `daily_rate` decimal(10,2) NOT NULL,
  `status` enum('operational','maintenance','retired') NOT NULL DEFAULT 'operational',
  `image` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `vehicles`
--

LOCK TABLES `vehicles` WRITE;
/*!40000 ALTER TABLE `vehicles` DISABLE KEYS */;
INSERT INTO `vehicles` VALUES (1,'Toyota','Vios','Sedan','Automatic',5,1800.00,'operational','vehicle-placeholder.svg'),(2,'Honda','City','Sedan','Automatic',5,2000.00,'operational','https://thumb.wikimedia.org/wikipedia/commons/thumb/2/2e/2020_Honda_Brio_Satya_S_1.2_DD1_%2820211001%29_01.jpg/960px-2020_Honda_Brio_Satya_S_1.2_DD1_%2820211001%29_01.jpg?utm_source=commons.wikimedia.org&utm_campaign=imageinfo&utm_content=thumbnail'),(3,'Mitsubishi','Montero Sport','SUV','Automatic',7,3500.00,'operational','vehicle-placeholder.svg'),(4,'Toyota','Fortuner','SUV','Automatic',7,3500.00,'operational','vehicle-placeholder.svg'),(5,'Toyota','Innova','SUV','Automatic',7,2800.00,'operational','https://thumb.wikimedia.org/wikipedia/commons/thumb/0/0a/Innova_Zenix_Hybrid_Car.jpg/960px-Innova_Zenix_Hybrid_Car.jpg?utm_source=commons.wikimedia.org&utm_campaign=imageinfo&utm_content=thumbnail'),(6,'Toyota','RAV4','SUV','Automatic',5,3200.00,'operational','vehicle-placeholder.svg'),(7,'Toyota','Camry','Premium','Automatic',5,4200.00,'operational','vehicle-placeholder.svg'),(8,'Honda','Civic','Sedan','Automatic',5,2500.00,'operational','https://thumb.wikimedia.org/wikipedia/commons/thumb/7/70/1978_Honda_civic_based_Spex_Elf_kit_car_front.jpg/960px-1978_Honda_civic_based_Spex_Elf_kit_car_front.jpg?utm_source=commons.wikimedia.org&utm_campaign=imageinfo&utm_content=thumbnail'),(9,'Honda','BR-V','SUV','Automatic',7,2800.00,'operational','https://thumb.wikimedia.org/wikipedia/commons/thumb/f/f9/Honda_BR-V_DG3_1.5_VX_Meteoroid_Gray_Metallic.jpg/960px-Honda_BR-V_DG3_1.5_VX_Meteoroid_Gray_Metallic.jpg?utm_source=commons.wikimedia.org&utm_campaign=imageinfo&utm_content=thumbnail'),(10,'Honda','CR-V','SUV','Automatic',5,3500.00,'operational','https://thumb.wikimedia.org/wikipedia/commons/thumb/7/7a/2002_Honda_CR-V_2.0_%28Bronze_colour%29_in_Indonesia_01.jpg/960px-2002_Honda_CR-V_2.0_%28Bronze_colour%29_in_Indonesia_01.jpg?utm_source=commons.wikimedia.org&utm_campaign=imageinfo&utm_content=thumbnail'),(11,'Honda','Accord','Premium','Automatic',5,4500.00,'operational','https://thumb.wikimedia.org/wikipedia/commons/thumb/3/3d/1970s_Honda_Accord_EX_%2811295856565%29.jpg/960px-1970s_Honda_Accord_EX_%2811295856565%29.jpg?utm_source=commons.wikimedia.org&utm_campaign=imageinfo&utm_content=thumbnail'),(12,'Mitsubishi','Xpander','SUV','Automatic',7,2600.00,'operational','vehicle-placeholder.svg'),(13,'Mitsubishi','Mirage','Sedan','Automatic',5,1700.00,'operational','vehicle-placeholder.svg'),(14,'Nissan','Almera','Sedan','Automatic',5,1900.00,'operational','vehicle-placeholder.svg'),(15,'Nissan','Terra','SUV','Automatic',7,3600.00,'operational','vehicle-placeholder.svg'),(16,'Nissan','Navara','SUV','Automatic',5,3300.00,'operational','vehicle-placeholder.svg'),(17,'Ford','Everest','SUV','Automatic',7,4000.00,'operational','vehicle-placeholder.svg'),(18,'Ford','Ranger','SUV','Automatic',5,3500.00,'operational','vehicle-placeholder.svg'),(19,'Mazda','CX-5','Premium','Automatic',5,3800.00,'operational','vehicle-placeholder.svg'),(20,'Hyundai','Tucson','SUV','Automatic',5,3200.00,'operational','vehicle-placeholder.svg');
/*!40000 ALTER TABLE `vehicles` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-04 16:54:49
