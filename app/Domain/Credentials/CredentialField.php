<?php

namespace App\Domain\Credentials;

/** One credential of a service: where it is stored and where its `.env` fallback lives. */
final readonly class CredentialField
{
    /**
     * @param  string  $type  text | password | url
     * @param  string  $setting  key in the `settings` table
     * @param  string|null  $config  config() path of the `.env` fallback
     * @param  list<string>  $rules  Laravel validation rules applied on save when a value is given
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $setting,
        public ?string $config = null,
        public string $type = 'text',
        public bool $secret = false,
        public bool $required = false,
        public array $rules = [],
        public ?string $hint = null,
    ) {}
}
