<?php

declare(strict_types=1);

namespace App\Console\Prompts\Code\Tools;

use ArtisanBuild\Resonance\Channel\PresenceChannel;
use ArtisanBuild\Resonance\Facades\Resonance;
use ArtisanBuild\Resonance\Resonance as Connection;
use Closure;

final class SubscribePusher
{
    protected ?Connection $connection = null;

    protected ?PresenceChannel $channel = null;

    protected ?Closure $onMessage = null;

    protected ?Closure $onJoin = null;

    protected ?Closure $onLeave = null;

    protected ?Closure $onError = null;

    protected bool $connected = false;

    protected string $connectionName;

    public static function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'subscribe_pusher',
                'description' => 'Subscribe to a Pusher/Reverb channel to receive real-time messages.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'channel' => [
                            'type' => 'string',
                            'description' => 'The channel name to subscribe to',
                        ],
                        'connection' => [
                            'type' => 'string',
                            'description' => 'The Resonance connection name (default: reverb)',
                        ],
                    ],
                    'required' => ['channel'],
                ],
            ],
        ];
    }

    public function __construct(string $connection = 'reverb')
    {
        $this->connectionName = $connection;
    }

    public static function make(string $connection = 'reverb'): static
    {
        return new self($connection);
    }

    public function onMessage(Closure $callback): static
    {
        $this->onMessage = $callback;

        return $this;
    }

    public function onJoin(Closure $callback): static
    {
        $this->onJoin = $callback;

        return $this;
    }

    public function onLeave(Closure $callback): static
    {
        $this->onLeave = $callback;

        return $this;
    }

    public function onError(Closure $callback): static
    {
        $this->onError = $callback;

        return $this;
    }

    public function subscribe(string $channelName): static
    {
        $this->connection = Resonance::connection($this->connectionName);

        $this->connection->connected(function () use ($channelName) {
            $this->connected = true;
            $this->channel = $this->connection->join($channelName);

            $this->channel->here(function (array $users) {
                // Initial user list received
            });

            $this->channel->joining(function (array $user) {
                if ($this->onJoin) {
                    ($this->onJoin)($user);
                }
            });

            $this->channel->leaving(function (array $user) {
                if ($this->onLeave) {
                    ($this->onLeave)($user);
                }
            });

            $this->channel->listenForWhisper('message', function ($data) {
                if ($this->onMessage) {
                    ($this->onMessage)($data);
                }
            });

            $this->channel->listenForWhisper('ai-request', function ($data) {
                if ($this->onMessage) {
                    ($this->onMessage)([
                        ...$data,
                        'type' => 'ai-request',
                    ]);
                }
            });

            $this->channel->listenForWhisper('action', function ($data) {
                if ($this->onMessage) {
                    ($this->onMessage)([
                        ...$data,
                        'type' => 'action',
                    ]);
                }
            });
        });

        $this->connection->error(function (\Exception $e) {
            if ($this->onError) {
                ($this->onError)($e);
            }
        });

        return $this;
    }

    public function unsubscribe(): static
    {
        if ($this->channel) {
            $this->channel->unsubscribe();
            $this->channel = null;
        }

        if ($this->connection) {
            $this->connection->disconnect();
            $this->connection = null;
        }

        $this->connected = false;

        return $this;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function getChannel(): ?PresenceChannel
    {
        return $this->channel;
    }

    public function getConnection(): ?Connection
    {
        return $this->connection;
    }
}
