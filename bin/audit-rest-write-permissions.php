<?php
/**
 * Audit helper for audit/admin-hardening-triage.md (card 10061741479): prints every non-GET REST
 * route with its permission callback and what a guest and a plain member get.
 * Run: wp eval-file bin/audit-rest-write-permissions.php [member-user-id]
 *
 * @package WB_Gamification
 */
$member = (int) ( $args[0] ?? 0 );
if ( ! $member ) {
	$member = (int) ( get_users( array( 'role__not_in' => array( 'administrator', 'editor' ), 'number' => 1, 'fields' => 'ID' ) )[0] ?? 0 );
}
$rows = array();
foreach ( rest_get_server()->get_routes( 'wb-gamification/v1' ) as $route => $handlers ) {
	foreach ( $handlers as $h ) {
		$write = array_diff( array_keys( array_filter( (array) $h['methods'] ) ), array( 'GET', 'HEAD' ) );
		if ( ! $write ) { continue; }
		$cb = $h['permission_callback'] ?? null;
		$name = is_array( $cb ) ? ( is_object( $cb[0] ) ? ( new ReflectionClass( $cb[0] ) )->getShortName() : $cb[0] ) . '::' . $cb[1] : ( $cb instanceof Closure ? 'closure' : (string) $cb );
		$req = new WP_REST_Request( reset( $write ), $route );
		$as = function ( $uid ) use ( $cb, $req ) { wp_set_current_user( $uid ); $r = $cb ? call_user_func( $cb, $req ) : true; return true === $r ? 'ALLOWED' : 'refused'; };
		$rows[] = sprintf( "| `%s` | %s | `%s` | %s | %s |", $route, implode( ',', $write ), $name, $as( 0 ), $as( $member ) );
	}
}
echo implode( "\n", $rows ), "\n";
