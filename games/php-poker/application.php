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
$app->browser(__DIR__.'/browser/persistence.mjs');
$app->browser(__DIR__.'/browser/game.mjs');
$session=Session::restore((string)(vrzno_env('pokerLoad'))());
$engine=new Engine(new Device(1280,720,'PHP Poker'));
$presenter=new Presenter(__DIR__);
$view=$session->view();
$presenter->update($view);
$width=1280; $height=720; $dpr=1.0; $resized=true; $published='';

$engine->setInputHandler(static function(Engine $engine,float $elapsed) use (
	$session,$presenter,&$view,&$width,&$height,&$dpr,&$resized
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
	$presenter->resize($width,$height,$dpr);
	$intents=$presenter->input($controls,$pointer,$elapsed);
	$commands=vrzno_env('pokerCommands');
	if($commands)
	{
		$queued=json_decode((string)$commands(),true,16,JSON_THROW_ON_ERROR);
		if(is_array($queued)) $intents=array_merge($intents,array_slice($queued,0,16));
	}
	foreach($intents as $intent)
	{
		if(!is_array($intent)) continue;
		$seed=($intent['type']??null)==='start'?(string)(vrzno_env('pokerSeed'))():null;
		$session->command($intent,$seed);
	}
	if(!($input['hidden']??false)) $session->tick($elapsed);
	if($session->consumeSaveDirty()) (vrzno_env('pokerSave'))($session->save());
	if($session->consumeDirty())
	{
		$view=$session->view();
		$storage=json_decode((string)(vrzno_env('pokerStorageStatus'))(),true,8,JSON_THROW_ON_ERROR);
		if(($storage['error']??'')!=='' && $view['error']==='') $view['error']=$storage['error'];
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
