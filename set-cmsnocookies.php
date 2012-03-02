#!/usr/bin/php
<?php

require("../../app/Mage.php");

$config = Mage::app()->getConfig();
Mage::app()->getCache()->clean();

$option = Mage::getStoreConfig('web/browser_capabilities/cookies');

if ($option) {
    print("Setting value of 'web/browser_capabilities/cookies' to false\n");
    $config->saveConfig('web/browser_capabilities/cookies', $newoption);
    Mage::app()->getCache()->clean();
}
else {
    print("Value of 'web/browser_capabilities/cookies' is already set to false\n");
}

?>
