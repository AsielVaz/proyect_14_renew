<?php

declare(strict_types=1);

namespace App;

use JsonException;

final class DeepSeekChatClient
{
    public function complete(array $messages, array $tools, float $deadline): array
    {
        $key = trim(\env_value('DEEPSEEK_API_KEY', ''));
        if ($key === '' || !function_exists('curl_init')) {
            throw new ChatException('El asistente no está disponible. Revisa la configuración de DeepSeek.', 503);
        }
        $remaining = (int) (($deadline - microtime(true)) * 1000);
        if ($remaining < 500) {
            throw new ChatException('DeepSeek tardó demasiado. Puedes reintentar la consulta.', 504);
        }

        $payload = [
            'model' => \env_value('DEEPSEEK_MODEL', 'deepseek-chat'),
            'messages' => $messages,
            'thinking' => ['type' => 'disabled'],
            'stream' => false,
            'max_tokens' => 1200,
        ];
        if ($tools !== []) {
            $payload['tools'] = $tools;
        }

        $curl = curl_init('https://api.deepseek.com/chat/completions');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ChatRepository::json($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => min($remaining, 5000),
            CURLOPT_TIMEOUT_MS => $remaining,
        ]);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $transportError = curl_errno($curl);
        if (!is_string($raw) || $status < 200 || $status >= 300) {
            error_log("Chat DeepSeek: HTTP {$status}, cURL {$transportError}");
            if ($transportError === CURLE_OPERATION_TIMEDOUT) {
                throw new ChatException('DeepSeek tardó demasiado. Puedes reintentar la consulta.', 504);
            }
            throw new ChatException('DeepSeek no está disponible en este momento. Puedes reintentar la consulta.', 502);
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = null;
        }
        $message = $decoded['choices'][0]['message'] ?? null;
        if (!is_array($message)) {
            throw new ChatException('DeepSeek devolvió una respuesta incompleta. Puedes reintentar la consulta.', 502);
        }
        return $message;
    }
}

