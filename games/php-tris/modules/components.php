<?php
declare(strict_types=1);

return /** Register the authored board components.
 * @param \Phonics\Package\Context $context Session component registry.
 * @return void Registers component constructors without global systems.
 */
static function(\Phonics\Package\Context $context): void {
	\PhpTris\Module::registerComponents($context);
};
