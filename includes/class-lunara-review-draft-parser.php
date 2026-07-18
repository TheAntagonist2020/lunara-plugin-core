<?php
/**
 * Pure parser for editorial Review draft HTML.
 *
 * The parser performs no HTTP requests and writes no WordPress state. It
 * converts supported article markup to editable Gutenberg blocks while
 * returning Debrief and metadata fields as a deterministic data contract.
 *
 * @package Lunara_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Lunara_Review_Draft_Parser {

    const MAX_INPUT_BYTES = 1048576;

    /**
     * Parse a Review draft into a safe import preview.
     *
     * @param mixed $html Editorial draft HTML.
     * @return array<string,mixed>
     */
    public static function parse( $html ) {
        $result = self::empty_result();

        if ( ! is_string( $html ) ) {
            $result['errors'][] = 'invalid_input_type';
            return $result;
        }

        $html = preg_replace( '/^\xEF\xBB\xBF/', '', $html );
        $html = str_replace( array( "\r\n", "\r" ), "\n", $html );

        if ( '' === trim( $html ) ) {
            $result['errors'][] = 'empty_input';
            return $result;
        }

        if ( strlen( $html ) > self::MAX_INPUT_BYTES ) {
            $result['errors'][] = 'input_too_large';
            return $result;
        }

        if ( false !== strpos( $html, "\0" ) || preg_match( '/<\s*(script|style|iframe)\b/i', $html ) ) {
            $result['errors'][] = 'unsafe_element';
            return $result;
        }

        $working            = $html;
        $result['metadata'] = self::extract_metadata( $working, $result['warnings'] );
        $result['excerpt']  = self::extract_excerpt( $working );

        self::extract_identity( $working, $result );

        if ( substr_count( $working, '<!--' ) !== substr_count( $working, '-->' ) ) {
            $result['errors'][] = 'malformed_comment';
            return self::finish( $result );
        }

        $working = preg_replace( '/<!--.*?-->/s', '', $working );
        if ( preg_match( '/<[^>]*$/s', $working ) || self::has_unbalanced_markup( $working ) ) {
            $result['errors'][] = 'malformed_markup';
            return self::finish( $result );
        }

        list( $article_html, $debrief_html ) = self::split_debrief( $working );

        if ( '' === $debrief_html ) {
            $result['errors'][] = 'missing_debrief';
        } else {
            self::extract_debrief( $debrief_html, $result );
        }

        $result['standfirst'] = self::extract_standfirst( $article_html );
        $article_html         = preg_replace( '/\s*<hr\b[^>]*\/?\s*>\s*$/i', '', $article_html );
        $result['content']    = self::serialize_blocks( $article_html, $result['errors'], $result['warnings'] );

        self::validate_result( $result );

        return self::finish( $result );
    }

    /**
     * Build the exact stable response shape.
     *
     * @return array<string,mixed>
     */
    private static function empty_result() {
        $empty_pairing = array(
            'title'   => '',
            'year'    => 0,
            'imdb_id' => '',
            'reason'  => '',
        );

        return array(
            'valid'          => false,
            'errors'         => array(),
            'warnings'       => array(),
            'title'          => '',
            'year'           => 0,
            'imdb_id'        => '',
            'standfirst'     => '',
            'content'        => '',
            'excerpt'        => '',
            'metadata'       => array(),
            'score'          => '',
            'where_to_watch' => '',
            'pairings'       => array(
                'theme_echo'      => $empty_pairing,
                'counter_program' => $empty_pairing,
                'career_context'  => $empty_pairing,
            ),
        );
    }

    /**
     * Extract the optional EOF-tolerant metadata comment.
     *
     * @param string            $html Draft HTML, modified in place.
     * @param array<int,string> $warnings Parser warnings.
     * @return array<string,string>
     */
    private static function extract_metadata( &$html, &$warnings ) {
        if ( ! preg_match( '/<!--\s*LUNARA\s+METADATA\b/i', $html, $match, PREG_OFFSET_CAPTURE ) ) {
            $warnings[] = 'missing_metadata';
            return array();
        }

        $start         = $match[0][1];
        $content_start = $start + strlen( $match[0][0] );
        $end           = strpos( $html, '-->', $content_start );

        if ( false === $end ) {
            $body       = substr( $html, $content_start );
            $html       = substr( $html, 0, $start );
            $warnings[] = 'unterminated_metadata_comment';
        } else {
            $body = substr( $html, $content_start, $end - $content_start );
            $html = substr( $html, 0, $start ) . substr( $html, $end + 3 );
        }

        $metadata = array();
        foreach ( explode( "\n", $body ) as $line ) {
            $line = trim( $line );
            if ( '' === $line || ! preg_match( '/^([^:]{1,80}):\s*(.+)$/u', $line, $parts ) ) {
                continue;
            }

            $key   = self::normalize_label( $parts[1] );
            $value = self::plain_text( $parts[2] );
            if ( '' !== $key && '' !== $value && ! isset( $metadata[ $key ] ) ) {
                $metadata[ $key ] = $value;
            }
        }

        if ( empty( $metadata ) ) {
            $warnings[] = 'empty_metadata';
        }

        return $metadata;
    }

    /**
     * Extract and remove the editorial excerpt comment.
     *
     * @param string $html Draft HTML, modified in place.
     * @return string
     */
    private static function extract_excerpt( &$html ) {
        if ( ! preg_match( '/<!--\s*LUNARA\s+EXCERPT\s*:\s*(.*?)\s*-->/is', $html, $match, PREG_OFFSET_CAPTURE ) ) {
            return '';
        }

        $excerpt = self::plain_text( $match[1][0] );
        $html    = substr_replace( $html, '', $match[0][1], strlen( $match[0][0] ) );

        return $excerpt;
    }

    /**
     * Extract and remove the canonical header comment.
     *
     * @param string              $html Draft HTML, modified in place.
     * @param array<string,mixed> $result Parser result.
     * @return void
     */
    private static function extract_identity( &$html, &$result ) {
        $pattern = '/<!--\s*(.+?)\s*\((\d{4})\)\s*--\s*(tt\d{6,9})\s*-->/iu';
        if ( preg_match( $pattern, $html, $match, PREG_OFFSET_CAPTURE ) ) {
            $result['title']   = self::plain_text( $match[1][0] );
            $result['year']    = (int) $match[2][0];
            $result['imdb_id'] = strtolower( $match[3][0] );
            $html              = substr_replace( $html, '', $match[0][1], strlen( $match[0][0] ) );
            return;
        }

        // Word/Google exports discard HTML comments. Accept the same identity
        // contract when it is retained as a standalone heading or paragraph.
        if ( preg_match_all( '/<(?:h[1-6]|p)\b[^>]*>.*?<\/(?:h[1-6]|p)>/isu', $html, $visible_blocks, PREG_OFFSET_CAPTURE ) ) {
            foreach ( $visible_blocks[0] as $visible_block ) {
                $visible_text = self::plain_text( $visible_block[0] );
                if ( preg_match( '/^(.+?)\s*\((\d{4})\)\s*(?:--|\x{2013}|\x{2014})\s*(tt\d{6,9})$/u', $visible_text, $visible_match ) ) {
                    $result['title']   = self::plain_text( $visible_match[1] );
                    $result['year']    = (int) $visible_match[2];
                    $result['imdb_id'] = strtolower( $visible_match[3] );
                    $html              = substr_replace( $html, '', $visible_block[1], strlen( $visible_block[0] ) );
                    $result['warnings'][] = 'identity_from_visible_heading';
                    return;
                }
            }
        }

        $result['errors'][] = 'missing_identity';
    }

    /**
     * Separate article markup from the Debrief module.
     *
     * @param string $html Remaining draft HTML.
     * @return array{0:string,1:string}
     */
    private static function split_debrief( $html ) {
        $pattern = '/<(?:strong|h[1-6]|p)\b[^>]*>\s*(?:(?:<(?:strong|b|em|span)\b[^>]*>\s*)*)LUNARA\s+DEBRIEF\s*(?:(?:<\/(?:strong|b|em|span)>\s*)*)<\/(?:strong|h[1-6]|p)>/iu';
        if ( ! preg_match( $pattern, $html, $match, PREG_OFFSET_CAPTURE ) ) {
            return array( $html, '' );
        }

        $offset = $match[0][1];
        return array( substr( $html, 0, $offset ), substr( $html, $offset + strlen( $match[0][0] ) ) );
    }

    /**
     * Extract the leading italic standfirst and remove it from the article.
     *
     * @param string $article_html Article HTML, modified in place.
     * @return string
     */
    private static function extract_standfirst( &$article_html ) {
        $pattern = '/^\s*(?:<p\b[^>]*>\s*)?<em\b[^>]*>(.*?)<\/em>(?:\s*<\/p>)?\s*/is';
        if ( ! preg_match( $pattern, $article_html, $match ) ) {
            return '';
        }

        $article_html = substr( $article_html, strlen( $match[0] ) );
        return self::plain_text( $match[1] );
    }

    /**
     * Parse Debrief list items into the fixed role contract.
     *
     * @param string              $html Debrief HTML.
     * @param array<string,mixed> $result Parser result.
     * @return void
     */
    private static function extract_debrief( $html, &$result ) {
        $root = self::load_fragment( $html, $result['errors'] );
        if ( ! $root ) {
            return;
        }

        $xpath = new DOMXPath( $root->ownerDocument );
        $items = $xpath->query( './/li | .//p', $root );
        foreach ( $items as $item ) {
            if ( 'p' === strtolower( $item->nodeName ) ) {
                $ancestor = $item->parentNode;
                $in_list  = false;
                while ( $ancestor && $ancestor !== $root ) {
                    if ( 'li' === strtolower( $ancestor->nodeName ) ) {
                        $in_list = true;
                        break;
                    }
                    $ancestor = $ancestor->parentNode;
                }
                if ( $in_list ) {
                    continue;
                }
            }

            $strongs = $item->getElementsByTagName( 'strong' );
            if ( 0 === $strongs->length ) {
                $strongs = $item->getElementsByTagName( 'b' );
            }
            if ( 0 === $strongs->length ) {
                $plain_item = self::plain_text( $item->textContent );
                if ( preg_match( '/^(Score|Where\s+to\s+Watch|Theme\s+Echo|Counter[- ]Program|Career\s+Context)\s*:\s*(.+)$/iu', $plain_item, $plain_match ) ) {
                    $label = $plain_match[1];
                    $value = $plain_match[2];
                } else {
                    continue;
                }
            } else {
                $label = trim( rtrim( self::plain_text( $strongs->item( 0 )->textContent ), ':' ) );
                $value = self::plain_text( $item->textContent );
                $value = preg_replace( '/^' . preg_quote( $label, '/' ) . '\s*:\s*/iu', '', $value );
            }

            switch ( self::normalize_label( $label ) ) {
                case 'score':
                    $result['score'] = $value;
                    break;
                case 'where_to_watch':
                    $result['where_to_watch'] = $value;
                    break;
                case 'theme_echo':
                    self::set_pairing( 'theme_echo', $value, $result );
                    break;
                case 'counter_program':
                    self::set_pairing( 'counter_program', $value, $result );
                    break;
                case 'career_context':
                    self::set_pairing( 'career_context', $value, $result );
                    break;
            }
        }
    }

    /**
     * Parse one Debrief pairing value.
     *
     * @param string              $role Role key.
     * @param string              $value Pairing display value.
     * @param array<string,mixed> $result Parser result.
     * @return void
     */
    private static function set_pairing( $role, $value, &$result ) {
        if ( '' !== $result['pairings'][ $role ]['imdb_id'] ) {
            $result['warnings'][] = 'duplicate_pairing_' . $role;
            return;
        }

        if ( ! class_exists( 'Lunara_Debrief_Contract' ) ) {
            $result['errors'][] = 'invalid_pairing_' . $role;
            return;
        }

        $pairing = Lunara_Debrief_Contract::parse_pairing_text( $value );
        if ( ! $pairing['valid'] ) {
            $result['errors'][] = 'invalid_pairing_' . $role;
            return;
        }

        $result['pairings'][ $role ] = array(
            'title'   => self::plain_text( $pairing['title'] ),
            'year'    => (int) $pairing['year'],
            'imdb_id' => $pairing['imdb_id'],
            'reason'  => self::plain_text( $pairing['reason'] ),
        );
    }

    /**
     * Serialize safe top-level article nodes as editable Gutenberg blocks.
     *
     * @param string            $html Article HTML.
     * @param array<int,string> $errors Parser errors.
     * @param array<int,string> $warnings Parser warnings.
     * @return string
     */
    private static function serialize_blocks( $html, &$errors, &$warnings ) {
        if ( '' === trim( $html ) ) {
            return '';
        }

        $root = self::load_fragment( $html, $errors );
        if ( ! $root ) {
            return '';
        }

        $blocks = array();
        foreach ( $root->childNodes as $node ) {
            $block = self::node_to_block( $node, $warnings );
            if ( '' !== $block ) {
                $blocks[] = $block;
            }
        }

        return implode( "\n\n", $blocks );
    }

    /**
     * Convert one top-level DOM node to a block.
     *
     * @param DOMNode           $node DOM node.
     * @param array<int,string> $warnings Parser warnings.
     * @return string
     */
    private static function node_to_block( $node, &$warnings ) {
        if ( XML_TEXT_NODE === $node->nodeType ) {
            $text = self::plain_text( $node->textContent );
            return '' === $text ? '' : self::paragraph_block( self::escape_text( $text ) );
        }

        if ( XML_ELEMENT_NODE !== $node->nodeType ) {
            return '';
        }

        $tag = strtolower( $node->nodeName );
        if ( 'p' === $tag ) {
            $inline = self::inline_children( $node );
            return '' === $inline ? '' : self::paragraph_block( $inline );
        }

        if ( preg_match( '/^h([1-6])$/', $tag, $match ) ) {
            $level  = (int) $match[1];
            $inline = self::inline_children( $node );
            if ( '' === $inline ) {
                return '';
            }
            return '<!-- wp:heading {"level":' . $level . '} -->' . "\n"
                . '<h' . $level . ' class="wp-block-heading">' . $inline . '</h' . $level . '>' . "\n"
                . '<!-- /wp:heading -->';
        }

        if ( 'blockquote' === $tag ) {
            $body = self::quote_body( $node );
            return '' === $body ? '' : '<!-- wp:quote -->' . "\n"
                . '<blockquote class="wp-block-quote">' . $body . '</blockquote>' . "\n"
                . '<!-- /wp:quote -->';
        }

        if ( 'ul' === $tag || 'ol' === $tag ) {
            return self::list_block( $node, 'ol' === $tag );
        }

        if ( 'hr' === $tag ) {
            return '<!-- wp:separator -->' . "\n"
                . '<hr class="wp-block-separator has-alpha-channel-opacity"/>' . "\n"
                . '<!-- /wp:separator -->';
        }

        if ( in_array( $tag, array( 'figure', 'pre', 'table' ), true ) ) {
            $safe = self::safe_fallback_html( $node );
            return '' === $safe ? '' : '<!-- wp:html -->' . "\n" . $safe . "\n" . '<!-- /wp:html -->';
        }

        $text = self::plain_text( $node->textContent );
        if ( '' !== $text ) {
            $warnings[] = 'unsupported_element_unwrapped';
            return self::paragraph_block( self::escape_text( $text ) );
        }

        return '';
    }

    /**
     * Format a paragraph block.
     *
     * @param string $inline Safe inline HTML.
     * @return string
     */
    private static function paragraph_block( $inline ) {
        return '<!-- wp:paragraph -->' . "\n" . '<p>' . $inline . '</p>' . "\n" . '<!-- /wp:paragraph -->';
    }

    /**
     * Serialize a quote's supported contents.
     *
     * @param DOMNode $node Quote node.
     * @return string
     */
    private static function quote_body( $node ) {
        $parts  = array();
        $inline = '';

        foreach ( $node->childNodes as $child ) {
            if ( XML_ELEMENT_NODE === $child->nodeType && 'p' === strtolower( $child->nodeName ) ) {
                if ( '' !== trim( $inline ) ) {
                    $parts[] = '<p>' . self::normalize_inline( $inline ) . '</p>';
                    $inline  = '';
                }
                $paragraph = self::inline_children( $child );
                if ( '' !== $paragraph ) {
                    $parts[] = '<p>' . $paragraph . '</p>';
                }
            } else {
                $inline .= self::inline_node( $child );
            }
        }

        if ( '' !== trim( $inline ) ) {
            $parts[] = '<p>' . self::normalize_inline( $inline ) . '</p>';
        }

        return implode( '', $parts );
    }

    /**
     * Serialize a list block.
     *
     * @param DOMNode $node List node.
     * @param bool    $ordered Whether this is an ordered list.
     * @return string
     */
    private static function list_block( $node, $ordered ) {
        $tag   = $ordered ? 'ol' : 'ul';
        $items = array();
        foreach ( $node->childNodes as $child ) {
            if ( XML_ELEMENT_NODE !== $child->nodeType || 'li' !== strtolower( $child->nodeName ) ) {
                continue;
            }

            $inline = self::inline_children( $child );
            if ( '' !== $inline ) {
                $items[] = '<li>' . $inline . '</li>';
            }
        }

        if ( empty( $items ) ) {
            return '';
        }

        $attributes = $ordered ? ' {"ordered":true}' : '';
        return '<!-- wp:list' . $attributes . ' -->' . "\n"
            . '<' . $tag . ' class="wp-block-list">' . implode( '', $items ) . '</' . $tag . '>' . "\n"
            . '<!-- /wp:list -->';
    }

    /**
     * Serialize safe inline descendants.
     *
     * @param DOMNode $node Parent node.
     * @return string
     */
    private static function inline_children( $node ) {
        $html = '';
        foreach ( $node->childNodes as $child ) {
            $html .= self::inline_node( $child );
        }
        return self::normalize_inline( $html );
    }

    /**
     * Serialize one safe inline node.
     *
     * @param DOMNode $node DOM node.
     * @return string
     */
    private static function inline_node( $node ) {
        if ( XML_TEXT_NODE === $node->nodeType ) {
            return self::escape_text( $node->nodeValue );
        }

        if ( XML_ELEMENT_NODE !== $node->nodeType ) {
            return '';
        }

        $tag = strtolower( $node->nodeName );
        if ( 'br' === $tag ) {
            return '<br>';
        }

        $map = array(
            'b'      => 'strong',
            'strong' => 'strong',
            'i'      => 'em',
            'em'     => 'em',
            'code'   => 'code',
            's'      => 's',
            'del'    => 'del',
            'sup'    => 'sup',
            'sub'    => 'sub',
            'mark'   => 'mark',
        );

        if ( isset( $map[ $tag ] ) ) {
            $safe_tag = $map[ $tag ];
            return '<' . $safe_tag . '>' . self::inline_children( $node ) . '</' . $safe_tag . '>';
        }

        if ( 'a' === $tag ) {
            $href  = $node->hasAttribute( 'href' ) ? self::safe_url( $node->getAttribute( 'href' ), false ) : '';
            $title = $node->hasAttribute( 'title' ) ? self::plain_text( $node->getAttribute( 'title' ) ) : '';
            $attrs = '' !== $href ? ' href="' . self::escape_attribute( $href ) . '"' : '';
            $attrs .= '' !== $title ? ' title="' . self::escape_attribute( $title ) . '"' : '';
            return '<a' . $attrs . '>' . self::inline_children( $node ) . '</a>';
        }

        return self::inline_children( $node );
    }

    /**
     * Sanitize a limited raw-HTML fallback element.
     *
     * @param DOMNode $node Fallback root.
     * @return string
     */
    private static function safe_fallback_html( $node ) {
        $allowed = array(
            'figure', 'figcaption', 'img', 'pre', 'code', 'table', 'caption',
            'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'strong', 'b', 'em',
            'i', 'a', 'br', 'sup', 'sub',
        );

        return self::safe_html_node( $node, $allowed );
    }

    /**
     * Recursively serialize an allowlisted fallback subtree.
     *
     * @param DOMNode          $node DOM node.
     * @param array<int,string> $allowed Allowed element names.
     * @return string
     */
    private static function safe_html_node( $node, $allowed ) {
        if ( XML_TEXT_NODE === $node->nodeType ) {
            return self::escape_text( $node->nodeValue );
        }
        if ( XML_ELEMENT_NODE !== $node->nodeType ) {
            return '';
        }

        $tag = strtolower( $node->nodeName );
        if ( ! in_array( $tag, $allowed, true ) ) {
            $children = '';
            foreach ( $node->childNodes as $child ) {
                $children .= self::safe_html_node( $child, $allowed );
            }
            return $children;
        }

        $attributes = '';
        if ( 'a' === $tag && $node->hasAttribute( 'href' ) ) {
            $url = self::safe_url( $node->getAttribute( 'href' ), false );
            $attributes = '' !== $url ? ' href="' . self::escape_attribute( $url ) . '"' : '';
        } elseif ( 'img' === $tag ) {
            $src = $node->hasAttribute( 'src' ) ? self::safe_url( $node->getAttribute( 'src' ), true ) : '';
            if ( '' === $src ) {
                return '';
            }
            $attributes = ' src="' . self::escape_attribute( $src ) . '"';
            foreach ( array( 'alt', 'width', 'height' ) as $attribute ) {
                if ( ! $node->hasAttribute( $attribute ) ) {
                    continue;
                }
                $value = self::plain_text( $node->getAttribute( $attribute ) );
                if ( ( 'width' === $attribute || 'height' === $attribute ) && ! preg_match( '/^\d{1,5}$/', $value ) ) {
                    continue;
                }
                $attributes .= ' ' . $attribute . '="' . self::escape_attribute( $value ) . '"';
            }
        } elseif ( ( 'th' === $tag || 'td' === $tag ) && $node->hasAttribute( 'colspan' ) ) {
            $colspan = $node->getAttribute( 'colspan' );
            if ( preg_match( '/^\d{1,2}$/', $colspan ) ) {
                $attributes = ' colspan="' . self::escape_attribute( $colspan ) . '"';
            }
        }

        if ( 'img' === $tag || 'br' === $tag ) {
            return '<' . $tag . $attributes . '>';
        }

        $children = '';
        foreach ( $node->childNodes as $child ) {
            $children .= self::safe_html_node( $child, $allowed );
        }

        return '<' . $tag . $attributes . '>' . $children . '</' . $tag . '>';
    }

    /**
     * Load an HTML fragment without network access.
     *
     * @param string            $html Fragment HTML.
     * @param array<int,string> $errors Parser errors.
     * @return DOMElement|null
     */
    private static function load_fragment( $html, &$errors ) {
        if ( ! class_exists( 'DOMDocument' ) ) {
            $errors[] = 'missing_dom_extension';
            return null;
        }

        $document = new DOMDocument( '1.0', 'UTF-8' );
        $previous = libxml_use_internal_errors( true );
        $loaded   = $document->loadHTML(
            '<!doctype html><html><head><meta charset="utf-8"></head><body><div id="lunara-parser-root">' . $html . '</div></body></html>',
            LIBXML_NONET | LIBXML_COMPACT
        );
        $libxml_errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        if ( ! $loaded ) {
            $errors[] = 'malformed_markup';
            return null;
        }

        foreach ( $libxml_errors as $error ) {
            if ( LIBXML_ERR_FATAL === $error->level ) {
                $errors[] = 'malformed_markup';
                return null;
            }
        }

        $root = $document->getElementById( 'lunara-parser-root' );
        if ( ! $root ) {
            $errors[] = 'malformed_markup';
            return null;
        }

        return $root;
    }

    /**
     * Reject broken element nesting before DOMDocument can silently repair it.
     *
     * Editorial drafts use explicit closing tags. Treating omitted or crossed
     * closing tags as malformed keeps the preview faithful to the source and
     * avoids importing a browser-repaired structure the editor did not write.
     *
     * @param string $html Comment-free draft HTML.
     * @return bool
     */
    private static function has_unbalanced_markup( $html ) {
        $void  = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' );
        $stack = array();

        if ( ! preg_match_all( '/<\s*(\/?)\s*([a-z][a-z0-9]*)\b([^>]*)>/i', $html, $matches, PREG_SET_ORDER ) ) {
            return false;
        }

        foreach ( $matches as $match ) {
            $closing = '/' === $match[1];
            $tag     = strtolower( $match[2] );
            $tail    = trim( $match[3] );

            if ( in_array( $tag, $void, true ) || ( ! $closing && '/' === substr( $tail, -1 ) ) ) {
                continue;
            }

            if ( ! $closing ) {
                $stack[] = $tag;
                continue;
            }

            if ( empty( $stack ) || $tag !== end( $stack ) ) {
                return true;
            }
            array_pop( $stack );
        }

        return ! empty( $stack );
    }

    /**
     * Validate required Review and Debrief fields.
     *
     * @param array<string,mixed> $result Parser result.
     * @return void
     */
    private static function validate_result( &$result ) {
        if ( '' === $result['title'] ) {
            $result['errors'][] = 'missing_title';
        }
        if ( $result['year'] < 1888 || $result['year'] > 2100 ) {
            $result['errors'][] = 'invalid_year';
        }
        if ( ! preg_match( '/^tt\d{6,9}$/', $result['imdb_id'] ) ) {
            $result['errors'][] = 'invalid_imdb_id';
        }
        if ( '' === $result['standfirst'] ) {
            $result['errors'][] = 'missing_standfirst';
        }
        if ( '' === $result['content'] ) {
            $result['errors'][] = 'missing_article_content';
        }
        if ( '' === $result['excerpt'] ) {
            $result['warnings'][] = 'missing_excerpt';
        }
        if ( '' === $result['score'] ) {
            $result['errors'][] = 'missing_score';
        } elseif ( ! preg_match( '/^(?:[0-4](?:\.5)?|5(?:\.0)?)(?:\s*\/\s*5)?$/', $result['score'] ) ) {
            $result['errors'][] = 'invalid_score';
        }
        if ( '' === $result['where_to_watch'] ) {
            $result['errors'][] = 'missing_where_to_watch';
        }

        foreach ( array( 'theme_echo', 'counter_program', 'career_context' ) as $role ) {
            $pairing = $result['pairings'][ $role ];
            if ( '' === $pairing['title'] || 0 === $pairing['year'] || '' === $pairing['imdb_id'] || '' === $pairing['reason'] ) {
                $result['errors'][] = 'missing_pairing_' . $role;
            } elseif ( $pairing['year'] < 1888 || $pairing['year'] > 2100 ) {
                $result['errors'][] = 'invalid_pairing_year_' . $role;
            }
        }
    }

    /**
     * Deduplicate issues and set validity.
     *
     * @param array<string,mixed> $result Parser result.
     * @return array<string,mixed>
     */
    private static function finish( $result ) {
        $result['errors']   = array_values( array_unique( $result['errors'] ) );
        $result['warnings'] = array_values( array_unique( $result['warnings'] ) );
        $result['valid']    = empty( $result['errors'] );
        return $result;
    }

    /**
     * Normalize a human label to a deterministic key.
     *
     * @param string $label Label.
     * @return string
     */
    private static function normalize_label( $label ) {
        $label = strtolower( self::plain_text( $label ) );
        $label = preg_replace( '/[^a-z0-9]+/', '_', $label );
        return trim( $label, '_' );
    }

    /**
     * Normalize plain editorial text.
     *
     * @param mixed $value Text value.
     * @return string
     */
    private static function plain_text( $value ) {
        $value = html_entity_decode( strip_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value );
        $value = preg_replace( '/\s+/u', ' ', $value );
        return trim( $value );
    }

    /**
     * Collapse formatting whitespace in safe inline HTML.
     *
     * @param string $html Safe inline HTML.
     * @return string
     */
    private static function normalize_inline( $html ) {
        $html = preg_replace( '/\s+/u', ' ', $html );
        $html = preg_replace( '/\s*(<br>)\s*/', '$1', $html );
        return trim( $html );
    }

    /**
     * Escape a text node for HTML output.
     *
     * @param string $value Text.
     * @return string
     */
    private static function escape_text( $value ) {
        return htmlspecialchars( (string) $value, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' );
    }

    /**
     * Escape an HTML attribute.
     *
     * @param string $value Attribute value.
     * @return string
     */
    private static function escape_attribute( $value ) {
        return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
    }

    /**
     * Permit only safe absolute or local URLs.
     *
     * @param string $value URL.
     * @param bool   $allow_relative Whether root-relative paths are accepted.
     * @return string
     */
    private static function safe_url( $value, $allow_relative ) {
        $value = trim( html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        if ( $allow_relative && preg_match( '#^/(?!/)#', $value ) ) {
            return $value;
        }
        if ( preg_match( '#^https?://#i', $value ) || ( ! $allow_relative && preg_match( '#^mailto:#i', $value ) ) ) {
            return $value;
        }
        return '';
    }
}
