# Docker: Nextcloud with Merlin

A local development and test environment: the official
[`nextcloud`](https://hub.docker.com/_/nextcloud) image with Merlin built from
this checkout, installed into `custom_apps/merlin` and enabled. **Not for
production** (default passwords, no TLS, no backups).

## Quick start

```bash
docker compose up --build
```

Open <http://localhost:8080> and log in as `admin` / `admin`. The first start
takes a minute or two (Nextcloud installation). Merlin appears in the app menu.

What runs:

| Service | Purpose |
|---|---|
| `app` | Nextcloud (Apache) + Merlin, built from the `Dockerfile` |
| `db` | MariaDB 11.4 |
| `redis` | Caching and file locking |
| `cron` | Runs Nextcloud background jobs every 5 min, incl. Merlin's retention cleanup |

## Configuration

Put overrides in a `.env` file next to `docker-compose.yml` (it's gitignored):

```dotenv
NEXTCLOUD_VERSION=34-apache        # any version from appinfo/info.xml (30-35)
NEXTCLOUD_PORT=8080
NEXTCLOUD_ADMIN_USER=admin
NEXTCLOUD_ADMIN_PASSWORD=admin
NEXTCLOUD_TRUSTED_DOMAINS=localhost 127.0.0.1 192.168.1.20
MYSQL_PASSWORD=nextcloud
MYSQL_ROOT_PASSWORD=root
```

Admin user, database and trusted domains only take effect on the **first**
start. To start over completely: `docker compose down -v`.

## Updating Merlin after code changes

```bash
docker compose up --build -d
```

The image contains the built app (frontend via Node, PHP dependencies via
Composer). On every container start, `docker/merlin-hook.sh` syncs it into the
persistent `custom_apps/merlin`, runs `occ upgrade` if the app version changed
(database migrations) and enables the app.

## Useful commands

```bash
docker compose exec -u www-data app php occ app:list | grep -A1 merlin
docker compose exec -u www-data app php occ merlin:retention:run --help
docker compose logs -f app
```

## Without Compose

The image also works on its own, e.g. with SQLite:

```bash
docker build -t merlin-nextcloud .
docker run -d -p 8080:80 \
  -e SQLITE_DATABASE=nextcloud \
  -e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD=admin \
  -v merlin-nextcloud:/var/www/html merlin-nextcloud
```

Without a cron container, Nextcloud's background jobs (and Merlin's retention
cleanup) don't run; add one with `--entrypoint /cron.sh` on the same volume.

## Not included

The optional Piper TTS daemon for read-aloud (see `INSTALLATION.md`) is not
part of this setup.
