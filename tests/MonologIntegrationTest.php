<?php

declare(strict_types=1);

namespace OpenPii\PiiSanitizerBundle\Tests;

use OpenPii\MonologSanitizer\Processor\PiiSanitizerProcessor;
use OpenPii\PiiSanitizerBundle\PiiSanitizerBundle;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Boots the real MonologBundle next to ours and checks the processor ends up on the loggers.
 */
final class MonologIntegrationTest extends TestCase
{
    /**
     * @param array<string, mixed>                    $config
     * @param (callable(ContainerBuilder): void)|null $setup  extra services, registered before compiling
     */
    private static function build(array $config, ?callable $setup = null, bool $withMonolog = true): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $container->setParameter('kernel.logs_dir', sys_get_temp_dir());

        foreach ($withMonolog ? [new MonologBundle(), new PiiSanitizerBundle()] : [new PiiSanitizerBundle()] as $bundle) {
            $extension = $bundle->getContainerExtension();
            self::assertNotNull($extension);
            $container->registerExtension($extension);
            $bundle->build($container);
        }
        if ($withMonolog) {
            $container->loadFromExtension('monolog', [
                'channels' => ['security', 'payments'],
                'handlers' => ['main' => ['type' => 'stream', 'path' => 'php://stderr']],
            ]);
        }
        $container->loadFromExtension('pii_sanitizer', $config);
        if (null !== $setup) {
            $setup($container);
        }
        $container->getCompilerPassConfig()->setRemovingPasses([]);
        $container->compile();

        return $container;
    }

    private static function hasProcessor(Definition $logger): bool
    {
        foreach ($logger->getMethodCalls() as [$method, $args]) {
            if ('pushProcessor' === $method && $args[0] instanceof Reference && PiiSanitizerProcessor::class === (string) $args[0]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Monolog's "app" channel is the main monolog.logger service; other channels are monolog.logger.<name>.
     *
     * @return array<string, bool> channel => has the processor
     */
    private static function loggers(ContainerBuilder $container): array
    {
        $result = [];
        foreach (['app' => 'monolog.logger', 'security' => 'monolog.logger.security', 'payments' => 'monolog.logger.payments'] as $channel => $id) {
            $result[$channel] = self::hasProcessor($container->getDefinition($id));
        }

        return $result;
    }

    public function testProcessorIsPushedOntoEveryChannelLogger(): void
    {
        self::assertSame(['app' => true, 'security' => true, 'payments' => true], self::loggers(self::build([])));
    }

    public function testChannelFilterTargetsOnlyThoseChannels(): void
    {
        self::assertSame(
            ['app' => true, 'security' => true, 'payments' => false],
            self::loggers(self::build(['channels' => ['app', 'security']])),
        );
    }

    public function testUnknownChannelFailsWithAConfigurationError(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Invalid configuration for path "pii_sanitizer.channels": unknown Monolog channel "event". Known channels: "app", "payments", "security".');

        self::build(['channels' => ['security', 'event']]);
    }

    public function testChannelCreatedByATaggedServiceIsAccepted(): void
    {
        // A service asking for its own channel creates it, even if it isn't listed under monolog.channels.
        $container = self::build(['channels' => ['audit']], static function (ContainerBuilder $container): void {
            $container->register('app.audit_service', \stdClass::class)
                ->addArgument(new Reference(LoggerInterface::class))
                ->addTag('monolog.logger', ['channel' => 'audit']);
            $container->setAlias(LoggerInterface::class, 'logger');
        });

        self::assertTrue(self::hasProcessor($container->getDefinition('monolog.logger.audit')));
    }

    public function testMissingMonologBundleFailsInsteadOfSilentlyNotSanitizing(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('PiiSanitizerBundle requires MonologBundle');

        self::build([], null, withMonolog: false);
    }
}
