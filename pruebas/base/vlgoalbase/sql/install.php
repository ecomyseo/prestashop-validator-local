<?php
/**
 * Copyright since 2007 Ecom Experts
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright Since 2007 Ecom Experts
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

$sql = [];
$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'vlgoalbase` (
    `id_vlgoalbase` int(11) NOT NULL AUTO_INCREMENT,
    PRIMARY KEY (`id_vlgoalbase`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

foreach ($sql as $query) {
    if (!Db::getInstance()->execute($query)) {
        return false;
    }
}
