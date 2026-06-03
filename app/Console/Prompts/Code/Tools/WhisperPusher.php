<?php

declare(strict_types=1);

namespace App\Console\Prompts\Code\Tools;

use ArtisanBuild\Resonance\Channel\PresenceChannel;

final readonly class WhisperPusher
{
    public static function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'whisper_pusher',
                'description' => 'Send a whisper message to a Pusher/Reverb channel. Use this to broadcast responses to real-time requests or notify other users.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'event' => [
                            'type' => 'string',
                            'description' => 'The whisper event name (e.g., "ai-response", "status", "message")',
                        ],
                        'action' => [
                            'type' => 'string',
                            'description' => 'A short action identifier (e.g., "response", "error", "completed")',
                        ],
                        'message' => [
                            'type' => 'string',
                            'description' => 'The message content to send',
                        ],
                        'context' => [
                            'type' => 'object',
                            'description' => 'Optional additional context data',
                        ],
                    ],
                    'required' => ['event', 'message'],
                ],
            ],
        ];
    }

    public function handle(
        PresenceChannel $channel,
        string $event,
        string $message,
        ?string $action = null,
        array $context = [],
    ): array {
        $payload = [
            'action' => $action ?? 'response',
            'message' => $message,
            'timestamp' => now()->toIso8601String(),
            'context' => $context,
        ];

        $channel->whisper($event, $payload);

        return $this->success([
            'event' => $event,
            'payload' => $payload,
        ]);
    }

    public function respond(PresenceChannel $channel, string $message, array $context = []): array
    {
        return $this->handle($channel, 'ai-response', $message, 'response', $context);
    }

    public function status(PresenceChannel $channel, string $status, array $context = []): array
    {
        return $this->handle($channel, 'ai-status', $status, 'status', $context);
    }

    public function error(PresenceChannel $channel, string $error, array $context = []): array
    {
        return $this->handle($channel, 'ai-error', $error, 'error', $context);
    }

    public function completed(PresenceChannel $channel, string $message, array $context = []): array
    {
        return $this->handle($channel, 'ai-completed', $message, 'completed', $context);
    }

    protected function success(array $data): array
    {
        return [
            'success' => true,
            ...$data,
        ];
    }
}
