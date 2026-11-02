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

class Vlgoalbase extends Module
{
    public function __construct()
    {
        $this->name = 'vlgoalbase';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Ecom Experts';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Goal base module');
        $this->description = $this->l('Clean module used by the local validator tests.');
        $this->ps_versions_compliancy = ['min' => '1.7.8.0', 'max' => _PS_VERSION_];
    }

    public function isUsingNewTranslationSystem()
    {
        return true;
    }

    public function install()
    {
        include __DIR__ . '/sql/install.php';

        return parent::install() && $this->registerHook('displayHome');
    }

    public function uninstall()
    {
        include __DIR__ . '/sql/uninstall.php';

        return parent::uninstall();
    }

    public function hookDisplayHome($params)
    {
        $this->context->smarty->assign('vlgoalbase_title', Configuration::get('VLGOALBASE_TITLE'));

        return $this->display(__FILE__, 'views/templates/hook/home.tpl');
    }
}
