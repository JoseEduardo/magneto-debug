<?php

/**
 * Class Sheep_Debug_Model_Service
 *
 * @category Sheep
 * @package  Sheep_Debug
 * @license  Copyright: Pirate Sheep, 2016
 * @link     https://piratesheep.com
 */
class Sheep_Debug_Model_Service
{

    /**
     * @return Mage_Core_Model_Config
     */
    protected function getConfig()
    {
        return Mage::getConfig();
    }


    /**
     * @return Mage_Core_Model_Cache
     */
    protected function getCacheInstance()
    {
        return Mage::app()->getCacheInstance();
    }


    /**
     * @param $filepath
     * @return SimpleXMLElement
     */
    protected function loadXmlFile($filepath)
    {
        return simplexml_load_file($filepath);
    }


    /**
     * @param SimpleXMLElement $xml
     * @param string $filepath
     * @return bool
     */
    public function saveXml($xml, $filepath)
    {
        return $xml->saveXML($filepath);
    }


    /**
     * Flushes cache instance.
     */
    public function flushCache()
    {
        $this->getCacheInstance()->flush();
    }


    /**
     * Returns module configuration file
     *
     * @param $moduleName
     * @return string
     * @throws Exception
     */
    public function getModuleConfigFilePath($moduleName)
    {
        $config = $this->getConfig();
        $moduleConfig = $config->getModuleConfig($moduleName);

        if (!$moduleConfig) {
            throw  new Exception("Unable to find module '{$moduleName}'");
        }

        return $config->getOptions()->getEtcDir() . DS . 'modules' . DS . $moduleName . '.xml';
    }


    /**
     * Sets active status for specified module
     *
     * @param string $moduleName
     * @param bool $isActive
     * @throws Exception
     */
    public function setModuleStatus($moduleName, $isActive)
    {
        $moduleConfigFile = $this->getModuleConfigFilePath($moduleName);
        $configXml = $this->loadXmlFile($moduleConfigFile);
        if ($configXml === false) {
            throw new Exception("Unable to parse module configuration file {$moduleConfigFile}");
        }

        $configXml->modules->{$moduleName}->active = $isActive ? 'true' : 'false';

        // save
        if ($this->saveXml($configXml, $moduleConfigFile) === false) {
            throw new Exception("Unable to save module configuration file {$moduleConfigFile}. Check to see if web server user has write permissions.");
        }
    }


    public function getLocalXmlFilePath()
    {
        return Mage::getBaseDir('etc') . DS . 'local.xml';
    }


    public function setSqlProfilerStatus($isEnabled)
    {
        $filePath = $this->getLocalXmlFilePath();
        $xml = $this->loadXmlFile($filePath);
        if ($xml === false) {
            throw new Exception("Unable to parse local.xml configuration file: {$filePath}");
        }

        /** @var SimpleXMLElement $connectionNode */
        $connectionNode = $xml->global->resources->default_setup->connection;
        if ($isEnabled) {
            /** @noinspection PhpUndefinedFieldInspection */
            $connectionNode->profiler = '1';
        } else {
            unset($connectionNode->profiler);
        }

        if ($this->saveXml($xml, $filePath) === false) {
            throw new Exception("Unable to save {$filePath}: check if web server user has write permission");
        }
    }


    /**
     * Enables/Disables to automatically force varien profiler
     *
     * @param int $isEnabled
     */
    public function setVarienProfilerStatus($isEnabled)
    {
        $this->getConfig()->saveConfig(Sheep_Debug_Helper_Data::DEBUG_OPTION_FORCE_VARIEN_PROFILE_PATH, (int)$isEnabled);
    }


    /**
     * @param $status
     * @throws Exception
     */
    public function setFPCDebug($status)
    {
        if (!Mage::helper('sheep_debug')->isMagentoEE()) {
            throw new Exception ('Cannot enable FPC debug for this Magento version.');
        }

        $this->getConfig()->saveConfig('system/page_cache/debug', (int)$status);
    }


    /**
     * @param $status
     */
    public function setTemplateHints($status)
    {
        $this->deleteTemplateHintsDbConfigs();

        $config = $this->getConfig();
        $config->saveConfig('dev/debug/template_hints', (int)$status);
        $config->saveConfig('dev/debug/template_hints_blocks', (int)$status);
    }


    /**
     * Changes status for inline translations
     *
     * @param $status
     */
    public function setTranslateInline($status)
    {
        $this->getConfig()->saveConfig('dev/translate_inline/active', (int)$status);
    }


    /**
     * @param string $query
     * @return array
     */
    public function searchConfig($query)
    {
        $configArray = array();

        $configArray = Mage::helper('sheep_debug')->xml2array($this->getConfig()->getNode(), $configArray);

        $results = array();
        $configKeys = array_keys($configArray);
        foreach ($configKeys as $configKey) {
            if (strpos($configKey, $query) !== FALSE) {
                $results[$configKey] = $configArray[$configKey];
            }
        }

        return $results;
    }


    /**
     * Delete template_hints related configurations
     */
    public function deleteTemplateHintsDbConfigs()
    {
        $configTable = Mage::getResourceModel('core/config')->getMainTable();

        /** @var Magento_Db_Adapter_Pdo_Mysql $db */
        $db = Mage::getSingleton('core/resource')->getConnection('core_write');
        $db->delete($configTable, "path like 'dev/debug/template_hints%'");
    }


    /**
     * Deletes all saved request profiles
     *
     * @return int
     * @throws Zend_Db_Statement_Exception
     */
    public function purgeAllProfiles()
    {
        $table = Mage::getResourceModel('sheep_debug/requestInfo')->getMainTable();
        $deleteSql = "DELETE FROM {$table}";

        /** @var Magento_Db_Adapter_Pdo_Mysql $connection */
        $connection = Mage::getSingleton('core/resource')->getConnection('core_write');

        /** @var Varien_Db_Statement_Pdo_Mysql $result */
        $result = $connection->query($deleteSql);

        return $result->rowCount();
    }

    /**
     * Returns layout files that have updates for specified handle
     *
     * @param string $handle
     * @param int    $storeId
     * @param string $area
     * @return array
     */
    public function getFileUpdatesWithHandle($handle, $storeId, $area)
    {
        /** @var array $updateFiles */
        $updateFiles = Mage::helper('sheep_debug')->getLayoutUpdatesFiles($storeId, $area);

        /* @var $designPackage Mage_Core_Model_Design_Package */
        $designPackage = Mage::getModel('core/design_package');
        $designPackage->setStore(Mage::app()->getStore($storeId));
        $designPackage->setArea($area);

        // search handle in all layout files registered for this area, package name and theme
        $handleFiles = array();
        foreach ($updateFiles as $file) {
            $filename = $designPackage->getLayoutFilename($file, array(
                '_area'    => $designPackage->getArea(),
                '_package' => $designPackage->getPackageName(),
                '_theme'   => $designPackage->getTheme('layout')
            ));

            if (!is_readable($filename)) {
                continue;
            }

            /** @var SimpleXMLElement $fileXml */
            $fileXml = simplexml_load_file($filename);
            if ($fileXml === false) {
                continue;
            }

            /** @var SimpleXMLElement[] $result */
            $results = $fileXml->xpath("/layout/{$handle}");
            if ($results) {
                $handleFiles[$file] = array();
                /** @var SimpleXMLElement $result */
                foreach ($results as $result) {
                    $handleFiles[$file][] = $result->asXML();
                }
            }
        }

        return $handleFiles;
    }


    /**
     * @see \Mage_Core_Model_Resource_Layout::fetchUpdatesByHandle
     *
     * @param string $handle
     * @param int    $storeId
     * @param string $area
     * @return array
     */
    public function getDatabaseUpdatesWithHandle($handle, $storeId, $area)
    {
        $databaseHandles = array();

        /* @var $designPackage Mage_Core_Model_Design_Package */
        $designPackage = Mage::getModel('core/design_package');
        $designPackage->setStore(Mage::app()->getStore($storeId));
        $designPackage->setArea($area);

        /* @var $layoutResourceModel Mage_Core_Model_Resource_Layout */
        $layoutResourceModel = Mage::getResourceModel('core/layout');

        $bind = array(
            'store_id'             => $storeId,
            'area'                 => $area,
            'package'              => $designPackage->getPackageName(),
            'theme'                => $designPackage->getTheme('layout'),
            'layout_update_handle' => $handle
        );

        /* @var $readAdapter Varien_Db_Adapter_Pdo_Mysql */
        $readAdapter = Mage::getSingleton('core/resource')->getConnection('core_read');

        /* @var $select Varien_Db_Select */
        $select = $readAdapter->select()
            ->from(array('layout_update' => $layoutResourceModel->getMainTable()), array('xml'))
            ->join(array('link' => $layoutResourceModel->getTable('core/layout_link')),
                'link.layout_update_id=layout_update.layout_update_id',
                '')
            ->where('link.store_id IN (0, :store_id)')
            ->where('link.area = :area')
            ->where('link.package = :package')
            ->where('link.theme = :theme')
            ->where('layout_update.handle = :layout_update_handle')
            ->order('layout_update.sort_order ' . Varien_Db_Select::SQL_ASC);

        $result = $readAdapter->fetchCol($select, $bind);

        if (count($result)) {
            foreach ($result as $dbLayoutUpdate) {
                $databaseHandles[] = $dbLayoutUpdate;
            }
        }

        return $databaseHandles;
    }

}
