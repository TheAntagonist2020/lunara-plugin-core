<?php
/**
 * Modular Essay Builder — ACF Flexible Content (Design Spec §12 / §19A).
 *
 * Registers the drag-and-drop essay module palette on journal entries and
 * standard posts: prose passages, pull-quotes, intimate inset frames,
 * widescreen video spreads, and full-bleed cinematic banner panels. The
 * theme renders the modules after the main content (inc/essay-builder.php);
 * per the spec's Preview Rule, modules render on the front end only.
 *
 * Capped at 20 modules per essay (§19A performance guardrail).
 *
 * @package Lunara_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lunara_Essays {

	public static function init() {
		add_action( 'acf/init', array( __CLASS__, 'register_field_groups' ) );
	}

	public static function register_field_groups() {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}

		acf_add_local_field_group(
			array(
				'key'    => 'group_lunara_essay_builder',
				'title'  => 'Essay Builder',
				'fields' => array(
					array(
						'key'          => 'field_lunara_essay_modules',
						'label'        => 'Essay Modules',
						'name'         => 'essay_modules',
						'type'         => 'flexible_content',
						'button_label' => 'Add Essay Module',
						'max'          => 20,
						'instructions' => 'Drag-and-drop cinematic modules, rendered after the main text. Blocks preview on the front end only — use WordPress Preview, not the editor canvas.',
						'layouts'      => array(
							'layout_lunara_essay_prose' => array(
								'key'        => 'layout_lunara_essay_prose',
								'name'       => 'prose',
								'label'      => 'Prose Passage',
								'display'    => 'block',
								'sub_fields' => array(
									array(
										'key'          => 'field_lunara_essay_prose_text',
										'label'        => 'Text',
										'name'         => 'text',
										'type'         => 'wysiwyg',
										'tabs'         => 'all',
										'toolbar'      => 'full',
										'media_upload' => 0,
									),
								),
							),
							'layout_lunara_essay_pullquote' => array(
								'key'        => 'layout_lunara_essay_pullquote',
								'name'       => 'pullquote',
								'label'      => 'Pull-Quote',
								'display'    => 'block',
								'sub_fields' => array(
									array(
										'key'      => 'field_lunara_essay_pullquote_quote',
										'label'    => 'Quote',
										'name'     => 'quote',
										'type'     => 'textarea',
										'rows'     => 3,
										'required' => 1,
									),
									array(
										'key'   => 'field_lunara_essay_pullquote_attribution',
										'label' => 'Attribution',
										'name'  => 'attribution',
										'type'  => 'text',
										'placeholder' => 'Optional — a film, a voice, a year',
									),
								),
							),
							'layout_lunara_essay_inset' => array(
								'key'        => 'layout_lunara_essay_inset',
								'name'       => 'inset_frame',
								'label'      => 'Inset Frame',
								'display'    => 'block',
								'sub_fields' => array(
									array(
										'key'           => 'field_lunara_essay_inset_image',
										'label'         => 'Image',
										'name'          => 'image',
										'type'          => 'image',
										'return_format' => 'id',
										'preview_size'  => 'medium',
										'required'      => 1,
									),
									array(
										'key'   => 'field_lunara_essay_inset_caption',
										'label' => 'Caption',
										'name'  => 'caption',
										'type'  => 'text',
									),
									array(
										'key'           => 'field_lunara_essay_inset_side',
										'label'         => 'Side',
										'name'          => 'side',
										'type'          => 'select',
										'choices'       => array(
											'right' => 'Right (text wraps left)',
											'left'  => 'Left (text wraps right)',
										),
										'default_value' => 'right',
									),
								),
							),
							'layout_lunara_essay_video' => array(
								'key'        => 'layout_lunara_essay_video',
								'name'       => 'video_spread',
								'label'      => 'Widescreen Video Spread',
								'display'    => 'block',
								'sub_fields' => array(
									array(
										'key'      => 'field_lunara_essay_video_embed',
										'label'    => 'Video',
										'name'     => 'video',
										'type'     => 'oembed',
										'required' => 1,
									),
									array(
										'key'   => 'field_lunara_essay_video_note',
										'label' => 'Note',
										'name'  => 'note',
										'type'  => 'text',
										'placeholder' => 'Optional caption under the spread',
									),
								),
							),
							'layout_lunara_essay_banner' => array(
								'key'        => 'layout_lunara_essay_banner',
								'name'       => 'cinema_banner',
								'label'      => 'Cinematic Banner',
								'display'    => 'block',
								'sub_fields' => array(
									array(
										'key'           => 'field_lunara_essay_banner_image',
										'label'         => 'Image (wide)',
										'name'          => 'image',
										'type'          => 'image',
										'return_format' => 'id',
										'preview_size'  => 'large',
										'required'      => 1,
									),
									array(
										'key'   => 'field_lunara_essay_banner_kicker',
										'label' => 'Kicker',
										'name'  => 'kicker',
										'type'  => 'text',
										'placeholder' => 'Optional uppercase label',
									),
									array(
										'key'   => 'field_lunara_essay_banner_title',
										'label' => 'Title',
										'name'  => 'title',
										'type'  => 'text',
										'placeholder' => 'Optional overlay line',
									),
								),
							),
						),
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'journal',
						),
					),
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'post',
						),
					),
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'review',
						),
					),
				),
				'position' => 'normal',
			)
		);
	}
}
