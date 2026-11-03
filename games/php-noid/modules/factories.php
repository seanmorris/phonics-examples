<?php
declare(strict_types=1);

return /**
 * Apply the registered session callback.
 *
 * @param \Phonics\Package\Context $context Session registration and lifecycle services.
 * @return void Completes the operation.
 */
static function(\Phonics\Package\Context $context): void { \PhpNoid\Module::factories($context); };
