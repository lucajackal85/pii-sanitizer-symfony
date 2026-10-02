<?php

declare(strict_types=1);

namespace Jackal\PiiSanitizer\Bundle\DependencyInjection\Compiler;

use Jackal\PiiSanitizer\Processor\PiiSanitizerProcessor;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Fails early, with a message about this bundle's configuration, when the processor could not be attached:
 * MonologBundle is not registered, or "pii_sanitizer.channels" names a channel Monolog doesn't know.
 *
 * Without this, an unknown channel surfaces as "non-existent service monolog.logger.<name>" from inside
 * MonologBundle, and a missing MonologBundle leaves logs unsanitized without any error.
 *
 * Runs just before MonologBundle's LoggerChannelPass, and computes the channels the same way it does:
 * "app", the channels of services tagged "monolog.logger", and the ones declared under "monolog.channels".
 */
final class ValidateChannelsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(PiiSanitizerProcessor::class)) {
            return;
        }

        if (!$container->hasDefinition('monolog.logger')) {
            throw new \LogicException('PiiSanitizerBundle requires MonologBundle: add Symfony\Bundle\MonologBundle\MonologBundle to config/bundles.php (composer require symfony/monolog-bundle). Without it, log records are not sanitized.');
        }

        $requested = [];
        foreach ($container->getDefinition(PiiSanitizerProcessor::class)->getTag('monolog.processor') as $tag) {
            if (isset($tag['channel']) && \is_string($tag['channel'])) {
                $requested[] = $tag['channel'];
            }
        }
        if ([] === $requested) {
            return;
        }

        $known = $this->knownChannels($container);
        $unknown = array_values(array_diff($requested, $known));
        if ([] !== $unknown) {
            sort($known);

            throw new InvalidConfigurationException(\sprintf('Invalid configuration for path "pii_sanitizer.channels": unknown Monolog channel%s "%s". Known channels: "%s". Declare %s under "monolog.channels", or remove %s from "pii_sanitizer.channels".', \count($unknown) > 1 ? 's' : '', implode('", "', $unknown), implode('", "', $known), \count($unknown) > 1 ? 'them' : 'it', \count($unknown) > 1 ? 'them' : 'it'));
        }
    }

    /**
     * @return list<string>
     */
    private function knownChannels(ContainerBuilder $container): array
    {
        $channels = ['app'];

        foreach ($container->findTaggedServiceIds('monolog.logger') as $tags) {
            foreach ($tags as $tag) {
                if (!empty($tag['channel'])) {
                    $channel = $container->getParameterBag()->resolveValue($tag['channel']);
                    if (\is_string($channel)) {
                        $channels[] = $channel;
                    }
                }
            }
        }

        if ($container->hasParameter('monolog.additional_channels')) {
            $additional = $container->getParameter('monolog.additional_channels');
            foreach (\is_array($additional) ? $additional : [] as $channel) {
                if (\is_string($channel)) {
                    $channels[] = $channel;
                }
            }
        }

        return array_values(array_unique($channels));
    }
}
