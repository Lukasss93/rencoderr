# Rencoderr

A self-hosted media library manager focused on saving storage while preserving visual quality, built with Laravel, Livewire, and FFmpeg.

Rencoderr's goal is to scan your media libraries and re-encode files using more efficient codecs, such as converting H.264 to AV1, to reduce file sizes without a perceptible loss of quality.

The project is in active development. Library management, scheduled scans, media inventory, hardware encoder checks, and media settings with library overrides are available. Library transcoding stages encoded outputs for later quality control. Originals remain untouched; quality evaluation and replacement are not performed.

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

SQLite uses write-ahead logging (WAL) by default. Set `DB_JOURNAL_MODE` to override the journal mode.

- `/config` stores the database, application key, and runtime data. Keep it on local storage with reliable filesystem locking and back it up when replacing or upgrading the container.
- `/media` exposes your media files to the application. You can mount additional directories if needed.

A `.env` file is not required for this example; configure the environment variables and volume paths directly in `compose.yaml`.

### Media settings

Open **Settings → Media** to save global media preferences. In **Settings → Libraries**, choose **Media settings** on a library to override individual fields. New and existing libraries inherit every global setting until an override is saved.

Global preferences and library overrides are stored as individual rows in the `settings` table, with keys such as `media.video_codec`, `media.hdr_mode`, and `media.audio_languages`. Global rows have no owner; library overrides use the polymorphic `settingable` relation (`settingable_type = library`, `settingable_id = library ID`). Access them through `$library->settings()` and their owner through `$setting->settingable`. Each row contains one setting value; list preferences retain their arrays. Inherited fields have no library row, and resetting an override deletes its row. Deleting a library also removes its settings.

The **H.265 — High quality** and **AV1 — High quality** presets set the video codec, high quality, and balanced encoding speed. **Apply preset** changes the form; **Save media settings** persists it. Presets leave HDR, audio, subtitles, and safeguards unchanged. On a library page, applying a preset overrides exactly those three video fields. You can edit them afterward.

| Preference | Default | Available choices |
| --- | --- | --- |
| Video codec | H.265 | H.265, AV1 |
| Video quality | High | High, balanced, smaller files |
| Encoding speed | Balanced | Fast, balanced, slow |
| HDR handling | Preserve | Preserve, convert to SDR, skip HDR files |
| Audio codec / channels | Copy original / preserve | Copy, AAC, Opus / preserve, stereo |
| Converted audio bitrate | 64 kbps per output channel | 32–256 kbps per channel |
| Lossless audio | Preserve | Preserve or allow conversion |
| Audio / subtitle languages | All | Optional three-letter language tags |
| Subtitles / forced subtitles | Retain / preserve | Enable or disable independently |
| Skip files using these video codecs | H.265, AV1 | Any selection of H.264, H.265, AV1 |
| Smaller output / minimum savings | Required / 5% | Enable or disable / 0–99% |
| Video quality verification / minimum VMAF | Enabled / 95 | Enable or disable / 0–100 |

HDR means high dynamic range; SDR means standard dynamic range. VMAF (Video Multi-Method Assessment Fusion) measures perceptual similarity, not mathematical losslessness. Quality and speed map to native encoder options; their numeric quality values are not equivalent across encoder families.

Changes to global settings apply to fields a library inherits. An explicit override stays independent even if its value equals the global value. Disable **Override global setting** for one field, or choose **Use global settings for all**, then save to restore inheritance. Empty language lists explicitly mean all languages; empty skip-codec lists mean no codec-based skipping. Language tags are normalized to lowercase and deduplicated; examples include `eng`, `ita`, and `und` for unknown languages.

The preferences define these processing policies:

- Resolution and frame rate remain unchanged. HDR preservation must skip unsupported files instead of silently stripping HDR.
- Copied audio retains its codec, bitrate, and channels. Preserving lossless audio takes precedence over conversion and channel settings for those tracks. A language filter that would remove every existing audio track must skip the file.
- Forced subtitles are kept regardless of the language filter when subtitle retention and forced-subtitle preservation are both enabled. Disabling subtitle retention removes all subtitles.
- Skip-codec selections skip the whole file. To convert existing H.265 files to AV1, remove H.265 from the skip list; applying the AV1 preset does not change that list.
- Minimum savings applies only when smaller output is required; 0% still requires strictly smaller output. Minimum VMAF applies only when quality verification is enabled. Values remain saved while their related option is disabled.

Saving settings does not start a run. Each file resolves the latest global preferences and library overrides when its attempt starts; changes affect subsequent files without restarting workers. The effective values, including future quality-control thresholds, are saved as individual `media.*` settings owned by `media_transcode`. Hardware acceleration and worker limits remain global, separate settings.

### Queue workers

Open **Settings → Workers** to set independent maximums for standard workers and transcode workers. Scans, probes, and run enumeration share the standard pool, with scans taking priority. Encodes use the dedicated `transcodes` queue and `redis-transcodes` connection. Each pool defaults to one worker; standard workers never consume encodes.

Saving applies the limit automatically while Horizon runs; no Horizon or container restart is needed. Worker counts adjust gradually. When lowering the limit, retiring workers finish their current jobs within the existing timeouts before exiting. Saving a limit does not resume paused workers.

The page shows the saved maximum, the limit reported by Horizon, and the current worker count. Workers finishing their last job are excluded from that count. If Horizon is offline, the saved limit applies when it starts; if status cannot be read, the page shows that it is unavailable rather than claiming the limit was applied.

Saved limits persist in `/config`. The standard limit keeps the `queue_max_workers` setting and defaults to `HORIZON_WORKERS`; the transcode limit uses `transcode_max_workers` and defaults to `HORIZON_TRANSCODE_WORKERS`. Both environment defaults are `1`.

### Transcoding

Choose **Transcode library** in **Settings → Libraries**. Only present files with successful probe results matching their current size and modification time are admitted. The run counts missing or stale metadata separately; scan and probe those files before requesting another run. Sources with an unchanged retained output are excluded. Each run has a fixed inventory cutoff and resumes enumeration in chunks of 200. Repeated requests return the library's existing active run.

The **Queue** page lists runs and individual files, encoder, progress, elapsed time, exclusions, skipped files, and errors. Unknown duration is shown without a percentage. **Cancel run** stops admission and waiting work, then terminates running encodes; the library remains busy until their processes stop and incomplete output is removed. **Retry failed** creates a new run containing only failed files that still have current metadata, using current settings when each starts.

Supported video is encoded with H.265 or AV1 while preserving resolution, frame timing, bit depth and chroma. Unsupported stream combinations, interlacing, and unsupported formats are skipped. HDR10/HLG preservation uses software when necessary for metadata support; AV1 mastering metadata requires FFmpeg 7.1 or later. Before preserving HDR, a bounded, interruptible frame inspection checks the entire source for dynamic or changing HDR metadata and collects static mastering data. This preparation shares the execution budget and may take time. Dynamic HDR preservation is skipped. HDR-to-SDR conversion uses zscale and Hable tone mapping with BT.709 output tags. Supported subtitles, chapters, metadata and attachments are retained according to the media settings. Unsupported hardware falls back to software for the same codec before encoding; an encoding failure never silently starts a second full encode.

Completed files are labelled **Awaiting quality control**. They are staged as `<transcode-id>/<attempt-token>/output.mkv` on the local `transcodes` disk, under `storage/app/private/transcodes` by default (`/config/storage/app/private/transcodes` in the container). Incomplete attempts use `output.partial.mkv`. Originals are never overwritten, renamed or removed. Savings checks, VMAF evaluation and replacement do not run yet. Completed records are the durable handoff; `TranscodeStaged` is an after-commit notification for future consumers.

| Environment variable | Default | Purpose |
| --- | --- | --- |
| `TRANSCODE_TIMEOUT_SECONDS` | `604800` (seven days) | Execution budget, including preparation, saved per admitted file |
| `TRANSCODE_STAGING_DISK` | `transcodes` | Local staging disk configured in `config/filesystems.php` |
| `TRANSCODE_STAGING_PATH` | `storage/app/private/transcodes` | Root of the default staging disk; must be outside every library folder |
| `HORIZON_TRANSCODE_WORKERS` | `1` | Transcode worker default before saving a limit in Settings |

For execution budget `T`, the external watchdog uses the remaining budget with up to ten seconds to terminate; the job timeout is `T + 60`, overlap and Horizon timeouts are `T + 120`, Redis reservation is `T + 180`, and dispatch uniqueness is `T + 300`. A queued file retains its saved budget across recovery. Drain existing work before lowering the environment timeout, so queue reservations and supervisor limits still cover older payloads.

Laravel's native unique-job and overlap locks use the default application cache (Redis in the container). Publication intent and request/attempt tokens are also stored in the database, so duplicate or stale deliveries cannot accept an output. A confirmed queued job is never redispatched merely because it has waited a long time.

The scheduler reconciles interrupted enumeration and publication every minute. Running work is reclaimed only after its recorded deadline plus 120 seconds and after acquiring overlap protection; a stale heartbeat alone is insufficient. A hard-killed worker can therefore wait until its deadline for live recovery. Container startup runs `tasks:recover` exclusively before workers start, clears stale transcode locks and rotates outstanding tokens. Run that command manually only after all workers **and FFmpeg processes** have stopped. Completed staging files remain available.

Inspect reconciliation without changing anything, or apply live reconciliation explicitly:

```bash
docker compose exec --user rencoderr rencoderr php artisan transcodes:reconcile --json
docker compose exec --user rencoderr rencoderr php artisan transcodes:reconcile --apply --plain
```

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

Apply the changes with `docker compose up -d`. Open **Settings → Status** to inspect detected encoders, test them, and choose your hardware acceleration preference. Availability depends on the host GPU, drivers, and FFmpeg support. Detection results are kept until you select **Refresh detection** or restart the container.

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

### Manual transcode acceptance checks

After installing the change, apply its migrations with `lerd php artisan migrate` in a Lerd development checkout. Container startup applies migrations automatically. These checks are performed manually; automated tests use controlled media and process fixtures.

Create two small H.264 inputs inside the example Compose media mount:

```bash
docker compose exec --user rencoderr rencoderr mkdir -p /media/transcode-check-cpu /media/transcode-check-gpu
docker compose exec --user rencoderr rencoderr ffmpeg -nostdin -n -f lavfi -i testsrc2=size=1280x720:rate=24 -f lavfi -i sine=frequency=440:sample_rate=48000 -t 5 -c:v libx264 -pix_fmt yuv420p -c:a aac -metadata:s:a:0 language=eng /media/transcode-check-cpu/sample.mkv
docker compose exec --user rencoderr rencoderr cp -n /media/transcode-check-cpu/sample.mkv /media/transcode-check-gpu/sample.mkv
docker compose exec --user rencoderr rencoderr sha256sum /media/transcode-check-cpu/sample.mkv /media/transcode-check-gpu/sample.mkv
```

1. Add the CPU and GPU folders as separate libraries, scan them and wait for successful probes. Set **Software** acceleration, then choose **Transcode library** on the CPU library. The Queue page must reach **Awaiting quality control** with `libx265` (or `libsvtav1` for AV1), a nonempty output and no error.
2. Refresh encoder detection and select an available hardware backend in **Settings → Status**. Run the GPU library. Expect the matching native encoder (`hevc_nvenc`, `hevc_qsv`, `hevc_vaapi`, or the AV1 equivalent). If that backend is unavailable, the Queue page must report the software encoder for the same codec. Inspect driver diagnostics when testing VAAPI speed settings.
3. Repeat the checksum command: both input checksums must be unchanged. Inspect the staged output shown in Queue, substituting its relative path below. The result must report the selected video codec, 1280×720, 24 fps, and the retained audio stream:

```bash
docker compose exec --user rencoderr rencoderr ffprobe -v error -show_streams -show_format -of json /config/storage/app/private/transcodes/TRANSCODE_ID/ATTEMPT_TOKEN/output.mkv
```

4. Request the same library again: unchanged retained output must be counted, with no second encoding. With a longer test-only input, cancel during progress: the run must remain cancelling until the encoder stops, then become cancelled with no partial output. Induce an encoder failure on a test input and choose **Retry failed**: a new linked run must contain only failed files. Change media settings while files wait: only later attempts should use the new values.
5. Change the standard and transcode worker limits independently and refresh **Settings → Workers**. Each saved limit must apply to its own pool. Sign out and visit Queue: authentication must be required when enabled. Check the browser console for errors.

Any missing output, changed source checksum, silently changed bit depth/chroma, unexpected encoder, or premature completion after cancellation is a failed check. Representative HDR, multichannel audio and subtitle samples should also be inspected on the actual installed CPU/GPU before using the workflow with a real library.

To exercise native uniqueness and overlap against Redis in a development checkout with Lerd services already running:

```bash
TRANSCODE_LOCK_STORE=redis lerd php artisan test --compact tests/Feature/Transcoding/NativeTranscodeLocksTest.php
```

Expect two passing tests. The test uses a unique cache prefix and removes only its own lock keys. The normal suite exercises the same native lock and middleware code against its configured test cache.
