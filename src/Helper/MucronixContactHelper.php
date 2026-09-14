<?php

/**
 * @package     mod_mucronix_contact
 *
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Mucronix\Module\MucronixContact\Site\Helper;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormFactoryInterface;
use Joomla\CMS\Helper\MediaHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Mail\MailHelper;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Content\Site\Helper\RouteHelper;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Filesystem\File;
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Helper for mod_mucronix_contact
 *
 * The class name is dictated by com_ajax: for module=mucronix_contact it asks the module for the helper "MucronixContactHelper".
 *
 * @since  1.0.0
 */
class MucronixContactHelper implements DatabaseAwareInterface
{
    use DatabaseAwareTrait;

    /**
     * Base fields whose visibility and requirement are controlled by the module parameters.
     *
     * @var    string[]
     * @since  1.0.0
     */
    private const OPTIONAL_FIELDS = ['name', 'email', 'message'];

    /**
     * Field names the module owns. An extra field may not take any of them.
     *
     * @var    string[]
     * @since  1.0.0
     */
    private const RESERVED_FIELDS = ['name', 'email', 'message', 'attachment', 'consent', 'captcha', 'mcx_hp', 'mcx_module_id'];

    /**
     * Field types an extra field may use.
     *
     * @var    string[]
     * @since  1.0.0
     */
    private const ALLOWED_EXTRA_TYPES = [
        'text', 'textarea', 'email', 'tel', 'url', 'number', 'list', 'radio', 'checkbox', 'checkboxes', 'calendar',
    ];

    /**
     * Attributes removed from an extra field before it reaches the form.
     *
     * @var    string[]
     * @since  1.0.0
     */
    private const REFUSED_EXTRA_ATTRIBUTES = [
        'id', 'layout', 'layoutIncludePath', 'fieldset', 'formsource',
        'addfieldpath', 'addformpath', 'addrulepath', 'addfilterpath',
        'addfieldprefix', 'addformprefix', 'addruleprefix', 'addfilterprefix',
    ];

    /**
     * What an extra field may be called.
     *
     * @var    string
     * @since  1.0.0
     */
    private const EXTRA_NAME_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_]*$/';

    /**
     * What went wrong in the extra field description, for the person allowed to fix it.
     *
     * Every entry holds two wordings: 'display' for the block above the form, translated and with
     * the values escaped, and 'log' for the single line written when a message comes in, plain and
     * in english like the rest of the log.
     *
     * @var    array<int, array{display: string, log: string}>
     * @since  1.0.0
     */
    private array $extraFieldNotices = [];

    /**
     * Maximum length of the fields, checked again on the server.
     *
     * @var    array<string, integer>
     * @since  1.0.0
     */
    private const MAX_LENGTH = ['name' => 100, 'message' => 5000];

    /**
     * Seconds one session has to wait between two messages.
     *
     * @var    integer
     * @since  1.0.0
     */
    private const THROTTLE_SECONDS = 30;

    /**
     * Session key holding the time of the last message.
     *
     * @var    string
     * @since  1.0.0
     */
    private const THROTTLE_KEY = 'mcx.lastsent';

    /**
     * Session key holding the outcome of a submission between the post and the following read.
     * The id of the module instance is appended: every instance keeps its own.
     *
     * @var    string
     * @since  1.0.0
     */
    private const RESULT_KEY = 'mcx.result.';

    /**
     * What the content of a file may be, per extension. One extension can have several: a txt or a
     * zip is reported differently depending on the system, and a single string would refuse honest files.
     *
     * @var    array<string, string[]>
     * @since  1.0.0
     */
    private const MIME_MAP = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'pdf'  => ['application/pdf'],
        'txt'  => ['text/plain', 'application/octet-stream'],
        'zip'  => ['application/zip', 'application/x-zip-compressed', 'application/octet-stream', 'multipart/x-zip'],
    ];

    /**
     * Builds the contact form of a module instance.
     *
     * @param   Registry   $params  The module parameters.
     * @param   \stdClass  $module  The module object.
     *
     * @return  Form
     *
     * @since   1.0.0
     * @throws  \RuntimeException  If the form definition cannot be loaded.
     */
    public function getForm(Registry $params, \stdClass $module): Form
    {
        $moduleId = (int) $module->id;

        /*
         * Name and control are unique per module instance, so two forms on one page do not share ids.
         *
         * The control is also what keeps the module id out of reach. Every field of the form, the
         * extra ones described in the settings included, is posted as mcx_<id>[...], while the
         * instance names itself in a plain mcx_module_id beside it: different keys of $_POST, they
         * cannot meet. Checked on Joomla 6.1.3, brackets and quotes in a field name included: no
         * name escapes the prefix. mcx_module_id is on the reserved list as well, but it is the
         * control that actually protects it. Take the control away one day and the list alone will
         * not be enough: a field could then be posted under that very name.
         */
        $form = Factory::getContainer()->get(FormFactoryInterface::class)->createForm(
            'mod_mucronix_contact.' . $moduleId,
            ['control' => 'mcx_' . $moduleId]
        );

        $this->loadDefinition($form, $params);

        foreach (self::OPTIONAL_FIELDS as $name) {
            if (!$params->get('show_' . $name, 1)) {
                $form->removeField($name);

                continue;
            }

            $form->setFieldAttribute($name, 'required', $params->get('require_' . $name, 1) ? 'true' : 'false');
        }

        if ($params->get('show_attachment', 0)) {
            $form->setFieldAttribute('attachment', 'accept', '.' . implode(',.', $this->getAllowedExtensions($params)));

            /*
             * Our own copy of the core file layout, which prints the limit that really applies
             * instead of what php alone allows. The number arrives as a data attribute: the layout
             * only shows it, the arithmetic stays here.
             */
            $form->setFieldAttribute('attachment', 'layout', 'mucronix.form.field.file');
            $form->setFieldAttribute('attachment', 'layoutIncludePath', 'modules/mod_mucronix_contact/layouts');
            $form->setFieldAttribute('attachment', 'data-mucronix-max-bytes', (string) $this->getMaxBytes($params));
        } else {
            $form->removeField('attachment');
        }

        /*
         * An empty value means "use global", and the field then resolves the site setting itself.
         * Any other value is handed to the field as its plugin: a plugin name, or 0 for none,
         * on which CaptchaField::setup() drops the field out of the form altogether.
         */
        $captcha = trim((string) $params->get('captcha', ''));

        if ($captcha !== '') {
            $form->setFieldAttribute('captcha', 'plugin', $captcha);
        }

        if ($params->get('show_consent', 1)) {
            $form->setFieldAttribute('consent', 'label', $this->getConsentLabel($params));
            $form->setFieldAttribute('consent', 'translate_label', 'false');
        } else {
            $form->removeField('consent');
        }

        return $form;
    }

    /**
     * Loads the form definition, with the fields described in the settings folded in.
     *
     * The extra description is never handed to Form::load() as it arrived. With its default second
     * argument that method replaces a field of the same name by the one that came in, node and all,
     * and says nothing about it: a field called captcha takes the place of the captcha and leaves a
     * plain text box that any value satisfies, while the check here still finds a field named
     * captcha and believes it did its work. Checked on Joomla 6.1.3, including through a fieldset of
     * another name, which makes no difference: only a <fields> group counts as a group.
     *
     * @param   Form      $form    The form to fill.
     * @param   Registry  $params  The module parameters.
     *
     * @return  void
     *
     * @since   1.0.0
     * @throws  \RuntimeException  If the form definition of the module cannot be read.
     */
    private function loadDefinition(Form $form, Registry $params): void
    {
        $file  = JPATH_SITE . '/modules/mod_mucronix_contact/forms/contact.xml';
        $extra = $this->getExtraFields((string) $params->get('extra_fields', ''));

        if ($extra === []) {
            if (!$form->loadFile($file)) {
                throw new \RuntimeException(\sprintf('%s() could not load the form definition', __METHOD__));
            }

            return;
        }

        $document = new \DOMDocument();

        // Our own file: a failure here means a broken installation, not something typed in a setting
        if (!$document->load($file, \LIBXML_NONET)) {
            throw new \RuntimeException(\sprintf('%s() could not load the form definition', __METHOD__));
        }

        $captcha = (new \DOMXPath($document))->query('//field[@name="captcha"]')->item(0);

        if (!$captcha instanceof \DOMElement || !$captcha->parentNode instanceof \DOMNode) {
            throw new \RuntimeException(\sprintf('%s() found no captcha field to insert before', __METHOD__));
        }

        /*
         * In front of the captcha, inside the base fieldset. Loaded as a second fieldset they would
         * land after the captcha and the honeypot instead, which breaks the reading order as well as
         * the order of tabbing.
         */
        foreach ($extra as $field) {
            $captcha->parentNode->insertBefore($document->importNode($field, true), $captcha);
        }

        if (!$form->load($document->saveXML())) {
            throw new \RuntimeException(\sprintf('%s() could not load the merged form definition', __METHOD__));
        }
    }

    /**
     * Reads the field description from the settings and returns the elements that may be used.
     *
     * @param   string  $source  The contents of the extra_fields parameter.
     *
     * @return  \DOMElement[]
     *
     * @since   1.0.0
     */
    private function getExtraFields(string $source): array
    {
        $source = trim($source);

        if ($source === '') {
            return [];
        }

        $document = $this->parseExtraFields($source);

        if ($document === null) {
            return [];
        }

        // A field inside a field belongs to a repeatable element, and that type is not allowed anyway
        $nodes = (new \DOMXPath($document))->query('//field[not(ancestor::field)]');

        if ($nodes === false || $nodes->length === 0) {
            $this->addExtraFieldNotice(
                Text::_('MOD_MUCRONIX_CONTACT_EXTRA_FIELDS_EMPTY'),
                'the field description holds no field element'
            );

            return [];
        }

        $fields = [];
        $taken  = [];

        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $name = $this->getVettedName($node, $taken);

            if ($name === '' || !$this->hasVettedType($node, $name)) {
                continue;
            }

            $this->stripRefusedAttributes($node, $name);

            $taken[strtolower($name)] = true;
            $fields[]                 = $node;
        }

        return $fields;
    }

    /**
     * Turns the text of the parameter into a document, in whichever of the usual shapes it was written.
     *
     * Joomla accepts exactly one shape and answers every other with a quiet true. Checked on
     * 6.1.3: a bare <field />, a row of them, a <form> without a fieldset and a <fields> root all
     * load "successfully" and add nothing at all, which leaves nothing to notice and nothing to
     * explain. The wrapper is put on here so every one of them works. A real syntax error is
     * reported instead of being left to php, which prints its parser warnings into the page.
     *
     * @param   string  $source  The contents of the extra_fields parameter.
     *
     * @return  \DOMDocument|null  Null when the text cannot be read as XML.
     *
     * @since   1.0.0
     */
    private function parseExtraFields(string $source): ?\DOMDocument
    {
        $previous = libxml_use_internal_errors(true);

        libxml_clear_errors();

        // Never LIBXML_NOENT: with entities substituted a field description turns into a file reader
        $options  = \LIBXML_NONET;
        $document = new \DOMDocument();

        if ($document->loadXML($source, $options)) {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            return $document;
        }

        $errors = libxml_get_errors();

        libxml_clear_errors();

        /*
         * A declaration has to stand at the very start of a document, so a text that opens with one
         * cannot be wrapped: that attempt would fail over the wrapper and not over the mistake.
         */
        if (str_starts_with($source, '<?xml')) {
            libxml_use_internal_errors($previous);
            $this->addExtraFieldNotice(
                Text::sprintf('MOD_MUCRONIX_CONTACT_EXTRA_FIELDS_BROKEN', $this->describeXmlErrors($errors))
            );

            return null;
        }

        $wrapped = new \DOMDocument();

        if ($wrapped->loadXML('<form><fieldset name="contact">' . $source . '</fieldset></form>', $options)) {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            return $wrapped;
        }

        /*
         * The complaints of the second attempt, not the first. Unwrapped, a row of fields is
         * already wrong for having two roots, and that complaint would hide the real mistake
         * further down. The wrapper opens and closes on the first line, so the line numbers still
         * point at the text as it was typed.
         */
        $errors = libxml_get_errors();

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->addExtraFieldNotice(
            Text::sprintf('MOD_MUCRONIX_CONTACT_EXTRA_FIELDS_BROKEN', $this->describeXmlErrors($errors)),
            \sprintf('the field description could not be read as xml: %s', $this->forLog($this->describeXmlErrors($errors, false)))
        );

        return null;
    }

    /**
     * Puts the parser errors into one sentence naming the line and the reason.
     *
     * @param   \LibXMLError[]  $errors  What libxml reported.
     * @param   boolean         $escape  True for the block on the page, false for the plain log line.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function describeXmlErrors(array $errors, bool $escape = true): string
    {
        $lines = [];

        foreach ($errors as $error) {
            // The first ones are the cause, the rest are its consequences and only make the notice longer
            $message = trim((string) $error->message);
            $lines[] = Text::sprintf(
                'MOD_MUCRONIX_CONTACT_EXTRA_FIELDS_XML_ERROR',
                (int) $error->line,
                $escape ? $this->escape($message) : $message
            );

            if (\count($lines) === 2) {
                break;
            }
        }

        return $lines === [] ? Text::_('MOD_MUCRONIX_CONTACT_EXTRA_FIELDS_XML_ERROR_UNKNOWN') : implode(' ', $lines);
    }

    /**
     * Returns the name of an extra field, or an empty string when it may not be used.
     *
     * @param   \DOMElement  $node   The field element.
     * @param   array        $taken  The names already used, lowercase keys.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function getVettedName(\DOMElement $node, array $taken): string
    {
        $name = trim($node->getAttribute('name'));

        if ($name === '') {
            $this->addExtraFieldNotice(
                Text::_('MOD_MUCRONIX_CONTACT_EXTRA_FIELDS_NO_NAME'),
                'a field without a name was skipped'
            );

            return '';
        }

        /*
         * No dot and no hyphen, and that is the point of the pattern rather than a side effect.
         * FormField::getId() replaces every character outside A-Z a-z 0-9 and the underscore by an
         * underscore, and getFieldset() keys its result by that id: a.b, a-b and a_b all come out
         * as one key, and every field but the last vanishes from the page without a word. Checked
         * on Joomla 6.1.3. Letters, digits and underscores are the only characters that survive
         * that replacement unchanged, so a name that passes here can collide with nothing.
         */
        if (!preg_match(self::EXTRA_NAME_PATTERN, $name)) {
            $this->addExtraFieldNotice(
                Text::sprintf('MOD_MUCRONIX_CONTACT_EXTRA_FIELDS_BAD_NAME', $this->escape($name)),
                \sprintf('field "%s" skipped, the name may hold letters, digits and underscores only', $this->forLog($name))
            );

            return '';
        }

        /*
         * The names the module uses itself. Form::load() would put this field in place of ours and
         * report success: captcha leaves a text box that accepts anything, mcx_hp turns the honeypot
         * into a field nobody can see, and a default value on it makes every message look like a bot
         * and disappear behind a thank you. mcx_module_id cannot collide while the form is built
         * with a control, and is on the list anyway, see the comment in getForm().
         */
        if (\in_array(strtolower($name), self::RESERVED_FIELDS, true)) {
            $this->addExtraFieldNotice(
                Text::sprintf('MOD_MUCRONIX_CONTACT_EXTRA_FIELDS_RESERVED', $this->escape($name)),
                \sprintf('field "%s" skipped, the name belongs to the module', $this->forLog($name))
            );

            return '';
        }

        if (isset($taken[strtolower($name)])) {
            $this->addExtraFieldNotice(
                Text::sprintf('MOD_MUCRONIX_CONTACT_EXTRA_FIELDS_DUPLICATE', $this->escape($name)),
                \sprintf('field "%s" skipped, the name is used more than once', $this->forLog($name))
            );

            return '';
        }

        return $name;
    }

    /**
     * Tells whether the type of an extra field is one this form accepts.
     *
     * @param   \DOMElement  $node  The field element.
     * @param   string       $name  The name of the field, for the notice.
     *
     * @return  boolean
     *
     * @since   1.0.0
     */
    private function hasVettedType(\DOMElement $node, string $name): bool
    {
        $type = strtolower(trim($node->getAttribute('type')));

        if ($type === '') {
            $this->addExtraFieldNotice(
                Text::sprintf('MOD_MUCRONIX_CONTACT_EXTRA_FIELDS_NO_TYPE', $this->escape($name)),
                \sprintf('field "%s" skipped, no type given', $this->forLog($name))
            );

            return false;
        }

        /*
         * A white list, and it has to be one. The field types of Joomla do more than collect text:
         * checked on 6.1.3, type="sql" runs the query written in its attribute and renders what the
         * database answered, which turns the right to edit a module into the right to read tables.
         * user and editor pull parts of the administration into a public page, file would open a
         * second upload beside the one that is checked, and hidden puts a value into the message
         * that nobody typed.
         */
        if (!\in_array($type, self::ALLOWED_EXTRA_TYPES, true)) {
            $this->addExtraFieldNotice(
                Text::sprintf(
                    'MOD_MUCRONIX_CONTACT_EXTRA_FIELDS_BAD_TYPE',
                    $this->escape($name),
                    $this->escape($type),
                    implode(', ', self::ALLOWED_EXTRA_TYPES)
                ),
                \sprintf('field "%s" skipped, the type "%s" is not allowed', $this->forLog($name), $this->forLog($type))
            );

            return false;
        }

        return true;
    }

    /**
     * Takes off the attributes an extra field may not carry.
     *
     * @param   \DOMElement  $node  The field element.
     * @param   string       $name  The name of the field, for the notice.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function stripRefusedAttributes(\DOMElement $node, string $name): void
    {
        /*
         * id first. It is the second door into the same room as a reserved name, and the list of
         * names does not guard it: getFieldset() keys its result by the id, so a field of any name
         * carrying id="mcx_hp" pushes the honeypot out of the page while leaving it in the form.
         * Checked on Joomla 6.1.3: the protection is gone and nothing says so. The id is not needed
         * here at all, it is derived from the name. layout and layoutIncludePath would point the
         * field at a php file named in a setting, fieldset would move it elsewhere on the page.
         *
         * The add*path and add*prefix pair matters just as much, because they decide which class a
         * type resolves to and would leave the white list of types meaning nothing. Form::syncPaths()
         * reads them with the xpath //*[@addfieldprefix], so any element carries them, a <field>
         * included, and there is no need to own the <form> to set one. Checked on Joomla 6.1.3:
         * addfieldprefix="Vendor\Field" on a single field made type="text" resolve to that
         * namespace's TextField, the type string unchanged. FormHelper::addPrefix() unshifts the new
         * namespace in front of the core one, and both the prefix list and the path list are static
         * for the whole request, so the next form built after ours is served from there as well.
         */
        foreach (self::REFUSED_EXTRA_ATTRIBUTES as $attribute) {
            if (!$node->hasAttribute($attribute)) {
                continue;
            }

            $node->removeAttribute($attribute);
            $this->addExtraFieldNotice(
                Text::sprintf('MOD_MUCRONIX_CONTACT_EXTRA_FIELDS_ATTRIBUTE_DROPPED', $this->escape($name), $attribute),
                \sprintf('field "%s", the attribute "%s" was removed', $this->forLog($name), $attribute)
            );
        }
    }

    /**
     * Keeps a complaint about the field description for the warning block and for the log line.
     *
     * @param   string  $display  The ready translated message for the page.
     * @param   string  $log      The same thing in one plain line for the log.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function addExtraFieldNotice(string $display, string $log): void
    {
        // One mistake repeated over several fields is worth saying once
        foreach ($this->extraFieldNotices as $notice) {
            if ($notice['display'] === $display) {
                return;
            }
        }

        /*
         * Kept, not written. Drawing the form used to put every one of these into the log, so a page
         * carrying a module with two mistakes in its field description wrote two lines per view.
         * logSettingsNotices() writes them once, when a message comes in.
         */
        $this->extraFieldNotices[] = ['display' => $display, 'log' => $log];
    }

    /**
     * Escapes a piece of the settings for the warning block, which prints its messages as they are.
     *
     * @param   string  $value  The text as it was typed.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Collects the notices only someone who can fix them should see.
     *
     * @param   Registry                 $params  The module parameters.
     * @param   CMSApplicationInterface  $app     The application.
     *
     * @return  string[]  Ready translated messages.
     *
     * @since   1.0.0
     */
    public function getAdminWarnings(Registry $params, CMSApplicationInterface $app): array
    {
        return array_column($this->collectNotices($params, $app), 'display');
    }

    /**
     * Writes the settings that need attention to the log, once, as one line naming the instance.
     *
     * Not while the form is being drawn, which is what this used to do: the notices went to the log
     * on every single view of every page carrying the module, two lines at a time, from visitors and
     * crawlers alike. A live site would bury its real errors under that within a day. The block on
     * the page is the channel for the owner and it is unchanged; the log records what happened to
     * messages, so it is written here, where a message actually arrived.
     *
     * @param   Registry                 $params  The module parameters.
     * @param   \stdClass                $module  The module object.
     * @param   CMSApplicationInterface  $app     The application.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function logSettingsNotices(Registry $params, \stdClass $module, CMSApplicationInterface $app): void
    {
        $notices = $this->collectNotices($params, $app);

        if ($notices === []) {
            return;
        }

        $this->registerLogger();

        // The instance is named: on a site with several forms the notice alone says nothing
        Log::add(
            \sprintf(
                'Module %d, settings that need attention: %s',
                (int) $module->id,
                implode('; ', array_column($notices, 'log'))
            ),
            Log::WARNING,
            'mod_mucronix_contact'
        );
    }

    /**
     * Gathers everything worth telling the owner about, in both wordings at once.
     *
     * @param   Registry                 $params  The module parameters.
     * @param   CMSApplicationInterface  $app     The application.
     *
     * @return  array<int, array{display: string, log: string}>
     *
     * @since   1.0.0
     */
    private function collectNotices(Registry $params, CMSApplicationInterface $app): array
    {
        // Collected while the form was built, which the dispatcher does before it asks for these
        $warnings = $this->extraFieldNotices;
        $captcha  = $this->getMissingCaptcha($params, $app);

        if ($captcha !== '') {
            $warnings[] = [
                'display' => Text::sprintf('MOD_MUCRONIX_CONTACT_CAPTCHA_MISSING', htmlspecialchars($captcha, ENT_QUOTES, 'UTF-8')),
                'log'     => \sprintf('captcha plugin "%s" is selected but disabled or not installed', $this->forLog($captcha)),
            ];
        }

        $successPage = $this->getSuccessPageNotice($params, $app);

        // Only the two wordings: whether it also rolls the sending back is no business of this list
        if ($successPage !== null) {
            $warnings[] = ['display' => $successPage['display'], 'log' => $successPage['log']];
        }

        /*
         * The page cache plugin serves whole pages to guests from a store, and nothing the module
         * can do reaches it: the veto event onPageCacheSetCaching is dispatched at onAfterRoute,
         * long before any module is drawn, and only a plugin of the pagecache group may answer it.
         * So this is said rather than handled, and it is said whenever the plugin is on - whether
         * a guest can reach this particular form is not something that can be read from here.
         */
        if (PluginHelper::isEnabled('system', 'cache')) {
            $warnings[] = [
                'display' => Text::_('MOD_MUCRONIX_CONTACT_PAGE_CACHE_ON'),
                'log'     => 'the page cache plugin is on; the page holding this form has to be excluded in its settings,'
                    . ' or guests get a cached form token and, after a submission, a cached thank-you in place of the form',
            ];
        }

        /*
         * Switched on and unusable is the quiet kind of broken: the form sends, the mail arrives and
         * the chat stays empty, with only the log to say why.
         */
        if ($params->get('telegram_enabled', 0)) {
            $missing = [];

            if (trim((string) $params->get('telegram_token', '')) === '') {
                $missing[] = Text::_('MOD_MUCRONIX_CONTACT_FIELD_TELEGRAM_TOKEN_LABEL');
            }

            if (trim((string) $params->get('telegram_chat_id', '')) === '') {
                $missing[] = Text::_('MOD_MUCRONIX_CONTACT_FIELD_TELEGRAM_CHAT_ID_LABEL');
            }

            if ($missing !== []) {
                $warnings[] = [
                    'display' => Text::sprintf('MOD_MUCRONIX_CONTACT_TELEGRAM_INCOMPLETE', implode(', ', $missing)),
                    'log'     => \sprintf('Telegram is switched on but not configured, missing: %s', implode(', ', $missing)),
                ];
            }
        }

        if ($params->get('show_attachment', 0)) {
            $refused = $this->getRefusedExtensions($params);

            // Named rather than dropped in silence, or the owner keeps wondering why the file bounces
            if ($refused !== []) {
                $warnings[] = [
                    'display' => Text::sprintf('MOD_MUCRONIX_CONTACT_ATTACHMENT_TYPES_REFUSED', implode(', ', $refused)),
                    'log'     => \sprintf('attachment types refused whatever the setting says: %s', $this->forLog(implode(', ', $refused))),
                ];
            }

            $wanted = max(1, (int) $params->get('attachment_maxsize', 2)) * 1048576;
            $server = $this->getServerLimitBytes();

            // Both numbers are named: otherwise the owner keeps turning a setting that changes nothing
            if ($server > 0 && $wanted > $server) {
                $warnings[] = [
                    'display' => Text::sprintf(
                        'MOD_MUCRONIX_CONTACT_ATTACHMENT_LIMIT_WARNING',
                        (string) round($wanted / 1048576, 1),
                        (string) round($server / 1048576, 1)
                    ),
                    'log'     => \sprintf(
                        'the attachment limit is set to %s MB but this server accepts %s MB',
                        (string) round($wanted / 1048576, 1),
                        (string) round($server / 1048576, 1)
                    ),
                ];
            }
        }

        return $warnings;
    }

    /**
     * Makes a value from the settings fit for one line of a log file.
     *
     * @param   string  $value  The text as it was typed.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function forLog(string $value): string
    {
        // A tab or a newline would break the row format of the log, and a whole xml has no place in it
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        return mb_strlen($value) > 120 ? mb_substr($value, 0, 120) . '…' : $value;
    }

    /**
     * Returns the name of a selected captcha plugin that is not available, or an empty string.
     *
     * A plugin that is switched off or uninstalled does not make the field disappear: Joomla boots
     * plugins from the filesystem and falls back to a dummy, so the field is built, shows nothing
     * and never verifies. The form then cannot be sent at all, with no sign of why.
     *
     * @param   Registry                 $params  The module parameters.
     * @param   CMSApplicationInterface  $app     The application.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function getMissingCaptcha(Registry $params, CMSApplicationInterface $app): string
    {
        $name = trim((string) $params->get('captcha', ''));

        if ($name === '') {
            // The order CaptchaField uses: the menu item of the page first, then the site setting
            $default = (string) $app->get('captcha', '');
            $name    = $app->isClient('site') ? (string) $app->getParams()->get('captcha', $default) : $default;
        }

        // No captcha at all is a legitimate setting, there is nothing to warn about
        if ($name === '' || $name === '0' || PluginHelper::isEnabled('captcha', $name)) {
            return '';
        }

        return $name;
    }

    /**
     * Handles a form posted without JavaScript. Does nothing when the request does not belong to this instance.
     *
     * @param   Form                     $form    The contact form of this instance.
     * @param   Registry                 $params  The module parameters.
     * @param   \stdClass                $module  The module object.
     * @param   CMSApplicationInterface  $app     The application.
     *
     * @return  array|null  The result, or null when there is nothing to handle.
     *
     * @since   1.0.0
     */
    public function handleSubmission(Form $form, Registry $params, \stdClass $module, CMSApplicationInterface $app): ?array
    {
        $input    = $app->getInput();
        $moduleId = (int) $module->id;
        $session  = $app->getSession();
        $key      = self::RESULT_KEY . $moduleId;

        /*
         * Past post_max_size php throws the whole body away: no fields, no file, and no token or
         * module id either, so no instance can prove the request was meant for it. Every form on
         * the page that takes files says the same thing, and says it right here: with nothing to
         * verify there is nothing to store and nowhere to redirect.
         */
        if ($this->isRequestTooLarge($app)) {
            return $params->get('show_attachment', 0)
                ? $this->failure([Text::_('MOD_MUCRONIX_CONTACT_ERROR_REQUEST_TOO_BIG')])
                : null;
        }

        if (strtoupper($input->getMethod()) === 'POST' && $input->post->getInt('mcx_module_id', 0) === $moduleId) {
            $result = $this->process($form, $params, $module, $app);

            /*
             * A thank-you page was chosen, so that page is the answer and nothing is carried over:
             * a result left in the session would be shown out of nowhere the next time the visitor
             * opened the page with the form on it. It also means this branch needs no session at
             * all, which is why it stands above the check for one.
             */
            if (!empty($result['redirect'])) {
                $app->setHeader('Status', '303', true);
                $app->setHeader('Location', $result['redirect'], true);

                return null;
            }

            /*
             * Without a usable session the outcome cannot survive a redirect, so it is drawn right
             * here as before. Silence after sending is the worst answer: the visitor decides that
             * nothing arrived and writes a second time.
             */
            if ($session->isNew()) {
                $this->restore($form, $result);

                return $result;
            }

            $session->set($key, $result);

            /*
             * Post/Redirect/Get: a refresh then repeats a plain read instead of asking whether to
             * send the form again. These are the headers redirect() sets itself; setting them
             * without it keeps the rest of the page rendering, where redirect() would respond and
             * close on the spot and leave every module below this one undrawn.
             */
            $app->setHeader('Status', '303', true);
            $app->setHeader('Location', Uri::getInstance()->toString() . '#mcx-' . $moduleId, true);

            return null;
        }

        $stored = $session->get($key);

        if (!\is_array($stored)) {
            return null;
        }

        // Shown once: the next read finds a clean form, and no cache may keep this answer
        $session->remove($key);
        $app->allowCache(false);
        $this->restore($form, $stored);

        return $stored;
    }

    /**
     * Puts the values the visitor typed back into the form.
     *
     * @param   Form        $form    The contact form of this instance.
     * @param   array|null  $result  The outcome of a submission.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function restore(Form $form, ?array $result): void
    {
        if (\is_array($result) && !empty($result['data'])) {
            $form->bind($result['data']);
        }
    }

    /**
     * Handles the form sent by fetch. com_ajax calls this method for module=mucronix_contact&method=send.
     *
     * @return  array  ['success' => bool, 'message' => string, 'messages' => string[], 'errors' => array<string, string>]
     *
     * @since   1.0.0
     */
    public function sendAjax(): array
    {
        $app = Factory::getApplication();

        /*
         * com_ajax calls a namespaced helper without a try/catch of its own, unlike the old style
         * helpers. Anything thrown here would leave as an http 500 with the class, the file path
         * and the line in it, so nothing may escape this method.
         */
        try {
            // com_ajax loads the language only for the old style helpers, otherwise the texts stay raw keys
            $language = $app->getLanguage();
            $language->load('mod_mucronix_contact', JPATH_SITE) || $language->load('mod_mucronix_contact', JPATH_SITE . '/modules/mod_mucronix_contact');

            // A missing key returns the default, and the default of getInt is null, not zero
            $module = $this->getModule($app->getInput()->post->getInt('mcx_module_id', 0), $app);

            if ($module === null) {
                return $this->failure([Text::_('MOD_MUCRONIX_CONTACT_ERROR_SEND')]);
            }

            $params = new Registry($module->params);
            $result = $this->process($this->getForm($params, $module), $params, $module, $app);

            // The script keeps what the visitor typed in the page, there is no need to send it back
            unset($result['data']);

            return $result;
        } catch (\Throwable $e) {
            $this->registerLogger();
            Log::add($e->getMessage(), Log::ERROR, 'mod_mucronix_contact');

            return $this->failure([Text::_('MOD_MUCRONIX_CONTACT_ERROR_SEND')]);
        }
    }

    /**
     * Runs the checks and sends the message. Shared by the plain post and the fetch request.
     *
     * @param   Form                     $form    The contact form of this instance.
     * @param   Registry                 $params  The module parameters.
     * @param   \stdClass                $module  The module object.
     * @param   CMSApplicationInterface  $app     The application.
     *
     * @return  array
     *
     * @since   1.0.0
     */
    private function process(Form $form, Registry $params, \stdClass $module, CMSApplicationInterface $app): array
    {
        /*
         * The session is asked about first, and not as a nicety: Session::checkToken() does not
         * answer false for a session that has just been created. It enqueues a warning of its own,
         * calls $app->redirect() - respond() and close(), so the request ends inside it - and
         * returns true (Session::checkToken(), Joomla 6.1.3). Neither path ever reached the line
         * below.
         *
         * Where that redirect lands was measured rather than read off: it is built from index.php,
         * and SiteRouter::buildInit() merges the current request into it, so through com_ajax it
         * points straight back at the ajax endpoint - answered 303 to
         * /component/ajax?module=...&format=json&method=send. Fetch follows redirects by default,
         * asks that endpoint again as a GET, fails to read the answer as json and the catch-all
         * says "could not be sent, try again later" - advice to wait, where the answer is to
         * reload. A plain post is sent round the same way, losing the form and everything typed.
         *
         * A brand new session cannot be carrying a token that was minted by the one before it, so
         * refusing here is not a guess. The token itself is a hash of the user id and the session
         * token (Session::getFormToken()), which is why logging in or out in another tab spends it
         * even while the session lives on.
         */
        if ($app->getSession()->isNew() || !Session::checkToken()) {
            return $this->failure([Text::_('MOD_MUCRONIX_CONTACT_ERROR_TOKEN')]);
        }

        $raw = $app->getInput()->post->get('mcx_' . (int) $module->id, [], 'array');

        // A filled honeypot means a bot: answer as if everything went fine and send nothing
        if (trim((string) ($raw['mcx_hp'] ?? '')) !== '') {
            return $this->success($params, $app);
        }

        $session  = $app->getSession();
        $lastSent = (int) $session->get(self::THROTTLE_KEY, 0);

        if ($lastSent > 0 && (time() - $lastSent) < self::THROTTLE_SECONDS) {
            return $this->failure([Text::_('MOD_MUCRONIX_CONTACT_ERROR_TOO_OFTEN')], [], $raw);
        }

        /*
         * Once per submission, not once per view. Placed after the honeypot and the wait, so a bot
         * and a burst of refreshes cannot drive it either.
         */
        $this->logSettingsNotices($params, $module, $app);

        $data   = $form->filter($raw);
        $errors = $this->validate($form, $data, $params);
        $file   = $this->getUploadedFile($app, (int) $module->id, $params);

        if ($file !== null) {
            $fileError = $this->validateAttachment($file, $params);

            if ($fileError !== '') {
                $errors['attachment'] = $fileError;
            }
        }

        if ($errors !== []) {
            return $this->failure([Text::_('MOD_MUCRONIX_CONTACT_ERROR_VALIDATION')], $errors, $raw);
        }

        $this->registerLogger();
        $attachment = null;

        try {
            // Checked first, moved second: nothing unchecked ever reaches a folder of ours
            $attachment = $file !== null ? $this->storeAttachment($file, $app) : null;
        } catch (\Throwable $e) {
            Log::add($e->getMessage(), Log::ERROR, 'mod_mucronix_contact');

            return $this->failure(
                [Text::_('MOD_MUCRONIX_CONTACT_ERROR_VALIDATION')],
                ['attachment' => Text::_('MOD_MUCRONIX_CONTACT_ERROR_ATTACHMENT_FAILED')],
                $raw
            );
        }

        $pageUrl = $this->getPageUrl($app);

        try {
            (new MailSender())->send($form, $data, $params, $module, $app, $pageUrl, $attachment);

            /*
             * Second, and behind its own catch. The mail is the message and Telegram is a copy of
             * it: a chat that has moved, a token that was revoked or an API that does not answer
             * must not turn a message that has already been delivered into a refusal on the screen.
             * The owner learns of it from the log, the visitor has nothing to do with it.
             */
            if ($params->get('telegram_enabled', 0)) {
                try {
                    (new TelegramSender())->send($form, $data, $params, $pageUrl, $attachment);
                } catch (\Throwable $e) {
                    Log::add($e->getMessage(), Log::ERROR, 'mod_mucronix_contact');
                }
            }
        } catch (\Throwable $e) {
            // The visitor gets a plain explanation, the details go to the log
            Log::add($e->getMessage(), Log::ERROR, 'mod_mucronix_contact');

            return $this->failure([Text::_('MOD_MUCRONIX_CONTACT_ERROR_SEND')], [], $raw);
        } finally {
            // After every dispatch, not after the mail alone: Telegram wanted the same file
            if ($attachment !== null && is_file($attachment['path'])) {
                File::delete($attachment['path']);
            }
        }

        // Off by default: such a log grows without limit and records who wrote and when
        if ($params->get('log_sent', 0)) {
            Log::add(\sprintf('Message sent, module %d', (int) $module->id), Log::INFO, 'mod_mucronix_contact');
        }

        $session->set(self::THROTTLE_KEY, time());

        return $this->success($params, $app);
    }

    /**
     * Checks the data field by field, so that every message can be shown next to the field it belongs to.
     *
     * @param   Form      $form    The contact form of this instance.
     * @param   array     $data    The filtered form data.
     * @param   Registry  $params  The module parameters.
     *
     * @return  array<string, string>  Field name => message.
     *
     * @since   1.0.0
     */
    private function validate(Form $form, array $data, Registry $params): array
    {
        $errors = [];

        foreach (self::OPTIONAL_FIELDS as $name) {
            if (!$params->get('show_' . $name, 1)) {
                continue;
            }

            $value = trim((string) ($data[$name] ?? ''));

            if ($value === '') {
                if ($params->get('require_' . $name, 1)) {
                    $errors[$name] = Text::_('MOD_MUCRONIX_CONTACT_ERROR_REQUIRED');
                }

                continue;
            }

            if ($name === 'email' && !MailHelper::isEmailAddress($value)) {
                $errors[$name] = Text::_('MOD_MUCRONIX_CONTACT_ERROR_EMAIL');
            }

            if (isset(self::MAX_LENGTH[$name]) && mb_strlen($value) > self::MAX_LENGTH[$name]) {
                $errors[$name] = Text::_('MOD_MUCRONIX_CONTACT_ERROR_TOO_LONG');
            }
        }

        if ($params->get('show_consent', 1) && empty($data['consent'])) {
            $errors['consent'] = Text::_('MOD_MUCRONIX_CONTACT_ERROR_CONSENT');
        }

        /*
         * The extra fields, each one on its own. Form::validate() checks the whole form at once and
         * collects its messages in a flat list with no field attached to them, so an empty required
         * extra field would arrive below as a failed captcha and be shown under the captcha. Checked
         * on Joomla 6.1.3. FormField::validate() answers for one field and returns the message that
         * belongs to it. Running before the captcha also keeps that whole form check from starting:
         * it only runs while nothing else has gone wrong.
         */
        foreach ($form->getFieldset('contact') as $field) {
            if (\in_array($field->fieldname, self::RESERVED_FIELDS, true)) {
                continue;
            }

            $value = $data[$field->fieldname] ?? '';
            $value = \is_string($value) ? trim($value) : $value;

            try {
                $valid = $field->validate($value, null, new Registry($data));
            } catch (\Throwable $e) {
                // A rule named in the settings that does not exist: the visitor cannot do anything about it
                $this->registerLogger();
                Log::add($e->getMessage(), Log::ERROR, 'mod_mucronix_contact');

                $errors[$field->fieldname] = Text::_('MOD_MUCRONIX_CONTACT_ERROR_FIELD_INVALID');

                continue;
            }

            if ($valid instanceof \Exception) {
                $errors[$field->fieldname] = $valid->getMessage();

                continue;
            }

            // maxlength only reaches the browser, and the server checks everything again
            $maxLength = (int) $field->getAttribute('maxlength', 0);

            if ($maxLength > 0 && mb_strlen((string) $value) > $maxLength) {
                $errors[$field->fieldname] = Text::_('MOD_MUCRONIX_CONTACT_ERROR_TOO_LONG');
            }
        }

        if ($form->getField('captcha')) {
            /*
             * An empty value never goes to the rule: the proof of work plugin hands it straight to
             * base64_decode(), which warns about null. Without JavaScript the widget produces nothing,
             * so this is the normal case there, not an exception.
             */
            if (trim((string) ($data['captcha'] ?? '')) === '') {
                /*
                 * The message stays neutral on purpose: an empty value also happens with JavaScript
                 * on, when the proof of work is still being computed and the visitor is quicker.
                 * Only the noscript block may name JavaScript, it is shown exactly when it is off.
                 */
                $errors['captcha'] = Text::_('MOD_MUCRONIX_CONTACT_ERROR_CAPTCHA');
            } elseif ($errors === []) {
                /*
                 * The rule knows the captcha plugin chosen for the site. Only when the rest is clean:
                 * it checks every field, so a message missing above would come back a second time
                 * as a captcha failure.
                 */
                if (!$form->validate($data)) {
                    $errors['captcha'] = Text::_('MOD_MUCRONIX_CONTACT_ERROR_CAPTCHA');
                }
            }
        }

        return $errors;
    }

    /**
     * Returns the address of the page the form was sent from.
     *
     * Not Uri::getInstance(): that is the current request, and through com_ajax the current request
     * is the endpoint of com_ajax, the same string for every form on the site. The page is known
     * while the form is being drawn, so the layout writes it into a hidden input next to
     * mcx_module_id and it travels back with the submission, on both paths alike.
     *
     * @param   CMSApplicationInterface  $app  The application.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function getPageUrl(CMSApplicationInterface $app): string
    {
        $input  = $app->getInput();
        $posted = trim((string) $input->post->getString('mcx_page', ''));

        /*
         * The value comes back through the visitor, so it is checked rather than trusted. The
         * recipient reads this row as something the system filled in, decides by it which form
         * wrote and clicks it: a foreign address here would be worse than any line in the body,
         * which is read as the visitor's own words. Uri::isInternal() is what the core uses on the
         * addresses it is given to return to.
         */
        if ($posted !== '' && Uri::isInternal($posted)) {
            return $posted;
        }

        /*
         * Nothing usable came back: a page cached before the update, or a template override that
         * drops the input. Without JavaScript the form posts to the page itself, so the request is
         * the page and the old way is still right there. Through com_ajax it is not, and the root
         * of the site is the honest answer: it says less, but it says nothing false.
         */
        return $input->get('option') === 'com_ajax' ? Uri::root() : Uri::getInstance()->toString();
    }

    /**
     * Tells whether the request went past what this server accepts.
     *
     * Beyond post_max_size php discards the body, so both the fields and the files arrive empty
     * while the browser did send something. That contradiction is the only trace left.
     *
     * @param   CMSApplicationInterface  $app  The application.
     *
     * @return  boolean
     *
     * @since   1.0.0
     */
    private function isRequestTooLarge(CMSApplicationInterface $app): bool
    {
        $input = $app->getInput();

        if (strtoupper($input->getMethod()) !== 'POST') {
            return false;
        }

        /*
         * Only a form upload. An empty body with a length behind it also describes any post php
         * does not parse, json from someone else's script among them, and mixing our message into
         * a request that was never meant for us would be worse than saying nothing.
         */
        if (stripos((string) $input->server->get('CONTENT_TYPE', '', 'string'), 'multipart/form-data') !== 0) {
            return false;
        }

        return (int) $input->server->get('CONTENT_LENGTH', 0, 'int') > 0 && $input->post->getArray() === [];
    }

    /**
     * The file extensions this form accepts.
     *
     * @param   Registry  $params  The module parameters.
     *
     * @return  string[]
     *
     * @since   1.0.0
     */
    private function getAllowedExtensions(Registry $params): array
    {
        // Whatever the owner writes in the setting, anything executable stays out
        return array_values(array_diff($this->getConfiguredExtensions($params), $this->getForbiddenExtensions()));
    }

    /**
     * The extensions the owner asked for that are refused anyway, so the warning can name them.
     *
     * @param   Registry  $params  The module parameters.
     *
     * @return  string[]
     *
     * @since   1.0.0
     */
    private function getRefusedExtensions(Registry $params): array
    {
        return array_values(array_intersect($this->getConfiguredExtensions($params), $this->getForbiddenExtensions()));
    }

    /**
     * The extensions written in the setting, as written.
     *
     * @param   Registry  $params  The module parameters.
     *
     * @return  string[]
     *
     * @since   1.0.0
     */
    private function getConfiguredExtensions(Registry $params): array
    {
        return array_filter(array_map('trim', explode(',', strtolower((string) $params->get('attachment_types', '')))));
    }

    /**
     * Extensions no setting may allow.
     *
     * @return  string[]
     *
     * @since   1.0.0
     */
    private function getForbiddenExtensions(): array
    {
        return array_merge(InputFilter::FORBIDDEN_FILE_EXTENSIONS, MediaHelper::EXECUTABLES);
    }

    /**
     * The largest upload this server allows, in bytes, or zero when nothing limits it.
     *
     * @return  integer
     *
     * @since   1.0.0
     */
    private function getServerLimitBytes(): int
    {
        $media  = new MediaHelper();
        $limits = [];

        foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
            $value = (string) ini_get($setting);

            if ($value === '') {
                continue;
            }

            $bytes = (int) $media->toBytes($value);

            if ($bytes > 0) {
                $limits[] = $bytes;
            }
        }

        return $limits === [] ? 0 : min($limits);
    }

    /**
     * The limit that actually applies: the setting, capped by what the server allows.
     *
     * @param   Registry  $params  The module parameters.
     *
     * @return  integer  Bytes.
     *
     * @since   1.0.0
     */
    private function getMaxBytes(Registry $params): int
    {
        $wanted = max(1, (int) $params->get('attachment_maxsize', 2)) * 1048576;
        $server = $this->getServerLimitBytes();

        /*
         * Capped without a word to the visitor: an owner who asks for more than php gives would
         * otherwise break a working form. The warning above the form names both numbers.
         */
        return $server > 0 ? min($wanted, $server) : $wanted;
    }

    /**
     * The applying limit as megabytes, for the message shown to the visitor.
     *
     * @param   Registry  $params  The module parameters.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function getMaxMegabytes(Registry $params): string
    {
        return (string) round($this->getMaxBytes($params) / 1048576, 1);
    }

    /**
     * The attached file of this module instance, or null when there is none.
     *
     * @param   CMSApplicationInterface  $app       The application.
     * @param   integer                  $moduleId  The module instance.
     * @param   Registry                 $params    The module parameters.
     *
     * @return  array|null
     *
     * @since   1.0.0
     */
    private function getUploadedFile(CMSApplicationInterface $app, int $moduleId, Registry $params): ?array
    {
        if (!$params->get('show_attachment', 0)) {
            return null;
        }

        // Files::get() pivots the nested shape php builds for mcx_<id>[attachment]
        $files = $app->getInput()->files->get('mcx_' . $moduleId, [], 'array');
        $file  = $files['attachment'] ?? null;

        if (!\is_array($file) || !isset($file['error']) || (int) $file['error'] === \UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return $file;
    }

    /**
     * Checks an attached file. Nothing here moves it: it is still where php put it.
     *
     * @param   array     $file    The file descriptor.
     * @param   Registry  $params  The module parameters.
     *
     * @return  string  The message for the visitor, or an empty string when the file is fine.
     *
     * @since   1.0.0
     */
    private function validateAttachment(array $file, Registry $params): string
    {
        $error = (int) $file['error'];

        if ($error === \UPLOAD_ERR_INI_SIZE || $error === \UPLOAD_ERR_FORM_SIZE) {
            return Text::sprintf('MOD_MUCRONIX_CONTACT_ERROR_ATTACHMENT_TOO_BIG', $this->getMaxMegabytes($params));
        }

        if ($error !== \UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            return Text::_('MOD_MUCRONIX_CONTACT_ERROR_ATTACHMENT_FAILED');
        }

        /*
         * The size is checked before anything opens the file: the safety scan below walks the whole
         * of it, so an archive sitting at the limit must never get that far.
         */
        if ((int) $file['size'] > $this->getMaxBytes($params)) {
            return Text::sprintf('MOD_MUCRONIX_CONTACT_ERROR_ATTACHMENT_TOO_BIG', $this->getMaxMegabytes($params));
        }

        $allowed = $this->getAllowedExtensions($params);
        $list    = implode(', ', $allowed);
        $ext     = strtolower(File::getExt((string) $file['name']));

        if ($ext === '' || !\in_array($ext, $allowed, true)) {
            return Text::sprintf('MOD_MUCRONIX_CONTACT_ERROR_ATTACHMENT_TYPE', $list);
        }

        // What the content says, not what the name claims
        if (isset(self::MIME_MAP[$ext])) {
            $mime = (string) MediaHelper::getMimeType((string) $file['tmp_name']);

            if ($mime !== '' && !\in_array($mime, self::MIME_MAP[$ext], true)) {
                return Text::sprintf('MOD_MUCRONIX_CONTACT_ERROR_ATTACHMENT_TYPE', $list);
            }
        }

        // Null bytes, a buried .php in the name, php tags in the content, php inside an archive
        if (!InputFilter::isSafeFile($file)) {
            return Text::sprintf('MOD_MUCRONIX_CONTACT_ERROR_ATTACHMENT_TYPE', $list);
        }

        return '';
    }

    /**
     * Moves a checked file out of the php upload folder into the temporary folder of the site.
     *
     * @param   array                    $file  The file descriptor.
     * @param   CMSApplicationInterface  $app   The application.
     *
     * @return  array  ['path' => file on disk, 'name' => the name the visitor gave it]
     *
     * @since   1.0.0
     * @throws  \RuntimeException  When the file cannot be stored.
     */
    private function storeAttachment(array $file, CMSApplicationInterface $app): array
    {
        $name = (string) $file['name'];
        $base = File::makeSafe(File::stripExt($name));

        // Without the intl extension makeSafe strips a name that is not latin down to nothing
        $base = $base !== '' ? $base : 'attachment';
        $path = rtrim((string) $app->get('tmp_path'), '/\\')
            . '/mcx_' . bin2hex(random_bytes(6)) . '_' . $base . '.' . strtolower(File::getExt($name));

        /*
         * Unsafe is allowed here deliberately: the scan already ran above, and against the name the
         * visitor gave rather than the one we invent, while upload() would read the whole file again.
         */
        if (!File::upload((string) $file['tmp_name'], $path, false, true)) {
            throw new \RuntimeException(\sprintf('%s(): could not store the attachment', __METHOD__));
        }

        return ['path' => $path, 'name' => $name];
    }

    /**
     * Loads a published module instance the current visitor may see.
     *
     * ModuleHelper has no method for this: its module list is filtered by the menu item of the
     * current page, and a com_ajax request has none.
     *
     * @param   integer                  $id   The module id.
     * @param   CMSApplicationInterface  $app  The application.
     *
     * @return  \stdClass|null
     *
     * @since   1.0.0
     */
    private function getModule(int $id, CMSApplicationInterface $app): ?\stdClass
    {
        if ($id <= 0) {
            return null;
        }

        $db     = $this->getDatabase();
        $now    = Factory::getDate()->toSql();
        $levels = $app->getIdentity()->getAuthorisedViewLevels();

        $query = $db->createQuery()
            ->select($db->quoteName(['id', 'title', 'module', 'params']))
            ->from($db->quoteName('#__modules'))
            ->where(
                [
                    $db->quoteName('id') . ' = :id',
                    $db->quoteName('module') . ' = ' . $db->quote('mod_mucronix_contact'),
                    $db->quoteName('published') . ' = 1',
                    $db->quoteName('client_id') . ' = 0',
                ]
            )
            ->whereIn($db->quoteName('access'), $levels)
            ->extendWhere('AND', [$db->quoteName('publish_up') . ' IS NULL', $db->quoteName('publish_up') . ' <= :up'], 'OR')
            ->extendWhere('AND', [$db->quoteName('publish_down') . ' IS NULL', $db->quoteName('publish_down') . ' >= :down'], 'OR')
            ->bind(':id', $id, ParameterType::INTEGER)
            ->bind(':up', $now)
            ->bind(':down', $now);

        $db->setQuery($query);

        return $db->loadObject() ?: null;
    }

    /**
     * Registers the log file of the module.
     *
     * Without a logger of its own nothing is written at all: Joomla only keeps the messages
     * of the categories some extension has registered. Errors always go there; sent messages
     * only when the log_sent parameter is on.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function registerLogger(): void
    {
        static $registered = false;

        if ($registered) {
            return;
        }

        Log::addLogger(
            [
                'text_file'         => 'mod_mucronix_contact.php',
                'text_entry_format' => '{DATETIME}	{PRIORITY}	{CLIENTIP}	{MESSAGE}',
            ],
            Log::ALL,
            ['mod_mucronix_contact']
        );

        $registered = true;
    }

    /**
     * Builds the successful result, with the text from the parameters when it is set.
     *
     * The text is built whether a thank-you page is set or not: it is what the visitor sees if the
     * page turns out to lead nowhere, and the fallback has to be ready before that is known.
     *
     * @param   Registry                 $params  The module parameters.
     * @param   CMSApplicationInterface  $app     The application.
     *
     * @return  array
     *
     * @since   1.0.0
     */
    private function success(Registry $params, CMSApplicationInterface $app): array
    {
        $text = trim((string) $params->get('success_text', ''));
        $text = $text !== '' ? $text : Text::_('MOD_MUCRONIX_CONTACT_MESSAGE_SUCCESS');

        return [
            'success'  => true,
            'message'  => $text,
            'messages' => [$text],
            'errors'   => [],
            'redirect' => $this->getSuccessPageUrl($params, $app),
        ];
    }

    /**
     * Builds the address of the page the visitor is taken to after a successful submission.
     *
     * @param   Registry                 $params  The module parameters.
     * @param   CMSApplicationInterface  $app     The application.
     *
     * @return  string  The absolute address; an empty string when no page was chosen, or when the
     *                  one that was leads nowhere and the sending falls back to the form.
     *
     * @since   1.0.0
     */
    private function getSuccessPageUrl(Registry $params, CMSApplicationInterface $app): string
    {
        $itemId = (int) $params->get('success_page', 0);

        if ($itemId <= 0) {
            return '';
        }

        /*
         * A page there is no route to is worse than no page at all. The router answers with the
         * site root carrying the id, so the visitor is dropped on the front page straight after
         * sending a message, with nothing said anywhere. Falling back to the text on the form is
         * at least true, and the owner still learns of it from the notice and the log.
         *
         * Only the two cases where the address really leads nowhere roll back. A page closed to
         * guests is not one of them: it exists, it routes, and it works for everyone who is logged
         * in. That check is made against a guest and is a guess about who will send the form, so
         * acting on it would break a setting that was right - it stays a notice and nothing more.
         */
        $notice = $this->getSuccessPageNotice($params, $app);

        if ($notice !== null && $notice['fatal']) {
            return '';
        }

        /*
         * Absolute, because the address goes into a Location header and into the json the script
         * navigates by; and with the xhtml flag off, because neither of those two is markup and an
         * &amp; in there would travel as four characters too many.
         */
        return Route::_('index.php?Itemid=' . $itemId, false, Route::TLS_IGNORE, true);
    }

    /**
     * Returns what makes the chosen thank-you page lead nowhere, or null when it is sound.
     *
     * Three ways a page that was right when it was chosen stops being one, and all three are
     * silent: the item is deleted or unpublished, its type has no page behind it, or the page is
     * closed to guests. The first two end on the front page and the third on the login form, and
     * in every case the message has already gone out - so the owner sees nothing wrong, and only
     * the person who filled the form ever notices.
     *
     * The fatal flag separates the two that leave no address to go to, and which therefore roll
     * the sending back to the plain message on the form, from the one that does not: see
     * getSuccessPageUrl().
     *
     * @param   Registry                 $params  The module parameters.
     * @param   CMSApplicationInterface  $app     The application.
     *
     * @return  array{display: string, log: string, fatal: bool}|null
     *
     * @since   1.0.0
     */
    private function getSuccessPageNotice(Registry $params, CMSApplicationInterface $app): ?array
    {
        $itemId = (int) $params->get('success_page', 0);

        // Only the site application keeps a menu, and only it ever draws this module
        if ($itemId <= 0 || !$app instanceof CMSApplication) {
            return null;
        }

        /*
         * The site menu is the one source that answers all three questions at once. It holds
         * published items inside their publishing window only, so a deleted, unpublished or expired
         * one is simply absent; and it carries type and access, which MenusHelper::getMenuLinks() -
         * what the field itself is built from - does not: that query selects neither, so the field
         * can grey out a type but can say nothing whatever about access.
         */
        $item = $app->getMenu()->getItem($itemId);

        if ($item === null) {
            return [
                'display' => Text::sprintf('MOD_MUCRONIX_CONTACT_SUCCESS_PAGE_GONE', $itemId),
                'log'     => \sprintf('the thank-you page, menu item %d, is deleted, unpublished or out of its publishing dates', $itemId),
                'fatal'   => true,
            ];
        }

        /*
         * Everything that is not a component item is a system link: com_menus stores those with
         * component_id = 0 on purpose (com_menus ItemController::edit()), so the router finds no
         * component to build with, leaves the path empty and hands back the site root. That covers
         * separator, heading and container, which have no page at all, and equally url and alias,
         * which do lead somewhere but not by way of their own id.
         */
        if ($item->type !== 'component') {
            return [
                'display' => Text::sprintf(
                    'MOD_MUCRONIX_CONTACT_SUCCESS_PAGE_NO_PAGE',
                    $this->escape((string) $item->title),
                    $this->escape((string) $item->type)
                ),
                'log'     => \sprintf(
                    'the thank-you page "%s" is a menu item of type "%s" and has no page of its own',
                    $this->forLog((string) $item->title),
                    $this->forLog((string) $item->type)
                ),
                'fatal'   => true,
            ];
        }

        /*
         * Judged against a guest, because a guest is the visitor this can strand. On a page open
         * only to members every sender is logged in and the notice is beside the point, which is
         * why it names the level and says whom it concerns rather than calling the setting wrong.
         */
        if (!\in_array($item->access, Access::getAuthorisedViewLevels(0))) {
            $level = $this->getViewLevelTitle((int) $item->access);

            return [
                'display' => Text::sprintf(
                    'MOD_MUCRONIX_CONTACT_SUCCESS_PAGE_CLOSED',
                    $this->escape((string) $item->title),
                    $this->escape($level)
                ),
                'log'     => \sprintf(
                    'the thank-you page "%s" is open to "%s" only, so a visitor who is not logged in reaches the login form',
                    $this->forLog((string) $item->title),
                    $this->forLog($level)
                ),
                // Not fatal: the page is real and routes, it is only shut to people who are not logged in
                'fatal'   => false,
            ];
        }

        return null;
    }

    /**
     * Returns the name of a view level, so that a notice can name it instead of printing a number.
     *
     * @param   integer  $id  The view level id.
     *
     * @return  string  The title, or the id as text when there is no such level.
     *
     * @since   1.0.0
     */
    private function getViewLevelTitle(int $id): string
    {
        $db    = $this->getDatabase();
        $query = $db->createQuery()
            ->select($db->quoteName('title'))
            ->from($db->quoteName('#__viewlevels'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);
        $db->setQuery($query);

        return (string) ($db->loadResult() ?? $id);
    }

    /**
     * Builds the failed result.
     *
     * @param   string[]               $messages  The messages for the visitor.
     * @param   array<string, string>  $errors    Messages belonging to single fields.
     * @param   array                  $data      What the visitor typed, to fill the form again.
     *
     * @return  array
     *
     * @since   1.0.0
     */
    private function failure(array $messages, array $errors = [], array $data = []): array
    {
        return [
            'success'  => false,
            'message'  => $messages[0] ?? '',
            'messages' => $messages,
            'errors'   => $errors,
            'data'     => $data,
        ];
    }

    /**
     * Builds the label of the consent checkbox: the text, turned into a link when a policy article is selected.
     *
     * @param   Registry  $params  The module parameters.
     *
     * @return  string  HTML
     *
     * @since   1.0.0
     */
    private function getConsentLabel(Registry $params): string
    {
        $text = trim((string) $params->get('consent_text', ''));
        $text = htmlspecialchars($text !== '' ? $text : Text::_('MOD_MUCRONIX_CONTACT_FORM_CONSENT_LABEL'), ENT_QUOTES, 'UTF-8');
        $link = $this->getArticleLink((int) $params->get('consent_article', 0));

        if ($link === '') {
            return $text;
        }

        // A new tab keeps what the visitor has already typed into the form
        return '<a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">' . $text . '</a>';
    }

    /**
     * Returns the SEF link to an article, the same way plg_system_privacyconsent does.
     *
     * @param   integer  $id  The article id.
     *
     * @return  string  The link, or an empty string when there is no such article.
     *
     * @since   1.0.0
     */
    private function getArticleLink(int $id): string
    {
        if ($id <= 0) {
            return '';
        }

        $db    = $this->getDatabase();
        $query = $db->createQuery()
            ->select($db->quoteName(['id', 'alias', 'catid', 'language']))
            ->from($db->quoteName('#__content'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);
        $db->setQuery($query);
        $article = $db->loadObject();

        if (!$article) {
            return '';
        }

        $slug = $article->alias ? ($article->id . ':' . $article->alias) : $article->id;

        return Route::_(RouteHelper::getArticleRoute($slug, $article->catid, $article->language), false);
    }
}
