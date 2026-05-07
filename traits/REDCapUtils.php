<?php

namespace ORCA\OrcaCallListManager;

trait REDCapUtils
{
    private $_dataDictionary = [];
    private $timers = [];

    public function getDataDictionary($format = 'array')
    {
        if (!array_key_exists($format, $this->_dataDictionary ?? [])) {
            $this->_dataDictionary[$format] = \REDCap::getDataDictionary($format);
        }
        return $this->_dataDictionary[$format];
    }

    public function getDictionaryLabelFor($key)
    {
        $label = $this->getDataDictionary("array")[$key]['field_label'];
        if (empty($label)) {
            return $key;
        }
        return $label;
    }

    public function getDictionaryValuesFor($key)
    {
        // TODO consider using $this->getChoiceLabels()
        return $this->flatten_type_values($this->getDataDictionary()[$key]['select_choices_or_calculations']);
    }

    public function comma_delim_to_key_value_array($value)
    {
        $arr = explode(', ', trim($value));
        $sliced = array_slice($arr, 1, count($arr) - 1, true);
        return [$arr[0] => implode(', ', $sliced)];
    }

    public function array_flatten($array)
    {
        $return = [];
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $return = $return + $this->array_flatten($value);
            } else {
                $return[$key] = $value;
            }
        }
        return $return;
    }

    public function flatten_type_values($value)
    {
        $split = explode('|', $value);
        $mapped = array_map(function ($value) {
            return $this->comma_delim_to_key_value_array($value);
        }, $split);
        $result = $this->array_flatten($mapped);
        return $result;
    }

    /**
     * Truncate text to a specified limit.  The ellipsis '...' length is factored into the limit.
     * @param $value mixed can be string or array. If array, all values will be truncated if needed
     * @param $limit int the total maximum length for the text
     * @return mixed the value after truncation
     */
    public function truncate($value, $limit = 60) {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->truncate($v);
            }
        } else {
            // account for rich text editor or multi-line labels
            $v = preg_split("/\r\n|\n|<br>|<br \/>/", strip_tags($value, '<br>'));
            $value = $v[0];
            if (strlen($value) > ($limit - 3)) {
                $value = substr($value, 0, ($limit - 3)) . "...";
            }
        }
        return $value;
    }

    public function preout($content)
    {
        if (is_array($content) || is_object($content)) {
            echo "<pre>" . print_r($content, true) . "</pre>";
        } else {
            echo "<pre>$content</pre>";
        }
    }

    public function addTime($key = null)
    {
        if ($key == null) {
            $key = "STEP " . count($this->timers);
        }
        $this->timers[] = [
            "label" => $key,
            "value" => microtime(true)
        ];
    }

    public function outputTimerInfo($showAll = false, $return = false)
    {
        $initTime = null;
        $preTime = null;
        $curTime = null;
        $output = [];
        foreach ($this->timers as $index => $timeInfo) {
            $curTime = $timeInfo;
            if ($preTime == null) {
                $initTime = $timeInfo;
            } else {
                $calcTime = round($curTime["value"] - $preTime["value"], 4);
                if ($showAll) {
                    if ($return === true) {
                        $output[] = "{$timeInfo["label"]}: {$calcTime}";
                    } else {
                        echo "<p><i>{$timeInfo["label"]}: {$calcTime}</i></p>";
                    }
                }
            }
            $preTime = $curTime;
        }
        $calcTime = round($curTime["value"] - $initTime["value"], 4);
        if ($return === true) {
            $output[] = "Total Processing Time: {$calcTime} seconds";
            return $output;
        } else {
            echo "<p><i>Total Processing Time: {$calcTime} seconds</i></p>";
        }
    }

    /**
     * Outputs the module directory folder name into the page footer, for easy reference.
     * @return void
     */
    public function outputModuleVersionJS() {
        $module_info = $this->getModuleName() . " (" . $this->VERSION . ")";
        echo "<script>$(function() { $('div#south table tr:first td:last, #footer').prepend('<span>$module_info</span>&nbsp;|&nbsp;'); });</script>";
    }
}