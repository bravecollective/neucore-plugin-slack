CREATE DATABASE `slack_signup` /*!40100 DEFAULT CHARACTER SET utf8 */;

CREATE TABLE `invite`
(
    `character_id`   int(11) NOT NULL,
    `character_name` text    NOT NULL,
    `email`          text    NOT NULL,
    `email_history`  text DEFAULT NULL,
    `invited_at`     int(11) NOT NULL,
    `slack_id`       text DEFAULT NULL,
    `account_status` text DEFAULT NULL,
    PRIMARY KEY (`character_id`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8;
