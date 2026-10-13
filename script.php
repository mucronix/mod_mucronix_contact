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
 * The module comes in two editions under one name, so one may be installed over the other. What
 * the installer of Joomla leaves undone on such a change is done here: see postflight().
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
                     * The element of the module, the same in every edition.
                     *
                     * @var    string
                     * @since  2.0.0
                     */
                    private const MODULE = 'mod_mucronix_contact';

                    /**
                     * The list a smaller edition carries in the root of its package: the files the
                     * larger one has on top of it, one path to a line. It is not named in the
                     * manifest, so it is read from the package and never installed.
                     *
                     * @var    string
                     * @since  2.0.0
                     */
                    private const SURPLUS_LIST = 'pro-files.txt';

                    /**
                     * The edition being installed: lite or pro.
                     *
                     * @var    string
                     * @since  2.0.0
                     */
                    private string $edition = '';

                    /**
                     * The edition found on the site before its files were replaced, or null when
                     * the module was not installed.
                     *
                     * @var    string|null
                     * @since  2.0.0
                     */
                    private ?string $previous = null;

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

                        /*
                         * Now, and not later: the manifest in the module folder is still the one of
                         * the edition that is installed, and the copying that follows replaces it.
                         * Checked on Joomla 6.1.3: InstallerAdapter::install() and update() call
                         * preflight before copyBaseFiles().
                         */
                        $this->edition  = $this->readEdition($adapter->getParent()->getManifest());
                        $this->previous = $this->getInstalledEdition();

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
                        if ($type === 'uninstall') {
                            return true;
                        }

                        /*
                         * Nothing here may fail the installation. By now the files are copied and the
                         * record is stored; a false or an exception would roll back an edition that
                         * works, over something that only needed tidying.
                         */
                        try {
                            $this->dropForeignUpdateSites($adapter);

                            // The smaller edition over the larger one: the larger leaves files and settings behind
                            if ($this->edition === 'lite' && $this->previous === 'pro') {
                                $this->removeSurplusFiles($adapter->getParent()->getPath('source') . '/' . self::SURPLUS_LIST);
                                $this->warnAboutSettings();
                            }
                        } catch (\Throwable $e) {
                            Log::add(self::MODULE . ': tidying up after the installation failed: ' . $e->getMessage(), Log::WARNING, 'jerror');
                        }

                        return true;
                    }

                    /**
                     * Reads the edition out of a manifest.
                     *
                     * A manifest that says nothing belongs to a version from before the editions,
                     * and those had everything the larger edition has: so that is what it counts as.
                     *
                     * @param   \SimpleXMLElement|null  $manifest  The manifest.
                     *
                     * @return  string  lite or pro
                     *
                     * @since   2.0.0
                     */
                    private function readEdition(?\SimpleXMLElement $manifest): string
                    {
                        $edition = $manifest ? strtolower(trim((string) $manifest->edition)) : '';

                        return $edition === 'lite' ? 'lite' : 'pro';
                    }

                    /**
                     * Returns the edition installed on the site, or null when there is none.
                     *
                     * Read from the manifest in the module folder and from nothing else. The settings
                     * of the module instances cannot answer this: what the larger edition stores stays
                     * in the database under the smaller one, and is often empty under the larger.
                     *
                     * @return  string|null
                     *
                     * @since   2.0.0
                     */
                    private function getInstalledEdition(): ?string
                    {
                        $file = JPATH_SITE . '/modules/' . self::MODULE . '/' . self::MODULE . '.xml';

                        if (!is_file($file)) {
                            return null;
                        }

                        $previous = libxml_use_internal_errors(true);
                        $manifest = simplexml_load_file($file);

                        libxml_clear_errors();
                        libxml_use_internal_errors($previous);

                        return $this->readEdition($manifest ?: null);
                    }

                    /**
                     * Leaves the module with the update source of the edition just installed, and no other.
                     *
                     * The editions are updated from different addresses, and the wrong one is not
                     * harmless: it would offer the next version of the other edition as an update of
                     * this one. Joomla does not see to it. Checked on 6.1.3, plg_extension_joomla,
                     * addUpdateSite(): for a manifest with a single server it rewrites the address of
                     * the source the extension already has and leaves the rest of the record as it
                     * was - the name, and with it the download key in extra_query, which would then
                     * be sent along to an address that never asked for one. And when a record with
                     * the new address exists already, it links that one and keeps the old link beside
                     * it, which makes two sources.
                     *
                     * So every source of this module whose address is not the one in the manifest is
                     * taken away here, the updates it offered with it. The plugin runs after this
                     * method and then finds no source at all, and adds a fresh one: the right name,
                     * the right address, no key. A source with the right address is not touched, so
                     * an update within one edition keeps its key.
                     *
                     * @param   InstallerAdapter  $adapter  The adapter calling this method.
                     *
                     * @return  void
                     *
                     * @since   2.0.0
                     */
                    private function dropForeignUpdateSites(InstallerAdapter $adapter): void
                    {
                        $location = trim((string) $adapter->getParent()->getManifest()->updateservers->server);

                        if ($location === '') {
                            return;
                        }

                        $db      = Factory::getContainer()->get(DatabaseInterface::class);
                        $element = self::MODULE;

                        $query = $db->createQuery()
                            ->select($db->quoteName('extension_id'))
                            ->from($db->quoteName('#__extensions'))
                            ->where(
                                [
                                    $db->quoteName('element') . ' = :element',
                                    $db->quoteName('type') . ' = ' . $db->quote('module'),
                                    $db->quoteName('client_id') . ' = 0',
                                ]
                            )
                            ->bind(':element', $element);

                        $extensionId = (int) $db->setQuery($query)->loadResult();

                        if ($extensionId === 0) {
                            return;
                        }

                        $query = $db->createQuery()
                            ->select($db->quoteName(['s.update_site_id', 's.location']))
                            ->from($db->quoteName('#__update_sites_extensions', 'e'))
                            ->join(
                                'INNER',
                                $db->quoteName('#__update_sites', 's'),
                                $db->quoteName('s.update_site_id') . ' = ' . $db->quoteName('e.update_site_id')
                            )
                            ->where($db->quoteName('e.extension_id') . ' = :extension')
                            ->bind(':extension', $extensionId, ParameterType::INTEGER);

                        foreach ($db->setQuery($query)->loadObjectList() as $site) {
                            if (trim((string) $site->location) === $location) {
                                continue;
                            }

                            $siteId = (int) $site->update_site_id;

                            $query = $db->createQuery()
                                ->delete($db->quoteName('#__update_sites_extensions'))
                                ->where(
                                    [
                                        $db->quoteName('update_site_id') . ' = :site',
                                        $db->quoteName('extension_id') . ' = :extension',
                                    ]
                                )
                                ->bind(':site', $siteId, ParameterType::INTEGER)
                                ->bind(':extension', $extensionId, ParameterType::INTEGER);

                            $db->setQuery($query)->execute();

                            $query = $db->createQuery()
                                ->delete($db->quoteName('#__updates'))
                                ->where(
                                    [
                                        $db->quoteName('update_site_id') . ' = :site',
                                        $db->quoteName('extension_id') . ' = :extension',
                                    ]
                                )
                                ->bind(':site', $siteId, ParameterType::INTEGER)
                                ->bind(':extension', $extensionId, ParameterType::INTEGER);

                            $db->setQuery($query)->execute();

                            // A source may serve other extensions as well; it goes only when nobody is left on it
                            $query = $db->createQuery()
                                ->select('COUNT(*)')
                                ->from($db->quoteName('#__update_sites_extensions'))
                                ->where($db->quoteName('update_site_id') . ' = :site')
                                ->bind(':site', $siteId, ParameterType::INTEGER);

                            if ((int) $db->setQuery($query)->loadResult() > 0) {
                                continue;
                            }

                            foreach (['#__updates', '#__update_sites'] as $table) {
                                $query = $db->createQuery()
                                    ->delete($db->quoteName($table))
                                    ->where($db->quoteName('update_site_id') . ' = :site')
                                    ->bind(':site', $siteId, ParameterType::INTEGER);

                                $db->setQuery($query)->execute();
                            }
                        }
                    }

                    /**
                     * Deletes the files the larger edition left behind, by the list in the package.
                     *
                     * Joomla does not do it. Checked on 6.1.3: on an update Installer::parseFiles()
                     * compares the old and the new <files> by their top entries only, so a folder
                     * gone from the manifest is removed but a file gone from a folder that stays is
                     * not; <media> and <languages> are not compared at all; and the module adapter
                     * does even that much only since 6.1.0. A class left lying in src/ stays within
                     * reach of the autoloader, which is reason enough not to leave it.
                     *
                     * Only what the list names is deleted, and the list holds only what the smaller
                     * edition does not ship: nothing it uses, and no setting of any module instance.
                     *
                     * @param   string  $list  The file holding the list.
                     *
                     * @return  void
                     *
                     * @since   2.0.0
                     */
                    private function removeSurplusFiles(string $list): void
                    {
                        if (!is_file($list)) {
                            Log::add(self::MODULE . ': the list of files to remove is missing from the package', Log::WARNING, 'jerror');

                            return;
                        }

                        $module = JPATH_SITE . '/modules/' . self::MODULE;
                        $media  = JPATH_SITE . '/media/' . self::MODULE;

                        foreach (file($list, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                            $line = trim($line);

                            // A path of plain names and nothing else: no way up, no way out of the two folders
                            if (!preg_match('#^[A-Za-z0-9_-][A-Za-z0-9_.-]*(/[A-Za-z0-9_-][A-Za-z0-9_.-]*)*$#', $line)) {
                                continue;
                            }

                            // In the package the media files lie in media/, on the site in the media folder of the module
                            [$root, $path] = str_starts_with($line, 'media/')
                                ? [$media, $media . '/' . substr($line, 6)]
                                : [$module, $module . '/' . $line];

                            if (is_file($path)) {
                                File::delete($path);
                            }

                            // A folder that held nothing else goes too, and so does the one above it
                            for ($folder = \dirname($path); $folder !== $root && is_dir($folder); $folder = \dirname($folder)) {
                                if (\count(scandir($folder) ?: []) > 2 || !@rmdir($folder)) {
                                    break;
                                }
                            }
                        }
                    }

                    /**
                     * Tells the site owner what the smaller edition does with the settings of the larger one.
                     *
                     * Not a refusal: the change is allowed. But it is not free either, and the moment
                     * it costs something is a later one - the next save of a module - when nothing on
                     * the screen would connect the loss with this installation.
                     *
                     * @return  void
                     *
                     * @since   2.0.0
                     */
                    private function warnAboutSettings(): void
                    {
                        $key     = 'MOD_MUCRONIX_CONTACT_INSTALL_OVER_PRO';
                        $message = Text::_($key);

                        // The language file of the package was not loaded: said in English rather than as a raw key
                        if ($message === $key) {
                            $message = 'Mucronix Contact Lite was installed over Mucronix Contact Pro. The settings of Pro are'
                                . ' dropped the next time a module of this type is saved.';
                        }

                        Factory::getApplication()->enqueueMessage($message, 'warning');
                    }
                };
            }
        );
    }
};
