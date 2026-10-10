<?php

namespace App\Jobs;

use App\Actions\Productions\CancelProductionRender;
use App\Models\ProductionRender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
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

    /** 書き出し中に、取り消されていないかを確かめる間隔（秒）。 */
    private const CANCEL_CHECK_SECONDS = 3;

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

        // 長い書き出しで分析などのジョブを待たせないよう、専用の接続（キュー）で動かす。
        $this->onConnection('remotion');
    }

    public function handle(): void
    {
        $render = $this->render->refresh();

        // 取り消された書き出しは何もしない。前回の実行が途中で止まったまま（WSL が落ちたなど）のものは、
        // もう一度動かすと同じことが起きるおそれがあるため、失敗として記録して終わる。
        if ($render->status === ProductionRender::STATUS_RUNNING) {
            $render->update([
                'status' => ProductionRender::STATUS_FAILED,
                'stage' => null,
                'error_message' => '前回の書き出しが途中で止まりました（WSL が落ちた可能性があります）。必要なら書き出し直してください。',
                'completed_at' => now(),
            ]);

            return;
        }

        if ($render->status !== ProductionRender::STATUS_QUEUED) {
            return;
        }

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
            'concurrency' => (int) config('remotion.concurrency'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $render->update([
            'status' => ProductionRender::STATUS_RUNNING,
            'stage' => ProductionRender::STAGE_BUNDLING,
            'progress' => 0,
            'started_at' => now(),
        ]);

        try {
            $process = Process::path(config('remotion.project_path'))
                ->timeout((int) config('remotion.timeout'))
                ->start(
                    [config('remotion.node_binary'), config('remotion.render_script'), $jobPath],
                    fn (string $type, string $output) => $this->read($type, $output),
                );

            // 出力を読みながら、取り消されていないかを数秒ごとに確かめる。取り消されたら書き出しを止める。
            $checkedAt = 0.0;
            while ($process->running()) {
                $process->ensureNotTimedOut();

                if (microtime(true) - $checkedAt >= self::CANCEL_CHECK_SECONDS) {
                    $checkedAt = microtime(true);

                    if ($this->wasCancelled()) {
                        $process->stop();

                        throw new RuntimeException(CancelProductionRender::MESSAGE);
                    }
                }

                Sleep::for(500)->milliseconds();
            }

            $result = $process->wait();

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
        // 取り消し済み・記録済みなら、その内容を残す。
        if (! $this->render->refresh()->isInProgress()) {
            return;
        }

        $this->render->update([
            'status' => ProductionRender::STATUS_FAILED,
            'stage' => null,
            'error_message' => Str::limit($exception?->getMessage() ?? '書き出しに失敗しました。', 2000),
            'completed_at' => now(),
        ]);
    }

    /**
     * 画面から取り消されたか（実行中ではなくなったか）。
     */
    private function wasCancelled(): bool
    {
        return ProductionRender::query()->whereKey($this->render->id)->value('status') !== ProductionRender::STATUS_RUNNING;
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
