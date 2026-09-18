<?php

/**
 * @package     mod_mucronix_contact
 *
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Mucronix\Module\MucronixContact\Site\Field;

use Joomla\CMS\Form\Field\TextareaField;
use Joomla\CMS\Language\Text;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The Custom CSS box, an ordinary textarea whose description ends with the selector of this very
 * form: #mcx-<id>. Without it the site owner has to find the id of the module somewhere else and
 * guess at the shape of the selector, and every ready-made snippet in the styling notes needs it.
 *
 * The class name has to be the type with its first letter raised and "Field" after it - that is how
 * FormHelper::loadClass() builds it - so the type is customcss and the class CustomcssField. A name
 * in nicer case would be found on Windows and not on the server.
 *
 * @since  1.1.0
 */
class CustomcssField extends TextareaField
{
    /**
     * The form field type.
     *
     * @var    string
     * @since  1.1.0
     */
    protected $type = 'Customcss';

    /**
     * Returns the textarea with the selector of this instance printed under it.
     *
     * Under the field itself, not in the description: the edit form of com_modules asks for inline
     * help (administrator/components/com_modules/forms/module.xml, <inlinehelp button="show"/>), and
     * the core layout then hides every description behind a button beside the label. The selector is
     * what the ready-made snippets are pasted around, so it has to be in plain sight.
     *
     * @return  string
     *
     * @since   1.1.0
     */
    protected function getInput()
    {
        // The id of the module, which the edit form of com_modules carries in a hidden field
        $id = $this->form ? (int) $this->form->getValue('id') : 0;

        $note = $id > 0
            ? Text::sprintf('MOD_MUCRONIX_CONTACT_FIELD_CUSTOM_CSS_SELECTOR', '#mcx-' . $id)
            : Text::_('MOD_MUCRONIX_CONTACT_FIELD_CUSTOM_CSS_UNSAVED');

        return parent::getInput()
            . '<small class="form-text mcx-custom-css-note">' . htmlspecialchars($note, ENT_QUOTES, 'UTF-8') . '</small>';
    }
}
