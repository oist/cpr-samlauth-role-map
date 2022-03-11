<?php

namespace Drupal\samlauth_custom_attributes\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\samlauth\Event\SamlauthEvents;
use Drupal\samlauth\Event\SamlauthUserSyncEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event subscriber that synchronizes user properties on a user_sync event.
 *
 * This is basic module functionality, partially driven by config options. It's
 * split out into an event subscriber so that the logic is easier to tweak for
 * individual sites. (Set message or not? Completely break off login if an
 * account with the same name is found, or continue with a non-renamed account?
 * etc.)
 */
class UserSyncEventSubscriber implements EventSubscriberInterface {

  /**
   * A configuration object containing samlauth mapping settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $userMapping;

  /**
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $userSettings;

  /**
   * @var \Drupal\user\UserInterface
   */
  protected $account;

  /**
   * UserSyncEventSubscriber constructor.
   *
   * @param ConfigFactoryInterface $config_factory
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->userSettings = $config_factory->get('samlauth.user.settings');
    $this->userMapping = $config_factory->get('samlauth.user.mapping');
  }

  public static function getSubscribedEvents() {
    $events[SamlauthEvents::USER_SYNC][] = ['onUserSync'];
    return $events;
  }

  public function onUserSync(SamlauthUserSyncEvent $event) {
    $this->account = $event->getAccount();
    $originRoles = $this->account->getRoles(TRUE);

    // Resolve additional field/property mappings.
    if ($user_mappings = $this->userMapping->get('user_mapping')) {
      $attributes = $event->getAttributes();
      foreach ($user_mappings as $field_name => $mapping) {
        $method = "set" . ucfirst($field_name) . 'Attribute';
        if (!empty($mapping['attribute']) && isset($attributes[$mapping['attribute']])) {
          if ($value = $attributes[$mapping['attribute']]) {
            if (method_exists($this, $method)) {
              $this->$method($event, $value[0]);
            }
            else {
              $this->account->set($field_name, $value[0]);
              $event->markAccountChanged();
            }
          }
        }
      }
    }

    $this->keepRoles($originRoles, $event);

    $this->defaultRoles($event);
  }

  protected function setRolesAttribute(SamlauthUserSyncEvent $event, $value): void {
    if (!$mappers = $this->userMapping->get('mapper')) {
      return;
    }

    $mapper = array_filter($mappers, function ($mapper) use ($value) {
      return $mapper['name'] == $value;
    });

    $mapper = reset($mapper);

    $roles = $mapper ? array_keys(array_filter($mapper['roles'])) : '';

    $this->account->set('roles', $roles);

    $event->markAccountChanged();
  }

  protected function keepRoles($originRoles, SamlauthUserSyncEvent $event) {
    if ($kept_roles = $this->userSettings->get('user_roles.keep.roles')) {
      foreach ($originRoles as $role_id) {
        if (in_array($role_id, array_keys(array_filter($kept_roles)))) {
          $this->account->addRole($role_id);
          $event->markAccountChanged();
        }
      }
    }
  }

  protected function defaultRoles(SamlauthUserSyncEvent $event) {
    if ($assigned_role = $this->userSettings->get('user_roles.default.roles')) {
      foreach (array_keys(array_filter($assigned_role)) as $role_id) {
        $this->account->addRole($role_id);
        $event->markAccountChanged();
      }
    }
  }

}
