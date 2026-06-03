# Tobias

**Tobias** is an agentic terminal coding assistant — a full-screen TUI chat
agent with file tools, multi-session support, and pluggable AI providers.
It installs globally as the `toby` binary.

Built on [laravel-zero](https://laravel-zero.com), with its UI rendered by
[artisan-build/parfait](https://github.com/artisan-build/parfait) and its async,
non-blocking input loop powered by a ReactPHP event loop on top of
[laravel/prompts](https://github.com/laravel/prompts).

```
 Tobias   google/gemma-4-e4b   Main  [1/2]                    Ready   INSERT
  Sessions          Conversation                           Context
  ▼ ● Main (3)      Type a message to start...              Session
  ▼ ○ Code Prompt                                           Provider
                                                            Context Window
```

## Requirements

- PHP **8.4+** (required by `artisan-build/resonance`)
- `ext-mbstring`, `ext-intl`

## Installation

### Global (recommended)

```bash
composer global require artisan-build/tobias
```

Make sure your global composer `bin` directory is on your `PATH`
(`~/.composer/vendor/bin` or `~/.config/composer/vendor/bin`), then run:

```bash
toby
```

> Until Tobias is published to Packagist, install it from VCS by adding the
> GitHub repo to your global composer config (see `HANDOFF.md` → Distribution).

### Local (development)

```bash
git clone https://github.com/artisan-build/tobias
cd tobias
composer install
./toby
```

## Configuration

Tobias reads provider credentials from the environment. Set the keys for the
providers you use (in your shell profile for global use, or a `.env` file for
local development — see `.env.example`):

```bash
export ANTHROPIC_API_KEY=sk-ant-...
export OPENAI_API_KEY=sk-...
export GEMINI_API_KEY=...
# Local models need no key:
export LMSTUDIO_URL=http://127.0.0.1:1234/v1
export OLLAMA_URL=http://localhost:11434
```

Providers can be switched from inside the app. Supported out of the box:
Anthropic, OpenAI, Gemini, and LM Studio (local).

## Usage

```bash
toby            # launch the assistant (default command)
toby code       # same thing, explicitly
toby list       # show all commands
```

Inside the TUI: type to chat, `Esc` then `Enter` to send, slash-commands for
sessions/context/help, and `~` / `@` to insert file references. The agent has
file tools (`list_files`, `read_file`, `write_file`, `update_file`) and a
guarded `run_bash` (asks for approval before executing).

## How it fits together

Tobias was extracted from the `cli-playground` monorepo. Its UI and async
primitives are shared, standalone packages:

- **UI** — `artisan-build/parfait` (component-based TUI rendering).
- **Async input** — an `AsyncPrompt` that drives `laravel/prompts` with a
  ReactPHP event loop for non-blocking keystroke handling. The classes are
  currently vendored under `app/Support/Prompts` (see that file's header for
  why); they originate from `artisan-build/community-prompts`.
- **Real-time** — optional pusher/websocket collaboration via
  `artisan-build/resonance` (dormant unless a channel is connected).

## License

MIT
