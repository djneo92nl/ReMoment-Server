<?php

namespace Tests\Unit\Scenes;

use App\Domain\Scenes\SceneOperations;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/** `php artisan ha:package`: the generated Home Assistant rest_command package (shape only; not run in a real HA). */
class HaPackageTest extends TestCase
{
    private function package(array $options = []): array
    {
        Artisan::call('ha:package', ['--base-url' => 'http://remoment.test/'] + $options);

        return Yaml::parse(Artisan::output())['rest_command'];
    }

    public function test_it_writes_one_parseable_command_per_enabled_scene_operation(): void
    {
        $commands = $this->package();

        $this->assertCount(count((new SceneOperations)->all()), $commands);
        foreach ($commands as $name => $command) {
            $this->assertMatchesRegularExpression('/^remoment_[a-z0-9_]+$/', $name);
            $this->assertStringStartsWith('http://remoment.test/api/', $command['url']);
            $this->assertContains($command['method'], ['get', 'post', 'put', 'patch', 'delete']);
        }
    }

    public function test_move_playback_has_the_path_method_and_payload_of_the_contract(): void
    {
        $move = $this->package()['remoment_move_playback'];

        $this->assertSame('http://remoment.test/api/devices/{{ device }}/move', $move['url']);
        $this->assertSame('post', $move['method']);
        $this->assertSame('application/json', $move['content_type']);
        // Required first, the optional property only when the service data has it.
        $this->assertStringStartsWith("{% set p = {'to_device_id': to_device_id} %}", $move['payload']);
        $this->assertStringContainsString('{% if stop_source is defined %}', $move['payload']);
        $this->assertStringEndsWith('{{ p | tojson }}', $move['payload']);
    }

    public function test_commands_without_a_body_have_no_payload_and_path_parameters_are_templated(): void
    {
        $commands = $this->package();

        $this->assertSame('http://remoment.test/api/devices/{{ device }}/{{ action }}', $commands['remoment_transport']['url']);
        $this->assertArrayNotHasKey('payload', $commands['remoment_transport']);
        $this->assertSame('delete', $commands['remoment_multiroom_leave']['method']);
        $this->assertSame('http://remoment.test/api/devices/{{ device }}/radio/{{ station }}', $commands['remoment_play_radio']['url']);
        $this->assertStringContainsString("'key': key", $commands['remoment_press_remote_key']['payload']);
    }

    public function test_a_disabled_operation_is_left_out_and_no_admin_operation_is_in(): void
    {
        config(['scene-operations.operations.setPower.enabled' => false]);
        $commands = $this->package();

        $this->assertArrayNotHasKey('remoment_set_power', $commands);
        $this->assertArrayNotHasKey('remoment_admin_logout', $commands);
        $this->assertArrayNotHasKey('remoment_acknowledge_alert', $commands);
    }

    public function test_output_writes_a_file(): void
    {
        $file = sys_get_temp_dir().'/remoment-ha-'.uniqid().'.yaml';
        Artisan::call('ha:package', ['--base-url' => 'http://x.test', '--output' => $file]);

        $this->assertArrayHasKey('remoment_set_volume', Yaml::parseFile($file)['rest_command']);
        $this->assertStringContainsString('NOT verified', file_get_contents($file));
        unlink($file);
    }
}
