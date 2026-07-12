<?php
/**
 * Dependency-free regression checks for the editable Movie entity schema.
 *
 * Run with: php tests/movie-entity-schema-regression.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['lunara_movie_schema_test'] = array(
	'post_types'   => array(),
	'field_groups' => array(),
);

function __( $text ) {
	return $text;
}

function register_post_type( $post_type, $args ) {
	$GLOBALS['lunara_movie_schema_test']['post_types'][ $post_type ] = $args;
}

function acf_add_local_field_group( $group ) {
	$GLOBALS['lunara_movie_schema_test']['field_groups'][ $group['key'] ] = $group;
}

function lunara_movie_schema_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true )
		);
	}
}

function lunara_movie_schema_assert_true( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

require dirname( __DIR__ ) . '/includes/class-lunara-debrief-contract.php';
require dirname( __DIR__ ) . '/includes/class-lunara-entities.php';

Lunara_Entities::register_post_types();
Lunara_Entities::register_field_groups();

$movie_post_type = $GLOBALS['lunara_movie_schema_test']['post_types']['movie'] ?? array();
lunara_movie_schema_assert_same( true, $movie_post_type['show_in_rest'] ?? false, 'Movies must remain editable through the block editor and REST API.' );
lunara_movie_schema_assert_same( true, $movie_post_type['public'] ?? false, 'Movie dossiers must remain first-class public entities.' );
lunara_movie_schema_assert_true( in_array( 'editor', $movie_post_type['supports'] ?? array(), true ), 'Movie dossiers need an editable synopsis surface.' );
lunara_movie_schema_assert_true( in_array( 'thumbnail', $movie_post_type['supports'] ?? array(), true ), 'Movie dossiers need a local featured-poster destination.' );

$movie_group = $GLOBALS['lunara_movie_schema_test']['field_groups']['group_lunara_movie'] ?? array();
$movie_fields = array();
foreach ( $movie_group['fields'] ?? array() as $field ) {
	if ( ! empty( $field['name'] ) ) {
		$movie_fields[ $field['name'] ] = $field;
	}
}

$required_fields = array(
	'release_year',
	'directors',
	'principal_cast',
	'runtime',
	'imdb_title_id',
	'tmdb_movie_id',
	'original_title',
	'release_date',
	'genres',
	'countries',
	'content_rating',
	'backdrop_image',
);

foreach ( $required_fields as $field_name ) {
	lunara_movie_schema_assert_true( isset( $movie_fields[ $field_name ] ), 'Movie schema is missing importer field: ' . $field_name );
}

lunara_movie_schema_assert_same( 'relationship', $movie_fields['directors']['type'], 'Directors must remain editable Person relationships.' );
lunara_movie_schema_assert_same( 'relationship', $movie_fields['principal_cast']['type'], 'Principal cast must remain editable Person relationships.' );
lunara_movie_schema_assert_same( 'id', $movie_fields['backdrop_image']['return_format'], 'Backdrop images must resolve to local Media Library attachment IDs.' );
lunara_movie_schema_assert_same( 'image', $movie_fields['backdrop_image']['type'], 'Backdrop storage must be a local Media Library field.' );
lunara_movie_schema_assert_same( 'Legacy TMDB Backdrop URL', $movie_fields['tmdb_backdrop_url']['label'] ?? '', 'Remote backdrop URLs must remain clearly marked as legacy.' );

$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-lunara-entities.php' );
lunara_movie_schema_assert_true( false === strpos( $source, 'wp_remote_' ), 'Entity registration must never perform provider HTTP.' );
lunara_movie_schema_assert_true( false === strpos( $source, 'api_key' ), 'Entity registration must never contain provider credentials.' );

echo "Movie entity schema regression checks passed.\n";
