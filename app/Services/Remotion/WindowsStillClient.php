<?php

namespace App\Services\Remotion;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Windows で動かしている静止画のサーバー（remotion/scripts/still-server-windows.mjs）に入力を送り、画像を受け取る。
 * サーバーは remotion/src の Scene で描くため、動画と同じ部品・同じ見た目になる。
 * 制作の場面の静止画（RenderProduction）と、情報源の表紙（CreateVideoCover）が使う。
 */
class WindowsStillClient
{
    /**
     * @param  array{settings: array<string, mixed>, scene: array<string, mixed>}  $inputProps
     * @param  int|null  $frame  null なら場面の中ほど
     *
     * @throws RuntimeException サーバーにつながらない・失敗したとき
     */
    public function render(array $inputProps, string $imageFormat = 'png', float $scale = 1, ?int $frame = null): WindowsStill
    {
        $server = config('remotion.still_server');

        try {
            $response = Http::timeout((int) $server['timeout'])
                ->when($server['token'], fn ($request, string $token) => $request->withToken($token))
                ->post(rtrim($server['url'], '/').'/still', [
                    'inputProps' => $inputProps,
                    'imageFormat' => $imageFormat,
                    'scale' => $scale,
                    'frame' => $frame,
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Windows の静止画のサーバーにつながりません。Windows の PowerShell で remotion フォルダから node scripts\still-server-windows.mjs を起動してください。', previous: $e);
        }

        if ($response->failed()) {
            throw new RuntimeException('Windows の静止画のサーバーで失敗しました：'.($response->json('error') ?? "HTTP {$response->status()}"));
        }

        return new WindowsStill(
            image: $response->body(),
            renderMs: (int) $response->header('X-Render-Ms'),
            width: (int) $response->header('X-Width'),
            height: (int) $response->header('X-Height'),
            frame: $response->hasHeader('X-Frame') ? (int) $response->header('X-Frame') : $frame,
            remotionVersion: $response->header('X-Remotion-Version') ?: null,
            codeVersion: $response->header('X-Code-Version') ?: null,
        );
    }
}
