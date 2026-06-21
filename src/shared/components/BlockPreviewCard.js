/**
 * BlockPreviewCard — shared editor preview for Interactivity-API blocks.
 *
 * Wbcom Block Quality Standard: blocks whose runtime markup depends on the
 * Interactivity API store (`data-wp-interactive` / `data-wp-bind--*`) must
 * NOT use `<ServerSideRender>` in the editor — the store never runs in the
 * editor iframe, so every conditional state renders stacked and unstyled.
 * Instead they render this static, styled, branded preview card so the
 * editor surface is "ready with options + a real preview", while the live
 * render.php drives the frontend.
 *
 * @see ~/.claude/skills/wp-block-development/references/editor-preview-and-ssr.md Rule 2
 */

import { Icon } from '@wordpress/components';

/**
 * Always pass an SVG icon (e.g. from `@wordpress/icons`), NOT a dashicon
 * string. The editor canvas is an iframe and the dashicons FONT is not loaded
 * inside it, so a font-glyph icon renders blank. SVG icons are self-contained.
 *
 * @param {Object}      props
 * @param {JSX.Element} props.icon        SVG icon element (e.g. `starFilled` from '@wordpress/icons').
 * @param {string}      props.title       Block display title.
 * @param {string}      [props.description] One-line explanation of what renders on the frontend.
 * @param {string}      [props.status]    Configuration status line (reflects key attributes).
 * @param {string}      [props.statusType] 'configured' | 'unconfigured'.
 * @return {JSX.Element} Preview card.
 */
export default function BlockPreviewCard( {
	icon,
	title,
	description,
	status,
	statusType = 'configured',
} ) {
	return (
		<div className="wb-gam-block-preview">
			<div className="wb-gam-block-preview__icon">
				<Icon icon={ icon } size={ 28 } />
			</div>
			<div className="wb-gam-block-preview__body">
				<h3 className="wb-gam-block-preview__title">{ title }</h3>
				{ description && (
					<p className="wb-gam-block-preview__desc">{ description }</p>
				) }
				{ status && (
					<p
						className={ `wb-gam-block-preview__status wb-gam-block-preview__status--${ statusType }` }
					>
						{ status }
					</p>
				) }
			</div>
		</div>
	);
}
