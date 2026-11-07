<?php
declare(strict_types=1);

require_once 'phar://'.Phar::running(false).'/core/autoload.php';
require_once __DIR__.'/autoload.php';

use Phonics\Application\Context;
use Phonics\Engine;
use Phonics\Render\Device;
use PhpPoker\Session;
use PhpPoker\Ui\Presenter;

$app=new Context;
$app->lobby([
	'provider'=>'@matrix/lobby','title'=>'PHP Poker Online'
	,'options'=>['homeserver'=>'https://matrix.org','room'=>'#php-poker:matrix.org','configurationKey'=>'php-poker']
	,'game'=>[
		'id'=>'org.seanmorris.phonics.php-poker.five-card-draw','protocol'=>'1.0.0','rules'=>'five-card-draw-anything-v4'
		,'players'=>2,'spectators'=>false,'aspectRatio'=>'auto','checkpointMode'=>'history','actionBatches'=>true
		,'maximumActionBytes'=>49152
		,'actionTypes'=>['shuffle-packet','card-layers','poker-action','draw-choice','reveal-cards']
	]
]);
$app->browser(__DIR__.'/browser/persistence.mjs');
$app->browser(__DIR__.'/browser/mental-poker.mjs');
$app->browser(__DIR__.'/browser/online-deal.mjs');
$app->browser(__DIR__.'/browser/game.mjs');
$session=Session::restore((string)(vrzno_env('pokerLoad'))());
$engine=new Engine(new Device(1280,720,'PHP Poker'));
$presenter=new Presenter(__DIR__);
$view=$session->view();
$storage=json_decode((string)(vrzno_env('pokerStorageStatus'))(),true,8,JSON_THROW_ON_ERROR);
$filePublished=(string)(vrzno_env('pokerFileStatus'))();
$file=json_decode($filePublished,true,8,JSON_THROW_ON_ERROR);
if(($storage['error']??'')!=='' && $view['error']==='') $view['error']=$storage['error'];
if(($file['error']??'')!=='' && $view['error']==='') $view['error']=$file['error'];
$view['fileNotice']=(string)($file['notice']??'');
$view['fileRevision']=(int)($file['revision']??0);
$presenter->update($view);
$width=1280; $height=720; $dpr=1.0; $resized=true; $published=''; $onlinePublished='';

$engine->setInputHandler(static function(Engine $engine,float $elapsed) use (
	&$session,$presenter,&$view,&$width,&$height,&$dpr,&$resized,&$onlinePublished,&$filePublished
): bool {
	$viewport=json_decode((string)(vrzno_env('pokerViewport'))(),true,16,JSON_THROW_ON_ERROR);
	if(is_array($viewport)&&count($viewport)>=3)
	{
		$w=max(1,min(8192,(int)$viewport[0])); $h=max(1,min(8192,(int)$viewport[1]));
		$ratio=max(.5,min(4,(float)$viewport[2]));
		if($w!==$width||$h!==$height||$ratio!==$dpr) $resized=true;
		[$width,$height,$dpr]=[$w,$h,$ratio];
	}
	$input=json_decode((string)(vrzno_env('pokerInput'))(),true,32,JSON_THROW_ON_ERROR);
	$controls=is_array($input['controls']??null)?$input['controls']:[];
	$pointer=is_array($input['pointer']??null)?$input['pointer']:null;
	$ui=$engine->input->uiFrame();
	$presenter->resize($width,$height,$dpr);
	if(!($input['hidden']??false)) $presenter->advance($elapsed);
	$intents=$presenter->input($controls,$pointer,$elapsed,$ui['buttons']??[],(string)($ui['theme']??'keyboard'));
	$commands=vrzno_env('pokerCommands');
	if($commands)
	{
		// The browser owns online play. Give it real pointer, keyboard and controller intents as well as
		// commands injected through the accessibility bridge; it returns only work PHP should execute locally.
		$queued=json_decode((string)$commands(json_encode($intents,JSON_THROW_ON_ERROR)),true,16,JSON_THROW_ON_ERROR);
		$intents=is_array($queued)?array_slice($queued,0,16):[];
	}
	foreach($intents as $intent)
	{
		if(!is_array($intent)) continue;
		if(($intent['type']??null)==='save-game')
		{
			(vrzno_env('pokerFileSave'))($session->save());
			$session->command(['type'=>'save-game']);
			continue;
		}
		if(($intent['type']??null)==='load-game')
		{
			(vrzno_env('pokerFileLoad'))();
			continue;
		}
		$seed=($intent['type']??null)==='start'?(string)(vrzno_env('pokerSeed'))():null;
		$session->command($intent,$seed);
	}
	$fileLoaded=false;
	$selected=(string)(vrzno_env('pokerFileTakeLoad'))();
	if($selected!=='')
	{
		$candidate=Session::restore($selected);
		if($candidate->hasGame())
		{
			$candidate->resumeLoaded((int)($session->view()['generation']??0)+1);
			$session=$candidate; $fileLoaded=true;
		}
		else $session->command(['type'=>'load-failed']);
		(vrzno_env('pokerFileCompleteLoad'))($fileLoaded);
	}
	if(!($input['hidden']??false) && !$presenter->animating() && !$presenter->modal()) $session->tick($elapsed);
	if($session->consumeSaveDirty()) (vrzno_env('pokerSave'))($session->save());
	$sessionDirty=$session->consumeDirty();
	$fileJson=(string)(vrzno_env('pokerFileStatus'))();
	$fileChanged=$fileJson!==$filePublished;
	if($fileChanged) $filePublished=$fileJson;
	$file=json_decode($fileJson,true,8,JSON_THROW_ON_ERROR);
	$onlineJson=(string)(vrzno_env('pokerOnlineView'))();
	if($onlineJson!=='' && $onlineJson!==$onlinePublished)
	{
		$onlineView=json_decode($onlineJson,true,64,JSON_THROW_ON_ERROR);
		if(!is_array($onlineView)) throw new RuntimeException('Invalid online Poker view');
		$view=$onlineView; $onlinePublished=$onlineJson; $presenter->update($view);
	}
	elseif($onlineJson==='' && ($onlinePublished!=='' || $sessionDirty || $fileChanged || $fileLoaded))
	{
		$view=$session->view();
		$onlinePublished='';
		$storage=json_decode((string)(vrzno_env('pokerStorageStatus'))(),true,8,JSON_THROW_ON_ERROR);
		if(($storage['error']??'')!=='' && $view['error']==='') $view['error']=$storage['error'];
		if(($file['error']??'')!=='' && $view['error']==='') $view['error']=$file['error'];
		$view['fileNotice']=(string)($file['notice']??'');
		$view['fileRevision']=(int)($file['revision']??0);
		$view['fileLoaded']=$fileLoaded;
		$presenter->update($view);
	}
	// The game has its own menu and pause policy; do not toggle the engine's generic pause panel.
	return true;
});

$engine->start(
	static function(Engine $engine,float $alpha) use ($presenter,&$view,&$width,&$height,&$dpr,&$resized,&$published): void {
		if(!$resized&&!$presenter->dirty()) return;
		$engine->device->resize($width,$height);
		$engine->device->begin([.025,.035,.03,1]);
		$presenter->render($width,$height,$dpr);
		$engine->device->present();
		$resized=false;
		$status=json_encode(array_merge($view,$presenter->status()),JSON_THROW_ON_ERROR);
		if($status!==$published) { (vrzno_env('pokerStatus'))($status); $published=$status; }
	},
	static function() use ($presenter): void { $presenter->close(); },
	static function() use ($presenter,&$resized): void { $presenter->restore(); $resized=true; }
);
$app->ready();
