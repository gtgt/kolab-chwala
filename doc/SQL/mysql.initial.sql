CREATE TABLE IF NOT EXISTS `chwala_locks` (
    `uri`     varchar(512) BINARY NOT NULL,
    `owner`   varchar(256),
    `timeout` integer unsigned,
    `expires` datetime DEFAULT NULL,
    `token`   varchar(256),
    `scope`   tinyint,
    `depth`   tinyint,
    INDEX `uri_index` (`uri`, `depth`),
    INDEX `expires_index` (`expires`),
    INDEX `token_index` (`token`)
) /*!40000 ENGINE=INNODB */ /*!40101 CHARACTER SET utf8 COLLATE utf8_general_ci */;

CREATE TABLE IF NOT EXISTS `chwala_sessions` (
    `id`      varchar(40) BINARY NOT NULL,
    `uri`     varchar(1024) BINARY NOT NULL,
    `data`    mediumtext,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `uri_index` (`uri`(255)),
    INDEX `expires_index` (`expires`)
) /*!40000 ENGINE=INNODB */ /*!40101 CHARACTER SET utf8 COLLATE utf8_general_ci */;

INSERT INTO `system` (`name`, `value`) VALUES ('chwala-version', '2015110400');
