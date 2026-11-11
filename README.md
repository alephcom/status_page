# status_page

A public status page fed by Zabbix, with Cachet-style incidents, maintenance, uptime history and subscriptions.

Inspired by [ChalmersLibrary/zabbix-public-statuspage](https://github.com/ChalmersLibrary/zabbix-public-statuspage) (live Zabbix data, tag filtering, stale fallback, compact/micro views) and [Cachet](https://cachethq.io) (incidents, components, subscribers). Built on Laravel 13 + Filament 5.

## Features

- **Components and groups.** Each component's status (operational, degraded, partial outage, major outage, maintenance) is derived from Zabbix triggers on hosts and/or host groups.
  Hosts and host groups are picked with search-as-you-type lookups against the Zabbix API.
- **Incidents** with a timeline (investigating → identified → monitoring → resolved), markdown updates, and an optional component status that applies while the incident is open. Hidden drafts are supported. Also:
  - **templates** for recurring problems;
  - **scheduled updates**, posted automatically at a set time;
  - **post-incident reviews** published on the incident page;
  - optional **AI drafting** with Claude. See [Incident tools](#incident-tools).
- **Automatic drafts from Zabbix.** When a component goes into a major outage, a hidden draft can be opened. When monitoring recovers, the draft resolves itself, or a published incident is flagged for you to resolve.
- **Planned maintenance** managed in the app. Affected components show "Under maintenance" during the window. Subscribers get a reminder and start/end notices. Windows can repeat daily, weekly or monthly.
- **Uptime history.** 90-day bars per component, computed from a local status-change log.
- **Response-time graphs** (or any numeric Zabbix item) under components.
- **SLA report.** Monthly uptime against a per-component target, with CSV download and a printable view.
- **Component dependencies.** If something a component depends on is down, the component shows it too.
- **Private components**, shown only to signed-in staff and to networks you allow.
- **Manual overrides** per component, with an optional expiry.
- **Subscribers.** E-mail with double opt-in, or one-click sign-up with Google. Subscribers can filter by component and by minimum impact. Also available:
  - Atom feeds, overall or per component;
  - an iCalendar feed of maintenance;
  - signed JSON webhooks and Discord webhooks.
- **Embedding.** SVG status badges and a JavaScript status widget for other sites.
- **Google sign-in** (optional) for subscribers and for admin panel staff.
- **Telegram** (optional): people subscribe through a bot, and admins can post to channels and groups.
- **Languages and branding.** The public page comes in English, German, Spanish, French, Dutch and Swedish. You can set a logo, accent colour, custom CSS, footer links and an announcement banner. Visitors can pick light or dark mode.
- **Admin panel** at `/admin`. Editors handle incidents, maintenance and templates. Admins also manage components, users, webhooks, subscribers and settings, and see the **audit log**.
- **JSON API** at `/api/v1`: public read endpoints, plus a token-authenticated write API. See [REST API](#rest-api).
- **MCP server** at `/mcp`, so AI assistants and agents can read status and manage incidents and maintenance. See [MCP server](#mcp-server).
- **Stale handling.** If Zabbix is unreachable, the last known statuses stay up and the page says the data may be out of date. Admins are e-mailed if it lasts. `/health` lets outside monitoring check the page itself.
- **Views:** `/` (normal), `/?view=compact` (wall displays), `/?view=micro` (phones).

## Screenshots

Taken from the demo data (`php artisan migrate:fresh --seed --seeder=DemoSeeder`).

<details>
<summary>Show screenshots</summary>

| | |
|---|---|
| ![Status page with announcement, recurring maintenance and a response-time graph](docs/screenshots/01-status-page.png) | Status page with announcement, recurring maintenance and a response-time graph |
| ![Response-time graph with hover readout](docs/screenshots/02-response-time-graph.png) | Response-time graph with hover readout |
| ![Published post-incident review](docs/screenshots/03-post-incident-review.png) | Published post-incident review |
| ![Subscribe page: minimum impact, badge and widget code](docs/screenshots/04-subscribe-and-embed.png) | Subscribe page: minimum impact, badge and widget code |
| ![Public page in French](docs/screenshots/05-french.png) | Public page in French |
| ![Dark mode](docs/screenshots/06-dark-mode.png) | Dark mode |
| ![Badges and the embeddable widget on another site](docs/screenshots/07-badges-and-widget.png) | Badges and the embeddable widget on another site |
| ![Signed-in staff also see private components](docs/screenshots/08-staff-see-private-components.png) | Signed-in staff also see private components |
| ![Incidents list: automatic draft flagged Recovered, with Resolve](docs/screenshots/09-admin-incidents-recovered.png) | Incidents list: automatic draft flagged Recovered, with Resolve |
| ![Incident timeline, with the Scheduled updates tab](docs/screenshots/10-admin-incident-timeline-scheduled.png) | Incident timeline, with the Scheduled updates tab |
| ![Create an incident from a template](docs/screenshots/11-admin-create-from-template.png) | Create an incident from a template |
| ![Weekly recurring maintenance](docs/screenshots/12-admin-recurring-maintenance.png) | Weekly recurring maintenance |
| ![Component options: visibility, SLA target, dependencies, graphs](docs/screenshots/13-admin-component-options.png) | Component options: visibility, SLA target, dependencies, graphs |
| ![Monthly SLA report](docs/screenshots/14-admin-sla-report.png) | Monthly SLA report |
| ![Webhooks, including Discord](docs/screenshots/15-admin-webhooks-discord.png) | Webhooks, including Discord |
| ![Audit log](docs/screenshots/16-admin-audit-log.png) | Audit log |
| ![Settings: reminders, alerts, branding, languages, private networks](docs/screenshots/17-admin-settings.png) | Settings: reminders, alerts, branding, languages, private networks |

</details>

## How status is worked out

1. Every 30 s `statuspage:poll` makes **one** `trigger.get` call. It fetches the triggers currently in problem state that carry all of `ZABBIX_PUBLIC_TAGS` (default `output=statuspage`), skipping hosts in Zabbix maintenance. That tag is the safety boundary: untagged triggers never reach the page.
2. Each component source matches those triggers by host (technical or visible name) or host group. It can also require extra tags. Trigger severity maps to a status; the default is Warning → degraded, Average → partial outage, High/Disaster → major outage, lower severities ignored. You can change the mapping per source.
3. The public status is, in order of precedence:
   - a manual override, if one is active;
   - otherwise "maintenance" during a maintenance window, or the monitored status outside one;
   - then combined with any open incident's component status and the status of the components it depends on, with the worst status winning.
4. Every change is logged, and `statuspage:rollup-uptime` (every 15 min) turns the log into daily uptime figures.

Zabbix 6.0 and later are supported. The client checks `apiinfo.version` and adapts to version differences:
- 6.0–6.2 send the token in the request body; 6.4+ use a `Bearer` header.
- 6.0 uses `selectGroups`; 6.2+ use `selectHostGroups`.

A component can also take its status from **Zabbix services** (Services → Services, 6.0+) instead of hosts. Pick the "Zabbix services (SLA)" source and list the service names. A service's status is the severity of the worst problem affecting it, mapped through the same severity table. Build those services from triggers you are happy to publish.

If a component **depends on** others (Components → edit → Depends on), it also takes their status, worst wins. Cycles are refused.

Status drivers live behind `App\StatusSources\StatusSource` and are registered in `config/statuspage.php`.

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

Run these alongside the web server. The scheduler runs these jobs:
- the Zabbix poll;
- uptime roll-ups;
- `statuspage:tick` every minute: maintenance notices, recurring windows, scheduled updates and stale-data alerts;
- graph fetching;
- a nightly `statuspage:prune`.

```bash
php artisan schedule:work
```

```bash
php artisan queue:work
```

In Zabbix:
- Tag the triggers you want public with `output=statuspage`, or whatever you set in `ZABBIX_PUBLIC_TAGS`.
- Give the API token a user that can read those hosts.

Then add components in `/admin`. For an uploaded logo, run `php artisan storage:link` once (Docker does this for you).

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

## Google sign-in

Optional. When `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` are set, Google buttons appear; otherwise nothing changes.

- **Subscribers:** "Subscribe with Google" on `/subscribe` creates a confirmed subscription straight away, since Google has verified the address. "Sign in with Google" opens an existing subscription. The e-mail link still works.
- **Staff:** "Sign in with Google" on `/admin/login` signs in an existing admin or editor whose e-mail matches the Google account. It never creates admin accounts. Password login still works.
- Subscribing with Google never signs anyone in to the admin panel.

Setup in [Google Cloud Console](https://console.cloud.google.com/apis/credentials):
1. Configure the OAuth consent screen (user type "External" so anyone with a Google account can subscribe). Only the `openid` and `email` scopes are requested.
2. Create an OAuth client ID of type "Web application" with the authorised redirect URI `<APP_URL>/auth/google/callback`.
3. Put the client ID and secret in `.env` as `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET`. Make sure `APP_URL` matches the public URL.

## Telegram

Optional. Telegram chats get the same notifications as e-mail subscribers (incidents, incident updates, maintenance, and component changes if that setting is on), filtered by component.

- **Subscribers:** "Get updates on Telegram" on `/subscribe` opens the bot with the ticked components, and pressing Start subscribes. People can also message the bot directly or add it to a group. Commands: `/start`, `/stop`, `/status`, `/help`.
- **Channels and groups:** admins add them under **Notifications → Telegram Chats** (`@channelname` or a numeric chat id). The bot must be an admin of a channel to post there. A test message is sent when one is added, and errors are shown in the list.
- Chats that block or remove the bot are dropped automatically. Admin-added chats are kept, with the error shown.
- Sending is queued and limited to 25 messages per second, below Telegram's limit.

Setup:
1. Create a bot with [@BotFather](https://t.me/BotFather) and put its token and username in `.env` as `TELEGRAM_BOT_TOKEN` and `TELEGRAM_BOT_USERNAME`.
2. With `APP_URL` set to the public HTTPS URL, run:

```bash
php artisan statuspage:telegram-setup
```

This registers the webhook (`/telegram/webhook`, authenticated with a secret derived from `APP_KEY`) and the bot's command menu. Run it again if the URL, token or `APP_KEY` changes.

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
- `list-incident-templates`

Write tools, which go through the same services as the admin panel:
- `create-incident`: `visible` is required. `false` saves a hidden draft, `true` publishes it and notifies subscribers. `template_id` fills in the rest from a template.
- `post-incident-update`: adds a timeline entry and sets the status. Use `resolved` to resolve. With `publish_at` the update is scheduled instead.
- `update-incident`: title, impact, components, forced component status, post-incident review. `visible: true` publishes a draft.
- `schedule-maintenance` (optionally recurring), `update-maintenance` (reschedule, or end early), `cancel-maintenance` (deletes it)
- `set-component-override`: admins only, and hidden from editors

Updates are attributed to the token's user. The endpoint is limited to 60 requests per minute per token.

## Incident tools

- **Templates** (Communication → Incident Templates) hold a title, first update, impact, component status and components. Pick one at the top of "Create incident", or pass `template_id` to the API or MCP.
- **Scheduled updates.** In an incident's timeline, "Post update" takes an optional *Post at* time. Pending updates are listed under *Scheduled updates*, where you can edit or cancel them. `statuspage:tick` posts them when due.
- **Post-incident reviews.** Once an incident is resolved, its edit page has a *Post-incident review* section. Published reviews show on the incident page, in the feed and in the API.
- **Recovery.** For incidents that monitoring opened, the poller watches the components. When every one is healthy again:
  - a hidden draft is resolved by itself (Settings → Status page → Automation);
  - a published incident is marked "Recovered" in the list, with a *Resolve* button.

### AI drafting with Claude (optional)

Set `ANTHROPIC_API_KEY` (and optionally `ANTHROPIC_MODEL`, default `claude-opus-5-5`). Then:
- "Create incident" gets a *Draft with Claude* button. It writes a title, a first update and an impact from the selected components and their active Zabbix problems. Anything already in the title is used as a hint.
- "Post update" gets a *Draft with Claude* button that proposes the next update and status from the timeline.
- *Post-incident review* gets a *Draft with Claude* button. Where the facts are missing, it leaves "[To be completed]" placeholders rather than guessing.
- Automatic drafts from Zabbix get readable public text instead of the generic one. They stay hidden until someone publishes them.

The prompt tells Claude not to copy host names, trigger expressions or other internal identifiers into public text. Drafts fill the form and nothing is published without a person. If the model declines a request, the API retries it on its default fallback model (server-side fallbacks).

## Maintenance

- A visible window e-mails subscribers once when it is scheduled. Then:
  - a **reminder** goes out before it starts (default 60 minutes; set it, or turn it off, in Settings);
  - **started** and **completed** notices go out (can be turned off).
- Webhooks get `maintenance.started` and `maintenance.completed` either way.
- **Repeating windows** (daily, weekly, monthly, optionally until a date): when one ends, the next is created with the same details and components. Subscribers get one "scheduled" e-mail for the series, then reminders and start/end notices for each window.
- `/maintenance.ics` is an iCalendar feed of planned maintenance; `?components=1,2` filters it.

## Subscriptions and feeds

- Subscribers choose components and a **minimum impact** (all, minor+, major+, critical). Maintenance notices always go out. Admins can set the same for Telegram chats.
- `/feed.atom?components=1,2` limits the Atom feed to those components, plus site-wide notices.
- Subscribers can't pick private components. Incidents that only affect private components are not e-mailed, but webhooks still get them.

## Public page

- **Badges:** `/badge.svg` (overall) and `/badge/{component id}.svg`. Both take an optional `?label=` and `?style=flat-square`. Example: `![status](https://status.example.com/badge.svg)`.
- **Widget:** `<script src="https://status.example.com/embed.js" async></script>` shows a small floating status pill linking to the page. To place it inline, add an element with `data-statuspage-widget`. It reads `/api/v1/status`.
- **Languages:** English, Deutsch, Español, Français, Nederlands and Svenska. Choose which are offered, and the default, in Settings → Status page → Languages. Visitors get their browser's language if it is offered, and a switcher in the footer when there is more than one. Translations are in `lang/*.json`. E-mails and the admin panel stay in English.
- **Branding:** logo, accent colour, default theme, footer links, custom CSS and an announcement banner (with optional expiry) under Settings → Status page. Visitors can switch between automatic, light and dark in the footer.
- **Graphs:** under a component (Components → edit → Graphs), add a Zabbix host and numeric item key, e.g. a web scenario's `web.test.time[...]`. Every 5 minutes `statuspage:poll-metrics` copies recent history into 5-minute averages. The page shows the last 24 hours with a hover readout and a table view. "Fetch graphs" in the components list tests the set-up.
- **Private components** (Components → edit → Visibility) appear only to signed-in staff and to visitors from the networks listed under Settings → Status page → Private components. Incidents and maintenance that only affect private components are hidden from everyone else, and private component names are left out of mixed ones. The same applies to the API, feeds and badges. Behind a reverse proxy, configure trusted proxies so the visitor IP is right.

## SLA report

Set an *SLA target* on components, then open Components → SLA report. It shows monthly uptime per component and whether the target was met. Uptime counts major outages as down and partial outages as half down; degraded performance and maintenance count as up. *Download CSV* exports the month. *Print / PDF* opens a printable page.

## REST API

Public, no token:
- `GET /api/v1/status`
- `GET /api/v1/components`
- `GET /api/v1/incidents`
- `GET /api/v1/incidents/{id}`
- `GET /api/v1/maintenances`

Write endpoints use the same bearer tokens as the MCP server (Settings → API tokens), with the same roles:

| Method | Path | Body |
|---|---|---|
| POST | `/api/v1/incidents` | `title`, `message`, `visible`, optional `status`, `impact`, `component_ids`, `component_status`, `template_id` |
| PATCH | `/api/v1/incidents/{id}` | any of `title`, `impact`, `visible`, `component_ids`, `component_status`, `postmortem`, `postmortem_published` |
| POST | `/api/v1/incidents/{id}/updates` | `status`, `message`, optional `publish_at` (schedules it; returns 202) |
| POST | `/api/v1/maintenances` | `title`, `scheduled_start`, `scheduled_end`, `visible`, `component_ids`, optional `message`, `recurrence`, `recurrence_until` |
| PATCH | `/api/v1/maintenances/{id}` | any of the above |
| DELETE | `/api/v1/maintenances/{id}` | — |
| PUT | `/api/v1/components/{id}/override` | `status` (or `clear`), optional `until`. Admins only. |

```bash
curl -X POST https://status.example.com/api/v1/incidents -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" -d title="Deploy in progress" -d message="Short interruptions are possible." -d visible=1
```

## Running it

- **`/health`** returns 200 when the database answers, the scheduler polled recently and monitoring data is fresh. Otherwise it returns 503, with details in JSON. Point outside uptime monitoring at it; `/up` only shows that PHP answers.
- **Stale-data alerts:** admins are e-mailed when monitoring data has been stale for 10 minutes (configurable), and again when it recovers.
- **Audit log** (Settings → Audit log, admins): every create, update and delete of incidents, updates, maintenance, components, templates, webhooks, users and settings. Each entry records the user, where the change came from (admin panel, MCP, REST API or the system), the IP and the changed fields. Secrets and passwords are never logged.
- **Retention:** `statuspage:prune` runs nightly. It deletes:
  - status-change log entries older than 400 days (daily uptime figures are kept);
  - graph samples older than 30 days;
  - resolved problems older than 90 days;
  - audit entries older than a year;
  - unconfirmed subscriptions older than 7 days.

  Change these with the `STATUSPAGE_KEEP_*` variables in `.env.example`. `--dry-run` shows what it would delete.

## Webhooks

Each webhook has a format:
- **JSON** (default) is POSTed as `{"event": "...", "sent_at": "...", "data": {...}}`. If a secret is set, `X-Statuspage-Signature: sha256=<hex HMAC-SHA256 of the raw body>` is added.
- **Discord**: paste a channel's webhook URL (Channel settings → Integrations → Webhooks). Events are posted as embeds.

Events:
- `incident.created`
- `incident.updated`
- `incident.resolved`
- `maintenance.scheduled`
- `maintenance.started`
- `maintenance.completed`
- `component.status_changed`
- `component.override_changed`
