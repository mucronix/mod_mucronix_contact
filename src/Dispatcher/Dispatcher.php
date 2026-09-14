<?php

/**
 * @package     mod_mucronix_contact
 *
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Mucronix\Module\MucronixContact\Site\Dispatcher;

use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use Joomla\CMS\Helper\HelperFactoryAwareInterface;
use Joomla\CMS\Helper\HelperFactoryAwareTrait;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Dispatcher class for mod_mucronix_contact
 *
 * @since  1.0.0
 */
class Dispatcher extends AbstractModuleDispatcher implements HelperFactoryAwareInterface
{
    use HelperFactoryAwareTrait;

    /**
     * Returns the layout data.
     *
     * @return  array
     *
     * @since   1.0.0
     */
    protected function getLayoutData(): array
    {
        $data = parent::getLayoutData();

        /** @var \Mucronix\Module\MucronixContact\Site\Helper\MucronixContactHelper $helper */
        $helper = $this->getHelperFactory()->getHelper('MucronixContactHelper');

        $data['form']           = $helper->getForm($data['params'], $this->module);
        $data['result']         = $helper->handleSubmission($data['form'], $data['params'], $this->module, $this->getApplication());
        $data['warnings']       = $helper->getAdminWarnings($data['params'], $this->getApplication());

        return $data;
    }
}
