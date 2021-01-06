# neucore-plugin-slack

The plugin needs the following environment variables:
- NEUCORE_PLUGIN_SLACK_DB_DSN=mysql:dbname=brave_slack_signup;host=127.0.0.1
- NEUCORE_PLUGIN_SLACK_DB_USERNAME=username
- NEUCORE_PLUGIN_SLACK_DB_PASSWORD=password
- NEUCORE_PLUGIN_SLACK_CHANNEL="admin"
- NEUCORE_PLUGIN_SLACK_TOKEN="the-slack-token"
- NEUCORE_PLUGIN_SLACK_BOTNAME="slack-bot-name"

See also https://github.com/bravecollective/slack-signup

Install for development:
```shell
composer install
```
