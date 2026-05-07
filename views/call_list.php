<?php
/**
 * Call List Vue
 * @var \ORCA\OrcaCallListManager\OrcaCallListManager $module
 */

$config_index = isset($_GET['config']) ? (int)$_GET['config'] : 0;

$module->initializeJavascriptModuleObject();
$config = $module->getCallListConfig($module->getProjectId(), $config_index);
$page_state = $module->handleGetPageState($config_index);
?>

<div id="ORCA_CALL_LIST"></div>
<script>
    const OrcaCallList = function() {
        return {
            jsmo: <?= $module->getJavascriptModuleObjectName() ?>,
            config: <?= json_encode($config) ?>,
            pageState: <?= json_encode($page_state) ?>
        }
    };
</script>

<script type="module" src="<?= $module->getUrl('dist/pages/call_list.js') ?>"></script>
<link rel="stylesheet" href="<?= $module->getUrl('dist/assets/call_list.css') ?>">