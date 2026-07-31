# Deploying Avion to a DigitalOcean droplet (IP-only, HTTP)

The whole stack (Postgres, Redis, MongoDB, Laravel API, queue worker, React
frontend, Caddy proxy) runs on **one droplet** via Docker Compose. A Caddy proxy
on port 80 serves the app and proxies `/api` to the backend, so everything is
same-origin — no domain or CORS setup needed.

## 1. Create the droplet
- **Ubuntu 24.04**, **2 GB RAM minimum** (building the frontend needs the memory).
- Add your SSH key. Note the **public IP**.

## 2. Install Docker
```bash
ssh root@YOUR_DROPLET_IP
curl -fsSL https://get.docker.com | sh
docker --version && docker compose version
```

## 3. Get the code (both repos, side by side)
```bash
mkdir -p ~/avion && cd ~/avion
git clone https://github.com/pteang/airline_backend_CS394.git
git clone https://github.com/BoShowSpeed/AirlineSystemFront-End.git
cd airline_backend_CS394
```
> The prod compose expects the frontend at `../AirlineSystemFront-End`. If you
> cloned it elsewhere, set `FRONTEND_CONTEXT` in `.env`.

## 4. Configure secrets
```bash
cp .env.prod.example .env
echo "base64:$(openssl rand -base64 32)"      # copy this into APP_KEY
nano .env                                       # set APP_KEY, APP_URL=http://YOUR_IP, DB_PASSWORD
```

## 5. Launch
```bash
docker compose -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.prod.yml logs -f backend   # watch migrate + seed
```
First boot builds images and seeds demo data (~1–2 min). Then open:

**http://YOUR_DROPLET_IP**

Seeded logins (all password `password`): admin `admin@airline.test`,
staff `captain@airline.test`, passenger `jane@example.test`.

## 6. Firewall (recommended)
```bash
ufw allow OpenSSH
ufw allow 80/tcp
ufw enable
```
Only 22 and 80 are public; Postgres/Redis/Mongo stay on the internal Docker
network.

## Everyday commands
```bash
# Update after pushing code changes:
cd ~/avion/airline_backend_CS394 && git pull
cd ~/avion/AirlineSystemFront-End && git pull
cd ~/avion/airline_backend_CS394
docker compose -f docker-compose.prod.yml up -d --build

docker compose -f docker-compose.prod.yml ps       # status
docker compose -f docker-compose.prod.yml down      # stop (data kept)
docker compose -f docker-compose.prod.yml down -v   # stop + WIPE data
```

## Notes / next steps
- **HTTP only** (no domain → no free TLS). To add HTTPS later: point a domain at
  the droplet and change the Caddyfile's `:80` to your domain — Caddy fetches a
  Let's Encrypt cert automatically.
- To avoid building on the droplet, publish images to GHCR via CI and switch the
  compose `build:` blocks to `image:` — lighter and faster.
- Back up the `dbdata` volume regularly, or move to DigitalOcean Managed Postgres.
