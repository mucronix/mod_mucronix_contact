<?php

/**
 * @package     mod_mucronix_contact
 *
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Mucronix\Module\MucronixContact\Site\Helper;

use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Turns a submitted form into the ordered list of lines a notification is made of.
 *
 * One place, because there are two notifications. The mail and the Telegram message used to walk
 * the fields separately, with the same rules written out twice; the first change to one of them
 * would have parted the two silently, and nothing could have caught it - the test suite sees
 * neither the body of a mail nor the contents of a chat.
 *
 * @since  1.0.0
 */
final class MessageFields
{
    /**
     * Fields that are never part of a notification: one is a check, the other a trap, and neither
     * is anything the visitor wrote.
     *
     * @var    string[]
     * @since  1.0.0
     */
    private const SKIP_FIELDS = ['captcha', 'mcx_hp'];

    /**
     * Collects what the visitor filled in, in the order the form asked for it.
     *
     * @param   Form   $form  The contact form, for the labels, the types and the required states.
     * @param   array  $data  The filtered and validated form data.
     *
     * @return  array<int, array{label: string, value: string}>
     *
     * @since   1.0.0
     */
    public static function collect(Form $form, array $data): array
    {
        $fields = [];

        foreach ($form->getFieldset('contact') as $field) {
            if (\in_array($field->fieldname, self::SKIP_FIELDS, true)) {
                continue;
            }

            /*
             * A single checkbox that has to be ticked carries no news: the message could not have
             * been sent without it, so the answer is always the same word. Stated by meaning rather
             * than by the name "consent", or the same line would come back with the first required
             * tick box somebody adds through the extra fields.
             *
             * Checkboxes, the plural type, is deliberately not covered. Which of its options were
             * ticked is real information even when the field is required, and only the type tells
             * the two apart.
             */
            if ($field->type === 'Checkbox' && $field->required) {
                continue;
            }

            $value = $data[$field->fieldname] ?? '';

            /*
             * An unticked checkbox arrives empty, and an empty value is dropped below. For a
             * checkbox that is the answer rather than the absence of one: the recipient has to be
             * able to tell "did not tick it" from "the field was not on the form".
             */
            if ($field->type === 'Checkbox') {
                $fields[] = [
                    'label' => self::label($field),
                    'value' => $value ? Text::_('JYES') : Text::_('JNO'),
                ];

                continue;
            }

            if (trim((string) $value) === '') {
                continue;
            }

            $fields[] = [
                'label' => self::label($field),
                'value' => (string) $value,
            ];
        }

        return $fields;
    }

    /**
     * The label as a notification wants it.
     *
     * @param   object  $field  The form field.
     *
     * @return  string  Plain text, whatever the form put in the label.
     *
     * @since   1.0.0
     */
    private static function label(object $field): string
    {
        // The consent label can carry a link to the policy article; a notification wants the words
        return trim(strip_tags((string) $field->title));
    }
}
