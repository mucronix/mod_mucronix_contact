<?php

/**
 * @package     mod_mucronix_contact
 *
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

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
                        return true;
                    }
                };
            }
        );
    }
};
