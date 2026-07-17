<?php
/**
 * Convert local Word/Google document exports to safe Review draft HTML.
 *
 * The importer receives a base64-encoded local file from the private editor
 * surface. This class only reads the document package; it never writes files,
 * calls a provider, or executes document content.
 *
 * @package Lunara_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Lunara_Review_Draft_Document {

    const MAX_SOURCE_BYTES = 5242880;
    const MAX_OUTPUT_BYTES = 1048576;
    const MAX_ZIP_ENTRIES   = 128;

    /**
     * Convert a base64-encoded DOCX or Google HTML export.
     *
     * @param mixed  $encoded Base64 source.
     * @param string $format  docx or zip.
     * @return array<string,mixed>
     */
    public static function convert( $encoded, $format ) {
        $format = strtolower( trim( (string) $format ) );
        $result = array(
            'html'     => '',
            'warnings' => array(),
            'errors'   => array(),
        );

        if ( ! in_array( $format, array( 'docx', 'zip' ), true ) ) {
            $result['errors'][] = 'unsupported_document_format';
            return $result;
        }

        if ( ! is_string( $encoded ) || '' === $encoded ) {
            $result['errors'][] = 'empty_document';
            return $result;
        }

        if ( strlen( $encoded ) > ( self::MAX_SOURCE_BYTES * 2 ) ) {
            $result['errors'][] = 'document_too_large';
            return $result;
        }

        $binary = base64_decode( $encoded, true );
        if ( false === $binary || '' === $binary ) {
            $result['errors'][] = 'invalid_document_encoding';
            return $result;
        }

        if ( strlen( $binary ) > self::MAX_SOURCE_BYTES ) {
            $result['errors'][] = 'document_too_large';
            return $result;
        }

        $entries = self::read_zip_entries( $binary );
        if ( ! empty( $entries['errors'] ) ) {
            $result['errors'] = $entries['errors'];
            return $result;
        }

        if ( 'docx' === $format ) {
            if ( empty( $entries['files']['word/document.xml'] ) ) {
                $result['errors'][] = 'missing_word_document';
                return $result;
            }

            $result['html'] = self::docx_to_html( $entries['files']['word/document.xml'], $result['errors'] );
        } else {
            $html_file = self::select_html_entry( $entries['files'] );
            if ( '' === $html_file ) {
                $result['errors'][] = 'missing_html_document';
                return $result;
            }

            $result['html'] = self::html_document_body( $html_file, $result['errors'] );
        }

        if ( ! empty( $result['errors'] ) || '' === trim( (string) $result['html'] ) ) {
            if ( empty( $result['errors'] ) ) {
                $result['errors'][] = 'empty_document_content';
            }
            return $result;
        }

        if ( strlen( $result['html'] ) > self::MAX_OUTPUT_BYTES ) {
            $result['html']    = '';
            $result['errors'][] = 'converted_document_too_large';
            return $result;
        }

        $result['warnings'][] = 'document_converted_locally';
        return $result;
    }

    /**
     * Read only the small set of ZIP entries needed by the importer.
     *
     * @param string $binary ZIP bytes.
     * @return array<string,mixed>
     */
    private static function read_zip_entries( $binary ) {
        $result = array(
            'files'  => array(),
            'errors' => array(),
        );
        $end = strrpos( $binary, "PK\x05\x06" );

        if ( false === $end || strlen( $binary ) < $end + 22 ) {
            $result['errors'][] = 'invalid_zip_container';
            return $result;
        }

        $disk       = self::u16( $binary, $end + 4 );
        $start_disk = self::u16( $binary, $end + 6 );
        $count      = self::u16( $binary, $end + 10 );
        $size       = self::u32( $binary, $end + 12 );
        $offset     = self::u32( $binary, $end + 16 );

        if ( 0 !== $disk || 0 !== $start_disk || $count > self::MAX_ZIP_ENTRIES || 0xFFFFFFFF === $size || 0xFFFFFFFF === $offset ) {
            $result['errors'][] = 'unsupported_zip_container';
            return $result;
        }

        if ( $offset < 0 || $size < 0 || $offset + $size > strlen( $binary ) ) {
            $result['errors'][] = 'invalid_zip_directory';
            return $result;
        }

        $cursor = $offset;
        for ( $index = 0; $index < $count; $index++ ) {
            if ( $cursor + 46 > strlen( $binary ) || "PK\x01\x02" !== substr( $binary, $cursor, 4 ) ) {
                $result['errors'][] = 'invalid_zip_entry';
                return $result;
            }

            $flags        = self::u16( $binary, $cursor + 8 );
            $method       = self::u16( $binary, $cursor + 10 );
            $compressed   = self::u32( $binary, $cursor + 20 );
            $uncompressed = self::u32( $binary, $cursor + 24 );
            $name_length  = self::u16( $binary, $cursor + 28 );
            $extra_length = self::u16( $binary, $cursor + 30 );
            $comment_len  = self::u16( $binary, $cursor + 32 );
            $local_offset = self::u32( $binary, $cursor + 42 );
            $name         = substr( $binary, $cursor + 46, $name_length );

            $cursor += 46 + $name_length + $extra_length + $comment_len;
            if ( '' === $name || false !== strpos( $name, '..' ) || 0 === strpos( $name, '/' ) ) {
                continue;
            }

            if ( 0 !== ( $flags & 0x0001 ) || ! in_array( $method, array( 0, 8 ), true ) || $uncompressed > self::MAX_OUTPUT_BYTES ) {
                continue;
            }

            if ( $local_offset < 0 || $local_offset + 30 > strlen( $binary ) || "PK\x03\x04" !== substr( $binary, $local_offset, 4 ) ) {
                continue;
            }

            $local_name_length  = self::u16( $binary, $local_offset + 26 );
            $local_extra_length = self::u16( $binary, $local_offset + 28 );
            $data_offset        = $local_offset + 30 + $local_name_length + $local_extra_length;
            if ( $data_offset < 0 || $data_offset + $compressed > strlen( $binary ) ) {
                continue;
            }

            $payload = substr( $binary, $data_offset, $compressed );
            if ( 8 === $method ) {
                $payload = gzinflate( $payload );
            }
            if ( false === $payload || strlen( $payload ) > self::MAX_OUTPUT_BYTES ) {
                continue;
            }

            $result['files'][ $name ] = $payload;
        }

        return $result;
    }

    /** Return an HTML entry from a Google export archive. */
    private static function select_html_entry( $files ) {
        $names = array_keys( $files );
        usort(
            $names,
            function ( $left, $right ) {
                $left_score  = ( 'index.html' === strtolower( basename( $left ) ) ? 0 : 1 );
                $right_score = ( 'index.html' === strtolower( basename( $right ) ) ? 0 : 1 );
                return $left_score === $right_score ? strcmp( $left, $right ) : $left_score - $right_score;
            }
        );

        foreach ( $names as $name ) {
            if ( preg_match( '/\.html?$/i', $name ) && isset( $files[ $name ] ) ) {
                return (string) $files[ $name ];
            }
        }

        return '';
    }

    /** Convert a WordprocessingML document body to safe HTML. */
    private static function docx_to_html( $xml, &$errors ) {
        if ( ! class_exists( 'DOMDocument' ) ) {
            $errors[] = 'missing_dom_extension';
            return '';
        }

        $document = new DOMDocument( '1.0', 'UTF-8' );
        $previous = libxml_use_internal_errors( true );
        $loaded   = $document->loadXML( $xml, LIBXML_NONET | LIBXML_COMPACT );
        $libxml_errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        if ( ! $loaded ) {
            $errors[] = 'invalid_word_document';
            return '';
        }

        foreach ( $libxml_errors as $error ) {
            if ( LIBXML_ERR_FATAL === $error->level ) {
                $errors[] = 'invalid_word_document';
                return '';
            }
        }

        $xpath = new DOMXPath( $document );
        $xpath->registerNamespace( 'w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main' );
        $body = $xpath->query( '//w:body' )->item( 0 );
        if ( ! $body ) {
            $errors[] = 'missing_word_body';
            return '';
        }

        $html = '';
        foreach ( $body->childNodes as $node ) {
            if ( XML_ELEMENT_NODE !== $node->nodeType ) {
                continue;
            }

            if ( 'p' === $node->localName ) {
                $html .= self::docx_paragraph( $node, $xpath );
            } elseif ( 'tbl' === $node->localName ) {
                $html .= self::docx_table( $node, $xpath );
            }
        }

        return trim( $html );
    }

    /** Convert one Word paragraph and preserve bold/italic editorial cues. */
    private static function docx_paragraph( $paragraph, $xpath ) {
        $text = '';
        $runs = $xpath->query( './/w:r', $paragraph );
        foreach ( $runs as $run ) {
            $value = '';
            foreach ( $xpath->query( './w:t|./w:tab|./w:br|./w:cr', $run ) as $part ) {
                if ( 't' === $part->localName ) {
                    $value .= htmlspecialchars( $part->textContent, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' );
                } elseif ( 'tab' === $part->localName ) {
                    $value .= "\t";
                } else {
                    $value .= '<br>';
                }
            }

            if ( '' === $value ) {
                continue;
            }

            $properties = $xpath->query( './w:rPr', $run )->item( 0 );
            if ( $properties && $xpath->query( './w:b', $properties )->length ) {
                $value = '<strong>' . $value . '</strong>';
            }
            if ( $properties && $xpath->query( './w:i', $properties )->length ) {
                $value = '<em>' . $value . '</em>';
            }
            $text .= $value;
        }

        if ( '' === trim( strip_tags( $text ) ) ) {
            return '';
        }

        $plain = trim( html_entity_decode( strip_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        if ( preg_match( '/^(.+?)\s*\((\d{4})\)\s*(?:--|\x{2013}|\x{2014})\s*(tt\d{6,9})$/iu', $plain, $identity ) ) {
            return '<!-- ' . htmlspecialchars( trim( $identity[1] ), ENT_NOQUOTES, 'UTF-8' ) . ' (' . (int) $identity[2] . ') -- ' . strtolower( $identity[3] ) . ' -->';
        }

        $style = $xpath->query( './w:pPr/w:pStyle', $paragraph )->item( 0 );
        if ( $style && preg_match( '/heading([1-6])/i', $style->getAttributeNS( 'http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'val' ), $heading ) ) {
            $level = (int) $heading[1];
            return '<h' . $level . '>' . trim( $text ) . '</h' . $level . ">\n";
        }

        if ( $xpath->query( './w:pPr/w:numPr', $paragraph )->length ) {
            return '<ul><li>' . trim( $text ) . '</li></ul>' . "\n";
        }

        return '<p>' . trim( $text ) . '</p>' . "\n";
    }

    /** Convert a simple Word table to a safe HTML table. */
    private static function docx_table( $table, $xpath ) {
        $rows = array();
        foreach ( $xpath->query( './w:tr', $table ) as $row ) {
            $cells = array();
            foreach ( $xpath->query( './w:tc', $row ) as $cell ) {
                $parts = array();
                foreach ( $xpath->query( './/w:t', $cell ) as $text ) {
                    $parts[] = htmlspecialchars( $text->textContent, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' );
                }
                $cells[] = '<td>' . trim( implode( ' ', $parts ) ) . '</td>';
            }
            if ( ! empty( $cells ) ) {
                $rows[] = '<tr>' . implode( '', $cells ) . '</tr>';
            }
        }

        return empty( $rows ) ? '' : '<table><tbody>' . implode( '', $rows ) . '</tbody></table>' . "\n";
    }

    /** Strip document-level CSS/scripts from a Google HTML export. */
    private static function html_document_body( $html, &$errors ) {
        if ( ! class_exists( 'DOMDocument' ) ) {
            $errors[] = 'missing_dom_extension';
            return '';
        }

        $document = new DOMDocument( '1.0', 'UTF-8' );
        $previous = libxml_use_internal_errors( true );
        $loaded   = $document->loadHTML( (string) $html, LIBXML_NONET | LIBXML_COMPACT );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        if ( ! $loaded ) {
            $errors[] = 'invalid_html_document';
            return '';
        }

        foreach ( array( 'script', 'style', 'iframe', 'object', 'embed' ) as $tag ) {
            foreach ( iterator_to_array( $document->getElementsByTagName( $tag ) ) as $node ) {
                if ( $node->parentNode ) {
                    $node->parentNode->removeChild( $node );
                }
            }
        }

        $body = $document->getElementsByTagName( 'body' )->item( 0 );
        if ( ! $body ) {
            return trim( (string) $html );
        }

        $output = '';
        foreach ( $body->childNodes as $child ) {
            $output .= $document->saveHTML( $child );
        }
        return trim( $output );
    }

    /** Read a little-endian unsigned short. */
    private static function u16( $binary, $offset ) {
        $value = unpack( 'vvalue', substr( $binary, $offset, 2 ) );
        return isset( $value['value'] ) ? (int) $value['value'] : 0;
    }

    /** Read a little-endian unsigned 32-bit integer. */
    private static function u32( $binary, $offset ) {
        $value = unpack( 'Vvalue', substr( $binary, $offset, 4 ) );
        return isset( $value['value'] ) ? (int) $value['value'] : 0;
    }
}
