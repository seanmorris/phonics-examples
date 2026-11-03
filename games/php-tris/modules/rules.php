<?php
declare(strict_types=1);

return static function(\Phonics\Package\Context $context): void {
	\PhpTris\Module::factories($context);
	\PhpTris\Module::systems($context);
};
