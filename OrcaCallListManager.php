<?php

namespace ORCA\OrcaCallListManager;

use ExternalModules\AbstractExternalModule;

require_once 'traits/REDCapUtils.php';
require_once 'traits/ModuleLogUtils.php';
require_once 'traits/ModuleUtils.php';
require_once 'traits/RequestHandlers.php';

class OrcaCallListManager extends AbstractExternalModule {
    use REDCapUtils;
    use ModuleLogUtils;
    use ModuleUtils;
    use RequestHandlers;


    const DEFAULT_EMPTY_PAGESTATE = [
        'checkboxSelections' => [],
        'dropdownSelections' => []
    ];

    public function redcap_module_link_check_display($project_id, $link) {
        $link_num = $link['link_num'] ?? null;
        if ($link_num === null) {
            return $link;
        }

        $call_lists = $this->getSubSettings('dashboard_config');

        // hide link if no config at this index
        if (!isset($call_lists[$link_num]) || empty($call_lists[$link_num]['display_title'])) {
            return null;
        }

        // Modify link with config values
        $config = $call_lists[$link_num];
        $link['name'] = $config['display_title'];
        $link['url'] = $link['url'] . '&config=' . $link_num;

        return $link;
    }
}