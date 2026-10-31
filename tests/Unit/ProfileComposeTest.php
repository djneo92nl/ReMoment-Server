<?php

namespace Tests\Unit;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/** The compose files keep each profile's containers apart (docs/architecture/profiles.md). `docker compose config` per profile was run by hand. */
class ProfileComposeTest extends TestCase
{
    private function services(string $file): array
    {
        return Yaml::parseFile(base_path($file))['services'];
    }

    public function test_production_compose_starts_only_the_control_stack_without_profiles(): void
    {
        $services = $this->services('docker-compose.prod.yml');
        $always = array_keys(array_filter($services, fn ($s) => empty($s['profiles'])));

        $this->assertEqualsCanonicalizing(['app', 'redis', 'mosquitto'], $always);
    }

    public function test_mariadb_is_for_standard_and_full_and_not_required_by_the_app(): void
    {
        $services = $this->services('docker-compose.prod.yml');

        $this->assertSame(['db'], $services['mariadb']['profiles']);
        $this->assertFalse($services['app']['depends_on']['mariadb']['required']);
        $this->assertArrayNotHasKey('meilisearch', $services['app']['depends_on']);
    }

    public function test_meilisearch_is_an_optional_compose_profile_in_both_files(): void
    {
        $this->assertSame(['search'], $this->services('docker-compose.prod.yml')['meilisearch']['profiles']);
        $this->assertSame(['search'], $this->services('compose.yaml')['meilisearch']['profiles']);
    }

    public function test_the_app_receives_the_profile_and_control_gets_a_named_sqlite_volume(): void
    {
        $prod = Yaml::parseFile(base_path('docker-compose.prod.yml'));

        $this->assertSame('${PROFILE:-standard}', $prod['services']['app']['environment']['PROFILE']);
        $this->assertContains('remoment-sqlite:/data/sqlite', $prod['services']['app']['volumes']);
        $this->assertArrayHasKey('remoment-sqlite', $prod['volumes']);
    }

    public function test_the_sidecars_and_the_profile_config_agree_on_compose_profile_names(): void
    {
        $services = $this->services('docker-compose.prod.yml');
        $known = collect($services)->pluck('profiles')->flatten()->filter()->unique()->all();

        foreach (config('profile.compose_profiles') as $profile => $names) {
            foreach ($names as $name) {
                $this->assertContains($name, $known, "{$profile}: compose profile {$name} does not exist");
            }
        }
        $this->assertSame([], config('profile.compose_profiles.control'));
    }
}
