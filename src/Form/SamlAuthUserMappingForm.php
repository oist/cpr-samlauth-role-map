<?php

namespace Drupal\samlauth_custom_attributes\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Contains the samlauth user mapping form.
 */
class SamlAuthUserMappingForm extends ConfigFormBase
{
  /**
   * User settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $userSettings;

  /**
   * Entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructor for \Drupal\samlauth\Form\SamlAuthUserMappingForm.
   *
   * @param ConfigFactoryInterface $config
   *   An configuration factory.
   * @param EntityFieldManagerInterface $entity_field_manager
   *   An entity field manager.
   */
  public function __construct(ConfigFactoryInterface $config, EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager)
  {
    $this->userSettings = $config->get('samlauth.user.settings');
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'samlauth_user_mapping';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames()
  {
    return [
      'samlauth.user.mapping',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('samlauth.user.mapping');

    $form['user_mapping'] = [
      '#type' => 'table',
      '#header' => [
        'field_name' => $this->t('Field Name'),
        'attributes' => $this->t('IPD Attributes'),
        'settings' => $this->t('Settings'),
      ],
      '#empty' => $this->t('Currently there are no user mapping properties.'),
    ];

    foreach ($this->getUserEntityInputFields() as $field_name => $definition) {
      $form['user_mapping'][$field_name]['name'] = [
        '#plain_text' => $definition->getLabel(),
      ];
      $form['user_mapping'][$field_name]['attribute'] = [
        '#type' => 'select',
        '#options' => $this->getAttributeOptions(),
        '#empty_option' => $this->t('- None -'),
        '#default_value' => $config->get("user_mapping.$field_name.attribute"),
      ];
      $form['user_mapping'][$field_name]['settings'] = [
        '#type' => 'container',
        '#tree' => TRUE,
        '#states' => [
          'visible' => [
            ':input[name="user_mapping[' . $field_name . '][attribute]"]' => ['!value' => ''],
          ],
        ],
      ];
      $form['user_mapping'][$field_name]['settings']['use_account_linking'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Use when linking accounts'),
        '#default_value' => $config->get("user_mapping.$field_name.settings.use_account_linking"),
      ];
    }

    $form['user_roles'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('User Role'),
      '#prefix' => '<div id="group-wrapper">',
      '#suffix' => '</div>',
    ];

    if (empty($form_state->get('mapper'))) {
      $form_state->set('mapper', count($config->get('mapper.group')));
    }

    $mapperCount = $form_state->get('mapper');

    $form['user_roles']['btn_groups'] = [
      '#type' => 'container',
      '#attributes' => ['class' => 'form-item'],
    ];

    $form['user_roles']['btn_groups']['add'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add'),
      '#submit' => ['::addMap'],
      '#ajax' => [
        'callback' => '::handleMapperCallback',
        'wrapper' => 'group-wrapper',
      ],
    ];

    if ($mapperCount > 0) {
      $form['user_roles']['btn_groups']['remove'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove'),
        '#submit' => ['::removeMap'],
        '#ajax' => [
          'callback' => '::handleMapperCallback',
          'wrapper' => 'group-wrapper',
        ],
      ];
    }
    $form['user_roles']['mapper'] = [
      '#tree' => TRUE,
    ];

    for ($key = 0; $key < $mapperCount; $key++) {
      $form['user_roles']['mapper']['group'][$key] = [
        '#type' => 'details',
        '#title' => $this->t($config->get("mapper.group.$key.name") ?? 'New Mapping'),
        '#description' => $this->t('description'),
        '#open' => TRUE,
      ];

      $form['user_roles']['mapper']['group'][$key]['name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('IDP Attribute Value'),
        '#default_value' => $config->get("mapper.group.$key.name"),
      ];

      $form['user_roles']['mapper']['group'][$key]['roles'] = [
        '#type' => 'checkboxes',
        '#options' => $this->getUserRoleOptions(),
        '#default_value' => $config->get("mapper.group.$key.roles"),
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  public function handleMapperCallback(array &$form, FormStateInterface $form_state) {
    return $form['user_roles'];
  }

  public function addMap(array &$form, FormStateInterface $form_state) {
    $mapper = $form_state->get('mapper');
    $form_state->set('mapper', $mapper + 1);
    $form_state->setRebuild();
  }

  public function removeMap(array &$form, FormStateInterface $form_state) {
    $mapper = $form_state->get('mapper');
    $form_state->set('mapper', $mapper - 1);
    $form_state->setRebuild();
  }


  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $this->config('samlauth.user.mapping')
      ->setData($form_state->cleanValues()->getValues())
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Get user role options.
   *
   * @return array
   *   An array of user roles.
   */
  protected function getUserRoleOptions()
  {
    $options = [];

    foreach ($this->entityTypeManager->getStorage('user_role')
               ->loadMultiple() as $name => $role) {

      if (AccountInterface::ANONYMOUS_ROLE == $name
        || AccountInterface::AUTHENTICATED_ROLE == $name
        || !$role->status()) {
        continue;
      }

      $options[$name] = $role->label();
    }

    return $options;
  }

  /**
   * Get user entity input fields.
   *
   * @return array
   *   An array of user fields that allow inputed data.
   */
  protected function getUserEntityInputFields()
  {
    $input_fields = [];

    foreach ($this->getUserEntityFields() as $field_name => $definition) {
      if ($definition->isReadOnly() || $definition->isComputed()) {
        continue;
      }

      $input_fields[$field_name] = $definition;
    }

    ksort($input_fields);

    return $input_fields;
  }

  /**
   * Get user mapping attribute options.
   *
   * @return array
   *   An array of user mapping attribute options.
   */
  protected function getAttributeOptions()
  {
    $attributes = $this->getUserAttributes();

    if (empty($attributes)) {
      return [];
    }

    return array_combine($attributes, $attributes);
  }

  /**
   * Get user mapping attributes.
   *
   * @return array
   *   An array of user mapping attributes.
   */
  protected function getUserAttributes()
  {
    return array_map('trim', explode("\r\n", $this->userSettings->get('user_mapping.attributes')));
  }

  /**
   * Get user entity field definitions.
   *
   * @return array
   *   An array of user field definitions.
   */
  protected function getUserEntityFields()
  {
    return $this->entityFieldManager->getFieldDefinitions('user', 'user');
  }

}
