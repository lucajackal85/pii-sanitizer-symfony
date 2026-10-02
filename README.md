# pii-sanitizer-symfony

A Symfony bundle that removes PII and secrets from your Monolog logs **before** they reach Sentry, DataDog, log files or any other handler.

It wires the [`pii-sanitizer-php`](https://github.com/lucajackal85/pii-sanitizer-php) Monolog processor into MonologBundle. The processor sends each record to the local [PII Sanitizer Engine](https://github.com/lucajackal85/pii-sanitizer-engine) over a Unix socket.

```text
Before:  app.ERROR: Payment failed for John Doe {"email":"john.doe@example.com"}
After:   app.ERROR: Payment failed for [PRIVATE_PERSON] {"email":"[PRIVATE_EMAIL]"}
```

## Requirements

- PHP ≥ 8.1, Symfony 6.4 or 7.x, and MonologBundle 3.10 or later
- A running PII Sanitizer Engine container whose socket your PHP-FPM user can read and write. See the [engine quick start](https://github.com/lucajackal85/pii-sanitizer-engine#quick-start) and the [compose example](https://github.com/lucajackal85/pii-sanitizer-php/blob/main/examples/docker-compose.yml).

## Install

The packages are not on Packagist yet, so add both repositories to your app's `composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/lucajackal85/pii-sanitizer-symfony" },
    { "type": "vcs", "url": "https://github.com/lucajackal85/pii-sanitizer-php" }
]
```

Then require both. Composer only installs a development branch of a dependency when your app requires it explicitly:

```bash
composer require lucajackal85/pii-sanitizer-symfony:dev-main lucajackal85/pii-sanitizer-php:dev-main
```

While the repositories are private, Composer needs a GitHub token that can read them (`composer config --global github-oauth.github.com <token>`).

Register the bundle. There is no Flex recipe yet:

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

## When the engine is unavailable

| `on_failure` | Behaviour |
|---|---|
| `redact` *(default)* | The message becomes `[PII_SANITIZER_UNAVAILABLE]` and the context is dropped. Nothing leaks. |
| `passthrough` | The record is logged unchanged, and **may contain PII**. |

After a failure the processor stops calling the engine for `circuit_breaker_seconds`, so a dead engine doesn't slow down every request. See the [pii-sanitizer-php README](https://github.com/lucajackal85/pii-sanitizer-php#when-the-sidecar-is-unavailable) for details.

## Development

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

The tests compile a container with the real MonologBundle and check that the processor ends up on the right channel loggers.
