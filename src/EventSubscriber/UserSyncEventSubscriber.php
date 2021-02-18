<?php

namespace Drupal\samlauth_custom_attributes\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\samlauth\Event\SamlauthUserSyncEvent;
use Drupal\samlauth\EventSubscriber\UserSyncEventSubscriber as SamlauthUserSyncEventSubscriber;
use Egulias\EmailValidator\EmailValidator;
use Psr\Log\LoggerInterface;

/**
 * Event subscriber that synchronizes user properties on a user_sync event.
 *
 * This is basic module functionality, partially driven by config options. It's
 * split out into an event subscriber so that the logic is easier to tweak for
 * individual sites. (Set message or not? Completely break off login if an
 * account with the same name is found, or continue with a non-renamed account?
 * etc.)
 */
class UserSyncEventSubscriber extends SamlauthUserSyncEventSubscriber
{
  /**
   * A configuration object containing samlauth mapping settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $userMapping;

  /**
   * UserSyncEventSubscriber constructor.
   * @param ConfigFactoryInterface $config_factory
   * @param EntityTypeManagerInterface $entity_type_manager
   * @param TypedDataManagerInterface $typed_data_manager
   * @param EmailValidator $email_validator
   * @param LoggerInterface $logger
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, TypedDataManagerInterface $typed_data_manager, EmailValidator $email_validator, LoggerInterface $logger)
  {
    parent::__construct($config_factory, $entity_type_manager, $typed_data_manager, $email_validator, $logger);

    $this->userMapping = $config_factory->get('samlauth.user.mapping');
  }

  public function onUserSync(SamlauthUserSyncEvent $event) {
    $account = $event->getAccount();

    // Assign roles.
    if ($assigned_role = $this->userMapping->get('user_roles.assigned_role')) {
      foreach (array_keys(array_filter($assigned_role)) as $role_id) {
        if (!$account->hasRole($role_id)) {
          $account->addRole($role_id);
          $event->markAccountChanged();
        }
      }
    }

    // Resolve additional field/property mappings.
    if ($user_mappings = $this->userMapping->get('user_mapping')) {
      $attributes = $event->getAttributes();
      foreach ($user_mappings as $field_name => $mapping) {
        if (!empty($mapping['attribute'])) {
          if ($value = $attributes[$mapping['attribute']]) {
            $account->set($field_name, $value[0]);
            $event->markAccountChanged();
          }
        }
      }
    }
  }
}
