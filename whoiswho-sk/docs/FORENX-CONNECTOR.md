# ForenX Connector (budúce napojenie)

ForenX/Argus sa na WhoIsWho SK napojí ako **oddelený API klient** — žiadne
zdieľané databázy, žiadny import kódu medzi repozitármi.

## Konfigurácia vo ForenX (environment)

```
WHOISWHO_API_URL=https://whoiswho.example.com/api/v1
WHOISWHO_API_KEY=<service API key>
```

## Enrich flow (plán)

1. ForenX má v spise firmu (IČO) alebo osobu.
2. Caller zavolá WhoIsWho:
   - `GET {WHOISWHO_API_URL}/companies/{ico}` — konsolidovaný profil
   - `GET {WHOISWHO_API_URL}/companies/{ico}/graph?depth=2` — prepojenia
   - `GET {WHOISWHO_API_URL}/companies/{ico}/risk` — risk flags
3. Odpoveď sa uloží ako **enrichment záznam** do spisu (s `retrieved_at`
   a `source_url[]` na dohľadateľnosť pôvodu dát).
4. ForenX nikdy neingestuje registre priamo — jediný zdroj je WhoIsWho API.

## Pravidlá

- 1 service API key pre ForenX (rate limit 60 req/min, viz `whoiswho-api` limiter).
- Voliteľne: hlavička `X-Caller: forenx` — WhoIsWho ju zapisuje do `audit_log.caller`.
- WhoIsWho je market-wide — nevie nič o ForenX spisoch; enrich je pull model.
