
create table invite
(
    character_id          int          not null
        primary key,
    character_name        text         not null,
    email                 text         not null,
    email_history         text         null,
    invited_at            int          not null,
    slack_id              text         null,
    account_status        text         null,
    slack_name            varchar(255) null,
    previous_character_id int          null
)
    charset = utf8;
