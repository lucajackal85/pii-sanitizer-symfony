# pii-sanitizer-symfony

A Symfony bundle that removes PII and secrets from your Monolog logs **before** they reach Sentry, DataDog, log files or any other handler.

It wires the [`pii-sanitizer-php`](https://github.com/lucajackal85/pii-sanitizer-php) Monolog processor into MonologBundle. The processor sends each record to the local [PII Sanitizer Engine](https://github.com/lucajackal85/pii-sanitizer-engine) over a Unix socket.

```text
Before:  app.ERROR: Payment failed for John Doe {"email":"john.doe@example.com"}
After:   app.ERROR: Payment failed for [PRIVATE_PERSON] {"email":"[PRIVATE_EMAIL]"}
```

## Requirements

- PHP ≥ 8.1
- Symfony 6.4, 7.x or 8.x (Symfony 8 itself requires PHP 8.4)
- MonologBundle 3.10 or later, or 4.x
- A running PII Sanitizer Engine container whose socket your PHP-FPM user can read and write. See the [engine README](https://github.com/lucajackal85/pii-sanitizer-engine#readme) and the [compose example](https://github.com/lucajackal85/pii-sanitizer-php/blob/main/examples/docker-compose.yml).

## Install

```bash
composer require lucajackal85/pii-sanitizer-symfony
```

This also installs [`lucajackal85/pii-sanitizer-php`](https://github.com/lucajackal85/pii-sanitizer-php), the socket client and Monolog processor the bundle wires up.

In an app that uses Symfony Flex (the default), that's all: Flex adds the bundle to `config/bundles.php` for you. Without Flex, register it yourself:

```php
// config/bundles.php
return [
    // ...
    OpenPii\PiiSanitizerBundle\PiiSanitizerBundle::class => ['all' => true],
];
```

## Configure

Every option is optional. These are the defaults:

```yaml
# config/packages/pii_sanitizer.yaml
pii_sanitizer:
  socket_path: '/tmp/sockets/pii_sanitizer.sock'   # e.g. '%env(PII_SOCKET_PATH)%'
  connect_timeout: 0.05        # seconds
  read_timeout: 1.0            # seconds
  on_failure: redact           # redact | passthrough
  circuit_breaker_seconds: 5
  channels: []                 # empty = every channel; e.g. [app, security]
```

The bundle registers the processor with the `monolog.processor` tag. MonologBundle then adds it to every logger channel, or only to the channels you list. It runs before your handlers, so Sentry, DataDog and files only ever receive the scrubbed record.

To scrub only what leaves the server, list just those channels. Log calls wait for the engine, which on CPU takes about 100–400 ms for a new line.

Every channel you list must exist in Monolog: `app`, the channels declared under `monolog.channels`, or a channel a service logs to (for example `security`, `php`, `request`). An unknown name stops the container from compiling, with an error under `pii_sanitizer.channels` that lists the channels your app has. The bundle also refuses to start without MonologBundle, because records would silently not be sanitized.

## When the engine is unavailable

| `on_failure` | Behaviour |
|---|---|
| `redact` *(default)* | The message becomes `[PII_SANITIZER_UNAVAILABLE]` and the context is dropped. Nothing leaks. |
| `passthrough` | The record is logged unchanged, and **may contain PII**. |

After a failure the processor stops calling the engine for `circuit_breaker_seconds`, so a dead engine doesn't slow down every request. See the [pii-sanitizer-php README](https://github.com/lucajackal85/pii-sanitizer-php#when-the-sidecar-is-unavailable) for details.

## Development

```bash
composer install
composer check        # php-cs-fixer + rector (dry run), PHPStan and PHPUnit, like CI
composer cs-fix       # apply php-cs-fixer
composer rector-fix   # apply rector
```

The tests compile a container with the real MonologBundle and check that the processor ends up on the right channel loggers.

## License

MIT. See [LICENSE](LICENSE).
