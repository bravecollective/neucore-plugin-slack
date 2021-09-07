
CREATE TABLE `invite`
(
    `character_id`   int(11) NOT NULL,
    `character_name` text    NOT NULL,
    `email`          text    NOT NULL,
    `email_history`  text DEFAULT NULL,
    `invited_at`     int(11) NOT NULL,
    `slack_id`       text DEFAULT NULL,
    `account_status` text DEFAULT NULL,
    `slack_name`     varchar(1024) DEFAULT NULL,
    PRIMARY KEY (`character_id`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4;
