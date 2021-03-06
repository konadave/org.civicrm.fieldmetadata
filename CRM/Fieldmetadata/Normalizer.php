<?php

/**
 * Class CRM_Fieldmetadata_Normalizer
 *
 * This is the base class for all field-meta-data normalizer classes
 *
 */

abstract class CRM_Fieldmetadata_Normalizer {


  /**
   * Entry point for Normalization
   *
   * @param $data - The data returned by the Fetcher class
   * @param $params
   * @return mixed
   */
  function normalize($data, $params) {
    $metadata = $this->normalizeData($data, $params);
    $this->orderFields($metadata['fields']);
    $this->setVisibilityForFields($metadata['fields']);
    $this->fetchAndNormalizeDates($metadata['fields']);
    return $metadata;
  }

  /**
   * Sort the fields by the order key.
   *
   * @param $fields
   */
  function orderFields(&$fields) {
    foreach($fields as $field) {
      if (sizeof($field['options'] > 1)) {
        uasort($field['options'], array($this, "compareOrder"));
      }
    }
    uasort($fields, array($this, "compareOrder"));
  }

  /**
   * Loops through fields and normalizes the visibility values:
   *
   * - admin: only admins can use this field for input or view its value (admins
   *   can access fields with any visibility)
   * - public: anyone can use this field for input; fields which don't have a
   *   visibility property will return this
   * - public_and_listings: anyone can use this field for input or view
   *   its value (e.g., in a profile)
   * - user: only the user this field is about can use this field for input
   *
   * Values added to the "visibility" option group can also be returned (by
   * name). Any other strings will be returned as-is (e.g., if someone were to
   * hack civicrm_uf_field.visibility).
   *
   * @param array $fields
   */
  function setVisibilityForFields(&$fields) {
    $api = civicrm_api3('OptionValue', 'get', array(
      'option_group_id' => 'visibility',
    ));
    // Put options into an array: (int-like string) id => (string) name
    $options = array_column($api['values'], 'name', 'value');

    foreach ($fields as &$field) {
      if (empty($field['visibility']) || $field['visibility'] === 'Public Pages') {
        $field['visibility'] = 'public';
      }
      elseif ($field['visibility'] === 'Public Pages and Listings') {
        $field['visibility'] = 'public_and_listings';
      }
      elseif ($field['visibility'] === 'User and User Admin Only') {
        $field['visibility'] = 'user';
      }
      elseif (is_int($field['visibility']) || ctype_digit($field['visibility'])) {
        $visibilityId = (string) $field['visibility'];
        $field['visibility'] = $options[$visibilityId];
      }
    }
  }

  /**
   * This will go through and add minDate and maxDate keys
   * to each date field found. It will work for both custom
   * fields and core fields.
   *
   * @param array $fields
   * @return void
   */
  function fetchAndNormalizeDates(&$fields) {
   
    static $contactTypes = [];
    static $dates = [];
    static $formats = [];

    $dateWidgets = array('Date', 'DateTime', 'Select Date', 'crm-ui-datepicker');
    $thisYear = date('Y');

    foreach ($fields as &$field) {
      // skip non-date fields
      if (!in_array($field['widget'], $dateWidgets)) {
        continue;
      }

      // only do the lookup once
      if (empty($dates[$field['name']])) {

        // default to 20/20
        $start = $end = 20;

        // custom fields are little bit easier
        if (strpos($field['name'], 'custom_') === 0) {
          $customField = civicrm_api3('CustomField', 'getsingle', array(
            'id' => substr($field['name'], 7),
            'return' => 'start_date_years, end_date_years',
          ));
          $start = $customField['start_date_years'];
          $end = $customField['end_date_years'];
        }
        // core fields
        else {
          /*  we have to look up the field to determine it's formatType.
              $field['entity'] provied by the fetcher may not match the
              entity that must be passed to the api. e.g. $field['entity']
              for birth_date is Individual. Therefore, we need to make
              sure that any contact type passed in entity is normalized to
              Contact for the api call.
          */
          if (empty($contactTypes)) {
            $api = civicrm_api3('ContactType', 'get', array(
              'sequential' => 1,
              'is_active' => 1,
            ));
            foreach ($api['values'] as $contactType) {
              $contactTypes[] = $contactType['name'];
            }
          }
          $entity = in_array($field['entity'], $contactTypes) ? 'Contact' : $field['entity'];

          try {
            $api = civicrm_api3($entity, 'getfield', array(
              'name' => $field['name'],
              'action' => 'get',
            ));
            
            if (!empty($api['values']['html']['formatType'])) {
            
              $formatType = $api['values']['html']['formatType'];
              
              // only look-up the date format once
              if (empty($formats[$formatType])) {
                $params = array('name' => $formatType);
                $values = array();
                CRM_Core_DAO::commonRetrieve('CRM_Core_DAO_PreferencesDate', $params, $values);
                $formats[$formatType] = $values;
              }
              
              $start = $formats[$formatType]['start'];
              $end = $formats[$formatType]['end'];
            }
          }
          catch (CiviCRM_API3_Exception $e) {}
        }

        $dates[$field['name']]['minDate'] = ($thisYear - $start) . '-01-01';
        $dates[$field['name']]['maxDate'] = ($thisYear + $end) . '-12-31';
      }
      
      $field['minDate'] = $dates[$field['name']]['minDate'];
      $field['maxDate'] = $dates[$field['name']]['maxDate'];
    }
  }

  /**
   * Utility function for use inside uasort, called from orderFields()
   *
   * @param $a
   * @param $b
   * @return int
   */
  function compareOrder($a, $b) {
    if ($a['order'] == $b['order']) {
      return 0;
    }
    return ($a['order'] < $b['order']) ? -1 : 1;
  }



  /**
   * Returns an array with all the keys needed
   * for a field
   *
   * @return array
   */
  function getEmptyField() {
    return array(
      "entity" => null,
      "label" => "",
      "name" => "",
      "order" => 0,
      "required" => false,
      "default" => "",
      "options" => array(),
      "price" => array(),
      "displayPrice" => false,
      "quantity" => false,
      "preText" => "",
      "postText" => "",
    );
  }


  /**
   * Returns an array with all the needed keys for
   * a field option.
   *
   * @return array
   */
  function getEmptyOption() {
    return array(
      "label" => "",
      "name" => "",
      "value" => "",
      "order" => 0,
      "required" => false,
      "default" => false,
      "price" => false,
      "preText" => "",
      "postText" => "",
    );
  }

  /**
   * Where CiviCRM gives "", NULL, FALSE, "0", 0, etc. to represent FALSE, this
   * method saves the day by converting the value to a string.
   *
   * When output from normalizers is predictable and consistent, clients have
   * less type juggling to do.
   *
   * This method might be out of place here. It may be more appropriate to have
   * a Field class than to address this level of detail here.
   *
   * @param mixed $value
   * @return string "1" or "0"
   */
  function normalizeBoolean($value) {
    return $value ? "1" : "0";
  }

  /**
   * Updates the Widget type based on context
   *
   * @param $fields
   * @param $context
   * @throws CRM_Core_Exception
   */
  function setWidgetTypesByContext(&$fields, $context) {
    $getWidget = "get{$context}Widget";
    if (method_exists($this, $getWidget)) {
      foreach($fields as &$field) {
        $field['widget'] = $this->$getWidget($field['widget']);
        //todo: Run a hook so other extensions can update the widget type.
      }
    } else {
      //todo: Create a hook that registers context
      throw new CRM_Core_Exception("Cannot Set Context", 6);
    }
  }

  /**
   * Maps a field html_type to an angular widget
   *
   * TODO: This method is doing too much. For example, "Date" and "Select Date"
   * are obviously the same type; the normalizer layer should consolidate them
   * irrespective of the context (Angular vs. other), but there isn't an obvious
   * place to put this at the moment; some refactoring is in order.
   *
   * @param $htmlType
   * @return bool|string|CRM_Case_Form_CustomData
   * @throws CRM_Core_Exception
   */
  function getAngularWidget($htmlType) {
    switch($htmlType) {
      //crm-ui-select
      case 'Select State/Province':
        //return "crm-render-state";
        return "crm-render-select";
      case 'Select Country':
        //return "crm-render-country";
        return "crm-render-select";
      case 'RichTextEditor':
        return "crm-ui-richtext";
      case 'advcheckbox':
        return "crm-render-checkbox";
      case 'ChainSelect':
        return "crm-render-chain-select";
      case 'Date':
      case 'DateTime':
      case 'Select Date':
        return "crm-ui-datepicker";
      case 'Autocomplete-Select':
        return "crm-entityref";
      default:
        return "crm-render-". strtolower($htmlType);
    }
  }

  /**
   * Function Specific to normalizing for a given entity
   *
   * @param $data
   * @param $params
   * @return mixed
   */
  abstract protected function normalizeData(&$data, $params);

  /**
   * Instantiation function to get an instance of a Normalizer
   * sub-class for a given entity
   *
   * @param $entity - The Name of the entity for which we are trying to normalize metadata
   * @return subclass of CRM_Fieldmetadata_Normalizer for given entity
   * @throws CRM_Core_Exception
   */
  public static function &getInstanceForEntity($entity) {
    // key: Entity => value: PHP class
    $normalizerClasses = array();
    CRM_Fieldmetadata_Hook::registerNormalizer($normalizerClasses);
    $class = CRM_Utils_Array::value($entity, $normalizerClasses);

    if (!$class) {
      // throw exception indicating no normalizer
      // has been registered for this entity
      throw new CRM_Core_Exception("No Normalizer class has been registered for '{$entity}'", 3);
    }

    $normalizer = new $class;

    if (!is_subclass_of($normalizer, "CRM_Fieldmetadata_Normalizer")) {
      // throw exception indicating the provided class
      // does not extend the required base class
      throw new CRM_Core_Exception("Fetcher class '{$class}' does not extend the 'CRM_Fieldmetadata_Normalizer' base class", 4);
    }

    return $normalizer;
  }

  /**
   * Boolean fields in CiviCRM do not use an option group, so we have to
   * simulate the options.
   *
   * @param array $customField
   *   Looks like the result of api.CustomField.getsingle.
   * @return array
   */
  protected function mockBooleanOptions(array $customField) {
    $result = array();
    $fieldOptions = array(
      array(
        'label' => 'Yes',
        'weight' => 0,
        'value' => "1",
      ),
      array(
        'label' => 'No',
        'weight' => 1,
        'value' => "0",
      ),
    );
    foreach ($fieldOptions as $fieldOption) {
      $option = $this->getEmptyOption();
      $option['is_active'] = $this->normalizeBoolean(1);
      $option['label'] = $fieldOption['label'];
      $option['order'] = $fieldOption['weight'];
      $option['value'] = $fieldOption['value'];

      $isDefault = $customField['default_value'] === $option['value'];
      $option['default'] = $this->normalizeBoolean($isDefault);
      $option['name'] = 'custom_' . $customField['id'] . '[' . $fieldOption['value'] . ']';

      $result[] = $option;
    }

    return $result;
  }

}