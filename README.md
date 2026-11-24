# HowMarket API

Laravel backend for [HowMarket](https://github.com/tejHacks/howmarket-mobile), a crowdsourced app for checking food prices in Nigerian markets.

People report what an item costs in a market, and the server keeps those reports honest. The app shows a consensus price that a single wrong or fake report can't move.

## What the server enforces

- **Accounts.** Sign-in uses Sanctum tokens. Passwords are hashed, and sign-up and login are limited to 10 tries a minute.
- **Reports.**
  - The item and market must exist, and the price must be between ₦1 and ₦99,999,999.
  - Each person can post at most **20 reports in 24 hours**; the 21st gets a 429.
- **Votes.** You can confirm a report or flag it, once per report, and never on your own report.
- **Trust.** Each reporter has a trust score from 0.2 to 2, and only the server changes it.
  - It goes up when your price is close to the current consensus and down when it's far off.
  - The phone can't set it.
- **Privacy.** Other people appear only as an anonymous id: a keyed hash of their account. Emails and database ids are never sent out.
- **Consensus.** [`app/Services/Consensus.php`](app/Services/Consensus.php) is a line-for-line port of the app's `src/lib/consensus.ts`. It uses:
  - recency decay (3-day half-life),
  - trust and vote weighting,
  - a median-absolute-deviation outlier test,
  - a weighted median.

## Endpoints (`/api`)

| Method | Path | Auth | |
|---|---|---|---|
| GET | `/health` | – | Liveness |
| POST | `/register` | – | `name, email, password, area` → token + profile |
| POST | `/login` | – | `email, password` → token + profile |
| GET | `/me` | token | Profile, trust, reports today |
| PATCH | `/me` | token | `name?, area?` |
| POST | `/logout` | token | Revokes this token |
| GET | `/snapshot?area=` | optional | Markets, items, last 14 days of reports, trust by anonymous id, your votes |
| GET | `/items/{id}/prices?area=` | – | Server-side consensus per market and area-wide |
| POST | `/reports` | token | `item_id, market_id, price` |
| POST | `/reports/{id}/vote` | token | `vote: confirm \| flag` |

Areas: `Ibadan`, `Lagos`, `Abuja`.

## Run locally

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan serve --host 0.0.0.0 --port 8001
```

From the Android emulator the server is at `http://10.0.2.2:8001/api`. From the iOS simulator it's at `http://127.0.0.1:8001/api`.

## Tests

```bash
php artisan test
```

The suite has 24 tests:
- the consensus maths, mirroring the app's test cases,
- auth,
- the daily limit,
- the vote rules,
- trust updates,
- anonymous ids,
- the area and 14-day filters.
