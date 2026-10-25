#!/usr/bin/env bash
set -Eeuo pipefail

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
model=${QUALITY_CONTROL_MODEL_PATH:-/opt/ffmpeg/share/vmaf/vmaf_v0.6.1.json}

for tool in gcc g++ cc make autoconf pkg-config phpize; do
    if command -v "$tool" >/dev/null 2>&1; then
        echo "Build tool must not be present in the runtime: $tool" >&2
        exit 1
    fi
done

if [[ ! -r "$model" ]]; then
    echo "VMAF model is not readable: $model" >&2
    exit 1
fi

expected_version=$(sed -n 's/^ffmpeg=//p' /opt/ffmpeg/share/build-versions)
test -n "$expected_version"
for binary in ffmpeg ffprobe; do
    test "$(command -v "$binary")" = "/opt/ffmpeg/bin/$binary"
    "$binary" -version > "$work/$binary-version"
    grep -Fq "$binary version $expected_version " "$work/$binary-version"
    ldd "/opt/ffmpeg/bin/$binary" > "$work/$binary-libraries"
    if grep -q 'not found' "$work/$binary-libraries"; then
        cat "$work/$binary-libraries" >&2
        exit 1
    fi
done
sha256sum --check --status /opt/ffmpeg/share/vmaf/SHA256SUMS

ffmpeg -hide_banner -filters > "$work/filters"
for filter in libvmaf zscale tonemap; do
    grep -Eq "^[[:space:]]*[.A-Z]+[[:space:]]+$filter[[:space:]]" "$work/filters"
done
ffmpeg -hide_banner -encoders > "$work/encoders"
for encoder in libx264 libx265 libsvtav1 aac libopus h264_vaapi hevc_vaapi av1_vaapi h264_qsv hevc_qsv av1_qsv h264_nvenc hevc_nvenc av1_nvenc; do
    grep -Eq "^[[:space:]]*[.A-Z]+[[:space:]]+$encoder[[:space:]]" "$work/encoders"
done

for encoder in libx264 libx265 libsvtav1; do
    case "$encoder" in
        libx264) codec=h264; pixel=yuv420p; audio=aac; options=(-preset ultrafast) ;;
        libx265) codec=hevc; pixel=yuv420p10le; audio=aac; options=(-preset ultrafast -x265-params pools=2:frame-threads=1:log-level=error) ;;
        libsvtav1) codec=av1; pixel=yuv420p10le; audio=opus; options=(-preset 12 -svtav1-params lp=2) ;;
    esac
    audio_encoder=$audio
    if [[ "$audio" == opus ]]; then audio_encoder=libopus; fi
    ffmpeg -v error -nostdin -filter_threads 1 \
        -f lavfi -i "testsrc2=size=320x180:rate=24:duration=1,format=$pixel" \
        -f lavfi -i sine=frequency=440:sample_rate=48000:duration=1 \
        -c:v "$encoder" -threads:v 2 -pix_fmt "+$pixel" "${options[@]}" \
        -c:a "$audio_encoder" -ac 2 -b:a 128k -y "$work/$codec.mkv"
    ffprobe -v error -count_frames \
        -show_entries stream=codec_type,codec_name,pix_fmt,width,height,nb_read_frames,channels \
        -of json "$work/$codec.mkv" > "$work/streams.json"
    php -r '
        $streams = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR)["streams"];
        $video = $streams[0];
        $audio = $streams[1];
        if (count($streams) !== 2 || $video["codec_name"] !== $argv[2] || $video["pix_fmt"] !== $argv[3]
            || $video["width"] !== 320 || $video["height"] !== 180 || (int) $video["nb_read_frames"] !== 24
            || $audio["codec_name"] !== $argv[4] || $audio["channels"] !== 2) {
            fwrite(STDERR, "Software encode/decode did not preserve the expected streams.\n");
            exit(1);
        }
    ' "$work/streams.json" "$codec" "$pixel" "$audio"
    echo "Software encode/decode: $encoder passed"
done

# Both inputs use ten-bit frames, as the quality-control comparison does.
ffmpeg -v error -nostdin -filter_complex_threads 1 \
    -f lavfi -i testsrc2=size=320x180:rate=24:duration=2 \
    -filter_complex "[0:v]format=yuv420p10le,split=2[reference][distorted];[distorted][reference]libvmaf=model=path=$model:n_threads=2:n_subsample=1:log_fmt=xml:log_path=$work/vmaf.xml" \
    -an -f null -
php -r '
    libxml_use_internal_errors(true);
    $reader = XMLReader::open($argv[1], null, LIBXML_NONET);
    $frames = 0;
    $total = 0.0;
    $closed = false;
    while ($reader->read()) {
        if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === "VMAF") {
            $closed = true;
        }
        if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== "frame") {
            continue;
        }
        $score = $reader->getAttribute("vmaf");
        if ($reader->getAttribute("frameNum") !== (string) $frames || ! is_numeric($score)
            || ! is_finite((float) $score) || (float) $score < 0 || (float) $score > 100) {
            fwrite(STDERR, "VMAF returned an invalid frame or self-comparison score.\n");
            exit(1);
        }
        $total += (float) $score;
        $frames++;
    }
    $reader->close();
    if (! $closed || $frames !== 48 || libxml_get_errors() !== [] || $total / $frames < 99) {
        fwrite(STDERR, "VMAF did not return a complete 48-frame XML result with a mean score of at least 99.\n");
        exit(1);
    }
    echo "VMAF: 48 frames, mean score ".number_format($total / $frames, 6)."\n";
' "$work/vmaf.xml"

echo "FFmpeg runtime checks passed ($expected_version)"
