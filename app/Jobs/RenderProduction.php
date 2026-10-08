<?php

namespace App\Jobs;

use App\Models\ProductionRender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Remotion で書き出す。remotion/scripts/render.mjs を実行し、1 行ずつ出力される JSON を読んで
 * production_renders の進捗と結果を更新する（public_docs/remotion_tables.md）。
 */
class RenderProduction implements ShouldQueue
{
    use Queueable;

    /** 同じ入力なら同じ結果になるため、失敗したら原因（素材・コード）を直してから新しい行で書き出し直す。 */
    public int $tries = 1;

    public int $timeout;

    /** log 列に残す、Remotion の出力の末尾の長さ（文字数）。 */
    private const LOG_LIMIT = 8000;

    private string $buffer = '';

    private string $log = '';

    /** @var array<string, mixed>|null */
    private ?array $done = null;

    private ?string $error = null;

    public function __construct(public ProductionRender $render)
    {
        // 書き出しの打ち切りより少し長くし、ジョブより先にプロセスを止めて記録を残せるようにする。
        $this->timeout = (int) config('remotion.timeout') + 60;
    }

    public function handle(): void
    {
        $render = $this->render;
        $disk = Storage::disk(config('remotion.disk'));
        $storagePath = config('remotion.output_directory').'/'.$render->id.'-'.$render->kind.'.'.$this->extension();
        $disk->makeDirectory(dirname($storagePath));

        $jobPath = tempnam(sys_get_temp_dir(), 'remotion-job-');
        file_put_contents($jobPath, json_encode([
            'kind' => $render->kind,
            'compositionId' => $render->composition_id,
            'inputProps' => $render->input_props,
            'outputPath' => $disk->path($storagePath),
            'codec' => $render->codec,
            'imageFormat' => $render->image_format,
            'scale' => (float) $render->scale,
            'crf' => $render->crf,
            'frame' => $render->frame,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $render->update([
            'status' => ProductionRender::STATUS_RUNNING,
            'stage' => ProductionRender::STAGE_BUNDLING,
            'progress' => 0,
            'started_at' => now(),
        ]);

        try {
            $result = Process::path(config('remotion.project_path'))
                ->timeout((int) config('remotion.timeout'))
                ->start([config('remotion.node_binary'), config('remotion.render_script'), $jobPath])
                ->wait(fn (string $type, string $output) => $this->read($type, $output));

            $this->read('out', "\n"); // 改行で終わらなかった最後の行を処理する。

            if (! $result->successful() || $this->done === null) {
                throw new RuntimeException($this->error ?? "Remotion の書き出しが終了コード {$result->exitCode()} で終わりました。");
            }
        } catch (Throwable $e) {
            // failed() は別のインスタンスで呼ばれ、ここで集めた出力が見えないため、先にログを残す。
            $render->update(['log' => $this->log !== '' ? $this->log : null]);

            throw $e;
        } finally {
            @unlink($jobPath);
        }

        $this->complete($storagePath);
    }

    /**
     * ジョブが失敗した（書き出しの失敗、打ち切り、例外）ときに記録を残す。ログは handle() で保存済み。
     */
    public function failed(?Throwable $exception): void
    {
        $this->render->update([
            'status' => ProductionRender::STATUS_FAILED,
            'stage' => null,
            'error_message' => Str::limit($exception?->getMessage() ?? '書き出しに失敗しました。', 2000),
            'completed_at' => now(),
        ]);
    }

    /**
     * 出力を行に分けて処理する。出力は行の途中で区切られて届くことがある。
     */
    private function read(string $type, string $output): void
    {
        if ($type === 'err') {
            $this->appendLog($output);

            return;
        }

        $this->buffer .= $output;

        while (($newline = strpos($this->buffer, "\n")) !== false) {
            $line = trim(substr($this->buffer, 0, $newline));
            $this->buffer = substr($this->buffer, $newline + 1);

            if ($line !== '') {
                $this->handleLine($line);
            }
        }
    }

    private function handleLine(string $line): void
    {
        $message = json_decode($line, true);

        // render.mjs の JSON 以外の行（Chrome のダウンロードの表示、書体の警告など）はログに残すだけ。
        if (! is_array($message) || ! isset($message['type'])) {
            $this->appendLog($line."\n");

            return;
        }

        match ($message['type']) {
            'stage' => $this->render->update(['stage' => $message['stage']]),
            'progress' => $this->render->update([
                'stage' => $message['stage'],
                'progress' => min(99, (int) $message['progress']),
                'rendered_frames' => $message['renderedFrames'],
                'encoded_frames' => $message['encodedFrames'],
            ]),
            'done' => $this->done = $message,
            'error' => $this->recordError($message['message'] ?? ''),
            default => $this->appendLog($line."\n"),
        };
    }

    private function recordError(string $message): void
    {
        $this->appendLog($message."\n");
        // 例外の 1 行目だけをエラー内容にし、スタックトレースはログに残す。
        $this->error = Str::before($message, "\n") ?: '書き出しに失敗しました。';
    }

    private function appendLog(string $text): void
    {
        $this->log = Str::substr($this->log.$text, -self::LOG_LIMIT);
    }

    private function complete(string $storagePath): void
    {
        $done = $this->done;
        $render = $this->render;

        $render->update([
            'status' => ProductionRender::STATUS_COMPLETED,
            'stage' => null,
            'progress' => 100,
            'rendered_frames' => $render->kind === ProductionRender::KIND_STILL ? 1 : $done['durationInFrames'],
            'encoded_frames' => $render->kind === ProductionRender::KIND_STILL ? null : $done['durationInFrames'],
            'storage_path' => $storagePath,
            'mime_type' => $this->mimeType(),
            'size_bytes' => $done['sizeBytes'],
            'render_ms' => $done['renderMs'],
            'width' => $done['width'],
            'height' => $done['height'],
            'fps' => $done['fps'],
            'duration_in_frames' => $done['durationInFrames'],
            'frame' => $done['frame'],
            'remotion_version' => $done['remotionVersion'],
            'log' => $this->log !== '' ? $this->log : null,
            'completed_at' => now(),
        ]);

        // 場面の静止画は、制作案の画面でモックとして表示できるよう場面にも保存先を入れる。
        if ($render->isStill() && $render->scene !== null) {
            $render->scene->update(['preview_path' => $storagePath]);
        }
    }

    private function extension(): string
    {
        if ($this->render->isStill()) {
            return $this->render->image_format === 'jpeg' ? 'jpg' : ($this->render->image_format ?? 'png');
        }

        return match ($this->render->codec) {
            'vp8', 'vp9' => 'webm',
            'prores' => 'mov',
            'gif' => 'gif',
            default => 'mp4',
        };
    }

    private function mimeType(): string
    {
        return match ($this->extension()) {
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'webp' => 'image/webp',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'gif' => 'image/gif',
            default => 'video/mp4',
        };
    }
}
