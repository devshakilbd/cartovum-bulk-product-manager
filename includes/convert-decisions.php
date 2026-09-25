<?php
/**
 * Conversion decisions approved by the store owner.
 *
 * Kept in code rather than in the database, so every decision can be reviewed line by line and a
 * dry run writes nothing.
 *
 * 16 Sept 2026: 8 new values, 2024-ONWARDS to 2024-Present, Centre Bore 54.14 held.
 * 16 Sept 2026: dry run approved; Centre Bore 54.14 approved to become the existing 54.1.
 */

defined( 'ABSPATH' ) || exit;

return array(

	// New shared values approved for creation. Until they exist, the dry run shows them as
	// "new value (to be created on approval)" and conversion refuses products that need them.
	'planned_terms' => array(
		'pa_rim-size'      => array( '20"' ),
		'pa_stud-diameter' => array( '98mm' ),
		'pa_centre-bore'   => array( '66.1', '58.1', '66.5', '70.1', '70.5', '63.3' ),
	),

	// Approved non-exact mappings. The target is identified by term ID, and its name must still match.
	'overrides'     => array(
		'pa_wheel-year'  => array(
			'2024-ONWARDS' => array(
				'term_id' => 602,
				'name'    => '2024-Present',
			),
		),
		'pa_centre-bore' => array(
			'54.14' => array(
				'term_id' => 471,
				'name'    => '54.1',
			),
		),
	),

	// Values held for a separate decision. Any product carrying one is left exactly as it is.
	'holds'         => array(),
);
