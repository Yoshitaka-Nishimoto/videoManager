<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Exceptions\AiException;
use Throwable;

/**
 * 失敗の理由として画面に出す文。AI の呼び出しの失敗なら、HTTP の状態コードとその意味を後ろに付ける。
 * 例：「AI provider [gemini] is overloaded.（503：混雑中）」
 */
final class AiErrorMessage
{
    private const MEANINGS = [
        400 => 'リクエストの誤り',
        401 => '認証の失敗',
        402 => 'クレジット不足',
        403 => '権限なし',
        404 => 'モデルが見つからない',
        429 => '回数制限',
        500 => 'AI 側のエラー',
        502 => 'AI 側のエラー',
        503 => '混雑中',
        504 => '応答の時間切れ',
        529 => '混雑中',
    ];

    public static function for(Throwable $exception): string
    {
        $message = $exception->getMessage();
        $status = self::status($exception);

        if ($status === null) {
            return $message;
        }

        $meaning = self::MEANINGS[$status] ?? null;

        return $message.($meaning !== null ? "（{$status}：{$meaning}）" : "（{$status}）");
    }

    /**
     * AI の応答の HTTP 状態コード。AI の呼び出しの失敗でなければ null。
     */
    private static function status(Throwable $exception): ?int
    {
        for ($e = $exception; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof RequestException) {
                return $e->response->status();
            }
        }

        // SDK の例外は、元の HTTP の状態コードを code に持つ。
        if ($exception instanceof AiException && $exception->getCode() >= 400 && $exception->getCode() < 600) {
            return $exception->getCode();
        }

        return null;
    }
}
