<?php

namespace App\Livewire;

use App\Domain\Credentials\ServiceCredentials;
use App\Domain\Health\ExternalServices;
use Livewire\Component;

/**
 * /settings/services: every external service's credentials, rendered from its declaration
 * (docs/architecture/credentials.md). Secrets are never loaded into public properties: only what the
 * admin types travels to the server, and an empty secret input means "keep".
 */
class ServiceCredentialsManager extends Component
{
    /** @var array<string, array<string, string>> service => field => typed value (non-secret fields prefilled) */
    public array $inputs = [];

    /** @var array<string, string> service => base URL override ('' = the default) */
    public array $baseUrls = [];

    public ?string $saved = null;

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);

        foreach (ServiceCredentials::all() as $id => $service) {
            foreach ($service->fields as $f) {
                $this->inputs[$id][$f->name] = $f->secret || ServiceCredentials::origin($id, $f->name) !== 'settings' ? '' : ServiceCredentials::value($id, $f->name);
            }
            $this->baseUrls[$id] = $this->overridden($id);
        }
    }

    /** Every request of this component is the admin's. */
    public function hydrate(): void
    {
        abort_unless(auth()->check(), 403);
    }

    public function save(string $id): void
    {
        $service = ServiceCredentials::find($id);
        $rules = [];

        foreach ($service->fields as $f) {
            $rule = $f->required && !ServiceCredentials::isSet($id, $f->name) ? ['required'] : ['nullable'];
            $rules["inputs.{$id}.{$f->name}"] = [...$rule, 'string', 'max:512', ...$f->rules];
        }
        $rules["baseUrls.{$id}"] = ['nullable', 'url:http,https', 'max:512'];

        $this->validate($rules);

        ServiceCredentials::save($id, $this->inputs[$id] + ['base_url' => $this->baseUrls[$id] ?? '']);

        foreach ($service->fields as $f) {
            if ($f->secret) {
                $this->inputs[$id][$f->name] = '';
            }
        }
        $this->saved = $id;
    }

    /** Runs the service's existing health probes (read-only) and shows their outcome. */
    public function test(string $id, ExternalServices $health): void
    {
        $this->saved = null;
        $health->refresh(ServiceCredentials::find($id)->probes);
    }

    public function render(ExternalServices $health)
    {
        $results = $health->all();
        $services = [];

        foreach (ServiceCredentials::all() as $id => $service) {
            $services[$id] = [
                'service' => $service,
                'configured' => ServiceCredentials::configured($id),
                'missing' => ServiceCredentials::missing($id),
                'fields' => collect($service->fields)->map(fn ($f) => [
                    'field' => $f,
                    'set' => ServiceCredentials::isSet($id, $f->name),
                    'origin' => ServiceCredentials::origin($id, $f->name),
                    'mask' => $f->secret ? ServiceCredentials::mask($id, $f->name) : null,
                ])->all(),
                'results' => collect($service->probes)->map(fn ($p) => $results[$p] ?? null)->filter()->all(),
                'callback' => $service->callbackRoute ? route($service->callbackRoute) : null,
                'connected' => $service->account ? ($service->account['connected'])() : null,
                'baseUrl' => ServiceCredentials::baseUrl($id),
            ];
        }

        return view('livewire.service-credentials-manager', ['services' => $services]);
    }

    private function overridden(string $id): string
    {
        $service = ServiceCredentials::find($id);

        return (string) \App\Models\Setting::get($service->baseUrlSetting(), '');
    }
}
