# Rencoderr

A self-hosted media library manager focused on saving storage while preserving visual quality, built with Laravel, Livewire, and FFmpeg.

Rencoderr's goal is to scan your media libraries and re-encode files using more efficient codecs, such as converting H.264 to AV1, to reduce file sizes without a perceptible loss of quality.

The project is in active development. Library management, scheduled scans, media inventory, and hardware encoder checks are available. Re-encoding is planned; the transcoding queue is currently a placeholder.

## Docker Compose

Requires Docker with the Compose plugin. The current container targets Linux AMD64 and includes the web server, background workers, scheduler, FFmpeg, SQLite, and Valkey.

Create a directory for the application:

```bash
mkdir rencoderr
cd rencoderr
```

Save the following as `compose.yaml`. It uses the image published to GitHub Container Registry from [Lukasss93/rencoderr](https://github.com/Lukasss93/rencoderr):

```yaml
services:
  rencoderr:
    container_name: rencoderr
    image: ghcr.io/lukasss93/rencoderr:latest
    platform: linux/amd64
    restart: unless-stopped
    stop_grace_period: 30s
    ports:
      - "8000:8000"
    environment:
      PUID: "1000"
      PGID: "1000"
      TZ: Europe/Rome
      APP_URL: http://localhost:8000
      AUTH_ENABLED: "true"
    volumes:
      - ./config-data:/config
      - ./media:/media
```

Set `PUID` and `PGID` to the host user and group that should access your media (`id -u` and `id -g`). Replace `./media` with your media directory, and set `APP_URL` to the address you will use to access the application.

Start the application:

```bash
docker compose pull
docker compose up -d
docker compose logs -f rencoderr
```

Startup creates the database, runs migrations, and generates an application key that is persisted under `/config`.

Once the container is healthy, create your first account using the interactive prompts:

```bash
docker compose exec --user rencoderr rencoderr php artisan user:create
```

Open `http://localhost:8000`, sign in, and add a library under **Settings → Libraries**. Use container paths such as `/media/movies`, rather than host paths.

### Persistent data

- `/config` stores the database, application key, and runtime data. Keep it on local storage with reliable filesystem locking and back it up when replacing or upgrading the container.
- `/media` exposes your media files to the application. You can mount additional directories if needed.

A `.env` file is not required for this example; configure the environment variables and volume paths directly in `compose.yaml`.

### Queue workers

Open **Settings → Workers** to set the maximum number of background workers. Scans and media probes share this pool, with scans taking priority. Horizon keeps at least one worker and scales up as queued work increases.

Saving applies the limit automatically while Horizon runs; no Horizon or container restart is needed. Worker counts adjust gradually. When lowering the limit, retiring workers finish their current jobs within the existing timeouts before exiting. Saving a limit does not resume paused workers.

The page shows the saved maximum, the limit reported by Horizon, and the current worker count. Workers finishing their last job are excluded from that count. If Horizon is offline, the saved limit applies when it starts; if status cannot be read, the page shows that it is unavailable rather than claiming the limit was applied.

The saved maximum persists in `/config` and takes precedence over `HORIZON_WORKERS`. Until you save a maximum, `HORIZON_WORKERS` supplies the default, which is `1`.

### Hardware acceleration

For Intel or AMD hardware exposed through `/dev/dri`, add this under the `rencoderr` service:

```yaml
    devices:
      - /dev/dri:/dev/dri
```

For NVIDIA hardware, install the NVIDIA Container Toolkit on the host, add `NVIDIA_DRIVER_CAPABILITIES: compute,video,utility` to the service's `environment`, and add:

```yaml
    deploy:
      resources:
        reservations:
          devices:
            - driver: nvidia
              count: all
              capabilities: [gpu]
```

Apply the changes with `docker compose up -d`. Open **Settings → Status** to inspect detected encoders, test them, and choose your hardware acceleration preference. Availability depends on the host GPU, drivers, and FFmpeg support.

## Development checks

Clone the source repository for development:

```bash
git clone https://github.com/Lukasss93/rencoderr.git
cd rencoderr
```

With the project dependencies installed, run:

```bash
composer test
vendor/bin/phpstan analyse --no-progress
vendor/bin/pint --test
```
