<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
$counts=array_fill(0,9,0);
for($a=0;$a<48;$a++) for($b=$a+1;$b<49;$b++) for($c=$b+1;$c<50;$c++)
for($d=$c+1;$d<51;$d++) for($e=$d+1;$e<52;$e++)
	$counts[PhpPoker\Hand::evaluate([$a,$b,$c,$d,$e])['category']]++;
$expected=[1302540,1098240,123552,54912,10200,5108,3744,624,40];
echo json_encode(['hands'=>array_sum($counts),'categories'=>$counts,'expected'=>$expected],JSON_PRETTY_PRINT)."\n";
if($counts!==$expected) exit(1);
