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
3. On the **Dashboard**, choose **Transcode library** to process the selected library, or leave **All libraries** selected to process every library using its saved media settings. Existing active runs are reused, and search and availability filters do not limit these runs. Choose **Transcode** beside an individual file to process only that file.
4. Follow encoding and quality-control progress on **Queue** or the file's detail page.

A library can have one active run at a time. Missing files and files with stale inspection results need another scan before encoding. Library runs cover the entire library, including files hidden by Dashboard filters.

### Media settings

The **H.265 — Save storage** and **AV1 — Save storage** presets select the codec, the fixed quality for direct encoding, and Slow encoding. Apply a preset, then save the settings. Audio, subtitles, high dynamic range (HDR), and quality safeguards remain separately configurable.

Libraries inherit global preferences until you save an override. Settings are resolved when each file starts; changes apply to subsequent files.

By default, Rencoderr:

- Preserves resolution, frame timing, bit depth, HDR, audio, and subtitles where supported.
- Skips files already encoded with H.265 or AV1. Remove a codec from the skip list to convert those files.
- Requires at least **5% smaller output** and a **VMAF score of 95**.

VMAF measures perceptual video similarity. It helps screen results, but visual inspection remains useful, particularly for grain, dark scenes, and motion.

### Encoding and quality control

MP4 sources keep MP4 output; all other sources produce Matroska (`.mkv`). MP4 selection requires a `.mp4` extension (case insensitive) and MP4-family inspection metadata. Each container preserves compatible retained streams, including `mov_text` subtitles in MP4. A retained stream that cannot fit the output container skips the file with a reason; codecs are never silently converted or tracks removed. Source replacement follows the library write-back behavior described below.

With both savings and video-quality verification enabled, Rencoderr starts near the selected encoder’s normal quality range, measures representative samples, and adapts subsequent steps using measured VMAF and predicted size. It refines the quality/size boundaries and selects the smallest sampled candidate that meets both requirements before a full encode. **5% is the minimum worthwhile saving, not the target.** Disabling either check uses the selected fixed quality instead.

Preparation has limits on time, candidate count, and temporary storage. If it cannot find a suitable sampled candidate, the file is skipped with a reason and the closest measured result. Each request performs at most one full encode.

Completed encodes receive a separate quality check covering retained streams, frame timing, actual file size, and video quality. The overall video quality score must reach the configured minimum. **Segment VMAF tolerance (points)** in **Settings → Media** allows individual segments to fall below that minimum (default `1`; set `0` for a strict segment minimum). Libraries inherit the global value unless overridden in their media settings. For example, a minimum of `92` and tolerance of `1` require an overall score of at least `92` and every checked segment to score at least `91`. Settings are saved with each new transcode; existing checks keep their saved thresholds. A segment below its effective minimum cannot be hidden by a high overall average. The interface distinguishes a rejected result from a check that failed to finish; failed or cancelled checks can be retried.

Outputs are staged under `/config/storage/app/private/transcodes` by default. Estimated savings and verified potential savings are shown separately. Reclaimed disk space stays at zero while originals and staged outputs are retained, so allow additional storage for encoding.

An unchanged retained output prevents duplicate encoding unless quality control rejected it. After rejection, requesting the file again uses current settings. **Cancel run** stops waiting work and active encoding; **Retry failed** queues failed files in a new run. Container startup recovers interrupted work.

### Writing verified output back to a library

Choose a **Write-back behavior** when adding or editing each library:

- **Copy alongside for approval** (default) copies a passing encode beside its source. Use **Review replacement** on the Dashboard, Queue or media detail page to compare sizes, containers, streams and the measured video quality. **Replace original** queues the replacement; the source stays unchanged until then.
- **Replace automatically** installs the passing encode without waiting for review. Each transcode saves the library mode when it is queued; changing the mode does not approve older results.

Only completed, passing quality checks can write back. Checks disabled in the media settings are shown as not measured in the review. Original sources are deleted after the new file has been copied, verified and installed. The replacement keeps the original filename stem and uses its selected MP4 or MKV extension. A conflicting destination is never overwritten.

Pending copies are tracked and excluded from scans and transcode requests. Changed sources or encoded copies, unavailable folders and permission failures stop replacement and show an error. Fix the cause and use **Retry write-back** on the media detail page. Interrupted copies and replacements recover during startup; an installed file is not installed again. After installation the inventory is refreshed, a new media inspection is requested, and the staging output is removed. Reclaimed-space statistics appear only after replacement completes.

The library filesystem must allow writes, hard links and directory synchronization. Write-back has its own worker pool and a configurable `WRITEBACK_TIMEOUT_SECONDS` limit (default: six hours). This workflow applies to new transcodes; existing completed results are not installed automatically.

### Workers

Under **Settings → Workers**, set independent limits for standard tasks, encoding, write-back, and quality control. Each defaults to one worker. Changes apply without restarting the container; workers already processing a file finish before retiring.

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

Supported H.264, H.265, and AV1 inputs use GPU decoding on the selected VAAPI, Quick Sync, or NVIDIA device during preparation, scoring, and encoding. Lossless reference caching, structural frame inspection, and VMAF scoring still use CPU.

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
