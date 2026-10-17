<?php declare(strict_types=1);

/**
 * «Оповещения для сотрудников» — список объявлений.
 *
 * Открывается через /bitrix/admin/shef_toolsaiannounce_list.php — заглушку в
 * одну строку, которую пишет Main\AdminPage (каталог модуля браузеру
 * недоступен). Пункт меню — admin/menu.php, ссылка — со страницы настроек
 * модуля.
 *
 * Права — уровень модуля в «Настройки → Настройки модулей → права доступа»:
 * R — смотреть и «Показать мне снова», W — править список. Администратор
 * портала — всегда W.
 *
 * Перенесено из раздела «Утилиты» shef.toolsai (settings/tools.php,
 * 1.31.0–1.49) без изменения смысла. Новое: объявления модулей из события
 * OnAnnouncementsCollect показываются в общем списке с источником; «Изменить»
 * у такого объявления сохраняет свою копию в список настроек (она заменяет
 * модульную), «Вернуть как в модуле» — удаляет копию.
 *
 * Все действия — POST с sessid и редирект после; весь вывод экранирован.
 */

use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Shef\ToolsAiAnnounce\Announce\Announcements;
use Shef\ToolsAiAnnounce\Announce\Collector;
use Shef\ToolsAiAnnounce\Announce\SeenStore;
use Shef\ToolsAiAnnounce\Main\Constants;

require_once $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_admin_before.php';

/** @var \CMain $APPLICATION */
/** @var \CUser $USER */
global $APPLICATION, $USER;

if(!Loader::includeModule('shef.toolsaiannounce'))
{
	require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_admin_after.php';
	\CAdminMessage::ShowMessage('Модуль shef.toolsaiannounce не подключился.');
	require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_admin.php';

	return;
}

$right = $APPLICATION->GetGroupRight(Constants::MODULE_ID);
if($right < 'R')
{
	$APPLICATION->AuthForm('Нет доступа к «Оповещениям для сотрудников».');
}
$canEdit = $right >= 'W';

$h = static fn(mixed $v): string => htmlspecialcharsbx((string)$v);
$request = Context::getCurrent()->getRequest();
$self = Constants::listUrl();
$own = Announcements::parse((string)Option::get(Constants::MODULE_ID, Constants::OPTION_LIST, ''));
$saveOwn = static function(array $items): void
{
	Option::set(Constants::MODULE_ID, Constants::OPTION_LIST, (string)json_encode(array_values($items), JSON_UNESCAPED_UNICODE));
	Collector::clearCache();
};

if($request->isPost() && (string)$request->getPost('announce') !== '' && check_bitrix_sessid())
{
	$do = (string)$request->getPost('announce');
	$id = (string)$request->getPost('id');
	if($do === 'again')
	{
		// Показать мне снова: убрать отметку «видел» у себя
		SeenStore::forget((int)$USER->GetID(), $id);
	}
	elseif(!$canEdit)
	{
		$do = 'denied';
	}
	elseif($do === 'save')
	{
		$item = Announcements::normalize([
			'id' => $id !== '' ? $id : 'n-'.date('Ymd-His'),
			'title' => $request->getPost('title'),
			'text' => $request->getPost('text'),
			'url' => $request->getPost('url'),
			'linkText' => $request->getPost('linkText'),
			'audience' => $request->getPost('audience'),
			'layout' => $request->getPost('layout'),
			'active' => $request->getPost('active') === 'Y',
		]);
		if($item === null)
		{
			$do = 'invalid';
		}
		else
		{
			$found = false;
			foreach($own as $i => $old)
			{
				if($old['id'] === $item['id'])
				{
					$own[$i] = $item;
					$found = true;
				}
			}
			if(!$found)
			{
				$own[] = $item;
			}
			if(count($own) > Announcements::MAX_ITEMS)
			{
				$do = 'full';
			}
			else
			{
				$saveOwn($own);
			}
		}
	}
	elseif($do === 'delete')
	{
		$saveOwn(array_filter($own, static fn(array $i): bool => $i['id'] !== $id));
	}
	LocalRedirect($self.'&annDone='.rawurlencode($do));
}

$merged = Collector::collectWithConflicts();
$items = $merged['items'];
$edit = null;
foreach($items as $item)
{
	if($item['id'] === (string)$request->getQuery('annEdit'))
	{
		$edit = $item;
	}
}
$doneText = [
	'save' => 'Сохранено.',
	'delete' => 'Удалено.',
	'again' => 'Готово: откройте CRM — объявление покажется снова.',
	'denied' => 'Править список может только тот, у кого право «запись» на модуль.',
	'invalid' => 'Не сохранено: нужен заголовок, а id — латиница, цифры и дефис.',
	'full' => 'Не сохранено: в своём списке не больше '.Announcements::MAX_ITEMS.' объявлений.',
][(string)$request->getQuery('annDone')] ?? '';

$APPLICATION->SetTitle('Оповещения для сотрудников');
require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_admin_after.php';
?>
<p style="max-width:900px">Объявление показывается сотруднику <b>один раз</b> при открытии CRM — окном с текстом и ссылкой. Сначала проверьте на тестовых пользователях, потом переключите аудиторию на «все сотрудники». Новое объявление — новый показ всем; правка текста уже видевшим не покажется снова.</p>
<p style="max-width:900px">Объявления модулей приходят сами (событие <code>OnAnnouncementsCollect</code>). Чтобы изменить или выключить такое объявление, нажмите «Изменить» и сохраните — ваша копия заменит модульную. Тестовые пользователи — в <a href="/bitrix/admin/settings.php?mid=<?=$h(Constants::MODULE_ID)?>&amp;lang=<?=$h(LANGUAGE_ID)?>">настройках модуля</a>: сейчас <?=$h(implode(', ', Announcements::testUsers((string)Option::get(Constants::MODULE_ID, Constants::OPTION_TEST_USERS, '1'))) ?: 'никого')?>.</p>
<?php if($doneText !== ''): ?><p style="color:#2e7d32"><?=$h($doneText)?></p><?php endif; ?>
<?php if($merged['conflicts'] !== []): ?>
	<?php \CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => 'Конфликты объявлений', 'DETAILS' => implode('<br>', array_map($h, $merged['conflicts'])), 'HTML' => true]); ?>
<?php endif; ?>
<table class="adm-list-table" style="max-width:1000px">
	<tr class="adm-list-table-header">
		<td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Объявление</div></td>
		<td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Откуда</div></td>
		<td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Кому</div></td>
		<td class="adm-list-table-cell"><div class="adm-list-table-cell-inner">Показ</div></td>
		<td class="adm-list-table-cell"><div class="adm-list-table-cell-inner"></div></td>
	</tr>
	<?php foreach($items as $item): ?>
		<?php $fromSettings = $item['source'] === Announcements::SOURCE_SETTINGS; ?>
		<tr class="adm-list-table-row">
			<td class="adm-list-table-cell"><b><?=$h($item['title'])?></b><span style="display:block;color:#828b95"><?=$h($item['id'])?></span></td>
			<td class="adm-list-table-cell"><?=$fromSettings ? (isset($item['overrides']) ? 'настройки (вместо '.$h($item['overrides']).')' : 'настройки') : $h($item['source'])?></td>
			<td class="adm-list-table-cell"><?=$h(Announcements::AUDIENCES[$item['audience']])?>, <?=$h(Announcements::LAYOUTS[$item['layout']])?></td>
			<td class="adm-list-table-cell"><?=$item['active'] ? 'включён' : 'выключен'?></td>
			<td class="adm-list-table-cell" style="white-space:nowrap">
				<?php if($canEdit): ?><a class="adm-btn" href="<?=$h($self.'&annEdit='.rawurlencode($item['id']).'#announce-form')?>">Изменить</a><?php endif; ?>
				<form method="post" action="<?=$h($self)?>" style="display:inline"><?=bitrix_sessid_post()?><input type="hidden" name="announce" value="again"><input type="hidden" name="id" value="<?=$h($item['id'])?>"><input type="submit" class="adm-btn" value="Показать мне снова"></form>
				<?php if($canEdit && $fromSettings): ?>
					<form method="post" action="<?=$h($self)?>" style="display:inline" onsubmit="return confirm('<?=isset($item['overrides']) ? 'Вернуть объявление как в модуле?' : 'Удалить объявление?'?>');"><?=bitrix_sessid_post()?><input type="hidden" name="announce" value="delete"><input type="hidden" name="id" value="<?=$h($item['id'])?>"><input type="submit" class="adm-btn" value="<?=isset($item['overrides']) ? 'Вернуть как в модуле' : '✕'?>"></form>
				<?php endif; ?>
			</td>
		</tr>
	<?php endforeach; ?>
	<?php if($items === []): ?><tr class="adm-list-table-row"><td class="adm-list-table-cell" colspan="5">Объявлений нет.</td></tr><?php endif; ?>
</table>
<?php if($canEdit): ?>
<?php $e = $edit ?? ['id' => '', 'title' => '', 'text' => '', 'url' => Announcements::DEFAULT_URL, 'linkText' => 'Подробнее в базе знаний', 'audience' => Announcements::AUDIENCE_TEST, 'active' => true, 'layout' => Announcements::LAYOUT_COSMOS]; ?>
<h3 id="announce-form" style="margin:20px 0 8px"><?=$edit !== null ? 'Изменить объявление' : 'Новое объявление'?></h3>
<form method="post" action="<?=$h($self)?>" style="display:grid;gap:8px;max-width:700px">
	<?=bitrix_sessid_post()?><input type="hidden" name="announce" value="save"><input type="hidden" name="id" value="<?=$h($e['id'])?>">
	<input type="text" name="title" maxlength="120" required placeholder="Заголовок" value="<?=$h($e['title'])?>">
	<textarea name="text" rows="6" maxlength="2000" placeholder="Текст. Строка с «• » в начале — пункт списка."><?=$h($e['text'])?></textarea>
	<input type="url" name="url" placeholder="https://… — ссылка на подробности (можно пусто)" value="<?=$h($e['url'])?>">
	<input type="text" name="linkText" maxlength="60" placeholder="Текст кнопки" value="<?=$h($e['linkText'])?>">
	<label>Кому: <select name="audience"><?php foreach(Announcements::AUDIENCES as $code => $title): ?><option value="<?=$h($code)?>"<?=$e['audience'] === $code ? ' selected' : ''?>><?=$h($title)?></option><?php endforeach; ?></select></label>
	<label>Вид: <select name="layout"><?php foreach(Announcements::LAYOUTS as $code => $title): ?><option value="<?=$h($code)?>"<?=$e['layout'] === $code ? ' selected' : ''?>><?=$h($title)?></option><?php endforeach; ?></select></label>
	<label><input type="checkbox" name="active" value="Y"<?=$e['active'] ? ' checked' : ''?>> показывать</label>
	<div><input type="submit" class="adm-btn-save" value="Сохранить"><?php if($edit !== null): ?> <a class="adm-btn" href="<?=$h($self)?>">Отмена</a><?php endif; ?></div>
</form>
<?php endif; ?>
<?php
require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_admin.php';
