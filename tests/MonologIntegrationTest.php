<?php

declare(strict_types=1);

namespace OpenPii\PiiSanitizerBundle\Tests;

use OpenPii\MonologSanitizer\Processor\PiiSanitizerProcessor;
use OpenPii\PiiSanitizerBundle\PiiSanitizerBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Boots the real MonologBundle next to ours and checks the processor ends up on the loggers.
 */
final class MonologIntegrationTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     */
    private static function build(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $container->setParameter('kernel.logs_dir', sys_get_temp_dir());

        foreach ([new MonologBundle(), new PiiSanitizerBundle()] as $bundle) {
            $extension = $bundle->getContainerExtension();
            self::assertNotNull($extension);
            $container->registerExtension($extension);
            $bundle->build($container);
        }
        $container->loadFromExtension('monolog', [
            'channels' => ['security', 'payments'],
            'handlers' => ['main' => ['type' => 'stream', 'path' => 'php://stderr']],
        ]);
        $container->loadFromExtension('pii_sanitizer', $config);
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
}
