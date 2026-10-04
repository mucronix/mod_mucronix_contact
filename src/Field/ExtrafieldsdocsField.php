<?php

/**
 * @package     mod_mucronix_contact
 *
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Mucronix\Module\MucronixContact\Site\Field;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The link to the notes on extra fields, drawn as a button on the Extra Fields tab.
 *
 * That tab is the one place where the site owner writes markup by hand, and the Joomla documentation
 * it used to point at describes every field type of the core while the module takes eleven. The page
 * holds an example for each of the eleven and says what is refused and why.
 *
 * The same button as the one for the styling notes, opening another page. The class name is the type
 * with its first letter raised and "Field" after it, the way FormHelper::loadClass() builds it, so
 * the type is extrafieldsdocs and the class ExtrafieldsdocsField.
 *
 * @since  1.2.0
 */
class ExtrafieldsdocsField extends StylingdocsField
{
    /**
     * The form field type.
     *
     * @var    string
     * @since  1.2.0
     */
    protected $type = 'Extrafieldsdocs';

    /**
     * The first part of the file name of the notes.
     *
     * @var    string
     * @since  1.2.0
     */
    protected $page = 'extra-fields';

    /**
     * The language key of the wording on the button.
     *
     * @var    string
     * @since  1.2.0
     */
    protected $button = 'MOD_MUCRONIX_CONTACT_FIELD_EXTRA_FIELDS_DOCS_BUTTON';
}
