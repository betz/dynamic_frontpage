<?php

namespace Drupal\dynamic_frontpage\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Class DomainEntityConfigForm.
 *
 * Provides a configuration form for managing domain-entity pairs.
 */
class DomainEntityConfigForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['dynamic_frontpage.domain_entity_config'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'domain_entity_config_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('dynamic_frontpage.domain_entity_config');

    // Use the form state or fall back to the configuration.
    $pairs = $form_state->get('pairs') ?? $config->get('domain_entity_pairs') ?? [];

    // Save the current state of pairs into the form state.
    $form_state->set('pairs', $pairs);

    // Wrap the entire form for AJAX updates.
    $form['#prefix'] = '<div id="domain-entity-wrapper">';
    $form['#suffix'] = '</div>';

    $form['pairs'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Domain'),
        $this->t('Entity Type'),
        $this->t('Entity Reference'),
        $this->t('Actions'),
      ],
      '#empty' => $this->t('No domain-entity pairs have been added yet.'),
    ];

    foreach ($pairs as $index => $pair) {
      $entity_type = $form_state->getValue(['pairs', $index, 'entity_type']) ?? $pair['entity_type'] ?? 'node';

      $form['pairs'][$index]['domain'] = [
        '#type' => 'textfield',
        '#default_value' => $pair['domain'],
        '#attributes' => ['placeholder' => $this->t('example.com')],
        '#required' => TRUE,
      ];

      $form['pairs'][$index]['entity_type'] = [
        '#type' => 'select',
        '#options' => $this->getEntityTypeOptions(),
        '#default_value' => $entity_type,
        '#empty_option' => $this->t('- Select entity type -'),
        '#ajax' => [
          'callback' => '::updateEntityReferenceAutocomplete',
          'wrapper' => "entity-reference-{$index}",
        ],
        '#required' => TRUE,
      ];

      $form['pairs'][$index]['entity_id'] = [
        '#type' => 'entity_autocomplete',
        '#target_type' => $entity_type,
        '#default_value' => (!empty($pair['entity_id']))
          ? \Drupal::entityTypeManager()->getStorage($entity_type)->load($pair['entity_id'])
          : NULL,
        '#prefix' => '<div id="entity-reference-' . $index . '">',
        '#suffix' => '</div>',
        '#required' => TRUE,
      ];

      $form['pairs'][$index]['remove'] = [
        '#type' => 'submit',
        '#name' => 'remove_' . $index,
        '#value' => $this->t('Remove'),
        '#ajax' => [
          'callback' => '::ajaxRefresh',
          'wrapper' => 'domain-entity-wrapper',
        ],
        '#submit' => [[$this, 'removeCallback']],
        '#limit_validation_errors' => [], // Bypass validation for this action.
      ];
    }

    $form['add'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add Pair'),
      '#ajax' => [
        'callback' => '::ajaxRefresh',
        'wrapper' => 'domain-entity-wrapper',
      ],
      '#submit' => [[$this, 'addCallback']],
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save configuration'),
    ];

    return parent::buildForm($form, $form_state);
  }

  protected function getEntityTypeOptions() {
    $entity_type_definitions = \Drupal::entityTypeManager()->getDefinitions();
    $options = [];

    foreach ($entity_type_definitions as $entity_type_id => $entity_type) {
      // Only include content entities (non-config entities).
      if ($entity_type->getGroup() === 'content') {
        $options[$entity_type_id] = $entity_type->getLabel();
      }
    }

    // Sort options alphabetically by label.
    asort($options);

    return $options;
  }

  public function updateEntityReferenceAutocomplete(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    $name = $trigger['#name'];
    preg_match('/pairs\[(\d+)\]\[entity_type\]/', $name, $matches);
    $index = $matches[1] ?? NULL;

    if ($index !== NULL) {
      $entity_type = $form_state->getValue(['pairs', $index, 'entity_type']) ?? 'node';
      $form['pairs'][$index]['entity_id']['#target_type'] = $entity_type;
      return $form['pairs'][$index]['entity_id'];
    }

    return NULL;
  }

  /**
   * Adds a new empty row for domain-entity pairs.
   */
  public function addCallback(array &$form, FormStateInterface $form_state) {
    // Retrieve the current pairs.
    $pairs = $form_state->get('pairs') ?? [];

    // Append a new empty row.
    $pairs[] = [
      'domain' => '',
      'entity_type' => 'node',
      'entity_id' => '',
    ];

    // Save back to form state.
    $form_state->set('pairs', $pairs);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Removes a specific row from domain-entity pairs.
   */
  public function removeCallback(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    $name = $trigger['#name'];

    // Extract the index from the triggering element's name.
    preg_match('/remove_(\d+)/', $name, $matches);
    $index = $matches[1] ?? NULL;

    if ($index !== NULL) {
      // Retrieve the current pairs.
      $pairs = $form_state->get('pairs') ?? [];

      // Remove the pair at the specified index.
      unset($pairs[$index]);

      // Re-index the array to prevent gaps in the keys.
      $pairs = array_values($pairs);

      // Save back to form state.
      $form_state->set('pairs', $pairs);
      $form_state->setRebuild(TRUE);
    }
  }

  /**
   * Ajax callback to refresh the form.
   */
  public function ajaxRefresh(array &$form, FormStateInterface $form_state) {
    return $form; // Return the entire form to ensure all elements are updated.
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $pairs = [];
    foreach ($form_state->getValue('pairs') as $pair) {
      // Only save valid pairs with non-empty values.
      if (!empty($pair['domain']) && !empty($pair['entity_type']) && !empty($pair['entity_id'])) {
        $pairs[] = [
          'domain' => $pair['domain'],
          'entity_type' => $pair['entity_type'],
          'entity_id' => $pair['entity_id'],
        ];
      }
    }

    // Save the pairs to the configuration.
    $this->config('dynamic_frontpage.domain_entity_config')
      ->set('domain_entity_pairs', $pairs)
      ->save();

    parent::submitForm($form, $form_state);
  }
}
