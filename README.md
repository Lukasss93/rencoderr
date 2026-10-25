# Rencoderr

A self-hosted media library manager that re-encodes video to H.265 or AV1 to save storage while preserving visual quality. Built with Laravel, Livewire, and FFmpeg.

Rencoderr is in active development. It scans libraries, inspects media, selects encoding settings, and checks the results. **Originals and encoded outputs are retained; automatic replacement is not available yet.**

## Docker Compose

Requires Docker with the Compose plugin on Linux AMD64. The image includes the web server, background workers, scheduler, FFmpeg with VMAF, SQLite, and Valkey.

Create an application directory and save this as `compose.yaml`:

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

Set `PUID` and `PGID` to your host user and group (`id -u` and `id -g`), replace `./media` with your media directory, and set `APP_URL` to the address you will use.

Start the application:

```bash
docker compose pull
docker compose up -d
docker compose logs -f rencoderr
```

Startup creates the database, applies migrations, and persists an application key under `/config`. Once the container is healthy, create your first account:

```bash
docker compose exec --user rencoderr rencoderr php artisan user:create
```

Open `http://localhost:8000`, sign in, and add a library under **Settings → Libraries**. Use paths visible inside the container, such as `/media/movies`.

### Persistent data

- `/config` holds the database, application key, runtime data, and encoded outputs. Keep it on local storage with reliable filesystem locking and back it up before upgrades.
- `/media` exposes your media library. Additional directories can be mounted as needed.

Configure environment variables directly in Compose; a separate `.env` file is optional. See [.env.example](.env.example) for available settings, including encoding limits, temporary storage, and quality-control resources.

To update:

```bash
docker compose pull
docker compose up -d
```

## Using Rencoderr

1. Add a library, scan it, and wait for media inspection to finish.
2. Choose global preferences under **Settings → Media**, or override individual settings for a library under **Settings → Libraries**.
3. On the **Dashboard**, choose **Transcode library** or **Transcode** beside an individual file.
4. Follow encoding and quality-control progress on **Queue** or the file's detail page.

A library can have one active run at a time. Missing files and files with stale inspection results need another scan before encoding. Library runs cover the entire library, including files hidden by Dashboard filters.

### Media settings

The **H.265 — Save storage** and **AV1 — Save storage** presets select the codec, a starting quality hint, and Slow encoding. Apply a preset, then save the settings. Audio, subtitles, high dynamic range (HDR), and quality safeguards remain separately configurable.

Libraries inherit global preferences until you save an override. Settings are resolved when each file starts; changes apply to subsequent files.

By default, Rencoderr:

- Preserves resolution, frame timing, bit depth, HDR, audio, and subtitles where supported.
- Skips files already encoded with H.265 or AV1. Remove a codec from the skip list to convert those files.
- Requires at least **5% smaller output** and a **VMAF score of 95**.

VMAF measures perceptual video similarity. It helps screen results, but visual inspection remains useful, particularly for grain, dark scenes, and motion.

### Encoding and quality control

With both savings and video-quality verification enabled, Rencoderr measures representative samples and searches for the smallest acceptable output using the selected acceleration backend. **5% is the minimum worthwhile saving, not the target.** Disabling either check uses the selected fixed quality hint instead.

Preparation has limits on time, candidate count, and temporary storage. If it cannot confirm a suitable candidate, the file is skipped with a reason. Each request performs at most one full encode.

Completed encodes receive a separate quality check covering retained streams, frame timing, actual file size, and video quality. A poor measured segment cannot be hidden by a high overall average. The interface distinguishes a rejected result from a check that failed to finish; failed or cancelled checks can be retried.

Outputs are staged under `/config/storage/app/private/transcodes` by default. Estimated savings and verified potential savings are shown separately. Reclaimed disk space stays at zero while originals and staged outputs are retained, so allow additional storage for encoding.

An unchanged retained output prevents duplicate encoding unless quality control rejected it. After rejection, requesting the file again uses current settings. **Cancel run** stops waiting work and active encoding; **Retry failed** queues failed files in a new run. Container startup recovers interrupted work.

### Workers

Under **Settings → Workers**, set independent limits for standard tasks, encoding, and quality control. Each defaults to one worker. Changes apply without restarting the container; workers already processing a file finish before retiring.

## Hardware acceleration

Hardware encoding requires a compatible host GPU and driver. For Intel or AMD devices, add this under the Compose service:

```yaml
    devices:
      - /dev/dri:/dev/dri
```

For NVIDIA, install the NVIDIA Container Toolkit on the host, add `NVIDIA_DRIVER_CAPABILITIES: compute,video,utility` to the service's environment, and add:

```yaml
    deploy:
      resources:
        reservations:
          devices:
            - driver: nvidia
              count: all
              capabilities: [gpu]
```

Apply the changes with `docker compose up -d`. Open **Settings → Status** to refresh detection, test encoders, and choose acceleration:

- **Auto:** prefers an available hardware backend and keeps storage optimization on that encoder.
- **Software:** uses the CPU.
- **Explicit backend:** uses the selected backend when available. If it becomes unavailable, encoding falls back to software for the same codec.

On systems with multiple GPUs, set **Hardware device** to the NVIDIA GPU number, such as `1`, or the Intel/AMD render path, such as `/dev/dri/renderD129`. Leave it empty to use the default device.

## Development

With the project dependencies installed:

```bash
composer test
vendor/bin/phpstan analyse --no-progress
vendor/bin/pint --test
npm run build
```

Build a local container image:

```bash
podman build --format docker --tag localhost/rencoderr:dev .
```

The build checks software encoding, decoding, and VMAF. GPU execution needs verification on the deployment host. The [Dockerfile](Dockerfile) contains the pinned FFmpeg build configuration.
