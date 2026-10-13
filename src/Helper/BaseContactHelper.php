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
use Joomla\CMS\Form\FormFactoryInterface;
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
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Everything the contact form does in every edition of the module: the form, its checks, the
 * sending of the mail and the answer to the visitor.
 *
 * The helper the module asks for is MucronixContactHelper, which extends this class: com_ajax
 * dictates that name, and every edition ships its own file under it.
 *
 * @since  2.0.0
 */
abstract class BaseContactHelper implements DatabaseAwareInterface
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
     * Classes each Field Markup adds to the ones the core layouts print themselves, by the element a
     * field turns into on the page. Nothing here is ever taken away: form-control, form-select and
     * form-check-input stay in every variant, because only copies of the core layouts could remove
     * them, and every copy has to be compared with the original on each Joomla update.
     *
     * Radio buttons and checkbox lists get nothing. The radio layout writes form-check-input into the
     * input itself and hands a class of the field to the label instead, and a checkbox list takes its
     * input class from every single option: neither is reachable without a copy or without rewriting
     * options, which this simple form does not do for two field types.
     *
     * The field wrapper is not on the list in any variant. Spacing classes such as mb-3 or uk-margin
     * would bring back the two margins that --mcx-gap exists to replace.
     *
     * @var    array<string, array<string, string>>
     * @since  1.1.0
     */
    private const MARKUP_CLASSES = [
        'joomla' => [
            'button' => 'btn btn-primary',
        ],
        'uikit'  => [
            'input'    => 'uk-input',
            'textarea' => 'uk-textarea',
            'select'   => 'uk-select',
            'checkbox' => 'uk-checkbox',
            'label'    => 'uk-form-label',
            'button'   => 'uk-button uk-button-primary',
        ],
        'none'   => [],
    ];

    /**
     * The Field Markup of a new installation, and what an unknown value falls back to.
     *
     * @var    string
     * @since  1.1.0
     */
    private const MARKUP_DEFAULT = 'joomla';

    /**
     * The element each field type turns into, for MARKUP_CLASSES. A type missing here takes no class
     * on its input: radio and checkboxes on purpose, see above.
     *
     * @var    array<string, string>
     * @since  1.1.0
     */
    private const MARKUP_ELEMENTS = [
        'text'     => 'input',
        'email'    => 'input',
        'tel'      => 'input',
        'url'      => 'input',
        'number'   => 'input',
        'calendar' => 'input',
        'file'     => 'input',
        'textarea' => 'textarea',
        'list'     => 'select',
        'checkbox' => 'checkbox',
    ];

    /**
     * Fields that take no class of the markup at all, label included. The captcha is drawn by its
     * plugin, which receives the class of the field and would put it wherever it likes. The trap is
     * hidden by the markup, and a framework class carrying a display of its own could show it.
     *
     * @var    string[]
     * @since  1.1.0
     */
    private const MARKUP_SKIPPED = ['captcha', 'mcx_hp'];

    /**
     * The Own Styles values: full loads form-base.css and form-theme.css, base only the first,
     * none neither of them.
     *
     * @var    string[]
     * @since  1.1.0
     */
    private const STYLE_MODES = ['full', 'base', 'none'];

    /**
     * The Own Styles of a new installation, and what an unknown value falls back to.
     *
     * @var    string
     * @since  1.1.0
     */
    private const STYLE_DEFAULT = 'full';

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
         * The control is also what keeps the module id out of reach. Every field of the form is
         * posted as mcx_<id>[...], while the instance names itself in a plain mcx_module_id beside
         * it: different keys of $_POST, they cannot meet. Checked on Joomla 6.1.3, brackets and
         * quotes in a field name included: no name escapes the prefix. Take the control away one
         * day and a field could be posted under that very name.
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

        // Last, once the form holds exactly the fields that are going to be shown
        $this->applyMarkup($form, $params);

        return $form;
    }

    /**
     * Returns the Field Markup of the module instance: joomla, uikit or none.
     *
     * @param   Registry  $params  The module parameters.
     *
     * @return  string
     *
     * @since   1.1.0
     */
    public function getMarkup(Registry $params): string
    {
        $markup = (string) $params->get('markup', self::MARKUP_DEFAULT);

        return isset(self::MARKUP_CLASSES[$markup]) ? $markup : self::MARKUP_DEFAULT;
    }

    /**
     * Returns the Own Styles of the module instance: full, base or none.
     *
     * @param   Registry  $params  The module parameters.
     *
     * @return  string
     *
     * @since   1.1.0
     */
    public function getStyleMode(Registry $params): string
    {
        $mode = (string) $params->get('style_mode', self::STYLE_DEFAULT);

        return \in_array($mode, self::STYLE_MODES, true) ? $mode : self::STYLE_DEFAULT;
    }

    /**
     * Returns the classes the Field Markup gives the send button, empty for none.
     *
     * @param   Registry  $params  The module parameters.
     *
     * @return  string
     *
     * @since   1.1.0
     */
    public function getButtonClass(Registry $params): string
    {
        return self::MARKUP_CLASSES[$this->getMarkup($params)]['button'] ?? '';
    }

    /**
     * Returns the modifier classes of the root wrapper that switch the rules of form-theme.css on.
     * Empty unless the Own Styles is full: without form-theme.css they would mean nothing.
     *
     * @param   Registry  $params  The module parameters.
     *
     * @return  string
     *
     * @since   1.1.0
     */
    protected function getStyleModifiers(Registry $params): string
    {
        // The look form-theme.css draws by default: a border round every field
        return $this->getStyleMode($params) === 'full' ? 'mcx--fields-box' : '';
    }

    /**
     * Returns what the layout dresses the form with: the Own Styles, the classes of the wrapper,
     * of the form, of the box around a field and of the send button, and the rules to print in
     * the head after the stylesheets of the module, in that order.
     *
     * @param   Registry  $params    The module parameters.
     * @param   integer   $moduleId  The id of the module instance.
     *
     * @return  array{styleMode: string, wrapperClass: string, formClass: string, fieldClass: string, submitClass: string, inlineStyles: string[]}
     *
     * @since   2.0.0
     */
    public function getAppearance(Registry $params, int $moduleId): array
    {
        return [
            'styleMode'    => $this->getStyleMode($params),
            'wrapperClass' => trim('mcx ' . $this->getStyleModifiers($params)),
            'formClass'    => 'mcx-form',
            'fieldClass'   => 'mcx-field-wrap',
            'submitClass'  => trim('mcx-submit ' . $this->getButtonClass($params)),
            'inlineStyles' => [],
        ];
    }

    /**
     * Adds the classes of the Field Markup to the inputs and labels of the form.
     *
     * Written into the form definition before any field object exists: FormField::setup() reads
     * class and labelclass from there and appends required on its own. A class a field already
     * carries in the definition is kept, ours goes after it.
     *
     * @param   Form      $form    The form, with every field that is going to be shown.
     * @param   Registry  $params  The module parameters.
     *
     * @return  void
     *
     * @since   1.1.0
     */
    private function applyMarkup(Form $form, Registry $params): void
    {
        $classes = self::MARKUP_CLASSES[$this->getMarkup($params)];

        foreach ($form->getXml()->xpath('//fieldset[@name="contact"]/field') as $element) {
            $name = (string) $element['name'];
            $type = strtolower((string) $element['type']) ?: 'text';

            if (\in_array($name, self::MARKUP_SKIPPED, true) || $type === 'captcha') {
                continue;
            }

            $target = self::MARKUP_ELEMENTS[$type] ?? '';

            if (isset($classes[$target])) {
                $form->setFieldAttribute($name, 'class', $this->addClasses((string) $element['class'], $classes[$target]));
            }

            if (isset($classes['label'])) {
                $form->setFieldAttribute($name, 'labelclass', $this->addClasses((string) $element['labelclass'], $classes['label']));
            }
        }
    }

    /**
     * Appends classes to a class list, leaving out the ones already in it.
     *
     * @param   string  $list  The classes there are.
     * @param   string  $add   The classes to add.
     *
     * @return  string
     *
     * @since   1.1.0
     */
    private function addClasses(string $list, string $add): string
    {
        $classes = preg_split('/\s+/', trim($list . ' ' . $add), -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_unique($classes));
    }

    /**
     * Loads the form definition of the module.
     *
     * @param   Form      $form    The form to fill.
     * @param   Registry  $params  The module parameters.
     *
     * @return  void
     *
     * @since   1.0.0
     * @throws  \RuntimeException  If the form definition of the module cannot be read.
     */
    protected function loadDefinition(Form $form, Registry $params): void
    {
        if (!$form->loadFile(JPATH_SITE . '/modules/mod_mucronix_contact/forms/contact.xml')) {
            throw new \RuntimeException(\sprintf('%s() could not load the form definition', __METHOD__));
        }
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
    protected function escape(string $value): string
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
    protected function collectNotices(Registry $params, CMSApplicationInterface $app): array
    {
        $warnings = [];
        $captcha  = $this->getMissingCaptcha($params, $app);

        if ($captcha !== '') {
            $warnings[] = [
                'display' => Text::sprintf('MOD_MUCRONIX_CONTACT_CAPTCHA_MISSING', htmlspecialchars($captcha, ENT_QUOTES, 'UTF-8')),
                'log'     => \sprintf('captcha plugin "%s" is selected but disabled or not installed', $this->forLog($captcha)),
            ];
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
    protected function forLog(string $value): string
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

        if (strtoupper($input->getMethod()) === 'POST' && $input->post->getInt('mcx_module_id', 0) === $moduleId) {
            $result = $this->process($form, $params, $module, $app);

            /*
             * The result names a page to go to, so that page is the answer and nothing is carried over:
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

        if ($errors !== []) {
            return $this->failure([Text::_('MOD_MUCRONIX_CONTACT_ERROR_VALIDATION')], $errors, $raw);
        }

        $this->registerLogger();

        try {
            $this->deliver($form, $data, $params, $module, $app);
        } catch (\Throwable $e) {
            // The visitor gets a plain explanation, the details go to the log
            Log::add($e->getMessage(), Log::ERROR, 'mod_mucronix_contact');

            return $this->failure([Text::_('MOD_MUCRONIX_CONTACT_ERROR_SEND')], [], $raw);
        }

        $session->set(self::THROTTLE_KEY, time());

        return $this->success($params, $app);
    }

    /**
     * Hands a checked message over: the mail to the recipient. Whatever is thrown here is logged
     * by process() and answered with a plain refusal.
     *
     * @param   Form                     $form    The contact form of this instance.
     * @param   array                    $data    The filtered and validated form data.
     * @param   Registry                 $params  The module parameters.
     * @param   \stdClass                $module  The module object.
     * @param   CMSApplicationInterface  $app     The application.
     *
     * @return  void
     *
     * @since   2.0.0
     * @throws  \Throwable  When the message could not be sent.
     */
    protected function deliver(Form $form, array $data, Registry $params, \stdClass $module, CMSApplicationInterface $app): void
    {
        (new MailSender())->send($form, $data, $params, $module, $app, $this->getPageUrl($app));
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
    protected function validate(Form $form, array $data, Registry $params): array
    {
        return $this->validateCaptcha($form, $data, $this->validateFields($form, $data, $params));
    }

    /**
     * Checks the fields of the form, everything but the captcha.
     *
     * @param   Form      $form    The contact form of this instance.
     * @param   array     $data    The filtered form data.
     * @param   Registry  $params  The module parameters.
     *
     * @return  array<string, string>  Field name => message.
     *
     * @since   2.0.0
     */
    protected function validateFields(Form $form, array $data, Registry $params): array
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

        return $errors;
    }

    /**
     * Checks the captcha, after every other field and knowing what they came to.
     *
     * @param   Form                   $form    The contact form of this instance.
     * @param   array                  $data    The filtered form data.
     * @param   array<string, string>  $errors  What the fields have already been refused for.
     *
     * @return  array<string, string>  Field name => message, the captcha added when it failed.
     *
     * @since   2.0.0
     */
    protected function validateCaptcha(Form $form, array $data, array $errors): array
    {
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
                 * Form::validate() checks every field at once and collects its messages in a flat
                 * list with no field attached to them, so a message missing above would come back a
                 * second time as a captcha failure. Checked on Joomla 6.1.3.
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
    protected function getPageUrl(CMSApplicationInterface $app): string
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
     * of the categories some extension has registered.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    protected function registerLogger(): void
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
     * @param   Registry                 $params  The module parameters.
     * @param   CMSApplicationInterface  $app     The application.
     *
     * @return  array
     *
     * @since   1.0.0
     */
    protected function success(Registry $params, CMSApplicationInterface $app): array
    {
        $text = trim((string) $params->get('success_text', ''));
        $text = $text !== '' ? $text : Text::_('MOD_MUCRONIX_CONTACT_MESSAGE_SUCCESS');

        return [
            'success'  => true,
            'message'  => $text,
            'messages' => [$text],
            'errors'   => [],
        ];
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
    protected function failure(array $messages, array $errors = [], array $data = []): array
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
