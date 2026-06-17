<?php
declare( strict_types=1 );

namespace FastNutrition\MealPrep\Tests\Unit;

use FastNutrition\MealPrep\Stats\StatsRollup;
use PHPUnit\Framework\TestCase;

final class StatsRollupTest extends TestCase {

	/**
	 * @dataProvider postcode_provider
	 */
	public function test_outward_code( string $in, string $expected ): void {
		$this->assertSame( $expected, StatsRollup::outward_code( $in ) );
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function postcode_provider(): array {
		return [
			'standard'            => [ 'ST5 1AB', 'ST5' ],
			'lowercase'           => [ 'st5 1ab', 'ST5' ],
			'extra spaces'        => [ '  ST5   1AB ', 'ST5' ],
			'no space'            => [ 'ST51AB', 'ST5' ],
			'short outward'       => [ 'M1 1AE', 'M1' ],
			'no space short out'  => [ 'M11AE', 'M1' ],
			'long outward'        => [ 'CW3 9SS', 'CW3' ],
			'empty'               => [ '', '' ],
			'whitespace only'     => [ '   ', '' ],
			'too short to split'  => [ 'AB', 'AB' ],
		];
	}
}
