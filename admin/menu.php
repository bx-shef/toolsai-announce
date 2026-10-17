<?php declare(strict_types=1);

/**
 * Пункт «Оповещения для сотрудников» в меню административной части
 * (Настройки). Ядро подключает этот файл само — для каждого установленного
 * модуля, при построении меню; регистрировать и копировать его не нужно.
 *
 * Пункт виден тем, у кого на модуль право не ниже «чтения».
 */

defined('B_PROLOG_INCLUDED') && B_PROLOG_INCLUDED === true || die();

/** @var \CMain $APPLICATION */
global $APPLICATION;

if(!is_object($APPLICATION) || $APPLICATION->GetGroupRight('shef.toolsaiannounce') < 'R')
{
	return false;
}

return [
	'parent_menu' => 'global_menu_settings',
	'section' => 'shef_toolsaiannounce',
	'sort' => 1750,
	'text' => 'Оповещения для сотрудников',
	'title' => 'Объявления, которые сотрудник видит один раз при открытии CRM',
	'url' => 'shef_toolsaiannounce_list.php?lang='.LANGUAGE_ID,
	'icon' => 'sys_menu_icon',
	'items_id' => 'menu_shef_toolsaiannounce',
];
