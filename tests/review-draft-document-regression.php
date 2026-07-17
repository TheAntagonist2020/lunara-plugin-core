<?php
/**
 * Dependency-free regression checks for local Word/Google document conversion.
 *
 * Run with: php tests/review-draft-document-regression.php
 */

define( 'ABSPATH', __DIR__ . '/' );

function lunara_review_document_assert_same( $expected, $actual, $message ) {
    if ( $expected !== $actual ) {
        fwrite( STDERR, "FAIL: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
        exit( 1 );
    }
}

function lunara_review_document_assert_true( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
}

function lunara_review_document_zip( $files, $deflate = false ) {
    $local = '';
    $central = '';
    $offset = 0;
    $count = 0;

    foreach ( $files as $name => $contents ) {
        $name = (string) $name;
        $contents = (string) $contents;
        $method = $deflate ? 8 : 0;
        $payload = $deflate ? gzdeflate( $contents ) : $contents;
        $crc = crc32( $contents );
        if ( $crc < 0 ) {
            $crc += 4294967296;
        }

        $local .= "PK\x03\x04" . pack( 'v', 20 ) . pack( 'v', 0 ) . pack( 'v', $method )
            . pack( 'v', 0 ) . pack( 'v', 0 ) . pack( 'V', $crc ) . pack( 'V', strlen( $payload ) ) . pack( 'V', strlen( $contents ) )
            . pack( 'v', strlen( $name ) ) . pack( 'v', 0 ) . $name . $payload;

        $central .= "PK\x01\x02" . pack( 'v', 20 ) . pack( 'v', 20 ) . pack( 'v', 0 ) . pack( 'v', $method )
            . pack( 'v', 0 ) . pack( 'v', 0 ) . pack( 'V', $crc ) . pack( 'V', strlen( $payload ) ) . pack( 'V', strlen( $contents ) )
            . pack( 'v', strlen( $name ) ) . pack( 'v', 0 ) . pack( 'v', 0 ) . pack( 'v', 0 ) . pack( 'v', 0 )
            . pack( 'V', 0 ) . pack( 'V', $offset ) . $name;

        $offset = strlen( $local );
        $count++;
    }

    $central_offset = strlen( $local );
    $central_size = strlen( $central );
    $end = "PK\x05\x06" . pack( 'v', 0 ) . pack( 'v', 0 ) . pack( 'v', $count ) . pack( 'v', $count )
        . pack( 'V', $central_size ) . pack( 'V', $central_offset ) . pack( 'v', 0 );

    return $local . $central . $end;
}

require dirname( __DIR__ ) . '/includes/class-lunara-review-draft-document.php';
require dirname( __DIR__ ) . '/includes/class-lunara-review-draft-parser.php';

$word_xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:body>
    <w:p><w:r><w:t>Oppenheimer (2023) -- tt15398776</w:t></w:r></w:p>
    <w:p><w:r><w:rPr><w:i/></w:rPr><w:t>A Word standfirst that should become an editable Review field.</w:t></w:r></w:p>
    <w:p><w:r><w:t>The first paragraph of the imported Review.</w:t></w:r></w:p>
    <w:p><w:r><w:t>The second paragraph of the imported Review.</w:t></w:r></w:p>
    <w:p><w:r><w:rPr><w:b/></w:rPr><w:t>LUNARA DEBRIEF</w:t></w:r></w:p>
    <w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Score:</w:t></w:r><w:r><w:t> 5/5</w:t></w:r></w:p>
    <w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Year:</w:t></w:r><w:r><w:t> 2023</w:t></w:r></w:p>
    <w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Where to Watch:</w:t></w:r><w:r><w:t> Theatrical | Streaming</w:t></w:r></w:p>
    <w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Theme Echo:</w:t></w:r><w:r><w:t> The Fog of War (2003) [tt0317910] -- It carries the moral question forward.</w:t></w:r></w:p>
    <w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Counter-Program:</w:t></w:r><w:r><w:t> Godzilla (1954) [tt0047034] -- It tells the story from beneath the bomb.</w:t></w:r></w:p>
    <w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Career Context:</w:t></w:r><w:r><w:t> Dunkirk (2017) [tt5013056] -- It reveals Nolan's earlier experiment.</w:t></w:r></w:p>
    <w:sectPr/>
  </w:body>
</w:document>
XML;

$zip = lunara_review_document_zip( array( 'word/document.xml' => $word_xml ), true );
$converted = Lunara_Review_Draft_Document::convert( base64_encode( $zip ), 'docx' );
lunara_review_document_assert_same( array(), $converted['errors'], 'A valid DOCX package must convert without errors.' );
lunara_review_document_assert_true( in_array( 'document_converted_locally', $converted['warnings'], true ), 'Local conversion must be visible to the operator.' );
lunara_review_document_assert_true( false !== strpos( $converted['html'], '<!-- Oppenheimer (2023) -- tt15398776 -->' ), 'The DOCX identity paragraph must become the canonical parser comment.' );
lunara_review_document_assert_true( false !== strpos( $converted['html'], '<strong>LUNARA DEBRIEF</strong>' ), 'The DOCX Debrief marker must survive conversion.' );

$parsed = Lunara_Review_Draft_Parser::parse( $converted['html'] );
lunara_review_document_assert_true( $parsed['valid'], 'Converted DOCX HTML must pass the normal Review parser.' );
lunara_review_document_assert_same( 'Oppenheimer', $parsed['title'], 'The converted reviewed-film title must parse.' );
lunara_review_document_assert_same( '5/5', $parsed['score'], 'The converted Debrief score must parse.' );
lunara_review_document_assert_same( 'The Fog of War', $parsed['pairings']['theme_echo']['title'], 'The converted Theme Echo must parse.' );
lunara_review_document_assert_same( 'It tells the story from beneath the bomb.', $parsed['pairings']['counter_program']['reason'], 'The converted Counter-Program reason must remain editorial text.' );
lunara_review_document_assert_same( 'Dunkirk', $parsed['pairings']['career_context']['title'], 'The converted Career Context must parse.' );
lunara_review_document_assert_true( false === strpos( $parsed['content'], 'LUNARA DEBRIEF' ), 'Converted Debrief markup must stay out of article content.' );
lunara_review_document_assert_true( false !== strpos( $parsed['content'], '<!-- wp:paragraph -->' ), 'Converted prose must become editable Gutenberg paragraph blocks.' );

$google_html = '<html><head><style>body{display:none}</style><script>alert(1)</script></head><body>'
    . '<p>Google Film (2024) -- tt1234567</p><p><em>Google standfirst.</em></p><p>Google body.</p><p><strong>LUNARA DEBRIEF</strong></p>'
    . '<p><strong>Score:</strong> 4/5</p><p><strong>Where to Watch:</strong> Streaming</p>'
    . '<p><strong>Theme Echo:</strong> Echo Film (2001) [tt1111111] -- It carries the question.</p>'
    . '<p><strong>Counter-Program:</strong> Counter Film (1999) [tt2222222] -- It changes the temperature.</p>'
    . '<p><strong>Career Context:</strong> Career Film (2018) [tt3333333] -- It reveals the prior experiment.</p>'
    . '</body></html>';
$google_zip = lunara_review_document_zip( array( 'index.html' => $google_html ), true );
$google = Lunara_Review_Draft_Document::convert( base64_encode( $google_zip ), 'zip' );
lunara_review_document_assert_same( array(), $google['errors'], 'A Google HTML export package must convert without errors.' );
lunara_review_document_assert_true( false === strpos( $google['html'], '<script' ) && false === strpos( $google['html'], '<style' ), 'Google export scripts and styles must be removed before parsing.' );
$google_parsed = Lunara_Review_Draft_Parser::parse( $google['html'] );
lunara_review_document_assert_true( $google_parsed['valid'], 'A Google export with a visible identity heading must pass the normal Review parser.' );
lunara_review_document_assert_same( 'Google Film', $google_parsed['title'], 'Visible Google export identity must normalize to the reviewed-film title.' );
lunara_review_document_assert_same( 'Echo Film', $google_parsed['pairings']['theme_echo']['title'], 'Google export Debrief pairings must use the same fixed contract.' );

$oversized = Lunara_Review_Draft_Document::convert( base64_encode( str_repeat( 'x', Lunara_Review_Draft_Document::MAX_SOURCE_BYTES + 1 ) ), 'docx' );
lunara_review_document_assert_true( in_array( 'document_too_large', $oversized['errors'], true ), 'Oversized document packages must be rejected before conversion.' );

$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-lunara-review-draft-document.php' );
lunara_review_document_assert_true( false === strpos( $source, 'shell_exec' ), 'The converter must never execute document content.' );
lunara_review_document_assert_true( false === strpos( $source, 'wp_remote_' ), 'The converter must never contact a remote provider.' );

echo "Review draft document regression checks passed.\n";
