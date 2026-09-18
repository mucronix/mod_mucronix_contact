<?php

/**
 * @package     mod_mucronix_contact
 *
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Mucronix\Module\MucronixContact\Site\Field;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The link to the styling notes, drawn as a button on the Appearance tab.
 *
 * A page of its own rather than more text in the settings: what belongs there is longer than any
 * description - ready-made snippets to paste into Custom CSS - and the same page is what the README
 * points at instead of repeating it.
 *
 * The class name is the type with its first letter raised and "Field" after it, the way
 * FormHelper::loadClass() builds it, so the type is stylingdocs and the class StylingdocsField.
 *
 * @since  1.1.0
 */
class StylingdocsField extends FormField
{
    /**
     * The form field type.
     *
     * @var    string
     * @since  1.1.0
     */
    protected $type = 'Stylingdocs';

    /**
     * Returns the button, an ordinary link opening the notes in a new tab.
     *
     * No onclick and no script: a link is all this needs, and the administration has enough of both.
     *
     * @return  string
     *
     * @since   1.1.0
     */
    protected function getInput()
    {
        $file = 'styling.' . $this->getLanguageTag() . '.html';

        return '<a class="btn btn-secondary" href="'
            . htmlspecialchars(Uri::root(true) . '/media/mod_mucronix_contact/docs/' . $file, ENT_QUOTES, 'UTF-8')
            . '" target="_blank" rel="noopener">'
            . Text::_('MOD_MUCRONIX_CONTACT_FIELD_STYLING_DOCS_BUTTON')
            . '</a>';
    }

    /**
     * Returns the language the notes are opened in: the one the administration is speaking, or en-GB
     * when that language has no file of its own.
     *
     * @return  string
     *
     * @since   1.1.0
     */
    private function getLanguageTag(): string
    {
        $tag = Factory::getApplication()->getLanguage()->getTag();

        return is_file(JPATH_SITE . '/media/mod_mucronix_contact/docs/styling.' . $tag . '.html') ? $tag : 'en-GB';
    }
}
