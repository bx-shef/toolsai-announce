<?php
/**
 * Значения настроек по умолчанию — их отдаёт Option::get(), пока настройку
 * не сохранили.
 *
 * ANNOUNCE_list — свой список объявлений (JSON), правится на странице
 * «Новинки для сотрудников». Пусто — только объявления модулей из события
 * OnAnnouncementsCollect. ANNOUNCE_testusers — ID тестовых пользователей
 * через запятую; 1 — администратор портала.
 *
 * Коды те же, что были в shef.toolsai до выноса: установщик переносит
 * значения оттуда (Main\Migration).
 */
$shef_toolsaiannounce_default_option = [
	'ANNOUNCE_list' => '',
	'ANNOUNCE_testusers' => '1',
];
