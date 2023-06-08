# neucore-plugin-slack

A [Neucore](https://github.com/bravecollective/neucore) service plugin to request Slack invitations.

Account updates and removal notifications are done with 
[Neucore-Slack-Link](https://github.com/bravecollective/Neucore-Slack-Link).

## Requirements

- A [Neucore](https://github.com/bravecollective/neucore) installation.
- A MySQL database.

### Slack App

- Create a Slack app at https://api.slack.com/apps
- Add Bot Token Scope: `chat:write`
- Install app to workspace
- Add the bot to the "NEUCORE_PLUGIN_SLACK_CHANNEL" from the config

## Install

- Create the database schema from `slack_signup.sql`.

The plugin needs the following environment variables on the Neucore server:
- NEUCORE_PLUGIN_SLACK_DB_DSN=mysql:dbname=brave_slack_signup;host=127.0.0.1
- NEUCORE_PLUGIN_SLACK_DB_USERNAME=username
- NEUCORE_PLUGIN_SLACK_DB_PASSWORD=password
- NEUCORE_PLUGIN_SLACK_CHANNEL="admin"
- NEUCORE_PLUGIN_SLACK_TOKEN="the-slack-token"

Install for development:
```shell
composer install
```
