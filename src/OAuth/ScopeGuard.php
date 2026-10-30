<?php
declare(strict_types=1);

namespace Tropk\Mcp\OAuth;

/** Enforce OAuth consent at the ability boundary, including nested dispatch. */
final class ScopeGuard {
	public function register(): void {
		// This hook runs inside WP_Ability::execute(), before the callback,
		// covering both MCP tools and the adapter's execute-ability dispatcher.
		add_action( 'before_execute_ability', [ $this, 'enforce' ], 1, 2 );
		add_action( 'wp_before_execute_ability', [ $this, 'enforce' ], 1, 2 );
	}

	public function enforce( string $name, $input = null ): void {
		$row = $GLOBALS[ BearerAuthenticator::ATTR_TOKEN ] ?? null;
		if ( ! is_array( $row ) ) {
			return;
		}
		// Regex SQL screening is not a read-only security boundary. Keep both
		// arbitrary-SQL entry points unavailable to OAuth clients.
		if ( in_array( $name, [ 'tropk-core/db-execute-select', 'tropk-db/execute-select' ], true ) ) {
			throw new \RuntimeException( 'Arbitrary SQL is disabled for OAuth clients.' );
		}
		$ability = wp_get_ability( $name );
		if ( ! $ability ) {
			throw new \RuntimeException( 'Cannot resolve ability scope.' );
		}
		$meta = (array) $ability->get_meta();
		$required = Scopes::for_annotations( (array) ( $meta['annotations'] ?? [] ) );
		if ( ! Scopes::contains( (string) ( $row['scope'] ?? '' ), [ $required ] ) ) {
			throw new \RuntimeException( 'Insufficient OAuth scope: ' . $required );
		}
	}
}
