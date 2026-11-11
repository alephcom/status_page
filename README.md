# status_page

A public status page fed by Zabbix, with Cachet-style incidents, maintenance, uptime history and subscriptions.

Inspired by [ChalmersLibrary/zabbix-public-statuspage](https://github.com/ChalmersLibrary/zabbix-public-statuspage) (live Zabbix data, tag filtering, stale fallback, compact/micro views) and [Cachet](https://cachethq.io) (incidents, components, subscribers). Built on Laravel 13 + Filament 5.

## Features

- **Components and groups.** Each component's status (operational, degraded, partial outage, major outage, maintenance) is derived from Zabbix triggers on hosts and/or host groups.
- **Incidents** with a timeline (investigating → identified → monitoring → resolved), markdown updates, and an optional component status that applies while the incident is open. Hidden drafts are supported.
- **Planned maintenance** managed in the app. Affected components show "Under maintenance" during the window.
- **Uptime history.** 90-day bars per component, computed from a local status-change log.
- **Manual overrides** per component, with an optional expiry.
- **Subscribers.** E-mail with double opt-in and per-component filtering. Also an Atom feed and signed webhooks.
- **Admin panel** at `/admin`. Editors handle incidents and maintenance. Admins also manage components, users, webhooks, subscribers and settings.
- **Read-only JSON API** at `/api/v1/{status,components,incidents,maintenances}`.
- **MCP server** at `/mcp`, so AI assistants and agents can read status and manage incidents and maintenance. See [MCP server](#mcp-server).
- **Stale handling.** If Zabbix is unreachable, the last known statuses stay up and the page says the data may be out of date.
- **Views:** `/` (normal), `/?view=compact` (wall displays), `/?view=micro` (phones).

## How status is worked out

1. Every 30 s `statuspage:poll` makes **one** `trigger.get` call. It fetches the triggers currently in problem state that carry all of `ZABBIX_PUBLIC_TAGS` (default `output=statuspage`), skipping hosts in Zabbix maintenance. That tag is the safety boundary: untagged triggers never reach the page.
2. Each component source matches those triggers by host (technical or visible name) or host group. It can also require extra tags. Trigger severity maps to a status; the default is Warning → degraded, Average → partial outage, High/Disaster → major outage, lower severities ignored. You can change the mapping per source.
3. The public status is, in order of precedence:
   - a manual override, if one is active;
   - otherwise "maintenance" during a maintenance window, or the monitored status outside one;
   - then combined with any open incident's component status, with the worst status winning.
4. Every change is logged, and `statuspage:rollup-uptime` (every 15 min) turns the log into daily uptime figures.

Zabbix 6.0 and later are supported. The client checks `apiinfo.version` and adapts to version differences:
- 6.0–6.2 send the token in the request body; 6.4+ use a `Bearer` header.
- 6.0 uses `selectGroups`; 6.2+ use `selectHostGroups`.

Status drivers live behind `App\StatusSources\StatusSource`. A driver for Zabbix Services/SLA (`service.get` / `sla.getsli`) can be added there and registered in `config/statuspage.php`.

## Setup

```bash
composer install
```

```bash
cp .env.example .env && php artisan key:generate
```

Set `ZABBIX_API_URL` and `ZABBIX_API_TOKEN` in `.env`, then:

```bash
php artisan migrate
```

```bash
php artisan statuspage:make-admin you@example.com
```

Run these alongside the web server:

```bash
php artisan schedule:work
```

```bash
php artisan queue:work
```

In Zabbix:
- Tag the triggers you want public with `output=statuspage`, or whatever you set in `ZABBIX_PUBLIC_TAGS`.
- Give the API token a user that can read those hosts.

Then add components in `/admin`.

### Docker

```bash
docker compose up -d --build
```

```bash
docker compose exec app php artisan statuspage:make-admin you@example.com
```

This starts four containers: the web app on :8080, a queue worker, the scheduler, and MariaDB. Put `APP_KEY`, the Zabbix settings and the mail settings in `.env`.

## Development

There's a mock Zabbix API with canned problems:

```bash
php -S 127.0.0.1:3002 tests/MockZabbix/server.php
```

```bash
php artisan migrate:fresh --seed --seeder=DemoSeeder
```

```bash
ZABBIX_API_URL=http://127.0.0.1:3002/api_jsonrpc.php ZABBIX_API_TOKEN=mock-token php artisan statuspage:poll
```

```bash
php artisan serve
```

- `MOCK_ZABBIX_VERSION=7.0.5` emulates a newer API.
- `MOCK_ZABBIX_OK=1` reports no problems.
- Stopping the mock shows the stale notice.

The fixtures (`tests/Fixtures/ZabbixFixtures.php`) cover these cases:
- a trigger spanning two hosts
- a problem on an unconfigured host
- an ignored severity
- a match through a host group only
- a missing per-source tag

Run the tests:

```bash
php artisan test
```

## MCP server

`/mcp` is a [Model Context Protocol](https://modelcontextprotocol.io) endpoint (streamable HTTP, via `laravel/mcp`). It needs a bearer token belonging to an admin or editor. Create one in the admin panel under **Settings → API tokens**:
- Editors manage their own tokens.
- Admins see and revoke everyone's, and can issue a token for another user, e.g. a dedicated account for an on-call agent.
- Tokens can have an expiry date. The token is shown only once.

Or from the command line:

```bash
php artisan statuspage:mcp-token you@example.com --name=claude
```

Revoke it with `--revoke --name=claude`. Deleting a user revokes their tokens. Then add the server to your client, e.g. Claude Code:

```bash
claude mcp add --transport http statuspage https://status.example.com/mcp --header "Authorization: Bearer <token>"
```

Read tools (they also show hidden drafts):
- `get-status`: overall status, components, open incidents, upcoming maintenance
- `list-components`, `get-component` (active Zabbix problems, uptime)
- `list-incidents`, `get-incident` (full timeline)
- `list-maintenances`

Write tools, which go through the same services as the admin panel:
- `create-incident`: `visible` is required. `false` saves a hidden draft, `true` publishes it and notifies subscribers.
- `post-incident-update`: adds a timeline entry and sets the status. Use `resolved` to resolve.
- `update-incident`: title, impact, components, forced component status; `visible: true` publishes a draft.
- `schedule-maintenance`, `update-maintenance` (reschedule, or end early), `cancel-maintenance` (deletes it)
- `set-component-override`: admins only, and hidden from editors

Updates are attributed to the token's user. The endpoint is limited to 60 requests per minute per token.

## Webhooks

Webhooks are POSTed as JSON: `{"event": "...", "sent_at": "...", "data": {...}}`.

Events:
- `incident.created`
- `incident.updated`
- `maintenance.scheduled`
- `component.status_changed`

If a secret is set, `X-Statuspage-Signature: sha256=<hex HMAC-SHA256 of the raw body>` is added.
