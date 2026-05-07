<?php

namespace ORCA\OrcaCallListManager;

trait ModuleUtils {

    private $_metadata = [];

    public function getCallListConfig($project_id, $config_index = 0) {
        global $Proj;

        $call_lists = $this->getSubSettings('dashboard_config');
        $list_config = $call_lists[$config_index] ?? null;

        if (!$list_config) {
            return [
                'errors' => ['Call list configuration not found.'],
                'displayFields' => []
            ];
        }

        $config = [
            'configIndex' => $config_index,
            'displayTitle' => $list_config['display_title'] ?: 'Orca Call List',
            'showEntriesNumber' => (int)($list_config['display_entries_number'] ?: 10),
            'showEntriesOptions' => [10, 25, 50, 100, 150, 200, 500],
            'preventEmptySearch' => $list_config['prevent_empty_search'] === true,
            'hasRepeatingForms' => $Proj->hasRepeatingForms(),
            'errors' => [],
            'warnings' => []
        ];

        $primary_filter_field = $list_config['primary_filter_field'] ?? 'contact_result';
        $callback_alert_field = $list_config['callback_alert_field'] ?? null;

        $config['primaryFilterField'] = $primary_filter_field;
        $config['callbackAlertField'] = $callback_alert_field;

        $config['contactAttempts'] = [
            'display' => $list_config['display_contact_attempts'] === true,
            'fieldName' => $list_config["call_date_field"],
            'ranges' => $this->getContactAttemptRanges($list_config)
        ];
        //Validate contact attempt ranges
        foreach (['AM', 'PM', 'EVE'] as $key) {
            $value = $list_config['range_' . strtolower($key)] ?? '';
            if (!empty($value) && !preg_match('/^\d{2}:\d{2}-\d{2}:\d{2}$/', $value)) {
                $config['errors'][] = "Invalid {$key} time range: '{$value}'. Expected format: HH:MM-HH:MM (e.g., 00:00-11:59). Using default.";
            }
        }

        $config['metadata'] = $this->buildProjectMetadata($Proj);
        $config['primaryFilterMetadata'] = $this->buildPrimaryFilterMetadata($Proj, $primary_filter_field);
        $config['filterField'] = $this->buildFilterFieldConfig($Proj, $list_config, $config['metadata']);
        $config['displayFields'] = $this->buildDisplayFieldsConfig($Proj, $config, $list_config);
        $this->validateContactAttemptsConfig($Proj, $config);
        if (!empty($callback_alert_field)) {
            $config['hasCallbackAlertField'] = $this->hasFieldInDisplay($callback_alert_field, $config['displayFields']);
        } else {
            $config['hasCallbackAlertField'] = false;
        }

        if (empty($config['displayFields'])) {
            $config['errors'][] = 'No display fields configured for this call list.';
        }

        return $config;
    }

    public function handleGetCallListData($project_id, $payload) {
        global $Proj;

        $config_index = $payload['configIndex'] ?? 0;
        $call_lists = $this->getSubSettings('dashboard_config');
        $list_config = $call_lists[$config_index] ?? null;

        if (!$list_config) {
            return ['success' => false, 'error' => 'Call list not found'];
        }

        $filters = $payload['filters'] ?? [];
        $filter_params = [
            'selectedPrimaryFilters' => $filters['primaryFilterIds'] ?? [],
            'extraFilterValue' => $filters['extraFilterValue'] ?? '',
        ];

        // Check prevent empty search
        if ($list_config['prevent_empty_search'] === true) {
            $has_filters = !empty($filter_params['selectedPrimaryFilters']) || $filter_params['extraFilterValue'] !== '';
            if (!$has_filters) {
                return ['success' => true, 'records' => [], 'totalRecords' => 0];
            }
        }

        $primary_filter_field = $list_config['primary_filter_field'] ?? 'contact_result';
        $callback_alert_field = $list_config['call_back_date_time'] ?? null;

        $metadata = $this->buildProjectMetadata($Proj);
        $primary_filter_metadata = $this->buildPrimaryFilterMetadata($Proj, $primary_filter_field);
        $config = ['metadata' => $metadata, 'errors' => []];
        $display_fields = $this->buildDisplayFieldsConfig($Proj, $config, $list_config);
        $data_fields = $this->getDataFieldsList($Proj, $display_fields, $list_config, $primary_filter_field, $callback_alert_field);
        $dag_config = $this->getDagConfig($Proj);

        $data = \REDCap::getData([
            'return_format' => 'array',
            'fields' => array_unique($data_fields),
            'groups' => $dag_config['userDag'],
            'exportDataAccessGroups' => $dag_config['exportDataAccessGroups']
        ]);

        $results = $this->processRecords($data, $Proj, $metadata, $display_fields, $primary_filter_metadata, $filter_params, $list_config, $primary_filter_field, $callback_alert_field);

        return ['success' => true, 'records' => $results, 'totalRecords' => count($results)];
    }

    public function handleGetPageState($config_index = 0) {
        $page_state = $this->getSavedDataByName('config_state_' . $config_index);
        if (empty($page_state)) {
            $page_state = self::DEFAULT_EMPTY_PAGESTATE;
        }
        return [
            'success' => true,
            'checkboxSelections' => $page_state['checkboxSelections'] ?? [],
            'dropdownSelections' => $page_state['dropdownSelections'] ?? []
        ];
    }

    public function handleSavePageState($payload) {
        $config_index = $payload['configIndex'] ?? 0;
        $page_state = [
            'checkboxSelections' => $payload['checkboxSelections'] ?? [],
            'dropdownSelections' => $payload['dropdownSelections'] ?? []
        ];
        $success = $this->saveDataWithName($page_state, 'config_state_' . $config_index, true);
        return ['success' => $success];
    }

    private function getFieldValues($field_name, $Proj) {
        $metadata = $this->buildProjectMetadata($Proj);
        if (isset($metadata['fields'][$field_name]['values'])) {
            return $metadata['fields'][$field_name]['values'];
        }
        else if ($metadata['fields'][$field_name]['type'] === 'sql') {
            return $this->getSqlValuesFor($Proj, $field_name);
        }
        return [];
    }

    private function buildPrimaryFilterMetadata($Proj, $primary_filter_field) {
        $field_values = $this->getFieldValues($primary_filter_field, $Proj) ?? [];
        $colors = [
            '1' => 'cl-green', '2' => 'cl-blue', '3' => 'cl-red',
            '4' => 'cl-purple', '5' => 'cl-yellow', '6' => 'cl-orange'
        ];
        $metadata = [];
        foreach ($field_values as $key => $label) {
            $metadata[$key] = [
                'key' => (string)$key,
                'label' => $this->truncate($label),
                'status' => $colors[$key] ?? null
            ];
        }
        return $metadata;
    }

    private function buildProjectMetadata($Proj) {
        if ($this->_metadata === null) {
            $this->_metadata = [
                'fields' => [],
                'forms' => [],
                'formStatuses' => [0 => 'Incomplete', 1 => 'Unverified', 2 => 'Complete'],
                'dateFieldFormats' => ['date_mdy' => 'm/d/Y', 'datetime_mdy' => 'm/d/Y G:i'],
                'unstructuredFieldTypes' => ['text', 'textarea'],
                'customDictionaryValues' => [
                    'yesno' => ['1' => 'Yes', '0' => 'No'],
                    'truefalse' => ['1' => 'True', '0' => 'False']
                ]
            ];

            foreach ($Proj->forms as $form_name => $form_data) {
                $this->_metadata['forms'][$form_name] = ['eventInfo' => []];
                foreach ($form_data['fields'] as $field_name => $field_label) {
                    $field_type = $Proj->metadata[$field_name]["element_type"];

                    $this->_metadata['fields'][$field_name] = [
                        'form' => $form_name,
                        "type" => $field_type,
                    ];

                    // lets also get the values list for all structured fields
                    if ($Proj->isFormStatus($field_name)) {
                        // special value handling for form statuses
                        $this->_metadata["fields"][$field_name]["label"] = $this->truncate($form_data["menu"] . " Status");
                        $this->_metadata["fields"][$field_name]["values"] = $this->_metadata["formStatuses"];
                    } else {
                        $this->_metadata["fields"][$field_name]["label"] = $this->truncate($field_label);
                        switch ($field_type) {
                            case "select":
                            case "radio":
                            case "checkbox":
                            $this->_metadata["fields"][$field_name]["values"] = $this->getDictionaryValuesFor($field_name);
                                break;
                            case "yesno":
                            case "truefalse":
                            $this->_metadata["fields"][$field_name]["values"] = $this->_metadata["customDictionaryValues"][$field_type];
                                break;
                            case "sql":
                                // this is deferred, in case a project has a lot of sql fields
                                break;
                            default: break;
                        }
                    }
                }
            }

            foreach ($Proj->eventsForms as $event_id => $event_forms) {
                foreach ($event_forms as $form_name) {
                    $this->_metadata['forms'][$form_name]['eventInfo'][$event_id] = ['eventId' => $event_id, 'repeating' => false];
                }
            }

            if ($Proj->hasRepeatingForms()) {
                foreach ($Proj->getRepeatingFormsEvents() as $event_id => $event_forms) {
                    if ($event_forms === 'WHOLE') {
                        foreach ($Proj->eventsForms[$event_id] as $form_name) {
                            $this->_metadata['forms'][$form_name]['eventInfo'][$event_id]['repeating'] = true;
                        }
                    } else {
                        foreach ($event_forms as $form_name => $value) {
                            $this->_metadata['forms'][$form_name]['eventInfo'][$event_id]['repeating'] = true;
                        }
                    }
                }
            }
        }
        return $this->_metadata;
    }

    private function buildFilterFieldConfig($Proj, $list_config, $metadata) {
        $filter_field = $list_config['display_filter_fields'] ?? null;
        if (empty($filter_field) || !isset($Proj->metadata[$filter_field])) return null;

        $field_values = $this->getFieldValues($filter_field, $Proj);

        return [
            'fieldName' => $filter_field,
            'fieldLabel' => $this->truncate($this->getDictionaryLabelFor($filter_field)),
            'fieldValues' => $field_values
        ];
    }

    private function buildDisplayFieldsConfig($Proj, &$config, $list_config) {
        $display_fields = [];
        $field_index = 0;

        $fields = $list_config['display_fields'] ?? [];

        foreach ($fields as $display_field) {
            if (empty($display_field['display_field_name'])) continue;

            $field_name = $display_field['display_field_name'];
            if (!isset($config['metadata']['fields'][$field_name])) {
                $config['errors'][] = "Field '{$field_name}' not found in project.";
                continue;
            }

            $form_name = $config['metadata']['fields'][$field_name]['form'];
            $field_meta = $Proj->metadata[$field_name] ?? [];

            $field_config = [
                'fieldName' => $field_name,
                'fieldIndex' => $field_index,
                'display' => true,
                'isFormStatus' => $Proj->isFormStatus($field_name),
                'elementType' => $field_meta['element_type'] ?? null,
                'elementValidationType' => $field_meta['element_validation_type'] ?? null,
                'formName' => $form_name,
                'sortable' => true,
                'sortDirection' => $display_field['display_field_sort_direction'] ?? 'NONE',
                'sortPriority' => is_numeric($display_field['display_field_sort_priority']) ? (int)$display_field['display_field_sort_priority'] : null
            ];

            if ($field_config['isFormStatus']) {
                $field_config['label'] = ($Proj->forms[$form_name]['menu'] ?? $form_name) . ' Status';
            } else {
                $field_config['label'] = $this->truncate($this->getDictionaryLabelFor($field_name));
            }

            $display_fields[] = $field_config;
            $field_index++;
        }

        return $display_fields;
    }

    private function validateContactAttemptsConfig($Proj, &$config) {
        if (!$config['contactAttempts']['display']) return;

        $field = $config['contactAttempts']['fieldName'];
        if (!isset($config['metadata']['fields'][$field])) {
            $config['errors'][] = "Contact attempts field '{$field}' not found.";
            $config['contactAttempts']['display'] = false;
            return;
        }

        $form = $config['metadata']['fields'][$field]['form'];
        $has_repeating = false;
        foreach ($config['metadata']['forms'][$form]['eventInfo'] ?? [] as $event_info) {
            if ($event_info['repeating']) { $has_repeating = true; break; }
        }

        if (!$has_repeating) {
            $config['errors'][] = "Contact attempts field '{$field}' must be on a repeating form.";
            $config['contactAttempts']['display'] = false;
            return;
        }

        $validation = $Proj->metadata[$field]['element_validation_type'] ?? '';
        if ($validation !== 'datetime_mdy') {
            $config['errors'][] = "Contact attempts field '{$field}' must have 'datetime_mdy' validation.";
            $config['contactAttempts']['display'] = false;
            return;
        }

        $config['displayFields'][] = [
            'fieldName' => 'contact_attempts',
            'fieldIndex' => count($config['displayFields']),
            'display' => true,
            'isVirtual' => true,
            'label' => 'Contact Attempts',
            'sortable' => true
        ];
    }

    private function hasFieldInDisplay($field_name, $display_fields) {
        foreach ($display_fields as $field) {
            if ($field['fieldName'] === $field_name) return true;
        }
        return false;
    }


    private function getDataFieldsList($Proj, $display_fields, $list_config, $primary_filter_field, $callback_alert_field) {
        $data_fields = [$list_config["call_date_field"], $primary_filter_field];

        if (!empty($callback_alert_field)) {
            $data_fields[] = $callback_alert_field;
        }

        $filter_field = $list_config['display_filter_fields'] ?? null;
        if (!empty($filter_field)) {
            $data_fields[] = $filter_field;
        }

        foreach ($display_fields as $field) {
            if (!empty($field['fieldName']) && !($field['isVirtual'] ?? false)) {
                $data_fields[] = $field['fieldName'];
                if (!empty($field['formName'])) {
                    $data_fields[] = $field['formName'] . '_complete';
                }
            }
        }

        return $data_fields;
    }

    private function getDagConfig($Proj) {
        $config = ['userDag' => null, 'exportDataAccessGroups' => false];
        if (count($Proj->getGroups()) > 0) {
            $user_rights = \REDCap::getUserRights(USERID);
            $user_dag = $user_rights[USERID]['group_id'] ?? null;
            if (!empty($user_dag)) {
                $config['userDag'] = \REDCap::getGroupNames(true, $user_dag);
                $config['exportDataAccessGroups'] = true;
            }
        }
        return $config;
    }

    private function processRecords($data, $Proj, $metadata, $display_fields, $primary_filter_metadata, $filter_params, $list_config, $primary_filter_field, $callback_alert_field) {
        $results = [];

        $primary_filter_form = $metadata['fields'][$primary_filter_field]['form'] ?? null;
        $primary_filter_form_events = $primary_filter_form
            ? array_reverse($metadata['forms'][$primary_filter_form]['eventInfo'] ?? [], true)
            : [];

        $filter_field = $list_config['display_filter_fields'] ?? null;
        $contact_attempts_display = $list_config['display_contact_attempts'] === true;
        $contact_attempts_ranges = $this->getContactAttemptRanges($list_config);

        foreach ($data as $record_id => $record) {
            $record_info = [
                'recordId' => (string)$record_id,
                'dashboardUrl' => APP_PATH_WEBROOT . 'DataEntry/record_home.php?' . http_build_query([
                        'pid' => $this->getProjectId(),
                        'id' => $record_id
                    ]),
                'primaryFilter' => ['raw' => null, 'status' => null],
                'fields' => []
            ];

            $record_info['primaryFilter'] = $this->getFieldValueFromRecord(
                $record, $primary_filter_field, $primary_filter_form, $primary_filter_form_events, $Proj
            );
            if ($record_info['primaryFilter']['raw'] !== null) {
                $raw_value = $record_info['primaryFilter']['raw'];
                if (is_array($raw_value)) {
                    // For checkboxes, find the first checked option's status
                    foreach ($raw_value as $option_key => $checked) {
                        if ($checked === '1' && isset($primary_filter_metadata[$option_key])) {
                            $record_info['primaryFilter']['status'] = $primary_filter_metadata[$option_key]['status'] ?? null;
                            break;
                        }
                    }
                } else {
                    $record_info['primaryFilter']['status'] = $primary_filter_metadata[$raw_value]['status'] ?? null;
                }
            }

            $extra_filter_raw = null;
            if (!empty($filter_field) && isset($metadata['fields'][$filter_field])) {
                $filter_form = $metadata['fields'][$filter_field]['form'];
                $filter_form_events = array_reverse($metadata['forms'][$filter_form]['eventInfo'] ?? [], true);
                $extra_filter_result = $this->getFieldValueFromRecord($record, $filter_field, $filter_form, $filter_form_events, $Proj);
                $extra_filter_raw = $extra_filter_result['raw'];
            }

            foreach ($display_fields as $field_config) {
                $field_name = $field_config['fieldName'];
                if ($field_config['isVirtual'] ?? false) continue;
                $form_name = $field_config['formName'] ?? null;
                if (!$form_name) continue;

                $form_events = array_reverse($metadata['forms'][$form_name]['eventInfo'] ?? [], true);
                $field_result = $this->processDisplayField($record, $field_name, $form_name, $form_events, $field_config, $Proj, $metadata, $callback_alert_field);
                $record_info['fields'][$field_name] = $field_result;
            }


            if ($contact_attempts_display) {
                $record_info['fields']['contact_attempts'] = $this->processContactAttempts(
                    $record, $metadata, $contact_attempts_ranges, $list_config
                );
            }

            // Apply filters
            if (!$this->recordMatchesFilters($record_info, $extra_filter_raw, $filter_params)) {
                continue;
            }

            $results[] = $record_info;
        }

        return $results;
    }

    public function getSqlValuesFor($Proj, $field_name) {
        if ($Proj->metadata[$field_name]["element_type"] !== "sql") return [];
        if (!isset($this->buildProjectMetadata($Proj)["fields"][$field_name]["values"])) {
            $this->_metadata["fields"][$field_name]["values"] =
                parseEnum(getSqlFieldEnum($Proj->metadata[$field_name]['element_enum']));
        }
        return $this->_metadata["fields"][$field_name]["values"];
    }

    private function getFieldValueFromRecord($record, $field_name, $form_name, $form_events, $Proj) {
        $result = ['raw' => null, 'value' => null];

        foreach ($form_events as $ev_id => $event_info) {
            if ($event_info['repeating'] === true) {
                if (isset($record['repeat_instances'][$ev_id][$form_name])) {
                    $latest = end($record['repeat_instances'][$ev_id][$form_name]);
                    $result['raw'] = $latest[$field_name] ?? null;
                    break;
                } elseif (isset($record['repeat_instances'][$ev_id][null])) {
                    $latest = end($record['repeat_instances'][$ev_id][null]);
                    $result['raw'] = $latest[$field_name] ?? null;
                    break;
                }
            } else {
                $complete_field = $form_name . '_complete';
                if (($record[$ev_id][$complete_field] ?? '') !== '') {
                    $result['raw'] = $record[$ev_id][$field_name] ?? null;
                    break;
                }
            }
        }

        return $result;
    }

    private function processDisplayField($record, $field_name, $form_name, $form_events, $field_config, $Proj, $metadata, $callback_alert_field) {
        $field_result = ['value' => null];

        $raw_result = $this->getFieldValueFromRecord($record, $field_name, $form_name, $form_events, $Proj);
        $field_value = $raw_result['raw'];

        if ($field_config['isFormStatus']) {
            $field_result['value'] = $metadata['formStatuses'][$field_value] ?? 'Incomplete';
        } else {
            $element_type = $field_config['elementType'];

            if (!in_array($element_type, $metadata['unstructuredFieldTypes'])) {
                switch ($element_type) {
                    case 'select':
                    case 'radio':
                        $dd_values = $this->getDictionaryValuesFor($field_name);
                        $field_result['value'] = $dd_values[$field_value] ?? $field_value;
                        break;
                    case 'checkbox':
                        if (is_array($field_value)) {
                            $dd_values = $this->getDictionaryValuesFor($field_name);
                            $selected = [];
                            foreach ($field_value as $k => $v) {
                                if ($v === '1') {
                                    $selected[$k] = $dd_values[$k] ?? $k;
                                }
                            }
                            $field_result['value'] = $selected;
                        }
                        break;
                    case 'yesno':
                    case 'truefalse':
                        $field_result['value'] = $metadata['customDictionaryValues'][$element_type][$field_value] ?? $field_value;
                        break;
                    case 'sql':
                        $sql_values = $this->getSqlValuesFor($Proj, $field_name);
                        if (isset($sql_values[$field_value])) {
                            $field_value = $sql_values[$field_value];
                        } else if ($field_value !== null && $field_value != '') {
                            // we don't want to show the raw value if a match is not found
                            // TODO should we change this?
                            $field_value = "";
                        }
                        break;
                    default:
                        $field_result['value'] = $field_value;
                }
            } else {
                $field_result['value'] = $field_value;
            }
        }


        $validation_type = $field_config['elementValidationType'] ?? null;
        if (isset($metadata['dateFieldFormats'][$validation_type]) && !empty($field_value)) {
            $field_result['__SORT__'] = strtotime($field_value);
            $field_result['value'] = date($metadata['dateFieldFormats'][$validation_type], strtotime($field_value));
        }

        // Check callback alert (dynamic field)
        if (!empty($callback_alert_field) && $field_name === $callback_alert_field && !empty($field_value)) {
            if (strtotime('now') >= strtotime($field_value)) {
                $field_result['alert'] = true;
            }
        }

        return $field_result;
    }

    private function processContactAttempts($record, $metadata, $ranges, $list_config) {
        $call_date_field = $list_config["call_date_field"];
        $attempts = array_fill_keys(array_keys($ranges), 0);

        if (!isset($metadata['fields'][$call_date_field])) {
            return ['value' => []];
        }

        $call_date_form = $metadata['fields'][$call_date_field]['form'];
        $call_date_form_events = array_reverse($metadata['forms'][$call_date_form]['eventInfo'] ?? [], true);

        foreach ($call_date_form_events as $ev_id => $event_info) {
            if ($event_info['repeating'] === true && isset($record['repeat_instances'][$ev_id][$call_date_form])) {
                foreach ($record['repeat_instances'][$ev_id][$call_date_form] as $form_info) {
                    $call_date = $form_info[$call_date_field] ?? null;
                    if (empty($call_date)) continue;

                    $timestamp = strtotime($call_date);
                    foreach ($ranges as $range_key => $range) {
                        $begin = strtotime($range['begin'], $timestamp);
                        $end = strtotime($range['end'], $timestamp);
                        if ($timestamp >= $begin && $timestamp < $end) {
                            $attempts[$range_key]++;
                            break;
                        }
                    }
                }
            }
        }

        $attempt_strings = [];
        foreach ($attempts as $key => $count) {
            $attempt_strings[] = "$key ($count)";
        }
        $total_attempts = array_sum($attempts);
        return ['value' => $attempt_strings, '__SORT__', $total_attempts];
    }

    private function recordMatchesFilters($record_info, $extra_filter_raw, $filter_params) {
        $include_record = true;
        $primary_filter_raw = $record_info['primaryFilter']['raw'];

        // Apply primary filter
        if (!empty($filter_params['selectedPrimaryFilters'])) {
            $include_record = false;

            // Check "no_value" filter (records with no primary filter value)
            if (in_array('no_value', $filter_params['selectedPrimaryFilters'])) {
                if (empty($record_info['primaryFilter']['raw'])) {
                    $include_record = true;
                }
            }

            foreach ($filter_params['selectedPrimaryFilters'] as $filter_value) {
                if ($filter_value === 'no_value') continue;

                if (is_array($primary_filter_raw)) {
                    // For checkboxes, check if the selected option is checked ('1')
                    if (isset($primary_filter_raw[$filter_value]) && $primary_filter_raw[$filter_value] === '1') {
                        $include_record = true;
                        break;
                    }
                } else {
                    if ($primary_filter_raw === (string)$filter_value) {
                        $include_record = true;
                        break;
                    }
                }
            }
        }

        // Apply extra filter
        if ($include_record && $filter_params['extraFilterValue'] !== '') {
            // Handle checkbox fields (array) vs dropdown/radio (string)
            if (is_array($extra_filter_raw)) {
                // For checkboxes, check if the selected option is checked ('1')
                $include_record = isset($extra_filter_raw[$filter_params['extraFilterValue']])
                    && $extra_filter_raw[$filter_params['extraFilterValue']] === '1';
            } else {
                // For dropdowns/radio, direct comparison
                if ($extra_filter_raw != $filter_params['extraFilterValue']) {
                    $include_record = false;
                }
            }
        }

        return $include_record;
    }


    public function saveDataWithName($data, $name, $removeOldLogEntries = true) {
        $logId = $this->saveLogs('save module data - ' . $name, [$name => json_encode($data)]);

        if ($removeOldLogEntries && $logId) {
            $this->removeLogs('username = ? and log_id != ? and message = ?', [USERID, $logId, 'save module data - ' . $name]);
        }

        return (bool)$logId;
    }


    public function getSavedDataByName($name) {
        $moduleLogs = $this->getLogs([$name], 'username = ?', USERID);

        // Sort by log_id descending
        uksort($moduleLogs, function($a, $b) {
            if (!is_numeric($a)) return is_numeric($b) ? -1 : 0;
            if (!is_numeric($b)) return 1;
            return (int)$b - (int)$a;
        });

        // Find the parameter value
        foreach ($moduleLogs as $logRecord) {
            if (isset($logRecord['parameters'][$name])) {
                $decoded = json_decode($logRecord['parameters'][$name], true);
                return is_array($decoded) ? $decoded : [];
            }
        }
        return [];
    }

    private function getContactAttemptRanges($list_config) {
        $defaults = [
            'AM' => '00:00-11:59',
            'PM' => '12:00-17:59',
            'EVE' => '18:00-23:59'
        ];

        $ranges = [];
        foreach (['AM', 'PM', 'EVE'] as $key) {
            $value = $list_config['range_' . strtolower($key)] ?? $defaults[$key];
            if (empty($value) || !preg_match('/^\d{2}:\d{2}-\d{2}:\d{2}$/', $value)){
                $value = $defaults[$key];
            }

            list($begin, $end) = explode('-', $value);

            $ranges[$key] = [
                'begin' => $begin . ':00',
                'end' => $end . ':59',
                'label' => $begin . ' - ' . $end
            ];
        }

        return $ranges;
    }
}