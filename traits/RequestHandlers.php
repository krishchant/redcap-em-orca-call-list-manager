<?php

namespace ORCA\OrcaCallListManager;

trait RequestHandlers {

    public function redcap_module_ajax($action, $payload, $project_id) {

        try {
            switch ($action) {
                case 'get-call-list-data':
                    return $this->handleGetCallListData($project_id, $payload);

                case 'get-page-state':
                    $config_index = $payload['configIndex'] ?? 0;
                    return $this->handleGetPageState($config_index);

                case 'save-page-state':
                    return $this->handleSavePageState($payload);

                default:
                    throw new \Exception("Unknown action: {$action}");
            }
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}