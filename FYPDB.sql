-- MySQL dump 10.13  Distrib 8.0.40, for Win64 (x86_64)
-- Host: localhost    Database: fypdb
-- Server version 8.4.3

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

-- Disable Foreign Key Checks to avoid errors during drop/create
SET FOREIGN_KEY_CHECKS = 0;

-- Drop existing tables to ensure clean slate
DROP TABLE IF EXISTS `Product`;
DROP TABLE IF EXISTS `Client`;
DROP TABLE IF EXISTS `Manager`;
DROP TABLE IF EXISTS `Contractors`;
DROP TABLE IF EXISTS `Designer`;
DROP TABLE IF EXISTS `Supplier`;
DROP TABLE IF EXISTS `Material`;
DROP TABLE IF EXISTS `Order`;
DROP TABLE IF EXISTS `OrderProduct`;
DROP TABLE IF EXISTS `Order_Contractors`;
DROP TABLE IF EXISTS `Design`;
DROP TABLE IF EXISTS `Comment_design`;
DROP TABLE IF EXISTS `Schedule`;
DROP TABLE IF EXISTS `ChatRoom`;
DROP TABLE IF EXISTS `ChatRoomMember`;
DROP TABLE IF EXISTS `Message`;
DROP TABLE IF EXISTS `ProductLike`;
DROP TABLE IF EXISTS `DesignLike`;
DROP TABLE IF EXISTS `MessageRead`;
Drop TABLE IF EXISTS `OrderProductStatus`;

-- Client table
CREATE TABLE `Client` (
  `clientid` int NOT NULL AUTO_INCREMENT,
  `cname` varchar(255) NOT NULL,
  `ctel` int DEFAULT NULL,
  `cemail` varchar(255) DEFAULT NULL,
  `cpassword` varchar(255) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `remember_token` VARCHAR(64) DEFAULT NULL,
  PRIMARY KEY (`clientid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `Client` (`clientid`,`cname`,`ctel`,`cemail`,`cpassword`,`address`,`remember_token`) VALUES
(1, 'Alex Wong', 21232123, 'u3952310@gmail.com', 'User12345', 'ABC Building', NULL),
(2, 'Tina Chan', 12345678, 'abc321@gmail.com', '123456', '123 Building', NULL);

-- Manager table
CREATE TABLE `Manager` (
  `managerid` int NOT NULL AUTO_INCREMENT,
  `mname` varchar(255) NOT NULL,
  `mtel` int DEFAULT NULL,
  `memail` varchar(255) DEFAULT NULL,
  `mpassword` varchar(255) NOT NULL,
  `remember_token` VARCHAR(64) DEFAULT NULL,
  PRIMARY KEY (`managerid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `Manager` (`managerid`,`mname`,`mtel`,`memail`,`mpassword`,`remember_token`) VALUES
(1, 'Jeff Wong', 12312312, 'abcdef123@gmail.com', 'manager12345',NULL),
(2, 'Apple Chan', 12301230, '1234567@gmail.com', '12345678',NULL);

-- Contractors table
CREATE TABLE `Contractors` (
  `contractorid` int NOT NULL AUTO_INCREMENT,
  `cname` varchar(255) NOT NULL,
  `ctel` int DEFAULT NULL,
  `cemail` varchar(255) DEFAULT NULL,
  `cpassword` varchar(255) NOT NULL,
  `price` int DEFAULT NULL,
  `introduction` text DEFAULT NULL,
  `certification` VARCHAR(500) DEFAULT NULL,
  `managerid` int DEFAULT NULL,
  `remember_token` VARCHAR(64) DEFAULT NULL,
  PRIMARY KEY (`contractorid`),
  KEY `managerid_Contractors_idx` (`managerid`),
  CONSTRAINT `managerid_Contractors_fk` FOREIGN KEY (`managerid`) REFERENCES `Manager` (`managerid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `Contractors` (`contractorid`,`cname`,`ctel`,`cemail`,`cpassword`,`price`,`introduction`,`certification`,`managerid`,`remember_token`) VALUES
(1, 'abc Contractors company', 12312312, 'abc123@gmail.com', 'Contractors12345',600,null,null,1, NULL),
(2, '123 Contractors company', 12301230, 'abc@gmail.com', '12345678',700,null,null,2, NULL);

-- Designer table
CREATE TABLE `Designer` (
  `designerid` int NOT NULL AUTO_INCREMENT,
  `dname` varchar(255) NOT NULL,
  `dtel` int DEFAULT NULL,
  `demail` varchar(255) DEFAULT NULL,
  `dpassword` varchar(255) NOT NULL,
  `managerid` int DEFAULT NULL,
  `remember_token` VARCHAR(64) DEFAULT NULL,
  PRIMARY KEY (`designerid`),
  KEY `managerid_Designer_idx` (`managerid`),
  CONSTRAINT `managerid_Designer_fk` FOREIGN KEY (`managerid`) REFERENCES `Manager` (`managerid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `Designer` (`designerid`,`dname`,`dtel`,`demail`,`dpassword`,`managerid`,`remember_token`) VALUES
(1, 'John Wong', 12345678, 'abc123@gmail.com', 'designer12345',1, NULL),
(2, 'Billy Chan', 11002234, 'abcdd@gmail.com', '123456',2, NULL);

-- Supplier table
CREATE TABLE `Supplier` (
  `supplierid` int NOT NULL AUTO_INCREMENT,
  `sname` varchar(255) NOT NULL,
  `stel` int DEFAULT NULL,
  `semail` varchar(255) DEFAULT NULL,
  `spassword` varchar(255) NOT NULL,
  `remember_token` VARCHAR(64) DEFAULT NULL,
  PRIMARY KEY (`supplierid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- IMPORTANT: Updated password for ID 1 to '123456' to match your testing
INSERT INTO `Supplier` (`supplierid`,`sname`,`stel`,`semail`,`spassword`,`remember_token`) VALUES
(1, 'ABC', 12312312, 'abc1234@gmail.com', '123456', NULL),
(2, '123', 12301230, '12345123@gmail.com', '12345678', NULL);

-- Design table
CREATE TABLE `Design` (
  `designid` int NOT NULL AUTO_INCREMENT,
  `design` VARCHAR(500) DEFAULT NULL,
  `price` int NOT NULL,
  `tag` TEXT NOT NULL,
  `likes` int NOT NULL,
  `designerid` int NOT NULL,
  PRIMARY KEY (`designid`),
  KEY `designerid_pk_idx` (`designerid`),
  CONSTRAINT `designerid_pk` FOREIGN KEY (`designerid`) REFERENCES `Designer` (`designerid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `Design` (`designid`,`design`,`price`,`tag`,`likes`,`designerid`) VALUES
(1, 'design.jpg',500 , 'full house,modern','200',1),
(2, 'design2.jpg',1000, 'kitchen remodel,minimalist','20',1);

-- Comment_design table
CREATE TABLE `Comment_design` (
  `comment_designid` int NOT NULL AUTO_INCREMENT,
  `clientid` int NOT NULL,
  `content` varchar(255) DEFAULT NULL,
  `timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `designid` int NOT NULL,
  PRIMARY KEY (`comment_designid`),
  KEY `designid_pk2` (`designid`),
  KEY `comment_design_clientid_pk2` (`clientid`),
  CONSTRAINT `fk_comment_design_designid` FOREIGN KEY (`designid`) REFERENCES `Design` (`designid`),
  CONSTRAINT `fk_comment_design_clientid` FOREIGN KEY (`clientid`) REFERENCES `Client` (`clientid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `Comment_design` (`comment_designid`,`clientid`,`content`,`designid`) VALUES
(1, 2,'abc',1),
(2, 1,'123',1);

-- Product table
CREATE TABLE `Product` (
  `productid` int NOT NULL AUTO_INCREMENT,
  `pname` varchar(255) NOT NULL,
  `image` varchar(500) DEFAULT NULL,
  `price` int NOT NULL,
  `likes` int NOT NULL,
  `category` varchar(100) NOT NULL,
  `description` text,
  `size` varchar(100) DEFAULT NULL,
  `color` varchar(255) DEFAULT NULL,
  `material` varchar(255) DEFAULT NULL,
  `supplierid` int NOT NULL,
  PRIMARY KEY (`productid`),
  KEY `supplierid_product_idx` (`supplierid`),
  CONSTRAINT `chk_category` CHECK (`category` IN ('Furniture','Material')),
  CONSTRAINT `fk_product_supplier` FOREIGN KEY (`supplierid`) REFERENCES `Supplier` (`supplierid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `Product` (`productid`,`pname`, `image`, `price`, `likes`, `category`, `description`, `size`, `color`, `material`, `supplierid`) VALUES
(1, 'Modern Sofa', 'sofa.jpg', 2000, 100, 'Furniture', 'A comfortable modern sofa.', '200cm*80cm', 'Grey, Blue', 'Fabric, Wood', 1),
(2, 'Oak Chair', 'chair.jpg', 800, 50, 'Furniture', 'Solid wood chair.', '50cm*50cm', 'Brown,white', 'Oak', 1),
(3, 'Brick', 'brick.jpg', 200, 25, 'Material', 'A brick.', null, null, null, 1),
(4, 'Wood', 'wood.jpg', 800, 75, 'Material', 'A wood.', null, null, null, 2);


-- Order table
CREATE TABLE `Order` (
  `orderid` int NOT NULL AUTO_INCREMENT,
  `odate` datetime NOT NULL,
  `clientid` int NOT NULL,
  `budget` int NOT NULL,
  `Floor_Plan` VARCHAR(500) DEFAULT NULL,
  `Requirements` varchar(255) DEFAULT NULL,
  `designid` int NOT NULL,
  `ostatus` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`orderid`),
  KEY `clientid_pk_idx` (`clientid`),
  KEY `designid_pk_idx` (`designid`),
  CONSTRAINT `clientid_pk` FOREIGN KEY (`clientid`) REFERENCES `Client` (`clientid`),
  CONSTRAINT `designid_pk` FOREIGN KEY (`designid`) REFERENCES `Design` (`designid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `Order`
(`orderid`, `odate`, `clientid`, `budget`, `Floor_Plan`, `Requirements`,`designid`,`ostatus`) VALUES
(1, '2025-04-12 17:50:00', 1, 1000, NULL, 'abc',2,'Designing'),
(2, '2025-05-10 12:00:00', 2, 2000, NULL, 'abc',1,'Completed');

-- OrderMaterial table
CREATE TABLE `OrderProduct` (
  `orderproductid` int NOT NULL AUTO_INCREMENT,
  `productid` int NOT NULL,
  `quantity` int NOT NULL,
  `orderid` int NOT NULL,
  `deliverydate` date DEFAULT NULL,
  `managerid` int NOT NULL,
  PRIMARY KEY (`orderproductid`),
  KEY `productid_OrderProduct_idx` (`productid`),
  KEY `orderid_OrderProduct_idx` (`orderid`),
  KEY `managerid_OrderProduct_idx` (`managerid`),
  CONSTRAINT `fk_OrderProduct_materialid` FOREIGN KEY (`productid`) REFERENCES `Product` (`productid`),
  CONSTRAINT `fk_OrderProduct_orderid` FOREIGN KEY (`orderid`) REFERENCES `Order` (`orderid`),
  CONSTRAINT `fk_OrderProduct_managerid` FOREIGN KEY (`managerid`) REFERENCES `Manager` (`managerid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `OrderProduct`
(`orderproductid`, `productid`, `quantity`, `orderid`, `deliverydate`, `managerid`) VALUES
(1, 1, 10, 1, '2026-01-13', 1),
(2, 2, 20, 1, '2026-01-23', 1);

CREATE TABLE `OrderProductStatus` (
  `orderproductstatusid` int NOT NULL AUTO_INCREMENT,
  `orderproductid` int NOT NULL,
  `status` varchar(255) NOT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`orderproductstatusid`),
  KEY `orderproductid_idx` (`orderproductid`),
  CONSTRAINT `fk_orderproductstatus_orderproductid` FOREIGN KEY (`orderproductid`) REFERENCES `OrderProduct` (`orderproductid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `OrderProductStatus` (`orderproductstatusid`, `orderproductid`, `status`) VALUES
(1, 1, 'Pending'),
(2, 2, 'Shipped');

-- Order_Contractors table
CREATE TABLE `Order_Contractors` (
  `order_Contractorid` int NOT NULL AUTO_INCREMENT,
  `contractorid` int NOT NULL,
  `orderid` int NOT NULL,
  `managerid` int NOT NULL,
  PRIMARY KEY (`order_Contractorid`),
  KEY `contractorid_OC_idx1` (`contractorid`),
  KEY `orderid_OC_idx1` (`orderid`),
  KEY `managerid_OC_idx1` (`managerid`),
  CONSTRAINT `fk_OC_contractorid` FOREIGN KEY (`contractorid`) REFERENCES `Contractors` (`contractorid`),
  CONSTRAINT `fk_OC_orderid` FOREIGN KEY (`orderid`) REFERENCES `Order` (`orderid`),
  CONSTRAINT `fk_OC_managerid` FOREIGN KEY (`managerid`) REFERENCES `Manager` (`managerid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `Order_Contractors`
(`order_Contractorid`, `contractorid`, `orderid`, `managerid`) VALUES
(1, 1,1,1),
(2, 2,2,1);

-- Schedule table
CREATE TABLE `Schedule` (
  `scheduleid` int NOT NULL AUTO_INCREMENT,
  `managerid` int NOT NULL,
  `FinishDate` datetime DEFAULT NULL,
  `orderid` int NOT NULL,
  PRIMARY KEY (`scheduleid`),
  KEY `orderid_pk_idx` (`orderid`),
  KEY `managerid_pk_idx` (`managerid`),
  CONSTRAINT `managerid_fk` FOREIGN KEY (`managerid`) REFERENCES `Manager` (`managerid`),
  CONSTRAINT `orderid_fk` FOREIGN KEY (`orderid`) REFERENCES `Order` (`orderid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `Schedule` (`scheduleid`,`managerid`,`FinishDate`,`orderid`) VALUES
(1,1,'2025-05-13 12:01:00',1),
(2,2,'2025-06-13 13:31:00',2);

-- ChatRoom Tables
CREATE TABLE `ChatRoom` (
  `ChatRoomid` INT NOT NULL AUTO_INCREMENT,
  `roomname` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `room_type` ENUM('private', 'group') NOT NULL DEFAULT 'group',
  `created_by_type` ENUM('client', 'designer', 'manager', 'Contractors', 'supplier') NOT NULL,
  `created_by_id` INT NOT NULL,
  PRIMARY KEY (`ChatRoomid`),
  KEY `idx_creator` (`created_by_type`, `created_by_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `ChatRoom` (`ChatRoomid`, `roomname`, `description`, `room_type`, `created_by_type`, `created_by_id`) VALUES
(1, '123', 'the design process', 'group', 'manager',1),
(2, 'abc', 'the delivery process', 'private', 'client',1);

CREATE TABLE `ChatRoomMember` (
  `ChatRoomMemberid` INT NOT NULL AUTO_INCREMENT,
  `ChatRoomid` INT NOT NULL,
  `member_type` ENUM('client', 'designer', 'manager', 'Contractors', 'supplier') NOT NULL,
  `memberid` INT NOT NULL,
  PRIMARY KEY (`ChatRoomMemberid`),
  UNIQUE KEY `unique_member` (`ChatRoomid`, `member_type`, `memberid`),
  KEY `idx_room` (`ChatRoomid`),
  KEY `idx_member` (`member_type`, `memberid`),
  CONSTRAINT `fk_chatroom` FOREIGN KEY (`ChatRoomid`) REFERENCES `ChatRoom` (`ChatRoomid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `ChatRoomMember` (`ChatRoomMemberid`, `ChatRoomid`, `member_type`, `memberid`) VALUES
(1, 2, 'manager', 1),
(2, 2, 'designer', 1),
-- map test membership to existing designer id (use designerid=2)
(6, 3, 'designer', 2);

-- Message table
CREATE TABLE `Message` (
  `messageid` int NOT NULL AUTO_INCREMENT,
  `sender_type` ENUM('client', 'designer','manager','Contractors','supplier') NOT NULL,
  `sender_id` int NOT NULL,
  `content` text  NOT NULL,
  `message_type` ENUM('text', 'image', 'file') DEFAULT 'text',
  `attachment` VARCHAR(500) NULL,
  `ChatRoomid` int NOT NULL,
  `timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`messageid`),
  KEY `idx_room` (`ChatRoomid`),
  KEY `idx_sender` (`sender_type`, `sender_id`),
  CONSTRAINT `fk_message_room` FOREIGN KEY (`ChatRoomid`) REFERENCES `ChatRoom` (`ChatRoomid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `Message` (`messageid`, `sender_type`, `sender_id`, `content`,`message_type`,`attachment`,`ChatRoomid`) VALUES
(1, 'manager', 1, 'hi','text',null,2),
(2, 'designer', 1, 'hello','text',null,2);

-- Table to store uploaded file metadata (uploader identity and path)
CREATE TABLE `UploadedFiles` (
  `fileid` int NOT NULL AUTO_INCREMENT,
  `uploader_type` ENUM('client', 'designer','manager','Contractors','supplier') NOT NULL,
  `uploader_id` int NOT NULL,
  `filename` varchar(500) NOT NULL,
  `filepath` varchar(1000) NOT NULL,
  `mime` varchar(255) DEFAULT NULL,
  `size` int DEFAULT NULL,
  `uploaded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`fileid`),
  KEY `idx_uploader` (`uploader_type`, `uploader_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-member read/unread status for messages
CREATE TABLE `MessageRead` (
  `messagereadid` INT NOT NULL AUTO_INCREMENT,
  `messageid` INT NOT NULL,
  `ChatRoomMemberid` INT NOT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `read_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`messagereadid`),
  UNIQUE KEY `uniq_msg_member` (`messageid`, `ChatRoomMemberid`),
  KEY `idx_message` (`messageid`),
  KEY `idx_member` (`ChatRoomMemberid`),
  CONSTRAINT `fk_messageread_message` FOREIGN KEY (`messageid`) REFERENCES `Message` (`messageid`) ON DELETE CASCADE,
  CONSTRAINT `fk_messageread_member` FOREIGN KEY (`ChatRoomMemberid`) REFERENCES `ChatRoomMember` (`ChatRoomMemberid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed read status: for each message add rows for members of that chatroom
-- Message 1 (room 2): manager (memberid 1) is sender -> read, designer (memberid 2) unread
-- Message 2 (room 2): designer (memberid 2) is sender -> read, manager (memberid 1) unread
INSERT INTO `MessageRead` (`messagereadid`, `messageid`, `ChatRoomMemberid`, `is_read`, `read_at`) VALUES
(1, 1, 1, 1, '2025-01-01 10:00:00'),
(2, 1, 2, 0, NULL),
(3, 2, 1, 0, NULL),
(4, 2, 2, 1, '2025-01-01 10:05:00');

CREATE TABLE `ProductLike` (
  `productlikeid` INT NOT NULL AUTO_INCREMENT,
  `clientid` INT NOT NULL,
  `productid` INT NOT NULL,
  PRIMARY KEY (`productlikeid`),
  UNIQUE KEY `unique_client_product` (`clientid`, `productid`),
  KEY `idx_clientid` (`clientid`),
  KEY `idx_productid` (`productid`),
  CONSTRAINT `fk_productlike_clientid` FOREIGN KEY (`clientid`) REFERENCES `Client` (`clientid`) ON DELETE CASCADE,
  CONSTRAINT `fk_productlike_productid` FOREIGN KEY (`productid`) REFERENCES `Product` (`productid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `ProductLike` (`productlikeid`, `clientid`, `productid`) VALUES
(1, 1, 1),
(2, 2, 2);

CREATE TABLE `DesignLike` (
  `designlikeid` INT NOT NULL AUTO_INCREMENT,
  `clientid` INT NOT NULL,
  `designid` INT NOT NULL,
  PRIMARY KEY (`designlikeid`),
  UNIQUE KEY `unique_client_design` (`clientid`, `designid`),
  KEY `idx_clientid` (`clientid`),
  KEY `idx_designid` (`designid`),
  CONSTRAINT `fk_designlike_clientid` FOREIGN KEY (`clientid`) REFERENCES `Client` (`clientid`) ON DELETE CASCADE,
  CONSTRAINT `fk_designlike_designid` FOREIGN KEY (`designid`) REFERENCES `Design` (`designid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `DesignLike` (`designlikeid`, `clientid`, `designid`) VALUES
(1, 1, 1),
(2, 2, 2);


-- Re-enable Foreign Key Checks
SET FOREIGN_KEY_CHECKS = 1;

-- Additional test data for stakeholders (clients, managers, contractors, designers, suppliers),
-- plus sample chat rooms/messages and an order with related entries.
-- Additional test data removed: duplicate user inserts were deleted to keep IDs consistent
-- (Client/Manager/Contractors/Designer/Supplier rows already exist earlier in the dump)

INSERT INTO `ChatRoom` (`ChatRoomid`, `roomname`, `description`, `room_type`, `created_by_type`, `created_by_id`) VALUES
(3, 'project-room', 'discussion for project X', 'group', 'client',2);

INSERT INTO `ChatRoomMember` (`ChatRoomMemberid`, `ChatRoomid`, `member_type`, `memberid`) VALUES
(3, 3, 'client', 2),
(4, 3, 'supplier', 2),
(5, 1, 'designer', 2);

INSERT INTO `Message` (`messageid`, `sender_type`, `sender_id`, `content`,`message_type`,`attachment`,`ChatRoomid`) VALUES
(3, 'client', 2, 'Hi team, starting project', 'text', NULL, 3),
(4, 'supplier', 2, 'We can supply materials next week', 'text', NULL, 3),
(5, 'designer', 2, 'I will prepare the plan', 'text', NULL, 1);

-- MessageRead rows for messages in ChatRoom 3 (room members: ChatRoomMemberid 3=client2,4=supplier2,6=designer2)
INSERT INTO `MessageRead` (`messagereadid`, `messageid`, `ChatRoomMemberid`, `is_read`, `read_at`) VALUES
(5, 3, 3, 1, '2025-07-01 09:01:00'),
(6, 3, 4, 0, NULL),
(7, 3, 6, 0, NULL),
(8, 4, 3, 0, NULL),
(9, 4, 4, 1, '2025-07-01 09:05:00'),
(10,4,6,0,NULL),
(11,5,5,1,'2025-06-01 08:00:00');

INSERT INTO `Order` (`orderid`, `odate`, `clientid`, `budget`, `Floor_Plan`, `Requirements`,`designid`,`ostatus`) VALUES
(3, '2025-07-01 09:00:00', 1, 1500, NULL, 'Need quick remodel', 1, 'Pending');

INSERT INTO `OrderProduct` (`orderproductid`, `productid`, `quantity`, `orderid`, `managerid`) VALUES
(3, 3, 50, 3, 1);

INSERT INTO `Order_Contractors` (`order_Contractorid`, `contractorid`, `orderid`, `managerid`) VALUES
(3, 1, 3, 1);

INSERT INTO `Schedule` (`scheduleid`,`managerid`,`FinishDate`,`orderid`) VALUES
(3,1,'2025-08-01 17:00:00',3);

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;