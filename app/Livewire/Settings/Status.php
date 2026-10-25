<?php

namespace App\Livewire\Settings;

use App\Encoding\AccelerationPreference;
use App\Encoding\Data\Detection;
use App\Encoding\EncoderDetector;
use App\Encoding\EncoderTester;
use App\Encoding\Enums\EncoderType;
use App\Ffmpeg\Enums\HardwareBackend;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use TallStackUi\Traits\Interactions;

/** @property-read Detection $detection */
#[Title('Status')]
class Status extends Component
{
    use Interactions;

    public string $preferredAcceleration = AccelerationPreference::AUTOMATIC;

    public string $preferredDevice = '';

    public function mount(AccelerationPreference $preference): void
    {
        $this->preferredAcceleration = $preference->saved();
        $this->preferredDevice = $preference->savedDevice() ?? '';
    }

    public function updatedPreferredAcceleration(): void
    {
        $this->preferredDevice = '';
        $this->resetValidation();
    }

    #[Computed]
    public function detection(): Detection
    {
        return app(EncoderDetector::class)->current();
    }

    /**
     * Hardware may have changed since the page loaded, so the choice is checked against a fresh detection.
     */
    public function saveHardwareAcceleration(AccelerationPreference $preference, EncoderDetector $detector): void
    {
        $backend = HardwareBackend::tryFrom($this->preferredAcceleration);
        $this->validate([
            'preferredAcceleration' => ['required', Rule::in([AccelerationPreference::AUTOMATIC, AccelerationPreference::SOFTWARE, ...array_column(HardwareBackend::cases(), 'value')])],
            'preferredDevice' => ['nullable', 'string', 'max:255', $backend?->devicePattern() !== null ? 'regex:'.$backend->devicePattern() : Rule::in([''])],
        ], [
            'preferredAcceleration.in' => 'Choose an available hardware encoding backend, Automatic, or Software (CPU).',
            'preferredAcceleration.required' => 'Choose a hardware acceleration preference.',
            'preferredDevice.regex' => 'Enter a GPU number (0, 1, …) for NVIDIA, or a render device such as /dev/dri/renderD129 for Intel Quick Sync or VAAPI.',
            'preferredDevice.in' => 'Device selection requires NVIDIA NVENC, Intel Quick Sync, or VAAPI.',
        ]);

        $device = $this->preferredDevice === '' ? null : $this->preferredDevice;
        $detection = $detector->inspect($this->preferredAcceleration, $device);
        if (! in_array($this->preferredAcceleration, $preference->choices($detection), true)) {
            throw ValidationException::withMessages($device !== null
                ? ['preferredDevice' => 'The selected device is unavailable for this backend. Check its identifier, drivers, and container access.']
                : ['preferredAcceleration' => 'Choose an available hardware encoding backend, Automatic, or Software (CPU).']);
        }

        $preference->save($this->preferredAcceleration, $device);
        $detector->store($detection);
        unset($this->detection);
        $this->toast()->success('Hardware acceleration preference saved.')->send();
    }

    public function refreshDetection(EncoderDetector $detector): void
    {
        $detector->refresh();
        unset($this->detection);
    }

    public function testEncoder(string $name, EncoderTester $tester): void
    {
        $encoder = $this->detection->encoder($name);
        abort_unless($encoder?->isTestable() === true, 404);

        $tester->test($encoder);
        unset($this->detection);
    }

    public function render(AccelerationPreference $preference): View
    {
        $detection = $this->detection;
        $selectableBackends = $preference->availableBackends($detection, includeUnavailable: true);
        $savedAcceleration = $preference->saved();

        return view('livewire.settings.status', [
            'detection' => $detection,
            'selectableBackends' => $selectableBackends,
            'deviceSelectable' => HardwareBackend::tryFrom($this->preferredAcceleration)?->devicePattern() !== null,
            'savedDevice' => $preference->savedDevice(),
            'savedAcceleration' => $savedAcceleration,
            'effectiveAcceleration' => $preference->resolve($detection),
            'savedAccelerationUnavailable' => ! in_array($savedAcceleration, $preference->choices($detection), true),
            'groups' => $detection->encodersByCategory(),
            'videoCount' => $detection->availableCount(EncoderType::VIDEO),
            'audioCount' => $detection->availableCount(EncoderType::AUDIO),
        ])->layoutData(['settingsPage' => true]);
    }
}
