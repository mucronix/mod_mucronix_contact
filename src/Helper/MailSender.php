<?php

/**
 * @package     mod_mucronix_contact
 *
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Mucronix\Module\MucronixContact\Site\Helper;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Mail\MailerInterface;
use Joomla\CMS\Mail\MailHelper;
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Builds and sends the message of mod_mucronix_contact.
 *
 * @since  1.0.0
 */
class MailSender
{
    /**
     * Sends the message to the recipients of a module instance.
     *
     * @param   Form                     $form     The contact form, used for the field labels.
     * @param   array                    $data     The filtered and validated form data.
     * @param   Registry                 $params   The module parameters.
     * @param   \stdClass                $module   The module object.
     * @param   CMSApplicationInterface  $app      The application.
     * @param   string                   $pageUrl  The page the form was sent from, already checked.
     *
     * @return  void
     *
     * @since   1.0.0
     * @throws  \RuntimeException  When no valid recipient is configured.
     */
    public function send(
        Form $form,
        array $data,
        Registry $params,
        \stdClass $module,
        CMSApplicationInterface $app,
        string $pageUrl
    ): void {
        $this->prepare($form, $data, $params, $module, $app, $pageUrl)->Send();
    }

    /**
     * Builds the message, complete and ready to go out.
     *
     * @param   Form                     $form     The contact form, used for the field labels.
     * @param   array                    $data     The filtered and validated form data.
     * @param   Registry                 $params   The module parameters.
     * @param   \stdClass                $module   The module object.
     * @param   CMSApplicationInterface  $app      The application.
     * @param   string                   $pageUrl  The page the form was sent from, already checked.
     *
     * @return  MailerInterface
     *
     * @since   2.0.0
     * @throws  \RuntimeException  When no valid recipient is configured.
     */
    protected function prepare(
        Form $form,
        array $data,
        Registry $params,
        \stdClass $module,
        CMSApplicationInterface $app,
        string $pageUrl
    ): MailerInterface {
        $recipients = $this->getRecipients($params, $app);

        if ($recipients === []) {
            throw new \RuntimeException(\sprintf('%s(): no valid recipient configured', __METHOD__));
        }

        $subject = trim((string) $params->get('subject', ''));
        $subject = $subject !== '' ? $subject : Text::sprintf('MOD_MUCRONIX_CONTACT_MAIL_SUBJECT', $app->get('sitename'));

        $mailer = Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer();

        // The sender stays the site address: the visitor address in From would fail SPF and DKIM
        foreach ($recipients as $recipient) {
            $mailer->addRecipient($recipient);
        }

        $email = trim((string) ($data['email'] ?? ''));

        if ($email !== '' && MailHelper::isEmailAddress($email)) {
            $mailer->addReplyTo($email, trim((string) ($data['name'] ?? '')));
        }

        $mailer->setSubject(MailHelper::cleanSubject($subject));
        $mailer->isHtml(true);
        $mailer->setBody($this->getBody($form, $data, $module, $pageUrl));

        return $mailer;
    }

    /**
     * Collects the recipient addresses that can be written to, each of them once.
     *
     * @param   Registry                 $params  The module parameters.
     * @param   CMSApplicationInterface  $app     The application.
     *
     * @return  string[]
     *
     * @since   1.0.0
     */
    private function getRecipients(Registry $params, CMSApplicationInterface $app): array
    {
        $valid = [];

        foreach ($this->getAddresses($params, $app) as $address) {
            $address = MailHelper::cleanLine(trim($address));

            if ($address !== '' && MailHelper::isEmailAddress($address) && !\in_array($address, $valid, true)) {
                $valid[] = $address;
            }
        }

        return $valid;
    }

    /**
     * Returns the addresses as the settings give them: the parameter, or the site address when it is empty.
     *
     * @param   Registry                 $params  The module parameters.
     * @param   CMSApplicationInterface  $app     The application.
     *
     * @return  string[]
     *
     * @since   2.0.0
     */
    protected function getAddresses(Registry $params, CMSApplicationInterface $app): array
    {
        $main = trim((string) $params->get('recipient', ''));

        if ($main === '') {
            $main = (string) $app->get('mailfrom', '');
        }

        return [$main];
    }

    /**
     * Returns the lines of the message, in the order the form asked for them.
     *
     * @param   Form   $form  The contact form, used for the field labels.
     * @param   array  $data  The filtered and validated form data.
     *
     * @return  array<int, array{label: string, value: string}>
     *
     * @since   2.0.0
     */
    protected function getFields(Form $form, array $data): array
    {
        return MessageFields::collect($form, $data);
    }

    /**
     * Renders the message body through the overridable tmpl/default_mail.php layout.
     *
     * @param   Form       $form     The contact form, used for the field labels.
     * @param   array      $data     The filtered and validated form data.
     * @param   \stdClass  $module   The module object.
     * @param   string     $pageUrl  The page the form was sent from, already checked.
     *
     * @return  string  HTML
     *
     * @since   1.0.0
     */
    private function getBody(Form $form, array $data, \stdClass $module, string $pageUrl): string
    {
        /*
         * The address arrives ready made. Uri::getInstance() is the current request, and through
         * com_ajax that request is the endpoint of com_ajax itself, so the recipient of a site with
         * several forms on it could not tell which one wrote. Where the address comes from and what
         * it has to pass is decided in the helper, getPageUrl().
         */
        $displayData = [
            'fields'  => $this->getFields($form, $data),
            'module'  => $module,
            'pageUrl' => $pageUrl,
        ];

        $layout = ModuleHelper::getLayoutPath('mod_mucronix_contact', 'default_mail');

        $loader = static function (string $layout, array $displayData) {
            extract($displayData);

            ob_start();
            require $layout;

            return (string) ob_get_clean();
        };

        return $loader($layout, $displayData);
    }
}
