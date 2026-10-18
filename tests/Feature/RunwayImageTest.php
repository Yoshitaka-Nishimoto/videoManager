<?php

namespace Tests\Feature;

use App\Models\AiUsageLog;
use App\Models\ProductionAsset;
use App\Models\RunwayTask;
use App\Models\RunwayTaskInput;
use App\Models\VideoProduction;
use App\Services\Runway\RunwayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class RunwayImageTest extends TestCase
{
    use RefreshDatabase;

    private VideoProduction $production;

    private ProductionAsset $reference;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Sleep::fake();
        config(['services.runway.key' => 'test-key', 'services.runway.base_url' => 'https://api.dev.runwayml.com/v1']);

        $this->production = VideoProduction::factory()->create(['ratio' => '1280:720']);
        $path = ProductionAsset::directoryFor($this->production).'/person.jpg';
        Storage::disk('local')->put($path, $this->image('person.jpg', 320, 320));
        $this->reference = $this->production->assets()->create([
            'kind' => ProductionAsset::KIND_IMAGE,
            'title' => 'person',
            'storage_path' => $path,
            'mime_type' => 'image/jpeg',
        ]);
    }

    public function test_an_image_is_generated_from_the_reference_and_registered_as_an_asset(): void
    {
        Http::fake([
            '*/v1/text_to_image' => Http::response(['id' => 'task-1']),
            '*/v1/tasks/task-1' => Http::sequence()
                ->push(['id' => 'task-1', 'status' => 'RUNNING', 'progress' => 0.4])
                ->push(['id' => 'task-1', 'status' => 'SUCCEEDED', 'output' => ['https://output.example/a.png'], 'cost' => ['credits' => 8]]),
            'https://output.example/*' => Http::response($this->image('a.png', 192, 108)),
        ]);

        // Windows の区切りで、storage/app/private から書いたパスでも受け付ける。
        $this->artisan('runway:image', [
            'image' => 'storage\\app\\private\\'.str_replace('/', '\\', $this->reference->storage_path),
            '--shot' => '全身',
            '--background' => '公園',
            '--style' => 'manga',
        ])
            ->expectsOutputToContain('Runway に依頼しました（タスクID：task-1）')
            ->expectsOutputToContain('素材に登録しました')
            ->assertSuccessful();

        Http::assertSent(function (Request $request) {
            if (! str_ends_with($request->url(), '/text_to_image')) {
                return false;
            }

            return $request->hasHeader('Authorization', 'Bearer test-key')
                && $request->hasHeader('X-Runway-Version', '2024-11-06')
                && $request['model'] === 'gen4_image'
                && $request['ratio'] === '1920:1080'
                && $request['referenceImages'][0]['tag'] === 'person'
                && str_starts_with($request['referenceImages'][0]['uri'], 'data:image/jpeg;base64,')
                && str_contains($request['promptText'], 'full-body shot')
                && str_contains($request['promptText'], 'city park')
                && str_contains($request['promptText'], 'manga');
        });

        $task = RunwayTask::query()->sole();
        $this->assertSame([RunwayTask::STATUS_COMPLETED, 'SUCCEEDED', 100, 'task-1'], [$task->status, $task->runway_status, $task->progress, $task->runway_task_id]);
        $this->assertSame(['shot' => 'full', 'background' => 'park', 'style' => 'manga', 'video_production_id' => $this->production->id], $task->options);
        $this->assertStringNotContainsString('base64', json_encode($task->request_payload));

        $input = $task->inputs()->sole();
        $this->assertSame([RunwayTaskInput::ROLE_REFERENCE_IMAGE, $this->reference->id], [$input->role, $input->production_asset_id]);

        $output = $task->outputs()->sole();
        $this->assertSame(["runway/{$task->id}/0.png", 'image/png', 192, 108], [$output->storage_path, $output->mime_type, $output->width, $output->height]);
        Storage::disk('local')->assertExists($output->storage_path);

        $asset = $this->production->assets()->whereKeyNot($this->reference->id)->sole();
        $this->assertSame([ProductionAsset::KIND_IMAGE, 'Runway：全身・公園・漫画', 'image/png'], [$asset->kind, $asset->title, $asset->mime_type]);
        $this->assertStringStartsWith("productions/{$this->production->id}/assets/", $asset->storage_path);
        Storage::disk('local')->assertExists($asset->storage_path);

        $log = AiUsageLog::query()->sole();
        $this->assertSame(['runway', 'gen4_image', AiUsageLog::FEATURE_GENERATION, true, $task->id], [$log->provider, $log->model, $log->feature, $log->succeeded, $log->usable_id]);
        $this->assertEquals(0.08, (float) $log->estimated_cost);
    }

    public function test_dry_run_only_shows_the_prompt(): void
    {
        Http::fake();

        $this->artisan('runway:image', ['image' => $this->reference->storage_path, '--background' => 'blue', '--dry-run' => true])
            ->expectsOutputToContain('chroma-key blue background')
            ->expectsOutputToContain('--dry-run のため')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, RunwayTask::query()->count());
    }

    public function test_a_failed_generation_is_recorded_without_adding_an_asset(): void
    {
        Http::fake([
            '*/v1/text_to_image' => Http::response(['id' => 'task-2']),
            '*/v1/tasks/task-2' => Http::response(['id' => 'task-2', 'status' => 'FAILED', 'failure' => 'The image was flagged.', 'failureCode' => 'SAFETY.INPUT.IMAGE', 'cost' => ['credits' => 8]]),
        ]);

        $this->artisan('runway:image', ['image' => $this->reference->storage_path])
            ->expectsOutputToContain('The image was flagged.')
            ->assertFailed();

        $task = RunwayTask::query()->sole();
        $this->assertSame([RunwayTask::STATUS_FAILED, 'SAFETY.INPUT.IMAGE'], [$task->status, $task->failure_code]);
        $this->assertFalse($task->isRetryable());
        $this->assertSame(1, $this->production->assets()->count());
        $this->assertFalse(AiUsageLog::query()->sole()->succeeded);
    }

    public function test_an_api_error_is_shown(): void
    {
        Http::fake(['*/v1/text_to_image' => Http::response(['error' => 'You do not have enough credits to run this task.'], 400)]);

        $this->artisan('runway:image', ['image' => $this->reference->storage_path])
            ->expectsOutputToContain('enough credits')
            ->assertFailed();

        $task = RunwayTask::query()->sole();
        $this->assertSame([RunwayTask::STATUS_FAILED, null], [$task->status, $task->runway_task_id]);
        $this->assertEquals(0, (float) AiUsageLog::query()->sole()->estimated_cost);
    }

    public function test_invalid_options_and_missing_files_are_rejected(): void
    {
        Http::fake();

        $this->artisan('runway:image', ['image' => $this->reference->storage_path, '--shot' => 'sideways'])
            ->expectsOutputToContain('写り方「sideways」は使えません')
            ->assertFailed();

        $this->artisan('runway:image', ['image' => 'productions/999/assets/none.jpg'])
            ->expectsOutputToContain('画像が見つかりません')
            ->assertFailed();

        $this->artisan('runway:image', ['image' => $this->reference->storage_path, '--ratio' => '4:3'])
            ->expectsOutputToContain('比率「4:3」は使えません')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_the_api_key_is_required(): void
    {
        config(['services.runway.key' => null]);
        $this->app->forgetInstance(RunwayClient::class);
        Http::fake();

        $this->artisan('runway:image', ['image' => $this->reference->storage_path])
            ->expectsOutputToContain('RUNWAY_API_KEY が設定されていません')
            ->assertFailed();

        Http::assertNothingSent();
    }

    private function image(string $name, int $width, int $height): string
    {
        // 偽のファイルは変数が消えると一時ファイルごと消えるため、読み終えるまで持っておく。
        $file = UploadedFile::fake()->image($name, $width, $height);

        return (string) file_get_contents($file->getRealPath());
    }
}
