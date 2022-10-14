<?php

namespace Drupal\hel_tpm_general\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\inline_entity_form\Plugin\Field\FieldWidget\InlineEntityFormComplex;

/**
 * Defines the 'hel_tpm_general_inline_entity_form_complex_improved' field widget.
 *
 * @FieldWidget(
 *   id = "hel_tpm_general_inline_entity_form_complex_improved",
 *   label = @Translation("Inline entity form complex improved"),
 *   field_types = {
 *     "entity_reference",
 *     "entity_reference_revisions",
 *   },
 *   multiple_values = true
 * )
 */
class InlineEntityFormComplexImproved extends InlineEntityFormComplex {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    $defaults = parent::defaultSettings();
    $defaults += [
      'bundle_limit' => []
    ];
    return $defaults;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $form = parent::settingsForm($form, $form_state);
    if ($this->getSetting('allow_new') === TRUE) {
      $form['bundle_limit'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Limit content types'),
        '#options' => $this->getContentTypeOptions(),
        '#states'=> [
          'visible' => ['input[name="fields[field_service_location][settings_edit_form][settings][allow_new]"]' => ['checked' => TRUE]]
        ],
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary(); // TODO: Change the autogenerated stub
    if ($this->getSetting('allow_new')) {
      $limit = $this->getSetting('bundle_limit');
      if (empty($limit)) {
        $summary[] = $this->t('All content types allowed');
      }
      else {

        $summary[] = $this->t('Limited to @content_types', ['@content_types' => implode(', ', array_diff($limit, [0]))]);
      }
    }
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  protected function createInlineFormHandler() {
    if (!isset($this->inlineFormHandler)) {
      $target_type = $this->getFieldSetting('target_type');
      $this->inlineFormHandler = $this->entityTypeManager->getHandler($target_type, 'inline_form_table_view_mode');
    }
  }

  /**
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   * @param $delta
   * @param array $element
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *
   * @return array
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $settings = $this->getSettings();
    $target_type = $this->getFieldSetting('target_type');
    // Get the entity type labels for the UI strings.
    $labels = $this->getEntityTypeLabels();

    // Build a parents array for this element's values in the form.
    $parents = array_merge($element['#field_parents'], [
      $items->getName(),
      'form',
    ]);

    // Assign a unique identifier to each IEF widget.
    // Since $parents can get quite long, hashing ensures that every id has
    // a consistent and relatively short length while maintaining uniqueness.
    $this->setIefId($this->makeIefId($parents));

    // Get the langcode of the parent entity.
    $parent_langcode = $items->getEntity()->language()->getId();

    // Determine the wrapper ID for the entire element.
    $wrapper = 'inline-entity-form-' . $this->getIefId();

    $element = [
        '#type' => $this->getSetting('collapsible') ? 'details' : 'fieldset',
        '#tree' => TRUE,
        '#description' => $this->fieldDefinition->getDescription(),
        '#prefix' => '<div id="' . $wrapper . '">',
        '#suffix' => '</div>',
        '#ief_id' => $this->getIefId(),
        '#ief_root' => TRUE,
        '#translating' => $this->isTranslating($form_state),
        '#field_title' => $this->fieldDefinition->getLabel(),
        '#after_build' => [
          [get_class($this), 'removeTranslatabilityClue'],
        ],
      ] + $element;
    if ($element['#type'] == 'details') {
      // If there's user input, keep the details open. Otherwise, use settings.
      $element['#open'] = $form_state->getUserInput() ?: !$this->getSetting('collapsed');
    }

    $this->prepareFormState($form_state, $items, $element['#translating']);
    $entities = $form_state->get(['inline_entity_form', $this->getIefId(), 'entities']);

    // Prepare cardinality information.
    $entities_count = count($entities);
    $cardinality = $this->fieldDefinition->getFieldStorageDefinition()->getCardinality();
    $cardinality_reached = ($cardinality > 0 && $entities_count == $cardinality);

    // Build the "Multiple value" widget.
    // TODO - does this belong in #element_validate?
    $element['#element_validate'][] = [get_class($this), 'updateRowWeights'];
    // Add the required element marker & validation.
    if ($element['#required']) {
      $element['#element_validate'][] = [get_class($this), 'requiredField'];
    }

    $element['entities'] = [
      '#tree' => TRUE,
      '#theme' => 'inline_entity_form_entity_table',
      '#entity_type' => $target_type,
    ];

    // Get the fields that should be displayed in the table.
    $target_bundles = $this->getTargetBundles();
    $fields = $this->inlineFormHandler->getTableFields($target_bundles);
    $context = [
      'parent_entity_type' => $this->fieldDefinition->getTargetEntityTypeId(),
      'parent_bundle' => $this->fieldDefinition->getTargetBundle(),
      'field_name' => $this->fieldDefinition->getName(),
      'entity_type' => $target_type,
      'allowed_bundles' => $target_bundles,
    ];
    $this->moduleHandler->alter('inline_entity_form_table_fields', $fields, $context);
    $element['entities']['#table_fields'] = $fields;

    $weight_delta = max(ceil($entities_count * 1.2), 50);
    foreach ($entities as $key => $value) {
      // Data used by inline-entity-form-entity-table.html.twig.
      // @see template_preprocess_inline_entity_form_entity_table()
      /** @var \Drupal\Core\Entity\EntityInterface $entity */
      $entity = $value['entity'];
      $element['entities'][$key]['#label'] = $this->inlineFormHandler->getEntityLabel($value['entity']);
      $element['entities'][$key]['#entity'] = $value['entity'];
      $element['entities'][$key]['#needs_save'] = $value['needs_save'];

      // Handle row weights.
      $element['entities'][$key]['#weight'] = $value['weight'];

      // First check to see if this entity should be displayed as a form.
      if (!empty($value['form'])) {
        $element['entities'][$key]['title'] = [];
        $element['entities'][$key]['delta'] = [
          '#type' => 'value',
          '#value' => $value['weight'],
        ];

        // Add the appropriate form.
        if (in_array($value['form'], ['edit', 'duplicate'])) {
          $element['entities'][$key]['form'] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['ief-form', 'ief-form-row']],
            'inline_entity_form' => $this->getInlineEntityForm(
              $value['form'],
              $entity->bundle(),
              $parent_langcode,
              $key,
              array_merge($parents, ['inline_entity_form', 'entities', $key, 'form']),
              $value['form'] == 'edit' ? $entity : $entity->createDuplicate()
            ),
          ];

          $element['entities'][$key]['form']['inline_entity_form']['#process'] = [
            ['\Drupal\inline_entity_form\Element\InlineEntityForm', 'processEntityForm'],
            [get_class($this), 'addIefSubmitCallbacks'],
            [get_class($this), 'buildEntityFormActions'],
          ];
        }
        elseif ($value['form'] == 'remove') {
          $element['entities'][$key]['form'] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['ief-form', 'ief-form-row']],
            // Used by Field API and controller methods to find the relevant
            // values in $form_state.
            '#parents' => array_merge($parents, ['entities', $key, 'form']),
            // Store the entity on the form, later modified in the controller.
            '#entity' => $entity,
            // Identifies the IEF widget to which the form belongs.
            '#ief_id' => $this->getIefId(),
            // Identifies the table row to which the form belongs.
            '#ief_row_delta' => $key,
          ];
          $this->buildRemoveForm($element['entities'][$key]['form']);
        }
      }
      else {
        $row = &$element['entities'][$key];
        $row['title'] = [];
        $row['delta'] = [
          '#type' => 'weight',
          '#delta' => $weight_delta,
          '#default_value' => $value['weight'],
          '#attributes' => ['class' => ['ief-entity-delta']],
        ];
        // Add an actions container with edit and delete buttons for the entity.
        $row['actions'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['ief-entity-operations']],
        ];

        // Make sure entity_access is not checked for unsaved entities.
        $entity_id = $entity->id();
        if (empty($entity_id) || $entity->access('update')) {
          $row['actions']['ief_entity_edit'] = [
            '#type' => 'submit',
            '#value' => $this->t('Edit'),
            '#name' => 'ief-' . $this->getIefId() . '-entity-edit-' . $key,
            '#limit_validation_errors' => [],
            '#ajax' => [
              'callback' => 'inline_entity_form_get_element',
              'wrapper' => $wrapper,
            ],
            '#submit' => ['inline_entity_form_open_row_form'],
            '#ief_row_delta' => $key,
            '#ief_row_form' => 'edit',
          ];
        }

        // Add the duplicate button, if allowed.
        if ($settings['allow_duplicate'] && !$cardinality_reached && $entity->access('create')) {
          $row['actions']['ief_entity_duplicate'] = [
            '#type' => 'submit',
            '#value' => $this->t('Duplicate'),
            '#name' => 'ief-' . $this->getIefId() . '-entity-duplicate-' . $key,
            '#limit_validation_errors' => [array_merge($parents, ['actions'])],
            '#ajax' => [
              'callback' => 'inline_entity_form_get_element',
              'wrapper' => $wrapper,
            ],
            '#submit' => ['inline_entity_form_open_row_form'],
            '#ief_row_delta' => $key,
            '#ief_row_form' => 'duplicate',
          ];
        }

        // If 'allow_existing' is on, the default removal operation is unlink
        // and the access check for deleting happens inside the controller
        // removeForm() method.
        if (empty($entity_id) || $settings['allow_existing'] || $entity->access('delete')) {
          $row['actions']['ief_entity_remove'] = [
            '#type' => 'submit',
            '#value' => $this->t('Remove'),
            '#name' => 'ief-' . $this->getIefId() . '-entity-remove-' . $key,
            '#limit_validation_errors' => [],
            '#ajax' => [
              'callback' => 'inline_entity_form_get_element',
              'wrapper' => $wrapper,
            ],
            '#submit' => ['inline_entity_form_open_row_form'],
            '#ief_row_delta' => $key,
            '#ief_row_form' => 'remove',
            '#access' => !$element['#translating'],
          ];
        }
      }
    }

    // When in translation, the widget only supports editing (translating)
    // already added entities, so there's no need to show the rest.
    if ($element['#translating']) {
      if (empty($entities)) {
        // There are no entities available for translation, hide the widget.
        $element['#access'] = FALSE;
      }
      return $element;
    }

    if ($cardinality > 1) {
      // Add a visual cue of cardinality count.
      $message = $this->t('You have added @entities_count out of @cardinality_count allowed @label.', [
        '@entities_count' => $entities_count,
        '@cardinality_count' => $cardinality,
        '@label' => $labels['plural'],
      ]);
      $element['cardinality_count'] = [
        '#markup' => '<div class="ief-cardinality-count">' . $message . '</div>',
      ];
    }
    // Do not return the rest of the form if cardinality count has been reached.
    if ($cardinality_reached) {
      return $element;
    }

    $create_bundles = $this->getCreateBundles();
    $create_bundles_count = count($create_bundles);
    $allow_new = $settings['allow_new'] && !empty($create_bundles);
    // If the field is required and empty try to open one of the forms.
    if (empty($entities) && $this->fieldDefinition->isRequired()) {
      if ($settings['allow_existing'] && !$allow_new) {
        $form_state->set(['inline_entity_form', $this->getIefId(), 'form'], 'ief_add_existing');
      }
      elseif ($create_bundles_count == 1 && $allow_new && !$settings['allow_existing']) {
        $bundle = reset($target_bundles);

        // The parent entity type and bundle must not be the same as the inline
        // entity type and bundle, to prevent recursion.
        $parent_entity_type = $this->fieldDefinition->getTargetEntityTypeId();
        $parent_bundle = $this->fieldDefinition->getTargetBundle();
        if ($parent_entity_type != $target_type || $parent_bundle != $bundle) {
          $form_state->set(['inline_entity_form', $this->getIefId(), 'form'], 'add');
          $form_state->set(['inline_entity_form', $this->getIefId(), 'form settings'], [
            'bundle' => $bundle,
          ]);
        }
      }
    }

    // If no form is open, show buttons that open one.
    $open_form = $form_state->get(['inline_entity_form', $this->getIefId(), 'form']);

    /**
     * Modifications:
     * Everything above is copied exactly from InlineEntityFormComplex and
     * most everything below is also copied but just moved around such that
     * the add existing input is open by default.
     */

    // Make a delta key bigger than all existing ones, without assuming that
    // the keys are strictly consecutive.
    $new_key = $entities ? max(array_keys($entities)) + 1 : 0;

    if (empty($open_form)) {
      if ($settings['allow_existing']) {
        $element['form'] = [
          '#type' => 'fieldset',
          '#attributes' => ['class' => ['ief-form', 'ief-form-bottom']],
          // Identifies the IEF widget to which the form belongs.
          '#ief_id' => $this->getIefId(),
          // Used by Field API and controller methods to find the relevant
          // values in $form_state.
          '#parents' => array_merge($parents, [$new_key]),
          '#entity_type' => $target_type,
          '#ief_labels' => $this->getEntityTypeLabels(),
          '#match_operator' => $this->getSetting('match_operator'),
        ];
        $element['form'] += $this->referenceForm($element['form'], $form_state, $create_bundles);

        /**
         * Modifications to add existing:
         * Hide input label;
         * Change to not required;
         * Remove cancel button;
         * Replace submit function with InlineEntityFormComplexOpen::reference_form_submit;
         */
        unset($element['form']['entity_id']['#title']);
        unset($element['form']['entity_id']['#required']);
        $element['form']['#process'][] = [get_class($this), 'hideCancel'];
        $key = array_search('inline_entity_form_reference_form_submit', $element['form']['#ief_element_submit']);
        if ($key !== FALSE) {
          $element['form']['#ief_element_submit'][$key] = [get_class($this), 'reference_form_submit'];
        }
      }

      // The user is allowed to create an entity of at least one bundle.
      if ($allow_new) {
        $element['actions'] = [
          '#attributes' => ['class' => ['container-inline']],
          '#type' => 'container',
          '#weight' => 100,
        ];

        // Let the user select the bundle, if multiple are available.
        if ($create_bundles_count >= 1) {
          $bundles = [];
          foreach ($this->entityTypeBundleInfo->getBundleInfo($target_type) as $bundle_name => $bundle_info) {
            if (in_array($bundle_name, $create_bundles)) {
              $bundles[$bundle_name] = $bundle_info['label'];
            }
          }
          asort($bundles);

          $element['actions']['bundle'] = [
            '#type' => 'select',
            '#options' => $bundles,
          ];
        }
        if ($create_bundles_count == 1) {
          $element['actions']['bundle']['#attributes'] = ['class' => ['visually-hidden']];
        }

        $element['actions']['ief_add'] = [
          '#type' => 'submit',
          '#value' => $this->t('Create new @type_singular', ['@type_singular' => $labels['singular']]),
          '#name' => 'ief-' . $this->getIefId() . '-add',
          '#limit_validation_errors' => [array_merge($parents, ['actions'])],
          '#ajax' => [
            'callback' => 'inline_entity_form_get_element',
            'wrapper' => $wrapper,
          ],
          '#submit' => ['inline_entity_form_open_form'],
          '#ief_form' => 'add',
        ];

        /**
         * Modifications to add new:
         * Change container to fieldset;
         * Change "Add new.." text to "Create new..";
         */
        $element['actions']['#type'] = 'fieldset';
        if ($create_bundles_count == 1) {
          $element['actions']['ief_add']['#value'] = $this->t('Create new @type_singular', ['@type_singular' => reset($bundles)]);
        }
      }
    }
    else {
      // There's a form open, show it.
      if ($form_state->get(['inline_entity_form', $this->getIefId(), 'form']) == 'add') {
        $element['form'] = [
          '#type' => 'fieldset',
          '#attributes' => ['class' => ['ief-form', 'ief-form-bottom']],
          'inline_entity_form' => $this->getInlineEntityForm(
            'add',
            $this->determineBundle($form_state),
            $parent_langcode,
            $new_key,
            array_merge($parents, [$new_key])
          ),
        ];
        $element['form']['inline_entity_form']['#process'] = [
          ['\Drupal\inline_entity_form\Element\InlineEntityForm', 'processEntityForm'],
          [get_class($this), 'addIefSubmitCallbacks'],
          [get_class($this), 'buildEntityFormActions'],
        ];
      }
    }

    return $element;
  }

  /**
   * Helper method for reference form for future modifications.
   *
   * @param $reference_form
   * @param $form_state
   * @param $bundles
   *
   * @return array
   */
  private function referenceForm($reference_form, &$form_state, $bundles) {
    return inline_entity_form_reference_form($reference_form, $form_state);
  }

  /**
   * Checks for non-empty input value before passing onto original submit
   * function.
   *
   * This is only necessary because changing the add existing autocomplete
   * to not required allows submitting an empty value and the original
   * submit function does not check for an empty value.
   *
   * @param $form
   *   The complete parent form.
   * @param $form_state
   *   The form state of the parent form.
   *
   * @see inline_entity_form_reference_form_submit()
   */
  public static function reference_form_submit($reference_form, FormStateInterface $form_state) {
    $form_values = NestedArray::getValue($form_state->getValues(), $reference_form['#parents']);
    if (!empty($form_values['entity_id'])) {
      inline_entity_form_reference_form_submit($reference_form, $form_state);
    }
  }

  /**
   * Filter out bundles that are not allowed in widget config.
   *
   * @param $bundles
   *
   * @return mixed
   */
  protected function filterBundles($bundles) {
    $bundle_limit = $this->getSetting('bundle_limit');
    if (empty($bundle_limit)) {
      return $bundles;
    }
    foreach ($bundles as $key => $bundle) {
      if ($bundle_limit[$bundle] === $bundle) {
        continue;
      }
      unset($bundles[$key]);
    }
    return array_values($bundles);
  }

  /**
   * { @inheritdoc }
   */
  public function getCreateBundles() {
    $bundles = parent::getCreateBundles(); // TODO: Change the autogenerated stub
    return $this->filterBundles($bundles);
  }

  /**
   * Disable editing.
   *
   * @param $element
   * @param int $entity_count
   *
   * @return void|null
   */
  protected function disableEdit(&$element, int $entity_count) {
    if (empty($element['entities'])) {
      return NULL;
    }

    for ($i = 0; $i < $entity_count; $i++) {
      $entity = &$element['entities'][$i];
      if ($entity['#needs_save'] === FALSE) {
        unset($entity['actions']['ief_entity_edit']);
      }
    }
  }

  /**
   * Helper method to fetch array of content types with labels.
   *
   * @return array
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getContentTypeOptions() {
    $options = [];
    $node_types = $this->entityTypeManager->getStorage('node_type')->loadByProperties([]);
    foreach ($node_types as $key => $node_type) {
      $options[$key] = $node_type->label();
    }
    return $options;
  }

}
