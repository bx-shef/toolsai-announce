<?php declare(strict_types=1);

/**
 * «[SH] Оповещения для сотрудников» — список объявлений.
 *
 * Оформление — штатные UI ядра (ui.alerts, ui.buttons, ui.forms, ui.label,
 * ui.hint), как страницы shef.toolsai; подписи и цвета бейджей — ListView::row().
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
use Bitrix\Main\UI\Extension;
use Shef\ToolsAiAnnounce\Announce\Announcements;
use Shef\ToolsAiAnnounce\Announce\Collector;
use Shef\ToolsAiAnnounce\Announce\ListView;
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
	$APPLICATION->AuthForm('Нет доступа к странице «'.Constants::TITLE.'».');
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

$APPLICATION->SetTitle(Constants::TITLE);
Extension::load(['ui.alerts', 'ui.buttons', 'ui.forms', 'ui.label', 'ui.hint']);
$badge = static fn(array $b): string => '<span class="ui-label ui-label-sm ui-label-fill '.$h($b['class']).'"><span class="ui-label-inner">'.$h($b['text']).'</span></span>';
$testUsers = implode(', ', Announcements::testUsers((string)Option::get(Constants::MODULE_ID, Constants::OPTION_TEST_USERS, '1'))) ?: 'никого';
require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_admin_after.php';
?>
<style>
.shef-ann{max-width:1000px}
.shef-ann-card{background:#fff;border:1px solid #e6e9ec;border-radius:12px;padding:20px 24px;margin:0 0 20px}
.shef-ann-card h3{margin:0 0 14px;font-size:17px;font-weight:600;color:#333}
.shef-ann-list{width:100%;border-collapse:collapse}
.shef-ann-list th{text-align:left;font-weight:500;color:#828b95;font-size:12px;padding:8px 10px;border-bottom:1px solid #e6e9ec}
.shef-ann-list td{padding:12px 10px;border-bottom:1px solid #f1f3f5;vertical-align:top}
.shef-ann-list tr:last-child td{border-bottom:0}
.shef-ann-list .ui-label{margin:0 4px 4px 0}
.shef-ann-id{display:block;color:#a8adb4;font-size:12px;margin-top:2px}
.shef-ann-actions{white-space:nowrap;text-align:right}
.shef-ann-actions form{display:inline}
.shef-ann-actions .ui-btn{margin:0 0 4px 4px}
.shef-ann .ui-form-row{margin-bottom:12px}
</style>
<div class="shef-ann">
	<div class="ui-alert ui-alert-primary ui-alert-icon-info">
		<span class="ui-alert-message">Объявление показывается сотруднику <b>один раз</b> при открытии CRM — окном с текстом и ссылкой. Сначала проверьте на тестовых пользователях, потом переключите аудиторию на «все сотрудники». Новое объявление — новый показ всем; правка текста уже видевшим не покажется снова.</span>
	</div>
	<div class="ui-alert ui-alert-default">
		<span class="ui-alert-message">Объявления модулей приходят сами (событие <code>OnAnnouncementsCollect</code>). Чтобы изменить или выключить такое объявление, нажмите «Изменить» и сохраните — ваша копия заменит модульную. Тестовые пользователи — в <a href="/bitrix/admin/settings.php?mid=<?=$h(Constants::MODULE_ID)?>&amp;lang=<?=$h(LANGUAGE_ID)?>">настройках модуля</a>: сейчас <?=$h($testUsers)?>.</span>
	</div>
	<?php if($doneText !== ''): ?>
		<div class="ui-alert <?=in_array((string)$request->getQuery('annDone'), ['denied', 'invalid', 'full'], true) ? 'ui-alert-danger' : 'ui-alert-success'?>"><span class="ui-alert-message"><?=$h($doneText)?></span></div>
	<?php endif; ?>
	<?php if($merged['conflicts'] !== []): ?>
		<div class="ui-alert ui-alert-warning ui-alert-icon-warning"><span class="ui-alert-message"><b>Конфликты объявлений</b><br><?=implode('<br>', array_map($h, $merged['conflicts']))?></span></div>
	<?php endif; ?>

	<div class="shef-ann-card">
		<h3>Объявления</h3>
		<table class="shef-ann-list">
			<tr><th>Объявление</th><th>Откуда</th><th>Кому</th><th>Показ</th><th></th></tr>
			<?php foreach($items as $item): ?>
				<?php $row = ListView::row($item, $canEdit); ?>
				<tr>
					<td><b><?=$h($row['title'])?></b><span class="shef-ann-id"><?=$h($row['id'])?></span></td>
					<td><?=$badge($row['source'])?></td>
					<td><?=$badge($row['audience'])?><?=$badge($row['layout'])?></td>
					<td><?=$badge($row['active'])?></td>
					<td class="shef-ann-actions">
						<?php if($row['canEdit']): ?><a class="ui-btn ui-btn-xs ui-btn-light-border" href="<?=$h($self.'&annEdit='.rawurlencode($row['id']).'#announce-form')?>">Изменить</a><?php endif; ?>
						<form method="post" action="<?=$h($self)?>"><?=bitrix_sessid_post()?><input type="hidden" name="announce" value="again"><input type="hidden" name="id" value="<?=$h($row['id'])?>"><button type="submit" class="ui-btn ui-btn-xs ui-btn-light-border" data-hint="Снять у себя отметку «видел»: окно покажется при следующем открытии CRM" data-hint-no-icon>Показать мне снова</button></form>
						<?php if($row['delete'] !== null): ?>
							<form method="post" action="<?=$h($self)?>" data-confirm="<?=$h($row['delete']['confirm'])?>" onsubmit="return confirm(this.getAttribute('data-confirm'));"><?=bitrix_sessid_post()?><input type="hidden" name="announce" value="delete"><input type="hidden" name="id" value="<?=$h($row['id'])?>"><button type="submit" class="ui-btn ui-btn-xs ui-btn-danger-light"><?=$h($row['delete']['button'])?></button></form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			<?php if($items === []): ?><tr><td colspan="5" style="color:#828b95">Объявлений нет.</td></tr><?php endif; ?>
		</table>
	</div>

<?php if($canEdit): ?>
<?php $e = $edit ?? ['id' => '', 'title' => '', 'text' => '', 'url' => Announcements::DEFAULT_URL, 'linkText' => 'Подробнее в базе знаний', 'audience' => Announcements::AUDIENCE_TEST, 'active' => true, 'layout' => Announcements::LAYOUT_COSMOS]; ?>
	<div class="shef-ann-card" id="announce-form">
		<h3><?=$edit !== null ? 'Изменить объявление' : 'Новое объявление'?></h3>
		<form method="post" action="<?=$h($self)?>" class="ui-form" style="max-width:700px">
			<?=bitrix_sessid_post()?><input type="hidden" name="announce" value="save"><input type="hidden" name="id" value="<?=$h($e['id'])?>">
			<div class="ui-form-row"><div class="ui-form-label"><div class="ui-ctl-label-text">Заголовок</div></div>
				<div class="ui-form-content"><div class="ui-ctl ui-ctl-textbox ui-ctl-w100"><input type="text" class="ui-ctl-element" name="title" maxlength="120" required value="<?=$h($e['title'])?>"></div></div></div>
			<div class="ui-form-row"><div class="ui-form-label"><div class="ui-ctl-label-text">Текст</div></div>
				<div class="ui-form-content"><div class="ui-ctl ui-ctl-textarea ui-ctl-w100 ui-ctl-resize-y"><textarea class="ui-ctl-element" name="text" rows="6" maxlength="2000" placeholder="Строка с «• » в начале — пункт списка."><?=$h($e['text'])?></textarea></div></div></div>
			<div class="ui-form-row"><div class="ui-form-label"><div class="ui-ctl-label-text">Ссылка на подробности (можно пусто)</div></div>
				<div class="ui-form-content"><div class="ui-ctl ui-ctl-textbox ui-ctl-w100"><input type="url" class="ui-ctl-element" name="url" placeholder="https://…" value="<?=$h($e['url'])?>"></div></div></div>
			<div class="ui-form-row"><div class="ui-form-label"><div class="ui-ctl-label-text">Текст кнопки</div></div>
				<div class="ui-form-content"><div class="ui-ctl ui-ctl-textbox ui-ctl-w100"><input type="text" class="ui-ctl-element" name="linkText" maxlength="60" value="<?=$h($e['linkText'])?>"></div></div></div>
			<div class="ui-form-row"><div class="ui-form-label"><div class="ui-ctl-label-text">Кому</div></div>
				<div class="ui-form-content"><div class="ui-ctl ui-ctl-after-icon ui-ctl-dropdown ui-ctl-w100"><div class="ui-ctl-after ui-ctl-icon-angle"></div><select class="ui-ctl-element" name="audience"><?php foreach(Announcements::AUDIENCES as $code => $title): ?><option value="<?=$h($code)?>"<?=$e['audience'] === $code ? ' selected' : ''?>><?=$h($title)?></option><?php endforeach; ?></select></div></div></div>
			<div class="ui-form-row"><div class="ui-form-label"><div class="ui-ctl-label-text">Вид окна</div></div>
				<div class="ui-form-content"><div class="ui-ctl ui-ctl-after-icon ui-ctl-dropdown ui-ctl-w100"><div class="ui-ctl-after ui-ctl-icon-angle"></div><select class="ui-ctl-element" name="layout"><?php foreach(Announcements::LAYOUTS as $code => $title): ?><option value="<?=$h($code)?>"<?=$e['layout'] === $code ? ' selected' : ''?>><?=$h($title)?></option><?php endforeach; ?></select></div></div></div>
			<div class="ui-form-row"><div class="ui-form-content">
				<label class="ui-ctl ui-ctl-checkbox"><input type="checkbox" class="ui-ctl-element" name="active" value="Y"<?=$e['active'] ? ' checked' : ''?>><div class="ui-ctl-label-text">Показывать сотрудникам</div></label></div></div>
			<div>
				<button type="submit" class="ui-btn ui-btn-primary">Сохранить</button>
				<?php if($edit !== null): ?><a class="ui-btn ui-btn-link" href="<?=$h($self)?>">Отмена</a><?php endif; ?>
			</div>
		</form>
	</div>
<?php endif; ?>
</div>
<script>BX.ready(function(){ if(BX.UI && BX.UI.Hint) { BX.UI.Hint.init(document.querySelector('.shef-ann')); } });</script>
<?php
require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/epilog_admin.php';
