<?php

namespace cspoo\Swiftmailer\MailgunBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\DefinitionDecorator;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Loader;

/**
 * This is the class that loads and manages your bundle configuration.
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html}
 */
class cspooSwiftmailerMailgunExtension extends Extension
{
    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $loader = new Loader\XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.xml');

        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('mailgun.key', $config['key']);
        $container->setParameter('mailgun.domain', $config['domain']);
//        $container->setParameter('mailgun.endpoint', $config['endpoint']);

        $definitionDecorator = new DefinitionDecorator('swiftmailer.transport.eventdispatcher.abstract');
        $container->setDefinition('mailgun.swift_transport.eventdispatcher', $definitionDecorator);

        $container->getDefinition('mailgun.swift_transport.transport')
            ->replaceArgument(0, new Reference('mailgun.swift_transport.eventdispatcher'));

        $definition = $container->getDefinition('mailgun.library');
        if (!empty($config['http_client'])) {
            $definition->replaceArgument(1, new Reference($config['http_client']));
        }

        if (!empty($config['endpoint'])) {
            $arguments = $definition->getArguments();
            if (array_key_exists(2, $arguments)) {
                // Endpoint is already set, we need to override it
                $definition->replaceArgument(2, $config['endpoint']);
            } else {
                // Endpoint is not set, we can just add it
                $definition->addArgument($config['endpoint']);
            }
        }

        //set some alias
        $container->setAlias('mailgun', 'mailgun.swift_transport.transport');
        $container->setAlias('swiftmailer.mailer.transport.mailgun', 'mailgun.swift_transport.transport');
    }
}
