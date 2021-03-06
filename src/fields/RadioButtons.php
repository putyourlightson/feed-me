<?php
namespace verbb\feedme\fields;

use verbb\feedme\base\Field;
use verbb\feedme\base\FieldInterface;

use Craft;

use Cake\Utility\Hash;

class RadioButtons extends Field implements FieldInterface
{
    // Properties
    // =========================================================================

    public static $name = 'RadioButtons';
    public static $class = 'craft\fields\RadioButtons';


    // Templates
    // =========================================================================

    public function getMappingTemplate()
    {
        return 'feed-me/_includes/fields/option-select';
    }


    // Public Methods
    // =========================================================================

    public function parseField()
    {
        $value = $this->fetchSimpleValue();

        $options = Hash::get($this->field, 'settings.options');
        $match = Hash::get($this->fieldInfo, 'options.match', 'value');

        foreach ($options as $option) {
            if ($value === $option[$match]) {
                return $option['value'];
            }
        }

        return null;
    }

}