<?php

namespace Drupal\social_activitypub\Plugin\activitypub\type;

use Drupal\activitypub\Entity\ActivityPubActivityInterface;
use Drupal\activitypub\Services\Type\TypePluginBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * The ActivityPub core types.
 *
 * @ActivityPubType(
 *   id = "social_activitypub_dynamic_types",
 *   label = @Translation("Open Social Public message")
 * )
 */
class SocialDynamicTypes extends TypePluginBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'target_entity_type_id' => 'post',
      'target_bundle' => 'post',
      'object' => '',
      'field_mapping' => []
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    $dependencies = [];

    $configuration = $this->getConfiguration();
    if (!empty($configuration['target_entity_type_id'])) {
      $dependencies['module'] = [$configuration['target_entity_type_id']];
    }

    if (!empty($configuration['target_entity_type_id']) && !empty($configuration['target_bundle'])) {
      $dependencies['config'] = [$configuration['target_entity_type_id'] . '.type.' . $configuration['target_bundle']];
    }

    return $dependencies;
  }

  /**
   * {@inheritdoc}
   */
  public function getActivities() {
    return [
      'Create', 'Like', 'Announce',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getObjects() {
    return [
      'Article', 'Note',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getProperties($object) {
    return [
      'published' => 'Date',
      'name' => 'Title',
      'content' => 'Content',
      'summary' => 'Summary',
      'object' => 'Like/Announce/Follow target',
      'inReplyTo' => 'Reply link',
      'attachment' => 'Image attachment',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $entity_type_id = $this->getConfiguration()['target_entity_type_id'];

    $entity_types = ['post' => $this->t('Post')];
    $form['target_entity_type_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Entity type'),
      '#options' => $entity_types,
      '#default_value' => $entity_type_id,
      '#ajax' => [
        'trigger_as' => ['name' => 'type_configure'],
        'callback' => '::buildAjaxTypeForm',
        'wrapper' => 'type-config-form',
        'method' => 'replace',
        'effect' => 'fade',
      ],
    ];

    $bundle_options = [];
    $names = [];
    $config_names = $this->configFactory->listAll('social_post.post_type.');
    foreach ($config_names as $config_name) {
      $id = substr($config_name, strlen('social_post.post_type.'));
      $names[$id] = $id;
    }
    $bundle_options = $names;

      $form['target_bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Type'),
      '#options' => ['' => $this->t('- Select -')] + $bundle_options,
      '#default_value' => $this->getConfiguration()['target_bundle'],
      '#ajax' => [
        'trigger_as' => ['name' => 'type_configure'],
        'callback' => '::buildAjaxTypeForm',
        'wrapper' => 'type-config-form',
        'method' => 'replace',
        'effect' => 'fade',
      ],
    ];

    $default_mapping = [];
    $default_value = $this->getConfiguration()['field_mapping'];
    foreach ($default_value as $v) {
      $default_mapping[$v['property']] = $v['field_name'];
    }

    // Get bundle fields.
    $bundle_fields = ['' => $this->t('- Select -')];
    $field_definitions = $this->entityFieldManager->getFieldDefinitions($this->getConfiguration()['target_entity_type_id'], $this->getConfiguration()['target_bundle']);
    foreach ($field_definitions as $field_definition) {
      $bundle_fields[$field_definition->getFieldStorageDefinition()
        ->getName()] = $field_definition->getName();
    }

    $form['field_mapping'] = [
      '#type' => 'container',
    ];

    $properties = $this->getProperties($this->getConfiguration()['object']);
    foreach ($properties as $property => $label) {
      $form['field_mapping'][] = [
        'property' => [
          '#type' => 'value',
          '#value' => $property,
        ],
        'field_name' => [
          '#type' => 'select',
          '#title' => $this->t('Property: @property', ['@property' => $label]),
          '#options' => $bundle_fields,
          '#default_value' => isset($default_mapping[$property]) ? $default_mapping[$property] : '',
        ],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function build(ActivityPubActivityInterface $activity, EntityInterface $entity = NULL) {
    $object = [
      'type' => $this->getConfiguration()['object'],
      'id' => $this->renderEntityUrl($entity),
      'attributedTo' => $activity->getActor(),
    ];

    foreach ($this->getConfiguration()['field_mapping'] as $mapping) {
      if (!empty($mapping['field_name'])) {
        if ($entity->hasField($mapping['field_name']) && ($value = $entity->get($mapping['field_name'])->getValue())) {
          $field_type = $entity->get($mapping['field_name'])->getFieldDefinition()->getType();
          if ($v = $this->getValue($mapping['property'], $value, $field_type)) {
            $object[$mapping['property']] = $v;
          }
        }
      }
    }

    $to = $cc = $mention = [];
    $this->buildAudience($to, $cc, $mention, $activity);
    $object['to'] = $to;
    $object['cc'] = $cc;

    // Create.
    if ($this->getConfiguration()['activity'] == 'Create') {
      $return = [
        'type' => $this->getConfiguration()['activity'],
        'id' => $this->renderEntityUrl($activity),
        'actor' => $activity->getActor(),
        'to' => $to,
        'cc' => $cc,
        'object' => $object
      ];

      if (!empty($mention)) {
        $return['object']['tag'] = [(object) $mention];
      }
    }
    else {
      $return = [
        'type' => $this->getConfiguration()['activity'],
        'id' => $this->renderEntityUrl($activity),
        'actor' => $activity->getActor(),
        'to' => $to,
        'cc' => $cc,
        'object' => $object['object']
      ];
    }

    return $return;
  }

  /**
   * Gets an entity from an url.
   *
   * @param $url string
   *   The string url to obtain an entity.
   *
   * @return \Drupal\Core\Entity\EntityInterface $entity|NULL
   *   Returns an entity or NULL.
   */
  public static function getEntityFromUrl(string $url) {
    $host = \Drupal::request()->getSchemeAndHttpHost();
    try {
      if (UrlHelper::externalIsLocal($url, $host)) {
        $path = str_replace($host . base_path(), '', $url);
        /** @var \Drupal\Core\Path\PathValidatorInterface $validator */
        $validator = \Drupal::service('path.validator');
        $url_object = $validator->getUrlIfValidWithoutAccessCheck($path);
        if ($url_object && in_array($url_object->getRouteName(), ["entity.post.canonical", "activitypub.user.self"])) {
          $entity_type_id = $entity_id = NULL;
          switch ($url_object->getRouteName()) {
            case 'entity.post.canonical':
              $entity_type_id = 'post';
              $entity_id = $url_object->getRouteParameters()['post'];
              break;
            case 'activitypub.user.self':
              $entity_type_id = 'user';
              $entity_id = $url_object->getRouteParameters()['user'];
              break;
          }

          if ($entity_type_id && $entity_id) {
            $entity = \Drupal::entityTypeManager()->getStorage($entity_type_id)->load($entity_id);
            if ($entity) {
              return $entity;
            }
          }
        }
      }
    }
    catch (\Exception $ignored) {}

    return NULL;
  }
  
}
