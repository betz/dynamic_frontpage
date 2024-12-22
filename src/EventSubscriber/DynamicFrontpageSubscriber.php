<?php

namespace Drupal\dynamic_frontpage\EventSubscriber;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\path_alias\AliasManagerInterface;

class DynamicFrontpageSubscriber implements EventSubscriberInterface {

  private $httpKernel;
  protected $configFactory;
  protected $aliasManager;
  protected $entityRepository;

  public function __construct(
    HttpKernelInterface $httpKernel,
    ConfigFactoryInterface $config_factory,
    AliasManagerInterface $alias_manager,
    EntityRepositoryInterface $entity_repository
  ) {
    $this->httpKernel = $httpKernel;
    $this->configFactory = $config_factory;
    $this->aliasManager = $alias_manager;
    $this->entityRepository = $entity_repository;
  }

  public function checkFrontRedirection(RequestEvent $event) {
    if ($event->isMainRequest() && \Drupal::service('path.matcher')->isFrontPage()) {
      $config = $this->configFactory->get('dynamic_frontpage.domain_entity_config');
      $front_pages_config = $config->get('domain_entity_pairs') ?? [];

      $front_pages = [];
      foreach ($front_pages_config as $pair) {
        $domain = $pair['domain'];
        $entity_type = $pair['entity_type'];
        $entity_id = $pair['entity_id'];

        $entity = \Drupal::entityTypeManager()->getStorage($entity_type)->load($entity_id);
        if ($entity) {
          // Check if the entity type has a 'canonical' link template.
          $entity_type_definition = \Drupal::entityTypeManager()->getDefinition($entity_type);
          if ($entity_type_definition->hasLinkTemplate('canonical')) {
            // Get the canonical route path for the entity.
            $entity_url = $entity->toUrl('canonical', ['absolute' => FALSE])->toString();

            // Resolve the alias if it exists.
            $path_alias = $this->aliasManager->getAliasByPath($entity_url);
            $front_pages[$domain] = $path_alias ?: $entity_url;
          } else {
            // Log a warning for unsupported entities.
            \Drupal::logger('dynamic_frontpage')->warning('Entity type "@type" does not have a canonical link template.', [
              '@type' => $entity_type,
            ]);
          }
        }
      }



      $request = $event->getRequest();
      $host = $request->getHost();

      $config = $this->configFactory->getEditable('system.site');
      $path = $config->get('page.front');

      \Drupal::logger('dynamic_frontpage')->debug('Entity: @entity, Path: @path', [
        '@entity' => $entity ? $entity->id() : 'NULL',
        '@path' => $path,
      ]);

      if (!empty($front_pages[$host])) {
        $path = $front_pages[$host];
      }

      $subRequest = Request::create($path, 'GET', [], [], [], [
        'HTTPS' => $request->isSecure(),
        'HTTP_HOST' => $request->getHost(),
        'REQUEST_URI' => $path,
        'SERVER_PORT' => $request->getPort(),
      ]);

      $subRequest->headers->replace($request->headers->all());
      $subRequest->cookies->replace($request->cookies->all());

      $response = $this->httpKernel->handle($subRequest, HttpKernelInterface::SUB_REQUEST);
      $event->setResponse($response);
    }
  }

  public static function getSubscribedEvents() {
    $events[KernelEvents::REQUEST][] = ['checkFrontRedirection'];
    return $events;
  }
}
