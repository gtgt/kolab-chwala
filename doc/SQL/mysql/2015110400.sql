CREATE TABLE IF NOT EXISTS `chwala_sessions` (
    `id`         varchar(40) BINARY NOT NULL,
    `uri`        varchar(1024) BINARY NOT NULL,
    `owner`      varchar(255) BINARY NOT NULL,
    `owner_name` varchar(255) DEFAULT NULL,
    `data`       mediumtext,
    PRIMARY KEY (`id`),
    INDEX `uri_index` (`uri`(255)),
    INDEX `owner` (`owner`)
) /*!40000 ENGINE=INNODB */ /*!40101 CHARACTER SET utf8 COLLATE utf8_general_ci */;

CREATE TABLE IF NOT EXISTS `chwala_invitations` (
    `session_id` varchar(40) BINARY NOT NULL,
    `user`       varchar(255) BINARY NOT NULL,
    `user_name`  varchar(255) DEFAULT NULL,
    `status`     varchar(16) NOT NULL,
    `changed`    datetime DEFAULT NULL,
    `comment`    mediumtext,
    CONSTRAINT `session_id_fk_chwala_invitations` FOREIGN KEY (`session_id`)
       REFERENCES `chwala_sessions`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX `session_id` (`session_id`),
    UNIQUE INDEX `user_session_id` (`user`, `session_id`)
) /*!40000 ENGINE=INNODB */ /*!40101 CHARACTER SET utf8 COLLATE utf8_general_ci */;
