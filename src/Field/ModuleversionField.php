<?php

/**
 * @package     mod_mucronix_contact
 *
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Mucronix\Module\MucronixContact\Site\Field;

use Joomla\CMS\Extension\ExtensionHelper;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The name and the version of the module, one line at the top of the Module tab. Without it the
 * version is only to be found in the list of installed extensions.
 *
 * The class name is the type with its first letter raised and "Field" after it, the way
 * FormHelper::loadClass() builds it, so the type is moduleversion and the class ModuleversionField.
 *
 * @since  1.2.0
 */
class ModuleversionField extends FormField
{
    /**
     * The form field type.
     *
     * @var    string
     * @since  1.2.0
     */
    protected $type = 'Moduleversion';

    /**
     * Returns the line itself, plain text with nothing to submit.
     *
     * Printed here and not in the description: the edit form of com_modules asks for inline help,
     * and the core layout then hides every description behind a button beside the label.
     *
     * The version comes from the record of the extension in the database, not from the manifest on
     * the disk. The record holds what the installer actually installed, which is the question the
     * site owner is asking; files copied over by hand would answer something else.
     *
     * @return  string
     *
     * @since   1.2.0
     */
    protected function getInput()
    {
        $record   = ExtensionHelper::getExtensionRecord('mod_mucronix_contact', 'module', 0);
        $manifest = $record ? json_decode((string) $record->manifest_cache) : null;
        $version  = \is_object($manifest) && isset($manifest->version) ? (string) $manifest->version : '';

        // Without a version the name still stands: an empty row would look like a fault of the page
        $line = trim(Text::_('MOD_MUCRONIX_CONTACT') . ' ' . $version);

        return '<span id="' . $this->id . '" class="form-control-plaintext">'
            . htmlspecialchars($line, ENT_QUOTES, 'UTF-8')
            . '</span>';
    }
}
