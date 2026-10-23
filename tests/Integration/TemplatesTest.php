<?php

declare( strict_types=1 );

use CawlCheckout\Templates;
use PHPUnit\Framework\TestCase;

final class TemplatesTest extends TestCase {
	public function test_registered_template_is_returned_as_a_list(): void {
		// L'éditeur (block-editor.php) lit get_block_templates(...)[0] pour une page qui utilise ce modèle :
		// le cœur garde la clé texte du registre, d'où « Undefined array key 0 ».
		$templates = get_block_templates( array( 'slug__in' => array( Templates::SLUG ) ) );

		$this->assertArrayHasKey( 0, $templates );
		$this->assertSame( Templates::SLUG, $templates[0]->slug );
	}
}
