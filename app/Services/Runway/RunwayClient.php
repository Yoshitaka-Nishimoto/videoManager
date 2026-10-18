<?php

namespace App\Services\Runway;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Runway API を呼び出す。生成は非同期で、タスクを作ってから GET /tasks/{id} で状態を確かめる。
 *
 * 仕様は public_docs/runway_tables.md の「設計の前提」を参照。
 */
class RunwayClient
{
    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $baseUrl,
        private readonly string $version,
    ) {}

    /**
     * 画像の生成を依頼し、Runway のタスクID を返す。
     *
     * @param  array<string, mixed>  $payload
     */
    public function textToImage(array $payload): string
    {
        $id = $this->send(fn (PendingRequest $http) => $http->post('/text_to_image', $payload))->json('id');

        if (! is_string($id) || $id === '') {
            throw new RunwayException('Runway からタスクID が返りませんでした。');
        }

        return $id;
    }

    /**
     * タスクの状態（status、progress、output、failure、failureCode、cost など）。
     *
     * @return array<string, mixed>
     */
    public function task(string $id): array
    {
        return (array) $this->send(fn (PendingRequest $http) => $http->get('/tasks/'.rawurlencode($id)))->json();
    }

    /**
     * 生成物をダウンロードして中身を返す。出力の URL は 24〜48 時間で切れるため、完了したらすぐ呼ぶ。
     */
    public function download(string $url): string
    {
        try {
            $response = Http::timeout(120)->get($url);
        } catch (ConnectionException $e) {
            throw new RunwayException('Runway の生成物をダウンロードできませんでした。', previous: $e);
        }

        if ($response->failed()) {
            throw new RunwayException('Runway の生成物をダウンロードできませんでした（'.$response->status().'）。');
        }

        return $response->body();
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     */
    private function send(callable $request): Response
    {
        if (blank($this->apiKey)) {
            throw new RunwayException('RUNWAY_API_KEY が設定されていません。.env に設定してください。');
        }

        $http = Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->withHeaders(['X-Runway-Version' => $this->version])
            ->acceptJson()
            ->timeout(60);

        try {
            $response = $request($http);
        } catch (ConnectionException $e) {
            throw new RunwayException('Runway に接続できませんでした。時間をおいて再度お試しください。', previous: $e);
        }

        if ($response->failed()) {
            // 400 番台では {"error": "..."} で理由が返る（クレジット不足、指示文の不備など）。
            $reason = $response->json('error') ?? $response->body();

            throw new RunwayException('Runway API の呼び出しに失敗しました（'.$response->status().'）：'.(is_string($reason) ? $reason : json_encode($reason, JSON_UNESCAPED_UNICODE)));
        }

        return $response;
    }
}
