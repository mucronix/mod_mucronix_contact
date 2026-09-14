<?php

/**
 * @package     mod_mucronix_contact
 *
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/**
 * Layout variables
 * -----------------
 * @var   array      $fields   List of ['label' => string, 'value' => string], already filtered
 * @var   \stdClass  $module   The module object
 * @var   string     $pageUrl  The page the form was sent from
 */

?>
<table>
    <?php foreach ($fields as $field) : ?>
        <tr>
            <th align="left"><?php echo htmlspecialchars($field['label'], ENT_QUOTES, 'UTF-8'); ?></th>
            <td><?php echo nl2br(htmlspecialchars($field['value'], ENT_QUOTES, 'UTF-8')); ?></td>
        </tr>
    <?php endforeach; ?>
    <tr>
        <th align="left"><?php echo Text::_('MOD_MUCRONIX_CONTACT_MAIL_PAGE'); ?></th>
        <td><?php echo htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8'); ?></td>
    </tr>
    <tr>
        <th align="left"><?php echo Text::_('MOD_MUCRONIX_CONTACT_MAIL_DATE'); ?></th>
        <td><?php echo HTMLHelper::_('date', 'now', Text::_('DATE_FORMAT_LC2')); ?></td>
    </tr>
</table>
