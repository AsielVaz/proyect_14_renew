<?php

declare(strict_types=1);

namespace App;

final class ChatReviewWebhook
{
    public function send(int $conversationId): void
    {
        $url = trim(\env_value('S14_CHAT_REVIEW_WEBHOOK_URL', ''));
        if (!filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || !function_exists('curl_init')) {
            throw new ChatException('El envío a revisión no está configurado en este momento.', 503);
        }

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ChatRepository::json(['conversacion_id' => $conversationId]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Idempotency-Key: s14-chat-review-' . $conversationId,
            ],
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_WRITEFUNCTION => static fn ($handle, string $chunk): int => strlen($chunk),
        ]);
        $sent = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($sent === false || $status < 200 || $status >= 300) {
            error_log('Chat webhook de revisión: HTTP ' . $status . ', cURL ' . curl_errno($handle));
            throw new ChatException('No se pudo enviar la conversación a revisión. Intenta de nuevo.', 502);
        }
    }
}

