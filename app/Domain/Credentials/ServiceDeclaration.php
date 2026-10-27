<?php

namespace App\Domain\Credentials;

use Closure;

/** Everything the app knows about one external service's credentials (see docs/architecture/credentials.md). */
final readonly class ServiceDeclaration
{
    /**
     * @param  list<CredentialField>  $fields
     * @param  list<string>  $probes  ids of the `ExternalServices` probes that test this service
     * @param  list<string>  $steps  plain setup steps
     * @param  array{label: string, url: string}  $link  where to create the key
     * @param  string  $baseUrl  default API base URL; the setting `{id}_base_url` (or `$baseUrlConfig`) overrides it
     * @param  Closure(array<string, bool>): void|null  $onChanged  called with the names of the fields that changed
     * @param  array{label: string, route: string, connected: Closure(): bool}|null  $account  the user-specific login (OAuth) that stays outside the registry
     * @param  string|null  $callbackRoute  route name of the redirect/callback URL the user registers with the service
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $icon,
        public string $purpose,
        public array $fields,
        public string $baseUrl,
        public array $probes,
        public array $link,
        public array $steps,
        public ?string $terms = null,
        public ?string $baseUrlConfig = null,
        public ?Closure $onChanged = null,
        public ?array $account = null,
        public ?string $callbackRoute = null,
    ) {}

    public function field(string $name): CredentialField
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        throw new \InvalidArgumentException("Service {$this->id} has no field {$name}");
    }

    public function baseUrlSetting(): string
    {
        return "{$this->id}_base_url";
    }

    public function sourceSetting(): string
    {
        return "{$this->id}_source";
    }
}
