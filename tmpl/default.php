<?php

/**
 * @package     mod_mucronix_contact
 *
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;

/**
 * Layout variables
 * -----------------
 * @var   \stdClass                  $module
 * @var   \Joomla\Registry\Registry  $params
 * @var   \Joomla\CMS\Form\Form      $form
 * @var   array|null                 $result       ['success' => bool, 'messages' => string[]] after a submission
 * @var   string[]                   $warnings     Notices about settings that keep the form from working
 * @var   string                     $buttonClass  Classes the Field Markup gives the send button
 */

// Only someone who can fix them is told; visitors see the form as usual
$showWarnings = $warnings !== []
    && Factory::getApplication()->getIdentity()->authorise('core.manage', 'com_modules');

$moduleId   = (int) $module->id;
$intro      = trim((string) $params->get('form_intro', ''));
$submitText = trim((string) $params->get('submit_text', ''));
$submitText = $submitText !== '' ? $submitText : Text::_('MOD_MUCRONIX_CONTACT_FORM_SUBMIT');

$wrapperClass = trim('mcx ' . $params->get('wrapper_class', ''));
$formClass    = trim('mcx-form ' . $params->get('form_class', ''));

// The Button Class parameter goes after the classes of the Field Markup: it adds, it does not replace
$submitClass  = trim('mcx-submit ' . trim($buttonClass . ' ' . $params->get('submit_class', '')));

/*
 * The box around one field, and the honeypot is deliberately not one of them: it is hidden by the
 * markup, and a class carrying a display of its own would bring it back into view, where real
 * people fill it in and their messages are dropped without a word.
 */
$fieldClass   = trim('mcx-field-wrap ' . $params->get('field_class', ''));

$sent         = $result !== null && $result['success'];
$messageClass = $result === null ? '' : ' mcx-message--' . ($result['success'] ? 'ok' : 'error');
$fieldErrors  = $result['errors'] ?? [];

// The form works without JavaScript as a plain post; the script only takes over the sending
$wa = Factory::getApplication()->getDocument()->getWebAssetManager();
$wa->getRegistry()->addExtensionRegistryFile('mod_mucronix_contact');
$wa->useScript('mod_mucronix_contact.form');

/*
 * The stylesheet only tidies the inside of the form and can be switched off whole, for a template
 * that dresses its forms itself. The uri in joomla.asset.json carries no css segment, the same way
 * the script carries no js one: with it Joomla looks for media/mod_mucronix_contact/css/css/form.css,
 * finds nothing and drops the asset without a word - no error, no tag on the page.
 */
if ($params->get('load_css', 1)) {
    $wa->useStyle('mod_mucronix_contact.form');
}

Text::script('MOD_MUCRONIX_CONTACT_FORM_SENDING');
Text::script('MOD_MUCRONIX_CONTACT_ERROR_SEND');

?>
<?php // The id is the anchor the redirect after a submission points at ?>
<div id="mcx-<?php echo $moduleId; ?>" class="<?php echo htmlspecialchars($wrapperClass, ENT_QUOTES, 'UTF-8'); ?>" data-module-id="<?php echo $moduleId; ?>">
    <?php if ($showWarnings) : ?>
        <?php // Own markup on purpose: the class is not mcx-message, which the script uses for its own block ?>
        <?php foreach ($warnings as $warning) : ?>
            <div class="mcx-admin-warning" role="alert">
                <?php echo $warning; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($intro !== '' && !$sent) : ?>
        <div class="mcx-intro"><?php echo $intro; ?></div>
    <?php endif; ?>

    <?php // The block carries refusals as well as confirmations, so it announces itself as an alert ?>
    <div class="mcx-message<?php echo $messageClass; ?>" role="alert" aria-live="polite">
        <?php if ($result !== null) : ?>
            <?php foreach ($result['messages'] as $message) : ?>
                <?php // The success text comes from the editor parameter and may hold HTML, the rest are language strings ?>
                <div><?php echo $result['success'] ? $message : htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if (!$sent) : ?>
        <form id="mcx-form-<?php echo $moduleId; ?>" class="<?php echo htmlspecialchars($formClass, ENT_QUOTES, 'UTF-8'); ?>"
            action="<?php echo htmlspecialchars(Uri::getInstance()->toString(), ENT_QUOTES, 'UTF-8'); ?>"
            method="post" enctype="multipart/form-data">
            <?php foreach ($form->getFieldset('contact') as $field) : ?>
                <?php if ($field->fieldname === 'mcx_hp') : ?>
                    <?php // The honeypot is hidden by the markup itself, so that turning the module CSS off cannot reveal it ?>
                    <div class="mcx-hp" hidden>
                        <?php echo $field->renderField(); ?>
                    </div>
                <?php else : ?>
                    <?php $error = $fieldErrors[$field->fieldname] ?? ''; ?>
                    <?php /* Our own wrapper holds the field and everything that belongs to it. The core
                             layout has no slot for a message, and the vertical rhythm is set on this
                             wrapper in the CSS, so the text cannot drift towards the next field. */ ?>
                    <div class="<?php echo htmlspecialchars($fieldClass, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo $field->renderField(['class' => 'mcx-field mcx-field--' . $field->fieldname
                            . ($field->fieldname === 'consent' ? ' mcx-consent' : '')
                            . ($error !== '' ? ' is-invalid' : '')]); ?>
                        <?php if ($error !== '') : ?>
                            <?php // Without JavaScript the messages belonging to single fields are printed here ?>
                            <span class="mcx-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                        <?php // Its own class, and silent once the captcha has said something: one message at a time ?>
                        <?php if ($field->fieldname === 'captcha' && $error === '') : ?>
                            <noscript>
                                <span class="mcx-noscript"><?php echo Text::_('MOD_MUCRONIX_CONTACT_FORM_CAPTCHA_NOSCRIPT'); ?></span>
                            </noscript>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <button type="submit" class="<?php echo htmlspecialchars($submitClass, ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($submitText, ENT_QUOTES, 'UTF-8'); ?>
            </button>

            <input type="hidden" name="mcx_module_id" value="<?php echo $moduleId; ?>">
            <?php /* The page the form stands on. Through com_ajax the request carries no trace of it,
                     and the message would name the com_ajax endpoint instead. Checked again on arrival:
                     it comes back through the visitor. */ ?>
            <input type="hidden" name="mcx_page" value="<?php echo htmlspecialchars(Uri::getInstance()->toString(), ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo HTMLHelper::_('form.token'); ?>
        </form>
    <?php endif; ?>
</div>
