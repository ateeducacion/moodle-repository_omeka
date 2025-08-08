-- Create Omeka DB and user if they do not exist
CREATE DATABASE IF NOT EXISTS `omeka_s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'omeka_s'@'%' IDENTIFIED BY 'omeka_s';
GRANT ALL PRIVILEGES ON `omeka_s`.* TO 'omeka_s'@'%';
FLUSH PRIVILEGES;
