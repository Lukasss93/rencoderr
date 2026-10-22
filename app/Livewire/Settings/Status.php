<?php

namespace App\Livewire\Settings;

use App\Encoding\FfmpegHealth;
use App\Settings\ApplicationSettings;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * @phpstan-import-type Detection from FfmpegHealth
 * @phpstan-import-type EncoderCheck from FfmpegHealth
 */
#[Title('Status')]
class Status extends Component
{
    /** @var Detection */
    #[Locked]
    public array $report = ['version' => null, 'error' => null, 'encoders' => [], 'acceleration' => ['backends' => [], 'error' => null]];

    /** @var array<string, EncoderCheck> */
    #[Locked]
    public array $checks = [];

    #[Locked]
    public string $checkedAt = '';

    public string $preferredAcceleration = 'auto';

    public string $notice = '';

    public function mount(FfmpegHealth $health, ApplicationSettings $settings): void
    {
        $this->preferredAcceleration = $settings->hardwareAcceleration();
        $this->refreshDetection($health);
    }

    public function saveHardwareAcceleration(ApplicationSettings $settings, FfmpegHealth $health): void
    {
        $this->notice = '';
        $this->validate(['preferredAcceleration' => ['required', 'string', 'max:50']]);
        $this->refreshDetection($health);
        $settings->saveHardwareAcceleration($this->preferredAcceleration, $this->report);
        $this->notice = 'Hardware acceleration preference saved.';
    }

    public function refreshDetection(FfmpegHealth $health): void
    {
        $this->report = $health->detect();
        $this->checks = [];
        $this->checkedAt = now()->utc()->format('Y-m-d H:i:s').' UTC';
    }

    public function testEncoder(string $name, FfmpegHealth $health): void
    {
        $encoder = collect($this->report['encoders'])->firstWhere('name', $name);
        abort_unless($encoder !== null && $encoder['testable'], 404);

        $this->checks[$name] = $health->testEncoder($name);

        $backend = $encoder['backend'];

        if ($this->checks[$name]['successful'] && $backend !== null && isset($this->report['acceleration']['backends'][$backend])) {
            $this->report['acceleration']['backends'][$backend]['available'] = true;
            $this->report['acceleration']['backends'][$backend]['message'] = 'Encoded a generated frame with '.$name.'.';
        }
    }

    public function render(): View
    {
        $settings = app(ApplicationSettings::class);
        $availableBackends = $settings->availableHardwareBackends($this->report);
        $savedAcceleration = $settings->hardwareAcceleration();
        $groups = [
            'Video' => ['Hardware' => [], 'Software' => [], 'Supported' => []],
            'Audio' => ['Hardware' => [], 'Software' => [], 'Supported' => []],
        ];

        $counts = ['Video' => 0, 'Audio' => 0];

        foreach ($this->report['encoders'] as $encoder) {
            $backend = $encoder['backend'];
            $available = ! $encoder['hardware'] || ($backend !== null && ($this->report['acceleration']['backends'][$backend]['available'] ?? false));
            $available = $available && ($this->checks[$encoder['name']]['successful'] ?? true);
            $category = $encoder['hardware'] ? ($available ? 'Hardware' : 'Supported') : 'Software';
            $counts[$encoder['type']] += (int) $available;
            $groups[$encoder['type']][$category][$encoder['codec']][] = $encoder;
        }

        return view('livewire.settings.status', [
            'availableBackends' => $availableBackends,
            'savedAcceleration' => $savedAcceleration,
            'effectiveAcceleration' => $settings->resolveHardwareAcceleration($this->report),
            'savedAccelerationUnavailable' => ! in_array($savedAcceleration, ['auto', 'software'], true) && ! isset($availableBackends[$savedAcceleration]),
            'groups' => $groups,
            'videoCount' => $counts['Video'],
            'audioCount' => $counts['Audio'],
        ])->layoutData(['settingsPage' => true]);
    }
}
