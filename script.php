<?php

/**
 * @package     mod_mucronix_contact
 *
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Filesystem\File;

/**
 * The Mucronix Contact installer script service provider.
 *
 * The version check repeats Joomla\CMS\Installer\InstallerScriptTrait::checkCompatibility(),
 * but the trait itself is not used: it exists only since Joomla 6.0, and on older versions
 * this file would stop with a fatal error before the check could explain anything.
 *
 * @since  1.0.0
 */
return new class () implements ServiceProviderInterface {
    /**
     * Registers the service provider with a DI container.
     *
     * @param   Container  $container  The DI container.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function register(Container $container): void
    {
        $container->set(
            InstallerScriptInterface::class,
            function (Container $container) {
                return new class () implements InstallerScriptInterface {
                    /**
                     * Minimum PHP version required to install the extension
                     *
                     * @var    string
                     * @since  1.0.0
                     */
                    private const MINIMUM_PHP = '8.3.0';

                    /**
                     * Minimum Joomla version required to install the extension
                     *
                     * @var    string
                     * @since  1.0.0
                     */
                    private const MINIMUM_JOOMLA = '6.0.0';

                    /**
                     * Function called after the extension is installed.
                     *
                     * @param   InstallerAdapter  $adapter  The adapter calling this method
                     *
                     * @return  boolean  True on success
                     *
                     * @since   1.0.0
                     */
                    public function install(InstallerAdapter $adapter): bool
                    {
                        return true;
                    }

                    /**
                     * Function called after the extension is updated.
                     *
                     * @param   InstallerAdapter  $adapter  The adapter calling this method
                     *
                     * @return  boolean  True on success
                     *
                     * @since   1.0.0
                     */
                    public function update(InstallerAdapter $adapter): bool
                    {
                        return true;
                    }

                    /**
                     * Function called after the extension is uninstalled.
                     *
                     * @param   InstallerAdapter  $adapter  The adapter calling this method
                     *
                     * @return  boolean  True on success
                     *
                     * @since   1.0.0
                     */
                    public function uninstall(InstallerAdapter $adapter): bool
                    {
                        return true;
                    }

                    /**
                     * Function called before extension installation/update/removal procedure commences.
                     *
                     * @param   string            $type     The type of change (install or discover_install, update, uninstall)
                     * @param   InstallerAdapter  $adapter  The adapter calling this method
                     *
                     * @return  boolean  True on success
                     *
                     * @since   1.0.0
                     */
                    public function preflight(string $type, InstallerAdapter $adapter): bool
                    {
                        // The installer calls preflight on removal too; removal must stay possible on any server
                        if ($type === 'uninstall') {
                            return true;
                        }

                        if (version_compare(PHP_VERSION, self::MINIMUM_PHP, '<')) {
                            Log::add(Text::sprintf('JLIB_INSTALLER_MINIMUM_PHP', self::MINIMUM_PHP), Log::WARNING, 'jerror');

                            return false;
                        }

                        if (version_compare(JVERSION, self::MINIMUM_JOOMLA, '<')) {
                            Log::add(Text::sprintf('JLIB_INSTALLER_MINIMUM_JOOMLA', self::MINIMUM_JOOMLA), Log::WARNING, 'jerror');

                            return false;
                        }

                        return true;
                    }

                    /**
                     * Function called after extension installation/update/removal procedure commences.
                     *
                     * @param   string            $type     The type of change (install or discover_install, update, uninstall)
                     * @param   InstallerAdapter  $adapter  The adapter calling this method
                     *
                     * @return  boolean  True on success
                     *
                     * @since   1.0.0
                     */
                    public function postflight(string $type, InstallerAdapter $adapter): bool
                    {
                        if ($type !== 'update') {
                            return true;
                        }

                        $this->removeOldFiles();
                        $this->migrateAppearance();

                        return true;
                    }

                    /**
                     * Deletes what earlier versions left in the media folder.
                     *
                     * Checked on Joomla 6.1.3: a file dropped from the package survives an update of
                     * an extension. The folder css is in the manifest both times, so the installer
                     * copies the new files into it and leaves whatever else is lying there. Nothing
                     * links to form.css any more, but it stays on every site that ever had 1.0.x.
                     *
                     * @return  void
                     *
                     * @since   1.1.0
                     */
                    private function removeOldFiles(): void
                    {
                        // Split into form-base.css and form-theme.css in 1.1.0
                        $old = JPATH_SITE . '/media/mod_mucronix_contact/css/form.css';

                        if (is_file($old)) {
                            File::delete($old);
                        }
                    }

                    /**
                     * Moves the settings of every module instance from load_css to the parameters of 1.1.0.
                     *
                     * The aim is that a site looks the same the moment the update is over: "Basic" is
                     * the stylesheet of 1.0.x rule for rule, and "No Added Classes" is the button as it
                     * was, without btn-primary. New installations start at Full and Joomla instead, and
                     * that is decided by the manifest, not here.
                     *
                     * An instance carrying no load_css is left alone: it was either set up under 1.1.0
                     * already or its settings were never saved, and in both cases the manifest answers
                     * for it. That also makes running the same update twice harmless.
                     *
                     * @return  void
                     *
                     * @since   1.1.0
                     */
                    private function migrateAppearance(): void
                    {
                        try {
                            $db     = Factory::getContainer()->get(DatabaseInterface::class);
                            $module = 'mod_mucronix_contact';
                            $moved  = 0;

                            $query = $db->getQuery(true)
                                ->select($db->quoteName(['id', 'params']))
                                ->from($db->quoteName('#__modules'))
                                ->where($db->quoteName('module') . ' = :module')
                                ->bind(':module', $module, ParameterType::STRING);

                            foreach ($db->setQuery($query)->loadObjectList() as $instance) {
                                $params = json_decode((string) $instance->params, true);

                                if (!\is_array($params) || !\array_key_exists('load_css', $params)) {
                                    continue;
                                }

                                // The stylesheet was on: the layout of 1.0.x, and nothing beyond it
                                $params['style_mode'] = (int) $params['load_css'] === 1 ? 'base' : 'none';
                                $params['markup']     = 'none';

                                unset($params['load_css']);

                                $encoded = json_encode($params);
                                $id      = (int) $instance->id;

                                // Nothing is written on an encoding failure: half-written params are worse
                                if ($encoded === false) {
                                    continue;
                                }

                                $update = $db->getQuery(true)
                                    ->update($db->quoteName('#__modules'))
                                    ->set($db->quoteName('params') . ' = :params')
                                    ->where($db->quoteName('id') . ' = :id')
                                    ->bind(':params', $encoded, ParameterType::STRING)
                                    ->bind(':id', $id, ParameterType::INTEGER);

                                $db->setQuery($update)->execute();
                                $moved++;
                            }

                            if ($moved > 0) {
                                Factory::getApplication()->enqueueMessage(
                                    Text::sprintf('MOD_MUCRONIX_CONTACT_MIGRATION_DONE', $moved),
                                    'info'
                                );
                            }
                        } catch (\Throwable $e) {
                            /*
                             * A failed migration must not fail the update: the module works either way,
                             * only the look of the existing instances would go back to the defaults of
                             * the manifest. The site owner is told, and the reason is in the log.
                             */
                            Log::add(
                                'mod_mucronix_contact: moving the appearance settings failed: ' . $e->getMessage(),
                                Log::WARNING,
                                'jerror'
                            );
                        }
                    }
                };
            }
        );
    }
};
