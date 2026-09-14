<?php

/**
 * @package     mod_mucronix_contact
 *
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

/*
 * Copied from Joomla 6.1.3, layouts/joomla/form/field/file.php.
 *
 * One line differs, the one computing $maxSize: the core layout asks Utility::getMaxUploadSize()
 * with no argument, which answers what php allows and ignores the module setting, so a visitor is
 * told 512 MB and refused at 2. Here the limit that really applies is handed in as a data attribute
 * and passed to the same core call.
 *
 * On a Joomla update this file has to be compared with the original: everything except that line
 * should still match. While it is in use, a site template override of joomla.form.field.file does
 * not apply to this field.
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Utility\Utility;

extract($displayData);

/**
 * Layout variables
 * -----------------
 * @var   string   $autocomplete    Autocomplete attribute for the field.
 * @var   boolean  $autofocus       Is autofocus enabled?
 * @var   string   $class           Classes for the input.
 * @var   string   $description     Description of the field.
 * @var   boolean  $disabled        Is this field disabled?
 * @var   string   $group           Group the field belongs to. <fields> section in form XML.
 * @var   boolean  $hidden          Is this field hidden in the form?
 * @var   string   $hint            Placeholder for the field.
 * @var   string   $id              DOM id of the field.
 * @var   string   $label           Label of the field.
 * @var   string   $labelclass      Classes to apply to the label.
 * @var   boolean  $multiple        Does this field support multiple values?
 * @var   string   $name            Name of the input field.
 * @var   string   $onchange        Onchange attribute for the field.
 * @var   string   $onclick         Onclick attribute for the field.
 * @var   string   $pattern         Pattern (Reg Ex) of value of the form field.
 * @var   boolean  $readonly        Is this field read only?
 * @var   boolean  $repeat          Allows extensions to duplicate elements.
 * @var   boolean  $required        Is this field required?
 * @var   integer  $size            Size attribute of the input.
 * @var   boolean  $spellcheck      Spellcheck state for the form field.
 * @var   string   $validate        Validation rules to apply.
 * @var   string   $value           Value attribute of the field.
 * @var   array    $checkedOptions  Options that will be set as checked.
 * @var   boolean  $hasValue        Has this field a value assigned?
 * @var   array    $options         Options available for this field.
 * @var   array    $inputType       Options available for this field.
 * @var   string   $accept          File types that are accepted.
 * @var   string   $dataAttribute   Miscellaneous data attributes preprocessed for HTML output
 * @var   array    $dataAttributes  Miscellaneous data attribute for eg, data-*
 */

$maxSize = HTMLHelper::_('number.bytes', Utility::getMaxUploadSize((string) ($dataAttributes['data-mucronix-max-bytes'] ?? '')));

?>
<input type="file"
    name="<?php echo $name; ?>"
    id="<?php echo $id; ?>"
    <?php echo !empty($size) ? ' size="' . $size . '"' : ''; ?>
    <?php echo !empty($accept) ? ' accept="' . $accept . '"' : ''; ?>
    <?php echo !empty($class) ? ' class="form-control ' . $class . '"' : ' class="form-control"'; ?>
    <?php echo !empty($multiple) ? ' multiple' : ''; ?>
    <?php echo $disabled ? ' disabled' : ''; ?>
    <?php echo $autofocus ? ' autofocus' : ''; ?>
    <?php echo $dataAttribute; ?>
    <?php echo !empty($onchange) ? ' onchange="' . $onchange . '"' : ''; ?>
    <?php echo $required ? ' required' : ''; ?>><br>
    <?php echo Text::sprintf('JGLOBAL_MAXIMUM_UPLOAD_SIZE_LIMIT', $maxSize); ?>
