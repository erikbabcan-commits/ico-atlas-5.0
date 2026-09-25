# WhoIsWho SK — Operations & Security Guide (OPS.md)

Tento dokument opisuje produkčnú prevádzku, rotáciu kľúčov, TLS konfiguráciu a údržbu WhoIsWho SK na Hetzner VPS (`2.29.52.59`).

---

## 1. Architektúra siete a porty na VPS

Všetka externá komunikácia prechádza cez **Nginx reverse proxy**:

| Port / Služba | Väzba | Popis |
|---|---|---|
| **443 (HTTPS)** | `0.0.0.0:443` | Nginx TLS proxy s certifikátom a bezpečnostnými hlavičkami |
| **80 (HTTP)** | `0.0.0.0:80` | Automatický redirect na HTTPS + Let's Encrypt ACME challenge |
| **8000 (WhoIsWho API)** | `127.0.0.1:8000` | Interná väzba (nevystavená do internetu) |
| **8080 (ICO Atlas)** | `0.0.0.0:8080` (alebo `/atlas/` cez 443) | Záložný legacy ORSR scraper (P3) |
| **5432 (PostgreSQL)** | Interná Docker sieť | Nevystavené do internetu (`whoiswho-sk_default`) |
| **22 (SSH)** | `0.0.0.0:22` | Chránené cez `id_ed25519` kľúč a fail2ban |

---

## 2. Rotácia servisného API kľúča (WHOISWHO_API_KEY)

Pri kompromitácii alebo pravidelnej rotácii postupujte takto:

1. **Vygenerujte nový silný kľúč (32 bajtov hex):**
   ```bash
   NEW_KEY=$(openssl rand -hex 32)
   ```

2. **Aktualizujte kľúč v `.env` na VPS:**
   ```bash
   sed -i "s/^WHOISWHO_API_KEY=.*/WHOISWHO_API_KEY=$NEW_KEY/" /opt/whoiswho-sk/.env
   ```

3. **Pretvorte kontajner pre načítanie novej hodnoty:**
   ```bash
   cd /opt/whoiswho-sk && docker compose up -d
   ```

4. **Aktualizujte klientske prostredia:**
   * **Vercel Produkcia:**
     ```bash
     printf "$NEW_KEY" | npx vercel env add WHOISWHO_API_KEY production --force
     ```
   * **Lokálny `.env`:** Nastavte `WHOISWHO_API_KEY` v klientskom projekte.

5. **Overenie:**
   * Starý kľúč musí okamžite vracať `HTTP 401 Unauthorized`.
   * Nový kľúč musí vracať `HTTP 200 OK`.

---

## 3. Pridanie oficiálnej domény a Let's Encrypt certifikátu

Na VPS je predinštalovaný `certbot` aj `python3-certbot-nginx`.

Akonáhle nasmerujete DNS A-záznam (napr. `whoiswho.bizagent.sk` ➜ `2.29.52.59`):

```bash
# 1. Získanie a automatické nastavenie certifikátu
certbot --nginx -d whoiswho.bizagent.sk

# 2. Test automatickej obnovy certifikátu
certbot renew --dry-run
```

Certbot automaticky upraví konfiguráciu Nginxu v `/etc/nginx/sites-available/whoiswho` a aktivuje systémový timer.

---

## 4. Firewall a ochrana (UFW & fail2ban)

* **UFW:** Povolené len porty `22`, `80`, `443` a `8080`.
* **Fail2ban:** Monitoruje neúspešné pokusy o prihlásenie cez SSH (`/var/log/auth.log`) a automaticky banuje útočníkov.
* **Health monitoring:**
  ```bash
  curl -s -k https://2.29.52.59/api/v1/health
  ```
