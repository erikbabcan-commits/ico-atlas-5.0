# F0 Verify — stav zdrojov dát

Live smoke test vykonaný pred vypnutím legacy scrapu (dátum overenia: 2025-09-25, z vývojového prostredia).

| Zdroj | URL | Funguje | Poznámka |
|---|---|---|---|
| **RPO** (Register právnických osôb, ŠÚSR/MV SR) | `https://api.statistics.sk/rpo/v1/search?identifier={ico}` → `/entity/{id}` | **ÁNO** | Overené na IČO 31333532 (ESET, spol. s r.o.): vrátil `id=937053`, meno, právnu formu, sídlo, 3 konateľov (statutoryBodies), 8+ zainteresovaných osôb (stakeholders), vklady, predchodcov. Licencia cc-by 4.0. Dokumentácia: https://rpo.minv.sk/rpo-api-doc.html |
| **RÚZ** (Register účtovných závierok, MF SR) | `https://www.registeruz.sk/cruz-public/api/uctovne-jednotky?zmenene-od=2000-01-01&ico={ico}` → `/uctovna-jednotka?id={id}` | **ÁNO** | Overené na IČO 47700203: vrátil `id=[1535938]`. Detail obsahuje DIČ, SK NACE, právnu formu, veľkosť org., dátum založenia. Licencia CC0. Dokumentácia: https://www.registeruz.sk/cruz-public/home/api |
| **RPVS** (Register partnerov verejného sektora, MS SR) | `https://rpvs.gov.sk/opendatav2/PartneriVerejnehoSektora?$filter=Ico eq '{ico}'` → `/Partneri({id})?$expand=*` | **ÁNO** | Overené: IČO 31333532 → záznam Id 20634, PlatnostOd 2017-02-01. Swagger: https://rpvs.gov.sk/opendatav2/swagger (OData v2, `swagger.json` prístupný). $top/$skip obmedzené (limit 0 pre Top query) — používa sa len $filter. |
| **sluzby.orsr.sk** (Obchodný register MS SR) | `https://sluzby.orsr.sk/` | **NIE (žiadne verejné REST API)** | Portál je live, ale poskytuje len HTML vyhľadávanie — neexistuje oficiálne OpenAPI/REST rozhranie. Nový zákon 29/2026 Z. z. efektívne od 17. 8. 2026 priniesla zmeny, ale strojové rozhranie pre verejnosť stále chýba. |

## Záver F0

- **RPO, RÚZ, RPVS sú primárne cesty** — všetky tri fungujú, bohaté dáta, oficiálne API.
- **OR OpenAPI nefunguje** → podľa zadania zostáva **Atlas ORSR HTML scrape ako P3 fallback**:
  - defaultne vypnutý (`WHOISWHO_ORSR_SCRAPE_ENABLED=false`)
  - backend only, nikdy z browsera
  - cache TTL ≥ 24 h
  - ≤ 1 req/s
  - exponential backoff + circuit breaker (5 zlyhaní → 15 min open)
- Žiadny klientsky scrape, žiadne mock fake firmy — pri výpadku zdrojov endpoint vracia 404.

## Implementované endpointy (presné tvary)

### RPO
```
GET https://api.statistics.sk/rpo/v1/search?identifier={8-ciferne IČO}
GET https://api.statistics.sk/rpo/v1/entity/{id}?showHistoricalData=true
```

### RÚZ
```
GET https://www.registeruz.sk/cruz-public/api/uctovne-jednotky?zmenene-od=2000-01-01&ico={IČO}
GET https://www.registeruz.sk/cruz-public/api/uctovna-jednotka?id={ruz_id}
```

### RPVS (OData v2)
```
GET https://rpvs.gov.sk/opendatav2/PartneriVerejnehoSektora?$filter=Ico eq '{IČO}'
GET https://rpvs.gov.sk/opendatav2/Partneri({CisloVlozky})?$expand=*
```

## Licencie zdrojov

- RPO: Creative Commons Attribution 4.0 (cc-by 4.0), zákon č. 272/2015 Z. z.
- RÚZ: CC0.
- RPVS: verejné open data (OData v2).
