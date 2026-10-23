<?php
/**
 * Tests du journal.
 *
 * @package CawlCheckout
 */

declare( strict_types=1 );

use CawlCheckout\Log;
use PHPUnit\Framework\TestCase;

final class LogTest extends TestCase {
	private string $log_file;
	private string|false $previous;

	protected function setUp(): void {
		$this->log_file = (string) tempnam( sys_get_temp_dir(), 'cawl-log' );
		$this->previous = ini_set( 'error_log', $this->log_file );
	}

	protected function tearDown(): void {
		ini_set( 'error_log', false === $this->previous ? '' : $this->previous );
		@unlink( $this->log_file );
	}

	public function test_log_drops_non_whitelisted_keys(): void {
		Log::write( 'info', 'test', array( 'reference' => 'INS-1', 'email' => 'a@b.fr', 'api_secret' => 'x', 'token' => 'abc' ) );
		$line = (string) file_get_contents( $this->log_file );
		$this->assertStringContainsString( 'INS-1', $line );
		$this->assertStringNotContainsString( 'a@b.fr', $line );
		$this->assertStringNotContainsString( 'api_secret', $line );
		$this->assertStringNotContainsString( 'abc', $line );
	}

	public function test_log_line_format_and_all_whitelisted_keys(): void {
		$ctx = array(
			'reference'   => 'R',
			'state'       => 's',
			'from'        => 'a',
			'to'          => 'b',
			'status_code' => 9,
			'payment_id'  => 'p',
			'event_id'    => 'e',
			'type'        => 't',
			'source'      => 'w',
			'host'        => 'h',
			'http_status' => 200,
		);
		Log::write( 'error', 'msg', $ctx );
		$line  = (string) file_get_contents( $this->log_file );
		$start = (int) strpos( $line, '{' );
		$this->assertStringContainsString( '[cawl-checkout] ERROR msg {', $line );
		$this->assertSame( $ctx, json_decode( trim( substr( $line, $start ) ), true ) );
	}

	public function test_log_without_context(): void {
		Log::write( 'info', 'hello' );
		$this->assertStringContainsString( '[cawl-checkout] INFO hello', (string) file_get_contents( $this->log_file ) );
	}
}
