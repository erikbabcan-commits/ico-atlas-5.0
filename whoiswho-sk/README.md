# WhoIsWho SK

Graf due-diligence pre slovenské firmy a osoby — vrcholy **Person ↔ Company**, hrany
**STATUTORY, SHAREHOLDER, UBO, SAME_SEAT, INSOLVENCY, PUBLIC_MONEY**.

Killer query: *„štatutár v N firmách, K v konkurze, A rovnaké sídlo"* — všetko
deterministicky z verejných registrov SR, bez LLM.

> **Disclaimer:** WhoIsWho SK nie je úradný výpis. Údaje sú informatívne, získané z
> verejných registrov SR. Zohľadnite zákon č. 29/2026 Z. z. o obchodnom registri.

## Čo to je

- Samostatný produkt odvodený od ICO Atlas 5.0 (Laravel) — prevzaté je len jadro
  ingestu/API firiem, HTTP klienti na registre, cache a API key auth pattern.
- **NIE** ďalší lookup wrapper — jadro je graf osôb a firiem s derived edges a
  deterministickými risk flags.
- ForenX/Argus zostáva oddelený klient — napojí sa neskôr cez API
  (pozri `docs/FORENX-CONNECTOR.md`).

## Architektúra (3 vrstvy)

1. **Orchestrator** (`app/Services/IngestOrchestrator.php`) — volá RPO + RÚZ + RPVS,
   konsoliduje profil, perzistuje nodes/edges. Zlyhanie jedného zdroja nebrní ostatným.
2. **Persistence** — PostgreSQL: `companies`, `persons`, `edges`, `audit_log`,
   `report_jobs` (migrácie v `database/migrations/`). MVP bez Neo4j.
3. **API** — verejné v1 endpointy s Bearer service API key auth.

## Zdroje dát (priorita)

1. **RPO** — `api.statistics.sk/rpo/v1` (ŠÚSR/MV SR, cc-by 4.0) — primárny zdroj
   identity, štatutárov, spoločníkov.
2. **RÚZ** — `registeruz.sk/cruz-public/api` (CC0) — DIČ, SK NACE, zápisy.
3. **RPVS** — `rpvs.gov.sk/opendatav2` (OData v2) — partneri verejného sektora, KÚV.
4. **ORSR scrape** — **P3 fallback, defaultne vypnutý** (`WHOISWHO_ORSR_SCRAPE_ENABLED=false`),
   backend only, TTL ≥ 24 h, ≤ 1 req/s, exponential backoff + circuit breaker.

Stav overenia zdrojov: `docs/SOURCES.md`.

## Spustenie (Docker)

```bash
cp .env.example .env
# vygeneruj APP_KEY a WHOISWHO_API_KEY:
docker compose run --rm api php artisan key:generate
docker compose up -d --build
docker compose run --rm api php artisan migrate
```

API beží na `http://localhost:8000`.

Bez Dockeru:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

## API v1

| Metóda | Endpoint | Popis |
|---|---|---|
| GET | `/api/v1/health` | health check (bez auth) |
| GET | `/api/v1/companies/{ico}` | konsolidovaný profil firmy |
| GET | `/api/v1/companies/{ico}/graph?depth=1|2&edges=STATUTORY,...` | graf nodes+edges |
| GET | `/api/v1/companies/{ico}/risk` | deterministické risk flags + score 0–1 |
| POST | `/api/v1/reports/due-diligence` | **stub** — vráti job id + JSON draft |

Auth: `Authorization: Bearer <WHOISWHO_API_KEY>`.

```bash
KEY=...whoiswho api key...

curl -s http://localhost:8000/api/v1/health

curl -s -H "Authorization: Bearer $KEY" \
  http://localhost:8000/api/v1/companies/31333532

curl -s -H "Authorization: Bearer $KEY" \
  "http://localhost:8000/api/v1/companies/31333532/graph?depth=1&edges=STATUTORY,SHAREHOLDER"

curl -s -H "Authorization: Bearer $KEY" \
  http://localhost:8000/api/v1/companies/31333532/risk

curl -s -X POST -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -d '{"ico":"31333532"}' \
  http://localhost:8000/api/v1/reports/due-diligence
```

Každý výstup JSON obsahuje `meta.source_url[]`, `meta.retrieved_at` a `meta.disclaimer`.

## Risk flags (deterministické, nie LLM)

| Kód | Význam |
|---|---|
| `MULTI_BOARD` | štatutár pôsobí v N+ firmách (default N≥2) |
| `INSOLVENT_LINKS` | napojenie na konkurz/likvidáciu (status, predchodcovia) |
| `SHARED_SEAT` | rovnaké normalizované sídlo s inou firmou |
| `RPVS_UBO` | koneční užívatelia výhod v RPVS |

Score 0–1 = súčet váh zapnutých flagov (konfigurovateľné v `config/whoiswho.php`).

## Monetizácia (neskôr)

DD report / seats — teraz len stub endpoint `POST /reports/due-diligence` (vráti job id
+ JSON draft). Žiadny Stripe/billing UI v tejto fáze.

## Hranica vs ForenX

Pozri `docs/BLUEPRINT.md` — **WhoIsWho = graf/DD (market-wide), ForenX = spis/AI
(case-scoped)**. Žiadny kód WhoIsWho neleží v forzaxteligent monorepu.
