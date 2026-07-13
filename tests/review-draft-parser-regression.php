<?php
/**
 * Dependency-free regression checks for the Review draft parser.
 *
 * Run with: php tests/review-draft-parser-regression.php
 */

define( 'ABSPATH', __DIR__ . '/' );

function lunara_review_parser_assert_same( $expected, $actual, $message ) {
    if ( $expected !== $actual ) {
        fwrite( STDERR, "FAIL: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
        exit( 1 );
    }
}

function lunara_review_parser_assert_true( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
}

require dirname( __DIR__ ) . '/includes/class-lunara-review-draft-parser.php';

$fixture = <<<'HTML'
<!-- Test Film (2024) -- tt1234567 -->
<em>An italic standfirst for a deterministic parser test.</em>
<p>The opening paragraph has <strong>real emphasis</strong> and an <a href="javascript:alert(1)" onclick="alert(1)">unsafe link</a>.</p>
<h2>A useful heading</h2>
<blockquote><p>A quoted observation.</p></blockquote>
<ol><li>First point</li><li>Second point</li></ol>
<hr>
<strong>LUNARA DEBRIEF</strong>
<ul>
<li><strong>Score:</strong> 4.5/5</li>
<li><strong>Where to Watch:</strong> Theatrical | Streaming</li>
<li><strong>Theme Echo:</strong> <em>Echo Film</em> (2001) [tt1111111] -- It carries the central question forward.</li>
<li><strong>Counter-Program:</strong> <em>Counter Film</em> (1999) [tt2222222] -- It changes the temperature.</li>
<li><strong>Career Context:</strong> <em>Career Film</em> (2018) [tt3333333] -- It reveals the artist's prior experiment.</li>
</ul>
<!-- LUNARA EXCERPT: A compact excerpt for cards. -->
<!-- LUNARA METADATA
Director: Test Director
Runtime: 120 min
-->
HTML;

$parsed = Lunara_Review_Draft_Parser::parse( $fixture );
lunara_review_parser_assert_true( $parsed['valid'], 'A complete supported draft must parse successfully.' );
lunara_review_parser_assert_same(
    array( 'valid', 'errors', 'warnings', 'title', 'year', 'imdb_id', 'standfirst', 'content', 'excerpt', 'metadata', 'score', 'where_to_watch', 'pairings' ),
    array_keys( $parsed ),
    'The parser response must expose only the stable top-level contract.'
);
lunara_review_parser_assert_same( array( 'theme_echo', 'counter_program', 'career_context' ), array_keys( $parsed['pairings'] ), 'Debrief roles must remain exact and ordered.' );
lunara_review_parser_assert_same( 'Test Film', $parsed['title'], 'The header title must parse.' );
lunara_review_parser_assert_same( 2024, $parsed['year'], 'The header year must normalize to an integer.' );
lunara_review_parser_assert_same( 'tt1234567', $parsed['imdb_id'], 'The header IMDb ID must normalize.' );
lunara_review_parser_assert_same( '4.5/5', $parsed['score'], 'The Debrief score must parse.' );
lunara_review_parser_assert_same( 'Theatrical | Streaming', $parsed['where_to_watch'], 'Where to Watch must parse.' );
lunara_review_parser_assert_same( 'Echo Film', $parsed['pairings']['theme_echo']['title'], 'Theme Echo title must parse.' );
lunara_review_parser_assert_same( 1999, $parsed['pairings']['counter_program']['year'], 'Counter-Program year must parse.' );
lunara_review_parser_assert_same( 'tt3333333', $parsed['pairings']['career_context']['imdb_id'], 'Career Context IMDb ID must parse.' );
lunara_review_parser_assert_same( "It reveals the artist's prior experiment.", $parsed['pairings']['career_context']['reason'], 'Pairing reasons must remain editorial text.' );
lunara_review_parser_assert_same( array( 'director' => 'Test Director', 'runtime' => '120 min' ), $parsed['metadata'], 'Metadata labels must normalize deterministically.' );
lunara_review_parser_assert_true( false !== strpos( $parsed['content'], '<!-- wp:paragraph -->' ), 'Paragraphs must become editable Gutenberg blocks.' );
lunara_review_parser_assert_true( false !== strpos( $parsed['content'], '<!-- wp:heading {"level":2} -->' ), 'Headings must become editable Gutenberg blocks.' );
lunara_review_parser_assert_true( false !== strpos( $parsed['content'], '<!-- wp:quote -->' ), 'Blockquotes must become editable Gutenberg blocks.' );
lunara_review_parser_assert_true( false !== strpos( $parsed['content'], '<!-- wp:list {"ordered":true} -->' ), 'Ordered lists must become editable Gutenberg blocks.' );
lunara_review_parser_assert_true( false === strpos( $parsed['content'], 'javascript:' ), 'Unsafe URL protocols must be stripped.' );
lunara_review_parser_assert_true( false === strpos( $parsed['content'], 'onclick' ), 'Event handler attributes must be stripped.' );
lunara_review_parser_assert_true( false === strpos( $parsed['content'], 'LUNARA DEBRIEF' ), 'The Debrief marker must not enter article content.' );
lunara_review_parser_assert_true( false === strpos( $parsed['content'], 'Echo Film' ), 'Debrief pairings must not duplicate into article content.' );
lunara_review_parser_assert_same( $parsed, Lunara_Review_Draft_Parser::parse( $fixture ), 'Repeated parsing must be byte-for-byte deterministic.' );

$unterminated_metadata = preg_replace( '/-->\s*$/', '', $fixture );
$unterminated_parsed   = Lunara_Review_Draft_Parser::parse( $unterminated_metadata );
lunara_review_parser_assert_true( $unterminated_parsed['valid'], 'An EOF metadata comment without a closing delimiter must remain valid.' );
lunara_review_parser_assert_true( in_array( 'unterminated_metadata_comment', $unterminated_parsed['warnings'], true ), 'The tolerated EOF metadata condition must be visible as a warning.' );

$invalid_score = str_replace( '<strong>Score:</strong> 4.5/5', '<strong>Score:</strong> excellent', $fixture );
$invalid_score_parsed = Lunara_Review_Draft_Parser::parse( $invalid_score );
lunara_review_parser_assert_true( ! $invalid_score_parsed['valid'] && in_array( 'invalid_score', $invalid_score_parsed['errors'], true ), 'Scores outside the 0-5 editorial contract must be rejected.' );

$invalid_pairing_year = str_replace( '<em>Counter Film</em> (1999)', '<em>Counter Film</em> (2200)', $fixture );
$invalid_pairing_year_parsed = Lunara_Review_Draft_Parser::parse( $invalid_pairing_year );
lunara_review_parser_assert_true( ! $invalid_pairing_year_parsed['valid'] && in_array( 'invalid_pairing_year_counter_program', $invalid_pairing_year_parsed['errors'], true ), 'Companion years outside the supported film range must be rejected.' );

$malformed_comment = str_replace( '<p>The opening', '<!-- orphan comment\n<p>The opening', $fixture );
$malformed_parsed  = Lunara_Review_Draft_Parser::parse( $malformed_comment );
lunara_review_parser_assert_true( ! $malformed_parsed['valid'], 'An unclosed non-metadata comment must be rejected.' );
lunara_review_parser_assert_true( in_array( 'malformed_comment', $malformed_parsed['errors'], true ), 'Malformed comments must expose a stable error code.' );

$unsafe_parsed = Lunara_Review_Draft_Parser::parse( str_replace( '<p>The opening', '<script>alert(1)</script><p>The opening', $fixture ) );
lunara_review_parser_assert_true( ! $unsafe_parsed['valid'], 'Executable elements must be rejected rather than silently imported.' );
lunara_review_parser_assert_same( array( 'unsafe_element' ), $unsafe_parsed['errors'], 'Executable-element rejection must be immediate and deterministic.' );

$malformed_markup = str_replace( '<p>The opening paragraph', '<p>The opening <strong>paragraph', $fixture );
$malformed_parsed = Lunara_Review_Draft_Parser::parse( $malformed_markup );
lunara_review_parser_assert_true( ! $malformed_parsed['valid'], 'Crossed or unclosed element markup must be rejected before browser repair.' );
lunara_review_parser_assert_true( in_array( 'malformed_markup', $malformed_parsed['errors'], true ), 'Malformed elements must expose a stable error code.' );

$oversized_parsed = Lunara_Review_Draft_Parser::parse( str_repeat( 'x', Lunara_Review_Draft_Parser::MAX_INPUT_BYTES + 1 ) );
lunara_review_parser_assert_same( array( 'input_too_large' ), $oversized_parsed['errors'], 'Oversized input must be rejected before parsing.' );

$specimen_path = getenv( 'LUNARA_REVIEW_DRAFT_SPECIMEN' );
if ( $specimen_path && is_file( $specimen_path ) ) {
    $specimen        = file_get_contents( $specimen_path );
    $specimen_parsed = Lunara_Review_Draft_Parser::parse( $specimen );

    lunara_review_parser_assert_true( $specimen_parsed['valid'], 'The unchanged Oppenheimer production specimen must parse successfully.' );
    lunara_review_parser_assert_same( 'Oppenheimer', $specimen_parsed['title'], 'The specimen title must parse.' );
    lunara_review_parser_assert_same( 2023, $specimen_parsed['year'], 'The specimen year must parse.' );
    lunara_review_parser_assert_same( 'tt15398776', $specimen_parsed['imdb_id'], 'The specimen IMDb identity must parse.' );
    lunara_review_parser_assert_same( '5/5', $specimen_parsed['score'], 'The specimen score must parse.' );
    lunara_review_parser_assert_same( 'The Fog of War', $specimen_parsed['pairings']['theme_echo']['title'], 'The specimen Theme Echo must parse.' );
    lunara_review_parser_assert_same( 'Godzilla', $specimen_parsed['pairings']['counter_program']['title'], 'The specimen Counter-Program must parse.' );
    lunara_review_parser_assert_same( 'Dunkirk', $specimen_parsed['pairings']['career_context']['title'], 'The specimen Career Context must parse.' );
    lunara_review_parser_assert_same( 'Christopher Nolan (adapted from American Prometheus by Kai Bird and Martin J. Sherwin)', $specimen_parsed['metadata']['director_writer'], 'Slash-delimited metadata labels must normalize.' );
    lunara_review_parser_assert_same( 7, substr_count( $specimen_parsed['content'], '<!-- wp:paragraph -->' ), 'All seven specimen prose paragraphs must remain editable.' );
    lunara_review_parser_assert_true( false === strpos( $specimen_parsed['content'], 'The Fog of War' ), 'The specimen Debrief must remain outside article content.' );
    lunara_review_parser_assert_same( $specimen_parsed, Lunara_Review_Draft_Parser::parse( $specimen ), 'The unchanged specimen must parse deterministically.' );
} else {
    fwrite( STDOUT, "Oppenheimer specimen not present; portable fixture checks completed.\n" );
}

$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-lunara-review-draft-parser.php' );
lunara_review_parser_assert_true( false === strpos( $source, 'wp_remote_' ), 'The parser must never perform remote HTTP.' );
lunara_review_parser_assert_true( false === strpos( $source, 'wp_insert_post' ), 'The parser must never write WordPress post state.' );
lunara_review_parser_assert_true( false === strpos( $source, 'update_post_meta' ), 'The parser must never write WordPress metadata.' );

echo "Review draft parser regression checks passed.\n";
