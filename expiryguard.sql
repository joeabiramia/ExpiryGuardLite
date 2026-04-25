-- MySQL dump 10.13  Distrib 8.0.19, for Win64 (x86_64)
--
-- Host: roundhouse.proxy.rlwy.net    Database: railway
-- ------------------------------------------------------
-- Server version	9.4.0

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `activity_logs`
--

DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `company_id` int NOT NULL,
  `branch_id` int DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `action_type` enum('login','logout','create_product','update_product','remove_product','restore_product','create_user','update_user','delete_user') COLLATE utf8mb4_general_ci NOT NULL,
  `target_table` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `target_id` int DEFAULT NULL,
  `description` text COLLATE utf8mb4_general_ci,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_logs_company` (`company_id`),
  KEY `idx_logs_branch` (`branch_id`),
  KEY `idx_logs_user` (`user_id`),
  CONSTRAINT `fk_logs_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_logs_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activity_logs`
--

LOCK TABLES `activity_logs` WRITE;
/*!40000 ALTER TABLE `activity_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `activity_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `branches`
--

DROP TABLE IF EXISTS `branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `branches` (
  `id` int NOT NULL AUTO_INCREMENT,
  `company_id` int NOT NULL,
  `branch_name` varchar(150) COLLATE utf8mb4_general_ci NOT NULL,
  `branch_code` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `address_line` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `city` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `country` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_branch_per_company` (`company_id`,`branch_code`),
  CONSTRAINT `fk_branches_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `branches`
--

LOCK TABLES `branches` WRITE;
/*!40000 ALTER TABLE `branches` DISABLE KEYS */;
INSERT INTO `branches` VALUES (1,1,'Jbeil','MAIN','Main Street','Jbeil','Lebanon',1,'2026-04-15 11:49:51');
/*!40000 ALTER TABLE `branches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `category_rules`
--

DROP TABLE IF EXISTS `category_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `category_rules` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `alert_days_before` int NOT NULL DEFAULT '4',
  `auto_remove_days_before` int NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `category_name` (`category_name`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `category_rules`
--

LOCK TABLES `category_rules` WRITE;
/*!40000 ALTER TABLE `category_rules` DISABLE KEYS */;
INSERT INTO `category_rules` VALUES (1,'Dairy',7,3,'2026-04-15 12:25:09'),(2,'ice cream',90,30,'2026-04-15 12:25:09'),(3,'candies',3,1,'2026-04-15 12:25:09'),(5,'Snacks',14,0,'2026-04-15 12:25:09'),(7,'cans',30,0,'2026-04-19 17:51:29'),(8,'Condiment',30,0,'2026-04-20 08:31:41');
/*!40000 ALTER TABLE `category_rules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `companies`
--

DROP TABLE IF EXISTS `companies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `companies` (
  `id` int NOT NULL AUTO_INCREMENT,
  `company_name` varchar(150) COLLATE utf8mb4_general_ci NOT NULL,
  `company_code` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `industry` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `contact_email` varchar(150) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `contact_phone` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_code` (`company_code`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `companies`
--

LOCK TABLES `companies` WRITE;
/*!40000 ALTER TABLE `companies` DISABLE KEYS */;
INSERT INTO `companies` VALUES (1,'Toters','Toters','Retail','admin@expiryguard.com','+96100000000',1,'2026-04-15 11:49:51');
/*!40000 ALTER TABLE `companies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notification_logs`
--

DROP TABLE IF EXISTS `notification_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `company_id` int NOT NULL,
  `branch_id` int NOT NULL,
  `product_id` int NOT NULL,
  `notification_type` enum('near_expiry','expired','daily_summary','weekly_summary') COLLATE utf8mb4_general_ci NOT NULL,
  `channel` enum('in_app','email','push') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'in_app',
  `recipient_user_id` int DEFAULT NULL,
  `sent_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `status` enum('queued','sent','failed','read') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'sent',
  `message` text COLLATE utf8mb4_general_ci,
  PRIMARY KEY (`id`),
  KEY `idx_notif_company` (`company_id`),
  KEY `idx_notif_branch` (`branch_id`),
  KEY `idx_notif_product` (`product_id`),
  KEY `idx_notif_recipient` (`recipient_user_id`),
  CONSTRAINT `fk_notif_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notif_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notif_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`recipient_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notification_logs`
--

LOCK TABLES `notification_logs` WRITE;
/*!40000 ALTER TABLE `notification_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `notification_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `permissions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `permission_key` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `permission_label` varchar(150) COLLATE utf8mb4_general_ci NOT NULL,
  `module_name` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permission_key` (`permission_key`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permissions`
--

LOCK TABLES `permissions` WRITE;
/*!40000 ALTER TABLE `permissions` DISABLE KEYS */;
INSERT INTO `permissions` VALUES (1,'view_dashboard','View Dashboard','dashboard','Can access dashboard overview',1,'2026-04-19 18:13:59'),(2,'manage_companies','Manage Companies','companies','Can create, edit, or delete companies',1,'2026-04-19 18:13:59'),(3,'view_companies','View Companies','companies','Can view companies',1,'2026-04-19 18:13:59'),(4,'manage_branches','Manage Branches','branches','Can create, edit, or delete branches',1,'2026-04-19 18:13:59'),(5,'view_branches','View Branches','branches','Can view branches',1,'2026-04-19 18:13:59'),(6,'manage_users','Manage Users','users','Can create, edit, or deactivate users',1,'2026-04-19 18:13:59'),(7,'view_users','View Users','users','Can view users list',1,'2026-04-19 18:13:59'),(8,'manage_categories','Manage Categories','categories','Can create, edit, or delete categories',1,'2026-04-19 18:13:59'),(9,'view_categories','View Categories','categories','Can view categories',1,'2026-04-19 18:13:59'),(10,'manage_products','Manage Products','products','Can create and edit products',1,'2026-04-19 18:13:59'),(11,'delete_products','Delete Products','products','Can delete products',1,'2026-04-19 18:13:59'),(12,'view_products','View Products','products','Can view products',1,'2026-04-19 18:13:59'),(13,'add_stock_entries','Add Stock Entries','stock','Can add new product entries',1,'2026-04-19 18:13:59'),(14,'edit_stock_entries','Edit Stock Entries','stock','Can edit stock entries',1,'2026-04-19 18:13:59'),(15,'delete_stock_entries','Delete Stock Entries','stock','Can delete stock entries',1,'2026-04-19 18:13:59'),(16,'view_stock_entries','View Stock Entries','stock','Can view stock entries',1,'2026-04-19 18:13:59'),(17,'scan_products','Scan Products','scanner','Can scan barcode and add items',1,'2026-04-19 18:13:59'),(18,'remove_expired_items','Remove Expired Items','expiry','Can mark expired items as removed',1,'2026-04-19 18:13:59'),(19,'view_expiry_alerts','View Expiry Alerts','expiry','Can view expiry alerts',1,'2026-04-19 18:13:59'),(20,'manage_settings','Manage Settings','settings','Can manage system settings',1,'2026-04-19 18:13:59'),(21,'view_reports','View Reports','reports','Can view reports',1,'2026-04-19 18:13:59'),(22,'export_reports','Export Reports','reports','Can export reports',1,'2026-04-19 18:13:59'),(23,'view_audit_logs','View Audit Logs','logs','Can view audit logs',1,'2026-04-19 18:13:59');
/*!40000 ALTER TABLE `permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_catalog`
--

DROP TABLE IF EXISTS `product_catalog`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_catalog` (
  `id` int NOT NULL AUTO_INCREMENT,
  `barcode` varchar(100) NOT NULL,
  `product_name` varchar(150) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `barcode` (`barcode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_catalog`
--

LOCK TABLES `product_catalog` WRITE;
/*!40000 ALTER TABLE `product_catalog` DISABLE KEYS */;
/*!40000 ALTER TABLE `product_catalog` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `products` (
  `id` int NOT NULL AUTO_INCREMENT,
  `company_id` int DEFAULT '1',
  `branch_id` int DEFAULT '1',
  `barcode` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `product_name` varchar(150) COLLATE utf8mb4_general_ci NOT NULL,
  `batch_code` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `category` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `quantity` int NOT NULL DEFAULT '1',
  `unit` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `expiry_date` date NOT NULL,
  `status` enum('active','near_expiry','expired','removed') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'active',
  `entered_by` int NOT NULL,
  `entered_on` datetime DEFAULT CURRENT_TIMESTAMP,
  `is_removed` tinyint(1) NOT NULL DEFAULT '0',
  `removed_by` int DEFAULT NULL,
  `removed_on` datetime DEFAULT NULL,
  `notes` text COLLATE utf8mb4_general_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_branch_barcode_expiry` (`branch_id`,`barcode`,`expiry_date`),
  KEY `idx_products_company` (`company_id`),
  KEY `idx_products_branch` (`branch_id`),
  KEY `idx_products_status` (`status`),
  KEY `idx_products_expiry` (`expiry_date`),
  KEY `fk_products_entered_by` (`entered_by`),
  KEY `fk_products_removed_by` (`removed_by`),
  CONSTRAINT `fk_products_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_products_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_products_entered_by` FOREIGN KEY (`entered_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_products_removed_by` FOREIGN KEY (`removed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=95 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products`
--

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
INSERT INTO `products` VALUES (14,1,1,'7622201500061','lu le petit citron',NULL,'Snacks',1,NULL,'2026-04-26','removed',1,'2026-04-18 04:24:41',1,1,'2026-04-18 09:03:44',NULL),(15,1,1,'5907471400139','dr gerard magic black',NULL,'Snacks',1,NULL,'2026-07-03','active',1,'2026-04-18 04:25:32',0,NULL,NULL,NULL),(16,1,1,'5907471416277','dr gerard rolls rolls',NULL,'Snacks',1,NULL,'2026-07-04','active',1,'2026-04-18 04:26:18',0,NULL,NULL,NULL),(17,1,1,'7622201499679','lu le petit beure',NULL,'Snacks',1,NULL,'2026-04-30','removed',1,'2026-04-18 04:26:49',1,1,'2026-04-18 09:03:48',NULL),(18,1,1,'5410041014600','lu cent',NULL,'Snacks',1,NULL,'2026-04-18','removed',1,'2026-04-18 04:27:32',1,1,'2026-04-18 05:25:01',NULL),(19,1,1,'5410041014600','lu cent',NULL,'Snacks',1,NULL,'2026-06-30','active',1,'2026-04-18 04:28:52',0,NULL,NULL,NULL),(20,1,1,'8888077101101','yan yan chocolat',NULL,'Snacks',1,NULL,'2026-07-09','active',1,'2026-04-18 04:35:13',0,NULL,NULL,NULL),(21,1,1,'8888077101125','yan yan strawberry',NULL,'Snacks',1,NULL,'2026-07-08','active',1,'2026-04-18 04:36:06',0,NULL,NULL,NULL),(22,1,1,'8888077102092','hello panda strawberry',NULL,'Snacks',1,NULL,'2026-05-14','active',1,'2026-04-18 04:36:54',0,NULL,NULL,NULL),(23,1,1,'8888077102023','hello panda chocolate',NULL,'Snacks',1,NULL,'2026-06-11','active',1,'2026-04-18 04:37:32',0,NULL,NULL,NULL),(24,1,1,'5283027843644','health up brownies',NULL,'Snacks',1,NULL,'2026-07-20','active',1,'2026-04-18 04:38:53',0,NULL,NULL,NULL),(25,1,1,'5283027843408','health up oat',NULL,'Snacks',1,NULL,'2026-07-20','active',1,'2026-04-18 04:45:08',0,NULL,NULL,NULL),(26,1,1,'5283027843668','health up brownies 80%',NULL,'Snacks',1,NULL,'2026-07-20','active',1,'2026-04-18 04:45:56',0,NULL,NULL,NULL),(27,1,1,'5283027843682','health up oat 66%',NULL,'Snacks',1,NULL,'2026-07-21','active',1,'2026-04-18 04:46:46',0,NULL,NULL,NULL),(28,1,1,'5283027843569','health up digestive',NULL,'Snacks',1,NULL,'2026-07-20','active',1,'2026-04-18 04:47:18',0,NULL,NULL,NULL),(29,1,1,'603369557422','kaak jelab',NULL,'Snacks',1,NULL,'2026-07-19','active',1,'2026-04-18 04:50:32',0,NULL,NULL,NULL),(30,1,1,'8691707096551','biscolata minis',NULL,'Snacks',1,NULL,'2026-07-24','active',1,'2026-04-18 04:51:20',0,NULL,NULL,NULL),(31,1,1,'5281134009885','Papadopoulos digestive offer milk chocolate',NULL,'Snacks',1,NULL,'2026-05-31','active',1,'2026-04-18 04:54:32',0,NULL,NULL,NULL),(32,1,1,'5283027843545','health up digestive',NULL,'Snacks',1,NULL,'2026-07-20','active',1,'2026-04-18 04:57:17',0,NULL,NULL,NULL),(33,1,1,'5283027843521','health up orange and dark chocolate',NULL,'Snacks',1,NULL,'2026-07-21','active',1,'2026-04-18 04:58:08',0,NULL,NULL,NULL),(34,1,1,'7622210120854','chocoprice',NULL,'Snacks',1,NULL,'2026-07-31','active',1,'2026-04-18 05:11:37',0,NULL,NULL,NULL),(35,1,1,'8410376061772','gullon oats and chips',NULL,'Snacks',1,NULL,'2026-06-12','active',1,'2026-04-18 05:17:19',0,NULL,NULL,NULL),(36,1,1,'8410376064445','gullon zero finans',NULL,'Snacks',1,NULL,'2026-06-03','active',1,'2026-04-18 05:19:31',0,NULL,NULL,NULL),(37,1,1,'8410376070323','gullon zero sin azucares',NULL,'Snacks',1,NULL,'2026-07-03','active',1,'2026-04-18 05:22:51',0,NULL,NULL,NULL),(38,1,1,'8410376064438','gullon zero finans choco',NULL,'Snacks',1,NULL,'2026-07-15','active',1,'2026-04-18 05:28:28',0,NULL,NULL,NULL),(39,1,1,'4017100283260','pick up x5 original',NULL,'Snacks',1,NULL,'2026-07-24','active',1,'2026-04-18 05:31:31',0,NULL,NULL,NULL),(40,1,1,'4017100284434','pickup 5x salted caramel',NULL,'Snacks',1,NULL,'2026-07-02','active',1,'2026-04-18 05:32:35',0,NULL,NULL,NULL),(41,1,1,'4017100407413','pickup minis',NULL,'Snacks',1,NULL,'2026-06-16','active',1,'2026-04-18 05:33:43',0,NULL,NULL,NULL),(42,1,1,'4017100263101','pickup original',NULL,'Snacks',1,NULL,'2026-05-20','active',1,'2026-04-18 05:34:42',0,NULL,NULL,NULL),(43,1,1,'7622202050763','Cadbury fingers mini',NULL,'Snacks',1,NULL,'2026-07-02','active',1,'2026-04-18 05:36:27',0,NULL,NULL,NULL),(44,1,1,'8000500310427','nutella biscuits kis kbir',NULL,'Snacks',1,NULL,'2026-06-18','active',1,'2026-04-18 05:37:23',0,NULL,NULL,NULL),(45,1,1,'8680050317949','humm organic caco cake bite',NULL,'Snacks',1,NULL,'2026-06-20','active',1,'2026-04-18 05:40:23',0,NULL,NULL,NULL),(46,1,1,'8680050304918','organic cookies humm',NULL,'Snacks',1,NULL,'2026-06-01','active',1,'2026-04-18 05:41:18',0,NULL,NULL,NULL),(47,1,1,'8680050304901','humm quinao honey',NULL,'Snacks',1,NULL,'2026-07-02','active',1,'2026-04-18 05:43:32',0,NULL,NULL,NULL),(48,1,1,'8680468817260','hum organic puff',NULL,'Snacks',1,NULL,'2026-05-18','active',1,'2026-04-18 05:44:08',0,NULL,NULL,NULL),(49,1,1,'8680050304925','humm ginger bread',NULL,'Snacks',1,NULL,'2026-06-03','active',1,'2026-04-18 05:44:37',0,NULL,NULL,NULL),(50,1,1,'8411414015108','florbu tortas integrales',NULL,'Snacks',1,NULL,'2026-07-31','active',1,'2026-04-18 05:46:14',0,NULL,NULL,NULL),(51,1,1,'8411414015412','florbu digestive cookies',NULL,'Snacks',1,NULL,'2026-07-31','active',1,'2026-04-18 05:46:51',0,NULL,NULL,NULL),(52,1,1,'4017100290008','bahlsen zar2a',NULL,'Snacks',1,NULL,'2026-07-04','active',1,'2026-04-18 05:53:44',0,NULL,NULL,NULL),(53,1,1,'3415587409226','hoagen-dazs cookies and cream',NULL,'Snacks',1,NULL,'2026-06-11','active',1,'2026-04-18 06:11:39',0,NULL,NULL,NULL),(54,1,1,'3415587103224','hoagen dazs vanilla caramel almond',NULL,'Snacks',1,NULL,'2026-07-03','active',1,'2026-04-18 06:15:52',0,NULL,NULL,NULL),(55,1,1,'3415587404221','hoagen dazs chocolate choc almond',NULL,'Snacks',1,NULL,'2026-09-16','active',1,'2026-04-18 06:17:02',0,NULL,NULL,NULL),(56,1,1,'3415587400223','hoagen dazs macadamia nut brittle',NULL,'Snacks',1,NULL,'2026-11-26','active',1,'2026-04-18 06:19:35',0,NULL,NULL,NULL),(57,1,1,'3415581311235','hoagen dazs vanilla 100ml',NULL,'Snacks',1,NULL,'2026-12-16','active',1,'2026-04-18 06:21:08',0,NULL,NULL,NULL),(58,1,1,'3415581117752','hoagen dazs caramel biscuit and cream 460ml',NULL,'Snacks',1,NULL,'2026-12-09','active',1,'2026-04-18 06:22:20',0,NULL,NULL,NULL),(59,1,1,'3415583003756','hoagen dazs pistachio and cream 460ml',NULL,'Snacks',1,NULL,'2026-11-12','active',1,'2026-04-18 06:23:11',0,NULL,NULL,NULL),(60,1,1,'3415581105759','hoagen dazs strowberry and cream 460ml',NULL,'Snacks',1,NULL,'2026-09-23','active',1,'2026-04-18 06:24:14',0,NULL,NULL,NULL),(61,1,1,'5000159347242','m&m glace vanille',NULL,'Snacks',1,NULL,'2026-11-24','active',1,'2026-04-18 06:48:39',0,NULL,NULL,NULL),(62,1,1,'5281021101111','Bonjus King dark and milk chocolate ice cream',NULL,'Snacks',1,NULL,'2026-08-07','active',1,'2026-04-18 06:49:57',0,NULL,NULL,NULL),(63,1,1,'6925425478699','trolli gummi world mix candy',NULL,'Snacks',1,NULL,'2026-11-25','active',1,'2026-04-18 08:23:46',0,NULL,NULL,NULL),(64,1,1,'5285000170051','wooden Bakery white toast',NULL,'Snacks',1,NULL,'2026-05-27','active',1,'2026-04-18 08:42:04',0,NULL,NULL,NULL),(65,1,1,'5285000171423','wooden bakery kaak finger short sesame',NULL,'Snacks',1,NULL,'2026-07-15','active',1,'2026-04-18 08:43:24',0,NULL,NULL,NULL),(66,1,1,'5285000170075','wooden bakery toast bran',NULL,'Snacks',1,NULL,'2026-05-27','active',1,'2026-04-18 08:44:16',0,NULL,NULL,NULL),(67,1,1,'5285000170686','wooden bakery kaak chapelure',NULL,'Snacks',1,NULL,'2026-07-10','active',1,'2026-04-18 08:44:51',0,NULL,NULL,NULL),(68,1,1,'5281034100514','danway iceberg les folies Ashta',NULL,'Snacks',1,NULL,'2026-04-18','removed',1,'2026-04-18 09:48:27',1,1,'2026-04-20 11:52:39',NULL),(69,1,1,'5281034100514','danway iceberg les folies Ashta',NULL,'Snacks',1,NULL,'2026-09-01','active',1,'2026-04-18 09:48:57',0,NULL,NULL,NULL),(70,1,1,'5289000064426','Pop city mango ice cream',NULL,'Snacks',1,NULL,'2026-04-27','near_expiry',1,'2026-04-18 09:54:51',0,NULL,NULL,NULL),(71,1,1,'5000159483025','Bounty ice cream',NULL,'Snacks',1,NULL,'2026-12-01','active',1,'2026-04-18 09:56:48',0,NULL,NULL,NULL),(72,1,1,'5000159545709','Galaxy vanilla Ice cream',NULL,'Snacks',1,NULL,'2026-11-19','active',1,'2026-04-18 09:58:15',0,NULL,NULL,NULL),(73,1,1,'5027324001235','Little moons summer raspberry',NULL,'Snacks',1,NULL,'2026-12-01','active',1,'2026-04-18 09:59:44',0,NULL,NULL,NULL),(74,1,1,'5027324001860','Little moons honey roasted pistachio',NULL,'Snacks',1,NULL,'2026-11-01','active',1,'2026-04-18 10:01:37',0,NULL,NULL,NULL),(75,1,1,'5027324001839','Little moons passionfruit and mango',NULL,'Snacks',1,NULL,'2026-11-01','active',1,'2026-04-18 10:02:53',0,NULL,NULL,NULL),(76,1,1,'5060502402806','Dough licious chocolate Truffle ice cream',NULL,'Snacks',1,NULL,'2026-07-11','removed',1,'2026-04-18 10:04:46',1,1,'2026-04-18 10:07:00',NULL),(77,1,1,'5060502402806','Dough licious chocolate Truffle ice cream',NULL,'Snacks',1,NULL,'2026-11-07','active',1,'2026-04-18 10:07:38',0,NULL,NULL,NULL),(78,1,1,'5060502403780','Dough licious chocolate taspberry ice cream',NULL,'Snacks',1,NULL,'2026-11-22','active',1,'2026-04-18 10:08:48',0,NULL,NULL,NULL),(79,1,1,'3187670917061','pasquier eclairs chocolate',NULL,'Snacks',1,NULL,'2026-06-05','active',1,'2026-04-18 10:10:34',0,NULL,NULL,NULL),(80,1,1,'3187670998091','pasquier petits fours',NULL,'Snacks',1,NULL,'2026-09-30','active',1,'2026-04-18 10:11:47',0,NULL,NULL,NULL),(81,1,1,'3187670614748','pasquier macarons',NULL,'Snacks',1,NULL,'2026-06-10','active',1,'2026-04-18 10:12:56',0,NULL,NULL,NULL),(82,1,1,'4971880164754','plain mayonnaise',NULL,'Condiment',1,NULL,'2026-09-26','active',1,'2026-04-20 08:33:07',0,NULL,NULL,NULL),(83,1,1,'6277000008396','pro puffs salt and vinegar',NULL,'Snacks',1,NULL,'2026-07-01','active',1,'2026-04-20 08:34:51',0,NULL,NULL,NULL),(84,1,1,'6277000081351','pro heroes cheese',NULL,'Snacks',1,NULL,'2026-08-01','active',1,'2026-04-20 08:36:11',0,NULL,NULL,NULL),(85,1,1,'5285000171416','kaak finger short',NULL,'Snacks',1,NULL,'2026-07-15','active',1,'2026-04-20 08:38:46',0,NULL,NULL,NULL),(86,1,1,'8000105001645','Asolo Dolce Savoiardi LadyFingers',NULL,'Snacks',1,NULL,'2026-12-17','active',1,'2026-04-20 08:54:01',0,NULL,NULL,NULL),(87,1,1,'6221033173217','heinz mustard',NULL,'Condiment',1,NULL,'2026-10-12','active',1,'2026-04-20 09:48:45',0,NULL,NULL,NULL),(88,1,1,'5287000928380','drayke roasted corn with thyme',NULL,'Snacks',1,NULL,'2026-07-15','active',1,'2026-04-20 10:36:57',0,NULL,NULL,NULL),(89,1,1,'5287000928366','drayke roasted corn with black seed',NULL,'Snacks',1,NULL,'2026-07-15','active',1,'2026-04-20 10:38:01',0,NULL,NULL,NULL),(90,1,1,'2297200928359','drayke roasted original corn',NULL,'Snacks',1,NULL,'2026-06-30','active',1,'2026-04-20 10:39:12',0,NULL,NULL,NULL),(91,1,1,'5287000928229','drayke roasted corn with peanut',NULL,'Snacks',1,NULL,'2026-10-02','active',1,'2026-04-20 10:40:06',0,NULL,NULL,NULL),(92,1,1,'5287000928014','drayke corn tortillas',NULL,'Snacks',1,NULL,'2026-05-10','active',1,'2026-04-20 10:40:40',0,NULL,NULL,NULL),(93,1,1,'6291003097829','london dairy ice cream naturel strawberry',NULL,'ice cream',1,NULL,'2026-09-01','active',1,'2026-04-20 12:18:51',0,NULL,NULL,NULL),(94,1,1,'6291003097812','London dairy pralinesand and cream',NULL,'ice cream',1,NULL,'2026-09-21','active',1,'2026-04-20 12:21:21',0,NULL,NULL,NULL);
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_permissions`
--

DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_permissions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `role` enum('super_admin','company_admin','branch_manager','employee','viewer') COLLATE utf8mb4_general_ci NOT NULL,
  `permission_id` int NOT NULL,
  `is_allowed` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_permission` (`role`,`permission_id`),
  KEY `fk_role_permissions_permission` (`permission_id`),
  CONSTRAINT `fk_role_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=93 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_permissions`
--

LOCK TABLES `role_permissions` WRITE;
/*!40000 ALTER TABLE `role_permissions` DISABLE KEYS */;
INSERT INTO `role_permissions` VALUES (1,'super_admin',13,1,'2026-04-19 18:14:31'),(2,'super_admin',11,1,'2026-04-19 18:14:31'),(3,'super_admin',15,1,'2026-04-19 18:14:31'),(4,'super_admin',14,1,'2026-04-19 18:14:31'),(5,'super_admin',22,1,'2026-04-19 18:14:31'),(6,'super_admin',4,1,'2026-04-19 18:14:31'),(7,'super_admin',8,1,'2026-04-19 18:14:31'),(8,'super_admin',2,1,'2026-04-19 18:14:31'),(9,'super_admin',10,1,'2026-04-19 18:14:31'),(10,'super_admin',20,1,'2026-04-19 18:14:31'),(11,'super_admin',6,1,'2026-04-19 18:14:31'),(12,'super_admin',18,1,'2026-04-19 18:14:31'),(13,'super_admin',17,1,'2026-04-19 18:14:31'),(14,'super_admin',23,1,'2026-04-19 18:14:31'),(15,'super_admin',5,1,'2026-04-19 18:14:31'),(16,'super_admin',9,1,'2026-04-19 18:14:31'),(17,'super_admin',3,1,'2026-04-19 18:14:31'),(18,'super_admin',1,1,'2026-04-19 18:14:31'),(19,'super_admin',19,1,'2026-04-19 18:14:31'),(20,'super_admin',12,1,'2026-04-19 18:14:31'),(21,'super_admin',21,1,'2026-04-19 18:14:31'),(22,'super_admin',16,1,'2026-04-19 18:14:31'),(23,'super_admin',7,1,'2026-04-19 18:14:31'),(32,'company_admin',13,1,'2026-04-19 18:16:07'),(33,'company_admin',14,1,'2026-04-19 18:16:07'),(34,'company_admin',22,1,'2026-04-19 18:16:07'),(35,'company_admin',4,1,'2026-04-19 18:16:07'),(36,'company_admin',8,1,'2026-04-19 18:16:07'),(37,'company_admin',10,1,'2026-04-19 18:16:07'),(38,'company_admin',6,1,'2026-04-19 18:16:07'),(39,'company_admin',18,1,'2026-04-19 18:16:07'),(40,'company_admin',17,1,'2026-04-19 18:16:07'),(41,'company_admin',5,1,'2026-04-19 18:16:07'),(42,'company_admin',9,1,'2026-04-19 18:16:07'),(43,'company_admin',3,1,'2026-04-19 18:16:07'),(44,'company_admin',1,1,'2026-04-19 18:16:07'),(45,'company_admin',19,1,'2026-04-19 18:16:07'),(46,'company_admin',12,1,'2026-04-19 18:16:07'),(47,'company_admin',21,1,'2026-04-19 18:16:07'),(48,'company_admin',16,1,'2026-04-19 18:16:07'),(49,'company_admin',7,1,'2026-04-19 18:16:07'),(63,'branch_manager',13,1,'2026-04-19 18:16:15'),(64,'branch_manager',14,1,'2026-04-19 18:16:15'),(65,'branch_manager',10,1,'2026-04-19 18:16:15'),(66,'branch_manager',18,1,'2026-04-19 18:16:15'),(67,'branch_manager',17,1,'2026-04-19 18:16:15'),(68,'branch_manager',5,1,'2026-04-19 18:16:15'),(69,'branch_manager',9,1,'2026-04-19 18:16:15'),(70,'branch_manager',1,1,'2026-04-19 18:16:15'),(71,'branch_manager',19,1,'2026-04-19 18:16:15'),(72,'branch_manager',12,1,'2026-04-19 18:16:15'),(73,'branch_manager',21,1,'2026-04-19 18:16:15'),(74,'branch_manager',16,1,'2026-04-19 18:16:15'),(75,'branch_manager',7,1,'2026-04-19 18:16:15'),(78,'employee',13,1,'2026-04-19 18:16:21'),(79,'employee',18,1,'2026-04-19 18:16:21'),(80,'employee',17,1,'2026-04-19 18:16:21'),(81,'employee',9,1,'2026-04-19 18:16:21'),(82,'employee',1,1,'2026-04-19 18:16:21'),(83,'employee',19,1,'2026-04-19 18:16:21'),(84,'employee',12,1,'2026-04-19 18:16:21'),(85,'employee',16,1,'2026-04-19 18:16:21');
/*!40000 ALTER TABLE `role_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_permissions`
--

DROP TABLE IF EXISTS `user_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_permissions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `permission_id` int NOT NULL,
  `is_allowed` tinyint(1) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_permission` (`user_id`,`permission_id`),
  KEY `fk_user_permissions_permission` (`permission_id`),
  CONSTRAINT `fk_user_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_permissions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_permissions`
--

LOCK TABLES `user_permissions` WRITE;
/*!40000 ALTER TABLE `user_permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `company_id` int NOT NULL,
  `branch_id` int DEFAULT NULL,
  `full_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `username` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `role` enum('super_admin','company_admin','branch_manager','employee','viewer') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'employee',
  `email` varchar(150) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `phone` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_username` (`company_id`,`username`),
  KEY `fk_users_branch` (`branch_id`),
  CONSTRAINT `fk_users_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_users_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,1,NULL,'System Owner','Joe','$2y$10$kOqGVy.W8SF1AdZrK.r.R.V6UGZhyfpyzU9mXOc7bbSxaleBM16HG','super_admin','owner@expiryguard.com',NULL,1,NULL,'2026-04-15 11:49:51'),(4,1,1,'chrisshajje','chrisshajje','$2y$10$neFU7.hhKZkBs1Wn5EHJB.pBOWaP9dPIQ5uQze1Piw2foCcktruVq','employee',NULL,NULL,1,NULL,'2026-04-19 19:16:55'),(5,1,1,'Magalieghosh','Magalieghosh','$2y$10$ChVVca82I8HQMm/VsuIUbOJV3KfKLClOuLdIPKJCmt.N8W1q.q1Ey','employee',NULL,NULL,1,NULL,'2026-04-20 08:17:35');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'railway'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-04-21  9:07:36
