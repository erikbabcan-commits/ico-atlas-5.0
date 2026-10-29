# WhoIsWho SK × ForenX — hranica produktov

## Zásada

**WhoIsWho SK = graf + due-diligence (market-wide).**
**ForenX/Argus = spis + AI (case-scoped).**

Dva samostatné produkty, žiadne zdieľané tabuľky, žiadny zdieľaný deploy,
žiadny kód WhoIsWho vo forzaxteligent monorepe.

## Čo patrí kam

| Téma | WhoIsWho SK | ForenX |
|---|---|---|
| Rozsah dát | celý trh (všetky subjekty SR) | konkrétne spisy/prípady |
| Graf osôb a firiem | ✅ jadro | len konzumuje cez API |
| Risk flags (deterministické) | ✅ počíta | môžečítať |
| LLM analýza spisu | ❌ | ✅ jadro |
| Case tables (Supabase) | ❌ nepoužíva | ✅ |
| DD report produkt | ✅ (monetizácia) | — |
| Výpisy z registrov | ✅ ingest + cache | len cez WhoIsWho API |

## Čo je zámerne mimo WhoIsWho

- klientsky scrape z browsera ( WhoIsWho ingestuje len backendovo, z register API)
- mock fake firmy pri výpadku zdrojov (radšej 404 ako fake dáta)
- závislosť na ForenX/Supabase case tables
- LLM „graf z textu" — hrany sú len z registrov + deterministicky odvodené
- billing UI (Stripe) — v MVP len stub endpoint

## Zdieľané vzťahy

- ForenX sa neskôr napojí ako **oddelený API klient** — pozri `docs/FORENX-CONNECTOR.md`.
- Žiadna priama DB zdieľaná vrstva. Jediný kontrakt je HTTP API v1 + Bearer key.
