<?php
/**
 * Pro's provider conformance suite against the real Square adapter.
 *
 * @package WCPOS\WooCommercePOS\SquareTerminal\Tests\Conformance
 */

namespace WCPOS\WooCommercePOS\SquareTerminal\Tests\Conformance;

use WCPOS\WooCommercePOSPro\Tests\Conformance\Conformance_Fixture;
use WCPOS\WooCommercePOSPro\Tests\Conformance\Provider_Conformance_Test_Case;

require_once __DIR__ . '/Square_Conformance_Fixture.php';

class Test_Square_Provider_Conformance extends Provider_Conformance_Test_Case {
	protected function fixture(): Conformance_Fixture {
		return new Square_Conformance_Fixture();
	}
}
